<?php

namespace App\Services;

use App\Models\ManagedLaravelApp;
use App\Models\SupervisorWorker;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SupervisorManager
{
    private string $confDir;
    private string $supervisorctl;
    private string $helperScript;

    public function __construct()
    {
        $this->confDir       = config('supervisor_plugin.supervisor_conf_dir', '/etc/supervisor/conf.d');
        $this->supervisorctl = config('supervisor_plugin.supervisorctl_bin', '/usr/bin/supervisorctl');
        $this->helperScript  = config('supervisor_plugin.helper_script');
    }

    // =========================================================================
    // Worker Lifecycle
    // =========================================================================

    /**
     * Create a new supervisor worker: write conf file, register in DB, reload supervisor.
     *
     * @throws \RuntimeException
     */
    public function createWorker(
        ManagedLaravelApp $app,
        string $type,
        array  $workerConfig
    ): SupervisorWorker {
        $cpanelUser = $app->cpanel_user;

        // Generate a unique index for this type+user combination
        $existingCount = SupervisorWorker::where('cpanel_user', $cpanelUser)
            ->where('type', $type)
            ->count();
        $index = $existingCount + 1;

        $appId       = $app->getSafeAppIdentifier();
        $confName    = "{$cpanelUser}_{$appId}_{$type}_{$index}";
        $confFile    = $this->confDir . '/' . $confName . '.conf';
        $processName = "lsp_{$confName}"; // "lsp" = Laravel Supervisor Plugin namespace

        $logDir       = $app->getLogDirectory();
        $logFile      = "{$logDir}/{$confName}.log";
        $errorLogFile = "{$logDir}/{$confName}_error.log";

        // Build the supervisor command
        $command = $this->buildCommand($app, $type, $workerConfig);

        // Generate the conf file content
        $confContent = $this->renderConfTemplate(
            processName: $processName,
            command:     $command,
            user:        $cpanelUser,
            logFile:     $logFile,
            errorLogFile: $errorLogFile,
            config:      $workerConfig
        );

        // Persist to DB first (so we have the ID before writing files)
        $worker = SupervisorWorker::create([
            'managed_laravel_app_id' => $app->id,
            'cpanel_user'            => $cpanelUser,
            'type'                   => $type,
            'worker_name'            => $workerConfig['name'] ?? ucfirst($type) . " Worker #{$index}",
            'conf_filename'          => $confName . '.conf',
            'conf_path'              => $confFile,
            'process_name'           => $processName,
            'worker_config'          => $workerConfig,
            'desired_state'          => 'running',
            'autostart'              => true,
            'autorestart'            => true,
            'log_path'               => $logFile,
            'error_log_path'         => $errorLogFile,
        ]);

        try {
            // Run privileged helper to write conf and ensure log dir exists
            $this->runHelper('create_worker', [
                'conf_path'    => $confFile,
                'conf_content' => base64_encode($confContent),
                'log_dir'      => $logDir,
                'cpanel_user'  => $cpanelUser,
            ]);

            // Reload supervisor (reread + update only the new process)
            $this->supervisorRereadUpdate($processName);

            $worker->update(['last_started_at' => now(), 'last_status' => 'STARTING']);
        } catch (\Throwable $e) {
            // Roll back DB record on failure
            $worker->delete();
            throw new \RuntimeException("Failed to create worker: " . $e->getMessage(), 0, $e);
        }

        Log::info("[SupervisorManager] Created worker {$processName} for user {$cpanelUser}");

        return $worker;
    }

    /**
     * Delete a worker: stop process, remove conf file, delete DB record.
     */
    public function deleteWorker(SupervisorWorker $worker): void
    {
        $processName = $worker->process_name;
        $confPath    = $worker->conf_path;

        try {
            // Stop the process first
            $this->stopProcess($worker->cpanel_user, $processName);

            // Remove conf via helper
            $this->runHelper('delete_worker', [
                'conf_path'    => $confPath,
                'process_name' => $processName,
            ]);

            // Reload supervisor
            $this->supervisorRereadUpdate($processName);
        } catch (\Throwable $e) {
            Log::error("[SupervisorManager] Error deleting worker {$processName}: " . $e->getMessage());
            // Continue with DB cleanup even if supervisor operations fail
        }

        $worker->delete();

        Log::info("[SupervisorManager] Deleted worker {$processName}");
    }

    /**
     * Restart a specific process (never restarts the entire supervisor service).
     */
    public function restartProcess(string $cpanelUser, string $processName): array
    {
        $this->assertProcessOwnership($cpanelUser, $processName);

        $output = $this->runHelper('restart_process', [
            'process_name' => $processName,
        ]);

        // Update DB record
        SupervisorWorker::where('process_name', $processName)
            ->update(['last_restarted_at' => now()]);

        Log::info("[SupervisorManager] Restarted process {$processName} for user {$cpanelUser}");

        return ['success' => true, 'output' => $output];
    }

    /**
     * Stop a specific process.
     */
    public function stopProcess(string $cpanelUser, string $processName): array
    {
        $this->assertProcessOwnership($cpanelUser, $processName);

        $output = $this->runHelper('stop_process', ['process_name' => $processName]);

        SupervisorWorker::where('process_name', $processName)
            ->update(['desired_state' => 'stopped', 'last_status' => 'STOPPED']);

        return ['success' => true, 'output' => $output];
    }

    /**
     * Start a specific process.
     */
    public function startProcess(string $cpanelUser, string $processName): array
    {
        $this->assertProcessOwnership($cpanelUser, $processName);

        $output = $this->runHelper('start_process', ['process_name' => $processName]);

        SupervisorWorker::where('process_name', $processName)
            ->update(['desired_state' => 'running', 'last_status' => 'RUNNING']);

        return ['success' => true, 'output' => $output];
    }

    // =========================================================================
    // Status & Monitoring
    // =========================================================================

    /**
     * Get the status of all processes for a cPanel user.
     * Returns parsed supervisorctl status output.
     */
    public function getStatusForUser(string $cpanelUser): array
    {
        $workers = SupervisorWorker::where('cpanel_user', $cpanelUser)->get();

        if ($workers->isEmpty()) {
            return [];
        }

        $allStatus = $this->parseSupervisorctlStatus();
        $result    = [];

        foreach ($workers as $worker) {
            $status = $allStatus[$worker->process_name] ?? [
                'status'  => 'UNKNOWN',
                'pid'     => null,
                'uptime'  => null,
            ];

            $psInfo = $this->getProcessInfo($status['pid'] ?? null);

            $result[] = array_merge([
                'worker_id'    => $worker->id,
                'process_name' => $worker->process_name,
                'type'         => $worker->type,
                'worker_name'  => $worker->worker_name,
                'app_path'     => $worker->app->app_path ?? '',
                'port'         => $worker->assignedPort?->port,
                'protocol'     => $worker->assignedPort?->protocol,
                'last_restart' => $worker->last_restarted_at?->diffForHumans(),
            ], $status, $psInfo);
        }

        return $result;
    }

    /**
     * Return the tail of a log file for a worker.
     * Ownership validated before reading.
     */
    public function getLogTail(string $cpanelUser, int $workerId, int $lines = 50): array
    {
        $worker = SupervisorWorker::where('id', $workerId)
            ->where('cpanel_user', $cpanelUser)
            ->firstOrFail();

        $lines = min($lines, (int) config('supervisor_plugin.log.tail_lines', 50));

        return [
            'stdout' => $this->tailLogFile($worker->log_path, $lines),
            'stderr' => $this->tailLogFile($worker->error_log_path, $lines),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildCommand(ManagedLaravelApp $app, string $type, array $config): string
    {
        $phpBin  = escapeshellarg($app->php_binary);
        $artisan = escapeshellarg($app->artisan_path);

        return match ($type) {
            'queue' => $this->buildQueueCommand($phpBin, $artisan, $config),
            'scheduler' => "{$phpBin} {$artisan} schedule:work --no-interaction",
            'reverb' => "{$phpBin} {$artisan} reverb:start --no-interaction",
            default => throw new \InvalidArgumentException("Unknown worker type: {$type}"),
        };
    }

    private function buildQueueCommand(string $phpBin, string $artisan, array $config): string
    {
        $connection = isset($config['queue_connection'])
            ? ' --queue=' . escapeshellarg($config['queue_connection'])
            : '';

        $tries = isset($config['tries'])
            ? ' --tries=' . (int) $config['tries']
            : ' --tries=3';

        $timeout = isset($config['timeout'])
            ? ' --timeout=' . (int) $config['timeout']
            : ' --timeout=60';

        $memory = isset($config['memory'])
            ? ' --memory=' . (int) $config['memory']
            : ' --memory=128';

        return "{$phpBin} {$artisan} queue:work --no-interaction{$connection}{$tries}{$timeout}{$memory}";
    }

    private function renderConfTemplate(
        string $processName,
        string $command,
        string $user,
        string $logFile,
        string $errorLogFile,
        array  $config
    ): string {
        $defaults  = config('supervisor_plugin.worker_defaults.queue');
        $numprocs  = (int) ($config['numprocs'] ?? $defaults['numprocs'] ?? 1);
        $startsecs = (int) ($config['startsecs'] ?? $defaults['startsecs'] ?? 1);
        $stopwait  = (int) ($config['stopwaitsecs'] ?? $defaults['stopwaitsecs'] ?? 30);

        return <<<CONF
        [program:{$processName}]
        command={$command}
        user={$user}
        process_name=%(program_name)s_%(process_num)02d
        numprocs={$numprocs}
        autostart=true
        autorestart=true
        startsecs={$startsecs}
        startretries=3
        stopwaitsecs={$stopwait}
        stopasgroup=true
        killasgroup=true
        redirect_stderr=false
        stdout_logfile={$logFile}
        stdout_logfile_maxbytes=10MB
        stdout_logfile_backups=3
        stderr_logfile={$errorLogFile}
        stderr_logfile_maxbytes=10MB
        stderr_logfile_backups=3
        environment=HOME="/home/{$user}",USER="{$user}"
        CONF;
    }

    private function supervisorRereadUpdate(string $processName): void
    {
        $this->runHelper('supervisor_reread_update', ['process_name' => $processName]);
    }

    /**
     * Parse `supervisorctl status` output into a keyed array.
     */
    private function parseSupervisorctlStatus(): array
    {
        $output = $this->runHelper('supervisor_status', []);
        $result = [];

        foreach (explode("\n", $output ?? '') as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Format: "process_name   STATUS   pid XXXX, uptime H:MM:SS"
            if (preg_match('/^(\S+)\s+(\w+)\s+pid\s+(\d+),\s+uptime\s+(.+)$/', $line, $m)) {
                $result[$m[1]] = ['status' => $m[2], 'pid' => (int) $m[3], 'uptime' => trim($m[4])];
            } elseif (preg_match('/^(\S+)\s+(\w+)(.*)$/', $line, $m)) {
                $result[$m[1]] = ['status' => $m[2], 'pid' => null, 'uptime' => null];
            }
        }

        return $result;
    }

    private function getProcessInfo(?int $pid): array
    {
        if (! $pid) {
            return ['cpu' => null, 'memory' => null];
        }

        $output = @shell_exec("ps -p " . (int) $pid . " -o %cpu,%mem --no-headers 2>/dev/null");

        if (empty($output)) {
            return ['cpu' => null, 'memory' => null];
        }

        $parts = preg_split('/\s+/', trim($output));

        return [
            'cpu'    => isset($parts[0]) ? (float) $parts[0] : null,
            'memory' => isset($parts[1]) ? (float) $parts[1] : null,
        ];
    }

    private function tailLogFile(string $logPath, int $lines): string
    {
        // Validate path: must be under /home/*/logs/supervisor/
        if (! preg_match('#^/home/[a-z0-9_\-]+/logs/supervisor/#i', $logPath)) {
            return '';
        }

        if (! file_exists($logPath) || ! is_readable($logPath)) {
            return '';
        }

        $safeLines = min($lines, 200);
        $output    = @shell_exec("tail -n " . (int) $safeLines . " " . escapeshellarg($logPath) . " 2>/dev/null");

        return $output ?? '';
    }

    /**
     * Ensure a process name belongs to the given user (prefix check).
     */
    private function assertProcessOwnership(string $cpanelUser, string $processName): void
    {
        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            throw new \InvalidArgumentException("Invalid username");
        }

        // All plugin-managed processes are prefixed with lsp_{user}_
        $expectedPrefix = 'lsp_' . $cpanelUser . '_';

        if (strpos($processName, $expectedPrefix) !== 0) {
            // Also check DB ownership
            $exists = SupervisorWorker::where('process_name', $processName)
                ->where('cpanel_user', $cpanelUser)
                ->exists();

            if (! $exists) {
                throw new \RuntimeException(
                    "Access denied: process {$processName} does not belong to user {$cpanelUser}"
                );
            }
        }
    }

    /**
     * Invoke the privileged helper script via sudo.
     * The helper validates all inputs server-side.
     */
    private function runHelper(string $action, array $params): string
    {
        if (! preg_match('/^[a-z_]+$/', $action)) {
            throw new \InvalidArgumentException("Invalid helper action: {$action}");
        }

        $payload = base64_encode(json_encode([
            'action' => $action,
            'params' => $params,
        ]));

        $helper  = escapeshellarg($this->helperScript);
        $phpBin  = escapeshellarg(config('supervisor_plugin.php_bin', '/usr/bin/php'));
        $command = "sudo {$phpBin} {$helper} " . escapeshellarg($payload) . " 2>&1";

        $output = shell_exec($command);

        if ($output === null) {
            throw new \RuntimeException("Helper script returned no output for action: {$action}");
        }

        // The helper returns JSON: {"success": bool, "output": "...", "error": "..."}
        $response = json_decode($output, true);

        if (! is_array($response)) {
            throw new \RuntimeException("Helper script returned invalid JSON: " . substr($output, 0, 200));
        }

        if (! ($response['success'] ?? false)) {
            throw new \RuntimeException("Helper error: " . ($response['error'] ?? 'Unknown error'));
        }

        return $response['output'] ?? '';
    }
}
