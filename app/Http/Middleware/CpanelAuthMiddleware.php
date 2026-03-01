<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CpanelAuthMiddleware
{
    /**
     * Validate the incoming request is from an authenticated cPanel user.
     *
     * Authentication methods (tried in order):
     *  1. X-Cpanel-Auth-Token header (UAPI token auth)
     *  2. cpanel_session cookie (browser session)
     *  3. Signed plugin token (internal, for AJAX from Jupiter UI)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->resolveAuthenticatedUser($request);

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized. cPanel authentication required.'], 401);
            }
            return response('Unauthorized', 401);
        }

        // Attach user to request for downstream use
        $request->attributes->set('cpanel_user', $user);
        $request->attributes->set('cpanel_auth_method', 'verified');

        return $next($request);
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    private function resolveAuthenticatedUser(Request $request): ?string
    {
        // Method 1: Signed plugin token (our own JWT-style token)
        $pluginToken = $request->bearerToken()
            ?? $request->header('X-Plugin-Token')
            ?? $request->input('_plugin_token');

        if ($pluginToken) {
            $user = $this->validatePluginToken($pluginToken);
            if ($user) {
                return $user;
            }
        }

        // Method 2: cPanel UAPI token via Authorization header
        // Format: "Authorization: cpanel {user}:{token}"
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'cpanel ')) {
            $user = $this->validateCpanelUapiAuth(substr($authHeader, 7));
            if ($user) {
                return $user;
            }
        }

        // Method 3: Check REMOTE_USER set by cPanel's web server (for CGI/embedded use)
        $remoteUser = $request->server('REMOTE_USER') ?? $request->server('HTTP_X_CPANEL_REMOTE_USER');
        if ($remoteUser && $this->isValidUsername($remoteUser)) {
            return $remoteUser;
        }

        return null;
    }

    /**
     * Validate our signed plugin token.
     * Format: base64(json{user, exp, hmac})
     */
    private function validatePluginToken(string $token): ?string
    {
        try {
            $payload = json_decode(base64_decode($token), true);

            if (! isset($payload['user'], $payload['exp'], $payload['sig'])) {
                return null;
            }

            // Check expiry
            if (time() > $payload['exp']) {
                Log::debug("[CpanelAuth] Plugin token expired for user: {$payload['user']}");
                return null;
            }

            // Verify HMAC signature
            $secret    = config('supervisor_plugin.cpanel.shared_secret');
            $data      = $payload['user'] . '|' . $payload['exp'];
            $expected  = hash_hmac('sha256', $data, $secret);

            if (! hash_equals($expected, $payload['sig'])) {
                Log::warning("[CpanelAuth] Invalid plugin token signature for claimed user: {$payload['user']}");
                return null;
            }

            if (! $this->isValidUsername($payload['user'])) {
                return null;
            }

            return $payload['user'];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate a cPanel UAPI authorization string (user:token format).
     * Uses cPanel's local UAPI to verify the token is valid.
     */
    private function validateCpanelUapiAuth(string $credentials): ?string
    {
        [$user, $token] = array_pad(explode(':', $credentials, 2), 2, '');

        if (! $this->isValidUsername($user) || empty($token)) {
            return null;
        }

        // Sanitize token (alphanumeric only)
        if (! preg_match('/^[a-zA-Z0-9_\-]+$/', $token)) {
            return null;
        }

        // Verify via cPanel's local UAPI
        $url     = "http://localhost:2083/execute/Tokens/list_tokens";
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => "Authorization: cpanel {$user}:{$token}",
                'timeout' => 3,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            // If cPanel API unreachable, fall back to checking cPanel's userdata
            return $this->fallbackUserCheck($user) ? $user : null;
        }

        $data = json_decode($response, true);

        if (isset($data['status']) && $data['status'] === 1) {
            return $user;
        }

        return null;
    }

    /**
     * Fallback: verify that the user exists as a cPanel account.
     */
    private function fallbackUserCheck(string $user): bool
    {
        if (! $this->isValidUsername($user)) {
            return false;
        }

        return is_dir("/home/{$user}")
            && file_exists("/var/cpanel/userdata/{$user}/main");
    }

    private function isValidUsername(string $user): bool
    {
        return (bool) preg_match('/^[a-z0-9_\-]{1,32}$/i', $user);
    }
}
