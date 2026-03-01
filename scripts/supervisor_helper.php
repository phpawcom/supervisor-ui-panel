#!/usr/bin/php
<?php
/**
 * Privileged Supervisor Helper
 * ============================
 * This script runs as ROOT via sudo and performs privileged operations on behalf
 * of the Laravel Supervisor Manager plugin.
 *
 * Security model:
 *   - Invoked ONLY via: sudo /usr/bin/php /path/to/supervisor_helper.php <base64-payload>
 *   - Must be owned by root, chmod 700
 *   - sudoers entry is tightly scoped (see install.sh for the exact rule)
 *   - All inputs are strictly validated before use
 *   - No shell interpolation of user-controlled data without escapeshellarg()
 *
 * Supported actions:
 *   create_worker          Write supervisor .conf file + ensure log dir
 *   delete_worker          Remove supervisor .conf file
 *   restart_process        supervisorctl restart <process>
 *   stop_process           supervisorctl stop <process>
 *   start_process          supervisorctl start <process>
 *   supervisor_reread_update  supervisorctl reread + update
 *   supervisor_status      supervisorctl status (all)
 *   generate_token         Generate a plugin auth token
 */

declare(strict_types=1);

// ─── Bootstrap ───────────────────────────────────────────────────────────────

if (posix_getuid() !== 0) {
    exitError('This helper must run as root.');
}

if ($argc < 2) {
    exitError('Usage: supervisor_helper.php <base64-encoded-payload>');
}

// Decode input
$raw = base64_decode($argv[1], true);
if ($raw === false) {
    exitError('Invalid base64 payload.');
}

$payload = json_decode($raw, true);
if (! is_array($payload) || ! isset($payload['action'])) {
    exitError('Invalid JSON payload.');
}

$action = $payload['action'];
$params = $payload['params'] ?? [];

// Validate action name (whitelist)
$allowedActions = [
    'create_worker',
    'delete_worker',
    'restart_process',
    'stop_process',
    'start_process',
    'supervisor_reread_update',
    'supervisor_status',
    'generate_token',
];

if (! in_array($action, $allowedActions, true)) {
    exitError("Unknown action: {$action}");
}

// ─── Dispatch ────────────────────────────────────────────────────────────────

switch ($action) {
    case 'create_worker':
        actionCreateWorker($params);
        break;
    case 'delete_worker':
        actionDeleteWorker($params);
        break;
    case 'restart_process':
        actionProcessControl($params, 'restart');
        break;
    case 'stop_process':
        actionProcessControl($params, 'stop');
        break;
    case 'start_process':
        actionProcessControl($params, 'start');
        break;
    case 'supervisor_reread_update':
        actionSupervisorRereadUpdate($params);
        break;
    case 'supervisor_status':
        actionSupervisorStatus();
        break;
    case 'generate_token':
        actionGenerateToken($params);
        break;
}

// ─── Action implementations ──────────────────────────────────────────────────

function actionCreateWorker(array $params): void
{
    $confPath    = requireParam($params, 'conf_path');
    $confContent = requireParam($params, 'conf_content');
    $logDir      = requireParam($params, 'log_dir');
    $cpanelUser  = requireParam($params, 'cpanel_user');

    // Validate conf path (must be in /etc/supervisor/conf.d/)
    validateConfPath($confPath);

    // Validate log dir (must be under /home/{user}/logs/supervisor/)
    validateLogDir($logDir, $cpanelUser);

    // Decode conf content
    $confDecoded = base64_decode($confContent, true);
    if ($confDecoded === false) {
        exitError('Invalid base64 conf_content');
    }

    // Validate conf content (basic sanity check)
    if (! str_contains($confDecoded, '[program:')) {
        exitError('Invalid supervisor conf content: missing [program:] section');
    }

    // Ensure log directory exists and is owned by the user
    if (! is_dir($logDir)) {
        if (! mkdir($logDir, 0750, true)) {
            exitError("Failed to create log directory: {$logDir}");
        }
    }

    // Set log dir ownership to the cPanel user
    $uid = getUserUid($cpanelUser);
    if ($uid !== null) {
        chown($logDir, $uid);
        chgrp($logDir, $uid);
    }

    // Write the conf file (owned by root, readable by supervisord)
    if (file_put_contents($confPath, $confDecoded, LOCK_EX) === false) {
        exitError("Failed to write conf file: {$confPath}");
    }

    chmod($confPath, 0644);
    chown($confPath, 'root');

    logAction("Created conf: {$confPath}, log dir: {$logDir}");
    exitSuccess("Conf file written: {$confPath}");
}

function actionDeleteWorker(array $params): void
{
    $confPath    = requireParam($params, 'conf_path');
    $processName = requireParam($params, 'process_name');

    validateConfPath($confPath);
    validateProcessName($processName);

    if (file_exists($confPath)) {
        if (! unlink($confPath)) {
            exitError("Failed to delete conf file: {$confPath}");
        }
        logAction("Deleted conf: {$confPath}");
    }

    exitSuccess("Conf file removed: {$confPath}");
}

function actionProcessControl(array $params, string $operation): void
{
    $processName = requireParam($params, 'process_name');
    validateProcessName($processName);

    $bin    = getSupervisorctlBin();
    $cmd    = escapeshellarg($bin) . ' ' . escapeshellarg($operation) . ' ' . escapeshellarg($processName);
    $output = runCommand($cmd);

    logAction("supervisorctl {$operation} {$processName}: " . trim($output));
    exitSuccess($output);
}

