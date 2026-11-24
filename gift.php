<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

$pdo = Database::getConnection();

// =========================================================================
// Dynamic Full Live URL Resolution (matches index.php pattern)
// =========================================================================
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
);

$protocol    = $isHttps ? 'https://' : 'http://';
$host        = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$scriptDir   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$currentLiveUrl = $protocol . $host . ($scriptDir !== '' ? $scriptDir : '') . '/gift.php';

// QuickChart high-res QR endpoints
$qrCodeSvg = 'https://quickchart.io/qr?text=' . urlencode($currentLiveUrl) . '&size=400&margin=2&format=svg';
$qrCodePng = 'https://quickchart.io/qr?text=' . urlencode($currentLiveUrl) . '&size=400&margin=2&format=png';

// =========================================================================
// Session & Counter Authentication Guard
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 43200, // 12 hours
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$isLoggedIn      = !empty($_SESSION['admin_logged_in']);
$counterUsername = (string)($_SESSION['admin_username'] ?? '');
$userRole        = (string)($_SESSION['user_role'] ?? '');
$counterRegion   = trim((string)($_SESSION['counter_region'] ?? ''));
$isCounter       = ($userRole === 'counter');
$isSuperAdmin    = ($userRole === 'super_admin');
$hasTerminalAccess = $isLoggedIn && ($isCounter || $isSuperAdmin);

$counterId    = !empty($_SESSION['counter_id']) ? (int)$_SESSION['counter_id'] : 0;
$counterNo    = !empty($_SESSION['counter_no']) ? (int)$_SESSION['counter_no'] : 0;
$counterName  = (string)($_SESSION['counter_name'] ?? '');
$counterRange = (string)($_SESSION['counter_range'] ?? '');
$counterCode  = (string)($_SESSION['counter_code'] ?? '');

if ($isCounter && ($counterId === 0 || $counterName === '' || $counterNo === 0)) {
    $cStmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM `counters` WHERE username = :u LIMIT 1");
    $cStmt->execute([':u' => $counterUsername]);
    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($cRow) {
        $counterId    = (int)$cRow['id'];
        $counterNo    = !empty($cRow['counter_no']) ? (int)$cRow['counter_no'] : 0;
        $counterName  = $cRow['name'];
        $counterRange = (string)($cRow['name_range'] ?? '');
        $counterCode  = $cRow['code'];
        $_SESSION['counter_id']    = $counterId;
        $_SESSION['counter_no']    = $counterNo;
        $_SESSION['counter_name']  = $counterName;
        $_SESSION['counter_range'] = $counterRange;
        $_SESSION['counter_code']  = $counterCode;
    }
}

$counterTitle = 'Unassigned Counter';
if ($isSuperAdmin) {
    $counterTitle = 'Super Admin (All Counters)';
} elseif ($isCounter) {
    $cBadge = formatCounterDisplayName($counterNo, $counterName);
    if (!empty($counterRange)) {
        $counterTitle = "{$cBadge} ({$counterRange})";
    } elseif (!empty($cBadge)) {
        $counterTitle = $cBadge;
    } else {
        $counterTitle = ($counterRegion !== '' && $counterRegion !== 'Help Desk') 
            ? ($counterRegion . ' Counter') 
            : 'Help Desk Counter';
    }
}

// =========================================================================
// Gift Distribution Validation Logic
// =========================================================================
$serverResult = null;
$inputMobile  = '';

