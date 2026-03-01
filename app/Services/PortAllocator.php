<?php

namespace App\Services;

use App\Models\AssignedPort;
use App\Models\SupervisorWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PortAllocator
{
    private int $rangeStart;
    private int $rangeEnd;
    private array $reservedPorts;

    public function __construct()
    {
        $this->rangeStart    = (int) config('supervisor_plugin.reverb.port_range_start', 20000);
        $this->rangeEnd      = (int) config('supervisor_plugin.reverb.port_range_end', 21000);
        $this->reservedPorts = array_filter((array) config('supervisor_plugin.reverb.reserved_ports', []));
    }

    /**
     * Allocate a port for a Reverb worker.
     * Uses a database transaction to prevent race conditions.
     *
     * @throws \RuntimeException if no port is available.
     */
    public function allocate(SupervisorWorker $worker, string $domain): AssignedPort
    {
        return DB::transaction(function () use ($worker, $domain) {
            // Idempotent: return existing if already allocated
            if ($worker->assignedPort) {
                return $worker->assignedPort;
            }

            $port = $this->findFreePort();

            $sslMode = config('supervisor_plugin.reverb.ssl_mode', 'auto');
            [$sslDetected, $protocol] = $this->resolveProtocol($domain, $sslMode);

            $assigned = AssignedPort::create([
                'supervisor_worker_id' => $worker->id,
                'cpanel_user'          => $worker->cpanel_user,
                'port'                 => $port,
                'domain'               => $domain,
                'ssl_detected'         => $sslDetected,
                'protocol'             => $protocol,
                'is_active'            => true,
            ]);

            Log::info("[PortAllocator] Allocated port {$port} for worker {$worker->id} ({$worker->cpanel_user})");

            return $assigned;
        });
    }

    /**
     * Release a port back to the pool.
     */
    public function release(SupervisorWorker $worker): void
    {
        $port = $worker->assignedPort;
        if (! $port) {
            return;
        }

        Log::info("[PortAllocator] Releasing port {$port->port} for worker {$worker->id}");
        $port->delete();
    }

    /**
     * Return all currently allocated ports.
     */
    public function getAllocated(): array
    {
        return AssignedPort::pluck('port')->toArray();
    }

    /**
     * Return a summary of port usage for WHM admin.
     */
    public function getPortUsageSummary(): array
    {
        $allocated = AssignedPort::with('worker')
            ->where('is_active', true)
            ->orderBy('port')
            ->get();

        return [
            'range_start' => $this->rangeStart,
            'range_end'   => $this->rangeEnd,
            'total'       => $this->rangeEnd - $this->rangeStart + 1,
            'used'        => $allocated->count(),
            'free'        => ($this->rangeEnd - $this->rangeStart + 1) - $allocated->count(),
            'ports'       => $allocated->map(fn($p) => [
                'port'        => $p->port,
                'user'        => $p->cpanel_user,
                'domain'      => $p->domain,
                'protocol'    => $p->protocol,
                'worker_id'   => $p->supervisor_worker_id,
            ])->toArray(),
        ];
    }

    /**
     * Update SSL/protocol status for a domain (called after SSL cert changes).
     */
    public function refreshSslStatus(string $domain): void
    {
        $ports = AssignedPort::where('domain', $domain)->get();

        foreach ($ports as $port) {
            $sslMode = config('supervisor_plugin.reverb.ssl_mode', 'auto');
            [$sslDetected, $protocol] = $this->resolveProtocol($domain, $sslMode);

            $port->update([
                'ssl_detected' => $sslDetected,
                'protocol'     => $protocol,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find the lowest free port in the configured range.
     *
     * @throws \RuntimeException
     */
    private function findFreePort(): int
    {
        $usedPorts = AssignedPort::whereBetween('port', [$this->rangeStart, $this->rangeEnd])
            ->pluck('port')
            ->toArray();

        $usedPorts = array_merge($usedPorts, $this->reservedPorts);
        $usedPorts = array_unique($usedPorts);

        for ($port = $this->rangeStart; $port <= $this->rangeEnd; $port++) {
            if (! in_array($port, $usedPorts, true)) {
                return $port;
            }
        }

        throw new \RuntimeException(
            "No free ports available in range {$this->rangeStart}–{$this->rangeEnd}. "
            . count($usedPorts) . " ports already used."
        );
    }

    /**
     * Resolve the WebSocket protocol based on SSL detection mode.
     *
     * @return array{bool, string} [ssl_detected, protocol]
     */
    private function resolveProtocol(string $domain, string $sslMode): array
    {
        if ($sslMode === 'force_https') {
            return [true, 'wss'];
        }

        if ($sslMode === 'force_http') {
            return [false, 'ws'];
        }

        // Auto-detect: check if a valid SSL certificate exists for this domain
        $sslDetected = $this->checkSslCertExists($domain);
        $protocol    = $sslDetected ? 'wss' : 'ws';

        return [$sslDetected, $protocol];
    }

    /**
     * Check whether a valid cPanel SSL certificate exists for the domain.
     * Uses cPanel's certificate storage structure.
     */
    private function checkSslCertExists(string $domain): bool
    {
        if (empty($domain)) {
            return false;
        }

        // Sanitize domain
        $domain = preg_replace('/[^a-z0-9.\-]/i', '', $domain);

        // cPanel stores SSL in /var/cpanel/userdata/{user}/ssl/{domain}.crt style,
        // or we can check the live cert via openssl
        $certPaths = [
            "/var/cpanel/ssl/installed/certs/{$domain}.crt",
            "/etc/letsencrypt/live/{$domain}/fullchain.pem",
            "/var/cpanel/ssl/installed/keys/{$domain}.key",
        ];

        foreach ($certPaths as $path) {
            if (file_exists($path) && filesize($path) > 0) {
                return true;
            }
        }

        // Try socket check as last resort (with short timeout)
        return $this->checkSslSocket($domain);
    }

    private function checkSslSocket(string $domain): bool
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
                'capture_peer_cert' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$domain}:443",
            $errno,
            $errstr,
            3,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);
        return true;
    }
}
