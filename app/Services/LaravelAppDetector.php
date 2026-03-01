<?php

namespace App\Services;

use App\Models\ManagedLaravelApp;
use Illuminate\Support\Facades\Log;

class LaravelAppDetector
{
    /**
     * Scan all directories under /home/{user}/ and return detected Laravel apps.
     * Maximum scan depth is 3 to prevent runaway traversal.
     */
    public function detectForUser(string $cpanelUser): array
    {
        $homeDir = $this->safeHomePath($cpanelUser);

        if (! is_dir($homeDir)) {
            Log::warning("[LaravelAppDetector] Home directory not found: {$homeDir}");
            return [];
        }

        $apps = [];
        $this->scanDirectory($homeDir, $cpanelUser, $apps, 0, 3);
        return $apps;
    }

    /**
     * Persist newly detected apps to the database.
     * Returns array of ManagedLaravelApp models (new + existing).
     */
    public function syncForUser(string $cpanelUser): array
    {
        $detected = $this->detectForUser($cpanelUser);
        $synced   = [];

        foreach ($detected as $appData) {
            $model = ManagedLaravelApp::firstOrCreate(
                ['cpanel_user' => $cpanelUser, 'app_path' => $appData['app_path']],
                [
                    'app_name'         => $appData['app_name'],
                    'php_binary'       => $this->detectPhpBinary($cpanelUser),
                    'artisan_path'     => $appData['artisan_path'],
                    'environment'      => 'production',
                    'is_active'        => true,
                    'detected_features' => $appData['features'],
                    'last_scanned_at'  => now(),
                ]
            );

            // Update scan metadata even for existing records
            if (! $model->wasRecentlyCreated) {
                $model->update([
                    'detected_features' => $appData['features'],
                    'last_scanned_at'   => now(),
                ]);
            }

            $synced[] = $model;
        }

        return $synced;
    }

    /**
     * Validate that a given path is a Laravel app owned by the specified user.
     * Throws RuntimeException on validation failure.
     */
    public function validateOwnership(string $cpanelUser, string $appPath): void
    {
        $homeDir     = $this->safeHomePath($cpanelUser);
        $realAppPath = realpath($appPath);
        $realHome    = realpath($homeDir);

        if ($realAppPath === false || $realHome === false) {
            throw new \RuntimeException("Invalid path: {$appPath}");
        }

        // Prevent path traversal and symlink escape
        if (strpos($realAppPath . DIRECTORY_SEPARATOR, $realHome . DIRECTORY_SEPARATOR) !== 0) {
            throw new \RuntimeException("Path traversal detected: {$appPath} is not under {$homeDir}");
        }

        if (! $this->isLaravelApp($realAppPath)) {
            throw new \RuntimeException("Path is not a valid Laravel application: {$appPath}");
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function scanDirectory(
        string $dir,
        string $cpanelUser,
        array  &$apps,
        int    $depth,
        int    $maxDepth
    ): void {
        if ($depth > $maxDepth) {
            return;
        }

        // Safety: prevent scanning outside home
        $realDir  = realpath($dir);
        $realHome = realpath($this->safeHomePath($cpanelUser));

        if ($realDir === false || $realHome === false) {
            return;
        }

        if (strpos($realDir . '/', $realHome . '/') !== 0) {
            return; // Symlink escape – skip
        }

        if ($this->isLaravelApp($dir)) {
            $apps[] = $this->buildAppData($cpanelUser, $dir);
            return; // Don't recurse into a Laravel app
        }

        try {
            $entries = @scandir($dir);
        } catch (\Throwable $e) {
            return;
        }

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }

            $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_link($fullPath)) {
                continue; // Skip symlinks for security
            }

            if (is_dir($fullPath)) {
                $this->scanDirectory($fullPath, $cpanelUser, $apps, $depth + 1, $maxDepth);
            }
        }
    }

    private function isLaravelApp(string $dir): bool
    {
        return file_exists($dir . '/artisan')
            && file_exists($dir . '/composer.json')
            && (
                file_exists($dir . '/bootstrap/app.php')
                || file_exists($dir . '/app/Http/Kernel.php')
            );
    }

    private function buildAppData(string $cpanelUser, string $appPath): array
    {
        $name     = basename($appPath);
        $features = $this->detectFeatures($appPath);

        return [
            'app_name'    => $name,
            'app_path'    => $appPath,
            'artisan_path' => $appPath . '/artisan',
            'features'    => $features,
            'cpanel_user' => $cpanelUser,
        ];
    }

    private function detectFeatures(string $appPath): array
    {
        $composerJson = $appPath . '/composer.json';
        $features     = [
            'has_queue'     => false,
            'has_scheduler' => false,
            'has_reverb'    => false,
            'laravel_version' => null,
        ];

        if (! file_exists($composerJson)) {
            return $features;
        }

        $json = @json_decode(file_get_contents($composerJson), true);
        if (! is_array($json)) {
            return $features;
        }

        $require = array_merge(
            $json['require']     ?? [],
            $json['require-dev'] ?? []
        );

        $features['has_reverb']    = isset($require['laravel/reverb']);
        $features['has_queue']     = true; // All Laravel apps support queues
        $features['has_scheduler'] = true; // All Laravel apps support scheduler

        if (isset($require['laravel/framework'])) {
            $features['laravel_version'] = $require['laravel/framework'];
        }

        return $features;
    }

    private function detectPhpBinary(string $cpanelUser): string
    {
        // Try cPanel EA PHP paths first
        $candidates = [
            "/opt/cpanel/ea-php82/root/usr/bin/php",
            "/opt/cpanel/ea-php81/root/usr/bin/php",
            "/opt/cpanel/ea-php80/root/usr/bin/php",
            "/usr/bin/php",
            "/usr/local/bin/php",
        ];

        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return '/usr/bin/php';
    }

    /**
     * Return a validated, safe home directory path.
     * Only alphanumeric + underscore + hyphen are allowed in usernames.
     */
    private function safeHomePath(string $cpanelUser): string
    {
        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            throw new \InvalidArgumentException("Invalid cPanel username: {$cpanelUser}");
        }

        return "/home/{$cpanelUser}";
    }
}
