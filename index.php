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

$pdo = null;
$dbConnectionError = false;
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    error_log("Database connection error in index.php: " . $e->getMessage());
    $dbConnectionError = true;
}

// =========================================================================
// Dynamic Full Live URL Resolution (Hostinger, SSL, Cloudflare, Subfolders)
// =========================================================================
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
);

$protocol = $isHttps ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$rawScriptDir = str_replace('\\', '/', dirname($scriptName));
$scriptDir = ($rawScriptDir !== '' && $rawScriptDir !== '/' && $rawScriptDir !== '.' && !str_contains($rawScriptDir, ':')) 
    ? '/' . trim($rawScriptDir, '/') 
    : '';
$currentLiveUrl = $protocol . $host . $scriptDir . '/index.php';

// Active Day (Strictly 1 or 2, default Day 1)
$currentDay = (int)($_POST['day'] ?? $_GET['day'] ?? 1);
if ($currentDay !== 2) {
    $currentDay = 1;
}
$currentDayLiveUrl = $currentLiveUrl . '?day=' . $currentDay;
$qrCodeSvg = 'https://quickchart.io/qr?text=' . urlencode($currentDayLiveUrl) . '&size=400&margin=2&format=svg';
$qrCodePng = 'https://quickchart.io/qr?text=' . urlencode($currentDayLiveUrl) . '&size=400&margin=2&format=png';

// =========================================================================
// COE Kit Counter Message Constants & Helpers (Highlighted Badge Style)
// =========================================================================
defined('COE_KIT_MESSAGE_TEMPLATE') or define('COE_KIT_MESSAGE_TEMPLATE', 'Kindly collect the COE Kit from {counter}');
defined('COE_KIT_FALLBACK_TEXT') or define('COE_KIT_FALLBACK_TEXT', 'Kindly collect the COE Kit from the Help Desk');

function renderCoeKitLine(?string $counter, ?string $subtext = ''): string {
    $c = trim((string)($counter ?? ''));
    if ($c === '' || strcasecmp($c, 'null') === 0 || strcasecmp($c, 'undefined') === 0 || $c === '—' || $c === '-') {
        $c = 'Help Desk';
    }
    if (!str_contains(strtolower($c), 'counter') && !str_contains(strtolower($c), 'help desk')) {
        $c .= ' Counter';
    }

    $cEsc = htmlspecialchars($c, ENT_QUOTES, 'UTF-8');
    $subEsc = htmlspecialchars(trim((string)$subtext), ENT_QUOTES, 'UTF-8');

    $html = '<div class="coe-kit-notice-box my-2 text-center">';
    $html .= '<div class="coe-kit-notice-title small text-secondary fw-semibold mb-1">Kindly collect the COE Kit from</div>';
    $html .= '<div class="coe-kit-badge-wrap mb-1">';
    $html .= '<span class="badge coe-kit-counter-pill"><i class="bi bi-box-seam me-1.5"></i>' . $cEsc . '</span>';
    $html .= '</div>';
    if ($subEsc !== '') {
        $html .= '<div class="coe-kit-subtext text-muted small">' . $subEsc . '</div>';
    }
    $html .= '</div>';
    return $html;
}

// =========================================================================
// Check-in Validation Logic (2-Day Standalone PDO Execution)
// =========================================================================
$serverResult = null;
$inputIdentifier = trim((string)($_GET['id'] ?? $_GET['userid'] ?? $_GET['user_id'] ?? $_GET['mobile'] ?? ''));

