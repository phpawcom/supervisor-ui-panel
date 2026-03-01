<?php

namespace App\Http\Controllers\CPanel;

use App\Http\Controllers\Controller;
use App\Models\ManagedLaravelApp;
use App\Models\SupervisorWorker;
use App\Services\LaravelAppDetector;
use App\Services\LVEResourceMonitor;
use App\Services\PackageLimitResolver;
use App\Services\PortAllocator;
use App\Services\ReverbConfigurator;
use App\Services\SupervisorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WorkerController extends Controller
{
    public function __construct(
        private readonly SupervisorManager    $supervisorManager,
        private readonly PackageLimitResolver $packageLimitResolver,
        private readonly PortAllocator        $portAllocator,
        private readonly ReverbConfigurator   $reverbConfigurator,
        private readonly LVEResourceMonitor   $lveMonitor,
        private readonly LaravelAppDetector   $appDetector,
    ) {}

    /**
     * List all workers for the user.
     */
    public function index(Request $request)
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        $workers = SupervisorWorker::with(['app', 'assignedPort'])
            ->where('cpanel_user', $cpanelUser)
            ->orderBy('type')
            ->orderBy('created_at')
            ->get();

        $apps = ManagedLaravelApp::where('cpanel_user', $cpanelUser)
            ->where('is_active', true)
            ->get();

        $usage = $this->packageLimitResolver->getUsageWithLimits($cpanelUser);

        return view('cpanel.workers.index', [
            'cpanel_user' => $cpanelUser,
            'workers'     => $workers,
            'apps'        => $apps,
            'usage'       => $usage,
        ]);
    }

    /**
     * Show the create worker form.
     */
    public function create(Request $request)
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        $apps   = ManagedLaravelApp::where('cpanel_user', $cpanelUser)
            ->where('is_active', true)
            ->get();
        $usage  = $this->packageLimitResolver->getUsageWithLimits($cpanelUser);
        $limits = $this->packageLimitResolver->resolveForUser($cpanelUser);
        $lve    = $this->lveMonitor->checkResourceSafety($cpanelUser);

        // Auto-detect apps if none registered
        if ($apps->isEmpty()) {
            $apps = collect($this->appDetector->syncForUser($cpanelUser));
        }

        return view('cpanel.workers.create', [
            'cpanel_user' => $cpanelUser,
            'apps'        => $apps,
            'usage'       => $usage,
            'limits'      => $limits,
            'lve'         => $lve,
        ]);
    }

    /**
     * Store a new worker.
     */
    public function store(Request $request): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        $validator = Validator::make($request->all(), [
            'app_id'           => 'required|integer',
            'type'             => 'required|in:queue,scheduler,reverb',
            'name'             => 'required|string|max:100|regex:/^[a-zA-Z0-9 _\-]+$/',
            'queue_connection' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_\-]+$/',
            'numprocs'         => 'nullable|integer|min:1|max:5',
            'tries'            => 'nullable|integer|min:1|max:10',
            'timeout'          => 'nullable|integer|min:10|max:3600',
            'memory'           => 'nullable|integer|min:64|max:1024',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Verify app ownership
        $app = ManagedLaravelApp::where('id', $request->input('app_id'))
            ->where('cpanel_user', $cpanelUser)
            ->first();

        if (! $app) {
            return response()->json(['success' => false, 'error' => 'App not found or access denied.'], 404);
        }

        // Validate app is actually a Laravel app owned by this user
        try {
            $this->appDetector->validateOwnership($cpanelUser, $app->app_path);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 403);
        }

        // Check package limits
        try {
            $this->packageLimitResolver->assertCanCreate($cpanelUser, $request->input('type'));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 403);
        }

        // Check LVE resource safety
        $lveSafety = $this->lveMonitor->checkResourceSafety($cpanelUser);
        if (! $lveSafety['safe']) {
            return response()->json([
                'success' => false,
                'error'   => $lveSafety['reason'],
            ], 429);
        }

        $workerConfig = [
            'name'             => $request->input('name'),
            'numprocs'         => (int) $request->input('numprocs', 1),
            'queue_connection' => $request->input('queue_connection', 'default'),
            'tries'            => (int) $request->input('tries', 3),
            'timeout'          => (int) $request->input('timeout', 60),
            'memory'           => (int) $request->input('memory', 128),
        ];

        try {
            $worker = $this->supervisorManager->createWorker($app, $request->input('type'), $workerConfig);

            // For Reverb workers, allocate a port
            if ($worker->type === 'reverb') {
                $domain = $request->input('domain', '');
                $this->portAllocator->allocate($worker, $domain);

                // Reload worker to get port
                $worker->refresh();
            }

            return response()->json([
                'success'    => true,
                'worker_id'  => $worker->id,
                'message'    => "Worker '{$worker->worker_name}' created successfully.",
                'process'    => $worker->process_name,
                'port'       => $worker->assignedPort?->port,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a single worker detail page.
     */
    public function show(Request $request, SupervisorWorker $worker)
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        // EnsureAccountIsolation also handles this, but be explicit
        if ($worker->cpanel_user !== $cpanelUser) {
            abort(403);
        }

        $worker->load(['app', 'assignedPort']);

        $statuses = $this->supervisorManager->getStatusForUser($cpanelUser);
        $status   = collect($statuses)->firstWhere('process_name', $worker->process_name);

        return view('cpanel.workers.show', [
            'cpanel_user' => $cpanelUser,
            'worker'      => $worker,
            'status'      => $status,
        ]);
    }

    /**
     * Delete a worker.
     */
    public function destroy(Request $request, SupervisorWorker $worker): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if ($worker->cpanel_user !== $cpanelUser) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        try {
            // Release port if reverb
            if ($worker->type === 'reverb') {
                $this->portAllocator->release($worker);
            }

            $this->supervisorManager->deleteWorker($worker);

            return response()->json(['success' => true, 'message' => 'Worker deleted.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Restart a worker process.
     */
    public function restart(Request $request, SupervisorWorker $worker): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if ($worker->cpanel_user !== $cpanelUser) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        try {
            $result = $this->supervisorManager->restartProcess($cpanelUser, $worker->process_name);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Stop a worker process.
     */
    public function stop(Request $request, SupervisorWorker $worker): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if ($worker->cpanel_user !== $cpanelUser) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        try {
            $result = $this->supervisorManager->stopProcess($cpanelUser, $worker->process_name);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Start a worker process.
     */
    public function start(Request $request, SupervisorWorker $worker): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if ($worker->cpanel_user !== $cpanelUser) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        try {
            $result = $this->supervisorManager->startProcess($cpanelUser, $worker->process_name);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch log tail for a worker.
     */
    public function logs(Request $request, SupervisorWorker $worker): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if ($worker->cpanel_user !== $cpanelUser) {
            return response()->json(['error' => 'Access denied.'], 403);
        }

        $lines = (int) $request->input('lines', 50);

        try {
            $logs = $this->supervisorManager->getLogTail($cpanelUser, $worker->id, $lines);
            return response()->json(['success' => true, 'logs' => $logs]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
