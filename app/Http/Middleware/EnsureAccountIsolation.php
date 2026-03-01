<?php

namespace App\Http\Middleware;

use App\Models\SupervisorWorker;
use App\Models\ManagedLaravelApp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsolation
{
    /**
     * Enforce that the authenticated cPanel user can only access their own resources.
     * This runs AFTER CpanelAuthMiddleware has set cpanel_user on the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $cpanelUser = $request->attributes->get('cpanel_user');

        if (! $cpanelUser) {
            return response()->json(['error' => 'Authentication context missing.'], 403);
        }

        // Check route-model-bound worker
        $worker = $request->route('worker');
        if ($worker instanceof SupervisorWorker) {
            if ($worker->cpanel_user !== $cpanelUser) {
                return response()->json([
                    'error' => 'Access denied: this worker does not belong to your account.',
                ], 403);
            }
        }

        // Check route-model-bound app
        $app = $request->route('app');
        if ($app instanceof ManagedLaravelApp) {
            if ($app->cpanel_user !== $cpanelUser) {
                return response()->json([
                    'error' => 'Access denied: this app does not belong to your account.',
                ], 403);
            }
        }

        // Check user param in request body/query
        $requestedUser = $request->input('cpanel_user')
            ?? $request->input('user')
            ?? $request->route('cpanel_user');

        if ($requestedUser && $requestedUser !== $cpanelUser) {
            return response()->json([
                'error' => 'Access denied: you cannot perform operations for another user.',
            ], 403);
        }

        return $next($request);
    }
}
