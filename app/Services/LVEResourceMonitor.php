<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LVEResourceMonitor
{
    private bool $lveAvailable;
    private string $lvectlBin;

    public function __construct()
    {
        $this->lvectlBin    = config('supervisor_plugin.lve.lvectl_bin', '/usr/sbin/lvectl');
        $this->lveAvailable = config('supervisor_plugin.lve.enabled', true) && $this->detectLve();
    }

    /**
     * Whether CloudLinux LVE is available on this server.
     */
    public function isAvailable(): bool
    {
        return $this->lveAvailable;
    }

    /**
     * Get current LVE usage for a cPanel user.
     * Returns a structured array; all values null if LVE not available.
     */
    public function getUsage(string $cpanelUser): array
    {
        if (! $this->lveAvailable) {
            return $this->unavailableResponse();
        }

        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            return $this->unavailableResponse();
        }

        $cacheKey = "lve_usage_{$cpanelUser}";

        return Cache::remember($cacheKey, 10, function () use ($cpanelUser) {
            return $this->fetchLveUsage($cpanelUser);
        });
    }

    /**
     * Get LVE limits for a cPanel user.
     */
    public function getLimits(string $cpanelUser): array
    {
        if (! $this->lveAvailable) {
            return $this->unavailableResponse();
        }

        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            return $this->unavailableResponse();
        }

        $cacheKey = "lve_limits_{$cpanelUser}";

        return Cache::remember($cacheKey, 60, function () use ($cpanelUser) {
            return $this->fetchLveLimits($cpanelUser);
        });
    }

    /**
     * Check whether it is safe to create a new worker for the user.
     * Returns ['safe' => bool, 'reason' => string|null]
     */
    public function checkResourceSafety(string $cpanelUser): array
    {
        if (! $this->lveAvailable) {
            return ['safe' => true, 'reason' => null, 'lve_available' => false];
        }

        $usage  = $this->getUsage($cpanelUser);
        $limits = $this->getLimits($cpanelUser);

        if (! $usage['available'] || ! $limits['available']) {
            return ['safe' => true, 'reason' => null, 'lve_available' => true];
        }

        $warnCpu = (int) config('supervisor_plugin.lve.warn_cpu_pct', 80);
        $warnMem = (int) config('supervisor_plugin.lve.warn_mem_pct', 80);

        // CPU check: lCPU is in units (1024 = 1 core), uCPU is current usage
        if ($limits['cpu_limit'] > 0) {
            $cpuPct = ($usage['cpu_usage'] / $limits['cpu_limit']) * 100;
            if ($cpuPct >= $warnCpu) {
                return [
                    'safe'          => false,
                    'reason'        => sprintf(
                        'CPU usage is at %.1f%% of your LVE limit. Adding more workers may cause throttling.',
                        $cpuPct
                    ),
                    'lve_available' => true,
                ];
            }
        }

        // Memory check: in KB
        if ($limits['mem_limit'] > 0) {
            $memPct = ($usage['mem_usage'] / $limits['mem_limit']) * 100;
            if ($memPct >= $warnMem) {
                return [
                    'safe'          => false,
                    'reason'        => sprintf(
                        'Memory usage is at %.1f%% of your LVE limit (%d MB used of %d MB). '
                        . 'Adding more workers may exceed your memory limit.',
                        $memPct,
                        (int) ($usage['mem_usage'] / 1024),
                        (int) ($limits['mem_limit'] / 1024)
                    ),
                    'lve_available' => true,
                ];
            }
        }

        return ['safe' => true, 'reason' => null, 'lve_available' => true];
    }

    /**
     * Get LVE statistics for all users (WHM admin view).
     */
    public function getAllUsersStats(): array
    {
        if (! $this->lveAvailable) {
            return [];
        }

        $output = $this->runLvectl(['stat', '--json']);
        if ($output === null) {
            return [];
        }

        $data = @json_decode($output, true);
        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function detectLve(): bool
    {
        // Check for CloudLinux kernel module
        if (! file_exists('/proc/lve/list')) {
            return false;
        }

        if (! is_executable($this->lvectlBin)) {
            return false;
        }

        return true;
    }

    private function fetchLveUsage(string $cpanelUser): array
    {
        // lvectl stat <username> --format=json
        $output = $this->runLvectl(['stat', $cpanelUser, '--json']);

        if ($output === null) {
            return $this->unavailableResponse();
        }

        $data = @json_decode($output, true);

        if (! isset($data[0])) {
            return $this->unavailableResponse();
        }

        $entry = $data[0];

        return [
            'available'  => true,
            'lve_id'     => $entry['ID']        ?? null,
            'cpu_usage'  => (float) ($entry['uCPU']  ?? 0),
            'mem_usage'  => (int)   ($entry['uPMem'] ?? 0),   // in KB
            'io_usage'   => (float) ($entry['uIO']   ?? 0),
            'ep_usage'   => (int)   ($entry['uEP']   ?? 0),   // entry processes
            'nproc'      => (int)   ($entry['uNproc'] ?? 0),
            'cpu_pct'    => null, // calculated externally
            'mem_pct'    => null,
        ];
    }

    private function fetchLveLimits(string $cpanelUser): array
    {
        // lvectl limits <username> --json
        $output = $this->runLvectl(['limits', $cpanelUser, '--json']);

        if ($output === null) {
            return $this->unavailableResponse();
        }

        $data = @json_decode($output, true);

        if (! isset($data[0])) {
            return $this->unavailableResponse();
        }

        $entry = $data[0];

        return [
            'available'  => true,
            'cpu_limit'  => (int)   ($entry['lCPU']  ?? 0),   // in CPU units (1024 = 1 core)
            'mem_limit'  => (int)   ($entry['lPMem'] ?? 0),   // in KB
            'io_limit'   => (float) ($entry['lIO']   ?? 0),
            'ep_limit'   => (int)   ($entry['lEP']   ?? 0),
            'nproc_limit' => (int)  ($entry['lNproc'] ?? 0),
        ];
    }

    /**
     * Execute lvectl with the given arguments.
     * Returns stdout or null on failure.
     */
    private function runLvectl(array $args): ?string
    {
        $escapedBin  = escapeshellarg($this->lvectlBin);
        $escapedArgs = implode(' ', array_map('escapeshellarg', $args));
        $command     = "{$escapedBin} {$escapedArgs} 2>/dev/null";

        $output = shell_exec($command);

        if ($output === null || $output === false) {
            Log::warning("[LVEResourceMonitor] lvectl command returned no output: {$command}");
            return null;
        }

        return $output;
    }

    private function unavailableResponse(): array
    {
        return [
            'available'  => false,
            'cpu_usage'  => null,
            'mem_usage'  => null,
            'io_usage'   => null,
            'ep_usage'   => null,
            'nproc'      => null,
            'cpu_limit'  => null,
            'mem_limit'  => null,
        ];
    }
}