$isJsonRequest = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check authentication
    if (!$hasTerminalAccess) {
        if ($isJsonRequest) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'   => false,
                'status'    => 'auth_required',
                'message'   => 'Counter login required. Please sign in with your regional counter credentials to serve visitors.',
                'login_url' => 'login.php?redirect=gift.php'
            ]);
            exit;
        }
        header('Location: login.php?redirect=gift.php');
        exit;
    }

    // Support JSON payload or standard POST
    $rawBody  = file_get_contents('php://input');
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

    $rawInput   = trim((string)($postData['identifier'] ?? $postData['mobile'] ?? ''));
    $inputMobile = $rawInput;

    if ($rawInput === '') {
        $serverResult = [
            'success' => false,
            'status'  => 'invalid_input',
            'message' => 'Please enter a valid mobile number or Register Id.',
            'mobile'  => $rawInput,
        ];
    } else {
        try {
            $visitor = null;

            // Detection rule: Pure numeric (after stripping spaces, dashes, +91) => Mobile number
            // Any letters, @, symbols or non-digit chars => Register Id
            $strippedForCheck = preg_replace('/[\s\-()]/', '', $rawInput);
            if (str_starts_with($strippedForCheck, '+91')) {
                $strippedForCheck = substr($strippedForCheck, 3);
            } elseif (str_starts_with($strippedForCheck, '+')) {
                $strippedForCheck = substr($strippedForCheck, 1);
            }
            $isPureNumeric = ($strippedForCheck !== '' && ctype_digit($strippedForCheck));

            if ($isPureNumeric) {
                $mobile = $strippedForCheck;
                if (strlen($mobile) === 12 && str_starts_with($mobile, '91')) {
                    $mobile = substr($mobile, 2);
                } elseif (strlen($mobile) === 11 && str_starts_with($mobile, '0')) {
                    $mobile = substr($mobile, 1);
                }

                $stmt = $pdo->prepare(
                    "SELECT g.id, g.name, g.mobile, g.user_id, g.fincert, g.region, g.counter_id,
                            g.is_gift_collected, g.gift_collected_at, g.handed_over_by, g.counter_region,
                            c.counter_no AS assigned_counter_no, c.name AS assigned_counter_name, c.name_range AS assigned_counter_range,
                            (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered
                     FROM `gift_distribution` g
                     LEFT JOIN `counters` c ON c.id = g.counter_id
                     LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
                     WHERE g.mobile = :mobile"
                );
                $stmt->execute([':mobile' => $mobile]);
                $matches = $stmt->fetchAll();
                if (count($matches) > 1) {
                    $serverResult = [
                        'success' => false,
                        'status'  => 'duplicate',
                        'message' => 'Multiple visitors share this mobile number. Please enter your Register Id to claim COE Kit.',
                        'mobile'  => $mobile,
                    ];
                } elseif (count($matches) === 1) {
                    $visitor = $matches[0];
                } else {
                    // Fallback: check if the numeric string matches user_id
                    $stmtUid = $pdo->prepare(
                        "SELECT g.id, g.name, g.mobile, g.user_id, g.fincert, g.region, g.counter_id,
                                g.is_gift_collected, g.gift_collected_at, g.handed_over_by, g.counter_region,
                                c.counter_no AS assigned_counter_no, c.name AS assigned_counter_name, c.name_range AS assigned_counter_range,
                                (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered
                         FROM `gift_distribution` g
                         LEFT JOIN `counters` c ON c.id = g.counter_id
                         LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
                         WHERE TRIM(LOWER(g.user_id)) = TRIM(LOWER(:uid))
                         LIMIT 1"
                    );
                    $stmtUid->execute([':uid' => $rawInput]);
                    $visitor = $stmtUid->fetch();
                }
            } else {
                // Register Id lookup: case-insensitive match (trimming and collapsing spaces)
                $normInput = preg_replace('/\s+/', ' ', trim($rawInput));
                $stmt = $pdo->prepare(
                    "SELECT g.id, g.name, g.mobile, g.user_id, g.fincert, g.region, g.counter_id,
                            g.is_gift_collected, g.gift_collected_at, g.handed_over_by, g.counter_region,
                            c.counter_no AS assigned_counter_no, c.name AS assigned_counter_name, c.name_range AS assigned_counter_range,
                            (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered
                     FROM `gift_distribution` g
                     LEFT JOIN `counters` c ON c.id = g.counter_id
                     LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
                     WHERE TRIM(LOWER(g.user_id)) = TRIM(LOWER(:uid))
                        OR REPLACE(TRIM(LOWER(g.user_id)), ' ', '') = REPLACE(TRIM(LOWER(:uid_nospace)), ' ', '')
                     LIMIT 1"
                );
                $stmt->execute([':uid' => $normInput, ':uid_nospace' => $normInput]);
                $visitor = $stmt->fetch();
            }

            if ($serverResult === null) {
                if (!$visitor) {
                    // Validation Rule 1: Not Found
                    $serverResult = [
                        'success' => false,
                        'status'  => 'not_found',
                        'message' => 'Access Denied: Not registered or eligible for COE Kit',
                        'mobile'  => $rawInput,
                    ];
                } else {
                    // Validation Rule 2: Counter Matching
                    $vCounterId    = !empty($visitor['counter_id']) ? (int)$visitor['counter_id'] : 0;
                    $vCounterNo    = !empty($visitor['assigned_counter_no']) ? (int)$visitor['assigned_counter_no'] : 0;
                    $vCounterName  = trim((string)($visitor['assigned_counter_name'] ?? ''));
                    $vCounterRange = trim((string)($visitor['assigned_counter_range'] ?? ''));
                    $vRegion       = trim((string)($visitor['region'] ?? ''));

                    // Fallback resolution if counter is unassigned or missing
                    if ($vCounterName === '' || $vCounterNo === 0) {
                        $resolved = Database::resolveCounterForVisitor($vRegion, (string)$visitor['name'], $pdo);
                        if ($resolved) {
                            $vCounterId    = (int)($resolved['id'] ?? 0);
                            $vCounterNo    = !empty($resolved['counter_no']) ? (int)$resolved['counter_no'] : 0;
                            $vCounterName  = $resolved['name'];
                            $vCounterRange = (string)($resolved['name_range'] ?? '');
                            if ($vCounterId > 0 && !empty($visitor['id'])) {
                                try {
                                    $pdo->prepare("UPDATE `gift_distribution` SET `counter_id` = :cid WHERE `id` = :gid AND (`counter_id` IS NULL OR `counter_id` = 0)")
                                        ->execute([':cid' => $vCounterId, ':gid' => (int)$visitor['id']]);
                                } catch (\Throwable $t) {}
                            }
                        }
                    }

                    $vCounterBadge = formatCounterDisplayName($vCounterNo, $vCounterName);

                    $isAllowedCounter     = true;
                    $mismatchMessage      = '';
                    $targetCounterDisplay = $vCounterBadge;

                    if ($isCounter) {
                        if ($vCounterId !== $counterId) {
                            $isAllowedCounter = false;
                            $vNameEsc = htmlspecialchars((string)$visitor['name'], ENT_QUOTES, 'UTF-8');
                            if ($vCounterName === 'Help Desk' || $vRegion === '' || $vCounterBadge === 'Help Desk') {
                                $targetCounterDisplay = 'Help Desk';
                                $mismatchMessage = "{$vNameEsc}, your region is unassigned. Please go to the <strong>Help Desk</strong>.";
                            } else {
                                $rangeSuffix = (!empty($vCounterRange)) ? " ({$vCounterRange})" : '';
                                $targetWithRange = htmlspecialchars($vCounterBadge . $rangeSuffix, ENT_QUOTES, 'UTF-8');
                                $targetShort     = htmlspecialchars($vCounterBadge, ENT_QUOTES, 'UTF-8');
                                $targetCounterDisplay = $vCounterBadge . $rangeSuffix;
                                $mismatchMessage = "{$vNameEsc}, your COE Kit counter is <strong>{$targetWithRange}</strong>. Please go to <strong>{$targetShort}</strong>.";
                            }
                        }
                    }

                    if (!$isAllowedCounter) {
                        $serverResult = [
                            'success'        => false,
                            'status'         => 'wrong_counter',
                            'message'        => $mismatchMessage,
                            'name'           => $visitor['name'],
                            'mobile'         => $visitor['mobile'],
                            'user_id'        => $visitor['user_id'] ?? '',
                            'fincert'        => $visitor['fincert'] ?? '',
                            'visitor_region' => $vRegion,
                            'counter_name'   => $vCounterBadge,
                            'counter_display'=> $targetCounterDisplay,
                        ];
                    } elseif ((int)$visitor['is_gift_collected'] === 1) {
                        // Validation Rule 3: Already Collected
                        $timeStr = !empty($visitor['gift_collected_at'])
                            ? formatIST($visitor['gift_collected_at'], 'd M Y, h:i A')
                            : 'Previously';

                        $serverResult = [
                            'success'           => false,
                            'status'            => 'duplicate',
                            'message'           => 'Already COE Kit Ghetla Aahe!',
                            'name'              => $visitor['name'],
                            'mobile'            => $visitor['mobile'],
                            'user_id'           => $visitor['user_id'] ?? '',
                            'fincert'           => $visitor['fincert'] ?? '',
                            'gift_collected_at' => $visitor['gift_collected_at'],
                            'formatted_time'    => $timeStr,
                            'handed_over_by'    => $visitor['handed_over_by'] ?? '',
                            'counter_region'    => $visitor['counter_region'] ?? '',
                        ];
                    } elseif ((int)($visitor['is_gate_entered'] ?? 0) !== 1) {
                        // Gate Entry Check: Visitor has not checked in at the gate yet
                        $serverResult = [
                            'success'        => false,
                            'status'         => 'not_entered',
                            'message'        => "{$visitor['name']}, your gate entry is not done yet. Please complete entry at the gate first, then come back for your COE Kit.",
                            'name'           => $visitor['name'],
                            'mobile'         => $visitor['mobile'],
                            'user_id'        => $visitor['user_id'] ?? '',
                            'fincert'        => $visitor['fincert'] ?? '',
                            'visitor_region' => $vRegion,
                            'counter_name'   => $vCounterBadge,
                            'counter_display'=> $targetCounterDisplay,
                        ];
                    } else {
                        // Validation Rule 4: First-Time — Approve & Update with handover metadata
                        $counterBadge = formatCounterDisplayName($counterNo, $counterName);
                        $actingCounterName = $isSuperAdmin ? 'Super Admin' : ($counterBadge ?: 'Help Desk');
                        $displayCounterName = $isSuperAdmin ? ($vCounterBadge ?: 'Super Admin') : $counterBadge;

                        $updateStmt = $pdo->prepare(
                            "UPDATE `gift_distribution`
                             SET is_gift_collected = 1,
                                 gift_collected_at = UTC_TIMESTAMP(),
                                 handed_over_by = :who,
                                 counter_region = :creg,
                                 counter_id = COALESCE(counter_id, :cid)
                             WHERE id = :id AND is_gift_collected = 0"
                        );
                        $updateStmt->execute([
                            ':id'   => $visitor['id'],
                            ':who'  => $counterUsername ?: 'admin',
                            ':creg' => $actingCounterName,
                            ':cid'  => $counterId ?: ($vCounterId ?: null)
                        ]);

                        // Fetch confirmed timestamp
                        $tsStmt = $pdo->prepare(
                            "SELECT gift_collected_at FROM `gift_distribution` WHERE id = :id"
                        );
                        $tsStmt->execute([':id' => $visitor['id']]);
                        $collectedAt   = $tsStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s');
                        $formattedTime = formatIST($collectedAt, 'd M Y, h:i A');

                        $serverResult = [
                            'success'           => true,
                            'status'            => 'success',
                            'message'           => 'COE Kit Handover Approved!',
                            'name'              => $visitor['name'],
                            'mobile'            => $visitor['mobile'],
                            'user_id'           => $visitor['user_id'] ?? '',
                            'fincert'           => $visitor['fincert'] ?? '',
                            'gift_collected_at' => $collectedAt,
                            'formatted_time'    => $formattedTime,
                            'counter_name'      => $displayCounterName,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $serverResult = [
                'success' => false,
                'status'  => 'error',
                'message' => 'Database error: ' . $e->getMessage(),
            ];
        }
    }

    // Return JSON response if called via AJAX
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
    <title>COE Kit Distribution Terminal | EventMS</title>

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Unified EventMS Design System (Navy Header + White Card Body) -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Mobile Viewport Constrained Terminal Container -->
<div class="mobile-terminal-wrap">

    <!-- Hybrid Card: Solid Dark Navy #0f1c30 Header + Pure White #ffffff Body -->
    <div class="gate-card hybrid-card">

        <!-- 1. Header: Solid Dark Navy #0f1c30 -->
        <div class="hybrid-header">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="hybrid-header-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>
                    <span class="hybrid-header-pill">
                        COE Kit Terminal<?= $hasTerminalAccess ? (' &middot; ' . htmlspecialchars($counterTitle)) : '' ?>
                    </span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn hybrid-header-pill" data-bs-toggle="modal" data-bs-target="#giftQrModal" title="Open QR Code">
                        <i class="bi bi-qr-code me-1"></i> QR
                    </button>
                    <?php if ($hasTerminalAccess): ?>
                        <a href="logout.php?redirect=gift.php" class="btn hybrid-header-pill text-danger-emphasis" title="Sign out of counter">
                            <i class="bi bi-box-arrow-right me-1"></i> Logout
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <h4 class="fw-bold mb-1 text-white">COE Kit Distribution Terminal</h4>
            <p class="text-white-50 small mb-0">
                <?php if ($hasTerminalAccess): ?>
                    Active Counter: <strong class="text-white"><?= htmlspecialchars($counterTitle) ?></strong>
                <?php else: ?>
                    Authorized Regional Counter Access Only
                <?php endif; ?>
            </p>
        </div>

        <!-- 2. Card Body: Clean Pure White #ffffff -->
        <div class="hybrid-body">

            <?php if (!$hasTerminalAccess): ?>
                <!-- Counter Authentication Required Screen -->
                <div class="text-center py-4">
                    <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;width:64px;height:64px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:1.75rem;margin-bottom:1rem;">
                        <i class="bi bi-lock-fill"></i>
                    </div>
                    <h4 class="fw-bold mb-2" style="color: #0f1c30;">Counter Login Required</h4>
                    <p class="text-muted small mb-4" style="max-width: 330px; margin: 0 auto 1.5rem auto; line-height: 1.5;">
                        COE Kits are distributed regionally at dedicated counters. Please sign in with your regional counter credentials to serve visitors.
                    </p>
                    <a href="login.php?redirect=gift.php" class="btn btn-checkin py-2.5 px-4" style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="bi bi-box-arrow-in-right"></i> Sign In to Regional Counter
                    </a>
                </div>
            <?php else: ?>

                <!-- Dynamic Result Box -->
                <div id="resultContainer">
                    <?php if ($serverResult): ?>
                        <?php if ($serverResult['status'] === 'success'): ?>
                            <div class="result-box status-success shadow-sm mb-3">
                                <div class="status-icon-circle">
                                    <i class="bi bi-check-circle-fill"></i>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">COE KIT APPROVED</span>
                                <h4 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h4>
                                <p class="fs-6 fw-bold mb-2 text-success"><?= htmlspecialchars($serverResult['message']) ?></p>
                                <?php 
                                    $fincertVal = trim((string)($serverResult['fincert'] ?? ''));
                                    if ($fincertVal !== ''):
                                ?>
                                    <div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.95rem; line-height: 1.3;">
                                        Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($fincertVal) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="p-2 bg-light rounded-3 border d-inline-block small text-secondary mt-2">
                                    <i class="bi bi-box-seam me-1 text-primary"></i> Counter: <strong class="text-dark"><?= htmlspecialchars($serverResult['counter_name'] ?? $counterTitle) ?></strong>
                                    <span class="mx-2 text-muted">|</span>
                                    <i class="bi bi-clock me-1 text-success"></i> Handed At: <strong class="text-dark"><?= htmlspecialchars($serverResult['formatted_time'] ?? '') ?></strong>
                                    <span class="mx-2 text-muted">|</span>
                                    <?php if (!empty($serverResult['mobile'])): ?>
                                        <i class="bi bi-telephone me-1 text-primary"></i> <strong class="text-dark">+91 <?= htmlspecialchars($serverResult['mobile']) ?></strong>
                                    <?php else: ?>
                                        <i class="bi bi-person-badge me-1 text-primary"></i> Register Id: <strong class="text-dark"><?= htmlspecialchars($serverResult['user_id'] ?? '') ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($serverResult['status'] === 'wrong_counter'): ?>
                            <div class="result-box status-duplicate shadow-sm mb-3" style="background:#fffbeb;border:1.5px solid #fcd34d;">
                                <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </div>
                                <span class="badge bg-warning text-dark border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">WRONG COUNTER</span>
                                <h4 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h4>
                                <p class="fs-6 fw-bold text-warning-emphasis mb-2"><?= $serverResult['message'] ?></p>
                                <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-2 px-3 mb-0 small text-start">
                                    <i class="bi bi-signpost-split-fill me-1 text-warning"></i>
                                    Please direct this attendee to <strong><?= htmlspecialchars($serverResult['counter_name'] ?? 'their assigned counter') ?></strong>.
                                </div>
                            </div>
                        <?php elseif ($serverResult['status'] === 'not_entered'): ?>
                            <div class="result-box status-duplicate shadow-sm mb-3" style="background:#fffbeb;border:1.5px solid #fcd34d;">
                                <div class="status-icon-circle" style="background:#fef3c7;color:#d97706;">
                                    <i class="bi bi-door-closed-fill"></i>
                                </div>
                                <span class="badge bg-warning text-dark border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">GATE ENTRY REQUIRED</span>
                                <h4 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h4>
                                <p class="fs-6 fw-bold text-warning-emphasis mb-2"><?= htmlspecialchars($serverResult['message']) ?></p>
                                <?php 
                                    $fincertVal = trim((string)($serverResult['fincert'] ?? ''));
                                    if ($fincertVal !== ''):
                                ?>
                                    <div class="result-id-line fw-bold text-dark my-1" style="font-size: 0.95rem; line-height: 1.3;">
                                        Your Id no. is <span class="text-primary text-break"><?= htmlspecialchars($fincertVal) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-2 px-3 mb-0 small text-start">
                                    <i class="bi bi-info-circle-fill me-1 text-warning"></i>
                                    Please complete entry at the gate terminal first, then return to claim your COE Kit.
                                </div>
                            </div>
                        <?php elseif ($serverResult['status'] === 'duplicate'): ?>
                            <div class="result-box status-duplicate shadow-sm mb-3">
                                <div class="status-icon-circle">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                </div>
                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">COE KIT BLOCKED</span>
                                <h4 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['name'] ?? 'Visitor') ?></h4>
                                <p class="fs-6 fw-bold text-warning-emphasis mb-2"><?= htmlspecialchars($serverResult['message']) ?></p>
                                <div class="p-2 bg-light rounded-3 border d-inline-block small text-secondary">
                                    <i class="bi bi-clock-history me-1 text-warning"></i> Prior Collection: <strong class="text-dark"><?= htmlspecialchars($serverResult['formatted_time'] ?? '') ?></strong>
                                    <?php if (!empty($serverResult['counter_region'])): ?>
                                        <span class="mx-2 text-muted">|</span>
                                        <i class="bi bi-geo-alt me-1 text-primary"></i> <strong class="text-dark"><?= htmlspecialchars($serverResult['counter_region']) ?></strong>
                                    <?php endif; ?>
                                    <span class="mx-2 text-muted">|</span>
                                    <?php if (!empty($serverResult['mobile'])): ?>
                                        <i class="bi bi-telephone me-1 text-primary"></i> <strong class="text-dark">+91 <?= htmlspecialchars($serverResult['mobile']) ?></strong>
                                    <?php else: ?>
                                        <i class="bi bi-person-badge me-1 text-primary"></i> Register Id: <strong class="text-dark"><?= htmlspecialchars($serverResult['user_id'] ?? '') ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php elseif ($serverResult['status'] === 'not_found'): ?>
                            <div class="result-box status-denied shadow-sm mb-3">
                                <div class="status-icon-circle">
                                    <i class="bi bi-slash-circle-fill"></i>
                                </div>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill mb-2 fw-bold text-uppercase" style="letter-spacing: 0.5px;">ACCESS REJECTED</span>
                                <h5 class="fw-bold mb-1" style="color: #0f1c30;"><?= htmlspecialchars($serverResult['message']) ?></h5>
                                <p class="text-muted small mb-2"><?= !empty($isPureNumeric) ? 'Mobile' : 'Register Id' ?>: <code class="text-danger bg-danger-subtle px-2 rounded border border-danger-subtle fw-bold"><?= htmlspecialchars($serverResult['mobile'] ?? '') ?></code></p>
                                <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger-emphasis py-2 mb-0 small">
                                    <i class="bi bi-info-circle me-1"></i> Number or Register Id not registered in COE Kit distribution list.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning border-warning-subtle text-warning-emphasis d-flex align-items-center rounded-3 shadow-sm mb-3" role="alert">
                                <i class="bi bi-exclamation-circle-fill fs-5 me-2 text-warning"></i>
                                <div>
                                    <div class="fw-bold">Validation Notice</div>
                                    <div class="small"><?= htmlspecialchars($serverResult['message'] ?? 'Validation error') ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Gift Verification Form (Mobile or Register Id Input) -->
                <form id="giftClaimForm" method="POST" action="gift.php" novalidate>

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
                                value="<?= htmlspecialchars($inputMobile) ?>"
                            >
                        </div>
                        <div class="terminal-helper-text">
                            Enter attendee mobile number or Register Id to verify and claim COE Kit
                        </div>
                    </div>

                    <!-- Primary CTA: Blue Submit Button -->
                    <div class="d-grid terminal-primary-btn-wrap">
                        <button type="submit" id="submitBtn" class="btn btn-checkin">
                            <i class="bi bi-box-seam me-2"></i> Verify &amp; Handover COE Kit
                        </button>
                    </div>

                    <!-- Secondary CTA: QR Code Button -->
                    <div class="d-grid terminal-secondary-btn-wrap">
                        <button type="button" class="btn btn-pill-white" data-bs-toggle="modal" data-bs-target="#giftQrModal">
                            <i class="bi bi-qr-code-scan me-2 text-primary"></i> Scan / Show COE Kit Terminal QR Code
                        </button>
                    </div>

                </form>

            <?php endif; ?>

        </div>
    </div>

</div>

<!-- High-Resolution QR Code Modal -->
<div class="modal fade" id="giftQrModal" tabindex="-1" aria-labelledby="giftQrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered px-3" style="max-width: 400px; margin: auto;">
        <div class="modal-content hybrid-modal overflow-hidden">
            <div class="modal-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <div class="hybrid-header-icon" style="width: 32px; height: 32px; font-size: 1rem;">
                        <i class="bi bi-qr-code-scan"></i>
                    </div>
                    <h6 class="modal-title fw-bold text-white mb-0" id="giftQrModalLabel">COE Kit Terminal QR</h6>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-4">
                <div class="qr-frame mb-3">
                    <img
                        src="<?= htmlspecialchars($qrCodeSvg) ?>"
                        alt="COE Kit Terminal QR Code"
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
                    <span><?= htmlspecialchars($currentLiveUrl) ?></span>
                </div>

                <!-- Modal Actions -->
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" id="modalCopyBtn">
                        <i class="bi bi-clipboard me-1"></i> Copy Link
                    </button>
                    <a href="<?= htmlspecialchars($qrCodePng) ?>" download="coe-kit-terminal-qr.png" class="btn btn-outline-primary btn-sm rounded-pill px-3 fw-semibold">
                        <i class="bi bi-download me-1"></i> Save PNG
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5.3.3 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Client Controller (Digit Counter, AJAX Handler) -->
<script src="assets/js/main.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modalCopyBtn = document.getElementById('modalCopyBtn');
    const liveUrl = <?= json_encode($currentLiveUrl) ?>;

    if (modalCopyBtn) {
        modalCopyBtn.addEventListener('click', async () => {
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
