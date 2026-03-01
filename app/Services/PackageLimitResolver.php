<?php

namespace App\Services;

use App\Models\PackageLimit;
use App\Models\SupervisorWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class PackageLimitResolver
{
    /**
     * Resolve the package limits for a given cPanel user.
     * Reads their WHM package via the cPanel API, then looks up our PackageLimit record.
     */
    public function resolveForUser(string $cpanelUser): array
    {
        $packageName = $this->getWhmPackageForUser($cpanelUser);
        return $this->resolveForPackage($packageName);
    }

    /**
     * Resolve limits for a named WHM package.
     */
    public function resolveForPackage(string $packageName): array
    {
        $limit = PackageLimit::where('package_name', $packageName)->first();

        if ($limit) {
            return $limit->toLimitsArray();
        }

        // Fall back to config defaults
        Log::debug("[PackageLimitResolver] No limit record for package '{$packageName}', using defaults.");
        return config('supervisor_plugin.default_limits');
    }

    /**
     * Check whether the user can create a new worker of the given type.
     *
     * @throws \RuntimeException with a human-readable message if limit exceeded.
     */
    public function assertCanCreate(string $cpanelUser, string $workerType): void
    {
        $limits  = $this->resolveForUser($cpanelUser);
        $current = $this->countWorkers($cpanelUser);

        // Total limit
        if ($current['total'] >= $limits['max_workers_total']) {
            throw new \RuntimeException(
                "Worker limit reached: maximum {$limits['max_workers_total']} total workers allowed on your plan."
            );
        }

        // Reverb-specific checks
        if ($workerType === 'reverb') {
            if (! $limits['reverb_enabled']) {
                throw new \RuntimeException("Reverb WebSocket workers are not enabled on your hosting plan.");
            }
            if ($current['reverb'] >= $limits['max_reverb_workers']) {
                throw new \RuntimeException(
                    "Reverb worker limit reached: maximum {$limits['max_reverb_workers']} allowed on your plan."
                );
            }
        }

        // Queue
        if ($workerType === 'queue' && $current['queue'] >= $limits['max_queue_workers']) {
            throw new \RuntimeException(
                "Queue worker limit reached: maximum {$limits['max_queue_workers']} allowed on your plan."
            );
        }

        // Scheduler
        if ($workerType === 'scheduler' && $current['scheduler'] >= $limits['max_scheduler_workers']) {
            throw new \RuntimeException(
                "Scheduler worker limit reached: maximum {$limits['max_scheduler_workers']} allowed on your plan."
            );
        }
    }

    /**
     * Check whether the user can add a new managed app.
     *
     * @throws \RuntimeException
     */
    public function assertCanAddApp(string $cpanelUser): void
    {
        $limits  = $this->resolveForUser($cpanelUser);
        $appCount = \App\Models\ManagedLaravelApp::where('cpanel_user', $cpanelUser)->count();

        if ($appCount >= 1 && ! $limits['multi_app_enabled']) {
            throw new \RuntimeException("Multi-app support is not enabled on your hosting plan.");
        }

        if ($limits['multi_app_enabled'] && $appCount >= $limits['max_apps']) {
            throw new \RuntimeException(
                "App limit reached: maximum {$limits['max_apps']} Laravel apps allowed on your plan."
            );
        }
    }

    /**
     * Return worker counts for a cPanel user.
     */
    public function countWorkers(string $cpanelUser): array
    {
        $workers = SupervisorWorker::where('cpanel_user', $cpanelUser)->get();

        return [
            'total'     => $workers->count(),
            'queue'     => $workers->where('type', 'queue')->count(),
            'scheduler' => $workers->where('type', 'scheduler')->count(),
            'reverb'    => $workers->where('type', 'reverb')->count(),
        ];
    }

    /**
     * Return usage statistics with limits for dashboard display.
     */
    public function getUsageWithLimits(string $cpanelUser): array
    {
        $limits  = $this->resolveForUser($cpanelUser);
        $current = $this->countWorkers($cpanelUser);

        return [
            'limits'  => $limits,
            'current' => $current,
            'package' => $this->getWhmPackageForUser($cpanelUser),
        ];
    }

    /**
     * Retrieve the WHM package name assigned to a cPanel user.
     * Reads /var/cpanel/userdata/{user}/main (cPanel user data file).
     */
    public function getWhmPackageForUser(string $cpanelUser): string
    {
        // Validate username
        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            return 'default';
        }

        $cacheKey = "whm_package_{$cpanelUser}";

        return Cache::remember($cacheKey, 300, function () use ($cpanelUser) {
            // Try reading from cPanel's userdata file
            $userDataFile = "/var/cpanel/userdata/{$cpanelUser}/main";

            if (file_exists($userDataFile) && is_readable($userDataFile)) {
                $content = file_get_contents($userDataFile);
                // cPanel userdata is YAML-like; find "plan:" line
                if (preg_match('/^plan:\s*(.+)$/m', $content, $matches)) {
                    return trim($matches[1]);
                }
            }

            // Fallback: use whmapi1 (only available when running as root/reseller)
            $result = $this->callWhmApi('accountsummary', ['user' => $cpanelUser]);
            if (isset($result['data']['acct'][0]['plan'])) {
                return $result['data']['acct'][0]['plan'];
            }

            return 'default';
        });
    }

    /**
     * Return all WHM packages (for admin UI).
     */
    public function getAllWhmPackages(): array
    {
        $result = $this->callWhmApi('listpkgs');

        if (isset($result['data']['pkg']) && is_array($result['data']['pkg'])) {
            return array_column($result['data']['pkg'], 'name');
        }

        return ['default'];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Call the WHM JSON API (whmapi1) via the local socket.
     * Only works when running as root or via setuid helper.
     */
    private function callWhmApi(string $function, array $params = []): array
    {
        $queryString = http_build_query($params);
        $url         = "http://localhost:2086/json-api/{$function}?api.version=1&{$queryString}";

        // Use the root auth token stored by the installer
        $tokenFile = config('supervisor_plugin.storage_path') . '/whm_token';
        $authHeader = '';

        if (file_exists($tokenFile) && is_readable($tokenFile)) {
            $token      = trim(file_get_contents($tokenFile));
            $authHeader = "Authorization: whm root:{$token}";
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => $authHeader,
                'timeout' => 5,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            Log::warning("[PackageLimitResolver] WHM API call failed for function: {$function}");
            return [];
        }

        return json_decode($response, true) ?? [];
    }
}
