<?php

namespace App\Services;

use App\Models\ManagedLaravelApp;
use App\Models\SupervisorWorker;
use App\Models\AssignedPort;
use Illuminate\Support\Facades\Log;

class ReverbConfigurator
{
    private PortAllocator $portAllocator;

    public function __construct(PortAllocator $portAllocator)
    {
        $this->portAllocator = $portAllocator;
    }

    /**
     * Build the artisan command for a Reverb worker.
     * Includes correct host, port, and protocol settings.
     */
    public function buildCommand(SupervisorWorker $worker, ManagedLaravelApp $app): string
    {
        $port     = $worker->assignedPort?->port ?? $this->getOrAllocatePort($worker, $app);
        $protocol = $worker->assignedPort?->protocol ?? 'ws';
        $host     = '0.0.0.0';

        $phpBin  = escapeshellarg($app->php_binary);
        $artisan = escapeshellarg($app->artisan_path);

        // Build reverb:start with explicit host and port
        $cmd = "{$phpBin} {$artisan} reverb:start --host={$host} --port={$port} --no-interaction";

        if ($protocol === 'wss') {
            $cmd .= ' --tls';
        }

        return $cmd;
    }

    /**
     * Return the WebSocket URL for a Reverb worker.
     */
    public function getWebSocketUrl(SupervisorWorker $worker): ?string
    {
        return $worker->assignedPort?->getWebSocketUrl();
    }

    /**
     * Generate the BROADCAST_CONNECTION and REVERB_* env vars for a .env file.
     * Returns an associative array of env var name => value.
     */
    public function getEnvVars(SupervisorWorker $worker): array
    {
        $port = $worker->assignedPort;

        if (! $port) {
            return [];
        }

        $appId  = substr(md5($worker->cpanel_user . $worker->id), 0, 20);
        $appKey = bin2hex(random_bytes(16));

        return [
            'BROADCAST_CONNECTION'   => 'reverb',
            'REVERB_APP_ID'          => $appId,
            'REVERB_APP_KEY'         => $appKey,
            'REVERB_APP_SECRET'      => bin2hex(random_bytes(20)),
            'REVERB_HOST'            => $port->domain ?? 'localhost',
            'REVERB_PORT'            => (string) $port->port,
            'REVERB_SCHEME'          => $port->protocol === 'wss' ? 'https' : 'http',
        ];
    }

    /**
     * Update SSL detection for all Reverb workers on a domain.
     */
    public function refreshDomainSsl(string $domain): void
    {
        $this->portAllocator->refreshSslStatus($domain);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getOrAllocatePort(SupervisorWorker $worker, ManagedLaravelApp $app): int
    {
        // Determine the primary domain for the account
        $domain = $this->detectPrimaryDomain($app->cpanel_user);

        $port = $this->portAllocator->allocate($worker, $domain);
        return $port->port;
    }

    /**
     * Read the primary domain from cPanel's userdata.
     */
    private function detectPrimaryDomain(string $cpanelUser): string
    {
        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $cpanelUser)) {
            return $cpanelUser . '.example.com';
        }

        $userDataFile = "/var/cpanel/userdata/{$cpanelUser}/main";

        if (file_exists($userDataFile) && is_readable($userDataFile)) {
            $content = file_get_contents($userDataFile);
            if (preg_match('/^main_domain:\s*(.+)$/m', $content, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fallback: use the username as a subdomain hint
        return $cpanelUser . '.example.com';
    }
}
