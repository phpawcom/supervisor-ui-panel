#!/usr/bin/php
<?php
/**
 * Token Generator
 * ===============
 * Generates a short-lived HMAC-signed plugin token.
 * Called by the WHM CGI entry point and cPanel page bootstrap.
 *
 * Usage:  php generate_token.php <role> <username>
 *   role: cpanel_user | whm_admin
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: generate_token.php <role> <username>\n");
    exit(1);
}

$role = $argv[1];
$user = $argv[2];

// Validate
if (! in_array($role, ['cpanel_user', 'whm_admin'], true)) {
    fwrite(STDERR, "Invalid role: {$role}\n");
    exit(1);
}

if (! preg_match('/^[a-z0-9_\-]{1,32}$/i', $user)) {
    fwrite(STDERR, "Invalid username: {$user}\n");
    exit(1);
}

$secretFile = '/var/cpanel/laravel_supervisor_plugin/plugin_secret';

if (! file_exists($secretFile)) {
    fwrite(STDERR, "Secret file not found: {$secretFile}\n");
    exit(1);
}

$secret = trim(file_get_contents($secretFile));

if (empty($secret) || strlen($secret) < 32) {
    fwrite(STDERR, "Invalid or too-short secret\n");
    exit(1);
}

$exp  = time() + 3600;
$data = $user . '|' . $exp . ($role === 'whm_admin' ? '|whm_admin' : '');
$sig  = hash_hmac('sha256', $data, $secret);

$payload = [
    'user' => $user,
    'exp'  => $exp,
    'sig'  => $sig,
    'role' => $role,
];

if ($role === 'whm_admin') {
    $payload['reseller'] = $user !== 'root' ? $user : null;
}

echo base64_encode(json_encode($payload));
exit(0);