$isJsonRequest = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Read raw body if JSON payload
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

    $rawDay = (int)($postData['day'] ?? $currentDay);
    $day = ($rawDay === 2) ? 2 : 1;
    $currentDay = $day;

    $rawInput = trim((string)($postData['identifier'] ?? $postData['mobile'] ?? ''));
    $inputIdentifier = $rawInput;

    if ($pdo === null) {
        $serverResult = [
            'success' => false,
            'status'  => 'error',
            'message' => 'Service temporarily unavailable. Please try again shortly.',
            'day'     => $day,
        ];
    } elseif ($rawInput === '') {
        $serverResult = [
            'success' => false,
            'status'  => 'invalid_input',
            'message' => 'Please enter your mobile number or Register Id.',
            'day'     => $day,
        ];
    } else {
        try {
            $visitor = null;
            $cleanInput = trim($rawInput);

            // Strip spaces, dashes, parentheses, +91 or +
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

            // Detection rule: only digits -> mobile number; otherwise -> Register Id
            $isOnlyDigits = ($strippedDigits !== '' && ctype_digit($strippedDigits));

            if ($isOnlyDigits) {
                // Lookup by Mobile Number
                $stmt = $pdo->prepare("
                    SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region, v.school_name, v.designation, v.email,
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
                $matches = $stmt->fetchAll();

                if (count($matches) === 0) {
                    // Fallback in case a Register Id is all digits (e.g. 9818803226)
                    $stmtUid = $pdo->prepare("
                        SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region, v.school_name, v.designation, v.email,
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
                    $uidMatch = $stmtUid->fetch();
                    if ($uidMatch) {
                        $visitor = $uidMatch;
                    } else {
                        $serverResult = [
                            'success'    => false,
                            'status'     => 'not_found',
                            'message'    => 'Not registered. Please check your mobile number or Register Id.',
                            'identifier' => $cleanInput,
                            'mobile'     => $strippedDigits,
                            'day'        => $day,
                        ];
                    }
                } elseif (count($matches) > 1) {
                    // Shared number linked to multiple people -> do NOT check in anyone
                    $serverResult = [
                        'success'    => false,
                        'status'     => 'multiple_matches',
                        'message'    => 'This mobile number is linked to more than one registration. Please enter your Register Id instead.',
                        'identifier' => $cleanInput,
                        'mobile'     => $strippedDigits,
                        'day'        => $day,
                    ];
                } else {
                    $visitor = $matches[0];
                }
            } else {
                // Lookup by Register Id (case-insensitive, trim both sides, collapse multiple spaces)
                $normalizedUid = preg_replace('/\s+/', ' ', $cleanInput);

                $stmt = $pdo->prepare("
                    SELECT v.id, v.name, v.mobile, v.user_id, v.fincert, v.region, v.school_name, v.designation, v.email,
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
                $visitor = $stmt->fetch();

                if (!$visitor) {
                    $serverResult = [
                        'success'    => false,
                        'status'     => 'not_found',
                        'message'    => 'Not registered. Please check your mobile number or Register Id.',
                        'identifier' => $cleanInput,
                        'user_id'    => $cleanInput,
                        'day'        => $day,
                    ];
                }
            }

            if ($visitor !== null) {
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

                // Generate subtext line for Pune counters, combined counters, and Help Desk
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

                $coeKitHtml = renderCoeKitLine($assignedCounterBadge, $counterSubtext);

                if ($day === 1 && (int)$visitor['is_day1_checked_in'] === 1) {
                    $timeStr = !empty($visitor['day1_checked_in_at'])
                        ? formatIST($visitor['day1_checked_in_at'], 'd M Y, h:i A')
                        : 'Recorded';

                    $serverResult = [
                        'success'         => false,
                        'status'          => 'duplicate',
                        'message'         => 'Already Checked In for Day 1 at ' . $timeStr,
                        'day'             => 1,
                        'name'            => $visitor['name'],
                        'mobile'          => $visitor['mobile'],
                        'user_id'         => $visitor['user_id'],
                        'fincert'         => $visitor['fincert'],
                        'region'          => $userRegion,
                        'counter_id'      => (int)($visitor['counter_id'] ?? 0),
                        'counter_name'    => $assignedCounterBadge,
                        'counter_range'   => $assignedCounterRange,
                        'counter_regions' => $assignedCounterRegions,
                        'counter_subtext' => $counterSubtext,
                        'coe_kit_text'    => "Kindly collect the COE Kit from {$assignedCounterBadge}",
                        'coe_kit_html'    => $coeKitHtml,
                        'checked_in_at'   => $visitor['day1_checked_in_at'],
                        'formatted_time'  => $timeStr,
                    ];
                } elseif ($day === 2 && (int)$visitor['is_day2_checked_in'] === 1) {
                    $timeStr = !empty($visitor['day2_checked_in_at'])
                        ? formatIST($visitor['day2_checked_in_at'], 'd M Y, h:i A')
                        : 'Recorded';

                    $serverResult = [
                        'success'         => false,
                        'status'          => 'duplicate',
                        'message'         => 'Already Checked In for Day 2 at ' . $timeStr,
                        'day'             => 2,
                        'name'            => $visitor['name'],
                        'mobile'          => $visitor['mobile'],
                        'user_id'         => $visitor['user_id'],
                        'fincert'         => $visitor['fincert'],
                        'region'          => $userRegion,
                        'counter_id'      => (int)($visitor['counter_id'] ?? 0),
                        'counter_name'    => $assignedCounterBadge,
                        'counter_range'   => $assignedCounterRange,
                        'counter_regions' => $assignedCounterRegions,
                        'counter_subtext' => $counterSubtext,
                        'coe_kit_text'    => "Kindly collect the COE Kit from {$assignedCounterBadge}",
                        'coe_kit_html'    => $coeKitHtml,
                        'checked_in_at'   => $visitor['day2_checked_in_at'],
                        'formatted_time'  => $timeStr,
                    ];
                } elseif ($day === 1) {
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

                    $serverResult = [
                        'success'         => true,
                        'status'          => 'success',
                        'message'         => 'Welcome to Day 1, ' . $visitor['name'] . '!',
                        'day'             => 1,
                        'name'            => $visitor['name'],
                        'mobile'          => $visitor['mobile'],
                        'user_id'         => $visitor['user_id'],
                        'fincert'         => $visitor['fincert'],
                        'region'          => $userRegion,
                        'counter_id'      => (int)($visitor['counter_id'] ?? 0),
                        'counter_name'    => $assignedCounterBadge,
                        'counter_range'   => $assignedCounterRange,
                        'counter_regions' => $assignedCounterRegions,
                        'counter_subtext' => $counterSubtext,
                        'coe_kit_text'    => "Kindly collect the COE Kit from {$assignedCounterBadge}",
                        'coe_kit_html'    => $coeKitHtml,
                        'checked_in_at'   => $checkedInAt,
                        'formatted_time'  => $formattedTime,
                    ];
                } else {
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

                    $serverResult = [
                        'success'         => true,
                        'status'          => 'success',
                        'message'         => 'Welcome to Day 2, ' . $visitor['name'] . '!',
                        'day'             => 2,
                        'name'            => $visitor['name'],
                        'mobile'          => $visitor['mobile'],
                        'user_id'         => $visitor['user_id'],
                        'fincert'         => $visitor['fincert'],
                        'region'          => $userRegion,
                        'counter_id'      => (int)($visitor['counter_id'] ?? 0),
                        'counter_name'    => $assignedCounterBadge,
                        'counter_range'   => $assignedCounterRange,
                        'counter_regions' => $assignedCounterRegions,
                        'counter_subtext' => $counterSubtext,
                        'coe_kit_text'    => "Kindly collect the COE Kit from {$assignedCounterBadge}",
                        'coe_kit_html'    => $coeKitHtml,
                        'checked_in_at'   => $checkedInAt,
                        'formatted_time'  => $formattedTime,
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log("Database check-in error in index.php: " . $e->getMessage());
            $serverResult = [
                'success' => false,
                'status'  => 'error',
                'message' => 'Service temporarily unavailable. Please try again.',
                'day'     => $day,
            ];
        }
    }

    // Return instant JSON response if called via AJAX
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
    <title>Visitor Gate Terminal | EventMS</title>
    
    <!-- SVG Favicon (Eliminates 404 on Favicon Request) -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛡️</text></svg>">

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Unified EventMS Design System (Navy Header + White Card Body) with Cache Busting -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?: time() ?>">

    <script>
    // Immediate Inline Day Switcher (Guaranteed Execution Regardless of External Script Cache)
    function switchDay(day) {
        var selectedDay = (parseInt(day, 10) === 2) ? 2 : 1;
        var dayInput = document.getElementById('dayInput');
        var day1Btn = document.getElementById('day1Btn');
        var day2Btn = document.getElementById('day2Btn');
        var submitBtn = document.getElementById('submitBtn');
        var submitDayLabel = document.getElementById('submitDayLabel');
        var resultContainer = document.getElementById('resultContainer');
        var mobileInput = document.getElementById('mobileInput');

        if (dayInput) {
            dayInput.value = selectedDay;
        }

        if (day1Btn) {
            if (selectedDay === 1) {
                day1Btn.classList.add('active');
                day1Btn.setAttribute('aria-pressed', 'true');
            } else {
                day1Btn.classList.remove('active');
                day1Btn.setAttribute('aria-pressed', 'false');
            }
        }

        if (day2Btn) {
            if (selectedDay === 2) {
                day2Btn.classList.add('active');
                day2Btn.setAttribute('aria-pressed', 'true');
            } else {
                day2Btn.classList.remove('active');
                day2Btn.setAttribute('aria-pressed', 'false');
            }
        }

        if (submitDayLabel) {
            submitDayLabel.textContent = 'Day ' + selectedDay;
        } else if (submitBtn) {
            submitBtn.innerHTML = '<i class="bi bi-shield-check me-2"></i> Verify &amp; Check In (<span id="submitDayLabel">Day ' + selectedDay + '</span>)';
        }

        // Update URL query param (?day=1 or ?day=2) without triggering page reload
        try {
            var currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('day', selectedDay);
            window.history.replaceState(null, '', currentUrl.toString());
        } catch (e) {}

        // Clear previous check-in result container on day switch
        if (resultContainer) {
            resultContainer.innerHTML = '';
        }

        // Dynamically update modal QR preview to match active day
        try {
            var modalLiveUrlText = document.getElementById('modalLiveUrlText');
            var modalQrImg = document.getElementById('modalQrImg');
            var modalDownloadPng = document.getElementById('modalDownloadPng');
            var dayUrl = window.location.origin + window.location.pathname + '?day=' + selectedDay;
            if (modalLiveUrlText) modalLiveUrlText.textContent = dayUrl;
            if (modalQrImg) modalQrImg.src = 'https://quickchart.io/qr?text=' + encodeURIComponent(dayUrl) + '&size=400&margin=2&format=svg';
            if (modalDownloadPng) modalDownloadPng.href = 'https://quickchart.io/qr?text=' + encodeURIComponent(dayUrl) + '&size=400&margin=2&format=png';
        } catch (e) {}

        // Keep focus on mobile input for fast attendance entry
        if (mobileInput) {
            mobileInput.focus();
        }
    }
    window.switchDay = switchDay;
    window.setDay = switchDay;
    </script>

    <style>
    /* Safe-Area Inset Variables */
    :root {
        --sat: env(safe-area-inset-top, 0px);
        --sar: env(safe-area-inset-right, 0px);
        --sab: env(safe-area-inset-bottom, 0px);
        --sal: env(safe-area-inset-left, 0px);
    }

    /* Page-level: strictly 100vh/100dvh, zero margins, no scrollbar */
    html, body {
        height: 100vh !important;
        height: 100dvh !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        scrollbar-width: none !important;
        -ms-overflow-style: none !important;
        box-sizing: border-box !important;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    html::-webkit-scrollbar, body::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
    }

    /* Constrained centered terminal layout */
    .mobile-terminal-wrap {
        width: 100%;
        max-width: 420px;
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        margin: 0 auto;
        padding: clamp(6px, 1.5vh, 14px) clamp(10px, 3vw, 16px);
        padding-top: max(clamp(6px, 1.5vh, 14px), var(--sat));
        padding-bottom: max(clamp(6px, 1.5vh, 14px), var(--sab));
        padding-left: max(clamp(10px, 3vw, 16px), var(--sal));
        padding-right: max(clamp(10px, 3vw, 16px), var(--sar));
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        box-sizing: border-box;
        overflow: hidden !important;
    }

    /* Card as flex column sharing height */
    .gate-card.hybrid-card {
        display: flex;
        flex-direction: column;
        width: 100%;
        max-height: calc(100dvh - clamp(10px, 2vh, 20px));
        max-height: calc(100vh - clamp(10px, 2vh, 20px));
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 16px 36px -10px rgba(15, 28, 48, 0.2);
        box-sizing: border-box;
        min-width: 0;
    }

    /* Compact Navy Header */
    .hybrid-header {
        flex-shrink: 0;
        padding: clamp(0.45rem, 1.2vh, 0.75rem) clamp(0.85rem, 2.5vw, 1.15rem) !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        box-sizing: border-box;
    }

    .hybrid-header .header-top-row {
        margin-bottom: clamp(3px, 0.8vh, 6px) !important;
    }

    .hybrid-header-icon {
        width: 30px;
        height: 30px;
        font-size: 0.95rem;
        border-radius: 8px;
    }

    .hybrid-header-pill {
        padding: 0.2rem 0.6rem;
        font-size: 0.72rem;
        touch-action: manipulation !important;
        -webkit-tap-highlight-color: transparent !important;
    }

    .hybrid-header-pill:active {
        transform: scale(0.96) !important;
    }

    .terminal-title {
        font-size: clamp(0.98rem, 2.1vh, 1.18rem);
        font-weight: 700;
        margin-bottom: 2px !important;
        color: #ffffff;
        line-height: 1.2;
    }

    .terminal-subtitle {
        font-size: clamp(0.68rem, 1.3vh, 0.76rem);
        color: rgba(255, 255, 255, 0.65);
        margin-bottom: 0 !important;
        line-height: 1.2;
    }

    /* Compact Card Body */
    .hybrid-body {
        flex: 1 1 auto;
        padding: clamp(0.45rem, 1.2vh, 0.75rem) clamp(0.75rem, 2.5vw, 1.15rem) !important;
        display: flex;
        flex-direction: column;
        justify-content: center;
        overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
        box-sizing: border-box;
        min-width: 0;
    }

    .hybrid-body::-webkit-scrollbar {
        display: none !important;
        width: 0 !important;
        height: 0 !important;
    }

    /* Day Selector */
    .day-selector-container {
        width: 100%;
        margin-bottom: clamp(4px, 0.9vh, 6px) !important;
        position: relative;
        z-index: 10;
        box-sizing: border-box;
    }

    .day-selector-track {
        display: flex;
        background: #f1f5f9;
        border: 1.5px solid #e2e8f0;
        border-radius: 9999px;
        padding: 3px;
        gap: 4px;
        position: relative;
        z-index: 10;
        box-sizing: border-box;
    }

    .day-pill-btn {
        flex: 1;
        border: none;
        background: transparent;
        padding: 0.3rem 0.55rem;
        min-height: clamp(34px, 4.2vh, 38px);
        border-radius: 9999px;
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        touch-action: manipulation !important;
        -webkit-tap-highlight-color: transparent !important;
        text-decoration: none;
        user-select: none;
        -webkit-user-select: none;
        pointer-events: auto !important;
        position: relative;
        z-index: 11;
        box-sizing: border-box;
    }

    .day-pill-btn * {
        pointer-events: none !important;
    }

    .day-pill-btn:hover {
        color: #0f172a;
    }

    .day-pill-btn:active {
        transform: scale(0.97) !important;
    }

    .day-pill-btn.active,
    .day-pill-btn#day1Btn.active,
    .day-pill-btn#day2Btn.active {
        background: #10b981 !important;
        color: #ffffff !important;
        box-shadow: 0 2px 10px rgba(16, 185, 129, 0.35) !important;
    }

    /* Result Box, Icon & Metadata */
    .result-box {
        border-radius: 12px;
        padding: clamp(0.35rem, 0.9vh, 0.55rem) 10px !important;
        text-align: center;
        margin-bottom: clamp(4px, 0.8vh, 6px) !important;
        box-sizing: border-box;
    }

    .status-icon-circle {
        width: 32px !important;
        height: 32px !important;
        border-radius: 50%;
        margin: 0 auto 0.2rem !important;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem !important;
    }

    .result-box .badge {
        font-size: 0.68rem !important;
        padding: 0.16rem 0.55rem !important;
        margin-bottom: 0.15rem !important;
        letter-spacing: 0.4px;
    }

    .result-box h5 {
        font-size: 0.96rem !important;
        margin-bottom: 0.1rem !important;
        line-height: 1.2;
    }

    .result-box p {
        font-size: 0.78rem !important;
        margin-bottom: 0.15rem !important;
        line-height: 1.2;
    }

    .result-meta-box {
        width: 100% !important;
        box-sizing: border-box !important;
        padding: 5px 8px !important;
        background: rgba(255, 255, 255, 0.85) !important;
        border: 1px solid rgba(0, 0, 0, 0.08) !important;
        border-radius: 8px !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 2px !important;
        text-align: center !important;
        font-size: 12px !important;
        line-height: 1.3 !important;
    }

    .result-meta-row-label {
        font-weight: 500 !important;
        color: #64748b !important;
        text-align: center !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1.3 !important;
    }

    .result-meta-row-datetime {
        font-weight: 700 !important;
        color: #0f1c30 !important;
        white-space: normal !important;
        word-break: normal !important;
        text-align: center !important;
        line-height: 1.3 !important;
    }

    .result-meta-row-phone {
        font-weight: 700 !important;
        color: #0f1c30 !important;
        white-space: nowrap !important;
        text-align: center !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        line-height: 1.3 !important;
    }

    /* Highlighted COE Kit Counter Badge (Problem 3) */
    .coe-kit-notice-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 8px 12px;
        margin: 6px 0;
        text-align: center;
        box-sizing: border-box;
        width: 100%;
    }
    .coe-kit-notice-title {
        font-size: 0.8rem !important;
        font-weight: 600;
        color: #475569;
        margin-bottom: 5px;
        letter-spacing: 0.2px;
    }
    .coe-kit-badge-wrap {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        margin-bottom: 3px;
    }
    .coe-kit-counter-pill {
        background: #1d4ed8 !important;
        color: #ffffff !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        padding: 6px 16px !important;
        border-radius: 999px !important;
        letter-spacing: 0.3px;
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        max-width: 100%;
        white-space: normal !important;
        word-break: break-word;
        box-shadow: 0 2px 6px rgba(29, 78, 216, 0.25);
        line-height: 1.35;
        border: none !important;
    }
    .coe-kit-subtext {
        font-size: 0.78rem !important;
        color: #64748b;
        margin-top: 3px;
        line-height: 1.35;
    }

    /* Terminal Form Controls & Input Styling */
    .terminal-label-row {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        min-height: 20px !important;
        margin-bottom: 8px !important; /* label -> 8px -> input */
    }

    .terminal-input-label {
        font-size: 12px !important;
        font-weight: 500 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        color: #64748b !important;
        margin-bottom: 0 !important;
        display: inline-flex !important;
        align-items: center !important;
    }

    .digit-counter-badge {
        font-size: 11px !important;
        font-weight: 600 !important;
        padding: 2px 8px !important;
        border-radius: 9999px !important;
        background-color: #f1f5f9 !important;
        color: #64748b !important;
        border: 1px solid #e2e8f0 !important;
        line-height: 1.4 !important;
        visibility: hidden; /* Reserved height without layout jump */
        white-space: nowrap !important;
        box-sizing: border-box !important;
    }

    .digit-counter-badge.is-valid {
        background-color: #ecfdf5 !important;
        color: #059669 !important;
        border-color: #a7f3d0 !important;
    }

    .phone-input-wrap {
        position: relative !important;
        display: flex !important;
        align-items: center !important;
        width: 100% !important;
        margin-bottom: 8px !important; /* input -> 8px -> helper text */
    }

    .phone-input-prefix-box {
        position: absolute !important;
        left: 14px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        display: none !important;
        align-items: center !important;
        gap: 6px !important;
        pointer-events: none !important;
        user-select: none !important;
        z-index: 2 !important;
    }

    .phone-input-wrap.has-prefix .phone-input-prefix-box,
    .phone-input.has-prefix ~ .phone-input-prefix-box {
        display: flex !important;
    }

    .phone-input-prefix {
        font-size: 16px !important;
        font-weight: 700 !important;
        color: #0f172a !important;
        letter-spacing: 0.5px !important;
    }

    .phone-prefix-divider {
        height: 20px !important;
        width: 1.5px !important;
        background-color: #cbd5e1 !important;
        display: inline-block !important;
        margin-left: 2px !important;
    }

    .phone-input {
        height: 52px !important;
        font-size: 17px !important;
        font-weight: 600 !important;
        color: #0f172a !important;
        letter-spacing: 0 !important;
        border-radius: 12px !important;
        border: 2px solid #cbd5e1 !important;
        background-color: #ffffff !important;
        padding: 0 16px !important;
        width: 100% !important;
        text-align: left !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        box-shadow: none !important;
        box-sizing: border-box !important;
        touch-action: manipulation !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease, padding-left 0.15s ease !important;
    }

    .phone-input-wrap.has-prefix .phone-input,
    .phone-input.has-prefix {
        padding-left: 60px !important;
        letter-spacing: 0.5px !important;
    }

    .phone-input.user-id-mode {
        padding-left: 16px !important;
        letter-spacing: 0 !important;
    }

    .phone-input::placeholder,
    .phone-input::-webkit-input-placeholder,
    .phone-input::-moz-placeholder,
    .phone-input:-ms-input-placeholder {
        font-size: 15px !important;
        font-weight: 400 !important;
        letter-spacing: normal !important;
        text-transform: none !important;
        color: #94a3b8 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        opacity: 1 !important;
    }

    .phone-input:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
        outline: none !important;
    }

    .terminal-helper-text {
        font-size: 13px !important;
        font-weight: 400 !important;
        color: #94a3b8 !important;
        text-align: center !important;
        line-height: 1.4 !important;
        margin-bottom: 16px !important; /* helper text -> 16px -> primary button */
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .terminal-primary-btn-wrap {
        margin-bottom: 12px !important; /* primary button -> 12px -> QR button */
    }

    .terminal-secondary-btn-wrap {
        margin-bottom: 0 !important;
    }

    .btn-checkin {
        height: clamp(44px, 4.8vh, 48px) !important;
        font-size: 0.95rem !important;
        margin-top: clamp(4px, 0.9vh, 6px) !important;
        touch-action: manipulation !important;
        -webkit-tap-highlight-color: transparent !important;
        box-sizing: border-box !important;
    }

    .btn-checkin:active {
        transform: scale(0.97) !important;
    }

    .btn-pill-white {
        height: clamp(38px, 4.2vh, 42px) !important;
        font-size: 0.88rem !important;
        touch-action: manipulation !important;
        -webkit-tap-highlight-color: transparent !important;
        box-sizing: border-box !important;
    }

    .btn-pill-white:active {
        transform: scale(0.97) !important;
    }

    /* Small Screen / Short Viewport Rules (e.g. 320x568, 360x640) */
    @media (max-height: 600px) {
        .mobile-terminal-wrap {
            padding: clamp(3px, 0.8vh, 8px) 10px !important;
        }
        .gate-card.hybrid-card {
            border-radius: 16px !important;
            max-height: calc(100dvh - 8px) !important;
        }
        .hybrid-header {
            padding: 0.35rem 0.75rem !important;
        }
        .hybrid-header .header-top-row {
            margin-bottom: 2px !important;
        }
        .hybrid-header-icon {
            width: 24px !important;
            height: 24px !important;
            font-size: 0.82rem !important;
        }
        .terminal-title {
            font-size: 0.96rem !important;
            margin-bottom: 0 !important;
        }
        .terminal-subtitle {
            font-size: 0.68rem !important;
        }
        .hybrid-body {
            padding: 0.4rem 0.75rem !important;
        }
        .day-selector-container {
            margin-bottom: 3px !important;
        }
        .day-pill-btn {
            min-height: 32px !important;
            font-size: 0.74rem !important;
            padding: 0.2rem 0.4rem !important;
        }
        .result-box {
            padding: 0.3rem 8px !important;
            margin-bottom: 4px !important;
        }
        .status-icon-circle {
            width: 26px !important;
            height: 26px !important;
            font-size: 0.95rem !important;
            margin-bottom: 0.1rem !important;
        }
        .result-box h5 {
            font-size: 0.88rem !important;
        }
        .result-meta-box {
            padding: 4px 6px !important;
            font-size: 11.5px !important;
            gap: 1px !important;
        }
        .phone-input {
            height: 44px !important;
            font-size: 16px !important;
        }
        .phone-input-wrap.has-prefix .phone-input,
        .phone-input.has-prefix {
            padding-left: 56px !important;
        }
        .terminal-label-row {
            margin-bottom: 5px !important;
        }
        .phone-input-wrap {
            margin-bottom: 5px !important;
        }
        .terminal-helper-text {
            margin-bottom: 12px !important;
            font-size: 12px !important;
        }
        .terminal-primary-btn-wrap {
            margin-bottom: 8px !important;
        }
        .btn-checkin {
            height: 42px !important;
            font-size: 0.88rem !important;
            margin-top: 0 !important;
        }
        .btn-pill-white {
            height: 36px !important;
            font-size: 0.82rem !important;
        }
    }

    /* Micro-Height / Mobile Landscape Rules (e.g. 844x390, 640x360) */
    @media (max-height: 460px) {
        .hybrid-header .header-top-row {
            display: none !important;
        }
        .hybrid-header {
            padding: 0.25rem 0.65rem !important;
        }
        .terminal-title {
            font-size: 0.88rem !important;
        }
        .terminal-subtitle {
            display: none !important;
        }
        .hybrid-body {
            padding: 0.3rem 0.65rem !important;
        }
        .day-pill-btn {
            min-height: 28px !important;
            font-size: 0.72rem !important;
            padding: 0.15rem 0.35rem !important;
        }
        .phone-input {
            height: 38px !important;
            font-size: 16px !important;
        }
        .phone-input-wrap.has-prefix .phone-input,
        .phone-input.has-prefix {
            padding-left: 54px !important;
        }
        .terminal-label-row {
            margin-bottom: 4px !important;
        }
        .phone-input-wrap {
            margin-bottom: 4px !important;
        }
        .terminal-helper-text {
            display: none !important;
        }
        .terminal-primary-btn-wrap {
            margin-bottom: 6px !important;
        }
        .btn-checkin {
            height: 36px !important;
            font-size: 0.82rem !important;
        }
        .btn-pill-white {
            height: 30px !important;
            font-size: 0.76rem !important;
        }
    }
    </style>
</head>
<body>

<!-- Mobile Viewport Constrained Terminal Container (Max 420px, 100dvh, Safe-Area Padded) -->
<div class="mobile-terminal-wrap">
    
    <!-- Hybrid Card: Solid Dark Navy #0f1c30 Header + Pure White #ffffff Body -->
    <div class="gate-card hybrid-card">
        
        <!-- 1. Header: Solid Dark Navy #0f1c30, Glowing Shield Icon, Badge Pill, QR Toggle -->
        <div class="hybrid-header">
            <div class="d-flex justify-content-between align-items-center header-top-row">
                <div class="d-flex align-items-center gap-2">
                    <div class="hybrid-header-icon">
                        <i class="bi bi-shield-lock-fill"></i>
                    </div>
                    <span class="hybrid-header-pill">
                        Gatepass
                    </span>
                </div>
                <button type="button" class="btn hybrid-header-pill" data-bs-toggle="modal" data-bs-target="#gateQrModal" title="Open QR Code">
                    <i class="bi bi-camera me-1"></i> QR Code
                </button>
            </div>
            <h5 class="terminal-title">Visitor Gate Terminal</h5>
            <p class="terminal-subtitle">Enter registered mobile number or Register Id</p>
        </div>

        <!-- 2. Card Body: Clean Pure White #ffffff with High-Contrast Text -->
        <div class="hybrid-body">

            <!-- Day Selector Pill Switch (Day 1 / Day 2 Entry) -->
            <div class="day-selector-container">
                <div class="day-selector-track">
                    <button type="button" class="day-pill-btn <?= $currentDay === 1 ? 'active' : '' ?>" id="day1Btn" data-day="1" aria-pressed="<?= $currentDay === 1 ? 'true' : 'false' ?>" onclick="switchDay(1)">
                        <i class="bi bi-calendar-event me-1"></i> Day 1 Entry
                    </button>
                    <button type="button" class="day-pill-btn <?= $currentDay === 2 ? 'active' : '' ?>" id="day2Btn" data-day="2" aria-pressed="<?= $currentDay === 2 ? 'true' : 'false' ?>" onclick="switchDay(2)">
                        <i class="bi bi-calendar2-check me-1"></i> Day 2 Entry
                    </button>
                </div>
            </div>

            <!-- Dynamic Result Box (Allowed / Duplicate / Denied) -->
            <div id="resultContainer">
                <?php if ($serverResult): ?>
                    <?php if ($serverResult['status'] === 'success'): ?>
                        <?php 
                            $resDay = (int)($serverResult['day'] ?? $currentDay);
                        ?>
                        <div class="result-box status-success shadow-sm mb-2">
                            <div class="status-icon-circle">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">DAY <?= $resDay ?> ENTRY APPROVED</span>
                            <h5 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h5>
                            <p class="small fw-bold mb-1 text-success">Welcome to Day <?= $resDay ?>, <?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?>!</p>
                            <?php if (!empty($serverResult['fincert'])): ?>
                                <div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.95rem; line-height: 1.3;">
                                    Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($serverResult['fincert']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($serverResult['coe_kit_html'])): ?>
                                <?= $serverResult['coe_kit_html'] ?>
                            <?php else: ?>
                                <?= renderCoeKitLine($serverResult['counter_name'] ?? $serverResult['coe_kit_text'] ?? $serverResult['region'] ?? '', $serverResult['counter_subtext'] ?? '') ?>
                            <?php endif; ?>
                            <div class="result-meta-box">
                                <div class="result-meta-row-label"><i class="bi bi-clock me-1 text-success"></i>Day <?= $resDay ?> Checked In</div>
                                <div class="result-meta-row-datetime"><?= htmlspecialchars($serverResult['formatted_time'] ?? formatIST($serverResult['checked_in_at'] ?? 'now', 'd M Y, h:i A')) ?></div>
                                <div class="result-meta-row-phone">
                                    <?php if (!empty($serverResult['mobile'])): ?>
                                        <i class="bi bi-telephone me-1 text-primary"></i>+91 <?= htmlspecialchars($serverResult['mobile']) ?>
                                    <?php else: ?>
                                        <i class="bi bi-person-badge me-1 text-primary"></i>Register Id: <?= htmlspecialchars($serverResult['user_id'] ?? '') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($serverResult['status'] === 'duplicate'): ?>
                        <div class="result-box status-duplicate shadow-sm mb-2">
                            <div class="status-icon-circle">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">DAY <?= (int)($serverResult['day'] ?? $currentDay) ?> ALREADY CHECKED IN</span>
                            <h5 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h5>
                            <p class="small fw-bold text-warning-emphasis mb-1.5"><?= htmlspecialchars($serverResult['message']) ?></p>
                            <?php if (!empty($serverResult['fincert'])): ?>
                                <div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.9rem; line-height: 1.3;">
                                    Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($serverResult['fincert']) ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($serverResult['coe_kit_html'])): ?>
                                <?= $serverResult['coe_kit_html'] ?>
                            <?php else: ?>
                                <?= renderCoeKitLine($serverResult['counter_name'] ?? $serverResult['coe_kit_text'] ?? $serverResult['region'] ?? '', $serverResult['counter_subtext'] ?? '') ?>
                            <?php endif; ?>
                            <div class="result-meta-box">
                                <div class="result-meta-row-label"><i class="bi bi-clock-history me-1 text-warning"></i>Prior Check-in</div>
                                <div class="result-meta-row-datetime"><?= htmlspecialchars($serverResult['formatted_time'] ?? 'Recorded') ?></div>
                                <div class="result-meta-row-phone">
                                    <?php if (!empty($serverResult['mobile'])): ?>
                                        <i class="bi bi-telephone me-1 text-primary"></i>+91 <?= htmlspecialchars($serverResult['mobile']) ?>
                                    <?php else: ?>
                                        <i class="bi bi-person-badge me-1 text-primary"></i>Register Id: <?= htmlspecialchars($serverResult['user_id'] ?? '') ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($serverResult['status'] === 'multiple_matches'): ?>
                        <div class="result-box status-duplicate shadow-sm mb-2" style="background:#fffbeb;border-color:#fde68a;">
                            <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                                <i class="bi bi-people-fill"></i>
                            </div>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">MULTIPLE REGISTRATIONS</span>
                            <h5 class="fw-bold mb-1 text-dark" style="font-size:0.9rem;line-height:1.35;"><?= htmlspecialchars($serverResult['message']) ?></h5>
                            <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-1 px-2 mb-0 small" style="font-size: 0.73rem;">
                                <i class="bi bi-shield-exclamation me-1"></i> Shared mobile number detected. Please enter your unique Register Id (e.g. OS_...) to confirm entry.
                            </div>
                        </div>
                    <?php elseif ($serverResult['status'] === 'not_found'): ?>
                        <div class="result-box status-denied shadow-sm mb-2">
                            <div class="status-icon-circle">
                                <i class="bi bi-slash-circle-fill"></i>
                            </div>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-0.5 rounded-pill mb-1 fw-bold text-uppercase" style="letter-spacing: 0.4px;">ACCESS REJECTED</span>
                            <h5 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['message']) ?></h5>
                            <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger-emphasis py-1 px-2 mb-0 small" style="font-size: 0.73rem;">
                                <i class="bi bi-info-circle me-1"></i> Number or Register Id not registered in allowed visitor list.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center rounded-3 shadow-sm py-1.5 px-2 mb-2" role="alert">
                            <i class="bi bi-exclamation-circle-fill fs-6 me-2 text-warning"></i>
                            <div>
                                <div class="fw-bold small">Validation Notice</div>
                                <div class="small" style="font-size: 0.75rem;"><?= htmlspecialchars($serverResult['message'] ?? 'Validation error') ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Gate Check-In Form (Mobile or Register Id Input) -->
            <form id="gateCheckInForm" method="POST" action="index.php" novalidate>
                <input type="hidden" name="day" id="dayInput" value="<?= $currentDay ?>">
                
                <div class="input-section-wrap" style="margin-bottom: 0;">
                    <div class="terminal-label-row">
                        <label for="mobileInput" class="terminal-input-label">
                            <i class="bi bi-person-bounding-box me-1 text-primary"></i> MOBILE NUMBER OR REGISTER ID
                        </label>
                        <span id="digitCounter" class="digit-counter-badge">
                            0 / 10 digits
                        </span>
                    </div>

                    <!-- Input Field (Supports Mobile with +91 or Register Id with auto-switch) -->
                    <div class="phone-input-wrap position-relative" id="phoneInputWrap">
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

                <!-- 4. Primary CTA: Electric Royal Blue (#1d58d8) with Day Context -->
                <div class="d-grid terminal-primary-btn-wrap">
                    <button type="submit" id="submitBtn" class="btn btn-checkin">
                        <i class="bi bi-shield-check me-2"></i> Verify &amp; Check In (<span id="submitDayLabel">Day <?= $currentDay ?></span>)
                    </button>
                </div>

                <!-- 5. Secondary CTA: Clean White Pill Button (btn-pill-white) -->
                <div class="d-grid terminal-secondary-btn-wrap">
                    <button type="button" class="btn btn-pill-white" data-bs-toggle="modal" data-bs-target="#gateQrModal">
                        <i class="bi bi-qr-code-scan me-2 text-primary"></i> Scan / Show Gatepass QR Code
                    </button>
                </div>

            </form>

        </div>
    </div>

</div>

<!-- High-Resolution QR Code Modal (Hybrid Navy Header + Pure White Body) -->
<div class="modal fade" id="gateQrModal" tabindex="-1" aria-labelledby="gateQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered px-3" style="max-width: 400px; margin: auto;">
        <div class="modal-content hybrid-modal overflow-hidden">
            <div class="modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <div class="hybrid-header-icon" style="width: 32px; height: 32px; font-size: 1rem;">
                        <i class="bi bi-qr-code-scan"></i>
                    </div>
                    <h6 class="modal-title fw-bold text-white mb-0" id="gateQrModalLabel">Visitor Self Check-In QR</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="qr-frame mb-3">
                    <img 
                        id="modalQrImg"
                        src="<?= htmlspecialchars($qrCodeSvg) ?>" 
                        alt="Gate Check-In QR Code" 
                        width="230" 
                        height="230" 
                        class="img-fluid rounded-2"
                        loading="eager"
                    >
                </div>
                <h6 class="fw-bold mb-1" style="color: #0f1c30;">Scan with Smartphone Camera</h6>
                <p class="text-muted small mb-3">Point mobile camera to open this terminal directly on any device.</p>

                <!-- URL Preview -->
                <div class="p-2 bg-light rounded-3 border text-truncate small text-primary mb-3 user-select-all fw-semibold">
                    <i class="bi bi-link-45deg me-1"></i>
                    <span id="modalLiveUrlText"><?= htmlspecialchars($currentDayLiveUrl) ?></span>
                </div>

                <!-- Modal Actions -->
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" id="modalCopyBtn">
                        <i class="bi bi-clipboard me-1"></i> Copy Link
                    </button>
                    <a id="modalDownloadPng" href="<?= htmlspecialchars($qrCodePng) ?>" download="visitor-gatepass-qr.png" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-download me-1"></i> Save PNG
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5.3.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Client Controller with Cache-Busting Version Query Parameter -->
<script src="assets/js/main.js?v=<?= filemtime(__DIR__ . '/assets/js/main.js') ?: time() ?>"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalCopyBtn = document.getElementById('modalCopyBtn');

    if (modalCopyBtn) {
        modalCopyBtn.addEventListener('click', async () => {
            const dayInput = document.getElementById('dayInput');
            const activeDay = dayInput ? dayInput.value : '1';
            const liveUrl = window.location.origin + window.location.pathname + '?day=' + activeDay;
            try {
                await navigator.clipboard.writeText(liveUrl);
                modalCopyBtn.innerHTML = `<i class="bi bi-check2 text-success me-1"></i> Copied!`;
                setTimeout(() => {
                    modalCopyBtn.innerHTML = `<i class="bi bi-clipboard me-1"></i> Copy Link`;
                }, 2000);
            } catch (e) {
                prompt("Copy URL manually:", liveUrl);
            }
        });
    }
});
</script>

</body>
</html>
