<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WhmAuthMiddleware
{
    /**
     * Validate the request is from an authenticated WHM admin (root or reseller).
     *
     * Authentication methods:
     *  1. WHM access hash (root hash token)
     *  2. WHM session cookie
     *  3. Signed plugin token with 'whm_admin' role
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $this->resolveAuthenticatedAdmin($request);

        if (! $admin) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized. WHM admin authentication required.'], 401);
            }
            return response('Unauthorized — WHM Admin Required', 401);
        }

        $request->attributes->set('whm_admin', $admin['user']);
        $request->attributes->set('whm_is_root', $admin['is_root']);
        $request->attributes->set('whm_reseller', $admin['reseller'] ?? null);

        return $next($request);
    }

    // -------------------------------------------------------------------------

    private function resolveAuthenticatedAdmin(Request $request): ?array
    {
        // Method 1: Plugin admin token
        $pluginToken = $request->bearerToken()
            ?? $request->header('X-Plugin-Admin-Token')
            ?? $request->input('_admin_token');

        if ($pluginToken) {
            return $this->validateAdminPluginToken($pluginToken);
        }

        // Method 2: WHM hash auth header
        // Format: "Authorization: WHM root:{hash}"
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'WHM ')) {
            return $this->validateWhmHashAuth(substr($authHeader, 4));
        }

        // Method 3: REMOTE_USER set to root by web server
        $remoteUser = $request->server('REMOTE_USER');
        if ($remoteUser === 'root') {
            return ['user' => 'root', 'is_root' => true];
        }

        return null;
    }

    private function validateAdminPluginToken(string $token): ?array
    {
        try {
            $payload = json_decode(base64_decode($token), true);

            if (! isset($payload['user'], $payload['exp'], $payload['sig'], $payload['role'])) {
                return null;
            }

            if ($payload['role'] !== 'whm_admin') {
                return null;
            }

            if (time() > $payload['exp']) {
                return null;
            }

            $secret   = config('supervisor_plugin.cpanel.shared_secret');
            $data     = $payload['user'] . '|' . $payload['exp'] . '|' . $payload['role'];
            $expected = hash_hmac('sha256', $data, $secret);

            if (! hash_equals($expected, $payload['sig'])) {
                Log::warning("[WhmAuth] Invalid admin token signature for user: {$payload['user']}");
                return null;
            }

            return [
                'user'     => $payload['user'],
                'is_root'  => $payload['user'] === 'root',
                'reseller' => $payload['reseller'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Validate WHM hash authentication.
     * Reads root's access hash from /root/.accesshash.
     */
    private function validateWhmHashAuth(string $credentials): ?array
    {
        [$user, $hash] = array_pad(explode(':', $credentials, 2), 2, '');

        if (empty($user) || empty($hash)) {
            return null;
        }

        // Only allow root or known resellers
        if ($user !== 'root' && ! $this->isKnownReseller($user)) {
            return null;
        }

        // Read the stored access hash
        $hashFile = $user === 'root'
            ? '/root/.accesshash'
            : "/var/cpanel/resellers/{$user}";

        if (! file_exists($hashFile) || ! is_readable($hashFile)) {
            return null;
        }

        $storedHash = preg_replace('/\s+/', '', file_get_contents($hashFile));
        $submitted  = preg_replace('/\s+/', '', $hash);

        if (! hash_equals($storedHash, $submitted)) {
            Log::warning("[WhmAuth] Invalid hash for user: {$user}");
            return null;
        }

        return [
            'user'    => $user,
            'is_root' => $user === 'root',
        ];
    }

    private function isKnownReseller(string $user): bool
    {
        if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $user)) {
            return false;
        }

        return file_exists("/var/cpanel/resellers/{$user}");
    }
}
