<?php
/**
 * CSRF Token Generator
 * GET /api/token.php
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration missing']);
    exit;
}
$config = require $configPath;

$secret = $config['security']['token_secret'];
$ttl    = $config['security']['token_ttl'] ?? 600;

$payload = base64_encode(json_encode([
    'exp' => time() + $ttl,
    'iat' => time(),
    'jti' => bin2hex(random_bytes(16)),
]));

$signature = hash_hmac('sha256', $payload, $secret);

echo json_encode(['token' => $payload . '.' . $signature]);
