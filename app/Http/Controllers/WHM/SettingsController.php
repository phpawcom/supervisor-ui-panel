<?php

namespace App\Http\Controllers\WHM;

use App\Http\Controllers\Controller;
use App\Models\SupervisorWorker;
use App\Models\AssignedPort;
use App\Models\ManagedLaravelApp;
use App\Services\LVEResourceMonitor;
use App\Services\PackageLimitResolver;
use App\Services\PortAllocator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingsController extends Controller
{
    public function __construct(
        private readonly PortAllocator        $portAllocator,
        private readonly PackageLimitResolver $packageLimitResolver,
        private readonly LVEResourceMonitor   $lveMonitor,
    ) {}

    /**
     * WHM Settings & overview page.
     */
    public function index(Request $request)
    {
        $portUsage   = $this->portAllocator->getPortUsageSummary();
        $lveAvailable = $this->lveMonitor->isAvailable();

        // Global statistics
        $stats = [
            'total_workers'  => SupervisorWorker::count(),
            'active_workers' => SupervisorWorker::where('desired_state', 'running')->count(),
            'total_apps'     => ManagedLaravelApp::count(),
            'total_users'    => SupervisorWorker::distinct('cpanel_user')->count('cpanel_user'),
            'reverb_workers' => SupervisorWorker::where('type', 'reverb')->count(),
            'ports_used'     => $portUsage['used'],
            'ports_free'     => $portUsage['free'],
        ];

        $workersByUser = SupervisorWorker::with(['app', 'assignedPort'])
            ->orderBy('cpanel_user')
            ->get()
            ->groupBy('cpanel_user');

        return view('whm.settings.index', [
            'whm_admin'     => $request->attributes->get('whm_admin'),
            'stats'         => $stats,
            'port_usage'    => $portUsage,
            'workers'       => $workersByUser,
            'lve_available' => $lveAvailable,
        ]);
    }

    /**
     * Update global plugin settings.
     */
    public function updateGlobalSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reverb_port_start'   => 'required|integer|min:10000|max:60000',
            'reverb_port_end'     => 'required|integer|min:10001|max:60001',
            'reverb_ssl_mode'     => 'required|in:auto,force_https,force_http',
            'reverb_reserved_ports' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $portStart = (int) $request->input('reverb_port_start');
        $portEnd   = (int) $request->input('reverb_port_end');

        if ($portEnd <= $portStart) {
            return response()->json([
                'success' => false,
                'error'   => 'Port range end must be greater than port range start.',
            ], 422);
        }

        // Parse and validate reserved ports
        $reservedRaw = $request->input('reverb_reserved_ports', '');
        $reserved    = [];
        foreach (explode(',', $reservedRaw) as $p) {
            $p = trim($p);
            if (! empty($p) && is_numeric($p)) {
                $port = (int) $p;
                if ($port >= $portStart && $port <= $portEnd) {
                    $reserved[] = $port;
                }
            }
        }

        // Write to .env file via helper
        $settings = [
            'REVERB_PORT_START'      => $portStart,
            'REVERB_PORT_END'        => $portEnd,
            'REVERB_SSL_MODE'        => $request->input('reverb_ssl_mode'),
            'REVERB_RESERVED_PORTS'  => implode(',', $reserved),
        ];

        $this->updateEnvFile($settings);

        return response()->json([
            'success'  => true,
            'message'  => 'Global settings updated. Changes apply to new port allocations.',
            'settings' => $settings,
        ]);
    }

    /**
     * Admin: view all workers across all users.
     */
    public function allWorkers(Request $request): JsonResponse
    {
        $workers = SupervisorWorker::with(['app', 'assignedPort'])
            ->orderBy('cpanel_user')
            ->orderBy('type')
            ->get()
            ->map(fn($w) => [
                'id'           => $w->id,
                'user'         => $w->cpanel_user,
                'type'         => $w->type,
                'name'         => $w->worker_name,
                'process'      => $w->process_name,
                'app_path'     => $w->app?->app_path,
                'desired_state' => $w->desired_state,
                'port'         => $w->assignedPort?->port,
                'created_at'   => $w->created_at?->toIso8601String(),
            ]);

        return response()->json(['workers' => $workers]);
    }

    /**
     * Admin: Port usage summary.
     */
    public function portUsage(): JsonResponse
    {
        return response()->json($this->portAllocator->getPortUsageSummary());
    }

    /**
     * Admin: force-release a port.
     */
    public function releasePort(Request $request, int $port): JsonResponse
    {
        $assigned = AssignedPort::where('port', $port)->first();

        if (! $assigned) {
            return response()->json(['success' => false, 'error' => 'Port not found.'], 404);
        }

        $assigned->delete();

        return response()->json(['success' => true, 'message' => "Port {$port} released."]);
    }

    // -------------------------------------------------------------------------

    /**
     * Update .env key-value pairs atomically.
     */
    private function updateEnvFile(array $settings): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath) || ! is_writable($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);

        foreach ($settings as $key => $value) {
            $key   = preg_replace('/[^A-Z0-9_]/', '', strtoupper($key));
            $value = str_replace(['"', "'", "\n"], '', (string) $value);

            if (preg_match("/^{$key}=.*/m", $content)) {
                $content = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
            } else {
                $content .= PHP_EOL . "{$key}={$value}";
            }
        }

        file_put_contents($envPath, $content, LOCK_EX);
    }
}