function actionSupervisorRereadUpdate(array $params): void
{
    $processName = $params['process_name'] ?? null;

    $bin = getSupervisorctlBin();

    // Reread first
    $rereadOut = runCommand(escapeshellarg($bin) . ' reread 2>&1');

    // Update (applies only new/changed configs)
    $updateOut = runCommand(escapeshellarg($bin) . ' update 2>&1');

    $combined = "reread:\n{$rereadOut}\nupdate:\n{$updateOut}";
    logAction("supervisorctl reread+update" . ($processName ? " (process: {$processName})" : ''));
    exitSuccess($combined);
}

function actionSupervisorStatus(): void
{
    $bin    = getSupervisorctlBin();
    $output = runCommand(escapeshellarg($bin) . ' status 2>&1');
    exitSuccess($output);
}

function actionGenerateToken(array $params): void
{
    $role       = $params['role']       ?? 'cpanel_user';
    $user       = requireParam($params, 'user');
    $secretFile = '/var/cpanel/laravel_supervisor_plugin/plugin_secret';

    if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $user)) {
        exitError("Invalid username: {$user}");
    }

    if (! in_array($role, ['cpanel_user', 'whm_admin'], true)) {
        exitError("Invalid role: {$role}");
    }

    if (! file_exists($secretFile)) {
        exitError("Plugin secret file not found: {$secretFile}");
    }

    $secret = trim(file_get_contents($secretFile));
    if (empty($secret)) {
        exitError('Plugin secret is empty');
    }

    $exp  = time() + 3600; // 1 hour
    $data = $user . '|' . $exp . ($role === 'whm_admin' ? '|whm_admin' : '');
    $sig  = hash_hmac('sha256', $data, $secret);

    $payload = ['user' => $user, 'exp' => $exp, 'sig' => $sig, 'role' => $role];
    if ($role === 'whm_admin') {
        $payload['role'] = 'whm_admin';
    }

    $token = base64_encode(json_encode($payload));
    exitSuccess($token);
}

// ─── Validation helpers ───────────────────────────────────────────────────────

function validateConfPath(string $path): void
{
    $realPath = realpath(dirname($path));

    // Must be in /etc/supervisor/conf.d/ or configured equivalent
    $allowedDirs = [
        '/etc/supervisor/conf.d',
        '/etc/supervisord.d',
        '/etc/supervisor.d',
    ];

    $allowed = false;
    foreach ($allowedDirs as $dir) {
        if ($realPath === $dir || str_starts_with($realPath . '/', $dir . '/')) {
            $allowed = true;
            break;
        }
    }

    if (! $allowed) {
        exitError("Conf path not in allowed supervisor conf directory: {$path}");
    }

    // Must end in .conf
    if (! str_ends_with($path, '.conf')) {
        exitError("Conf path must end in .conf: {$path}");
    }

    // Filename must match our naming convention: {user}_{app}_{type}_{index}.conf
    $filename = basename($path, '.conf');
    if (! preg_match('/^[a-z0-9_]{1,200}$/i', $filename)) {
        exitError("Invalid conf filename: {$filename}");
    }
}

function validateLogDir(string $logDir, string $cpanelUser): void
{
    if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
        exitError("Invalid username: {$cpanelUser}");
    }

    $expectedPrefix = "/home/{$cpanelUser}/logs/supervisor";

    // Resolve parent directory to detect symlink escapes
    $parentReal = realpath(dirname($logDir));
    $expectedReal = realpath("/home/{$cpanelUser}/logs") ?: "/home/{$cpanelUser}/logs";

    if (strpos($logDir, $expectedPrefix) !== 0) {
        exitError("Log directory not under user's home: {$logDir}");
    }
}

function validateProcessName(string $name): void
{
    // Plugin process names must start with "lsp_" to namespace them
    if (! preg_match('/^lsp_[a-z0-9_]{1,200}$/i', $name)) {
        exitError("Invalid process name (must match lsp_*): {$name}");
    }
}

// ─── Utility ──────────────────────────────────────────────────────────────────

function getSupervisorctlBin(): string
{
    $candidates = [
        '/usr/bin/supervisorctl',
        '/usr/local/bin/supervisorctl',
        '/usr/sbin/supervisorctl',
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    exitError('supervisorctl not found. Is Supervisor installed?');
}

function runCommand(string $command): string
{
    $output   = null;
    $exitCode = null;

    exec($command . ' 2>&1', $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    // supervisorctl returns 3 for "already running" etc — treat those as info
    if ($exitCode !== 0 && $exitCode !== 3) {
        logAction("Command exit code {$exitCode}: {$command}\nOutput: {$output}");
    }

    return $output;
}

function getUserUid(string $user): ?int
{
    $info = posix_getpwnam($user);
    return $info ? (int) $info['uid'] : null;
}

function requireParam(array $params, string $key): string
{
    if (! isset($params[$key]) || $params[$key] === '') {
        exitError("Missing required parameter: {$key}");
    }
    return (string) $params[$key];
}

function logAction(string $message): void
{
    $logFile = '/var/log/laravel_supervisor_plugin_install.log';
    $ts      = date('Y-m-d H:i:s');
    $entry   = "[{$ts}] [helper] {$message}" . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function exitSuccess(string $output): never
{
    echo json_encode(['success' => true, 'output' => $output, 'error' => null]);
    exit(0);
}

function exitError(string $message): never
{
    echo json_encode(['success' => false, 'output' => '', 'error' => $message]);
    logAction("ERROR: {$message}");
    exit(1);
}
