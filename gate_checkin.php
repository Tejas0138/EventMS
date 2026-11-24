<?php
declare(strict_types=1);

// =========================================================================
// Production Error Configuration: Log to file, never display raw traces
// =========================================================================
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if empty
if (empty($_SESSION['gate_checkin_csrf'])) {
    $_SESSION['gate_checkin_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['gate_checkin_csrf'];

$pdo = null;
$dbError = false;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    error_log("Database connection error in gate_checkin.php: " . $e->getMessage());
    $dbError = true;
}

// =========================================================================
// Resolve Event Settings & Automated Date-Based Day Resolution (IST)
// =========================================================================
$settings = $pdo ? getEventSettings($pdo) : [
    'day1_date'         => '2026-11-18',
    'day2_date'         => '2026-11-19',
    'self_checkin_open' => '1',
    'event_name'        => 'COE Event 2026',
];

$day1Date = trim((string)($settings['day1_date'] ?? '2026-11-18'));
$day2Date = trim((string)($settings['day2_date'] ?? '2026-11-19'));
$isOpen   = ((string)($settings['self_checkin_open'] ?? '1')) === '1';
$eventName = trim((string)($settings['event_name'] ?? 'COE Event 2026'));

// Current Date in Asia/Kolkata (IST)
$nowIST = new \DateTime('now', new \DateTimeZone('Asia/Kolkata'));
$todayDate = $nowIST->format('Y-m-d');
$todayFormatted = $nowIST->format('d M Y');
$nowTimestamp = $nowIST->getTimestamp();

// Gate status decided STRICTLY ON THE SERVER from dates & settings
// The day can NEVER come from the browser. Ignore any day parameter sent by the page.
$gateStatus = 'open'; // 'open' | 'closed' | 'not_open_today'
$serverDay = 0;

if (!$isOpen) {
    $gateStatus = 'closed';
} elseif ($todayDate !== '' && $todayDate === $day1Date) {
    $gateStatus = 'open';
    $serverDay = 1;
} elseif ($todayDate !== '' && $todayDate === $day2Date) {
    $gateStatus = 'open';
    $serverDay = 2;
} else {
    $gateStatus = 'not_open_today';
}

// =========================================================================
// =========================================================================
// Server-Side Rate Limiter: Max 300 attempts/min per IP, max 5 failed attempts/10min per ID/mobile
// =========================================================================
function checkRateLimit(PDO $pdo, string $ip, string $identifier = ''): array {
    $now = time();
    $windowStart = $now - 60;
    $identWindow = $now - 600;
    try {
        // Probabilistic cleanup of old attempts (1 in 100 requests) to avoid table lock contention
        if (mt_rand(1, 100) === 1) {
            $pdo->prepare("DELETE FROM `rate_limits` WHERE attempt_time < :old")->execute([':old' => $now - 900]);
        }
        // 1. Check IP rate limit (300 requests / minute)
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `rate_limits` WHERE ip = :ip AND action = 'gate_self_checkin' AND attempt_time > :ws");
        $cntStmt->execute([':ip' => $ip, ':ws' => $windowStart]);
        $count = (int)$cntStmt->fetchColumn();
        if ($count >= 300) {
            return ['allowed' => false, 'status' => 'rate_limited', 'message' => 'Please wait a minute.'];
        }

        // 2. Check identifier failed attempts limit (max 5 failed attempts per ID or mobile in 10 minutes)
        $cleanIdent = trim($identifier);
        if ($cleanIdent !== '') {
            $idStmt = $pdo->prepare("SELECT COUNT(*) FROM `rate_limits` WHERE identifier = :ident AND action = 'gate_failed_attempt' AND attempt_time > :iws");
            $idStmt->execute([':ident' => strtolower($cleanIdent), ':iws' => $identWindow]);
            $failedCount = (int)$idStmt->fetchColumn();
            if ($failedCount >= 5) {
                return ['allowed' => false, 'status' => 'rate_limited', 'message' => 'Too many failed attempts for this ID or mobile. Please ask the event desk.'];
            }
        }

        // Log general check-in attempt for IP
        $insStmt = $pdo->prepare("INSERT INTO `rate_limits` (ip, action, identifier, attempt_time) VALUES (:ip, 'gate_self_checkin', :ident, :t)");
        $insStmt->execute([
            ':ip'    => $ip,
            ':ident' => ($cleanIdent !== '' ? strtolower($cleanIdent) : null),
            ':t'     => $now
        ]);
        return ['allowed' => true];
    } catch (\Throwable $e) {
        return ['allowed' => true]; // Don't block users if rate limiting table fails
    }
}

function recordFailedAttempt(PDO $pdo, string $ip, string $identifier): void {
    $cleanIdent = trim($identifier);
    if ($cleanIdent === '') return;
    try {
        $insStmt = $pdo->prepare("INSERT INTO `rate_limits` (ip, action, identifier, attempt_time) VALUES (:ip, 'gate_failed_attempt', :ident, :t)");
        $insStmt->execute([':ip' => $ip, ':ident' => strtolower($cleanIdent), ':t' => time()]);
    } catch (\Throwable $e) {}
}

// =========================================================================
// Handle POST Check-In Request
// =========================================================================
$serverResult = null;
$inputIdentifier = trim((string)($_GET['id'] ?? ''));

$isJsonRequest = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Parse JSON body or form-data
    $rawBody = file_get_contents('php://input');
    $postData = [];
    if (!empty($rawBody)) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $postData = $decoded;
        }
    }
    if (empty($postData)) {
        $postData = $_POST;
    }

    $rawInput = trim((string)($postData['identifier'] ?? $postData['mobile'] ?? ''));
    $inputIdentifier = $rawInput;
    $sentCsrf = (string)($postData['csrf_token'] ?? '');

    // 1. Verify CSRF
    if (!hash_equals($_SESSION['gate_checkin_csrf'] ?? '', $sentCsrf)) {
        $serverResult = [
            'success' => false,
            'status'  => 'error',
            'message' => 'Security token expired. Please refresh the page and try again.',
        ];
    }
    // 2. Rate limit check (per-IP and per-identifier)
    elseif ($pdo !== null && !($rateCheck = checkRateLimit($pdo, $ip, $rawInput))['allowed']) {
        $serverResult = [
            'success' => false,
            'status'  => $rateCheck['status'] ?? 'rate_limited',
            'message' => $rateCheck['message'] ?? 'Please wait a minute.',
        ];
    }
    // 3. Database check
    elseif ($pdo === null) {
        $serverResult = [
            'success' => false,
            'status'  => 'error',
            'message' => 'Service temporarily unavailable. Please try again shortly.',
        ];
    }
    // 4. Closed or Not Open Today
    elseif ($gateStatus === 'closed') {
        $serverResult = [
            'success' => false,
            'status'  => 'gate_closed',
            'message' => 'Entry is closed.',
        ];
    } elseif ($gateStatus === 'not_open_today') {
        $serverResult = [
            'success' => false,
            'status'  => 'not_open_today',
            'message' => 'Entry is not open today.',
        ];
    }
    // 5. Empty Input
    elseif ($rawInput === '') {
        $serverResult = [
            'success' => false,
            'status'  => 'invalid_input',
            'message' => 'Please enter your mobile number or Register Id.',
        ];
    }
    // 6. Execute Check-in Logic for the Server-Determined Day
    else {
        try {
            $visitor = null;
            $cleanInput = trim($rawInput);

            // Strip spaces, dashes, parens, +91 or +
            $strippedDigits = preg_replace('/[\s\-()]/', '', $cleanInput);
            if (str_starts_with($strippedDigits, '+91')) {
                $strippedDigits = substr($strippedDigits, 3);
            } elseif (str_starts_with($strippedDigits, '91') && strlen($strippedDigits) === 12) {
                $strippedDigits = substr($strippedDigits, 2);
            } elseif (str_starts_with($strippedDigits, '0') && strlen($strippedDigits) === 11) {
                $strippedDigits = substr($strippedDigits, 1);
            } elseif (str_starts_with($strippedDigits, '+')) {
                $strippedDigits = substr($strippedDigits, 1);
            }

            // Detection rule: only digits -> mobile number (8-10 digits); otherwise -> Register Id
            $isOnlyDigits = ($strippedDigits !== '' && ctype_digit($strippedDigits));

            if ($isOnlyDigits) {
                // Lookup by Mobile Number
                $stmt = $pdo->prepare("
                    SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region,
                           v.counter_id, c.counter_no, c.name AS counter_name, c.code AS counter_code, c.name_range AS counter_range,
                           (
                               SELECT GROUP_CONCAT(NULLIF(TRIM(cr.region), '') ORDER BY cr.region SEPARATOR ', ')
                               FROM `counter_regions` cr
                               WHERE cr.counter_id = c.id AND TRIM(cr.region) != ''
                           ) AS counter_regions,
                           v.is_day1_checked_in, v.day1_checked_in_at, v.is_day2_checked_in, v.day2_checked_in_at
                    FROM `allowed_visitors` v
                    LEFT JOIN `counters` c ON c.id = v.counter_id
                    WHERE v.mobile = :mobile
                ");
                $stmt->execute([':mobile' => $strippedDigits]);
                $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($matches) === 0) {
                    // Fallback in case a Register Id is all digits (e.g. 9818803226)
                    $stmtUid = $pdo->prepare("
                        SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region,
                               v.counter_id, c.counter_no, c.name AS counter_name, c.code AS counter_code, c.name_range AS counter_range,
                               (
                                   SELECT GROUP_CONCAT(NULLIF(TRIM(cr.region), '') ORDER BY cr.region SEPARATOR ', ')
                                   FROM `counter_regions` cr
                                   WHERE cr.counter_id = c.id AND TRIM(cr.region) != ''
                               ) AS counter_regions,
                               v.is_day1_checked_in, v.day1_checked_in_at, v.is_day2_checked_in, v.day2_checked_in_at
                        FROM `allowed_visitors` v
                        LEFT JOIN `counters` c ON c.id = v.counter_id
                        WHERE LOWER(TRIM(v.user_id)) = LOWER(:uid)
                        LIMIT 1
                    ");
                    $stmtUid->execute([':uid' => $cleanInput]);
                    $uidMatch = $stmtUid->fetch(PDO::FETCH_ASSOC);
                    if ($uidMatch) {
                        $visitor = $uidMatch;
                    } else {
                        recordFailedAttempt($pdo, $ip, $rawInput);
                        $serverResult = [
                            'success' => false,
                            'status'  => 'not_found',
                            'message' => 'Not registered. Please check your mobile number or Register Id.',
                        ];
                    }
                } elseif (count($matches) > 1) {
                    recordFailedAttempt($pdo, $ip, $rawInput);
                    // Shared number linked to multiple people -> do NOT check in anyone
                    $serverResult = [
                        'success' => false,
                        'status'  => 'multiple_matches',
                        'message' => 'This mobile number is linked to more than one registration. Please enter your Register Id instead.',
                    ];
                } else {
                    $visitor = $matches[0];
                }
            } else {
                // Lookup by Register Id (case-insensitive, trim both sides, collapse spaces)
                $normalizedUid = preg_replace('/\s+/', ' ', $cleanInput);

                $stmt = $pdo->prepare("
                    SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region,
                           v.counter_id, c.counter_no, c.name AS counter_name, c.code AS counter_code, c.name_range AS counter_range,
                           (
                               SELECT GROUP_CONCAT(NULLIF(TRIM(cr.region), '') ORDER BY cr.region SEPARATOR ', ')
                               FROM `counter_regions` cr
                               WHERE cr.counter_id = c.id AND TRIM(cr.region) != ''
                           ) AS counter_regions,
                           v.is_day1_checked_in, v.day1_checked_in_at, v.is_day2_checked_in, v.day2_checked_in_at
                    FROM `allowed_visitors` v
                    LEFT JOIN `counters` c ON c.id = v.counter_id
                    WHERE LOWER(TRIM(v.user_id)) = LOWER(:uid)
                       OR LOWER(TRIM(REPLACE(v.user_id, '  ', ' '))) = LOWER(:norm_uid)
                    LIMIT 1
                ");
                $stmt->execute([
                    ':uid'      => $cleanInput,
                    ':norm_uid' => $normalizedUid,
                ]);
                $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$visitor) {
                    $visitor = null;
                    recordFailedAttempt($pdo, $ip, $rawInput);
                    $serverResult = [
                        'success' => false,
                        'status'  => 'not_found',
                        'message' => 'Not registered. Please check your mobile number or Register Id.',
                    ];
                }
            }

            if (!empty($visitor)) {
                $userRegion             = trim((string)($visitor['region'] ?? ''));
                $assignedCounterNo      = !empty($visitor['counter_no']) ? (int)$visitor['counter_no'] : null;
                $assignedCounterName    = trim((string)($visitor['counter_name'] ?? ''));
                $assignedCounterCode    = trim((string)($visitor['counter_code'] ?? ''));
                $assignedCounterRange   = trim((string)($visitor['counter_range'] ?? ''));
                $assignedCounterRegions = trim((string)($visitor['counter_regions'] ?? ''));

                if ($assignedCounterName === '' || $assignedCounterNo === null) {
                    $resolved = Database::resolveCounterForVisitor($userRegion, (string)$visitor['name'], $pdo);
                    if ($resolved) {
                        $assignedCounterNo    = !empty($resolved['counter_no']) ? (int)$resolved['counter_no'] : null;
                        $assignedCounterName  = $resolved['name'];
                        $assignedCounterCode  = $resolved['code'];
                        $assignedCounterRange = (string)($resolved['name_range'] ?? '');
                        if (!empty($resolved['id'])) {
                            try {
                                $pdo->prepare("UPDATE `allowed_visitors` SET `counter_id` = :cid WHERE `id` = :vid AND (`counter_id` IS NULL OR `counter_id` = 0)")
                                    ->execute([':cid' => (int)$resolved['id'], ':vid' => (int)$visitor['id']]);
                            } catch (\Throwable $t) {}
                        }
                    }
                }

                $assignedCounterBadge = formatCounterDisplayName($assignedCounterNo, $assignedCounterName);

                // Generate subtext line for counters
                $counterSubtext = '';
                if (!empty($assignedCounterRange)) {
                    $cleanRange = trim($assignedCounterRange);
                    $counterSubtext = str_starts_with(strtolower($cleanRange), 'name') ? $cleanRange : ("Names " . $cleanRange);
                } elseif (!empty($assignedCounterRegions)) {
                    $regList = array_values(array_filter(array_map('trim', explode(',', $assignedCounterRegions))));
                    if (count($regList) === 1) {
                        $r = reset($regList);
                        $counterSubtext = (strtolower($r) === 'help desk') ? 'Unassigned attendees & assistance' : ($r . ' visitors');
                    } elseif (count($regList) === 2) {
                        $counterSubtext = $regList[0] . ' and ' . $regList[1] . ' visitors';
                    } elseif (count($regList) > 2) {
                        $last = array_pop($regList);
                        $counterSubtext = implode(', ', $regList) . ' and ' . $last . ' visitors';
                    }
                } elseif ($assignedCounterCode === 'helpdesk' || $assignedCounterBadge === 'Help Desk' || $userRegion === '') {
                    $counterSubtext = 'Unassigned attendees & assistance';
                } elseif ($userRegion !== '') {
                    $counterSubtext = $userRegion . ' visitors';
                }

                $cleanFincert = trim((string)($visitor['fincert'] ?? ''));

                // Check prior check-in for the server-decided day
                if ($serverDay === 1 && (int)$visitor['is_day1_checked_in'] === 1) {
                    $timeStr = !empty($visitor['day1_checked_in_at'])
                        ? formatIST($visitor['day1_checked_in_at'], 'd M Y, h:i A')
                        : 'Recorded';

                    $serverResult = [
                        'success'         => false,
                        'status'          => 'duplicate',
                        'message'         => 'Already Checked In for Day 1 at ' . $timeStr,
                        'day'             => 1,
                        'name'            => $visitor['name'],
                        'fincert'         => $cleanFincert,
                        'counter_name'    => $assignedCounterBadge,
                        'counter_subtext' => $counterSubtext,
                        'checked_in_at'   => $visitor['day1_checked_in_at'],
                        'formatted_time'  => $timeStr,
                    ];
                } elseif ($serverDay === 2 && (int)$visitor['is_day2_checked_in'] === 1) {
                    $timeStr = !empty($visitor['day2_checked_in_at'])
                        ? formatIST($visitor['day2_checked_in_at'], 'd M Y, h:i A')
                        : 'Recorded';

                    $serverResult = [
                        'success'         => false,
                        'status'          => 'duplicate',
                        'message'         => 'Already Checked In for Day 2 at ' . $timeStr,
                        'day'             => 2,
                        'name'            => $visitor['name'],
                        'fincert'         => $cleanFincert,
                        'counter_name'    => $assignedCounterBadge,
                        'counter_subtext' => $counterSubtext,
                        'checked_in_at'   => $visitor['day2_checked_in_at'],
                        'formatted_time'  => $timeStr,
                    ];
                } elseif ($serverDay === 1) {
                    $updateStmt = $pdo->prepare("
                        UPDATE `allowed_visitors` 
                        SET is_day1_checked_in = 1, 
                            day1_checked_in_at = UTC_TIMESTAMP()
                        WHERE id = :id AND is_day1_checked_in = 0
                    ");
                    $updateStmt->execute([':id' => $visitor['id']]);

                    $tsStmt = $pdo->prepare("SELECT day1_checked_in_at FROM `allowed_visitors` WHERE id = :id");
                    $tsStmt->execute([':id' => $visitor['id']]);
                    $checkedInAt = $tsStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s');
                    $formattedTime = formatIST($checkedInAt, 'd M Y, h:i A');

                    if ($updateStmt->rowCount() === 0) {
                        // Already checked in or parallel race condition
                        $serverResult = [
                            'success'         => false,
                            'status'          => 'duplicate',
                            'message'         => 'Already Checked In for Day 1 at ' . $formattedTime,
                            'day'             => 1,
                            'name'            => $visitor['name'],
                            'fincert'         => $cleanFincert,
                            'counter_name'    => $assignedCounterBadge,
                            'counter_subtext' => $counterSubtext,
                            'checked_in_at'   => $checkedInAt,
                            'formatted_time'  => $formattedTime,
                        ];
                    } else {
                        $serverResult = [
                            'success'         => true,
                            'status'          => 'success',
                            'message'         => 'Welcome to Day 1, ' . $visitor['name'] . '!',
                            'day'             => 1,
                            'name'            => $visitor['name'],
                            'fincert'         => $cleanFincert,
                            'counter_name'    => $assignedCounterBadge,
                            'counter_subtext' => $counterSubtext,
                            'checked_in_at'   => $checkedInAt,
                            'formatted_time'  => $formattedTime,
                        ];
                    }
                } elseif ($serverDay === 2) {
                    $updateStmt = $pdo->prepare("
                        UPDATE `allowed_visitors` 
                        SET is_day2_checked_in = 1, 
                            day2_checked_in_at = UTC_TIMESTAMP()
                        WHERE id = :id AND is_day2_checked_in = 0
                    ");
                    $updateStmt->execute([':id' => $visitor['id']]);

                    $tsStmt = $pdo->prepare("SELECT day2_checked_in_at FROM `allowed_visitors` WHERE id = :id");
                    $tsStmt->execute([':id' => $visitor['id']]);
                    $checkedInAt = $tsStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s');
                    $formattedTime = formatIST($checkedInAt, 'd M Y, h:i A');

                    if ($updateStmt->rowCount() === 0) {
                        // Already checked in or parallel race condition
                        $serverResult = [
                            'success'         => false,
                            'status'          => 'duplicate',
                            'message'         => 'Already Checked In for Day 2 at ' . $formattedTime,
                            'day'             => 2,
                            'name'            => $visitor['name'],
                            'fincert'         => $cleanFincert,
                            'counter_name'    => $assignedCounterBadge,
                            'counter_subtext' => $counterSubtext,
                            'checked_in_at'   => $checkedInAt,
                            'formatted_time'  => $formattedTime,
                        ];
                    } else {
                        $serverResult = [
                            'success'         => true,
                            'status'          => 'success',
                            'message'         => 'Welcome to Day 2, ' . $visitor['name'] . '!',
                            'day'             => 2,
                            'name'            => $visitor['name'],
                            'fincert'         => $cleanFincert,
                            'counter_name'    => $assignedCounterBadge,
                            'counter_subtext' => $counterSubtext,
                            'checked_in_at'   => $checkedInAt,
                            'formatted_time'  => $formattedTime,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Database check-in error in gate_checkin.php: " . $e->getMessage());
            $serverResult = [
                'success' => false,
                'status'  => 'error',
                'message' => 'Service temporarily unavailable. Please try again.',
            ];
        }
    }

    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($serverResult);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0f1c30">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Visitor Gate Check-In | <?= htmlspecialchars($eventName) ?></title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛡️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
    :root {
        --sat: env(safe-area-inset-top, 0px);
        --sar: env(safe-area-inset-right, 0px);
        --sab: env(safe-area-inset-bottom, 0px);
        --sal: env(safe-area-inset-left, 0px);
        --navy: #0f1c30;
        --navy-light: #182740;
        --blue: #1d4ed8;
        --border-color: #e2e8f0;
    }

    html, body {
        min-height: 100vh;
        min-height: 100dvh;
        margin: 0;
        padding: 0;
        background: #f1f5f9;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: #0f172a;
        -webkit-font-smoothing: antialiased;
        box-sizing: border-box;
    }

    .checkin-container {
        width: 100%;
        max-width: 420px;
        min-height: 100vh;
        min-height: 100dvh;
        margin: 0 auto;
        padding: 12px 14px;
        padding-top: max(12px, var(--sat));
        padding-bottom: max(16px, var(--sab));
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        box-sizing: border-box;
    }

    .checkin-card {
        width: 100%;
        background: #ffffff;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 16px 36px -10px rgba(15, 28, 48, 0.15), 0 0 1px 1px rgba(15, 28, 48, 0.05);
        display: flex;
        flex-direction: column;
        box-sizing: border-box;
    }

    /* Navy Header */
    .checkin-header {
        background: var(--navy);
        padding: 18px 20px 16px;
        color: #ffffff;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        box-sizing: border-box;
    }

    .checkin-header-icon {
        width: 36px;
        height: 36px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.15);
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        color: #ffffff;
        margin-bottom: 8px;
    }

    .checkin-title {
        font-size: 1.18rem;
        font-weight: 700;
        margin: 0 0 6px;
        color: #ffffff;
        letter-spacing: -0.2px;
    }

    /* Server-decided Day Badge */
    .day-badge-wrap {
        display: flex;
        justify-content: center;
        align-items: center;
        margin-top: 4px;
    }

    .day-badge {
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.4px;
        padding: 5px 14px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-transform: uppercase;
    }

    /* Day 1: Green Accent */
    .day-badge-day1 {
        background: #ecfdf5 !important;
        color: #065f46 !important;
        border: 1px solid #a7f3d0 !important;
        box-shadow: 0 1px 4px rgba(5, 150, 105, 0.15);
    }

    /* Day 2: Teal Accent */
    .day-badge-day2 {
        background: #f0fdfa !important;
        color: #0f766e !important;
        border: 1px solid #99f6e4 !important;
        box-shadow: 0 1px 4px rgba(15, 118, 110, 0.15);
    }

    /* Closed / Off Day Badge */
    .day-badge-neutral {
        background: rgba(255, 255, 255, 0.12) !important;
        color: #cbd5e1 !important;
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
    }

    /* Body */
    .checkin-body {
        padding: 20px 18px 24px;
        box-sizing: border-box;
    }

    /* Form Controls */
    .terminal-label-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 20px;
        margin-bottom: 8px;
    }

    .terminal-input-label {
        font-size: 11.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #475569;
        margin: 0;
        display: inline-flex;
        align-items: center;
    }

    .digit-counter-badge {
        font-size: 11px;
        font-weight: 600;
        padding: 2px 8px;
        border-radius: 9999px;
        background-color: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
        visibility: hidden;
        white-space: nowrap;
    }

    .digit-counter-badge.is-valid {
        background-color: #ecfdf5;
        color: #059669;
        border-color: #a7f3d0;
    }

    .phone-input-wrap {
        position: relative;
        display: flex;
        align-items: center;
        width: 100%;
        margin-bottom: 8px;
    }

    .phone-input-prefix-box {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        display: none;
        align-items: center;
        gap: 6px;
        pointer-events: none;
        user-select: none;
        z-index: 2;
    }

    .phone-input-wrap.has-prefix .phone-input-prefix-box {
        display: flex;
    }

    .phone-input-prefix {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: 0.5px;
    }

    .phone-prefix-divider {
        height: 20px;
        width: 1.5px;
        background-color: #cbd5e1;
        display: inline-block;
        margin-left: 2px;
    }

    .phone-input {
        height: 52px;
        font-size: 17px;
        font-weight: 600;
        color: #0f172a;
        border-radius: 12px;
        border: 2px solid #cbd5e1;
        background-color: #ffffff;
        padding: 0 16px;
        width: 100%;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, padding-left 0.15s ease;
        box-sizing: border-box;
    }

    .phone-input-wrap.has-prefix .phone-input {
        padding-left: 60px;
        letter-spacing: 0.5px;
    }

    .phone-input::placeholder {
        font-size: 14.5px;
        font-weight: 400;
        color: #94a3b8;
    }

    .phone-input:focus {
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.15);
        outline: none;
    }

    .terminal-helper-text {
        font-size: 12.5px;
        color: #94a3b8;
        text-align: center;
        margin-bottom: 18px;
        line-height: 1.4;
    }

    .btn-verify {
        width: 100%;
        height: 48px;
        background: var(--blue);
        color: #ffffff;
        font-size: 0.95rem;
        font-weight: 700;
        border-radius: 12px;
        border: none;
        box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        transition: all 0.15s ease;
        touch-action: manipulation;
    }

    .btn-verify:active {
        transform: scale(0.98);
        background: #1e40af;
    }

    .btn-verify:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    /* Result Card Styles */
    .result-box {
        border-radius: 14px;
        padding: 14px 12px;
        text-align: center;
        margin-bottom: 16px;
        box-sizing: border-box;
    }

    .status-success {
        background: #f0fdf4;
        border: 1.5px solid #86efac;
    }

    .status-duplicate {
        background: #fffbeb;
        border: 1.5px solid #fde68a;
    }

    .status-denied {
        background: #fef2f2;
        border: 1.5px solid #fca5a5;
    }

    .status-neutral {
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
    }

    .status-icon-circle {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        margin: 0 auto 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
    }

    .status-success .status-icon-circle { background: #dcfce7; color: #16a34a; }
    .status-duplicate .status-icon-circle { background: #fef3c7; color: #d97706; }
    .status-denied .status-icon-circle { background: #fee2e2; color: #dc2626; }
    .status-neutral .status-icon-circle { background: #f1f5f9; color: #64748b; }

    .result-visitor-name {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
        margin: 4px 0 2px;
        line-height: 1.25;
        word-break: break-word;
    }

    .result-welcome-text {
        font-size: 0.88rem;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .result-id-line {
        font-size: 0.92rem;
        font-weight: 700;
        color: #334155;
        margin: 4px 0;
    }

    /* COE Kit Location Box */
    .coe-kit-notice-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px 12px;
        margin: 8px 0;
        text-align: center;
        box-sizing: border-box;
    }

    .coe-kit-notice-title {
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 5px;
    }

    .coe-kit-counter-pill {
        background: var(--blue) !important;
        color: #ffffff !important;
        font-size: 15px !important;
        font-weight: 700 !important;
        padding: 6px 16px !important;
        border-radius: 999px !important;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        white-space: normal !important;
        word-break: break-word;
        box-shadow: 0 2px 6px rgba(29, 78, 216, 0.25);
        line-height: 1.35;
    }

    .coe-kit-subtext {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 4px;
        line-height: 1.35;
    }

    /* LIVE IST CLOCK CARD (Gate staff visual anti-screenshot protection) */
    .live-clock-card {
        background: #ffffff;
        border: 1.5px solid #bbf7d0;
        border-radius: 12px;
        padding: 8px 10px;
        margin-top: 10px;
        box-shadow: 0 2px 8px rgba(16, 185, 129, 0.12);
    }

    .live-clock-time {
        font-size: 1.15rem;
        font-weight: 800;
        color: #065f46;
        letter-spacing: 0.5px;
    }

    .live-indicator-dot {
        width: 8px;
        height: 8px;
        background: #10b981;
        border-radius: 50%;
        display: inline-block;
        animation: pulseLive 1.5s infinite;
    }

    @keyframes pulseLive {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
        70% { transform: scale(1.15); box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .btn-checkin-another {
        height: 44px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.9rem;
    }
    </style>
</head>
<body>

<div class="checkin-container">
    <div class="checkin-card">
        
        <!-- Header: Navy background with Day Badge -->
        <div class="checkin-header">
            <div class="checkin-header-icon">
                <i class="bi bi-shield-check"></i>
            </div>
            <h1 class="checkin-title">Visitor Gate Check-In</h1>
            
            <div class="day-badge-wrap">
                <?php if ($gateStatus === 'open' && $serverDay === 1): ?>
                    <span class="day-badge day-badge-day1">
                        <i class="bi bi-calendar-event me-1.5"></i>DAY 1 ENTRY &bull; <?= htmlspecialchars($todayFormatted) ?>
                    </span>
                <?php elseif ($gateStatus === 'open' && $serverDay === 2): ?>
                    <span class="day-badge day-badge-day2">
                        <i class="bi bi-calendar2-check me-1.5"></i>DAY 2 ENTRY &bull; <?= htmlspecialchars($todayFormatted) ?>
                    </span>
                <?php else: ?>
                    <span class="day-badge day-badge-neutral">
                        <i class="bi bi-calendar-x me-1.5"></i><?= htmlspecialchars($todayFormatted) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Body -->
        <div class="checkin-body">
            
            <?php if ($gateStatus === 'closed'): ?>
                <!-- Neutral Card: Entry Closed -->
                <div class="result-box status-neutral p-4 text-center">
                    <div class="status-icon-circle mb-2">
                        <i class="bi bi-door-closed-fill"></i>
                    </div>
                    <h5 class="fw-bold mb-1" style="color: #0f1c30;">Entry is closed.</h5>
                    <p class="text-muted small mb-0">Self check-in is currently closed by the event administrator.</p>
                </div>

            <?php elseif ($gateStatus === 'not_open_today'): ?>
                <!-- Neutral Card: Not Open Today -->
                <div class="result-box status-neutral p-4 text-center">
                    <div class="status-icon-circle mb-2">
                        <i class="bi bi-calendar-x-fill"></i>
                    </div>
                    <h5 class="fw-bold mb-1" style="color: #0f1c30;">Entry is not open today.</h5>
                    <p class="text-muted small mb-0">Self check-in will open on the scheduled event dates.</p>
                </div>

            <?php else: ?>
                <!-- Live Gate Open: Form & Results -->

                <!-- Dynamic Results Container -->
                <div id="resultContainer">
                    <?php if ($serverResult): ?>
                        <?php if ($serverResult['status'] === 'success'): ?>
                            <div class="result-box status-success shadow-sm">
                                <div class="status-icon-circle"><i class="bi bi-check-circle-fill"></i></div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                                    DAY <?= (int)$serverResult['day'] ?> ENTRY APPROVED
                                </span>
                                <div class="result-visitor-name"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></div>
                                <p class="result-welcome-text text-success">Welcome to Day <?= (int)$serverResult['day'] ?>, <?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?>!</p>
                                
                                <?php if (!empty($serverResult['fincert'])): ?>
                                    <div class="result-id-line">
                                        Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($serverResult['fincert']) ?></span>
                                    </div>
                                <?php endif; ?>

                                

                                <div class="small text-muted mb-2">
                                    <i class="bi bi-clock-history me-1 text-success"></i>Checked in at <?= htmlspecialchars($serverResult['formatted_time']) ?>
                                </div>

                                <!-- Live Clock for Gatekeeper Visual Verification -->
                                <div class="live-clock-card">
                                    <div class="d-flex align-items-center justify-content-center gap-2 small fw-bold text-success mb-1">
                                        <span class="live-indicator-dot"></span>
                                        <span>VERIFIED LIVE IST CLOCK</span>
                                    </div>
                                    <!--<div id="liveClockDisplay" class="live-clock-time font-monospace">--:--:--</div>-->
                                    <!--<div class="text-muted" style="font-size:0.7rem;">Show this screen to gate staff for verified entry</div>-->
                                </div>

                                <button type="button" class="btn btn-outline-secondary w-100 mt-3 btn-checkin-another" id="btnCheckinAnother">
                                    <i class="bi bi-arrow-repeat me-1"></i> Check in another visitor
                                </button>
                            </div>

                        <?php elseif ($serverResult['status'] === 'duplicate'): ?>
                            <div class="result-box status-duplicate shadow-sm">
                                <div class="status-icon-circle"><i class="bi bi-exclamation-triangle-fill"></i></div>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                                    DAY <?= (int)$serverResult['day'] ?> ALREADY CHECKED IN
                                </span>
                                <div class="result-visitor-name"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></div>
                                <p class="small fw-bold text-warning-emphasis mb-1"><?= htmlspecialchars($serverResult['message']) ?></p>

                                <?php if (!empty($serverResult['fincert'])): ?>
                                    <div class="result-id-line">
                                        Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($serverResult['fincert']) ?></span>
                                    </div>
                                <?php endif; ?>


                                <div class="live-clock-card" style="border-color:#fde68a;">
                                    <div class="d-flex align-items-center justify-content-center gap-2 small fw-bold text-warning-emphasis mb-1">
                                        <span class="live-indicator-dot" style="background:#f59e0b;"></span>
                                        <span>LIVE IST CLOCK</span>
                                    </div>
                                    <div id="liveClockDisplay" class="live-clock-time font-monospace" style="color:#b45309;">--:--:--</div>
                                </div>

                                <button type="button" class="btn btn-outline-secondary w-100 mt-3 btn-checkin-another" id="btnCheckinAnother">
                                    <i class="bi bi-arrow-repeat me-1"></i> Check in another visitor
                                </button>
                            </div>

                        <?php elseif ($serverResult['status'] === 'multiple_matches'): ?>
                            <div class="result-box status-duplicate shadow-sm">
                                <div class="status-icon-circle"><i class="bi bi-people-fill"></i></div>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                                    MULTIPLE REGISTRATIONS
                                </span>
                                <h6 class="fw-bold mb-1 text-dark" style="font-size:0.92rem;line-height:1.35;"><?= htmlspecialchars($serverResult['message']) ?></h6>
                                <button type="button" class="btn btn-outline-secondary w-100 mt-2 btn-checkin-another" id="btnCheckinAnother">
                                    <i class="bi bi-arrow-repeat me-1"></i> Try again with Register Id
                                </button>
                            </div>

                        <?php elseif ($serverResult['status'] === 'not_found'): ?>
                            <div class="result-box status-denied shadow-sm">
                                <div class="status-icon-circle"><i class="bi bi-slash-circle-fill"></i></div>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                                    NOT REGISTERED
                                </span>
                                <h6 class="fw-bold mb-1 text-dark" style="font-size:0.92rem;"><?= htmlspecialchars($serverResult['message']) ?></h6>
                                <button type="button" class="btn btn-outline-secondary w-100 mt-2 btn-checkin-another" id="btnCheckinAnother">
                                    <i class="bi bi-arrow-repeat me-1"></i> Try again
                                </button>
                            </div>

                        <?php else: ?>
                            <div class="alert alert-warning text-center small rounded-3 py-2 mb-3">
                                <?= htmlspecialchars($serverResult['message'] ?? 'Notice') ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Gate Check-In Form -->
                <form id="selfCheckInForm" method="POST" action="gate_checkin.php" novalidate <?= ($serverResult && ($serverResult['status'] === 'success' || $serverResult['status'] === 'duplicate')) ? 'style="display:none;"' : '' ?>>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="input-section-wrap">
                        <div class="terminal-label-row">
                            <label for="mobileInput" class="terminal-input-label">
                                <i class="bi bi-person-bounding-box me-1 text-primary"></i> MOBILE NUMBER OR REGISTER ID
                            </label>
                            <span id="digitCounter" class="digit-counter-badge">
                                0 / 10 digits
                            </span>
                        </div>

                        <div class="phone-input-wrap" id="phoneInputWrap">
                            <div class="phone-input-prefix-box" id="phonePrefixBox">
                                <span class="phone-input-prefix">+91</span>
                                <span class="phone-prefix-divider"></span>
                            </div>
                            <input 
                                type="text" 
                                class="phone-input" 
                                id="mobileInput" 
                                name="identifier" 
                                maxlength="50" 
                                autocomplete="off" 
                                autocorrect="off"
                                autocapitalize="off"
                                spellcheck="false"
                                placeholder="Mobile number or Register Id"
                                autofocus 
                                required 
                                value="<?= htmlspecialchars($inputIdentifier) ?>"
                            >
                        </div>
                        <div class="terminal-helper-text">
                            Enter your mobile number or Register Id
                        </div>
                    </div>

                    <button type="submit" id="submitBtn" class="btn-verify">
                        <i class="bi bi-shield-check"></i>
                        <span id="btnText">Verify &amp; Check In</span>
                    </button>
                </form>

            <?php endif; ?>

        </div>
    </div>
</div>

<script>
// Server-synchronized Live IST Clock
const serverEpoch = <?= $nowTimestamp * 1000 ?>;
const clientPageLoadTime = Date.now();
const timeOffset = serverEpoch - clientPageLoadTime;

function updateLiveClock() {
    const clockEl = document.getElementById('liveClockDisplay');
    if (!clockEl) return;
    const now = new Date(Date.now() + timeOffset);
    const options = {
        timeZone: 'Asia/Kolkata',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    };
    try {
        clockEl.textContent = new Intl.DateTimeFormat('en-IN', options).format(now);
    } catch (e) {
        clockEl.textContent = now.toLocaleTimeString();
    }
}
setInterval(updateLiveClock, 500);
updateLiveClock();

// Input Sanitization & Auto-Detection
document.addEventListener('DOMContentLoaded', () => {
    const mobileInput = document.getElementById('mobileInput');
    const digitCounter = document.getElementById('digitCounter');
    const prefixBox = document.getElementById('phonePrefixBox');
    const inputWrap = document.getElementById('phoneInputWrap');
    const form = document.getElementById('selfCheckInForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = document.getElementById('btnText');
    const resultContainer = document.getElementById('resultContainer');

    function sanitizeAndCount() {
        if (!mobileInput) return;
        const rawVal = mobileInput.value;
        const trimmed = rawVal.trim();

        // Strip formatting characters to test numeric mode
        let testStr = trimmed.replace(/[\s\-()]/g, '');
        if (testStr.startsWith('+91')) {
            testStr = testStr.slice(3);
        } else if (testStr.startsWith('+')) {
            testStr = testStr.slice(1);
        }
        const isPureNumeric = testStr.length > 0 && /^\d+$/.test(testStr);

        if (trimmed === '') {
            if (prefixBox) prefixBox.style.display = 'none';
            if (digitCounter) {
                digitCounter.style.visibility = 'hidden';
                digitCounter.textContent = '0 / 10 digits';
                digitCounter.className = 'digit-counter-badge';
            }
            if (inputWrap) inputWrap.classList.remove('has-prefix');
            mobileInput.style.paddingLeft = '16px';
            mobileInput.style.letterSpacing = '0';
        } else if (isPureNumeric) {
            if (prefixBox) prefixBox.style.display = 'flex';
            if (digitCounter) digitCounter.style.visibility = 'visible';
            if (inputWrap) inputWrap.classList.add('has-prefix');
            mobileInput.style.paddingLeft = '60px';
            mobileInput.style.letterSpacing = '0.5px';

            let digits = testStr;
            if (digits.length === 12 && digits.startsWith('91')) {
                digits = digits.slice(2);
            } else if (digits.length === 11 && digits.startsWith('0')) {
                digits = digits.slice(1);
            }
            if (digits.length > 10) {
                digits = digits.slice(0, 10);
            }
            if (rawVal !== '' && /^[0-9+\s()-]+$/.test(mobileInput.value) && mobileInput.value !== digits) {
                mobileInput.value = digits;
            }
            if (digitCounter) {
                digitCounter.textContent = `${digits.length} / 10 digits`;
                if (digits.length === 10) {
                    digitCounter.className = 'digit-counter-badge is-valid';
                } else {
                    digitCounter.className = 'digit-counter-badge';
                }
            }
        } else {
            // Register Id mode
            if (prefixBox) prefixBox.style.display = 'none';
            if (digitCounter) {
                digitCounter.style.visibility = 'hidden';
            }
            if (inputWrap) inputWrap.classList.remove('has-prefix');
            mobileInput.style.paddingLeft = '16px';
            mobileInput.style.letterSpacing = '0';
        }
    }

    if (mobileInput) {
        mobileInput.addEventListener('input', sanitizeAndCount);
        mobileInput.addEventListener('focus', sanitizeAndCount);
        sanitizeAndCount();
        mobileInput.focus();
    }

    // Helper to escape HTML safely
    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    // Render client-side result HTML
    function renderResult(data) {
        if (!resultContainer) return;
        if (data.status === 'success') {
            const fincertHtml = data.fincert ? `
                <div class="result-id-line">
                    Your Id no. is <span class="text-primary text-break">${escapeHtml(data.fincert)}</span>
                </div>
            ` : '';
            const subtextHtml = data.counter_subtext ? `<div class="coe-kit-subtext">${escapeHtml(data.counter_subtext)}</div>` : '';

            resultContainer.innerHTML = `
                <div class="result-box status-success shadow-sm">
                    <div class="status-icon-circle"><i class="bi bi-check-circle-fill"></i></div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                        DAY ${data.day} ENTRY APPROVED
                    </span>
                    <div class="result-visitor-name">${escapeHtml(data.name || 'Visitor')}</div>
                    <p class="result-welcome-text text-success">Welcome to Day ${data.day}, ${escapeHtml(data.name || 'Visitor')}!</p>
                    ${fincertHtml}
                
                    <div class="live-clock-card">
                        <div class="d-flex align-items-center justify-content-center gap-2 small fw-bold text-success mb-1">
                            <span class="live-indicator-dot"></span>
                            <span>VERIFIED LIVE IST CLOCK</span>
                        </div>
                        <div id="liveClockDisplay" class="live-clock-time font-monospace">--:--:--</div>
                        <div class="text-muted" style="font-size:0.7rem;">Show this screen to gate staff for verified entry</div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-3 btn-checkin-another" id="btnCheckinAnother">
                        <i class="bi bi-arrow-repeat me-1"></i> Check in another visitor
                    </button>
                </div>
            `;
            if (form) form.style.display = 'none';
            updateLiveClock();
        } else if (data.status === 'duplicate') {
            const fincertHtml = data.fincert ? `
                <div class="result-id-line">
                    Your Id no. is <span class="text-primary text-break">${escapeHtml(data.fincert)}</span>
                </div>
            ` : '';
            const subtextHtml = data.counter_subtext ? `<div class="coe-kit-subtext">${escapeHtml(data.counter_subtext)}</div>` : '';

            resultContainer.innerHTML = `
                <div class="result-box status-duplicate shadow-sm">
                    <div class="status-icon-circle"><i class="bi bi-exclamation-triangle-fill"></i></div>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                        DAY ${data.day} ALREADY CHECKED IN
                    </span>
                    <div class="result-visitor-name">${escapeHtml(data.name || 'Visitor')}</div>
                    <p class="small fw-bold text-warning-emphasis mb-1">${escapeHtml(data.message || '')}</p>
                    ${fincertHtml}
                    <div class="coe-kit-notice-box">
                        <div class="coe-kit-notice-title">Kindly collect the COE Kit from</div>
                        <div class="d-flex justify-content-center">
                            <span class="badge coe-kit-counter-pill">
                                <i class="bi bi-box-seam me-1.5"></i>${escapeHtml(data.counter_name || 'Help Desk')}
                            </span>
                        </div>
                        ${subtextHtml}
                    </div>
                    <div class="live-clock-card" style="border-color:#fde68a;">
                        <div class="d-flex align-items-center justify-content-center gap-2 small fw-bold text-warning-emphasis mb-1">
                            <span class="live-indicator-dot" style="background:#f59e0b;"></span>
                            <span>LIVE IST CLOCK</span>
                        </div>
                        <div id="liveClockDisplay" class="live-clock-time font-monospace" style="color:#b45309;">--:--:--</div>
                    </div>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-3 btn-checkin-another" id="btnCheckinAnother">
                        <i class="bi bi-arrow-repeat me-1"></i> Check in another visitor
                    </button>
                </div>
            `;
            if (form) form.style.display = 'none';
            updateLiveClock();
        } else if (data.status === 'multiple_matches') {
            resultContainer.innerHTML = `
                <div class="result-box status-duplicate shadow-sm">
                    <div class="status-icon-circle"><i class="bi bi-people-fill"></i></div>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                        MULTIPLE REGISTRATIONS
                    </span>
                    <h6 class="fw-bold mb-1 text-dark" style="font-size:0.92rem;line-height:1.35;">${escapeHtml(data.message || '')}</h6>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-2 btn-checkin-another" id="btnCheckinAnother">
                        <i class="bi bi-arrow-repeat me-1"></i> Try again with Register Id
                    </button>
                </div>
            `;
        } else if (data.status === 'not_found') {
            resultContainer.innerHTML = `
                <div class="result-box status-denied shadow-sm">
                    <div class="status-icon-circle"><i class="bi bi-slash-circle-fill"></i></div>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase">
                        NOT REGISTERED
                    </span>
                    <h6 class="fw-bold mb-1 text-dark" style="font-size:0.92rem;">${escapeHtml(data.message || 'Not registered.')}</h6>
                    <button type="button" class="btn btn-outline-secondary w-100 mt-2 btn-checkin-another" id="btnCheckinAnother">
                        <i class="bi bi-arrow-repeat me-1"></i> Try again
                    </button>
                </div>
            `;
        } else {
            resultContainer.innerHTML = `
                <div class="alert alert-warning text-center small rounded-3 py-2 mb-3">
                    ${escapeHtml(data.message || 'Validation error')}
                </div>
            `;
        }
    }

    // Handle Check In Another Visitor button click (delegated)
    document.addEventListener('click', (e) => {
        if (e.target.closest('#btnCheckinAnother')) {
            e.preventDefault();
            if (resultContainer) resultContainer.innerHTML = '';
            if (form) form.style.display = 'block';
            if (mobileInput) {
                mobileInput.value = '';
                sanitizeAndCount();
                mobileInput.focus();
            }
        }
    });

    // Form submission via AJAX
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const val = mobileInput ? mobileInput.value.trim() : '';
            if (!val) {
                if (mobileInput) mobileInput.focus();
                return;
            }

            if (submitBtn) submitBtn.disabled = true;
            if (btnText) btnText.textContent = 'Verifying...';

            try {
                const formData = new FormData(form);
                const res = await fetch('gate_checkin.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData
                });
                const data = await res.json();
                renderResult(data);
            } catch (err) {
                // Fallback to normal form submit on network failure
                form.submit();
            } finally {
                if (submitBtn) submitBtn.disabled = false;
                if (btnText) btnText.textContent = 'Verify & Check In';
            }
        });
    }
});
</script>

</body>
</html>