<?php

namespace App\Http\Controllers\WHM;

use App\Http\Controllers\Controller;
use App\Models\PackageLimit;
use App\Services\PackageLimitResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PackageController extends Controller
{
    public function __construct(
        private readonly PackageLimitResolver $packageLimitResolver,
    ) {}

    /**
     * List all WHM packages and their configured limits.
     */
    public function index(Request $request)
    {
        $whmPackages    = $this->packageLimitResolver->getAllWhmPackages();
        $configuredLimits = PackageLimit::orderBy('package_name')->get()->keyBy('package_name');
        $defaults       = config('supervisor_plugin.default_limits');

        $packages = array_map(function ($packageName) use ($configuredLimits, $defaults) {
            $limit = $configuredLimits->get($packageName);
            return [
                'name'       => $packageName,
                'configured' => $limit !== null,
                'limits'     => $limit ? $limit->toLimitsArray() : $defaults,
                'model'      => $limit,
            ];
        }, $whmPackages);

        return view('whm.packages.index', [
            'packages'  => $packages,
            'defaults'  => $defaults,
            'whm_admin' => $request->attributes->get('whm_admin'),
        ]);
    }

    /**
     * Show edit form for a package limit.
     */
    public function edit(Request $request, string $packageName)
    {
        $limit    = PackageLimit::firstOrNew(['package_name' => $packageName]);
        $defaults = config('supervisor_plugin.default_limits');

        return view('whm.packages.edit', [
            'package_name' => $packageName,
            'limit'        => $limit,
            'defaults'     => $defaults,
            'whm_admin'    => $request->attributes->get('whm_admin'),
        ]);
    }

    /**
     * Save / update package limits.
     */
    public function upsert(Request $request, string $packageName): JsonResponse
    {
        // Validate package name
        if (! preg_match('/^[a-zA-Z0-9_\-. ]{1,128}$/', $packageName)) {
            return response()->json(['error' => 'Invalid package name.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'max_workers_total'        => 'required|integer|min:0|max:50',
            'max_queue_workers'        => 'required|integer|min:0|max:20',
            'max_scheduler_workers'    => 'required|integer|min:0|max:5',
            'max_reverb_workers'       => 'required|integer|min:0|max:10',
            'reverb_enabled'           => 'required|boolean',
            'multi_app_enabled'        => 'required|boolean',
            'max_apps'                 => 'required|integer|min:1|max:20',
            'notes'                    => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $limit = PackageLimit::updateOrCreate(
            ['package_name' => $packageName],
            $data
        );

        return response()->json([
            'success' => true,
            'message' => "Limits updated for package '{$packageName}'.",
            'limits'  => $limit->toLimitsArray(),
        ]);
    }

    /**
     * Delete limits for a package (resets to defaults).
     */
    public function destroy(string $packageName): JsonResponse
    {
        if (! preg_match('/^[a-zA-Z0-9_\-. ]{1,128}$/', $packageName)) {
            return response()->json(['error' => 'Invalid package name.'], 422);
        }

        $deleted = PackageLimit::where('package_name', $packageName)->delete();

        return response()->json([
            'success' => $deleted > 0,
            'message' => $deleted > 0
                ? "Limits for '{$packageName}' have been reset to defaults."
                : "No configured limits found for '{$packageName}'.",
        ]);
    }

    /**
     * Get limits for a specific package (JSON API).
     */
    public function show(string $packageName): JsonResponse
    {
        if (! preg_match('/^[a-zA-Z0-9_\-. ]{1,128}$/', $packageName)) {
            return response()->json(['error' => 'Invalid package name.'], 422);
        }

        $limits = $this->packageLimitResolver->resolveForPackage($packageName);

        return response()->json([
            'package_name' => $packageName,
            'limits'       => $limits,
        ]);
    }
}
