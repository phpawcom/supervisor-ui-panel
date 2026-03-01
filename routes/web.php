<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Plugin Auth Token Endpoint
|--------------------------------------------------------------------------
| Called by the cPanel Jupiter entry point (cpanel-plugin/jupiter/index.html)
| to exchange a cPanel session credential for a short-lived plugin token.
|
| Rate-limited to prevent brute-force attempts.
*/
Route::post('/cpanel-plugins/supervisormanager/api/auth/token', function (\Illuminate\Http\Request $request) {
    $user  = null;
    $role  = 'cpanel_user';

    // Validate the incoming cPanel credential
    $authHeader = $request->header('Authorization', '');

    if (str_starts_with($authHeader, 'cpanel ')) {
        $creds = explode(':', substr($authHeader, 7), 2);
        $user  = $creds[0] ?? null;
        // Basic username sanity check
        if (! $user || ! preg_match('/^[a-z0-9_\-]{1,32}$/i', $user)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
    }

    if (! $user) {
        return response()->json(['error' => 'No credentials provided'], 401);
    }

    // Generate token via privileged helper
    $phpBin  = escapeshellarg(config('supervisor_plugin.php_bin', '/usr/bin/php'));
    $script  = escapeshellarg(base_path('scripts/generate_token.php'));
    $token   = shell_exec("{$phpBin} {$script} " . escapeshellarg($role) . ' ' . escapeshellarg($user));

    if (empty(trim($token ?? ''))) {
        return response()->json(['error' => 'Token generation failed'], 500);
    }

    return response()->json(['token' => trim($token), 'expires_in' => 3600]);
})->middleware('throttle:20,1');
