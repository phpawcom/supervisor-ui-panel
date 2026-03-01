<?php

namespace App\Http\Controllers\CPanel;

use App\Http\Controllers\Controller;
use App\Services\LaravelAppDetector;
use App\Services\LVEResourceMonitor;
use App\Services\PackageLimitResolver;
use App\Services\SupervisorManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly SupervisorManager    $supervisorManager,
        private readonly PackageLimitResolver $packageLimitResolver,
        private readonly LVEResourceMonitor   $lveMonitor,
        private readonly LaravelAppDetector   $appDetector,
    ) {}

    /**
     * Main dashboard view.
     */
    public function index(Request $request)
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        $workerStatuses = $this->supervisorManager->getStatusForUser($cpanelUser);
        $usageWithLimits = $this->packageLimitResolver->getUsageWithLimits($cpanelUser);
        $lveUsage       = $this->lveMonitor->getUsage($cpanelUser);
        $lveLimits      = $this->lveMonitor->getLimits($cpanelUser);

        return view('cpanel.dashboard', [
            'cpanel_user'     => $cpanelUser,
            'worker_statuses' => $workerStatuses,
            'usage'           => $usageWithLimits,
            'lve_usage'       => $lveUsage,
            'lve_limits'      => $lveLimits,
            'lve_available'   => $this->lveMonitor->isAvailable(),
        ]);
    }

    /**
     * API: Poll worker statuses (JSON, for live refresh).
     */
    public function pollStatus(Request $request): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        $statuses = $this->supervisorManager->getStatusForUser($cpanelUser);

        return response()->json([
            'statuses'   => $statuses,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * API: Get LVE resource usage (JSON).
     */
    public function lveStatus(Request $request): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if (! $this->lveMonitor->isAvailable()) {
            return response()->json(['available' => false]);
        }

        return response()->json([
            'available' => true,
            'usage'     => $this->lveMonitor->getUsage($cpanelUser),
            'limits'    => $this->lveMonitor->getLimits($cpanelUser),
            'safety'    => $this->lveMonitor->checkResourceSafety($cpanelUser),
        ]);
    }

    /**
     * Scan and list detected Laravel apps for the user.
     */
    public function scanApps(Request $request): JsonResponse
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        try {
            $apps = $this->appDetector->syncForUser($cpanelUser);

            return response()->json([
                'success' => true,
                'apps'    => $apps->map(fn($a) => [
                    'id'       => $a->id,
                    'name'     => $a->app_name,
                    'path'     => $a->app_path,
                    'features' => $a->detected_features,
                ])->values(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
