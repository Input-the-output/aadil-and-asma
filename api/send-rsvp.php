<?php
/**
 * RSVP Submission Handler
 *
 * POST /api/send-rsvp.php
 * Body: {
 *   "guest_id": 42,
 *   "attending_wedding": true,
 *   "attending_pre_wedding": true,
 *   "plus_one_coming": true,
 *   "plus_one_name": "Marie Keyrouz"
 * }
 * Headers: X-RSVP-Token: <token>
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ─── Load config ──────────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration missing']);
    exit;
}
$config = require $configPath;

// ─── Origin validation ────────────────────────────────────────
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['security']['allowed_origins'] ?? [];
if ($origin && !in_array($origin, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized origin']);
    exit;
}
if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
}

// ─── Rate limiting ────────────────────────────────────────────
$rateDir = $config['security']['rate_limit_dir'];
if (!is_dir($rateDir)) {
    mkdir($rateDir, 0700, true);
}
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = $rateDir . md5($ip . '_submit') . '.json';
$rateData = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
$now = time();
$rateData = array_values(array_filter($rateData, function ($ts) use ($now) {
    return ($now - $ts) < 60;
}));
if (count($rateData) >= ($config['security']['submit_rpm'] ?? 5)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment.']);
    exit;
}
$rateData[] = $now;
file_put_contents($rateFile, json_encode($rateData), LOCK_EX);

// ─── Token validation ─────────────────────────────────────────
$token = $_SERVER['HTTP_X_RSVP_TOKEN'] ?? '';
if (empty($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing security token']);
    exit;
}
$parts = explode('.', $token);
if (count($parts) !== 2) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
$payload = json_decode(base64_decode($parts[0]), true);
$signature = $parts[1];
if (!$payload || !isset($payload['exp'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
$expectedSig = hash_hmac('sha256', $parts[0], $config['security']['token_secret']);
if (!hash_equals($expectedSig, $signature)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}
if ($payload['exp'] < time()) {
    http_response_code(403);
    echo json_encode(['error' => 'Token expired. Please refresh and try again.']);
    exit;
}

// ─── Parse input ──────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

$guestId           = isset($input['guest_id']) ? intval($input['guest_id']) : null;
$attendingWedding  = (bool)($input['attending_wedding'] ?? false);
$attendingPreWed   = (bool)($input['attending_pre_wedding'] ?? false);
$plusOneComing     = (bool)($input['plus_one_coming'] ?? false);
$plusOneName       = trim(strip_tags($input['plus_one_name'] ?? ''));

if (!$guestId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid submission']);
    exit;
}

// Validate plus_one_name if provided
if ($plusOneName && !preg_match('/^[\p{L}\s\'\-\.]+$/u', $plusOneName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid plus-one name.']);
    exit;
}

// ─── Validate guest exists ────────────────────────────────────
$guestsFile = $config['paths']['guests_json'];
$guests = json_decode(file_get_contents($guestsFile), true);
$guest = null;
foreach ($guests as $g) {
    if ($g['id'] === $guestId) {
        $guest = $g;
        break;
    }
}
if (!$guest) {
    http_response_code(400);
    echo json_encode(['error' => 'Guest not found']);
    exit;
}

// ─── Write RSVP with flock ────────────────────────────────────
$rsvpsFile = $config['paths']['rsvps_json'];

$fp = fopen($rsvpsFile, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not open RSVP file']);
    exit;
}

if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    http_response_code(500);
    echo json_encode(['error' => 'Could not lock RSVP file']);
    exit;
}

$contents = stream_get_contents($fp);
$rsvps = json_decode($contents, true) ?: [];

// Check not already submitted
foreach ($rsvps as $r) {
    if ($r['guest_id'] === $guestId) {
        flock($fp, LOCK_UN);
        fclose($fp);
        http_response_code(409);
        echo json_encode(['error' => 'RSVP already submitted', 'already_submitted' => true]);
        exit;
    }
}

// Build RSVP record
$rsvp = [
    'guest_id'               => $guestId,
    'guest_name'             => $guest['name'],
    'attending_wedding'      => $attendingWedding,
    'attending_pre_wedding'  => $attendingPreWed,
    'plus_one_coming'        => $plusOneComing,
    'plus_one_name'          => $plusOneComing ? $plusOneName : '',
    'submitted_at'           => date('c'),
];

$rsvps[] = $rsvp;

// Write back
fseek($fp, 0);
ftruncate($fp, 0);
fwrite($fp, json_encode($rsvps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(['success' => true]);
