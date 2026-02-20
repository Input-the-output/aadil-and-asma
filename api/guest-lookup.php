<?php
/**
 * Guest Lookup API — Fuzzy Name Search
 *
 * POST /api/guest-lookup.php
 * Body: { "name": "George" } or { "guest_id": 42 }
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
$rateFile = $rateDir . md5($ip . '_lookup') . '.json';
$rateData = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
$now = time();
$rateData = array_values(array_filter($rateData, function ($ts) use ($now) {
    return ($now - $ts) < 60;
}));
if (count($rateData) >= ($config['security']['lookup_rpm'] ?? 10)) {
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

// ─── Load guests ──────────────────────────────────────────────
$guestsFile = $config['paths']['guests_json'];
if (!file_exists($guestsFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Guest data not found']);
    exit;
}
$guests = json_decode(file_get_contents($guestsFile), true);

// ─── Load existing RSVPs ──────────────────────────────────────
$rsvpsFile = $config['paths']['rsvps_json'];
$rsvps = [];
if (file_exists($rsvpsFile)) {
    $rsvps = json_decode(file_get_contents($rsvpsFile), true) ?: [];
}
$submittedIds = array_column($rsvps, 'guest_id');

// ─── Parse input ──────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

// Direct guest_id lookup (candidate selection)
$guestId = isset($input['guest_id']) ? intval($input['guest_id']) : null;
if ($guestId) {
    foreach ($guests as $g) {
        if ($g['id'] === $guestId) {
            if (in_array($guestId, $submittedIds)) {
                echo json_encode(['already_submitted' => true, 'guest_name' => $g['name']]);
                exit;
            }
            echo json_encode(['guest' => $g]);
            exit;
        }
    }
    echo json_encode(['guest' => null]);
    exit;
}

// Name-based search
$name = trim($input['name'] ?? '');
if (empty($name) || strlen($name) < 2 || strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid name.']);
    exit;
}
$name = strip_tags($name);
if (!preg_match('/^[\p{L}\s\'\-\.]+$/u', $name)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid name.']);
    exit;
}

// ─── Fuzzy matching pipeline ──────────────────────────────────
$nameLower = mb_strtolower($name);
$nameParts = preg_split('/\s+/', $nameLower);
$searchFirst = $nameParts[0] ?? '';
$searchLast  = end($nameParts);
$searchFirstMp = metaphone($searchFirst);
$searchLastMp  = count($nameParts) > 1 ? metaphone($searchLast) : '';

// Step 1: Exact match
foreach ($guests as $g) {
    if ($g['name_lower'] === $nameLower) {
        if (in_array($g['id'], $submittedIds)) {
            echo json_encode(['already_submitted' => true, 'guest_name' => $g['name']]);
            exit;
        }
        echo json_encode(['guest' => $g]);
        exit;
    }
}

// Step 2: Score all guests
$candidates = [];
foreach ($guests as $g) {
    $score = 0;
    $gLower = $g['name_lower'];
    $gParts = preg_split('/\s+/', $gLower);
    $gFirst = $gParts[0] ?? '';
    $gLast  = end($gParts);

    // Substring match (name contains search or vice versa)
    if (strpos($gLower, $nameLower) !== false || strpos($nameLower, $gLower) !== false) {
        $score += 40;
    }

    // First name metaphone match (compute from name at runtime for consistency)
    $gFirstMp = metaphone($gFirst);
    $gLastMp  = metaphone($gLast);
    if ($searchFirstMp && $gFirstMp === $searchFirstMp) {
        $score += 30;
    }

    // Last name metaphone match
    if ($searchLastMp && $gLastMp === $searchLastMp) {
        $score += 30;
    }

    // First name Levenshtein (within distance 2)
    $levFirst = levenshtein($searchFirst, $gFirst);
    if ($levFirst <= 2) {
        $score += (3 - $levFirst) * 10; // 30, 20, 10
    }

    // Last name Levenshtein (within distance 2)
    if (count($nameParts) > 1) {
        $levLast = levenshtein($searchLast, $gLast);
        if ($levLast <= 2) {
            $score += (3 - $levLast) * 10;
        }
    }

    // Full name Levenshtein
    $levFull = levenshtein($nameLower, $gLower);
    if ($levFull <= 3) {
        $score += (4 - $levFull) * 5;
    }

    // Word containment: any search word starts a guest name word
    foreach ($nameParts as $sp) {
        foreach ($gParts as $gp) {
            if (strlen($sp) >= 3 && strpos($gp, $sp) === 0) {
                $score += 15;
            }
        }
    }

    if ($score >= 50) {
        $candidates[] = [
            'guest' => $g,
            'score' => $score,
            'already_submitted' => in_array($g['id'], $submittedIds),
        ];
    }
}

// Sort by score descending
usort($candidates, function ($a, $b) {
    return $b['score'] - $a['score'];
});

// Limit to top 5
$candidates = array_slice($candidates, 0, 5);

if (count($candidates) === 0) {
    echo json_encode(['guest' => null]);
    exit;
}

if (count($candidates) === 1) {
    $c = $candidates[0];
    if ($c['already_submitted']) {
        echo json_encode(['already_submitted' => true, 'guest_name' => $c['guest']['name']]);
        exit;
    }
    echo json_encode(['guest' => $c['guest']]);
    exit;
}

// Multiple candidates — return list for "Did you mean?"
$candidateList = array_map(function ($c) {
    return [
        'id'   => $c['guest']['id'],
        'name' => $c['guest']['name'],
        'already_submitted' => $c['already_submitted'],
    ];
}, $candidates);

echo json_encode(['candidates' => $candidateList]);
