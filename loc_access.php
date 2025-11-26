<?php
// loc_access.php
// Simple 30-day access logging for LOC gate

header('Content-Type: application/json');

$logFile = __DIR__ . '/loc_access_log.json';

// --- Helpers ------------------------------------------------

function loadLog($logFile) {
    if (!file_exists($logFile)) {
        return ['entries' => []];
    }

    $raw = file_get_contents($logFile);
    if ($raw === false || $raw === '') {
        return ['entries' => []];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        return ['entries' => []];
    }

    return $data;
}

function saveLog($logFile, $data) {
    file_put_contents($logFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// --- Input --------------------------------------------------

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$action   = $input['action']   ?? '';
$identity = $input['identity'] ?? '';
$txHash   = $input['txHash']   ?? null;

if ($identity === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing identity']);
    exit;
}

// You can change UTC to your preferred timezone if you want
$now = new DateTime('now', new DateTimeZone('UTC'));
$data = loadLog($logFile);

// --- Actions ------------------------------------------------

if ($action === 'check') {
    if (!isset($data['entries'][$identity])) {
        echo json_encode(['status' => 'none']);
        exit;
    }

    $lastPaidStr = $data['entries'][$identity]['lastPaidDate'] ?? '';
    $last = DateTime::createFromFormat('Y-m-d', $lastPaidStr);

    if (!$last) {
        echo json_encode(['status' => 'none']);
        exit;
    }

    // Difference in full days
    $diffDays = $last->diff($now)->days;

    // 0..29 days => active (30 calendar days including the payment day)
    if ($diffDays <= 29) {
        $expires = clone $last;
        $expires->modify('+30 days');

        echo json_encode([
            'status'       => 'active',
            'lastPaidDate' => $last->format('Y-m-d'),
            'expiresOn'    => $expires->format('Y-m-d'),
        ]);
        exit;
    } else {
        echo json_encode([
            'status'       => 'expired',
            'lastPaidDate' => $last->format('Y-m-d'),
        ]);
        exit;
    }
}
elseif ($action === 'log') {
    $today = $now->format('Y-m-d');

    if (!isset($data['entries'][$identity])) {
        $data['entries'][$identity] = [
            'lastPaidDate' => $today,
            'history'      => [],
        ];
    } else {
        $data['entries'][$identity]['lastPaidDate'] = $today;
        if (!isset($data['entries'][$identity]['history']) || !is_array($data['entries'][$identity]['history'])) {
            $data['entries'][$identity]['history'] = [];
        }
    }

    $entry = ['date' => $today];
    if ($txHash) {
        $entry['txHash'] = $txHash;
    }
    $data['entries'][$identity]['history'][] = $entry;

    saveLog($logFile, $data);

    $expires = (clone $now)->modify('+30 days');

    echo json_encode([
        'status'       => 'logged',
        'lastPaidDate' => $today,
        'expiresOn'    => $expires->format('Y-m-d'),
    ]);
    exit;
}
else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}
