<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =========================================================================
// Auth Guard
// =========================================================================
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$pdo           = Database::getConnection();
$adminUsername = htmlspecialchars($_SESSION['admin_username'] ?? 'User');
$userRole      = (string)($_SESSION['user_role'] ?? 'guard');
$isSuperAdmin  = ($userRole === 'super_admin');
$isGuard       = ($userRole === 'guard');
$isCounter     = ($userRole === 'counter');
$counterRegion = trim((string)($_SESSION['counter_region'] ?? ''));
$counterId     = (int)($_SESSION['counter_id'] ?? 0);
$counterName   = (string)($_SESSION['counter_name'] ?? '');
$counterRange  = (string)($_SESSION['counter_range'] ?? '');
$counterCode   = (string)($_SESSION['counter_code'] ?? '');

if ($isCounter && ($counterId === 0 || $counterName === '')) {
    $cStmt = $pdo->prepare("SELECT id, name, code, name_range FROM `counters` WHERE username = :u LIMIT 1");
    $cStmt->execute([':u' => $_SESSION['admin_username'] ?? '']);
    $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
    if ($cRow) {
        $counterId    = (int)$cRow['id'];
        $counterName  = $cRow['name'];
        $counterRange = (string)($cRow['name_range'] ?? '');
        $counterCode  = $cRow['code'];
        $_SESSION['counter_id']    = $counterId;
        $_SESSION['counter_name']  = $counterName;
        $_SESSION['counter_range'] = $counterRange;
        $_SESSION['counter_code']  = $counterCode;
    }
}

$counterAllowedRegions = [];
if ($isCounter && $counterId > 0) {
    $crStmt = $pdo->prepare("SELECT region FROM `counter_regions` WHERE counter_id = :cid ORDER BY region ASC");
    $crStmt->execute([':cid' => $counterId]);
    $counterAllowedRegions = array_filter(array_map('trim', $crStmt->fetchAll(PDO::FETCH_COLUMN)));
}
$isSingleRegionCounter = $isCounter && (empty($counterAllowedRegions) || count($counterAllowedRegions) <= 1 || !empty($counterRange));
$_SESSION['counter_allowed_regions'] = $counterAllowedRegions;
$_SESSION['counter_is_single_region'] = $isSingleRegionCounter;

$roleLabel = 'User';
if ($isSuperAdmin) {
    $roleLabel = 'Super Admin';
} elseif ($isCounter) {
    if (!empty($counterRange)) {
        $roleLabel = "{$counterName} ({$counterRange})";
    } elseif (!empty($counterName)) {
        $roleLabel = $counterName;
    } else {
        $roleLabel = ($counterRegion !== '' ? $counterRegion : 'Help Desk') . ' Counter';
    }
} elseif ($isGuard) {
    $roleLabel = 'Gate Guard (Gate 1)';
}

// =========================================================================
// Tab Access & Role Boundary Enforcement
// =========================================================================
$tab = $_GET['tab'] ?? ($isCounter ? 'gift' : 'gate');
if ($isCounter && $tab !== 'gift') {
    header('Location: admin.php?tab=gift');
    exit;
}
if ($isGuard && $tab !== 'gate') {
    header('Location: admin.php?tab=gate');
    exit;
}
if (($tab === 'counters' || $tab === 'gate_qr') && !$isSuperAdmin) {
    header('Location: admin.php?tab=' . ($isCounter ? 'gift' : 'gate') . '&msg=permission_denied');
    exit;
}

// =========================================================================
// Action Handlers (Check-in, Reset, Import, Export, Counter Management)
// =========================================================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$msg    = $_GET['msg']    ?? null;
$id     = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($id > 0) {
    // Preserve search, filter, region and pagination query parameters
    $redirectUrl = "admin.php?tab=" . urlencode($tab);
    if (!empty($_GET['gfilter'])) {
        $redirectUrl .= "&gfilter=" . urlencode((string)$_GET['gfilter']);
    }
    if (!empty($_GET['gsearch'])) {
        $redirectUrl .= "&gsearch=" . urlencode((string)$_GET['gsearch']);
    }
    if (!empty($_GET['gregion'])) {
        $redirectUrl .= "&gregion=" . urlencode((string)$_GET['gregion']);
    }
    if (!empty($_GET['gpage'])) {
        $redirectUrl .= "&gpage=" . urlencode((string)$_GET['gpage']);
    }
    if (!empty($_GET['dfilter'])) {
        $redirectUrl .= "&dfilter=" . urlencode((string)$_GET['dfilter']);
    }
    if (!empty($_GET['dsearch'])) {
        $redirectUrl .= "&dsearch=" . urlencode((string)$_GET['dsearch']);
    }
    if (!empty($_GET['dregion'])) {
        $redirectUrl .= "&dregion=" . urlencode((string)$_GET['dregion']);
    }
    if (!empty($_GET['dpage'])) {
        $redirectUrl .= "&dpage=" . urlencode((string)$_GET['dpage']);
    }

    $isAjaxAction = (
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
        isset($_REQUEST['ajax'])
    );

    try {
        // Gate entry actions — forbidden for counter staff
        if (in_array($action, ['reset_d1', 'reset_day1', 'gate_reset_d1', 'reset_d2', 'reset_day2', 'gate_reset_d2', 'checkin_d1', 'checkin_day1', 'gate_checkin_d1', 'gate_checkin', 'checkin_d2', 'checkin_day2', 'gate_checkin_d2', 'gate_reset'], true)) {
            if ($isCounter) {
                if ($isAjaxAction) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Permission denied: Counter staff cannot modify Gate Entry logs.']);
                    exit;
                }
                header("Location: admin.php?tab=gift&msg=permission_denied");
                exit;
            }
        }

        if ($action === 'reset_d1' || $action === 'reset_day1' || $action === 'gate_reset_d1') {
            $stmt = $pdo->prepare("UPDATE allowed_visitors SET is_day1_checked_in = 0, day1_checked_in_at = NULL, is_checked_in = 0, checked_in_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl);
            exit;
        }
        if ($action === 'reset_d2' || $action === 'reset_day2' || $action === 'gate_reset_d2') {
            $stmt = $pdo->prepare("UPDATE allowed_visitors SET is_day2_checked_in = 0, day2_checked_in_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl);
            exit;
        }
        if ($action === 'checkin_d1' || $action === 'checkin_day1' || $action === 'gate_checkin_d1' || $action === 'gate_checkin') {
            $stmt = $pdo->prepare("UPDATE allowed_visitors SET is_day1_checked_in = 1, day1_checked_in_at = UTC_TIMESTAMP(), is_checked_in = 1, checked_in_at = COALESCE(checked_in_at, UTC_TIMESTAMP()) WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl);
            exit;
        }
        if ($action === 'checkin_d2' || $action === 'checkin_day2' || $action === 'gate_checkin_d2') {
            $stmt = $pdo->prepare("UPDATE allowed_visitors SET is_day2_checked_in = 1, day2_checked_in_at = UTC_TIMESTAMP() WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl);
            exit;
        }
        if ($action === 'gate_reset') {
            $stmt = $pdo->prepare("UPDATE allowed_visitors SET is_day1_checked_in = 0, day1_checked_in_at = NULL, is_day2_checked_in = 0, day2_checked_in_at = NULL, is_checked_in = 0, checked_in_at = NULL WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl);
            exit;
        }

        // COE Kit Handover & Reset with Region Isolation Enforcement
        if ($action === 'mark_given' || $action === 'gift_mark_given') {
            // Verify gate entry status (mandatory for both Counter Staff and Super Admin)
            $checkGateStmt = $pdo->prepare("
                SELECT g.id, g.counter_id, g.region, g.is_gift_collected,
                       (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered
                FROM `gift_distribution` g
                LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
                WHERE g.id = :id
            ");
            $checkGateStmt->execute([':id' => $id]);
            $visRow = $checkGateStmt->fetch(PDO::FETCH_ASSOC);

            if (!$visRow) {
                if ($isAjaxAction) {
                    http_response_code(404);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Visitor not found.']);
                    exit;
                }
                header("Location: " . $redirectUrl);
                exit;
            }

            // Gate entry required: Day 1 or Day 2 check-in must exist
            if ((int)($visRow['is_gate_entered'] ?? 0) !== 1) {
                if ($isAjaxAction) {
                    http_response_code(400);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Gate entry required: Visitor has not checked in at the gate yet.']);
                    exit;
                }
                header("Location: admin.php?tab=gift&msg=gate_not_entered");
                exit;
            }

            if ($isCounter) {
                $visCounterId = (int)($visRow['counter_id'] ?? 0);
                $attendeeReg  = trim((string)($visRow['region'] ?? ''));

                $isRegionAllowed = false;
                if ($visCounterId > 0 && $counterId > 0) {
                    $isRegionAllowed = ($visCounterId === $counterId);
                } elseif (!empty($counterAllowedRegions)) {
                    $isRegionAllowed = in_array(strtolower($attendeeReg), array_map('strtolower', $counterAllowedRegions), true);
                } elseif ($counterRegion === 'Help Desk') {
                    $isRegionAllowed = ($attendeeReg === '');
                } else {
                    $isRegionAllowed = (strcasecmp($attendeeReg, $counterRegion) === 0);
                }

                if (!$isRegionAllowed) {
                    if ($isAjaxAction) {
                        http_response_code(403);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error' => 'Permission denied: This attendee belongs to another region counter.']);
                        exit;
                    }
                    header("Location: admin.php?tab=gift&msg=permission_denied");
                    exit;
                }
            }

            $actingCounterName = $isSuperAdmin ? 'Super Admin' : (!empty($counterName) ? $counterName : ($counterRegion !== '' ? "{$counterRegion} Counter" : 'Help Desk'));
            $stmt = $pdo->prepare("
                UPDATE `gift_distribution` 
                SET is_gift_collected = 1, 
                    gift_collected_at = UTC_TIMESTAMP(), 
                    handed_over_by = :who, 
                    counter_region = :creg 
                WHERE id = :id AND is_gift_collected = 0
            ");
            $stmt->execute([
                ':id'   => $id,
                ':who'  => $_SESSION['admin_username'] ?? 'admin',
                ':creg' => $actingCounterName,
            ]);
            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl . "&msg=gift_given_one");
            exit;
        }

        if ($action === 'reset_gift' || $action === 'gift_reset') {
            if (!$isSuperAdmin) {
                http_response_code(403);
                if ($isAjaxAction) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error' => 'Only the Super Admin can reset a kit.']);
                    exit;
                }
                echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Only the Super Admin can reset a kit.</p></body></html>';
                exit;
            }

            $visInfoStmt = $pdo->prepare("SELECT id, name, user_id, handed_over_by, counter_region FROM `gift_distribution` WHERE id = :id");
            $visInfoStmt->execute([':id' => $id]);
            $visInfo = $visInfoStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("
                UPDATE `gift_distribution` 
                SET is_gift_collected = 0, 
                    gift_collected_at = NULL, 
                    handed_over_by = NULL, 
                    counter_region = NULL 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);

            if ($visInfo) {
                try {
                    $nowIST = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i:s A');
                    $handedCounter = $visInfo['handed_over_by'] ?? 'Unknown';
                    if (!empty($visInfo['counter_region'])) {
                        $handedCounter .= ' (' . $visInfo['counter_region'] . ')';
                    }
                    $details = json_encode([
                        'visitor_name'   => $visInfo['name'],
                        'register_id'    => $visInfo['user_id'] ?: '—',
                        'handed_over_by' => $visInfo['handed_over_by'] ?? 'Unknown',
                        'counter_region' => $visInfo['counter_region'] ?? 'Unknown',
                        'note'           => "COE Kit reset by Super Admin. Originally handed over by {$handedCounter}."
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $insAudit = $pdo->prepare("
                        INSERT INTO `audit_log` (`action`, `performed_by`, `target_id`, `target_name`, `details`, `created_at_ist`, `created_at`)
                        VALUES ('reset_kit', :by, :tid, :tname, :details, :ist, NOW())
                    ");
                    $insAudit->execute([
                        ':by'         => $_SESSION['admin_username'] ?? 'admin',
                        ':tid'        => $visInfo['user_id'] ?: (string)$id,
                        ':tname'      => $visInfo['name'],
                        ':details'    => $details,
                        ':ist'        => $nowIST
                    ]);
                } catch (\Throwable $e) {
                    error_log("Failed to write kit reset audit log: " . $e->getMessage());
                }
            }

            if ($isAjaxAction) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'action' => $action, 'id' => $id]);
                exit;
            }
            header("Location: " . $redirectUrl . "&msg=gift_reset_one");
            exit;
        }
    } catch (\Throwable $e) {
        if ($isAjaxAction) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
        header("Location: " . $redirectUrl . "&msg=db_error");
        exit;
    }
}

// =========================================================================
// Super Admin Actions for Regional Counter Management
// =========================================================================

// Super Admin Action: Reset Counter Password
if ($action === 'reset_counter_pwd' || $action === 'reset_counter_password') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=counters&msg=permission_denied');
        exit;
    }
    $counterId = (int)($_POST['counter_id'] ?? $_GET['counter_id'] ?? 0);
    if ($counterId > 0) {
        $cStmt = $pdo->prepare("SELECT id, name, username FROM `counters` WHERE id = :id LIMIT 1");
        $cStmt->execute([':id' => $counterId]);
        $targetCounter = $cStmt->fetch();
        if (!$targetCounter) {
            // Fallback: check admins table
            $aStmt = $pdo->prepare("SELECT id, username, region FROM `admins` WHERE id = :id LIMIT 1");
            $aStmt->execute([':id' => $counterId]);
            $targetCounter = $aStmt->fetch();
        }

        if ($targetCounter) {
            $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ@#$%*';
            $newPlainPass = '';
            for ($i = 0; $i < 12; $i++) {
                $newPlainPass .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $newHash = password_hash($newPlainPass, PASSWORD_DEFAULT);

            // Update counters table
            $pdo->prepare("UPDATE `counters` SET password_hash = :p WHERE id = :id OR username = :u")
                ->execute([':p' => $newHash, ':id' => $counterId, ':u' => $targetCounter['username']]);

            // Update admins table
            $pdo->prepare("UPDATE `admins` SET password = :p, failed_attempts = 0, locked_until = NULL WHERE username = :u OR counter_id = :id")
                ->execute([':p' => $newHash, ':u' => $targetCounter['username'], ':id' => $counterId]);

            $_SESSION['counter_pwd_reset'] = [
                'id'       => $counterId,
                'username' => $targetCounter['username'],
                'region'   => $targetCounter['name'] ?? $targetCounter['region'] ?? 'Counter',
                'password' => $newPlainPass,
            ];
            header('Location: admin.php?tab=counters&msg=counter_pwd_reset');
            exit;
        }
    }
    header('Location: admin.php?tab=counters&msg=db_error');
    exit;
}

// Super Admin Action: Toggle Counter Status (Active / Disabled)
if ($action === 'toggle_counter_status') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=counters&msg=permission_denied');
        exit;
    }
    $counterId = (int)($_POST['counter_id'] ?? $_GET['counter_id'] ?? 0);
    if ($counterId > 0) {
        $cStmt = $pdo->prepare("SELECT id, username, is_active FROM `counters` WHERE id = :id LIMIT 1");
        $cStmt->execute([':id' => $counterId]);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);

        if ($cRow) {
            $newActive = ((int)$cRow['is_active'] === 1) ? 0 : 1;
            $newStatus = $newActive ? 'active' : 'disabled';

            $pdo->prepare("UPDATE `counters` SET is_active = :a WHERE id = :id")
                ->execute([':a' => $newActive, ':id' => $counterId]);
            $pdo->prepare("UPDATE `admins` SET status = :s, failed_attempts = 0, locked_until = NULL WHERE username = :u OR counter_id = :id")
                ->execute([':s' => $newStatus, ':u' => $cRow['username'], ':id' => $counterId]);

            header('Location: admin.php?tab=counters&msg=counter_status_toggled');
            exit;
        }
    }
    header('Location: admin.php?tab=counters&msg=db_error');
    exit;
}

// Super Admin Action: Reassign Visitor to Another Counter
if ($action === 'reassign_counter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=gift&msg=permission_denied');
        exit;
    }
    $visitorId    = (int)($_POST['visitor_id'] ?? 0);
    $newCounterId = (int)($_POST['new_counter_id'] ?? 0);
    $reason       = trim((string)($_POST['reason'] ?? 'Queue rebalance / Admin adjustment'));

    if ($visitorId > 0 && $newCounterId > 0) {
        $vStmt = $pdo->prepare("SELECT id, name, user_id, counter_id FROM `gift_distribution` WHERE id = :id LIMIT 1");
        $vStmt->execute([':id' => $visitorId]);
        $vRow = $vStmt->fetch(PDO::FETCH_ASSOC);

        if ($vRow) {
            $oldCid = !empty($vRow['counter_id']) ? (int)$vRow['counter_id'] : null;

            $pdo->prepare("UPDATE `gift_distribution` SET counter_id = :cid WHERE id = :id")
                ->execute([':cid' => $newCounterId, ':id' => $visitorId]);
            if (!empty($vRow['user_id'])) {
                $pdo->prepare("UPDATE `allowed_visitors` SET counter_id = :cid WHERE user_id = :uid")
                    ->execute([':cid' => $newCounterId, ':uid' => $vRow['user_id']]);
            }

            $pdo->prepare("
                INSERT INTO `counter_reassignment_log` 
                (`visitor_id`, `visitor_name`, `user_id`, `old_counter_id`, `new_counter_id`, `changed_by`, `reason`)
                VALUES (:vid, :vname, :uid, :old_cid, :new_cid, :by, :reason)
            ")->execute([
                ':vid'     => $visitorId,
                ':vname'   => $vRow['name'],
                ':uid'     => $vRow['user_id'],
                ':old_cid' => $oldCid,
                ':new_cid' => $newCounterId,
                ':by'      => $_SESSION['admin_username'] ?? 'admin',
                ':reason'  => $reason
            ]);

            $dFilter = urlencode((string)($_POST['dfilter'] ?? 'all'));
            $dPage   = urlencode((string)($_POST['dpage'] ?? '1'));
            $dSearch = urlencode((string)($_POST['dsearch'] ?? ''));
            header("Location: admin.php?tab=gift&msg=counter_reassigned&dfilter={$dFilter}&dpage={$dPage}&dsearch={$dSearch}");
            exit;
        }
    }
    header('Location: admin.php?tab=gift&msg=db_error');
    exit;
}

// =========================================================================
// Super Admin Action: Save Event Settings (Gate Self Check-In Schedule & Switch)
// =========================================================================
if ($action === 'save_event_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=gate&msg=permission_denied');
        exit;
    }
    $postCsrf = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $postCsrf)) {
        header('Location: admin.php?tab=gate_qr&msg=invalid_csrf');
        exit;
    }
    $day1 = trim((string)($_POST['day1_date'] ?? '2026-11-18'));
    $day2 = trim((string)($_POST['day2_date'] ?? '2026-11-19'));
    $selfCheckinOpen = !empty($_POST['self_checkin_open']) ? '1' : '0';
    $evName = trim((string)($_POST['event_name'] ?? 'COE Event 2026'));

    updateEventSettings($pdo, [
        'day1_date'         => $day1,
        'day2_date'         => $day2,
        'self_checkin_open' => $selfCheckinOpen,
        'event_name'        => $evName,
    ], (string)($_SESSION['admin_username'] ?? 'admin'));

    header('Location: admin.php?tab=gate_qr&msg=settings_saved');
    exit;
}

// =========================================================================
// Super Admin Action: Add Single Visitor
// =========================================================================
if ($action === 'add_visitor' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
              || (isset($_POST['ajax']) && (string)$_POST['ajax'] === '1');

    if (!$isSuperAdmin) {
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Forbidden. Super Admin access required.']);
            exit;
        }
        header('Location: admin.php?tab=' . ($isCounter ? 'gift' : 'gate') . '&msg=permission_denied');
        exit;
    }

    // Verify CSRF
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired security token. Please refresh the page.']);
            exit;
        }
        header('Location: admin.php?tab=' . urlencode($tab) . '&msg=invalid_token');
        exit;
    }

    $errors = [];
    $warnings = [];

    // 1. Full Name * (required, text, min 2 chars)
    $name = trim((string)($_POST['name'] ?? ''));
    if (mb_strlen($name, 'UTF-8') < 2) {
        $errors['name'] = 'Full Name is required (at least 2 characters).';
    }

    // 2. Register Id * (required, unique case-insensitive)
    $rawUserId = (string)($_POST['user_id'] ?? '');
    $userId = preg_replace('/\s+/', ' ', trim($rawUserId));
    if ($userId === '') {
        $errors['user_id'] = 'Register Id is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.\-@ ]+$/', $userId)) {
        $errors['user_id'] = 'Register Id contains invalid characters (allowed: letters, digits, _, ., -, @, space).';
    } else {
        $uidCheck = $pdo->prepare("
            SELECT name, user_id FROM `allowed_visitors` 
            WHERE LOWER(TRIM(user_id)) = LOWER(:uid) 
            LIMIT 1
        ");
        $uidCheck->execute([':uid' => $userId]);
        $existingUid = $uidCheck->fetch(PDO::FETCH_ASSOC);
        if ($existingUid) {
            $errors['user_id'] = "This Register Id already exists (" . ($existingUid['name'] ?? '') . ").";
        }
    }

    // 3. Region * (required, must exist in database)
    $regionInput = trim((string)($_POST['region'] ?? ''));
    $region = '';
    if ($regionInput === '') {
        $errors['region'] = 'Please select a Region.';
    } else {
        $regCheck = $pdo->prepare("
            SELECT DISTINCT region FROM (
                SELECT region FROM `allowed_visitors` WHERE region IS NOT NULL AND TRIM(region) != ''
                UNION
                SELECT region FROM `counter_regions` WHERE region IS NOT NULL AND TRIM(region) != ''
            ) AS combined
            WHERE LOWER(TRIM(region)) = LOWER(:r)
            LIMIT 1
        ");
        $regCheck->execute([':r' => $regionInput]);
        $matchedReg = $regCheck->fetchColumn();
        if ($matchedReg) {
            $region = (string)$matchedReg;
        } else {
            $errors['region'] = 'Please select a valid Region from the list.';
        }
    }

    // 4. Optional fields: School Name & Designation
    $schoolName  = trim((string)($_POST['school_name'] ?? ''));
    $designation = trim((string)($_POST['designation'] ?? ''));

    // 5. Mobile Number (optional, 8 to 10 digits)
    $rawMobile = trim((string)($_POST['mobile'] ?? ''));
    $cleanMobile = '';
    $confirmDupMobile = !empty($_POST['confirm_duplicate_mobile']);

    if ($rawMobile !== '') {
        $digits = preg_replace('/\D/', '', $rawMobile);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) < 8 || strlen($digits) > 10) {
            $errors['mobile'] = 'Mobile number must be 8 to 10 digits.';
        } else {
            $cleanMobile = $digits;
            $mobCheck = $pdo->prepare("SELECT name FROM `allowed_visitors` WHERE mobile = :mob LIMIT 1");
            $mobCheck->execute([':mob' => $cleanMobile]);
            $existingMob = $mobCheck->fetch(PDO::FETCH_ASSOC);
            if ($existingMob && !$confirmDupMobile) {
                $warnings['mobile'] = [
                    'message' => "This mobile number is also used by " . ($existingMob['name'] ?? 'another visitor') . ". These visitors will need to use their Register Id at the gate.",
                    'confirm_field' => 'confirm_duplicate_mobile'
                ];
            }
        }
    }

    // 6. Email (optional)
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // 7. FinCert / Id no. (optional, uppercase)
    $rawFincert = trim((string)($_POST['fincert'] ?? ''));
    $fincert = $rawFincert !== '' ? strtoupper($rawFincert) : '';
    $confirmDupFincert = !empty($_POST['confirm_duplicate_fincert']);

    if ($fincert !== '') {
        $fcCheck = $pdo->prepare("SELECT name FROM `allowed_visitors` WHERE fincert = :fc LIMIT 1");
        $fcCheck->execute([':fc' => $fincert]);
        $existingFc = $fcCheck->fetch(PDO::FETCH_ASSOC);
        if ($existingFc && !$confirmDupFincert) {
            $warnings['fincert'] = [
                'message' => "This FinCert / Id no. is also used by " . ($existingFc['name'] ?? 'another visitor') . ".",
                'confirm_field' => 'confirm_duplicate_fincert'
            ];
        }
    }

    // If any validation errors or unconfirmed warnings exist, halt
    if (!empty($errors) || !empty($warnings)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'   => !empty($errors) ? 'error' : 'warning',
                'errors'   => $errors,
                'warnings' => $warnings,
            ]);
            exit;
        }
        $_SESSION['add_visitor_errors']   = $errors;
        $_SESSION['add_visitor_warnings'] = $warnings;
        $_SESSION['add_visitor_old']      = $_POST;
        header('Location: admin.php?tab=' . urlencode($tab) . '&msg=validation_error');
        exit;
    }

    // Resolve Counter
    $assignedCounter   = Database::resolveCounterForVisitor($region, $name, $pdo);
    $targetCounterId   = $assignedCounter ? (int)$assignedCounter['id'] : null;
    $targetCounterName = $assignedCounter ? $assignedCounter['name'] : ($region . ' Counter');
    $adminUser         = $_SESSION['admin_username'] ?? 'admin';
    $utcNow            = gmdate('Y-m-d H:i:s');
    $istNow            = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. Insert into allowed_visitors
        $insAv = $pdo->prepare("
            INSERT INTO `allowed_visitors` (
                `name`, `user_id`, `region`, `school_name`, `designation`,
                `mobile`, `email`, `fincert`, `counter_id`,
                `is_checked_in`, `checked_in_at`,
                `is_day1_checked_in`, `day1_checked_in_at`,
                `is_day2_checked_in`, `day2_checked_in_at`,
                `added_by`, `created_at`
            ) VALUES (
                :name, :user_id, :region, :school_name, :designation,
                :mobile, :email, :fincert, :counter_id,
                0, NULL,
                0, NULL,
                0, NULL,
                :added_by, :created_at
            )
        ");
        $insAv->execute([
            ':name'        => $name,
            ':user_id'     => $userId,
            ':region'      => $region,
            ':school_name' => $schoolName !== '' ? $schoolName : null,
            ':designation' => $designation !== '' ? $designation : null,
            ':mobile'      => $cleanMobile,
            ':email'       => $email !== '' ? $email : null,
            ':fincert'     => $fincert !== '' ? $fincert : null,
            ':counter_id'  => $targetCounterId,
            ':added_by'    => $adminUser,
            ':created_at'  => $utcNow
        ]);
        $newVisitorId = (int)$pdo->lastInsertId();

        // 2. Insert into gift_distribution
        $insGd = $pdo->prepare("
            INSERT INTO `gift_distribution` (
                `name`, `user_id`, `region`, `school_name`, `designation`,
                `mobile`, `email`, `fincert`, `counter_id`, `counter_region`,
                `is_gift_collected`, `gift_collected_at`,
                `added_by`, `created_at`
            ) VALUES (
                :name, :user_id, :region, :school_name, :designation,
                :mobile, :email, :fincert, :counter_id, :counter_region,
                0, NULL,
                :added_by, :created_at
            )
        ");
        $insGd->execute([
            ':name'           => $name,
            ':user_id'        => $userId,
            ':region'         => $region,
            ':school_name'    => $schoolName !== '' ? $schoolName : null,
            ':designation'    => $designation !== '' ? $designation : null,
            ':mobile'         => $cleanMobile,
            ':email'          => $email !== '' ? $email : null,
            ':fincert'        => $fincert !== '' ? $fincert : null,
            ':counter_id'     => $targetCounterId,
            ':counter_region' => $region,
            ':added_by'       => $adminUser,
            ':created_at'     => $utcNow
        ]);
        $newGiftId = (int)$pdo->lastInsertId();

        // 3. Write Audit Log
        $insAudit = $pdo->prepare("
            INSERT INTO `audit_log` (`action`, `performed_by`, `target_id`, `target_name`, `details`, `created_at_ist`, `created_at`)
            VALUES ('add_visitor', :by, :tid, :tname, :details, :ist, :created_at)
        ");
        $detailsText = json_encode([
            'user_id'      => $userId,
            'region'       => $region,
            'counter_id'   => $targetCounterId,
            'counter_name' => $targetCounterName,
            'mobile'       => $cleanMobile,
            'fincert'      => $fincert
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $insAudit->execute([
            ':by'         => $adminUser,
            ':tid'        => $userId,
            ':tname'      => $name,
            ':details'    => $detailsText,
            ':ist'        => $istNow,
            ':created_at' => $utcNow
        ]);

        $pdo->commit();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'       => 'success',
                'message'      => "Visitor {$name} added",
                'visitor_id'   => $newVisitorId,
                'gift_id'      => $newGiftId,
                'name'         => $name,
                'user_id'      => $userId,
                'region'       => $region,
                'counter_name' => $targetCounterName,
                'counter_id'   => $targetCounterId
            ]);
            exit;
        }

        $_SESSION['flash_visitor_name'] = $name;
        header('Location: admin.php?tab=' . urlencode($tab) . '&msg=visitor_added');
        exit;

    } catch (\Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Failed to add visitor: " . $ex->getMessage());
        if ($isAjax) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Database transaction failed: ' . $ex->getMessage()
            ]);
            exit;
        }
        header('Location: admin.php?tab=' . urlencode($tab) . '&msg=db_error');
        exit;
    }
}

// Super Admin Action: Create New Counter
if ($action === 'create_counter' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=gift&msg=permission_denied');
        exit;
    }
    $newRegion   = trim((string)($_POST['new_region'] ?? ''));
    $newUsername = trim((string)($_POST['new_username'] ?? ''));
    $newPass     = trim((string)($_POST['new_password'] ?? ''));

    if ($newRegion === '') {
        header('Location: admin.php?tab=counters&msg=invalid_counter_region');
        exit;
    }

    if ($newUsername === '') {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($newRegion));
        $slug = trim($slug, '_');
        $newUsername = 'counter_' . $slug;
    }

    // Check uniqueness of username
    $uCheck = $pdo->prepare("SELECT COUNT(*) FROM `admins` WHERE username = :u");
    $uCheck->execute([':u' => $newUsername]);
    if ((int)$uCheck->fetchColumn() > 0) {
        header('Location: admin.php?tab=counters&msg=counter_exists');
        exit;
    }

    if ($newPass === '') {
        $chars = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ!@#$%';
        for ($i = 0; $i < 11; $i++) {
            $newPass .= $chars[random_int(0, strlen($chars) - 1)];
        }
    }

    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("
        INSERT INTO `admins` (`username`, `password`, `role`, `region`, `status`, `failed_attempts`) 
        VALUES (:u, :p, 'counter', :r, 'active', 0)
    ");
    $ins->execute([
        ':u' => $newUsername,
        ':p' => $newHash,
        ':r' => $newRegion,
    ]);

    $_SESSION['counter_pwd_reset'] = [
        'id'       => (int)$pdo->lastInsertId(),
        'username' => $newUsername,
        'region'   => $newRegion,
        'password' => $newPass,
    ];
    header('Location: admin.php?tab=counters&msg=counter_created');
    exit;
}

// Gate: Reset All (Super Admin ONLY)
if ($action === 'gate_reset_all' || $action === 'reset_all') {
    if (!$isSuperAdmin) {
        header('Location: admin.php?tab=gate&msg=permission_denied');
        exit;
    }
    $pdo->exec("UPDATE allowed_visitors SET is_day1_checked_in = 0, day1_checked_in_at = NULL, is_day2_checked_in = 0, day2_checked_in_at = NULL, is_checked_in = 0, checked_in_at = NULL;");
    header('Location: admin.php?tab=gate&msg=gate_reset_all');
    exit;
}

// Gift: Reset All (Super Admin ONLY)
if ($action === 'gift_reset_all') {
    if (!$isSuperAdmin) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><h1>403 Forbidden</h1><p>Only the Super Admin can reset a kit.</p></body></html>';
        exit;
    }
    $pdo->exec("UPDATE `gift_distribution` SET is_gift_collected = 0, gift_collected_at = NULL, handed_over_by = NULL, counter_region = NULL");
    try {
        $nowIST = (new \DateTime('now', new \DateTimeZone('Asia/Kolkata')))->format('d M Y, h:i:s A');
        $insAudit = $pdo->prepare("
            INSERT INTO `audit_log` (`action`, `performed_by`, `target_id`, `target_name`, `details`, `created_at_ist`, `created_at`)
            VALUES ('reset_all_kits', :by, NULL, NULL, :details, :ist, NOW())
        ");
        $insAudit->execute([
            ':by'      => $_SESSION['admin_username'] ?? 'admin',
            ':details' => 'Super Admin performed Reset All COE Kits',
            ':ist'     => $nowIST
        ]);
    } catch (\Throwable $e) {}
    header('Location: admin.php?tab=gift&msg=gift_reset_all');
    exit;
}

// CSV Bulk Import with Dual-Table Synchronization (Gate Entry + Gift Distribution)
if ($action === 'import_csv' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentTab = (string)($_POST['tab'] ?? $_GET['tab'] ?? 'gate');

    // Strict Backend Guard Protection
    if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
        header("Location: admin.php?tab={$currentTab}&msg=permission_denied");
        exit;
    }

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK || strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)) !== 'csv' || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        header("Location: admin.php?tab={$currentTab}&msg=invalid_file");
        exit;
    }

    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    $importedCount = $skippedCount = 0;
    $headers = null;

    // Prepared statements for dual table synchronization
    $stmtGate = $pdo->prepare("
        INSERT INTO `allowed_visitors` 
        (`name`, `mobile`, `user_id`, `region`, `fincert`, `school_name`, `designation`, `email`, `is_day1_checked_in`, `day1_checked_in_at`, `is_day2_checked_in`, `day2_checked_in_at`, `is_checked_in`, `checked_in_at`)
        VALUES (:name, :mobile, :user_id, :region, :fincert, :school_name, :designation, :email, 0, NULL, 0, NULL, 0, NULL)
        ON DUPLICATE KEY UPDATE 
            `name` = VALUES(`name`),
            `mobile` = COALESCE(NULLIF(VALUES(`mobile`), ''), `mobile`),
            `region` = COALESCE(NULLIF(VALUES(`region`), ''), `region`),
            `fincert` = COALESCE(NULLIF(VALUES(`fincert`), ''), `fincert`),
            `school_name` = COALESCE(NULLIF(VALUES(`school_name`), ''), `school_name`),
            `designation` = COALESCE(NULLIF(VALUES(`designation`), ''), `designation`),
            `email` = COALESCE(NULLIF(VALUES(`email`), ''), `email`)
    ");

    $stmtGift = $pdo->prepare("
        INSERT INTO `gift_distribution` 
        (`name`, `mobile`, `user_id`, `region`, `fincert`, `school_name`, `designation`, `email`, `is_gift_collected`, `gift_collected_at`)
        VALUES (:name, :mobile, :user_id, :region, :fincert, :school_name, :designation, :email, 0, NULL)
        ON DUPLICATE KEY UPDATE 
            `name` = VALUES(`name`),
            `mobile` = COALESCE(NULLIF(VALUES(`mobile`), ''), `mobile`),
            `region` = COALESCE(NULLIF(VALUES(`region`), ''), `region`),
            `fincert` = COALESCE(NULLIF(VALUES(`fincert`), ''), `fincert`),
            `school_name` = COALESCE(NULLIF(VALUES(`school_name`), ''), `school_name`),
            `designation` = COALESCE(NULLIF(VALUES(`designation`), ''), `designation`),
            `email` = COALESCE(NULLIF(VALUES(`email`), ''), `email`)
    ");

    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($handle, 3000, ',')) !== false) {
            if (empty($row) || (count($row) === 1 && trim((string)$row[0]) === '')) continue;
            if (count($row) === 1 && str_contains((string)$row[0], ';')) $row = str_getcsv($row[0], ';');

            // Detect Header Row
            if ($headers === null) {
                $firstRowStr = strtolower(implode(' ', $row));
                if (str_contains($firstRowStr, 'name') || str_contains($firstRowStr, 'contact') || str_contains($firstRowStr, 'user') || str_contains($firstRowStr, 'register') || str_contains($firstRowStr, 'umname') || str_contains($firstRowStr, 'mobile')) {
                    $headers = [];
                    foreach ($row as $colIdx => $colName) {
                        $c = strtolower(trim((string)$colName));
                        $headers[$c] = $colIdx;
                    }
                    continue; // Skip header row
                } else {
                    $headers = false; // Legacy format without headers
                }
            }

            $name = '';
            $mobile = '';
            $userId = null;
            $region = null;
            $fincert = null;
            $schoolName = null;
            $designation = null;
            $email = null;

            if (is_array($headers)) {
                $getVal = function(array $patterns) use ($headers, $row): ?string {
                    foreach ($headers as $colName => $idx) {
                        foreach ($patterns as $p) {
                            if ($colName === $p || str_contains($colName, $p)) {
                                return isset($row[$idx]) ? trim((string)$row[$idx]) : null;
                            }
                        }
                    }
                    return null;
                };

                $rawName = $getVal(['full name', 'umname', 'name', 'guest']) ?? '';
                $rawMobile = $getVal(['contact', 'mobile number', 'mobile', 'phone']) ?? '';
                $rawUserId = $getVal(['register id', 'register_id', 'registerid', 'register', 'user_id', 'userid', 'user id']) ?? null;
                $rawRegion = $getVal(['region', 'city', 'location']) ?? null;
                $rawFincert = $getVal(['fincert', 'fin cert', 'id no']) ?? null;
                $rawSchool = $getVal(['schname', 'school name', 'school']) ?? null;
                $rawDesig = $getVal(['desig', 'designation']) ?? null;
                $rawEmail = $getVal(['emaild', 'email']) ?? null;

                $name = $rawName;
                $mobile = $rawMobile;
                $userId = $rawUserId;
                $region = $rawRegion;
                $fincert = $rawFincert;
                $schoolName = $rawSchool;
                $designation = $rawDesig;
                $email = $rawEmail;
            } else {
                $rawName = trim((string)($row[0] ?? ''));
                $rawMobile = trim((string)($row[1] ?? ''));
                if (preg_match('/^[0-9+\s()-]+$/', $rawName) && !preg_match('/^[0-9+\s()-]+$/', $rawMobile)) {
                    [$rawName, $rawMobile] = [$rawMobile, $rawName];
                }
                $name = $rawName;
                $mobile = $rawMobile;
            }

            // Clean mobile
            $m = preg_replace('/\D/', '', $mobile);
            if (strlen($m) === 12 && str_starts_with($m, '91')) $m = substr($m, 2);
            if (strlen($m) === 11 && str_starts_with($m, '0'))  $m = substr($m, 1);
            if (strlen($m) !== 10) {
                $m = ($m !== '') ? $m : null;
            }

            // User ID handling
            $cleanUserId = ($userId !== null && trim($userId) !== '') ? trim($userId) : null;
            if ($cleanUserId === null && $m === null) {
                $skippedCount++;
                continue;
            }
            if ($cleanUserId === null && $m !== null) {
                $cleanUserId = 'USR_' . $m;
            }

            $cleanRegion = ($region !== null && trim($region) !== '') ? ucwords(strtolower(trim($region))) : null;
            $cleanName = mb_substr(trim($name) ?: ('Visitor ' . ($m ? substr($m, -4) : $cleanUserId)), 0, 100);
            $cleanFincert = ($fincert !== null && trim($fincert) !== '') ? strtoupper(trim($fincert)) : null;
            $cleanSchool = ($schoolName !== null && trim($schoolName) !== '') ? trim($schoolName) : null;
            $cleanDesig = ($designation !== null && trim($designation) !== '') ? trim($designation) : null;
            $cleanEmail = ($email !== null && trim($email) !== '') ? trim($email) : null;

            $params = [
                ':name'         => $cleanName,
                ':mobile'       => $m,
                ':user_id'      => $cleanUserId,
                ':region'       => $cleanRegion,
                ':fincert'      => $cleanFincert,
                ':school_name'  => $cleanSchool,
                ':designation'  => $cleanDesig,
                ':email'        => $cleanEmail,
            ];

            $stmtGate->execute($params);
            $stmtGift->execute($params);
            $importedCount++;
        }
        $pdo->commit();

        // Automatically assign newly imported visitors to appropriate counters
        try {
            Database::syncVisitorCounters($pdo);
        } catch (\Throwable $t) {
            error_log("Failed to auto-sync visitor counters after CSV import: " . $t->getMessage());
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($handle);
        header("Location: admin.php?tab={$currentTab}&msg=import_error");
        exit;
    }
    fclose($handle);
    header("Location: admin.php?tab={$currentTab}&msg=imported&import_success={$importedCount}&count={$importedCount}&skipped={$skippedCount}");
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'gate_csv') {
    if (empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
        header('Location: admin.php?tab=gate&msg=permission_denied');
        exit;
    }
    $stmt = $pdo->query("SELECT * FROM `allowed_visitors` ORDER BY name ASC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=gate_visitors_2day_' . date('Y-m-d_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'S.No', 'Full Name', 'Register Id', 'FinCert ID (Your Id no.)', 'Region', 'School Name', 'Designation', 'Mobile Number', 'Email',
        'Day 1 Status', 'Day 1 Check-in Time (IST)', 'Day 2 Status', 'Day 2 Check-in Time (IST)', 'Created At (IST)'
    ]);
    $sno = 1;
    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $sno++,
            $r['name'] ?? '',
            $r['user_id'] ?? '',
            $r['fincert'] ?? '',
            $r['region'] ?? '',
            $r['school_name'] ?? '',
            $r['designation'] ?? '',
            $r['mobile'] ?? '',
            $r['email'] ?? '',
            (int)($r['is_day1_checked_in'] ?? 0) === 1 ? 'Inside Gate' : 'Pending',
            !empty($r['day1_checked_in_at']) ? formatIST($r['day1_checked_in_at'], 'd M Y, h:i:s A') : 'N/A',
            (int)($r['is_day2_checked_in'] ?? 0) === 1 ? 'Inside Gate' : 'Pending',
            !empty($r['day2_checked_in_at']) ? formatIST($r['day2_checked_in_at'], 'd M Y, h:i:s A') : 'N/A',
            !empty($r['created_at']) ? formatIST($r['created_at'], 'd M Y, h:i:s A') : 'N/A'
        ]);
    }
    fclose($out);
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'gate_tab_csv') {
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['user_role']) || $_SESSION['user_role'] !== 'super_admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden: Super Admin access required.";
        exit;
    }

    $gSearch = trim((string)($_GET['gsearch'] ?? ''));
    $gFilter = trim((string)($_GET['gfilter'] ?? 'all'));
    $gRegion = trim((string)($_GET['gregion'] ?? ''));

    $gSql = "SELECT a.*, 
                    c.name AS counter_name,
                    COALESCE(g.is_gift_collected, 0) AS is_gift_collected
             FROM `allowed_visitors` a
             LEFT JOIN `gift_distribution` g ON a.user_id = g.user_id
             LEFT JOIN `counters` c ON g.counter_id = c.id
             WHERE 1=1";
    $gP = [];
    if ($gSearch !== '') {
        $gSql .= " AND (a.name LIKE :s1 OR a.mobile LIKE :s2 OR a.user_id LIKE :s3 OR a.fincert LIKE :s4 OR a.region LIKE :s5 OR a.school_name LIKE :s6)";
        $gP[':s1'] = $gP[':s2'] = $gP[':s3'] = $gP[':s4'] = $gP[':s5'] = $gP[':s6'] = "%$gSearch%";
    }
    if ($gRegion !== '') {
        $gSql .= " AND a.region = :greg";
        $gP[':greg'] = $gRegion;
    }
    if ($gFilter === 'day1') {
        $gSql .= " AND a.is_day1_checked_in = 1";
    } elseif ($gFilter === 'day2') {
        $gSql .= " AND a.is_day2_checked_in = 1";
    } elseif ($gFilter === 'pending_both') {
        $gSql .= " AND a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0";
    } elseif ($gFilter === 'checked_in') {
        $gSql .= " AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1)";
    } elseif ($gFilter === 'pending') {
        $gSql .= " AND a.is_day1_checked_in = 0";
    }

    $gSql .= " ORDER BY 
        CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 0 ELSE 1 END ASC,
        GREATEST(
            COALESCE(CASE WHEN a.is_day1_checked_in = 1 THEN a.day1_checked_in_at END, '1970-01-01 00:00:00'),
            COALESCE(CASE WHEN a.is_day2_checked_in = 1 THEN a.day2_checked_in_at END, '1970-01-01 00:00:00')
        ) DESC,
        a.name ASC";

    $gStmt = $pdo->prepare($gSql);
    $gStmt->execute($gP);
    $rows = $gStmt->fetchAll(PDO::FETCH_ASSOC);
    sortGateVisitors($rows);

    $tabFileSlugMap = [
        'all'          => 'all',
        'day1'         => 'day1_inside',
        'day2'         => 'day2_inside',
        'pending_both' => 'pending_both',
    ];
    $tabSlug = $tabFileSlugMap[$gFilter] ?? 'all';
    $dateIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    $regionSlug = '';
    if (!empty($gRegion)) {
        $cleanReg = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($gRegion)));
        $cleanReg = trim($cleanReg, '_');
        if ($cleanReg !== '') {
            $regionSlug = '_' . $cleanReg;
        }
    }
    $filename = "gate_entry_{$tabSlug}{$regionSlug}_{$dateIst}.csv";

    // Clean any prior output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    // CSV Headers
    fputcsv($out, [
        '#',
        'Full Name',
        'Register Id',
        'FinCert',
        'Region',
        'Counter',
        'School',
        'Designation',
        'Mobile',
        'Email',
        'Day 1 Status',
        'Day 1 Check-in Time (IST)',
        'Day 2 Status',
        'Day 2 Check-in Time (IST)',
        'COE Kit Status'
    ]);

    $csvSanitize = static function ($val): string {
        $str = (string)($val ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    };

    $sno = 1;
    foreach ($rows as $r) {
        $d1Status = ((int)($r['is_day1_checked_in'] ?? 0) === 1) ? 'Inside Gate' : 'Pending';
        $d1Time = !empty($r['day1_checked_in_at']) ? formatIST($r['day1_checked_in_at'], 'd M Y, h:i A') : '';

        $d2Status = ((int)($r['is_day2_checked_in'] ?? 0) === 1) ? 'Inside Gate' : 'Pending';
        $d2Time = !empty($r['day2_checked_in_at']) ? formatIST($r['day2_checked_in_at'], 'd M Y, h:i A') : '';

        $kitStatus = ((int)($r['is_gift_collected'] ?? 0) === 1) ? 'Collected' : 'Pending';

        $line = [
            $sno++,
            $csvSanitize($r['name'] ?? ''),
            $csvSanitize($r['user_id'] ?? ''),
            $csvSanitize($r['fincert'] ?? ''),
            $csvSanitize($r['region'] ?? ''),
            $csvSanitize($r['counter_name'] ?? ''),
            $csvSanitize($r['school_name'] ?? ''),
            $csvSanitize($r['designation'] ?? ''),
            $csvSanitize($r['mobile'] ?? ''),
            $csvSanitize($r['email'] ?? ''),
            $d1Status,
            $d1Time,
            $d2Status,
            $d2Time,
            $kitStatus
        ];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'gift_tab_csv') {
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['super_admin', 'counter'], true)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "403 Forbidden: Access restricted.";
        exit;
    }

    $isExportSuperAdmin = ($_SESSION['user_role'] === 'super_admin');
    $isExportCounter    = ($_SESSION['user_role'] === 'counter');
    $exportCounterId    = (int)($_SESSION['counter_id'] ?? 0);
    $exportCounterName  = (string)($_SESSION['counter_name'] ?? '');

    // Resolve counterId and counterName for counter users if not present in session
    if ($isExportCounter && ($exportCounterId === 0 || $exportCounterName === '')) {
        $cStmt = $pdo->prepare("SELECT id, name, code, name_range FROM `counters` WHERE username = :u LIMIT 1");
        $cStmt->execute([':u' => $_SESSION['admin_username'] ?? '']);
        $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
        if ($cRow) {
            $exportCounterId   = (int)$cRow['id'];
            $exportCounterName = (string)$cRow['name'];
        }
    }

    $dSearch = trim((string)($_GET['dsearch'] ?? ''));
    $dFilter = trim((string)($_GET['dfilter'] ?? 'entered'));
    if (!in_array($dFilter, ['entered', 'collected', 'pending', 'not_entered', 'all'], true)) {
        $dFilter = 'entered';
    }

    $dSql = "SELECT g.*, 
                    c.name AS counter_name,
                    c.name_range AS counter_range,
                    (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered,
                    GREATEST(COALESCE(a.day1_checked_in_at, '1970-01-01 00:00:00'), COALESCE(a.day2_checked_in_at, '1970-01-01 00:00:00'), COALESCE(a.checked_in_at, '1970-01-01 00:00:00')) AS gate_entered_at,
                    a.is_day1_checked_in, a.day1_checked_in_at,
                    a.is_day2_checked_in, a.day2_checked_in_at
             FROM `gift_distribution` g
             LEFT JOIN `counters` c ON c.id = g.counter_id
             LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
             WHERE 1=1";
    $dP = [];

    if ($dSearch !== '') {
        $dSql .= " AND (g.name LIKE :s1 OR g.mobile LIKE :s2 OR g.user_id LIKE :s3 OR g.fincert LIKE :s4 OR g.region LIKE :s5 OR g.school_name LIKE :s6)";
        $dP[':s1'] = $dP[':s2'] = $dP[':s3'] = $dP[':s4'] = $dP[':s5'] = $dP[':s6'] = "%$dSearch%";
    }

    $counterFilterName = '';
    $dRegion = '';

    if ($isExportCounter) {
        // Enforce counter user isolation: lock to their own counter, ignore any client counter or region param
        $dSql .= " AND g.counter_id = :mycid";
        $dP[':mycid'] = $exportCounterId;
    } else {
        // Super Admin
        $dCounter = isset($_GET['dcounter']) ? (int)$_GET['dcounter'] : 0;
        $dRegion  = trim((string)($_GET['dregion'] ?? ''));

        if ($dCounter > 0) {
            $dSql .= " AND g.counter_id = :dcid";
            $dP[':dcid'] = $dCounter;
            $cntNameStmt = $pdo->prepare("SELECT name FROM `counters` WHERE id = :cid LIMIT 1");
            $cntNameStmt->execute([':cid' => $dCounter]);
            $counterFilterName = (string)$cntNameStmt->fetchColumn();
        }
        if ($dRegion !== '') {
            $dSql .= " AND g.region = :dreg";
            $dP[':dreg'] = $dRegion;
        }
    }

    if ($dFilter === 'entered') {
        $dSql .= " AND g.is_gift_collected = 0 AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1)";
        $dSql .= " ORDER BY gate_entered_at DESC, g.name ASC";
    } elseif ($dFilter === 'collected') {
        $dSql .= " AND g.is_gift_collected = 1";
        $dSql .= " ORDER BY COALESCE(g.gift_collected_at, '1970-01-01 00:00:00') DESC, g.name ASC";
    } elseif ($dFilter === 'pending') {
        $dSql .= " AND g.is_gift_collected = 0";
        $dSql .= " ORDER BY CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 0 ELSE 1 END ASC,
                            CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN gate_entered_at END DESC,
                            g.name ASC";
    } elseif ($dFilter === 'not_entered') {
        $dSql .= " AND g.is_gift_collected = 0 AND (a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0)";
        $dSql .= " ORDER BY g.name ASC";
    } else {
        // all
        $dSql .= " ORDER BY 
            CASE WHEN g.is_gift_collected = 1 THEN 0 ELSE 1 END ASC,
            COALESCE(g.gift_collected_at, '1970-01-01 00:00:00') DESC,
            g.name ASC";
    }

    $dStmt = $pdo->prepare($dSql);
    $dStmt->execute($dP);
    $rows = $dStmt->fetchAll(PDO::FETCH_ASSOC);
    sortGiftVisitors($rows, $dFilter);

    $tabSlugMap = [
        'entered'     => 'entered',
        'collected'   => 'collected',
        'pending'     => 'pending',
        'not_entered' => 'not_entered',
        'all'         => 'all',
    ];
    $tabSlug = $tabSlugMap[$dFilter] ?? 'entered';
    $dateIst = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

    $scopeSlug = '';
    if ($isExportSuperAdmin) {
        if ($dCounter > 0 && !empty($counterFilterName)) {
            $scopeSlug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($counterFilterName)));
        } elseif (!empty($dRegion)) {
            $scopeSlug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($dRegion)));
        }
    } else {
        if (!empty($exportCounterName)) {
            $scopeSlug = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($exportCounterName)));
        }
    }
    $scopeSlug = trim((string)$scopeSlug, '_');
    $scopePart = ($scopeSlug !== '') ? '_' . $scopeSlug : '';
    $filename = "coe_kit_{$tabSlug}{$scopePart}_{$dateIst}.csv";

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");

    // CSV Headers (17 columns)
    fputcsv($out, [
        '#',
        'Full Name',
        'Register Id',
        'FinCert',
        'Region',
        'Counter',
        'School',
        'Designation',
        'Mobile',
        'Email',
        'Gate Day 1 Status',
        'Day 1 Check-in Time (IST)',
        'Gate Day 2 Status',
        'Day 2 Check-in Time (IST)',
        'COE Kit Status',
        'Kit Collected Time (IST)',
        'Handed Over By'
    ]);

    $csvSanitize = static function ($val): string {
        $str = (string)($val ?? '');
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@'], true)) {
            return "'" . $str;
        }
        return $str;
    };

    $sno = 1;
    foreach ($rows as $r) {
        $d1CheckedIn = ((int)($r['is_day1_checked_in'] ?? 0) === 1);
        $d1Status    = $d1CheckedIn ? 'Inside Gate' : 'Pending';
        $d1Time      = ($d1CheckedIn && !empty($r['day1_checked_in_at'])) ? formatIST($r['day1_checked_in_at'], 'd M Y, h:i A') : '';

        $d2CheckedIn = ((int)($r['is_day2_checked_in'] ?? 0) === 1);
        $d2Status    = $d2CheckedIn ? 'Inside Gate' : 'Pending';
        $d2Time      = ($d2CheckedIn && !empty($r['day2_checked_in_at'])) ? formatIST($r['day2_checked_in_at'], 'd M Y, h:i A') : '';

        $isGiftCollected = ((int)($r['is_gift_collected'] ?? 0) === 1);
        if ($isGiftCollected) {
            $kitStatus = 'Collected';
        } elseif ($d1CheckedIn || $d2CheckedIn) {
            $kitStatus = 'Pending';
        } else {
            $kitStatus = 'Not Entered';
        }

        $kitTime = ($isGiftCollected && !empty($r['gift_collected_at'])) ? formatIST($r['gift_collected_at'], 'd M Y, h:i A') : '';

        $line = [
            $sno++,
            $csvSanitize($r['name'] ?? ''),
            $csvSanitize($r['user_id'] ?? ''),
            $csvSanitize($r['fincert'] ?? ''),
            $csvSanitize($r['region'] ?? ''),
            $csvSanitize($r['counter_name'] ?? ''),
            $csvSanitize($r['school_name'] ?? ''),
            $csvSanitize($r['designation'] ?? ''),
            $csvSanitize($r['mobile'] ?? ''),
            $csvSanitize($r['email'] ?? ''),
            $d1Status,
            $d1Time,
            $d2Status,
            $d2Time,
            $kitStatus,
            $kitTime,
            $csvSanitize($r['handed_over_by'] ?? '')
        ];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'gift_csv') {
    if (!$isSuperAdmin && !$isCounter) {
        header('Location: admin.php?tab=gift&msg=permission_denied');
        exit;
    }

    if ($isCounter) {
        $stmt = $pdo->prepare("SELECT * FROM `gift_distribution` WHERE counter_id = :cid ORDER BY name ASC");
        $stmt->execute([':cid' => $counterId]);
        $filenameRegion = preg_replace('/[^a-z0-9]+/i', '_', strtolower($counterName ?: 'counter'));
        $filename = 'coe_kit_distribution_' . $filenameRegion . '_' . date('Y-m-d_His') . '.csv';
    } else {
        $dRegionFilter = trim((string)($_GET['dregion'] ?? ''));
        $dCounterFilter = (int)($_GET['dcounter'] ?? 0);
        $sql = "SELECT * FROM `gift_distribution` WHERE 1=1";
        $p = [];
        if ($dCounterFilter > 0) {
            $sql .= " AND counter_id = :dcid";
            $p[':dcid'] = $dCounterFilter;
        }
        if ($dRegionFilter !== '') {
            $sql .= " AND region = :reg";
            $p[':reg'] = $dRegionFilter;
        }
        $sql .= " ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($p);
        $filename = 'coe_kit_distribution_all_' . date('Y-m-d_His') . '.csv';
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'S.No', 'Full Name', 'Register Id', 'FinCert ID (Your Id no.)', 'Region', 'School Name', 'Designation', 'Mobile Number', 'Email',
        'Kit Status', 'Handover Timestamp (IST)', 'Handed Over By', 'Counter Region', 'Created At (IST)'
    ]);
    $sno = 1;
    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            $sno++,
            $r['name'] ?? '',
            $r['user_id'] ?? '',
            $r['fincert'] ?? '',
            $r['region'] ?? '',
            $r['school_name'] ?? '',
            $r['designation'] ?? '',
            $r['mobile'] ?? '',
            $r['email'] ?? '',
            (int)($r['is_gift_collected'] ?? 0) === 1 ? 'Kit Collected' : 'Pending',
            !empty($r['gift_collected_at']) ? formatIST($r['gift_collected_at'], 'd M Y, h:i:s A') : 'N/A',
            $r['handed_over_by'] ?? '',
            $r['counter_region'] ?? '',
            !empty($r['created_at']) ? formatIST($r['created_at'], 'd M Y, h:i:s A') : 'N/A'
        ]);
    }
    fclose($out);
    exit;
}

// =========================================================================
// Metrics (2-Day Event Gate Tracking & Gift Distribution)
// =========================================================================
$d1Count = (int)$pdo->query("SELECT COUNT(*) FROM `allowed_visitors` WHERE is_day1_checked_in = 1")->fetchColumn();
$d2Count = (int)$pdo->query("SELECT COUNT(*) FROM `allowed_visitors` WHERE is_day2_checked_in = 1")->fetchColumn();
$totalVisitors = (int)$pdo->query("SELECT COUNT(*) FROM `allowed_visitors`")->fetchColumn();
$pendingBothCount = (int)$pdo->query("SELECT COUNT(*) FROM `allowed_visitors` WHERE is_day1_checked_in = 0 AND is_day2_checked_in = 0")->fetchColumn();

$d1Pending = max(0, $totalVisitors - $d1Count);
$d2Pending = max(0, $totalVisitors - $d2Count);
$d1Rate    = $totalVisitors > 0 ? round(($d1Count / $totalVisitors) * 100) : 0;
$d2Rate    = $totalVisitors > 0 ? round(($d2Count / $totalVisitors) * 100) : 0;

// Legacy aliases for backward safety
$checkedInCount   = $d1Count;
$gatePending      = $d1Pending;
$gateRate         = $d1Rate;

try {
    if ($isCounter) {
        $dmStmt = $pdo->prepare("
            SELECT COUNT(*) AS total, 
                   SUM(g.is_gift_collected=1) AS given, 
                   SUM(g.is_gift_collected=0) AS pend,
                   SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS entered,
                   SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0) THEN 1 ELSE 0 END) AS not_entered
            FROM `gift_distribution` g
            LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
            WHERE g.counter_id = :cid
        ");
        $dmStmt->execute([':cid' => $counterId]);
        $dm = $dmStmt->fetch();
        if (!$dm || $dm['total'] === null) {
            $dm = ['total' => 0, 'given' => 0, 'pend' => 0, 'entered' => 0, 'not_entered' => 0];
        }
    } else {
        $dm = $pdo->query("
            SELECT COUNT(*) AS total, 
                   SUM(g.is_gift_collected=1) AS given, 
                   SUM(g.is_gift_collected=0) AS pend,
                   SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS entered,
                   SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0) THEN 1 ELSE 0 END) AS not_entered
            FROM `gift_distribution` g
            LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
        ")->fetch();
    }
} catch (\Throwable $e) {
    $dm = ['total' => 0, 'given' => 0, 'pend' => 0, 'entered' => 0, 'not_entered' => 0];
}
$totalGiftElig   = (int)($dm['total'] ?? 0);
$giftsGiven      = (int)($dm['given'] ?? 0);
$giftsPending    = (int)($dm['pend']  ?? 0);
$giftsEntered    = (int)($dm['entered'] ?? 0);
$giftsNotEntered = (int)($dm['not_entered'] ?? 0);
$giftRate        = $totalGiftElig > 0 ? round(($giftsGiven / $totalGiftElig) * 100) : 0;

// Counters Management & Filter List (Super Admin & Combined Counters)
$countersList = [];
$totalCountersCount = 0;
$activeCountersCount = 0;
$totalCountersVisitors = 0;
$totalCountersGiven = 0;
$totalCountersPending = 0;

try {
    $countersStmt = $pdo->query("
        SELECT c.id, c.counter_no, c.name, c.code, c.username, c.name_range, c.is_active, c.last_login,
               a.id AS admin_id, a.status AS admin_status,
               COALESCE(g_agg.total_visitors, 0) AS total_visitors,
               COALESCE(g_agg.kits_distributed, 0) AS kits_distributed,
               COALESCE(g_agg.kits_pending, 0) AS kits_pending,
               (
                   SELECT GROUP_CONCAT(NULLIF(TRIM(cr.region), '') ORDER BY cr.region SEPARATOR ', ')
                   FROM `counter_regions` cr
                   WHERE cr.counter_id = c.id AND TRIM(cr.region) != ''
               ) AS regions_covered
        FROM `counters` c
        LEFT JOIN `admins` a ON (a.username = c.username)
        LEFT JOIN (
            SELECT counter_id,
                   COUNT(*) AS total_visitors,
                   SUM(CASE WHEN is_gift_collected = 1 THEN 1 ELSE 0 END) AS kits_distributed,
                   SUM(CASE WHEN is_gift_collected = 0 THEN 1 ELSE 0 END) AS kits_pending
            FROM `gift_distribution`
            WHERE counter_id IS NOT NULL AND counter_id > 0
            GROUP BY counter_id
        ) g_agg ON (g_agg.counter_id = c.id)
        WHERE c.is_active = 1 AND c.code != 'helpdesk'
        ORDER BY COALESCE(c.counter_no, 999) ASC, c.id ASC
    ");
    $countersList = $countersStmt ? $countersStmt->fetchAll() : [];
    $totalCountersCount = count($countersList);
    foreach ($countersList as $cRow) {
        if ((int)($cRow['is_active'] ?? 1) === 1) {
            $activeCountersCount++;
        }
        $totalCountersVisitors += (int)($cRow['total_visitors'] ?? 0);
        $totalCountersGiven    += (int)($cRow['kits_distributed'] ?? 0);
        $totalCountersPending  += (int)($cRow['kits_pending'] ?? 0);
    }

    // Sanity check: sum of counter visitor counts must equal total visitors
    $totalDbVisitors = (int)$pdo->query("SELECT COUNT(*) FROM `allowed_visitors`")->fetchColumn();
    if ($totalCountersVisitors !== $totalDbVisitors) {
        error_log("[WARNING] Sanity check mismatch: Sum of counter visitor counts ($totalCountersVisitors) does not match total allowed visitors ($totalDbVisitors).");
    }
} catch (\Throwable $e) {
    error_log("Failed to query counters: " . $e->getMessage());
}

// =========================================================================
// Regions & Filters (Tab 1 & Tab 2)
// Safety-net query: Explicit utf8mb4_unicode_ci collations on both sides of UNION
// Wrapped in try/catch to ensure zero fatal error crashes
// =========================================================================
$allRegions = [];
try {
    $regionsStmt = $pdo->query("
        SELECT DISTINCT region FROM (
            SELECT region COLLATE utf8mb4_unicode_ci AS region 
            FROM `allowed_visitors` 
            WHERE region IS NOT NULL AND TRIM(region) != ''
            UNION 
            SELECT region COLLATE utf8mb4_unicode_ci AS region 
            FROM `gift_distribution` 
            WHERE region IS NOT NULL AND TRIM(region) != ''
        ) AS combined 
        WHERE region IS NOT NULL AND TRIM(region) != '' 
        ORDER BY region ASC
    ");
    $rawRegions = $regionsStmt ? $regionsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    error_log("Failed to fetch distinct regions with UNION: " . $e->getMessage());
    try {
        $rawRegions = $pdo->query("
            SELECT DISTINCT region 
            FROM `allowed_visitors` 
            WHERE region IS NOT NULL AND TRIM(region) != '' 
            ORDER BY region ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $t) {
        error_log("Fallback distinct regions query failed: " . $t->getMessage());
        $rawRegions = [];
    }
}

// Normalize case and trim whitespace (e.g. 'Pune' vs 'PUNE' or extra spaces)
$normalizedRegions = [];
foreach ($rawRegions as $reg) {
    $clean = trim((string)$reg);
    if ($clean === '' || $clean === '—' || $clean === '-') continue;
    $key = mb_strtolower($clean, 'UTF-8');
    if (!isset($normalizedRegions[$key])) {
        $normalizedRegions[$key] = $clean;
    }
}
$allRegions = array_values($normalizedRegions);
natcasesort($allRegions);
$allRegions = array_values($allRegions);

$regionCounterHintMap = [];
foreach ($allRegions as $reg) {
    if (mb_strtolower($reg, 'UTF-8') === 'pune') {
        $regionCounterHintMap[$reg] = 'pune';
    } else {
        $resC = Database::resolveCounterForVisitor($reg, '', $pdo);
        $regionCounterHintMap[$reg] = $resC ? $resC['name'] : 'Help Desk';
    }
}

try {
    $gateRegionCountsStmt = $pdo->query("
        SELECT region, COUNT(*) as cnt 
        FROM `allowed_visitors` 
        WHERE region IS NOT NULL AND TRIM(region) != '' 
        GROUP BY region
    ");
    $gateRegionCounts = $gateRegionCountsStmt ? $gateRegionCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
} catch (\Throwable $e) {
    error_log("Failed to fetch gate region counts: " . $e->getMessage());
    $gateRegionCounts = [];
}

try {
    $giftRegionCountsStmt = $pdo->query("
        SELECT region, COUNT(*) as cnt 
        FROM `gift_distribution` 
        WHERE region IS NOT NULL AND TRIM(region) != '' 
        GROUP BY region
    ");
    $giftRegionCounts = $giftRegionCountsStmt ? $giftRegionCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
} catch (\Throwable $e) {
    error_log("Failed to fetch gift region counts: " . $e->getMessage());
    $giftRegionCounts = [];
}

// Event Settings for Gate QR & Public Self Check-In
$eventSettings = getEventSettings($pdo);
$settingsLogs  = [];
if ($tab === 'gate_qr' && $isSuperAdmin) {
    try {
        $logStmt = $pdo->query("SELECT performed_by AS changed_by, details, COALESCE(NULLIF(created_at_ist, ''), DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p')) AS changed_at FROM `audit_log` WHERE `action` = 'update_event_settings' ORDER BY `id` DESC LIMIT 20");
        if ($logStmt) {
            $settingsLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (\Throwable $e) {
        $settingsLogs = [];
    }
}
$gateCheckinUrl          = getBaseUrl() . '/gate_checkin.php';
$currentIstDate          = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$currentIstDateFormatted = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('d M Y');
$isD1Today               = ($currentIstDate === ($eventSettings['day1_date'] ?? '2026-11-18'));
$isD2Today               = ($currentIstDate === ($eventSettings['day2_date'] ?? '2026-11-19'));
$selfCheckinIsOpen       = (int)($eventSettings['self_checkin_open'] ?? 1) === 1;

if ($isD1Today) {
    $todayStatusText = "Today ({$currentIstDateFormatted}) = Day 1";
} elseif ($isD2Today) {
    $todayStatusText = "Today ({$currentIstDateFormatted}) = Day 2";
} else {
    $todayStatusText = "Today ({$currentIstDateFormatted}) = Entry is not open today";
}

// Pagination config
$perPage = 50;

// =========================================================================
// Gate Visitors (Tab 1 - 2-Day Event Filters & Pagination)
// =========================================================================
$gSearch = trim((string)($_GET['gsearch'] ?? ''));
$gFilter = trim((string)($_GET['gfilter'] ?? 'all'));
$gRegion = trim((string)($_GET['gregion'] ?? ''));
$gPage   = max(1, (int)($_GET['gpage'] ?? 1));

$gSql = "SELECT * FROM `allowed_visitors` WHERE 1=1";
$gP   = [];
if ($gSearch !== '') {
    $gSql .= " AND (name LIKE :s1 OR mobile LIKE :s2 OR user_id LIKE :s3 OR fincert LIKE :s4 OR region LIKE :s5 OR school_name LIKE :s6)";
    $gP[':s1'] = $gP[':s2'] = $gP[':s3'] = $gP[':s4'] = $gP[':s5'] = $gP[':s6'] = "%$gSearch%";
}
if ($gRegion !== '') {
    $gSql .= " AND region = :greg";
    $gP[':greg'] = $gRegion;
}
if ($gFilter === 'day1') {
    $gSql .= " AND is_day1_checked_in = 1";
} elseif ($gFilter === 'day2') {
    $gSql .= " AND is_day2_checked_in = 1";
} elseif ($gFilter === 'pending_both') {
    $gSql .= " AND is_day1_checked_in = 0 AND is_day2_checked_in = 0";
} elseif ($gFilter === 'checked_in') {
    $gSql .= " AND (is_day1_checked_in = 1 OR is_day2_checked_in = 1)";
} elseif ($gFilter === 'pending') {
    $gSql .= " AND is_day1_checked_in = 0";
}

$gSql .= " ORDER BY 
    CASE WHEN (is_day1_checked_in = 1 OR is_day2_checked_in = 1) THEN 0 ELSE 1 END ASC,
    GREATEST(
        COALESCE(CASE WHEN is_day1_checked_in = 1 THEN day1_checked_in_at END, '1970-01-01 00:00:00'),
        COALESCE(CASE WHEN is_day2_checked_in = 1 THEN day2_checked_in_at END, '1970-01-01 00:00:00')
    ) DESC,
    name ASC";

$gStmt = $pdo->prepare($gSql);
$gStmt->execute($gP);
$gateVisitors = $gStmt->fetchAll();
sortGateVisitors($gateVisitors);

$totalGateRows  = count($gateVisitors);
$totalGatePages = max(1, (int)ceil($totalGateRows / $perPage));
if ($gPage > $totalGatePages) {
    $gPage = $totalGatePages;
}
$gateOffset          = ($gPage - 1) * $perPage;
$gateVisibleVisitors = array_slice($gateVisitors, $gateOffset, $perPage);

// =========================================================================
// Gift Visitors (Tab 2 - Filters & Pagination)
// =========================================================================
$dSearch  = trim((string)($_GET['dsearch'] ?? ''));
$dFilter  = trim((string)($_GET['dfilter'] ?? ''));
if ($dFilter === '' || !in_array($dFilter, ['entered', 'collected', 'pending', 'not_entered', 'all'], true)) {
    $dFilter = 'entered';
}
$dCounter = isset($_GET['dcounter']) ? (int)$_GET['dcounter'] : 0;
$dRegion  = trim((string)($_GET['dregion'] ?? ''));
$dPage    = max(1, (int)($_GET['dpage'] ?? 1));

$activeCounterName = '';
if ($dCounter > 0 && !empty($countersList)) {
    foreach ($countersList as $cOpt) {
        if ((int)$cOpt['id'] === $dCounter) {
            $activeCounterName = (string)$cOpt['name'];
            break;
        }
    }
}

// For counter staff: determine regions covered by this counter
$counterAllowedRegions = [];
if ($isCounter) {
    $crStmt = $pdo->prepare("SELECT region FROM `counter_regions` WHERE counter_id = :cid ORDER BY region ASC");
    $crStmt->execute([':cid' => $counterId]);
    $counterAllowedRegions = array_filter(array_map('trim', $crStmt->fetchAll(PDO::FETCH_COLUMN)));
}

// Compute dynamic tab counts following scope, filters (Region / Counter) and Search
$cSql = "SELECT COUNT(*) AS total,
                SUM(CASE WHEN g.is_gift_collected = 1 THEN 1 ELSE 0 END) AS given,
                SUM(CASE WHEN g.is_gift_collected = 0 THEN 1 ELSE 0 END) AS pend,
                SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS entered,
                SUM(CASE WHEN g.is_gift_collected = 0 AND (a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0) THEN 1 ELSE 0 END) AS not_entered
         FROM `gift_distribution` g
         LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
         WHERE 1=1";
$cP = [];
if ($dSearch !== '') {
    $cSql .= " AND (g.name LIKE :cs1 OR g.mobile LIKE :cs2 OR g.user_id LIKE :cs3 OR g.fincert LIKE :cs4 OR g.region LIKE :cs5 OR g.school_name LIKE :cs6)";
    $cP[':cs1'] = $cP[':cs2'] = $cP[':cs3'] = $cP[':cs4'] = $cP[':cs5'] = $cP[':cs6'] = "%$dSearch%";
}
if ($isCounter) {
    $cSql .= " AND g.counter_id = :mycid";
    $cP[':mycid'] = $counterId;
    if ($dRegion !== '') {
        $cSql .= " AND g.region = :dreg";
        $cP[':dreg'] = $dRegion;
    }
} else {
    if ($dCounter > 0) {
        $cSql .= " AND g.counter_id = :dcid";
        $cP[':dcid'] = $dCounter;
    }
    if ($dRegion !== '') {
        $cSql .= " AND g.region = :dreg";
        $cP[':dreg'] = $dRegion;
    }
}
$cStmt = $pdo->prepare($cSql);
$cStmt->execute($cP);
$cRow = $cStmt->fetch(PDO::FETCH_ASSOC);

$totalGiftElig   = (int)($cRow['total'] ?? 0);
$giftsGiven      = (int)($cRow['given'] ?? 0);
$giftsPending    = (int)($cRow['pend']  ?? 0);
$giftsEntered    = (int)($cRow['entered'] ?? 0);
$giftsNotEntered = (int)($cRow['not_entered'] ?? 0);
$giftRate        = $totalGiftElig > 0 ? round(($giftsGiven / $totalGiftElig) * 100) : 0;

$dSql = "SELECT g.*, c.name AS assigned_counter_name, c.name_range AS assigned_counter_range,
                (CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 1 ELSE 0 END) AS is_gate_entered,
                GREATEST(COALESCE(a.day1_checked_in_at, '1970-01-01 00:00:00'), COALESCE(a.day2_checked_in_at, '1970-01-01 00:00:00'), COALESCE(a.checked_in_at, '1970-01-01 00:00:00')) AS gate_entered_at
         FROM `gift_distribution` g
         LEFT JOIN `counters` c ON c.id = g.counter_id
         LEFT JOIN `allowed_visitors` a ON a.user_id = g.user_id
         WHERE 1=1";
$dP   = [];
if ($dSearch !== '') {
    $dSql .= " AND (g.name LIKE :s1 OR g.mobile LIKE :s2 OR g.user_id LIKE :s3 OR g.fincert LIKE :s4 OR g.region LIKE :s5 OR g.school_name LIKE :s6)";
    $dP[':s1'] = $dP[':s2'] = $dP[':s3'] = $dP[':s4'] = $dP[':s5'] = $dP[':s6'] = "%$dSearch%";
}

if ($isCounter) {
    $dSql .= " AND g.counter_id = :mycid";
    $dP[':mycid'] = $counterId;
    if ($dRegion !== '') {
        $dSql .= " AND g.region = :dreg";
        $dP[':dreg'] = $dRegion;
    }
} else {
    if ($dCounter > 0) {
        $dSql .= " AND g.counter_id = :dcid";
        $dP[':dcid'] = $dCounter;
    }
    if ($dRegion !== '') {
        $dSql .= " AND g.region = :dreg";
        $dP[':dreg'] = $dRegion;
    }
}

if ($dFilter === 'entered') {
    $dSql .= " AND g.is_gift_collected = 0 AND (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1)";
    $dSql .= " ORDER BY gate_entered_at DESC, g.name ASC";
} elseif ($dFilter === 'collected') {
    $dSql .= " AND g.is_gift_collected = 1";
    $dSql .= " ORDER BY COALESCE(g.gift_collected_at, '1970-01-01 00:00:00') DESC, g.name ASC";
} elseif ($dFilter === 'pending') {
    $dSql .= " AND g.is_gift_collected = 0";
    $dSql .= " ORDER BY CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN 0 ELSE 1 END ASC,
                        CASE WHEN (a.is_day1_checked_in = 1 OR a.is_day2_checked_in = 1) THEN gate_entered_at END DESC,
                        g.name ASC";
} elseif ($dFilter === 'not_entered') {
    $dSql .= " AND g.is_gift_collected = 0 AND (a.is_day1_checked_in = 0 AND a.is_day2_checked_in = 0)";
    $dSql .= " ORDER BY g.name ASC";
} else {
    // all
    $dSql .= " ORDER BY 
        CASE WHEN g.is_gift_collected = 1 THEN 0 ELSE 1 END ASC,
        COALESCE(g.gift_collected_at, '1970-01-01 00:00:00') DESC,
        g.name ASC";
}

$dStmt = $pdo->prepare($dSql);
$dStmt->execute($dP);
$giftVisitors = $dStmt->fetchAll();
sortGiftVisitors($giftVisitors, $dFilter);

$totalGiftRows  = count($giftVisitors);
$totalGiftPages = max(1, (int)ceil($totalGiftRows / $perPage));
if ($dPage > $totalGiftPages) {
    $dPage = $totalGiftPages;
}
$giftOffset          = ($dPage - 1) * $perPage;
$giftVisibleVisitors = array_slice($giftVisitors, $giftOffset, $perPage);

$isGate = $tab === 'gate';

// Helper function to render Gate Visitors Table Rows
if (!function_exists('renderGateTableRows')) {
function renderGateTableRows(array $gateVisitors, string $gFilter = '', string $gSearch = '', string $gRegion = '', int $gPage = 1, int $startIdx = 0): string
{
    ob_start();
    if (empty($gateVisitors)): ?>
        <tr class="tr-empty">
            <td colspan="9" style="text-align:center;padding:3rem;color:var(--text-muted);">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4;"></i>
                No matching visitors found.
            </td>
        </tr>
    <?php else:
        $extraParams = '';
        if (!empty($gFilter) && $gFilter !== 'all') $extraParams .= '&gfilter=' . urlencode($gFilter);
        if (!empty($gSearch)) $extraParams .= '&gsearch=' . urlencode($gSearch);
        if (!empty($gRegion)) $extraParams .= '&gregion=' . urlencode($gRegion);
        if ($gPage > 1) $extraParams .= '&gpage=' . urlencode((string)$gPage);

        foreach ($gateVisitors as $idx => $v): 
            $visitor = $v;
            $rawPhone = trim((string)($v['mobile'] ?? ''));
            $cleanPhone = preg_replace('/\D/', '', $rawPhone);
            $formattedPhone = strlen($cleanPhone) === 10
                ? substr($cleanPhone, 0, 5) . ' ' . substr($cleanPhone, 5)
                : htmlspecialchars($rawPhone);
            $schoolOrDesig = trim((string)($v['school_name'] ?? ''));
            if ($schoolOrDesig === '') {
                $schoolOrDesig = trim((string)($v['designation'] ?? 'Attendee'));
            }
        ?>
            <tr data-id="<?= (int)$v['id'] ?>"
                data-name="<?= htmlspecialchars($v['name']) ?>"
                data-d1-checked="<?= (int)($v['is_day1_checked_in'] ?? 0) ?>"
                data-d1-time="<?= htmlspecialchars($v['day1_checked_in_at'] ?? '') ?>"
                data-d2-checked="<?= (int)($v['is_day2_checked_in'] ?? 0) ?>"
                data-d2-time="<?= htmlspecialchars($v['day2_checked_in_at'] ?? '') ?>">
                <td class="td-id col-id">
                    <span class="id-badge">#<?= $startIdx + $idx + 1 ?></span>
                </td>
                <td class="td-name col-name">
                    <div class="guest-name"><?= htmlspecialchars($v['name']) ?></div>
                    <div class="guest-type" title="<?= htmlspecialchars($schoolOrDesig) ?>"><?= htmlspecialchars($schoolOrDesig) ?></div>
                </td>
                <td class="td-userid col-userid">
                    <span class="card-meta-label">Register Id</span>
                    <span class="user-id-badge" title="<?= htmlspecialchars($v['user_id'] ?: '—') ?>"><?= htmlspecialchars($v['user_id'] ?: '—') ?></span>
                </td>
                <td class="td-fincert col-fincert">
                    <span class="card-meta-label">FinCert</span>
                    <?php $cleanFc = trim((string)($v['fincert'] ?? '')); ?>
                    <span class="user-id-badge fincert-badge" title="<?= htmlspecialchars($cleanFc !== '' ? $cleanFc : '—') ?>"><?= htmlspecialchars($cleanFc !== '' ? $cleanFc : '-') ?></span>
                </td>
                <td class="td-region col-region">
                    <span class="card-meta-label">Region</span>
                    <span class="region-badge" title="<?= htmlspecialchars($v['region'] ?: '—') ?>"><?= htmlspecialchars($v['region'] ?: '—') ?></span>
                </td>
                <td class="td-mobile col-mobile">
                    <?php if ($cleanPhone !== ''): ?>
                        <a href="tel:+91<?= htmlspecialchars($cleanPhone) ?>" class="mobile-badge-link" title="Call guest">
                            <i class="bi bi-telephone-fill"></i>
                            <span class="mobile-num">+91&nbsp;<?= htmlspecialchars($formattedPhone) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:0.82rem;">—</span>
                    <?php endif; ?>
                </td>
                <td class="td-status td-status-d1 col-status-d1">
                    <div class="status-card-inner">
                        <span class="card-status-label">Day 1</span>
                        <?php if ((int)($v['is_day1_checked_in'] ?? 0) === 1): ?>
                            <span class="status-pill pill-green">
                                <span class="pulse-dot"></span> Inside Gate
                            </span>
                            <?php if (!empty($v['day1_checked_in_at'])): ?>
                                <div class="status-timestamp">
                                    <i class="bi bi-clock-history text-success"></i>
                                    <?= htmlspecialchars(formatIST($v['day1_checked_in_at'], 'd M, h:i A')) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-pill pill-amber">
                                <i class="bi bi-clock" style="font-size:0.7rem;"></i> Pending
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="td-status td-status-d2 col-status-d2">
                    <div class="status-card-inner">
                        <span class="card-status-label">Day 2</span>
                        <?php if ((int)($v['is_day2_checked_in'] ?? 0) === 1): ?>
                            <span class="status-pill pill-teal">
                                <span class="pulse-dot-teal"></span> Inside Gate
                            </span>
                            <?php if (!empty($v['day2_checked_in_at'])): ?>
                                <div class="status-timestamp">
                                    <i class="bi bi-clock-history" style="color:#0891b2;"></i>
                                    <?= htmlspecialchars(formatIST($v['day2_checked_in_at'], 'd M, h:i A')) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-pill pill-amber">
                                <i class="bi bi-clock" style="font-size:0.7rem;"></i> Pending
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="td-actions col-actions">
                    <div class="action-btn-group">
                        <!-- Day 1 Action Button -->
                        <?php if ($visitor['is_day1_checked_in'] == 1): ?>
                            <a href="admin.php?tab=gate&action=reset_d1&id=<?= (int)$visitor['id'] ?><?= $extraParams ?>" 
                               class="btn-reset-row action-btn"
                               onclick="return confirm('Reset Day 1 for <?= htmlspecialchars(addslashes($visitor['name'])) ?>?');">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset D1
                            </a>
                        <?php else: ?>
                            <a href="admin.php?tab=gate&action=checkin_d1&id=<?= (int)$visitor['id'] ?><?= $extraParams ?>" 
                               class="btn-checkin-d1 action-btn"
                               title="Check in Day 1">
                                <i class="bi bi-check-lg"></i> Day 1
                            </a>
                        <?php endif; ?>

                        <!-- Day 2 Action Button -->
                        <?php if ($visitor['is_day2_checked_in'] == 1): ?>
                            <a href="admin.php?tab=gate&action=reset_d2&id=<?= (int)$visitor['id'] ?><?= $extraParams ?>" 
                               class="btn-reset-row action-btn"
                               onclick="return confirm('Reset Day 2 for <?= htmlspecialchars(addslashes($visitor['name'])) ?>?');">
                                <i class="bi bi-arrow-counterclockwise"></i> Reset D2
                            </a>
                        <?php else: ?>
                            <a href="admin.php?tab=gate&action=checkin_d2&id=<?= (int)$visitor['id'] ?><?= $extraParams ?>" 
                               class="btn-day2-action action-btn"
                               title="Check in Day 2">
                                <i class="bi bi-check-lg"></i> Day 2
                            </a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    endif;
    return ob_get_clean();
}
}

// Helper function to render Gift Visitors Table Rows
if (!function_exists('renderGiftTableRows')) {
function renderGiftTableRows(array $giftVisitors, string $dFilter = '', string $dSearch = '', string $dRegion = '', int $dPage = 1, int $startIdx = 0, int $dCounter = 0, ?bool $isSingleRegionCounter = null): string
{
    ob_start();
    $isSuperAdmin = !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin';
    $isCounter = !empty($_SESSION['user_role']) && $_SESSION['user_role'] === 'counter';
    if ($isSingleRegionCounter === null) {
        $isSingleRegionCounter = $isCounter && (!empty($_SESSION['counter_is_single_region']) || !empty($_SESSION['counter_range']));
    }
    if (empty($giftVisitors)): ?>
        <tr class="tr-empty">
            <td colspan="8" style="text-align:center;padding:3rem;color:var(--text-muted);">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4;"></i>
                No matching attendees found.
            </td>
        </tr>
    <?php else:
        $extraParams = '';
        if (!empty($dFilter) && $dFilter !== 'all') $extraParams .= '&dfilter=' . urlencode($dFilter);
        if (!empty($dSearch)) $extraParams .= '&dsearch=' . urlencode($dSearch);
        if (!empty($dRegion)) $extraParams .= '&dregion=' . urlencode($dRegion);
        if ($dCounter > 0) $extraParams .= '&dcounter=' . urlencode((string)$dCounter);
        if ($dPage > 1) $extraParams .= '&dpage=' . urlencode((string)$dPage);

        foreach ($giftVisitors as $idx => $v): 
            $rawPhone = trim((string)($v['mobile'] ?? ''));
            $cleanPhone = preg_replace('/\D/', '', $rawPhone);
            $formattedPhone = strlen($cleanPhone) === 10
                ? substr($cleanPhone, 0, 5) . ' ' . substr($cleanPhone, 5)
                : htmlspecialchars($rawPhone);
            $schoolOrDesig = trim((string)($v['school_name'] ?? ''));
            if ($schoolOrDesig === '') {
                $schoolOrDesig = trim((string)($v['designation'] ?? 'Attendee'));
            }
            $assignedCounter = trim((string)($v['assigned_counter_name'] ?? ''));
            if ($assignedCounter === '') {
                $assignedCounter = (!empty($v['region'])) ? ($v['region'] . ' Counter') : 'Help Desk';
            }
            $counterWithWbr = str_replace(' / ', ' / <wbr>', htmlspecialchars($assignedCounter));
        ?>
            <tr data-id="<?= (int)$v['id'] ?>"
                data-name="<?= htmlspecialchars($v['name']) ?>"
                data-gift-collected="<?= (int)($v['is_gift_collected'] ?? 0) ?>"
                data-gift-time="<?= htmlspecialchars($v['gift_collected_at'] ?? '') ?>">
                <td class="td-id col-id">
                    <span class="id-badge">#<?= $startIdx + $idx + 1 ?></span>
                </td>
                <td class="td-name col-name">
                    <div class="guest-name"><?= htmlspecialchars($v['name']) ?></div>
                    <div class="guest-type" title="<?= htmlspecialchars($schoolOrDesig) ?>"><?= htmlspecialchars($schoolOrDesig) ?></div>
                </td>
                <td class="td-userid col-userid">
                    <span class="card-meta-label">Register Id</span>
                    <span class="user-id-badge" title="<?= htmlspecialchars($v['user_id'] ?: '—') ?>"><?= htmlspecialchars($v['user_id'] ?: '—') ?></span>
                </td>
                <td class="td-region col-region">
                    <span class="card-meta-label"><?= $isSingleRegionCounter ? 'Region' : 'Region &amp; Counter' ?></span>
                    <?php if ($isSingleRegionCounter): ?>
                        <span class="region-badge" title="<?= htmlspecialchars($v['region'] ?: '—') ?>"><?= htmlspecialchars($v['region'] ?: '—') ?></span>
                    <?php else: ?>
                        <div class="region-counter-stack">
                            <span class="region-badge" title="<?= htmlspecialchars($v['region'] ?: '—') ?>"><?= htmlspecialchars($v['region'] ?: '—') ?></span>
                            <div class="counter-name-wrap" title="<?= htmlspecialchars($assignedCounter) ?>">
                                <i class="bi bi-shop text-primary flex-shrink-0"></i>
                                <span class="counter-name-text"><?= $counterWithWbr ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="td-mobile col-mobile">
                    <?php if ($cleanPhone !== ''): ?>
                        <a href="tel:+91<?= htmlspecialchars($cleanPhone) ?>" class="mobile-badge-link" title="Call guest">
                            <i class="bi bi-telephone-fill"></i>
                            <span class="mobile-num">+91&nbsp;<?= htmlspecialchars($formattedPhone) ?></span>
                        </a>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:0.82rem;">—</span>
                    <?php endif; ?>
                </td>
                <td class="td-actions col-actions">
                    <div class="action-btn-group">
                        <?php if ((int)$v['is_gift_collected'] === 1): ?>
                            <?php if ($isSuperAdmin): ?>
                                <a href="admin.php?tab=gift&action=reset_gift&id=<?= (int)$v['id'] ?><?= $extraParams ?>"
                                   class="btn-reset-gift action-btn"
                                   onclick="return confirm('Reset kit for <?= htmlspecialchars(addslashes($v['name'])) ?>? This marks the kit as not handed over.');"
                                   title="Reset COE Kit status">
                                    <i class="bi bi-arrow-counterclockwise"></i> Reset Kit
                                </a>
                            <?php else: ?>
                                <span class="status-pill pill-grey" style="cursor:default;" title="Kit Collected">
                                    Collected
                                </span>
                            <?php endif; ?>
                        <?php elseif ((int)($v['is_gate_entered'] ?? 0) === 1): ?>
                            <a href="admin.php?tab=gift&action=mark_given&id=<?= (int)$v['id'] ?><?= $extraParams ?>"
                               class="btn-mark-given action-btn"
                               onclick="return confirm('Confirm COE Kit handover for <?= htmlspecialchars(addslashes($v['name'])) ?>?');"
                               title="Mark COE Kit as given to attendee">
                                <i class="bi bi-box-seam"></i> Give Kit
                            </a>
                        <?php else: ?>
                            <button type="button" 
                                    class="btn-not-entered action-btn" 
                                    disabled 
                                    title="Visitor has not entered at the gate yet">
                                <i class="bi bi-door-closed"></i> Not Entered
                            </button>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="td-gift-status col-gift-status">
                    <div class="gift-status-wrap">
                        <span class="card-status-label">Kit Status</span>
                        <?php if ((int)$v['is_gift_collected'] === 1): ?>
                            <span class="status-pill pill-green">
                                <span class="pulse-dot"></span> Kit Collected
                            </span>
                        <?php elseif ((int)($v['is_gate_entered'] ?? 0) === 1): ?>
                            <span class="status-pill pill-amber">
                                <i class="bi bi-clock" style="font-size:0.7rem;"></i> Pending
                            </span>
                        <?php else: ?>
                            <span class="status-pill pill-not-entered">
                                <i class="bi bi-door-closed" style="font-size:0.75rem;"></i> Not Entered
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="td-gift-time col-gift-time">
                    <div class="gift-time-wrap">
                        <span class="card-status-label">Handover Timestamp</span>
                        <?php if (!empty($v['gift_collected_at'])): ?>
                            <?php if (!empty($v['handed_over_by'])): ?>
                                <div class="gift-handed-by fw-bold text-dark small" title="<?= htmlspecialchars($v['handed_over_by']) ?><?= !empty($v['counter_region']) ? ' (' . htmlspecialchars($v['counter_region']) . ')' : '' ?>">
                                    <i class="bi bi-person-check text-primary me-1"></i><?= htmlspecialchars($v['handed_over_by']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="gift-timestamp text-muted" style="font-size:0.75rem;">
                                <i class="bi bi-clock-history text-success me-1"></i><?= htmlspecialchars(formatIST($v['gift_collected_at'], 'd M, h:i A')) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-muted small gift-not-collected">— Not Yet Handed Over —</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach;
    endif;
    return ob_get_clean();
}
}

// Helper function for responsive pagination controls
if (!function_exists('renderPagination')) {
function renderPagination(int $currentPage, int $totalPages, int $totalRows, int $perPage, string $tab, array $queryParams): string
{
    if ($totalRows === 0) {
        return '<div class="pagination-bar d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2.5 border-top bg-light-subtle small text-muted">' .
               '<div>Showing <strong>0</strong> visitors</div></div>';
    }

    $pageParam = ($tab === 'gate') ? 'gpage' : 'dpage';
    $startRow  = (($currentPage - 1) * $perPage) + 1;
    $endRow    = min($totalRows, $currentPage * $perPage);

    $buildUrl = function(int $p) use ($tab, $pageParam, $queryParams) {
        $params = array_merge($queryParams, ['tab' => $tab, $pageParam => $p]);
        return 'admin.php?' . http_build_query($params);
    };

    ob_start();
    ?>
    <div class="pagination-bar d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2.5 border-top bg-light-subtle">
        <div class="small text-muted">
            Showing <strong><?= number_format($startRow) ?> - <?= number_format($endRow) ?></strong> of <strong><?= number_format($totalRows) ?></strong> (Page <?= $currentPage ?> of <?= $totalPages ?>)
        </div>
        <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0 gap-1 flex-wrap">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link rounded-2" href="<?= htmlspecialchars($buildUrl(1)) ?>" title="First page">&laquo; First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link rounded-2" href="<?= htmlspecialchars($buildUrl($currentPage - 1)) ?>" title="Previous page">&lsaquo; Prev</a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link rounded-2">&laquo; First</span></li>
                        <li class="page-item disabled"><span class="page-link rounded-2">&lsaquo; Prev</span></li>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage   = min($totalPages, $currentPage + 2);
                    if ($startPage > 1) {
                        echo '<li class="page-item disabled"><span class="page-link rounded-2 border-0 bg-transparent">…</span></li>';
                    }
                    for ($p = $startPage; $p <= $endPage; $p++):
                    ?>
                        <li class="page-item <?= $p === $currentPage ? 'active' : '' ?>">
                            <a class="page-link rounded-2 fw-semibold" href="<?= htmlspecialchars($buildUrl($p)) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor;
                    if ($endPage < $totalPages) {
                        echo '<li class="page-item disabled"><span class="page-link rounded-2 border-0 bg-transparent">…</span></li>';
                    }
                    ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link rounded-2" href="<?= htmlspecialchars($buildUrl($currentPage + 1)) ?>" title="Next page">Next &rsaquo;</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link rounded-2" href="<?= htmlspecialchars($buildUrl($totalPages)) ?>" title="Last page">Last &raquo;</a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link rounded-2">Next &rsaquo;</span></li>
                        <li class="page-item disabled"><span class="page-link rounded-2">Last &raquo;</span></li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
}

// Live AJAX Polling Endpoint
if (isset($_GET['ajax_live'])) {
    if ($tab === 'counters' || $tab === 'gate_qr') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'tab' => $tab]);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $rowsHtml = $isGate 
        ? renderGateTableRows($gateVisibleVisitors, $gFilter, $gSearch, $gRegion, $gPage, $gateOffset)
        : renderGiftTableRows($giftVisibleVisitors, $dFilter, $dSearch, $dRegion, $dPage, $giftOffset, $dCounter, $isSingleRegionCounter);

    echo json_encode([
        'success'      => true,
        'tab'          => $tab,
        'count'        => count($isGate ? $gateVisitors : $giftVisitors),
        'visibleCount' => count($isGate ? $gateVisibleVisitors : $giftVisibleVisitors),
        'startOffset'  => $isGate ? $gateOffset : $giftOffset,
        'rowsHtml'     => $rowsHtml,
        'regionCounts' => $isGate ? $gateRegionCounts : $giftRegionCounts,
        'metrics'      => [
            'totalVisitors'     => $totalVisitors,
            'd1Count'           => $d1Count,
            'd1Rate'            => $d1Rate,
            'd2Count'           => $d2Count,
            'd2Rate'            => $d2Rate,
            'pendingBothCount'  => $pendingBothCount,
            'totalGiftElig'     => $totalGiftElig,
            'giftsGiven'        => $giftsGiven,
            'giftRate'          => $giftRate,
            'giftsPending'      => $giftsPending,
            'giftsEntered'      => $giftsEntered,
            'giftsNotEntered'   => $giftsNotEntered,
        ]
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#0f1c30">
    <title><?= $tab === 'counters' ? 'Regional Counters' : ($tab === 'gate_qr' ? 'Gate QR Poster & Event Settings' : ($isGate ? 'Gate Entry Live Tracking' : 'COE Kit Distribution Control')) ?> | EventMS Admin</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2310b981'%3E%3Cpath d='M12 2L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-3zm-2 15l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z'/%3E%3C/svg%3E">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="assets/js/qrcode.min.js"></script>

    <style>
        /* ========================================================
           RESET & ROOT
           ======================================================== */
        *, *::before, *::after { box-sizing: border-box; }

        :root {
            --navy:           #0f1c30;
            --navy-light:     #162844;
            --navy-border:    rgba(255,255,255,0.08);
            --blue:           #1d58d8;
            --blue-hover:     #1545b0;
            --blue-glow:      rgba(29,88,216,0.35);
            --cyan:           #38bdf8;
            --page-bg:        #f1f5f9;
            --card-bg:        #ffffff;
            --border-light:   #e2e8f0;
            --text-dark:      #0f172a;
            --text-body:      #1e293b;
            --text-muted:     #64748b;
            --text-sidebar:   #94a3b8;
            --success:        #10b981;
            --warning:        #f59e0b;
            --danger:         #ef4444;
            --purple:         #8b5cf6;
            --sidebar-w:      260px;
            --topbar-h:       64px;
            --radius-card:    14px;
            --radius-pill:    9999px;
            --shadow-card:    0 1px 3px rgba(15,28,48,0.06), 0 4px 16px rgba(15,28,48,0.06);
            --shadow-sidebar: 4px 0 24px rgba(0,0,0,0.18);
            --transition:     all 0.2s ease;
            --sat:            env(safe-area-inset-top, 0px);
            --sab:            env(safe-area-inset-bottom, 0px);
            --sal:            env(safe-area-inset-left, 0px);
            --sar:            env(safe-area-inset-right, 0px);
        }

        html, body {
            min-height: 100%;
            height: auto;
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--page-bg);
            color: var(--text-body);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
            width: 100%;
            max-width: 100vw;
        }

        /* ========================================================
           APP SHELL: Sidebar + Main
           ======================================================== */
        .app-shell {
            display: flex;
            min-height: 100vh;
            min-height: 100dvh;
            width: 100%;
            overflow-x: hidden;
        }

        /* ========================================================
           SIDEBAR
           ======================================================== */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            height: 100dvh;
            max-height: 100dvh;
            background: var(--navy);
            display: flex;
            flex-direction: column;
            z-index: 200;
            box-shadow: var(--shadow-sidebar);
            transition: transform 0.28s cubic-bezier(0.4,0,0.2,1);
            overflow: hidden;
            padding-top: max(0px, env(safe-area-inset-top, 0px));
        }

        /* Brand Block */
        .sidebar-brand {
            flex-shrink: 0;
            padding: 1.25rem 1.25rem 1rem;
            border-bottom: 1px solid var(--navy-border);
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: var(--topbar-h);
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: rgba(56,189,248,0.15);
            border: 1.5px solid rgba(56,189,248,0.35);
            color: var(--cyan);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 0 12px rgba(56,189,248,0.2);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }

        .brand-text strong {
            font-size: 0.95rem;
            font-weight: 800;
            color: #ffffff;
            letter-spacing: -0.3px;
        }

        .brand-text span {
            font-size: 0.72rem;
            color: var(--text-sidebar);
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
        }

        .live-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 6px var(--success);
            animation: livePulse 2s infinite;
            flex-shrink: 0;
        }

        @keyframes livePulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.3); }
        }

        /* Scrollable navigation area in sidebar */
        .sidebar-nav-scroll {
            flex: 1 1 auto;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 1rem;
        }
        .sidebar-nav-scroll::-webkit-scrollbar { width: 4px; }
        .sidebar-nav-scroll::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.15); border-radius: 4px; }

        /* Nav Sections */
        .sidebar-section {
            padding: 1.1rem 0.85rem 0.5rem;
        }

        .sidebar-section-label {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #475569;
            padding: 0 0.5rem;
            margin-bottom: 0.4rem;
        }

        /* Nav Item */
        .nav-item-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.65rem 0.75rem;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-sidebar);
            font-size: 0.875rem;
            font-weight: 600;
            transition: var(--transition);
            position: relative;
            margin-bottom: 2px;
        }

        .nav-item-link:hover {
            background: rgba(255,255,255,0.07);
            color: #ffffff;
        }

        .nav-item-link.active {
            background: rgba(29,88,216,0.22);
            color: #ffffff;
            box-shadow: inset 3px 0 0 var(--blue);
        }

        .nav-item-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            bottom: 8px;
            width: 3px;
            background: var(--blue);
            border-radius: 0 3px 3px 0;
        }

        .nav-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            background: rgba(255,255,255,0.06);
            transition: var(--transition);
        }

        .nav-item-link.active .nav-icon {
            background: rgba(29,88,216,0.35);
            color: var(--cyan);
        }

        .nav-item-link:hover .nav-icon {
            background: rgba(255,255,255,0.1);
        }

        .nav-label { flex: 1; }

        .nav-badge {
            font-size: 0.65rem;
            font-weight: 800;
            padding: 0.15rem 0.5rem;
            border-radius: var(--radius-pill);
            background: rgba(255,255,255,0.1);
            color: #cbd5e1;
            letter-spacing: 0.3px;
        }

        .nav-item-link.active .nav-badge {
            background: rgba(29,88,216,0.4);
            color: var(--cyan);
        }

        /* Sidebar Divider */
        .sidebar-divider {
            border: none;
            border-top: 1px solid var(--navy-border);
            margin: 0.5rem 0.85rem;
        }

        /* Sidebar Bottom: Admin Profile + Logout pinned */
        .sidebar-bottom {
            flex-shrink: 0;
            margin-top: auto;
            padding: 0.85rem 0.85rem calc(16px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid var(--navy-border);
            background: var(--navy);
        }

        .admin-profile-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 0.75rem;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 0.65rem;
        }

        .admin-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--blue), #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: #ffffff;
            font-weight: 700;
            flex-shrink: 0;
        }

        .admin-info strong {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #ffffff;
        }

        .role-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.15rem 0.55rem;
            border-radius: var(--radius-pill);
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 0.3px;
            line-height: 1.2;
        }

        .role-pill-super {
            background: rgba(139, 92, 246, 0.25);
            color: #c4b5fd;
            border: 1px solid rgba(139, 92, 246, 0.4);
        }

        .role-pill-guard {
            background: rgba(16, 185, 129, 0.22);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.35);
        }

        .btn-logout-sidebar {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0.55rem 1rem;
            border-radius: 10px;
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.25);
            color: #fca5a5;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
        }

        .btn-logout-sidebar:hover {
            background: rgba(239,68,68,0.25);
            border-color: rgba(239,68,68,0.5);
            color: #ffffff;
        }

        /* ========================================================
           MAIN PANEL (Right)
           ======================================================== */
        .main-panel {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: 100dvh;
            min-width: 0;
            width: calc(100% - var(--sidebar-w));
            max-width: 100%;
        }

        /* ========================================================
           TOP NAVBAR
           ======================================================== */
        .topbar {
            min-height: var(--topbar-h);
            height: auto;
            background: var(--card-bg);
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            padding: 0.75rem 1.5rem;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(15,28,48,0.04);
        }

        /* Mobile hamburger */
        .sidebar-toggle {
            display: none;
            width: 36px;
            height: 36px;
            border: 1.5px solid var(--border-light);
            border-radius: 9px;
            background: #ffffff;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-dark);
            font-size: 1.1rem;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .sidebar-toggle:hover { background: var(--page-bg); }

        .topbar-text-group {
            display: flex;
            flex-direction: column;
            line-height: 1.25;
            min-width: 0;
        }

        .topbar-heading-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .topbar-heading-wrap strong {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .topbar-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .topbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-topbar {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0.45rem 0.9rem;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 700;
            text-decoration: none;
            border: 1.5px solid transparent;
            cursor: pointer;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn-topbar-ghost {
            background: #ffffff;
            border-color: var(--border-light);
            color: var(--text-body);
        }

        .btn-topbar-ghost:hover {
            background: var(--page-bg);
            border-color: #94a3b8;
            color: var(--text-dark);
        }

        .btn-topbar-primary {
            background: var(--blue);
            border-color: var(--blue);
            color: #ffffff;
            box-shadow: 0 2px 10px var(--blue-glow);
        }

        .btn-topbar-primary:hover {
            background: var(--blue-hover);
            border-color: var(--blue-hover);
            color: #ffffff;
        }

        .btn-topbar-danger {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .btn-topbar-danger:hover {
            background: #fee2e2;
            border-color: #f87171;
            color: #b91c1c;
        }

        /* ========================================================
           CONTENT BODY
           ======================================================== */
        .content-body {
            padding: 1.5rem;
            flex: 1;
        }

        /* ========================================================
           METRIC CARDS
           ======================================================== */
        .metrics-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .metric-card {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-card);
            padding: 1.1rem 1.25rem;
            box-shadow: var(--shadow-card);
            display: flex;
            flex-direction: column;
            gap: 4px;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
        }

        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(15,28,48,0.1);
        }

        .metric-card::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
        }

        .mc-blue::after   { background: var(--blue); }
        .mc-green::after  { background: var(--success); }
        .mc-teal::after   { background: #0891b2; }
        .mc-purple::after { background: var(--purple); }
        .mc-amber::after  { background: var(--warning); }

        .metric-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .metric-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        .mi-blue   { background: rgba(29,88,216,0.1);  color: var(--blue); }
        .mi-green  { background: rgba(16,185,129,0.1); color: var(--success); }
        .mi-teal   { background: rgba(8,145,178,0.12); color: #0891b2; }
        .mi-purple { background: rgba(139,92,246,0.1); color: var(--purple); }
        .mi-amber  { background: rgba(245,158,11,0.1); color: var(--warning); }

        .metric-value {
            font-size: 1.9rem;
            font-weight: 800;
            color: var(--text-dark);
            line-height: 1;
            margin-top: 2px;
        }

        .metric-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .rate-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.1rem 0.45rem;
            border-radius: var(--radius-pill);
            font-size: 0.65rem;
            font-weight: 800;
        }

        .rate-green  { background: #dcfce7; color: #166534; }
        .rate-teal   { background: #cffafe; color: #0e7490; }
        .rate-purple { background: #ede9fe; color: #5b21b6; }

        .mini-bar {
            height: 3px;
            background: var(--border-light);
            border-radius: var(--radius-pill);
            overflow: hidden;
            margin-top: 6px;
        }

        .mini-bar-fill {
            height: 100%;
            border-radius: var(--radius-pill);
            transition: width 0.6s ease;
        }

        /* ========================================================
           CONTENT CARD (Table Container)
           ======================================================== */
        .content-card {
            background: var(--card-bg);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            overflow: visible;
        }

        .content-card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            background: #fafbfc;
        }

        .content-card-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-dark);
        }

        .card-icon-dot {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }

        /* ========================================================
           FILTER BAR & TOOLBAR
           ======================================================== */
        .filter-bar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            background: #ffffff;
        }

        .filter-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            width: 100%;
        }

        .filter-search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            width: 100%;
            max-width: 100%;
        }

        .search-field-wrap {
            position: relative;
            flex: 1 1 320px;
            min-width: 220px;
        }

        .search-field-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.95rem;
            pointer-events: none;
        }

        .search-field {
            width: 100%;
            height: 44px;
            border: 1.5px solid var(--border-light);
            border-radius: 10px;
            padding: 0 0.85rem 0 2.4rem;
            font-size: 14px;
            color: var(--text-dark);
            background: var(--card-bg);
            outline: none;
            transition: var(--transition);
            box-sizing: border-box;
        }

        .search-field:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(29,88,216,0.12);
        }

        .counter-select-wrap {
            flex: 0 1 220px;
            min-width: 180px;
            max-width: 240px;
        }

        .region-select-wrap {
            flex: 0 1 200px;
            min-width: 160px;
            max-width: 220px;
        }

        .region-select {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            border-radius: 10px;
            border: 1.5px solid var(--border-light);
            height: 44px;
            width: 100%;
            box-sizing: border-box;
            background-color: var(--card-bg);
            padding: 0 0.75rem;
            text-overflow: ellipsis;
        }

        .region-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(29,88,216,0.12);
        }

        .filter-submit {
            height: 44px;
            padding: 0 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            background: var(--blue);
            color: #ffffff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: var(--transition);
            white-space: nowrap;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .filter-submit:hover { background: var(--blue-hover); }

        .filter-clear {
            height: 44px;
            padding: 0 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            border: 1.5px solid var(--border-light);
            background: #ffffff;
            color: var(--text-muted);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: var(--transition);
            white-space: nowrap;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .filter-clear:hover { background: var(--page-bg); color: var(--text-dark); }

        .filter-btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            width: 100%;
        }

        .filter-btn {
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 16px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            border: 1.5px solid var(--border-light);
            background: #ffffff;
            color: var(--text-muted);
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            white-space: nowrap;
            flex-shrink: 0;
            box-sizing: border-box;
        }

        .filter-btn:hover {
            background: var(--page-bg);
            color: var(--text-dark);
        }

        .filter-btn.active-all         { background: var(--navy); border-color: var(--navy); color: #ffffff; }
        .filter-btn.active-green       { background: var(--success); border-color: var(--success); color: #ffffff; }
        .filter-btn.active-teal        { background: #0891b2; border-color: #0891b2; color: #ffffff; }
        .filter-btn.active-amber       { background: var(--warning); border-color: var(--warning); color: #ffffff; }
        .filter-btn.active-entered     { background: #2563eb; border-color: #2563eb; color: #ffffff; }
        .filter-btn.active-not-entered { background: #64748b; border-color: #64748b; color: #ffffff; }

        /* Gate Entry & COE Kit Tab Export CSV Button */
        .btn-gate-tab-export,
        .btn-kit-tab-export {
            height: 40px !important;
            padding: 0 16px !important;
            border-radius: 10px !important;
            font-size: 13px !important;
            font-weight: 700 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            margin-left: auto !important;
            text-decoration: none !important;
            box-sizing: border-box !important;
            white-space: nowrap !important;
            max-width: 380px !important;
            flex-shrink: 0 !important;
            border: 1.5px solid var(--border-light) !important;
            background: #ffffff !important;
            color: var(--text-body) !important;
            transition: var(--transition) !important;
            cursor: pointer !important;
        }

        .btn-gate-tab-export:hover,
        .btn-kit-tab-export:hover {
            background: var(--page-bg) !important;
            border-color: #94a3b8 !important;
            color: var(--text-dark) !important;
        }

        .btn-gate-tab-export span,
        .btn-kit-tab-export span {
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            display: inline-block !important;
            max-width: 100% !important;
        }

        .btn-gate-tab-export.disabled,
        .btn-gate-tab-export:disabled,
        .btn-kit-tab-export.disabled,
        .btn-kit-tab-export:disabled {
            opacity: 0.5 !important;
            pointer-events: none !important;
            cursor: not-allowed !important;
            background: #f8fafc !important;
            border-color: var(--border-light) !important;
            color: var(--text-muted) !important;
        }

        /* ========================================================
           CRISP TABLE
           ======================================================== */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .crisp-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.875rem;
        }

        .crisp-table.table-gift {
            min-width: 1286px;
            table-layout: fixed !important;
        }

        .crisp-table.table-gift.table-single-region,
        .crisp-table.table-gift.table-counter-view.table-single-region {
            min-width: 1196px;
        }

        .crisp-table.table-gift.table-combined-region,
        .crisp-table.table-gift.table-counter-view.table-combined-region {
            min-width: 1286px;
        }

        .crisp-table.table-gift th,
        .crisp-table.table-gift td {
            padding: 10px 12px !important;
        }

        .crisp-table.table-gate {
            min-width: 1624px;
        }

        .crisp-table tbody td.td-fincert,
        .crisp-table thead th.col-fincert {
            padding-left: 12px !important;
            padding-right: 12px !important;
        }

        .crisp-table thead th {
            background: #f8fafc;
            border-bottom: 2px solid var(--border-light);
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 14px 14px;
            vertical-align: middle;
            white-space: nowrap;
            box-sizing: border-box;
        }

        .crisp-table tbody td {
            padding: 14px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-body);
            vertical-align: middle;
            box-sizing: border-box;
        }

        .crisp-table tbody tr {
            min-height: 76px;
            height: 76px;
        }

        .crisp-table tbody tr:last-child td { border-bottom: none; }

        .crisp-table tbody tr:hover td { background: #fafbfc; }

        /* Status labels hidden on desktop */
        .card-status-label,
        .card-meta-label {
            display: none;
        }

        .user-id-badge {
            display: inline-flex !important;
            align-items: center !important;
            height: 32px !important;
            box-sizing: border-box !important;
            font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            color: #1e293b !important;
            background: #f1f5f9 !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 8px !important;
            padding: 0 10px !important;
            letter-spacing: 0.2px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: 100% !important;
            width: max-content !important;
            vertical-align: middle !important;
        }

        .region-badge {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            height: 32px !important;
            box-sizing: border-box !important;
            font-size: 0.78rem !important;
            font-weight: 600 !important;
            color: #0369a1 !important;
            background: #f0f9ff !important;
            border: 1px solid #bae6fd !important;
            border-radius: 8px !important;
            padding: 0 10px !important;
            letter-spacing: 0.2px !important;
            white-space: nowrap !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            max-width: 100% !important;
            width: max-content !important;
            vertical-align: middle !important;
        }

        .region-counter-stack {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
            width: 100%;
        }

        .counter-name-wrap {
            display: flex;
            align-items: flex-start;
            gap: 5px;
            max-width: 100%;
            width: 100%;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.3;
            color: #475569;
        }

        .counter-name-wrap i {
            font-size: 0.82rem;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .counter-name-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
            word-break: normal;
            overflow-wrap: anywhere;
        }

        .pagination-bar {
            background: #ffffff;
        }

        .guest-name {
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.3;
        }

        .guest-type {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 0.74rem;
            color: var(--text-muted);
            line-height: 1.3;
            max-height: 2.6em;
            word-break: break-word;
            margin-top: 2px;
        }

        .id-badge {
            color: var(--text-muted);
            font-weight: 700;
            font-size: 0.8rem;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 6px;
            display: inline-block;
        }

        @keyframes rowSoftHighlight {
            0% {
                background-color: rgba(34, 197, 94, 0.28) !important;
            }
            100% {
                background-color: transparent !important;
            }
        }

        tr.row-highlight-new {
            animation: rowSoftHighlight 2.5s ease-out forwards !important;
        }

        tr.row-highlight-new td {
            animation: rowSoftHighlight 2.5s ease-out forwards !important;
        }

        .status-card-inner {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .status-timestamp {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-top: 2px;
            white-space: nowrap;
        }

        .gift-status-wrap, .gift-time-wrap {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .gift-timestamp {
            color: var(--text-dark);
            font-weight: 600;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ========================================================
           BADGES & STATUS PILLS
           ======================================================== */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 0.3rem 0.75rem;
            border-radius: var(--radius-pill);
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .pill-green {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .pill-teal {
            background: #cffafe;
            color: #0e7490;
            border: 1px solid #a5f3fc;
        }

        .pill-amber {
            background: #fefce8;
            color: #854d0e;
            border: 1px solid #fde68a;
        }

        .pill-not-entered {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .pill-grey {
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #cbd5e1;
        }

        .pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 5px var(--success);
            animation: livePulse 1.8s infinite;
        }

        .pulse-dot-teal {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #0891b2;
            box-shadow: 0 0 5px #0891b2;
            animation: livePulse 1.8s infinite;
        }

        .mobile-badge-link {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 8px !important;
            height: 32px !important;
            box-sizing: border-box !important;
            width: max-content !important;
            max-width: 100% !important;
            white-space: nowrap !important;
            padding: 0 12px !important;
            background: #f8fafc !important;
            border: 1px solid var(--border-light) !important;
            border-radius: 8px !important;
            color: var(--text-dark) !important;
            text-decoration: none !important;
            font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
            font-size: 0.82rem !important;
            font-weight: 600 !important;
            font-variant-numeric: tabular-nums !important;
            letter-spacing: 0.2px !important;
            vertical-align: middle !important;
            transition: var(--transition);
        }

        .mobile-badge-link i {
            font-size: 0.75rem;
            color: var(--blue);
            flex-shrink: 0;
        }

        .mobile-badge-link:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--blue) !important;
        }

        .mobile-num {
            white-space: nowrap !important;
            font-variant-numeric: tabular-nums !important;
        }

        /* ========================================================
           ACTION BUTTONS (Themes & Colors)
           ======================================================== */
        .btn-checkin-d1 {
            border: 1.5px solid #22c55e !important;
            background: transparent !important;
            color: #16a34a !important;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            touch-action: manipulation;
        }
        .btn-checkin-d1:hover {
            background: #16a34a !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(22, 163, 74, 0.35);
        }

        .btn-day2-action {
            border: 1.5px solid #0891b2 !important;
            background: #0891b2 !important;
            color: #ffffff !important;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            box-shadow: 0 1px 4px rgba(8, 145, 178, 0.25);
            touch-action: manipulation;
        }
        .btn-day2-action:hover {
            background: #0e7490 !important;
            border-color: #0e7490 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(14, 116, 144, 0.4);
        }

        .btn-reset-row {
            border: 1.5px solid #fde68a !important;
            background: #fefce8 !important;
            color: #92400e !important;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            touch-action: manipulation;
        }
        .btn-reset-row:hover {
            background: #fef3c7 !important;
            border-color: var(--warning) !important;
            color: #78350f !important;
        }

        .btn-mark-given {
            border: 1.5px solid #86efac !important;
            background: #ecfdf5 !important;
            color: #065f46 !important;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(16,185,129,0.12);
            touch-action: manipulation;
        }
        .btn-mark-given:hover {
            background: #10b981 !important;
            border-color: #10b981 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(16,185,129,0.35);
        }

        .badge-claimed {
            background: #dcfce7 !important;
            color: #15803d !important;
            border: 1px solid #bbf7d0 !important;
        }

        .btn-not-entered {
            border: 1.5px solid #cbd5e1 !important;
            background: #f1f5f9 !important;
            color: #64748b !important;
            cursor: not-allowed !important;
            opacity: 0.85;
            user-select: none;
            touch-action: manipulation;
        }

        .btn-reset-gift {
            border: 1.5px solid #fed7aa !important;
            background: #fff7ed !important;
            color: #c2410c !important;
            text-decoration: none;
            transition: var(--transition);
            cursor: pointer;
            touch-action: manipulation;
        }
        .btn-reset-gift:hover {
            background: #ea580c !important;
            border-color: #ea580c !important;
            color: #ffffff !important;
            box-shadow: 0 2px 8px rgba(234,88,12,0.3);
        }

        .btn-move-counter {
            background: #f1f5f9 !important;
            border: 1.5px solid #cbd5e1 !important;
            color: #334155 !important;
            cursor: pointer;
            touch-action: manipulation;
            transition: var(--transition);
        }
        .btn-move-counter:hover {
            background: #e2e8f0 !important;
            border-color: #94a3b8 !important;
            color: #0f172a !important;
        }

        /* ========================================================
           DESKTOP TABLE LAYOUT, COLUMNS & BUTTONS (>= 769px)
           ======================================================== */
        @media (min-width: 769px) {
            .crisp-table th,
            .crisp-table td {
                padding: 14px 14px !important;
                vertical-align: middle !important;
                box-sizing: border-box !important;
            }

            .crisp-table.table-gift th,
            .crisp-table.table-gift td {
                padding: 10px 12px !important;
            }

            .crisp-table tbody tr {
                height: 76px !important;
                min-height: 76px !important;
            }

            .col-id {
                width: 64px !important;
                min-width: 64px !important;
                max-width: 64px !important;
                text-align: center !important;
            }

            .col-name {
                width: 260px !important;
                min-width: 260px !important;
                max-width: 260px !important;
                text-align: left !important;
            }

            .table-gate .col-name {
                width: 220px !important;
                min-width: 220px !important;
                max-width: 220px !important;
                text-align: left !important;
            }

            .col-userid {
                width: 170px !important;
                min-width: 170px !important;
                max-width: 170px !important;
                text-align: left !important;
                overflow: hidden !important;
            }

            .col-fincert {
                width: 190px !important;
                min-width: 190px !important;
                max-width: 190px !important;
                text-align: left !important;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            .table-gate .col-region {
                width: 200px !important;
                min-width: 200px !important;
                max-width: 200px !important;
                text-align: left !important;
            }

            .col-region {
                width: 270px !important;
                min-width: 270px !important;
                max-width: 270px !important;
                text-align: left !important;
            }

            .col-mobile {
                width: 190px !important;
                min-width: 190px !important;
                max-width: 190px !important;
                text-align: left !important;
                overflow: visible !important;
            }

            .col-status-d1,
            .col-status-d2 {
                width: 170px !important;
                min-width: 170px !important;
                max-width: 170px !important;
                text-align: center !important;
            }

            .col-actions {
                width: 250px !important;
                min-width: 250px !important;
                max-width: 250px !important;
                text-align: right !important;
                padding-right: 16px !important;
            }

            /* COE Kit Distribution Table Column Widths & Alignments (Super Admin & Counter Views) */
            .crisp-table.table-gift {
                min-width: 1286px !important;
            }

            .crisp-table.table-gift.table-single-region,
            .crisp-table.table-gift.table-counter-view.table-single-region {
                min-width: 1196px !important;
            }

            .crisp-table.table-gift.table-combined-region,
            .crisp-table.table-gift.table-counter-view.table-combined-region {
                min-width: 1286px !important;
            }

            .table-gift .col-id {
                width: 56px !important;
                min-width: 56px !important;
                max-width: 56px !important;
                text-align: center !important;
            }

            .table-gift .col-name,
            .table-gift.table-counter-view .col-name {
                width: auto !important;
                min-width: 240px !important;
                max-width: none !important;
                text-align: left !important;
            }

            .table-gift .col-userid {
                width: 160px !important;
                min-width: 160px !important;
                max-width: 160px !important;
                text-align: left !important;
            }

            .table-gift .col-region,
            .table-gift.table-combined-region .col-region,
            .table-gift.table-counter-view.table-combined-region .col-region {
                width: 200px !important;
                min-width: 200px !important;
                max-width: 200px !important;
                text-align: left !important;
            }

            .table-gift.table-single-region .col-region,
            .table-gift.table-counter-view.table-single-region .col-region {
                width: 110px !important;
                min-width: 110px !important;
                max-width: 110px !important;
                text-align: left !important;
            }

            .table-gift .col-mobile {
                width: 180px !important;
                min-width: 180px !important;
                max-width: 180px !important;
                text-align: left !important;
            }

            .table-gift .col-actions,
            .table-gift.table-counter-view .col-actions {
                width: 130px !important;
                min-width: 130px !important;
                max-width: 130px !important;
                text-align: center !important;
                padding-left: 12px !important;
                padding-right: 12px !important;
            }

            .table-gift th.col-actions,
            .table-gift td.td-actions {
                text-align: center !important;
            }

            .table-gift .td-actions .action-btn-group {
                justify-content: center !important;
                margin: 0 auto !important;
            }

            .table-gift .col-gift-status {
                width: 120px !important;
                min-width: 120px !important;
                max-width: 120px !important;
                text-align: center !important;
            }

            .table-gift th.col-gift-status,
            .table-gift td.td-gift-status {
                text-align: center !important;
            }

            .table-gift .gift-status-wrap {
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                margin: 0 auto !important;
            }

            .table-gift .col-gift-time {
                width: 200px !important;
                min-width: 200px !important;
                max-width: 200px !important;
                text-align: left !important;
            }

            .table-gift th.col-gift-time,
            .table-gift td.td-gift-time {
                text-align: left !important;
                white-space: nowrap !important;
            }

            .table-gift .gift-time-wrap {
                align-items: flex-start !important;
                justify-content: center !important;
                text-align: left !important;
            }

            .table-gift .gift-not-collected,
            .table-gift .gift-timestamp {
                white-space: nowrap !important;
            }

            .table-gift .col-handed,
            .table-gift .col-gift-by {
                width: 170px !important;
                min-width: 170px !important;
                max-width: 170px !important;
                text-align: left !important;
            }

            /* Counters Table Specific Fixed Layout (Problem 2) */
            .table-counters {
                width: 100% !important;
                min-width: 1644px !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
            }
            .table-counters th,
            .table-counters td {
                padding: 12px 14px !important;
                vertical-align: middle !important;
                box-sizing: border-box !important;
            }
            .table-counters tbody tr {
                min-height: 72px !important;
                height: 72px;
            }
            .table-counters .col-c-id {
                width: 64px !important;
                min-width: 64px !important;
                max-width: 64px !important;
                text-align: center !important;
            }
            .table-counters .col-c-name {
                width: 320px !important;
                min-width: 320px !important;
                max-width: 320px !important;
                text-align: left !important;
            }
            .table-counters .counter-name-text {
                font-weight: 700;
                color: var(--text-dark, #0f1c30);
                font-size: 0.92rem;
                line-height: 1.35;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            .table-counters .col-c-regions {
                width: 440px !important;
                min-width: 440px !important;
                max-width: 440px !important;
                text-align: left !important;
                overflow: visible !important;
            }
            .region-chips-wrap {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                align-items: center;
            }
            .region-chip {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                font-size: 0.78rem;
                font-weight: 600;
                padding: 3px 8px;
                border-radius: 6px;
                white-space: nowrap;
            }
            .range-chip {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
                font-size: 0.78rem;
                font-weight: 600;
                padding: 3px 8px;
                border-radius: 6px;
                white-space: normal;
                line-height: 1.35;
                word-break: break-word;
                max-width: 100%;
            }
            .counter-user-box {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                width: 100%;
                max-width: 100%;
            }
            .counter-user-code {
                font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
                font-size: 13px !important;
                font-weight: 600;
                color: var(--navy, #0f1c30);
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                padding: 4px 8px;
                border-radius: 6px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 220px;
                display: inline-block;
            }
            .btn-copy-uname {
                width: 28px;
                height: 28px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
                background: #ffffff;
                color: #64748b;
                font-size: 12px;
                cursor: pointer;
                flex-shrink: 0;
                transition: all 0.15s ease;
            }
            .btn-copy-uname:hover {
                background: #f1f5f9;
                color: var(--blue, #1d4ed8);
                border-color: #94a3b8;
            }
            .table-counters .col-c-visitors {
                width: 90px !important;
                min-width: 90px !important;
                max-width: 90px !important;
                text-align: center !important;
            }
            .table-counters .col-c-distributed {
                width: 120px !important;
                min-width: 120px !important;
                max-width: 120px !important;
                text-align: center !important;
            }
            .table-counters .col-c-pending {
                width: 100px !important;
                min-width: 100px !important;
                max-width: 100px !important;
                text-align: center !important;
            }
            .table-counters .col-c-login {
                width: 170px !important;
                min-width: 170px !important;
                max-width: 170px !important;
                text-align: center !important;
            }
            .table-counters .col-c-status {
                width: 110px !important;
                min-width: 110px !important;
                max-width: 110px !important;
                text-align: center !important;
            }
            .table-counters .col-c-actions {
                width: 230px !important;
                min-width: 230px !important;
                max-width: 230px !important;
                text-align: right !important;
                padding-right: 16px !important;
            }
            .counter-actions-wrap {
                display: flex;
                gap: 8px;
                justify-content: flex-end;
                align-items: center;
                flex-wrap: nowrap;
                width: 100%;
            }
            .btn-counter-action {
                height: 34px !important;
                width: 104px !important;
                min-width: 104px !important;
                max-width: 104px !important;
                padding: 0 12px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                white-space: nowrap !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 6px !important;
                border-radius: 999px !important;
                box-sizing: border-box !important;
                flex-shrink: 0 !important;
                text-decoration: none !important;
                transition: all 0.15s ease-in-out;
            }

            /* Mobile Counter Cards Styles */
            .counters-mobile-list {
                padding: 12px;
            }
            .counter-mobile-card {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 14px;
                padding: 14px;
                margin-bottom: 12px;
                box-shadow: 0 1px 4px rgba(15, 28, 48, 0.05);
            }
            .cmc-name {
                font-size: 0.95rem;
                color: var(--navy, #0f1c30);
            }
            .cmc-stats-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 8px;
                text-align: center;
            }
            .cmc-stat-label {
                font-size: 0.68rem;
                text-transform: uppercase;
                font-weight: 800;
                color: #64748b;
                letter-spacing: 0.05em;
                margin-bottom: 2px;
            }
            .cmc-stat-val {
                font-size: 0.9rem;
            }

            .crisp-table tbody td.col-mobile {
                overflow: visible !important;
            }

            .crisp-table tbody td.col-userid {
                overflow: hidden !important;
            }

            .action-btn-group {
                display: flex !important;
                gap: 8px !important;
                justify-content: flex-end !important;
                align-items: center !important;
                flex-wrap: nowrap !important;
                width: 100% !important;
            }

            /* Unified 112px x 36px action pill buttons on desktop */
            .action-btn,
            .btn-checkin-d1,
            .btn-day2-action,
            .btn-reset-row,
            .btn-mark-given,
            .btn-reset-gift,
            .btn-not-entered,
            .btn-move-counter,
            .badge-claimed {
                width: 112px !important;
                min-width: 112px !important;
                max-width: 112px !important;
                height: 36px !important;
                min-height: 36px !important;
                max-height: 36px !important;
                padding: 0 14px !important;
                border-radius: 999px !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                white-space: nowrap !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 6px !important;
                flex-shrink: 0 !important;
                box-sizing: border-box !important;
                text-decoration: none !important;
                line-height: 1 !important;
                overflow: hidden !important;
            }

            /* Centered Status Badges on desktop */
            .status-card-inner,
            .gift-status-wrap {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                text-align: center;
                width: 100%;
            }

            .status-card-inner .status-pill {
                width: 140px !important;
                min-width: 140px !important;
                max-width: 140px !important;
                height: 30px !important;
                min-height: 30px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0 auto !important;
                white-space: nowrap !important;
                box-sizing: border-box !important;
                font-size: 0.75rem !important;
                font-weight: 700 !important;
                padding: 0 8px !important;
                border-radius: var(--radius-pill) !important;
            }

            .col-gift-status .status-pill,
            .col-actions .status-pill {
                width: 110px !important;
                min-width: 110px !important;
                max-width: 110px !important;
                height: 30px !important;
                min-height: 30px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin: 0 auto !important;
                white-space: nowrap !important;
                box-sizing: border-box !important;
                font-size: 0.75rem !important;
                font-weight: 700 !important;
                padding: 0 8px !important;
                border-radius: var(--radius-pill) !important;
            }

            .gift-time-wrap {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                justify-content: center;
                text-align: left;
                width: 100%;
                gap: 3px;
            }
        }

        /* ========================================================
           FLASH NOTICE
           ======================================================== */
        .flash-notice {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.7rem 1rem;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
            overflow: hidden;
            max-height: 120px;
            opacity: 1;
            transform: translateY(0);
            transition: opacity 0.2s ease, max-height 0.25s ease, margin 0.25s ease, padding 0.25s ease, transform 0.2s ease;
            box-sizing: border-box;
        }

        .flash-notice.flash-dismissing {
            opacity: 0;
            max-height: 0;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            padding-top: 0 !important;
            padding-bottom: 0 !important;
            border-top-width: 0 !important;
            border-bottom-width: 0 !important;
            transform: translateY(-4px);
            pointer-events: none;
        }

        .flash-close {
            background: transparent;
            border: none;
            color: inherit;
            font-size: 1.35rem;
            line-height: 1;
            padding: 0 4px;
            cursor: pointer;
            opacity: 0.55;
            transition: opacity 0.15s ease, transform 0.15s ease;
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .flash-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        .flash-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .flash-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .flash-danger  { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ========================================================
           SIDEBAR OVERLAY (Mobile)
           ======================================================== */
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 28, 48, 0.6);
            z-index: 199;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            transition: opacity 0.25s ease;
            opacity: 0;
        }

        /* ========================================================
           RESPONSIVE BREAKPOINTS
           ======================================================== */
        @media (max-width: 1024px) {
            .metrics-row { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        }

        @media (max-width: 767.98px) {
            .sidebar {
                transform: translateX(calc(-1 * var(--sidebar-w)));
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar-overlay.open {
                display: block;
                opacity: 1;
            }

            .main-panel {
                margin-left: 0;
                width: 100%;
                max-width: 100vw;
            }

            .sidebar-toggle {
                display: flex;
            }

            /* Mobile 2-Row Sticky Topbar */
            .topbar {
                display: grid;
                grid-template-columns: auto 1fr auto;
                grid-template-areas:
                    "toggle title actions"
                    "sub    sub   sub";
                gap: 6px 10px;
                padding: calc(8px + env(safe-area-inset-top, 0px)) 12px 10px 12px;
                min-height: auto;
                height: auto;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
            }

            .sidebar-toggle {
                grid-area: toggle;
                align-self: center;
            }

            .topbar-text-group {
                display: contents;
            }

            .topbar-heading-wrap {
                grid-area: title;
                align-self: center;
                min-width: 0;
            }

            .topbar-heading-wrap strong {
                font-size: 0.92rem;
            }

            .topbar-actions {
                grid-area: actions;
                align-self: center;
                margin-left: 0;
                display: flex;
                align-items: center;
                gap: 6px;
                flex-wrap: nowrap;
            }

            .topbar-actions .btn-topbar {
                width: 36px;
                height: 36px;
                min-width: 36px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 9px;
            }

            .topbar-actions .btn-topbar span {
                display: none !important;
            }

            .topbar-actions .btn-topbar i {
                font-size: 1.05rem;
                margin: 0;
            }

            .topbar-subtitle {
                grid-area: sub;
                width: 100%;
                margin: 0;
                padding-top: 4px;
                border-top: 1px dashed rgba(226, 232, 240, 0.7);
                line-height: 1.35;
                font-size: 0.72rem;
            }

            /* Content Body & Metrics */
            .content-body {
                padding: 12px 14px;
            }

            .metrics-row {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
                margin-bottom: 1rem !important;
            }

            .metric-card {
                padding: 0.85rem 0.9rem;
            }

            .metric-value {
                font-size: 1.5rem;
            }

            .metric-label {
                font-size: 0.68rem;
            }

            .metric-sub {
                font-size: 0.68rem;
                flex-wrap: wrap;
            }

            /* Mobile Filter Bar */
            .filter-bar {
                padding: 0.75rem 0.85rem;
            }

            .filter-form {
                display: flex;
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }

            .filter-search-row {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                width: 100%;
                max-width: 100%;
            }

            .filter-search-row .search-field-wrap {
                width: 100%;
                flex: 1 1 100%;
                min-width: 0;
            }

            .region-select-wrap {
                width: 100%;
                min-width: 100%;
                max-width: 100%;
            }

            .card-meta-label {
                display: block;
                font-size: 0.65rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--text-muted);
                margin-bottom: 2px;
            }

            .filter-submit {
                padding: 0 0.85rem;
                flex-shrink: 0;
            }

            .filter-clear {
                padding: 0 0.75rem;
                flex-shrink: 0;
            }

            .filter-btn-group {
                display: flex;
                flex-wrap: wrap;
                gap: 6px;
                width: 100%;
                padding: 2px 0 6px 0;
            }

            .filter-btn {
                flex: 0 0 auto;
                padding: 0.38rem 0.75rem;
                font-size: 0.76rem;
                white-space: nowrap;
            }

            .btn-gate-tab-export,
            .btn-kit-tab-export {
                width: 100% !important;
                max-width: 100% !important;
                margin-left: 0 !important;
                margin-top: 6px !important;
                justify-content: center !important;
                height: 40px !important;
                order: 99 !important;
            }

            /* Mobile Card Layout for Tables */
            .table-responsive {
                overflow: visible !important;
                -webkit-overflow-scrolling: auto;
            }

            .crisp-table,
            .crisp-table.table-gift,
            .crisp-table.table-gift.table-single-region,
            .crisp-table.table-gift.table-combined-region,
            .crisp-table.table-gift.table-counter-view,
            .crisp-table.table-gift.table-counter-view.table-single-region,
            .crisp-table.table-gift.table-counter-view.table-combined-region {
                display: block;
                width: 100% !important;
                min-width: 0 !important;
            }

            .crisp-table thead {
                display: none !important;
            }

            .crisp-table tbody {
                display: block;
                width: 100%;
                padding: 10px 12px;
            }

            .crisp-table tbody tr {
                display: grid;
                position: relative;
                background: var(--card-bg);
                border: 1px solid var(--border-light);
                border-radius: 14px;
                padding: 12px 14px;
                margin-bottom: 12px;
                box-shadow: 0 1px 4px rgba(15,28,48,0.05);
                gap: 10px;
            }

            .crisp-table tbody tr:hover td {
                background: transparent;
            }

            /* Gate Entry Grid Area Mapping - Two Equal 50/50 Columns */
            .crisp-table.table-gate tbody tr {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-areas:
                    "name   name"
                    "uid    reg"
                    "fc     fc"
                    "mobile mobile"
                    "stat1  stat2"
                    "act    act";
                gap: 10px;
            }

            /* Gift Distribution Grid Area Mapping - Two Equal 50/50 Columns */
            .crisp-table.table-gift tbody tr {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-areas:
                    "name   name"
                    "uid    reg"
                    "mobile mobile"
                    "status time"
                    "act    act";
                gap: 10px;
            }

            /* Empty State Row */
            .crisp-table tbody tr.tr-empty {
                display: block !important;
                text-align: center;
                padding: 2.5rem 1rem !important;
                background: transparent !important;
                border: none !important;
                box-shadow: none !important;
            }

            .crisp-table tbody tr.tr-empty td {
                display: block !important;
                padding: 0 !important;
                border: none !important;
            }

            /* Cell Placements in Cards */
            .crisp-table tbody td {
                display: block;
                padding: 0 !important;
                border: none !important;
            }

            .td-name {
                grid-area: name;
                min-width: 0;
                padding-right: 48px !important;
            }

            .td-userid {
                grid-area: uid;
                width: 100% !important;
                min-width: 0 !important;
                background: #f8fafc;
                border: 1px solid var(--border-light);
                border-radius: 8px;
                padding: 6px 10px !important;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .td-userid .user-id-badge {
                height: auto !important;
                min-height: 24px !important;
                white-space: normal !important;
                word-break: break-all !important;
                overflow-wrap: anywhere !important;
                text-overflow: clip !important;
                max-width: 100% !important;
                padding: 2px 0 !important;
                background: transparent !important;
                border: none !important;
                font-size: 0.78rem !important;
                display: block !important;
                width: 100% !important;
            }

            .td-fincert {
                grid-area: fc;
                width: 100% !important;
                min-width: 0 !important;
                background: #f8fafc;
                border: 1px solid var(--border-light);
                border-radius: 8px;
                padding: 6px 10px !important;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .td-fincert .fincert-badge {
                height: auto !important;
                min-height: 24px !important;
                white-space: normal !important;
                word-break: break-all !important;
                overflow-wrap: anywhere !important;
                text-overflow: clip !important;
                max-width: 100% !important;
                padding: 2px 0 !important;
                background: transparent !important;
                border: none !important;
                font-size: 0.78rem !important;
                display: block !important;
                width: 100% !important;
            }

            .td-region {
                grid-area: reg;
                width: 100% !important;
                min-width: 0 !important;
                background: #f8fafc;
                border: 1px solid var(--border-light);
                border-radius: 8px;
                padding: 6px 10px !important;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            .td-region .region-counter-stack {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 3px !important;
                width: 100% !important;
            }

            .td-region .region-badge {
                height: auto !important;
                min-height: 20px !important;
                white-space: normal !important;
                word-break: break-word !important;
                text-overflow: clip !important;
                max-width: 100% !important;
                padding: 0 !important;
                background: transparent !important;
                border: none !important;
                font-size: 0.8rem !important;
                font-weight: 700 !important;
                color: #0369a1 !important;
                display: block !important;
                width: 100% !important;
            }

            .td-region .counter-name-wrap {
                display: flex !important;
                align-items: flex-start !important;
                gap: 4px !important;
                font-size: 0.72rem !important;
                font-weight: 600 !important;
                color: #475569 !important;
                width: 100% !important;
                max-width: 100% !important;
                line-height: 1.25 !important;
            }

            .td-region .counter-name-text {
                white-space: normal !important;
                -webkit-line-clamp: unset !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
            }

            .td-id,
            .col-id {
                position: absolute !important;
                top: 12px !important;
                right: 14px !important;
                z-index: 2;
                text-align: right !important;
                padding: 0 !important;
                margin: 0 !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
            }

            .id-badge {
                display: inline-block;
                font-size: 0.72rem;
                font-weight: 700;
                color: var(--text-muted);
                background: #f1f5f9;
                padding: 2px 7px;
                border-radius: 6px;
            }

            .guest-name {
                font-size: 0.95rem;
                font-weight: 700;
                color: var(--text-dark);
                line-height: 1.25;
                word-break: break-word;
            }

            .guest-type {
                font-size: 0.72rem;
                color: var(--text-muted);
                margin-top: 2px;
            }

            .td-mobile {
                grid-area: mobile;
                width: 100%;
            }

            .td-mobile .mobile-badge-link {
                width: 100% !important;
                max-width: 100% !important;
                height: 38px !important;
                justify-content: center !important;
                font-size: 0.85rem !important;
            }

            /* Day 1 & Day 2 Status Boxes - Equal Columns */
            .td-status-d1 {
                grid-area: stat1;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                flex: none !important;
            }

            .td-status-d2 {
                grid-area: stat2;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                flex: none !important;
            }

            .td-gift-status {
                grid-area: status;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                flex: none !important;
            }

            .td-gift-time {
                grid-area: time;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                flex: none !important;
            }

            /* Status Boxes inside Mobile Cards: Equal Height, Padding and Radius */
            .status-card-inner,
            .gift-status-wrap,
            .gift-time-wrap {
                background: #f8fafc;
                border: 1px solid var(--border-light);
                border-radius: 10px;
                padding: 8px 10px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: flex-start;
                text-align: center;
                gap: 4px;
                min-height: 100%;
                height: 100%;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
                flex: none !important;
            }

            .card-status-label {
                display: block !important;
                font-size: 0.68rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.06em;
                color: var(--text-muted);
                text-align: center;
                width: 100%;
                margin-bottom: 2px;
            }

            /* Status Badge: Full-width Centered with White-Space Nowrap */
            .status-card-inner .status-pill,
            .gift-status-wrap .status-pill,
            .action-btn-group .status-pill {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 0.32rem 0.4rem !important;
                font-size: 0.74rem !important;
                font-weight: 700 !important;
                white-space: nowrap !important;
                box-sizing: border-box !important;
                text-align: center !important;
                gap: 4px !important;
                border-radius: var(--radius-pill) !important;
                margin: 0 !important;
            }

            /* Timestamp Wrap Cleanly without Resizing the Box */
            .status-timestamp {
                font-size: 0.68rem;
                color: var(--text-muted);
                margin-top: 2px;
                text-align: center;
                white-space: normal;
                word-break: normal;
                line-height: 1.25;
                width: 100%;
            }

            .gift-timestamp {
                font-size: 0.72rem;
                color: var(--text-dark);
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 4px;
                text-align: center;
                white-space: normal;
                word-break: normal;
                line-height: 1.25;
                width: 100%;
            }

            .td-actions {
                grid-area: act;
                padding-top: 6px !important;
                border-top: 1px dashed var(--border-light) !important;
                width: 100%;
            }

            /* Action Buttons: 2 Equal Columns Matching the Status Boxes Above */
            .action-btn-group {
                display: grid !important;
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                gap: 10px !important;
                width: 100% !important;
            }

            .action-btn-group > :only-child {
                grid-column: 1 / -1 !important;
            }

            .action-btn-group .btn,
            .action-btn-group .action-btn,
            .action-btn-group .btn-checkin-d1,
            .action-btn-group .btn-day2-action,
            .action-btn-group .btn-reset-row,
            .action-btn-group .badge-claimed,
            .action-btn-group .btn-reset-gift,
            .action-btn-group .btn-mark-given,
            .action-btn-group .btn-not-entered,
            .action-btn-group .btn-move-counter {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                flex: none !important;
                height: 40px !important;
                min-height: 40px !important;
                max-height: 40px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 0.82rem !important;
                font-weight: 700 !important;
                padding: 0 10px !important;
                border-radius: 999px !important;
                white-space: nowrap !important;
                box-sizing: border-box !important;
                margin: 0 !important;
            }
        }

        @media (max-width: 480px) {
            .content-body { padding: 10px 12px; }
            .metrics-row { gap: 8px !important; }
            .metric-card { padding: 0.75rem 0.8rem; }
            .metric-value { font-size: 1.35rem; }
            .metric-label { font-size: 0.65rem; }
            .metric-sub { font-size: 0.66rem; }
            .crisp-table tbody { padding: 8px 10px; }
            .crisp-table tbody tr { padding: 10px 12px; margin-bottom: 10px; gap: 10px; }
            .action-btn-group { gap: 10px !important; }
        }

        /* Add Visitor Modal Responsive & Bottom-sheet styling */
        #addVisitorModal .form-control:focus,
        #addVisitorModal .form-select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(29, 88, 216, 0.15);
        }
        #addVisitorModal .form-control,
        #addVisitorModal .form-select {
            font-size: 0.9rem;
            border-color: var(--border-light);
            transition: var(--transition);
        }
        #addVisitorModal .form-control::placeholder {
            font-size: 14px;
            font-weight: 400;
            color: #94a3b8;
        }
        @media (max-width: 767.98px) {
            #addVisitorModal.modal {
                padding: 0 !important;
            }
            #addVisitorModal .modal-dialog {
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
                min-height: 100% !important;
                display: flex !important;
                align-items: flex-end !important;
            }
            #addVisitorModal .modal-content {
                border-radius: 20px 20px 0 0 !important;
                max-height: 92vh !important;
                display: flex !important;
                flex-direction: column !important;
                border: none !important;
                width: 100% !important;
            }
            #addVisitorModal .modal-body {
                max-height: calc(92vh - 130px) !important;
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                padding: 1.25rem 1rem !important;
            }
            #addVisitorModal .modal-footer {
                position: sticky !important;
                bottom: 0 !important;
                background: #f8fafc !important;
                z-index: 10 !important;
                padding: 0.75rem 1rem !important;
                gap: 8px !important;
            }
            #addVisitorModal .modal-footer .btn {
                flex: 1 1 auto;
                text-align: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<!-- Sidebar Overlay (Mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="app-shell">

    <!-- ================================================================
         SIDEBAR
         ================================================================ -->
    <aside class="sidebar" id="sidebar">

        <!-- Brand -->
        <div class="sidebar-brand">
            <div class="brand-icon">
                <i class="bi bi-shield-check"></i>
            </div>
            <div class="brand-text">
                <strong>EventMS Admin</strong>
                <span>
                    <span class="live-dot"></span>
                    Session Active
                </span>
            </div>
        </div>

        <!-- Main Navigation & Public Terminals (Scrollable independently) -->
        <div class="sidebar-nav-scroll">
            <?php if ($isCounter): ?>
                <!-- Counter Role Navigation: COE Kit Only -->
                <div class="sidebar-section">
                    <div class="sidebar-section-label">COE Kit Counter</div>
                    <a href="admin.php?tab=gift" class="nav-item-link active">
                        <div class="nav-icon"><i class="bi bi-box-seam"></i></div>
                        <span class="nav-label">COE Kit Distribution</span>
                        <span class="nav-badge"><?= $giftsGiven ?>/<?= $totalGiftElig ?></span>
                    </a>
                </div>

            <?php elseif ($isSuperAdmin): ?>
                <!-- Super Admin Navigation: Gate, COE Kit & Regional Counters -->
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Live Tracking</div>

                    <a href="admin.php?tab=gate" class="nav-item-link <?= $tab === 'gate' ? 'active' : '' ?>">
                        <div class="nav-icon"><i class="bi bi-door-open-fill"></i></div>
                        <span class="nav-label">Gate Entry</span>
                        <!--<span class="nav-badge sidebar-gate-badge"><?= $checkedInCount ?>/<?= $totalVisitors ?></span>-->
                    </a>

                    <a href="admin.php?tab=gift" class="nav-item-link <?= $tab === 'gift' ? 'active' : '' ?>">
                        <div class="nav-icon"><i class="bi bi-box-seam"></i></div>
                        <span class="nav-label">COE Kit Distribution</span>
                        <!--<span class="nav-badge sidebar-gift-badge"><?= $giftsGiven ?>/<?= $totalGiftElig ?></span>-->
                    </a>

                    <a href="admin.php?tab=counters" class="nav-item-link <?= $tab === 'counters' ? 'active' : '' ?>">
                        <div class="nav-icon"><i class="bi bi-people-fill"></i></div>
                        <span class="nav-label">Regional Counters</span>
                        <span class="nav-badge"><?= $totalCountersCount ?: 20 ?></span>
                    </a>
                </div>

                <hr class="sidebar-divider">

                <div class="sidebar-section">
                    <div class="sidebar-section-label">Public Terminals</div>

                    <a href="admin.php?tab=gate_qr" class="nav-item-link <?= $tab === 'gate_qr' ? 'active' : '' ?>">
                        <div class="nav-icon"><i class="bi bi-qr-code"></i></div>
                        <span class="nav-label">Gate QR</span>
                    </a>
                </div>

            <?php else: ?>
                <!-- Guard Role Navigation: Gate Entry Only -->
                <div class="sidebar-section">
                    <div class="sidebar-section-label">Live Tracking</div>
                    <a href="admin.php?tab=gate" class="nav-item-link active">
                        <div class="nav-icon"><i class="bi bi-door-open-fill"></i></div>
                        <span class="nav-label">Gate Entry</span>
                        <span class="nav-badge sidebar-gate-badge"><?= $checkedInCount ?>/<?= $totalVisitors ?></span>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bottom: User Profile + Logout (Pinned at bottom) -->
        <div class="sidebar-bottom">
            <div class="admin-profile-card">
                <div class="admin-avatar" style="<?= $isSuperAdmin ? 'background: linear-gradient(135deg, var(--blue), #7c3aed);' : ($isCounter ? 'background: linear-gradient(135deg, #2563eb, #0891b2);' : 'background: linear-gradient(135deg, #10b981, #059669);') ?>">
                    <?= strtoupper(substr($adminUsername, 0, 1)) ?>
                </div>
                <div class="admin-info" style="flex:1;min-width:0;">
                    <strong style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $adminUsername ?></strong>
                    <div style="margin-top:2px;">
                        <?php if ($isSuperAdmin): ?>
                            <span class="role-pill role-pill-super">
                                <i class="bi bi-shield-shaded me-1"></i>Super Admin
                            </span>
                        <?php elseif ($isCounter): ?>
                            <span class="role-pill" style="background:rgba(29,88,216,0.18);color:var(--cyan);border:1px solid rgba(29,88,216,0.3);font-size:0.7rem;">
                                <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($counterRegion) ?> Counter
                            </span>
                        <?php else: ?>
                            <span class="role-pill role-pill-guard">
                                <i class="bi bi-shield-check me-1"></i>Gate Guard (Gate 1)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout-sidebar" onclick="return confirm('Sign out of active session?');">
                <i class="bi bi-box-arrow-right"></i>
                Sign Out
            </a>
        </div>

    </aside>

    <!-- ================================================================
         MAIN PANEL
         ================================================================ -->
    <main class="main-panel">

        <!-- TOP NAVBAR -->
        <div class="topbar">

            <!-- Mobile Toggle -->
            <button class="sidebar-toggle d-md-none" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>

            <!-- Dynamic Title -->
            <div class="topbar-text-group">
                <div class="topbar-heading-wrap">
                    <strong>
                        <?php if ($tab === 'counters'): ?>
                            👥 Regional Counter Management
                        <?php elseif ($tab === 'gate_qr'): ?>
                            📱 Gate QR Poster &amp; Event Settings
                        <?php elseif ($isGate): ?>
                            🚪 Gate Entry Live Tracking
                        <?php else: ?>
                            <?= $isCounter ? '📦 COE Kit Distribution' : '📦 COE Kit Distribution Control' ?>
                        <?php endif; ?>
                    </strong>
                    <?php if ($isSuperAdmin): ?>
                        <span class="role-pill role-pill-super d-none d-sm-inline-flex" style="font-size:0.65rem;">
                            <i class="bi bi-shield-shaded me-1"></i>Super Admin
                        </span>
                    <?php elseif ($isCounter): ?>
                        <span class="role-pill d-none d-sm-inline-flex" style="background:rgba(29,88,216,0.18);color:var(--cyan);border:1px solid rgba(29,88,216,0.3);font-size:0.65rem;">
                            <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($counterName ?: ($counterRegion ? $counterRegion . ' Counter' : 'Help Desk')) ?>
                        </span>
                    <?php else: ?>
                        <span class="role-pill role-pill-guard d-none d-sm-inline-flex" style="font-size:0.65rem;">
                            <i class="bi bi-shield-check me-1"></i>Gate Guard (Gate 1)
                        </span>
                    <?php endif; ?>
                </div>
                <small class="topbar-subtitle">
                    <?php if ($tab === 'counters'): ?>
                        <?= $totalCountersCount ?> Regional Counters &bull; <?= $activeCountersCount ?> Active &bull; <?= $totalCountersGiven ?> Total Kits Distributed Across All Booths
                    <?php elseif ($tab === 'gate_qr'): ?>
                        Permanent Gate Self Check-In QR &bull; <?= htmlspecialchars($eventSettings['event_name'] ?? 'COE Event 2026') ?> &bull; <?= $todayStatusText ?> &bull; Switch: <strong class="<?= $selfCheckinIsOpen ? 'text-success' : 'text-danger' ?>"><?= $selfCheckinIsOpen ? 'OPEN' : 'CLOSED' ?></strong>
                    <?php elseif ($isGate): ?>
                        Day 1: <strong id="topbarD1Count"><?= $d1Count ?></strong> (<span id="topbarD1Rate"><?= $d1Rate ?>%</span>) &bull; Day 2: <strong id="topbarD2Count"><?= $d2Count ?></strong> (<span id="topbarD2Rate"><?= $d2Rate ?>%</span>) &bull; <span id="topbarTotalCount"><?= $totalVisitors ?></span> Whitelisted
                    <?php elseif ($isCounter): ?>
                        <?php if (!empty($counterRange)): ?>
                            Names <?= htmlspecialchars($counterRange) ?> &bull; 
                        <?php elseif (count($counterAllowedRegions) > 1): ?>
                            Covering <?= htmlspecialchars(implode(', ', $counterAllowedRegions)) ?> &bull; 
                        <?php endif; ?>
                        <strong id="topbarGiftsGiven"><?= $giftsGiven ?></strong> of <strong id="topbarTotalGiftElig"><?= $totalGiftElig ?></strong> kits distributed &middot; <span id="topbarGiftRate"><?= $giftRate ?>%</span> kit rate
                    <?php else: ?>
                        <strong id="topbarGiftsGiven"><?= $giftsGiven ?></strong> of <strong id="topbarTotalGiftElig"><?= $totalGiftElig ?></strong> kits distributed &middot; <span id="topbarGiftRate"><?= $giftRate ?>%</span> kit rate
                    <?php endif; ?>
                </small>
            </div>

            <!-- Top Action Cluster -->
            <div class="topbar-actions">
                <?php if ($tab === 'counters' && $isSuperAdmin): ?>
                    <button type="button" class="btn-topbar btn-topbar-primary" data-bs-toggle="modal" data-bs-target="#createCounterModal" title="Create a new regional counter">
                        <i class="bi bi-plus-circle"></i><span>Create Counter</span>
                    </button>
                    <a href="counter_credentials.csv" download class="btn-topbar btn-topbar-ghost" title="Download initial credentials CSV">
                        <i class="bi bi-file-earmark-spreadsheet"></i><span>Credentials CSV</span>
                    </a>
                <?php elseif ($tab === 'gate_qr' && $isSuperAdmin): ?>
                    <a href="gate_qr_poster.php" target="_blank" class="btn-topbar btn-topbar-primary" title="Print full A4 Gate QR Poster">
                        <i class="bi bi-printer-fill"></i><span>Print Poster (A4)</span>
                    </a>
                    <a href="gate_checkin.php" target="_blank" class="btn-topbar btn-topbar-ghost" title="Open Public Gate Check-In page">
                        <i class="bi bi-box-arrow-up-right"></i><span>Open Check-In Page</span>
                    </a>
                <?php elseif ($tab === 'gate' && $isSuperAdmin): ?>
                    <button type="button" class="btn-topbar btn-topbar-primary" data-bs-toggle="modal" data-bs-target="#addVisitorModal" title="Add a single visitor">
                        <i class="bi bi-person-plus-fill"></i><span>Add Visitor</span>
                    </button>
                    <a href="admin.php?export=gate_csv&tab=gate" class="btn-topbar btn-topbar-ghost" title="Export 2-day visitor list as CSV">
                        <i class="bi bi-download"></i><span>Export CSV</span>
                    </a>
                    <button type="button" class="btn-topbar btn-topbar-primary" data-bs-toggle="modal" data-bs-target="#importCsvModal" title="Bulk import visitors CSV">
                        <i class="bi bi-cloud-arrow-up"></i><span>Import CSV</span>
                    </button>
                    <!--<a href="admin.php?action=gate_reset_all&tab=gate"-->
                    <!--   class="btn-topbar btn-topbar-danger"-->
                    <!--   onclick="return confirm('Reset ALL <?= $totalVisitors ?> gate entries for Day 1 and Day 2 to Not Checked-in?');"-->
                    <!--   title="Super Admin: Reset all gate entries">-->
                    <!--    <i class="bi bi-arrow-counterclockwise"></i><span>Reset All</span>-->
                    <!--</a>-->
                <?php elseif ($tab === 'gift'): ?>
                    <?php if ($isSuperAdmin): ?>
                        <button type="button" class="btn-topbar btn-topbar-primary" data-bs-toggle="modal" data-bs-target="#addVisitorModal" title="Add a single visitor">
                            <i class="bi bi-person-plus-fill"></i><span>Add Visitor</span>
                        </button>
                    <?php endif; ?>
                    <a href="admin.php?export=gift_csv&tab=gift<?= !empty($dRegion) ? '&dregion=' . urlencode($dRegion) : '' ?>" class="btn-topbar btn-topbar-ghost" title="Export COE Kit distribution list as CSV">
                        <i class="bi bi-download"></i><span>Export CSV</span>
                    </a>
                    <?php if ($isSuperAdmin): ?>
                        <button type="button" class="btn-topbar btn-topbar-primary" data-bs-toggle="modal" data-bs-target="#importCsvModal" title="Bulk import visitors CSV">
                            <i class="bi bi-cloud-arrow-up"></i><span>Import CSV</span>
                        </button>
                        <!--<a href="admin.php?action=gift_reset_all&tab=gift"-->
                        <!--   class="btn-topbar btn-topbar-danger"-->
                        <!--   onclick="return confirm('Reset ALL <?= $totalGiftElig ?> COE Kit records to Pending?');"-->
                        <!--   title="Super Admin: Reset all COE Kits">-->
                        <!--    <i class="bi bi-arrow-counterclockwise"></i><span>Reset All</span>-->
                        <!--</a>-->
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>

        <!-- CONTENT BODY -->
        <div class="content-body">

            <!-- Flash Notice -->
            <?php
            $notices = [
                'gate_checkin_d1'        => ['success', 'bi-check2-circle',          'Visitor Day 1 check-in verified! Marked as <strong>Inside Gate (Day 1)</strong>.'],
                'gate_reset_d1'          => ['warning', 'bi-arrow-counterclockwise', 'Visitor Day 1 entry reset to <strong>Pending</strong>.'],
                'gate_checkin_d2'        => ['success', 'bi-check2-circle',          'Visitor Day 2 check-in verified! Marked as <strong>Inside Gate (Day 2)</strong>.'],
                'gate_reset_d2'          => ['warning', 'bi-arrow-counterclockwise', 'Visitor Day 2 entry reset to <strong>Pending</strong>.'],
                'gate_checkin_one'       => ['success', 'bi-check2-circle',          'Visitor check-in verified! Marked as <strong>Inside Gate</strong>.'],
                'gate_reset_one'         => ['warning', 'bi-arrow-counterclockwise', 'Visitor gate entry reset to <strong>Not Checked-in</strong>.'],
                'gate_reset_all'         => ['danger',  'bi-arrow-repeat',           'All Day 1 &amp; Day 2 gate entries reset to <strong>Not Checked-in</strong>. Gate cleared.'],
                'gift_given_one'         => ['success', 'bi-box-seam',               'COE Kit handover approved! Visitor marked as <strong>Kit Collected</strong>.'],
                'gift_reset_one'         => ['warning', 'bi-arrow-counterclockwise', 'Visitor COE Kit status reset to <strong>Pending / Re-issued</strong>.'],
                'gift_reset_all'         => ['danger',  'bi-arrow-repeat',           'All COE Kit records reset to <strong>Pending</strong>. Distribution counter cleared.'],
                'counter_pwd_reset'      => ['success', 'bi-key-fill',                 'Counter password updated successfully! Please note the new password below.'],
                'counter_status_toggled' => ['success', 'bi-toggle-on',                'Counter account status updated successfully.'],
                'counter_reassigned'     => ['success', 'bi-check2-circle',             'Visitor counter successfully reassigned and logged.'],
                'counter_created'        => ['success', 'bi-person-check-fill',        'New regional counter created successfully! Credentials generated below.'],
                'counter_exists'         => ['danger',  'bi-exclamation-triangle-fill', 'A counter account with that username already exists. Please choose a different region or username.'],
                'invalid_counter_region' => ['danger',  'bi-exclamation-triangle-fill', 'Please enter a valid region name for the new counter.'],
                'imported'               => ['success', 'bi-cloud-check-fill',       'Successfully imported and synchronized <strong>' . (int)($_GET['count'] ?? $_GET['import_success'] ?? 0) . '</strong> visitor(s) across both Gate &amp; COE Kit modules. (' . (int)($_GET['skipped'] ?? 0) . ' skipped)'],
                'import_error'           => ['danger',  'bi-exclamation-octagon-fill', 'Import failed: Database error encountered during synchronization. Transaction was rolled back.'],
                'invalid_file'           => ['danger',  'bi-exclamation-triangle-fill', 'Upload failed: Please upload a valid <strong>.csv</strong> file.'],
                'visitor_added'          => ['success', 'bi-check2-circle',          'Visitor ' . htmlspecialchars($_SESSION['flash_visitor_name'] ?? 'Attendee') . ' added.'],
                'settings_saved'         => ['success', 'bi-check2-circle',          'Event settings updated successfully! Gate self check-in schedule and status saved.'],
                'invalid_csrf'           => ['danger',  'bi-shield-x',               'Security verification failed (invalid CSRF token). Please try again.'],
                'db_error'               => ['danger',  'bi-exclamation-octagon-fill',  'Action failed: A database error occurred. Your changes were not saved.'],
                'permission_denied'      => ['danger',  'bi-shield-x',               'Access Restricted: You do not have permission for this action or region.'],
                'gate_not_entered'       => ['warning', 'bi-door-closed-fill',       'Gate Entry Required: Visitor has not checked in at the gate yet. Please complete gate entry first before giving kit.'],
            ];
            if ($msg && isset($notices[$msg])):
                [$type, $icon, $text] = $notices[$msg];
                $isErrorNotice = in_array($msg, ['import_error', 'invalid_file', 'db_error', 'permission_denied', 'counter_exists', 'invalid_counter_region', 'gate_not_entered'], true);
            ?>
                <div class="flash-notice flash-<?= $type ?>" id="flashNotice" role="alert" data-type="<?= $type ?>" data-is-error="<?= $isErrorNotice ? 'true' : 'false' ?>">
                    <i class="<?= $icon ?> flex-shrink-0 fs-5"></i>
                    <div class="flex-grow-1"><?= $text ?></div>
                    <button type="button" class="flash-close" aria-label="Close" onclick="dismissFlashNotice(this.closest('.flash-notice'))" title="Dismiss notice">&times;</button>
                </div>
            <?php endif; ?>

            <!-- KPI METRICS ROW -->
            <div class="metrics-row">

                <?php if ($tab === 'gate_qr'): ?>
                    <!-- Gate QR & Event Settings View -->
                    <div class="metric-card mc-blue">
                        <div class="metric-label">
                            <span class="metric-icon mi-blue"><i class="bi bi-qr-code-scan"></i></span>
                            Self Check-In Portal
                        </div>
                        <div class="metric-value" style="font-size:1.35rem;color:<?= $selfCheckinIsOpen ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= $selfCheckinIsOpen ? 'Open' : 'Closed' ?>
                        </div>
                        <div class="metric-sub"><?= $selfCheckinIsOpen ? 'Visitors can scan &amp; check in' : 'Entry form is currently closed' ?></div>
                    </div>
                    <div class="metric-card mc-green">
                        <div class="metric-label">
                            <span class="metric-icon mi-green"><i class="bi bi-calendar-event"></i></span>
                            Today in IST
                        </div>
                        <div class="metric-value" style="font-size:1.35rem;color:var(--text-dark);">
                            <?= $isD1Today ? 'Day 1' : ($isD2Today ? 'Day 2' : 'Off-Schedule') ?>
                        </div>
                        <div class="metric-sub"><?= $currentIstDateFormatted ?> (Asia/Kolkata)</div>
                    </div>
                    <div class="metric-card mc-teal">
                        <div class="metric-label">
                            <span class="metric-icon mi-teal"><i class="bi bi-calendar2-range"></i></span>
                            Configured Schedule
                        </div>
                        <div class="metric-value" style="font-size:1.1rem;color:#0891b2;line-height:1.2;padding-top:4px;">
                            D1: <?= htmlspecialchars($eventSettings['day1_date'] ?? '2026-11-18') ?>
                        </div>
                        <div class="metric-sub">D2: <?= htmlspecialchars($eventSettings['day2_date'] ?? '2026-11-19') ?></div>
                    </div>
                    <div class="metric-card mc-amber">
                        <div class="metric-label">
                            <span class="metric-icon mi-amber"><i class="bi bi-people-fill"></i></span>
                            Total Whitelisted
                        </div>
                        <div class="metric-value" style="color:var(--warning);"><?= number_format($totalVisitors) ?></div>
                        <div class="metric-sub">Eligible attendees for entry</div>
                    </div>

                <?php elseif ($tab === 'counters'): ?>
                    <!-- Counters Management View -->
                    <div class="metric-card mc-blue">
                        <div class="metric-label">
                            <span class="metric-icon mi-blue"><i class="bi bi-shop"></i></span>
                            Total Counters
                        </div>
                        <div class="metric-value"><?= $totalCountersCount ?></div>
                        <div class="metric-sub">Configured regional booths</div>
                    </div>
                    <div class="metric-card mc-green">
                        <div class="metric-label">
                            <span class="metric-icon mi-green"><i class="bi bi-check-circle-fill"></i></span>
                            Active Counters
                        </div>
                        <div class="metric-value" style="color:var(--success);"><?= $activeCountersCount ?></div>
                        <div class="metric-sub">Online &amp; active booths</div>
                    </div>
                    <div class="metric-card mc-teal">
                        <div class="metric-label">
                            <span class="metric-icon mi-teal"><i class="bi bi-box-seam"></i></span>
                            Kits Distributed
                        </div>
                        <div class="metric-value" style="color:#0891b2;"><?= $totalCountersGiven ?></div>
                        <div class="metric-sub">Handed over across booths</div>
                    </div>
                    <div class="metric-card mc-amber">
                        <div class="metric-label">
                            <span class="metric-icon mi-amber"><i class="bi bi-clock-history"></i></span>
                            Kits Pending
                        </div>
                        <div class="metric-value" style="color:var(--warning);"><?= $totalCountersPending ?></div>
                        <div class="metric-sub">Awaiting collection</div>
                    </div>

                <?php elseif ($isCounter || $tab === 'gift'): ?>
                    <!-- Counter Staff & Super Admin COE Kit View -->
                    <div class="metric-card mc-blue">
                        <div class="metric-label">
                            <span class="metric-icon mi-blue"><i class="bi bi-people-fill"></i></span>
                            <?= $isCounter ? 'Visitors at this counter' : 'Total Eligible Visitors' ?>
                        </div>
                        <div class="metric-value" id="metricTotalGiftElig"><?= $totalGiftElig ?></div>
                        <div class="metric-sub"><?= $isCounter ? ('Assigned to ' . htmlspecialchars($counterName ?: ($counterRegion ? $counterRegion . ' Counter' : 'Help Desk'))) : 'All regional counters' ?></div>
                    </div>
                    <div class="metric-card mc-green">
                        <div class="metric-label">
                            <span class="metric-icon mi-green"><i class="bi bi-box-seam"></i></span>
                            Kits Distributed
                        </div>
                        <div class="metric-value" id="metricGiftsGiven" style="color:var(--success);"><?= $giftsGiven ?></div>
                        <div class="metric-sub">
                            Handed over
                            <span class="rate-pill rate-green" id="metricGiftRatePill"><?= $giftRate ?>%</span>
                        </div>
                        <div class="mini-bar">
                            <div class="mini-bar-fill" id="metricGiftRateBar" style="width:<?= $giftRate ?>%;background:var(--success);"></div>
                        </div>
                    </div>
                    <div class="metric-card mc-amber">
                        <div class="metric-label">
                            <span class="metric-icon mi-amber"><i class="bi bi-clock"></i></span>
                            Pending Kits
                        </div>
                        <div class="metric-value" id="metricGiftsPending" style="color:var(--warning);"><?= $giftsPending ?></div>
                        <div class="metric-sub">Awaiting handover</div>
                    </div>
                    <div class="metric-card mc-teal">
                        <div class="metric-label">
                            <span class="metric-icon mi-teal"><i class="bi bi-speedometer2"></i></span>
                            Distribution Rate
                        </div>
                        <div class="metric-value" id="metricGiftRate" style="color:#0891b2;"><?= $giftRate ?>%</div>
                        <div class="metric-sub"><?= $isCounter ? 'Counter completion' : 'Overall completion' ?></div>
                        <div class="mini-bar">
                            <div class="mini-bar-fill" id="metricGiftRateBar2" style="width:<?= $giftRate ?>%;background:#0891b2;"></div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Super Admin & Guard Standard Metrics -->
                    <!-- 1. Total Whitelisted Visitors -->
                    <div class="metric-card mc-blue">
                        <div class="metric-label">
                            <span class="metric-icon mi-blue"><i class="bi bi-people-fill"></i></span>
                            Total Visitors
                        </div>
                        <div class="metric-value" id="metricTotalVisitors"><?= $totalVisitors ?></div>
                        <div class="metric-sub">Whitelisted attendees</div>
                    </div>

                    <!-- 2. Day 1 Inside Gate -->
                    <div class="metric-card mc-green">
                        <div class="metric-label">
                            <span class="metric-icon mi-green"><i class="bi bi-calendar-check-fill"></i></span>
                            Day 1 Inside Gate
                        </div>
                        <div class="metric-value" style="color:var(--success);"><?= $d1Count ?></div>
                        <div class="metric-sub">
                            Day 1 checked in
                            <span class="rate-pill rate-green"><?= $d1Rate ?>%</span>
                        </div>
                        <div class="mini-bar">
                            <div class="mini-bar-fill" style="width:<?= $d1Rate ?>%;background:var(--success);"></div>
                        </div>
                    </div>

                    <!-- 3. Day 2 Inside Gate -->
                    <div class="metric-card mc-teal">
                        <div class="metric-label">
                            <span class="metric-icon mi-teal"><i class="bi bi-calendar2-check-fill"></i></span>
                            Day 2 Inside Gate
                        </div>
                        <div class="metric-value" style="color:#0891b2;"><?= $d2Count ?></div>
                        <div class="metric-sub">
                            Day 2 checked in
                            <span class="rate-pill rate-teal"><?= $d2Rate ?>%</span>
                        </div>
                        <div class="mini-bar">
                            <div class="mini-bar-fill" style="width:<?= $d2Rate ?>%;background:#0891b2;"></div>
                        </div>
                    </div>

                    <!-- 4. COE Kit Handover Stats -->
                    <div class="metric-card mc-amber">
                        <div class="metric-label">
                            <span class="metric-icon mi-amber"><i class="bi bi-box-seam"></i></span>
                            COE Kits Distributed
                        </div>
                        <div class="metric-value" style="color:var(--warning);"><?= $giftsGiven ?></div>
                        <div class="metric-sub">
                            <?= $giftsGiven ?> of <?= $totalGiftElig ?> kits
                            <span class="rate-pill" style="background:#fef3c7;color:#92400e;"><?= $giftRate ?>%</span>
                        </div>
                        <div class="mini-bar">
                            <div class="mini-bar-fill" style="width:<?= $giftRate ?>%;background:var(--warning);"></div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
            <!-- /METRICS ROW -->

            <?php if ($tab === 'counters' && $isSuperAdmin): ?>
            <!-- ============================================================
                 REGIONAL COUNTERS MANAGEMENT TABLE (Tab: counters)
                 ============================================================ -->
            <div class="content-card">
                <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="content-card-title">
                        <div class="card-icon-dot mi-blue" style="background:rgba(29,88,216,0.1);color:var(--blue);">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        Regional Counter Accounts &amp; Booth Activity
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#createCounterModal" style="background:var(--blue);border:none;">
                            <i class="bi bi-plus-circle me-1"></i> New Counter
                        </button>
                    </div>
                </div>

                <!-- Desktop & Tablet Table (>= 768px) -->
                <div class="table-responsive d-none d-md-block">
                    <table class="crisp-table table-counters">
                        <thead>
                            <tr>
                                <th class="col-c-id" style="width:64px;text-align:center;">#</th>
                                <th class="col-c-name" style="width:320px;text-align:left;">Counter Name</th>
                                <th class="col-c-regions" style="width:440px;text-align:left;">Regions / Range Covered</th>
                                <th class="col-c-visitors" style="width:90px;text-align:center;">Visitors</th>
                                <th class="col-c-distributed" style="width:120px;text-align:center;">Distributed</th>
                                <th class="col-c-pending" style="width:100px;text-align:center;">Pending</th>
                                <th class="col-c-login" style="width:170px;text-align:center;">Last Login (IST)</th>
                                <th class="col-c-status" style="width:110px;text-align:center;">Status</th>
                                <th class="col-c-actions" style="width:230px;text-align:right;padding-right:16px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($countersList)): ?>
                                <tr class="tr-empty">
                                    <td colspan="9" style="text-align:center;padding:3rem;color:var(--text-muted);">
                                        <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4;"></i>
                                        No counter accounts found. Click "New Counter" to create one.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($countersList as $cIdx => $c): 
                                    $cStatus = $c['status'] ?? ($c['admin_status'] ?? ((int)($c['is_active'] ?? 1) === 1 ? 'active' : 'disabled'));
                                    $isActive = ((int)($c['is_active'] ?? 1) === 1 && $cStatus === 'active');
                                    $tot = (int)($c['total_visitors'] ?? 0);
                                    $giv = (int)($c['kits_distributed'] ?? 0);
                                    $pen = (int)($c['kits_pending'] ?? 0);
                                    $pct = $tot > 0 ? round(($giv / $tot) * 100) : 0;
                                ?>
                                    <tr>
                                        <td class="col-c-id" style="text-align:center;">
                                            <span class="id-badge">#<?= !empty($c['counter_no']) ? (int)$c['counter_no'] : ($cIdx + 1) ?></span>
                                        </td>
                                        <td class="col-c-name">
                                            <strong class="counter-name-text" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></strong>
                                        </td>
                                        <td class="col-c-regions">
                                            <div class="region-chips-wrap">
                                                <?php if (!empty($c['name_range'])): ?>
                                                    <span class="range-chip" title="<?= htmlspecialchars($c['name_range']) ?>">
                                                        <i class="bi bi-sort-alpha-down"></i> <?= htmlspecialchars($c['name_range']) ?>
                                                    </span>
                                                <?php elseif ($c['code'] === 'helpdesk' || $c['name'] === 'Help Desk'): ?>
                                                    <span class="region-chip" style="background:#fef2f2;color:#991b1b;border-color:#fecaca;">
                                                        <i class="bi bi-question-circle"></i> Unassigned / Help Desk
                                                    </span>
                                                <?php elseif (!empty($c['regions_covered'])): 
                                                    $rChips = array_filter(array_map('trim', explode(',', (string)$c['regions_covered'])));
                                                    foreach ($rChips as $rc): ?>
                                                        <span class="region-chip">
                                                            <i class="bi bi-geo-alt-fill text-primary"></i> <?= htmlspecialchars($rc) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="col-c-visitors" style="text-align:center;">
                                            <strong class="text-dark"><?= number_format($tot) ?></strong>
                                        </td>
                                        <td class="col-c-distributed" style="text-align:center;">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill fw-bold">
                                                <?= number_format($giv) ?> (<?= $pct ?>%)
                                            </span>
                                        </td>
                                        <td class="col-c-pending" style="text-align:center;">
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1 rounded-pill fw-bold">
                                                <?= number_format($pen) ?>
                                            </span>
                                        </td>
                                        <td class="col-c-login" style="text-align:center;font-size:0.82rem;color:var(--text-muted);">
                                            <?php if (!empty($c['last_login'])): ?>
                                                <i class="bi bi-clock-history text-success me-1"></i><?= htmlspecialchars(formatIST($c['last_login'], 'd M Y, h:i A')) ?>
                                            <?php else: ?>
                                                <span class="text-muted italic">Never logged in</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-c-status" style="text-align:center;">
                                            <?php if ($isActive): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill fw-bold">
                                                    <i class="bi bi-check-circle-fill me-1"></i> Active
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 rounded-pill fw-bold">
                                                    <i class="bi bi-slash-circle-fill me-1"></i> Disabled
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="col-c-actions" style="text-align:right;padding-right:16px;">
                                            <div class="counter-actions-wrap">
                                                <button type="button" class="btn btn-outline-primary btn-counter-action" 
                                                        onclick="confirmResetCounterPassword(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['username'])) ?>', '<?= htmlspecialchars(addslashes($c['name'])) ?>')"
                                                        title="Generate and reset password">
                                                    <i class="bi bi-key"></i> <span>Reset Pwd</span>
                                                </button>
                                                <a href="admin.php?tab=counters&action=toggle_counter_status&counter_id=<?= (int)$c['id'] ?>" 
                                                   class="btn <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-counter-action"
                                                   onclick="return confirm('Are you sure you want to <?= $isActive ? 'DISABLE' : 'ENABLE' ?> <?= htmlspecialchars(addslashes($c['name'])) ?>?');"
                                                   title="<?= $isActive ? 'Disable booth login' : 'Enable booth login' ?>">
                                                    <i class="bi bi-<?= $isActive ? 'pause-circle' : 'play-circle' ?>"></i> <span><?= $isActive ? 'Disable' : 'Enable' ?></span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-total-row" style="background:#f8fafc;font-weight:700;border-top:2px solid #cbd5e1;">
                                <td class="col-c-id" style="text-align:center;">
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill">Total</span>
                                </td>
                                <td class="col-c-name" style="font-weight:700;color:var(--text-dark);">
                                    <?= $totalCountersCount ?> Regional Counters
                                </td>
                                <td class="col-c-regions" style="color:var(--text-muted);font-size:0.82rem;">
                                    <?= $activeCountersCount ?> active &bull; <?= $totalCountersCount - $activeCountersCount ?> disabled
                                </td>
                                <td class="col-c-visitors" style="text-align:center;font-weight:800;color:var(--text-dark);font-size:0.95rem;">
                                    <?= number_format($totalCountersVisitors) ?>
                                </td>
                                <td class="col-c-distributed" style="text-align:center;font-weight:800;color:var(--success);font-size:0.95rem;">
                                    <?= number_format($totalCountersGiven) ?> (<?= $totalCountersVisitors > 0 ? round(($totalCountersGiven / $totalCountersVisitors) * 100) : 0 ?>%)
                                </td>
                                <td class="col-c-pending" style="text-align:center;font-weight:800;color:#d97706;font-size:0.95rem;">
                                    <?= number_format($totalCountersPending) ?>
                                </td>
                                <td colspan="3" class="col-c-actions" style="text-align:right;padding-right:16px;color:var(--text-muted);font-size:0.82rem;font-weight:600;">
                                    <?php if ($totalCountersVisitors === 1270 || ($totalDbVisitors > 0 && $totalCountersVisitors === $totalDbVisitors)): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 rounded-pill fw-bold">
                                            <i class="bi bi-check-circle-fill me-1"></i> Total <?= number_format($totalCountersVisitors) ?> &bull; Balanced
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1.5 rounded-pill fw-bold">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Total <?= $totalCountersVisitors ?> &bull; Mismatch (<?= $totalDbVisitors ?: 1270 ?> expected)
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <!-- Mobile Cards View (< 768px) -->
                <div class="counters-mobile-list d-block d-md-none">
                    <?php if (empty($countersList)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                            No counter accounts found. Click "New Counter" to create one.
                        </div>
                    <?php else: ?>
                        <?php foreach ($countersList as $cIdx => $c): 
                            $cStatus = $c['status'] ?? ($c['admin_status'] ?? ((int)($c['is_active'] ?? 1) === 1 ? 'active' : 'disabled'));
                            $isActive = ((int)($c['is_active'] ?? 1) === 1 && $cStatus === 'active');
                            $tot = (int)($c['total_visitors'] ?? 0);
                            $giv = (int)($c['kits_distributed'] ?? 0);
                            $pen = (int)($c['kits_pending'] ?? 0);
                            $pct = $tot > 0 ? round(($giv / $tot) * 100) : 0;
                        ?>
                            <div class="counter-mobile-card">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                        <span class="id-badge">#<?= !empty($c['counter_no']) ? (int)$c['counter_no'] : ($cIdx + 1) ?></span>
                                        <strong class="cmc-name"><?= htmlspecialchars($c['name']) ?></strong>
                                    </div>
                                    <div>
                                        <?php if ($isActive): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill fw-bold" style="font-size:0.75rem;">
                                                <i class="bi bi-check-circle-fill me-1"></i> Active
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-0.5 rounded-pill fw-bold" style="font-size:0.75rem;">
                                                <i class="bi bi-slash-circle-fill me-1"></i> Disabled
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <div class="region-chips-wrap">
                                        <?php if (!empty($c['name_range'])): ?>
                                            <span class="range-chip" title="<?= htmlspecialchars($c['name_range']) ?>">
                                                <i class="bi bi-sort-alpha-down"></i> <?= htmlspecialchars($c['name_range']) ?>
                                            </span>
                                        <?php elseif ($c['code'] === 'helpdesk' || $c['name'] === 'Help Desk'): ?>
                                            <span class="region-chip" style="background:#fef2f2;color:#991b1b;border-color:#fecaca;">
                                                <i class="bi bi-question-circle"></i> Unassigned / Help Desk
                                            </span>
                                        <?php elseif (!empty($c['regions_covered'])): 
                                            $rChips = array_filter(array_map('trim', explode(',', (string)$c['regions_covered'])));
                                            foreach ($rChips as $rc): ?>
                                                <span class="region-chip">
                                                    <i class="bi bi-geo-alt-fill text-primary"></i> <?= htmlspecialchars($rc) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="mb-2.5">
                                    <div class="counter-user-box">
                                        <code class="counter-user-code" style="max-width:calc(100% - 36px);">
                                            <?= htmlspecialchars($c['username']) ?>
                                        </code>
                                        <button type="button" class="btn-copy-uname" onclick="copyCounterUsername('<?= htmlspecialchars(addslashes($c['username'])) ?>', this)" title="Copy username">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="cmc-stats-grid mb-2.5">
                                    <div class="cmc-stat-box">
                                        <div class="cmc-stat-label">Visitors</div>
                                        <div class="cmc-stat-val fw-bold text-dark"><?= number_format($tot) ?></div>
                                    </div>
                                    <div class="cmc-stat-box">
                                        <div class="cmc-stat-label">Distributed</div>
                                        <div class="cmc-stat-val fw-bold text-success"><?= number_format($giv) ?> <small class="text-muted" style="font-size:0.75rem;">(<?= $pct ?>%)</small></div>
                                    </div>
                                    <div class="cmc-stat-box">
                                        <div class="cmc-stat-label">Pending</div>
                                        <div class="cmc-stat-val fw-bold text-warning-emphasis"><?= number_format($pen) ?></div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mb-2.5 small text-muted" style="font-size:0.78rem;">
                                    <span><i class="bi bi-clock-history me-1"></i> Last Login:</span>
                                    <span><?= !empty($c['last_login']) ? htmlspecialchars(formatIST($c['last_login'], 'd M Y, h:i A')) : 'Never logged in' ?></span>
                                </div>

                                <div class="d-flex gap-2 w-100">
                                    <button type="button" class="btn btn-outline-primary btn-counter-action flex-fill" 
                                            onclick="confirmResetCounterPassword(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($c['username'])) ?>', '<?= htmlspecialchars(addslashes($c['name'])) ?>')"
                                            style="width:50% !important;min-width:0 !important;max-width:none !important;">
                                        <i class="bi bi-key"></i> <span>Reset Pwd</span>
                                    </button>
                                    <a href="admin.php?tab=counters&action=toggle_counter_status&counter_id=<?= (int)$c['id'] ?>" 
                                       class="btn <?= $isActive ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-counter-action flex-fill"
                                       onclick="return confirm('Are you sure you want to <?= $isActive ? 'DISABLE' : 'ENABLE' ?> <?= htmlspecialchars(addslashes($c['name'])) ?>?');"
                                       style="width:50% !important;min-width:0 !important;max-width:none !important;">
                                        <i class="bi bi-<?= $isActive ? 'pause-circle' : 'play-circle' ?>"></i> <span><?= $isActive ? 'Disable' : 'Enable' ?></span>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Mobile Summary Card -->
                        <div class="counter-mobile-card counter-total-card" style="background:#f8fafc;border:2px solid #cbd5e1;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="text-dark">Total All Booths</strong>
                                <span class="badge bg-primary text-white rounded-pill px-2.5 py-1 fw-bold">Total <?= number_format($totalCountersVisitors) ?></span>
                            </div>
                            <div class="cmc-stats-grid mb-2">
                                <div class="cmc-stat-box">
                                    <div class="cmc-stat-label">Total Visitors</div>
                                    <div class="cmc-stat-val fw-bold text-dark"><?= number_format($totalCountersVisitors) ?></div>
                                </div>
                                <div class="cmc-stat-box">
                                    <div class="cmc-stat-label">Distributed</div>
                                    <div class="cmc-stat-val fw-bold text-success"><?= number_format($totalCountersGiven) ?></div>
                                </div>
                                <div class="cmc-stat-box">
                                    <div class="cmc-stat-label">Pending</div>
                                    <div class="cmc-stat-val fw-bold text-warning-emphasis"><?= number_format($totalCountersPending) ?></div>
                                </div>
                            </div>
                            <div class="small text-center text-success fw-semibold">
                                <i class="bi bi-check-circle-fill me-1"></i> <?= $totalCountersCount ?> Counters (<?= $activeCountersCount ?> Active) &bull; Balanced
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($tab === 'gate_qr' && $isSuperAdmin): ?>
            <!-- ============================================================
                 GATE QR POSTER & EVENT SETTINGS (Tab: gate_qr)
                 ============================================================ -->
            <div class="row g-4 mb-4">
                <!-- LEFT COLUMN: Gate QR Code & Poster -->
                <div class="col-12 col-xl-5">
                    <div class="content-card h-100">
                        <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="content-card-title">
                                <div class="card-icon-dot mi-blue" style="background:rgba(29,88,216,0.1);color:var(--blue);">
                                    <i class="bi bi-qr-code"></i>
                                </div>
                                Permanent Gate QR Code
                            </div>
                            <span class="badge bg-light text-dark border px-2.5 py-1">Day 1 &amp; Day 2</span>
                        </div>
                        <div class="p-4 d-flex flex-column align-items-center text-center">
                            <div class="mb-3 p-3 bg-white rounded-4 border shadow-sm" style="display:inline-block;">
                                <div id="adminGateQrContainer" style="width:220px;height:220px;display:flex;align-items:center;justify-content:center;"></div>
                            </div>

                            <!-- Small Status Line under QR -->
                            <div class="mb-3 px-3 py-2 rounded-pill bg-light border text-muted small fw-medium">
                                <i class="bi bi-info-circle me-1 text-primary"></i>
                                <strong><?= htmlspecialchars($todayStatusText) ?></strong> &bull; Switch is <span class="fw-bold <?= $selfCheckinIsOpen ? 'text-success' : 'text-danger' ?>"><?= $selfCheckinIsOpen ? 'Open' : 'Closed' ?></span>
                            </div>

                            <!-- URL box with Copy button -->
                            <div class="w-100 mb-3" style="max-width:440px;">
                                <label class="form-label small fw-semibold text-muted text-uppercase d-block text-start mb-1" style="font-size:0.75rem;">Check-In Page URL</label>
                                <div class="input-group">
                                    <input type="text" id="gateCheckinUrlInput" class="form-control font-monospace form-control-sm bg-white" readonly value="<?= htmlspecialchars($gateCheckinUrl) ?>">
                                    <button class="btn btn-outline-primary btn-sm px-3" type="button" onclick="copyGateUrl()" id="btnCopyUrl" title="Copy URL">
                                        <i class="bi bi-clipboard me-1"></i>Copy
                                    </button>
                                </div>
                            </div>

                            <!-- Action Buttons: Download PNG, Print Poster -->
                            <div class="d-flex flex-wrap gap-2 justify-content-center w-100" style="max-width:440px;">
                                <button type="button" class="btn btn-outline-secondary btn-sm px-3 rounded-pill fw-semibold" onclick="downloadQrPng()">
                                    <i class="bi bi-download me-1"></i>Download PNG
                                </button>
                                <a href="gate_qr_poster.php" target="_blank" class="btn btn-primary btn-sm px-3 rounded-pill fw-semibold" style="background:var(--blue);border:none;">
                                    <i class="bi bi-printer-fill me-1"></i>Print Poster (A4)
                                </a>
                                <a href="gate_checkin.php" target="_blank" class="btn btn-outline-primary btn-sm px-3 rounded-pill fw-semibold">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>Open Check-In
                                </a>
                            </div>

                            <div class="mt-4 text-start w-100 p-3 rounded-3" style="background:#f8fafc;border:1px dashed #cbd5e1;font-size:0.83rem;">
                                <div class="fw-bold text-dark mb-1"><i class="bi bi-shield-check text-success me-1"></i>How Visitors Check In:</div>
                                <ul class="mb-0 ps-3 text-muted">
                                    <li>Visitors scan this QR code with their mobile phone camera.</li>
                                    <li>They enter their <strong>Mobile Number</strong> or <strong>Register Id</strong>.</li>
                                    <li>System automatically records check-in for the active day (IST).</li>
                                    <li>Day 1 and Day 2 use this exact same QR code.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT COLUMN: Event Settings & Audit Log -->
                <div class="col-12 col-xl-7">
                    <!-- Event Settings Form -->
                    <div class="content-card mb-4">
                        <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="content-card-title">
                                <div class="card-icon-dot mi-teal" style="background:rgba(8,145,178,0.1);color:#0891b2;">
                                    <i class="bi bi-sliders"></i>
                                </div>
                                Event Settings &amp; Schedule
                            </div>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1">Server Date / IST Time</span>
                        </div>
                        <div class="p-4">
                            <form method="POST" action="admin.php?tab=gate_qr&action=save_event_settings">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                                <div class="row g-3 mb-3">
                                    <div class="col-12">
                                        <label class="form-label fw-bold small text-dark">Event Name</label>
                                        <input type="text" name="event_name" class="form-control" required value="<?= htmlspecialchars($eventSettings['event_name'] ?? 'COE Event 2026') ?>" placeholder="e.g. COE Event 2026">
                                        <div class="form-text" style="font-size:0.78rem;">Displayed on the self check-in page and the printable gate poster.</div>
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label fw-bold small text-dark">Day 1 Date (IST)</label>
                                        <input type="date" name="day1_date" class="form-control" required value="<?= htmlspecialchars($eventSettings['day1_date'] ?? '2026-11-18') ?>">
                                        <div class="form-text" style="font-size:0.78rem;">Visitors scanning on this date are recorded as Day 1.</div>
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <label class="form-label fw-bold small text-dark">Day 2 Date (IST)</label>
                                        <input type="date" name="day2_date" class="form-control" required value="<?= htmlspecialchars($eventSettings['day2_date'] ?? '2026-11-19') ?>">
                                        <div class="form-text" style="font-size:0.78rem;">Visitors scanning on this date are recorded as Day 2.</div>
                                    </div>
                                </div>

                                <div class="p-3 rounded-3 mb-3" style="background:#f1f5f9;border:1px solid #e2e8f0;">
                                    <div class="form-check form-switch d-flex align-items-center justify-content-between p-0 mb-0">
                                        <div>
                                            <label class="form-check-label fw-bold text-dark d-block mb-0" for="selfCheckinSwitch" style="cursor:pointer;">
                                                Self Check-In Portal Switch
                                            </label>
                                            <span class="small text-muted" style="font-size:0.8rem;">
                                                When Closed, the QR page shows &ldquo;Entry is closed.&rdquo; and stops accepting visitor submissions.
                                            </span>
                                        </div>
                                        <input class="form-check-input ms-3" type="checkbox" role="switch" id="selfCheckinSwitch" name="self_checkin_open" value="1" <?= $selfCheckinIsOpen ? 'checked' : '' ?> style="width:2.6em;height:1.4em;cursor:pointer;">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-end">
                                    <button type="submit" class="btn btn-primary px-4 fw-bold" style="background:var(--blue);border:none;">
                                        <i class="bi bi-save me-1"></i> Save Event Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Audit Log of Changes in IST -->
                    <div class="content-card">
                        <div class="content-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="content-card-title">
                                <div class="card-icon-dot mi-amber" style="background:rgba(217,119,6,0.1);color:var(--warning);">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                                Settings Audit Log (IST)
                            </div>
                            <span class="badge bg-light text-muted border px-2 py-1 small"><?= count($settingsLogs) ?> record(s)</span>
                        </div>
                        <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                            <table class="table table-hover align-middle mb-0" style="font-size:0.83rem;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="padding:10px 16px;">Timestamp (IST)</th>
                                        <th style="padding:10px 16px;">Changed By</th>
                                        <th style="padding:10px 16px;">Changes / Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($settingsLogs)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">No settings changes recorded yet.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($settingsLogs as $log): ?>
                                            <tr>
                                                <td style="padding:8px 16px;white-space:nowrap;color:var(--text-muted);font-family:monospace;">
                                                    <?= htmlspecialchars($log['changed_at'] ?? '—') ?>
                                                </td>
                                                <td style="padding:8px 16px;font-weight:600;color:var(--text-dark);">
                                                    <i class="bi bi-person-fill text-muted me-1"></i><?= htmlspecialchars($log['changed_by'] ?? 'admin') ?>
                                                </td>
                                                <td style="padding:8px 16px;color:var(--text-dark);">
                                                    <?= htmlspecialchars($log['details'] ?? '—') ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif ($isGate): ?>
            <!-- ============================================================
                 GATE ENTRY TABLE (Tab: gate - 2-Day Event Tracking)
                 ============================================================ -->
            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <div class="card-icon-dot mi-blue" style="background:rgba(29,88,216,0.1);color:var(--blue);">
                            <i class="bi bi-table"></i>
                        </div>
                        2-Day Gate Entry Check-In Log
                    </div>
                    <span id="gateSubtitle" style="font-size:0.8rem;color:var(--text-muted);">
                        <?= count($gateVisitors) ?> visitors &bull; Latest check-in first
                    </span>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="tab" value="gate">
                        <input type="hidden" name="gfilter" value="<?= htmlspecialchars($gFilter) ?>">

                        <div class="filter-search-row">
                            <div class="search-field-wrap">
                                <i class="bi bi-search"></i>
                                <input type="text" name="gsearch" class="search-field" placeholder="Search name, Register Id, fincert, mobile, region, school…" value="<?= htmlspecialchars($gSearch) ?>">
                            </div>

                            <div class="region-select-wrap">
                                <select name="gregion" class="form-select region-select" onchange="this.form.submit()">
                                    <option value="">All Regions (<?= count($allRegions) ?>)</option>
                                    <?php foreach ($allRegions as $reg): 
                                        $cnt = $gateRegionCounts[$reg] ?? 0;
                                    ?>
                                        <option value="<?= htmlspecialchars($reg) ?>" <?= $gRegion === $reg ? 'selected' : '' ?>>
                                             <?= htmlspecialchars($reg) ?><?= $cnt > 0 ? " ({$cnt})" : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="filter-submit">
                                <i class="bi bi-funnel"></i> <span>Filter</span>
                            </button>
                            <?php if ($gSearch !== '' || $gFilter !== 'all' || $gRegion !== ''): ?>
                                <a href="admin.php?tab=gate" class="filter-clear" title="Clear all filters">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="filter-btn-group">
                            <a href="admin.php?tab=gate&gfilter=all<?= !empty($gSearch) ? '&gsearch=' . urlencode($gSearch) : '' ?><?= !empty($gRegion) ? '&gregion=' . urlencode($gRegion) : '' ?>"
                               class="filter-btn <?= $gFilter === 'all' ? 'active-all' : '' ?>">
                                All (<span id="badgeGateAll"><?= $totalVisitors ?></span>)
                            </a>
                            <a href="admin.php?tab=gate&gfilter=day1<?= !empty($gSearch) ? '&gsearch=' . urlencode($gSearch) : '' ?><?= !empty($gRegion) ? '&gregion=' . urlencode($gRegion) : '' ?>"
                               class="filter-btn <?= $gFilter === 'day1' ? 'active-green' : '' ?>">
                                Day 1 Inside (<span id="badgeGateD1"><?= $d1Count ?></span>)
                            </a>
                            <a href="admin.php?tab=gate&gfilter=day2<?= !empty($gSearch) ? '&gsearch=' . urlencode($gSearch) : '' ?><?= !empty($gRegion) ? '&gregion=' . urlencode($gRegion) : '' ?>"
                               class="filter-btn <?= $gFilter === 'day2' ? 'active-teal' : '' ?>">
                                Day 2 Inside (<span id="badgeGateD2"><?= $d2Count ?></span>)
                            </a>
                            <a href="admin.php?tab=gate&gfilter=pending_both<?= !empty($gSearch) ? '&gsearch=' . urlencode($gSearch) : '' ?><?= !empty($gRegion) ? '&gregion=' . urlencode($gRegion) : '' ?>"
                               class="filter-btn <?= $gFilter === 'pending_both' ? 'active-amber' : '' ?>">
                                Pending Both (<span id="badgeGatePending"><?= $pendingBothCount ?></span>)
                            </a>

                            <?php if ($isSuperAdmin): 
                                $tabLabelMap = [
                                    'all'          => 'All',
                                    'day1'         => 'Day 1 Inside',
                                    'day2'         => 'Day 2 Inside',
                                    'pending_both' => 'Pending Both',
                                ];
                                $activeTabName = $tabLabelMap[$gFilter] ?? 'All';
                                $gateExportCount = count($gateVisitors);
                                $labelParts = [$activeTabName];
                                if (!empty($gRegion)) {
                                    $labelParts[] = $gRegion;
                                }
                                $labelParts[] = (string)$gateExportCount;
                                $exportFullTitle = "Export CSV (" . implode(', ', $labelParts) . ")";
                                $exportUrl = "admin.php?export=gate_tab_csv&gfilter=" . urlencode($gFilter)
                                    . (!empty($gRegion) ? '&gregion=' . urlencode($gRegion) : '')
                                    . (!empty($gSearch) ? '&gsearch=' . urlencode($gSearch) : '');
                            ?>
                                <?php if ($gateExportCount > 0): ?>
                                    <a href="<?= $exportUrl ?>"
                                       class="btn-topbar btn-topbar-ghost btn-gate-tab-export"
                                       id="btnGateTabExport"
                                       title="<?= htmlspecialchars($exportFullTitle) ?>"
                                       onclick="showLiveFlashBanner('Exported <?= $gateExportCount ?> visitor<?= $gateExportCount === 1 ? '' : 's' ?>');">
                                        <i class="bi bi-download"></i><span><?= htmlspecialchars($exportFullTitle) ?></span>
                                    </a>
                                <?php else: ?>
                                    <button type="button"
                                            class="btn-topbar btn-topbar-ghost btn-gate-tab-export disabled"
                                            id="btnGateTabExport"
                                            disabled
                                            title="<?= htmlspecialchars($exportFullTitle) ?>">
                                        <i class="bi bi-download"></i><span><?= htmlspecialchars($exportFullTitle) ?></span>
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="table-responsive">
                    <table class="crisp-table table-gate">
                        <thead>
                            <tr>
                                <th class="col-id">#</th>
                                <th class="col-name">Full Name</th>
                                <th class="col-userid">Register Id</th>
                                <th class="col-fincert">FinCert</th>
                                <th class="col-region">Region</th>
                                <th class="col-mobile">Mobile Number</th>
                                <th class="col-status-d1">Day 1 Status</th>
                                <th class="col-status-d2">Day 2 Status</th>
                                <th class="col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="gateTableBody" data-start-offset="<?= $gateOffset ?>">
                            <?= renderGateTableRows($gateVisibleVisitors, $gFilter, $gSearch, $gRegion, $gPage, $gateOffset) ?>
                        </tbody>
                    </table>
                </div>

                <?= renderPagination($gPage, $totalGatePages, $totalGateRows, $perPage, 'gate', [
                    'gfilter' => $gFilter,
                    'gsearch' => $gSearch,
                    'gregion' => $gRegion,
                ]) ?>
            </div>

            <?php else: ?>
            <!-- ============================================================
                 COE KIT DISTRIBUTION TABLE (Tab: gift)
                 ============================================================ -->

            <div class="content-card">
                <div class="content-card-header">
                    <div class="content-card-title">
                        <div class="card-icon-dot" style="background:rgba(139,92,246,0.1);color:var(--purple);width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        COE Kit Distribution Log
                    </div>
                    <span id="giftSubtitle" style="font-size:0.8rem;color:var(--text-muted);">
                        <?= count($giftVisitors) ?> attendees &bull; Latest check-in first
                    </span>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <form method="GET" class="filter-form">
                        <input type="hidden" name="tab" value="gift">
                        <input type="hidden" name="dfilter" value="<?= htmlspecialchars($dFilter) ?>">

                        <div class="filter-search-row">
                            <div class="search-field-wrap">
                                <i class="bi bi-search"></i>
                                <input type="text" name="dsearch" class="search-field" placeholder="Search name, Register Id, mobile..." value="<?= htmlspecialchars($dSearch) ?>">
                            </div>

                            <?php if ($isCounter): ?>
                                <?php if (!empty($counterAllowedRegions) && count($counterAllowedRegions) > 1): ?>
                                    <div class="region-select-wrap">
                                        <select name="dregion" class="form-select region-select" onchange="this.form.submit()">
                                            <option value="">All my regions (<?= count($counterAllowedRegions) ?>)</option>
                                            <?php foreach ($counterAllowedRegions as $cr): ?>
                                                <option value="<?= htmlspecialchars($cr) ?>" <?= $dRegion === $cr ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cr) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="counter-select-wrap">
                                    <select name="dcounter" class="form-select region-select" onchange="this.form.submit()">
                                        <option value="0">All Counters (<?= count($countersList) ?>)</option>
                                        <?php foreach ($countersList as $cOpt): ?>
                                            <option value="<?= (int)$cOpt['id'] ?>" <?= $dCounter === (int)$cOpt['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cOpt['name']) ?> (<?= (int)$cOpt['total_visitors'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="region-select-wrap">
                                    <select name="dregion" class="form-select region-select" onchange="this.form.submit()">
                                        <option value="">All Regions (<?= count($allRegions) ?>)</option>
                                        <?php foreach ($allRegions as $reg): 
                                            $cnt = $giftRegionCounts[$reg] ?? 0;
                                        ?>
                                            <option value="<?= htmlspecialchars($reg) ?>" <?= $dRegion === $reg ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($reg) ?><?= $cnt > 0 ? " ({$cnt})" : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="filter-submit">
                                <i class="bi bi-funnel"></i> <span>Filter</span>
                            </button>
                            <?php if ($dSearch !== '' || $dFilter !== 'entered' || $dCounter > 0 || $dRegion !== ''): ?>
                                <a href="admin.php?tab=gift" class="filter-clear" title="Clear all filters">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php 
                            $counterParam = ($dCounter > 0) ? '&dcounter=' . urlencode((string)$dCounter) : '';
                            $regionParam  = (!empty($dRegion)) ? '&dregion=' . urlencode($dRegion) : '';
                            $searchParam  = (!empty($dSearch)) ? '&dsearch=' . urlencode($dSearch) : '';
                            $baseFilterUrl = "admin.php?tab=gift{$searchParam}{$counterParam}{$regionParam}";
                        ?>
                        <div class="filter-btn-group">
                            <a href="<?= $baseFilterUrl ?>&dfilter=entered"
                               class="filter-btn <?= $dFilter === 'entered' ? 'active-entered' : '' ?>">
                                Entered (<span id="badgeGiftEntered"><?= $giftsEntered ?></span>)
                            </a>
                            <a href="<?= $baseFilterUrl ?>&dfilter=collected"
                               class="filter-btn <?= $dFilter === 'collected' ? 'active-green' : '' ?>">
                                Kit Collected (<span id="badgeGiftGiven"><?= $giftsGiven ?></span>)
                            </a>
                            <a href="<?= $baseFilterUrl ?>&dfilter=pending"
                               class="filter-btn <?= $dFilter === 'pending' ? 'active-amber' : '' ?>">
                                Pending (<span id="badgeGiftPending"><?= $giftsPending ?></span>)
                            </a>
                            <a href="<?= $baseFilterUrl ?>&dfilter=not_entered"
                               class="filter-btn <?= $dFilter === 'not_entered' ? 'active-not-entered' : '' ?>">
                                Not Entered (<span id="badgeGiftNotEntered"><?= $giftsNotEntered ?></span>)
                            </a>
                            <a href="<?= $baseFilterUrl ?>&dfilter=all"
                               class="filter-btn <?= $dFilter === 'all' ? 'active-all' : '' ?>">
                                All (<span id="badgeGiftAll"><?= $totalGiftElig ?></span>)
                            </a>
                            <?php
                                $kitTabLabelMap = [
                                    'entered'     => 'Entered',
                                    'collected'   => 'Kit Collected',
                                    'pending'     => 'Pending',
                                    'not_entered' => 'Not Entered',
                                    'all'         => 'All',
                                ];
                                $activeKitTabName = $kitTabLabelMap[$dFilter] ?? 'Entered';
                                $kitExportCount   = count($giftVisitors);
                                $kitLabelParts    = [$activeKitTabName];

                                if ($isSuperAdmin) {
                                    if ($dCounter > 0 && !empty($activeCounterName)) {
                                        $kitLabelParts[] = $activeCounterName;
                                    } elseif (!empty($dRegion)) {
                                        $kitLabelParts[] = $dRegion;
                                    }
                                }
                                $kitLabelParts[]    = (string)$kitExportCount;
                                $kitExportFullTitle = "Export CSV (" . implode(', ', $kitLabelParts) . ")";
                                $kitExportUrl       = "admin.php?export=gift_tab_csv&dfilter=" . urlencode($dFilter)
                                    . ($isSuperAdmin && $dCounter > 0 ? '&dcounter=' . urlencode((string)$dCounter) : '')
                                    . ($isSuperAdmin && !empty($dRegion) ? '&dregion=' . urlencode($dRegion) : '')
                                    . (!empty($dSearch) ? '&dsearch=' . urlencode($dSearch) : '');
                            ?>
                            <?php if ($kitExportCount > 0): ?>
                                <a href="<?= $kitExportUrl ?>"
                                   class="btn-topbar btn-topbar-ghost btn-kit-tab-export"
                                   id="btnKitTabExport"
                                   title="<?= htmlspecialchars($kitExportFullTitle) ?>"
                                   onclick="showLiveFlashBanner('Exported <?= $kitExportCount ?> visitor<?= $kitExportCount === 1 ? '' : 's' ?>');">
                                    <i class="bi bi-download"></i><span><?= htmlspecialchars($kitExportFullTitle) ?></span>
                                </a>
                            <?php else: ?>
                                <button type="button"
                                        class="btn-topbar btn-topbar-ghost btn-kit-tab-export disabled"
                                        id="btnKitTabExport"
                                        disabled
                                        title="<?= htmlspecialchars($kitExportFullTitle) ?>">
                                    <i class="bi bi-download"></i><span><?= htmlspecialchars($kitExportFullTitle) ?></span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="table-responsive">
                    <table class="crisp-table table-gift <?= $isCounter ? ('table-counter-view ' . ($isSingleRegionCounter ? 'table-single-region' : 'table-combined-region')) : '' ?>">
                        <thead>
                            <tr>
                                <th class="col-id">#</th>
                                <th class="col-name">Full Name</th>
                                <th class="col-userid">Register Id</th>
                                <?php if ($isSingleRegionCounter): ?>
                                    <th class="col-region">Region</th>
                                <?php else: ?>
                                    <th class="col-region">Region &amp; Counter</th>
                                <?php endif; ?>
                                <th class="col-mobile">Mobile Number</th>
                                <th class="col-actions">Actions</th>
                                <th class="col-gift-status">Kit Status</th>
                                <th class="col-gift-time">Collection Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="giftTableBody" data-start-offset="<?= $giftOffset ?>">
                            <?= renderGiftTableRows($giftVisibleVisitors, $dFilter, $dSearch, $dRegion, $dPage, $giftOffset, $dCounter, $isSingleRegionCounter) ?>
                        </tbody>
                    </table>
                </div>

                <?= renderPagination($dPage, $totalGiftPages, $totalGiftRows, $perPage, 'gift', [
                    'dfilter'  => $dFilter,
                    'dsearch'  => $dSearch,
                    'dcounter' => $dCounter,
                    'dregion'  => $dRegion,
                ]) ?>
            </div>

            <?php endif; ?>

        </div><!-- /content-body -->
    </main><!-- /main-panel -->

</div><!-- /app-shell -->

<?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin'): ?>
<!-- ================================================================
     CSV Import Modal (Synchronized Across Gate Entry & Gift Distribution)
     ================================================================ -->
<div class="modal fade" id="importCsvModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;margin:0.75rem auto;padding:0 10px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:1px solid var(--border-light);">
            <form method="POST" action="admin.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_csv">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">

                <!-- Header -->
                <div style="background:var(--navy);padding:1.1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="brand-icon" style="width:32px;height:32px;font-size:0.95rem;">
                            <i class="bi bi-cloud-arrow-up"></i>
                        </div>
                        <span style="font-weight:700;font-size:0.95rem;color:#ffffff;">Bulk Import Visitors (CSV)</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Body -->
                <div style="padding:1.5rem;background:#ffffff;">
                    <div style="background:#f8fafc;border:1px solid var(--border-light);border-radius:10px;padding:0.85rem 1rem;margin-bottom:1rem;font-size:0.82rem;color:var(--text-muted);">
                        <div style="display:flex;align-items:center;gap:7px;margin-bottom:6px;">
                            <i class="bi bi-info-circle-fill" style="color:var(--blue);font-size:1rem;"></i>
                            <strong style="color:var(--text-dark);">Supported CSV Formats (Auto-Mapped)</strong>
                        </div>
                        <div style="font-family:monospace;background:#ffffff;border:1px solid var(--border-light);border-radius:7px;padding:0.5rem 0.75rem;font-size:0.75rem;color:var(--text-dark);margin-bottom:6px;line-height:1.4;">
                            <strong>Extended / Exported:</strong> sno, region, userid, schname, umname, desig, contact, emaild, fincert<br>
                            <strong>Simple:</strong> Name, Mobile Number
                        </div>
                        <ul style="margin:0;padding-left:1.1rem;line-height:1.7;font-size:0.76rem;">
                            <li>Simultaneously syncs attendees across <strong>Gate Entry</strong> and <strong>COE Kit Distribution</strong>.</li>
                            <li>Preserves Register Id, Region, FinCert ID, and existing check-in / COE Kit timestamps.</li>
                            <li>Phone numbers are automatically sanitized (+91 / 0 stripped).</li>
                        </ul>
                    </div>
                    <div>
                        <label style="display:block;font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;margin-bottom:6px;">
                            <i class="bi bi-filetype-csv" style="color:var(--blue);margin-right:4px;"></i> Select CSV File
                        </label>
                        <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required style="border-radius:9px;">
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding:0.85rem 1.5rem;background:#f8fafc;border-top:1px solid var(--border-light);display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm rounded-pill px-4 fw-bold" style="background:var(--blue);color:#ffffff;">
                        <i class="bi bi-cloud-arrow-up me-1"></i> Upload &amp; Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================
     Create Counter Modal (Super Admin ONLY)
     ================================================================ -->
<div class="modal fade" id="createCounterModal" tabindex="-1" aria-labelledby="createCounterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;margin:1rem auto;padding:0 12px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:1px solid var(--border-light);">
            <form method="POST" action="admin.php">
                <input type="hidden" name="action" value="create_counter">
                <input type="hidden" name="tab" value="counters">
                <div style="background:var(--navy);padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="brand-icon" style="width:32px;height:32px;font-size:0.95rem;background:rgba(29,88,216,0.2);color:var(--blue);">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>
                        <span style="font-weight:700;font-size:0.95rem;color:#ffffff;" id="createCounterModalLabel">Create Regional Counter</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div style="padding:1.5rem;background:#ffffff;">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Region Name <span class="text-danger">*</span></label>
                        <input type="text" name="new_region" class="form-control" placeholder="e.g. Nagpur, Mumbai, Kolhapur" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">Username <span class="text-muted fw-normal">(Optional, auto-generated)</span></label>
                        <input type="text" name="new_username" class="form-control" placeholder="e.g. counter_nagpur">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">Password <span class="text-muted fw-normal">(Optional, auto-generated)</span></label>
                        <input type="text" name="new_password" class="form-control" placeholder="Leave blank to generate a secure random password">
                    </div>
                </div>
                <div style="padding:0.85rem 1.4rem;background:#f8fafc;border-top:1px solid var(--border-light);display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" style="background:var(--blue);border:none;">
                        <i class="bi bi-plus-circle me-1"></i> Create Counter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================================================================
     Reassign Counter Modal (Super Admin ONLY)
     ================================================================ -->
<?php if ($isSuperAdmin): ?>
<div class="modal fade" id="reassignCounterModal" tabindex="-1" aria-labelledby="reassignCounterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;margin:1rem auto;padding:0 12px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:1px solid var(--border-light);box-shadow:0 12px 30px rgba(0,0,0,0.15);">
            <form method="POST" action="admin.php?tab=gift&action=reassign_counter">
                <input type="hidden" name="action" value="reassign_counter">
                <input type="hidden" name="visitor_id" id="reassignVisitorId" value="">
                <input type="hidden" name="dfilter" value="<?= htmlspecialchars($dFilter) ?>">
                <input type="hidden" name="dpage" value="<?= (int)$dPage ?>">
                <input type="hidden" name="dsearch" value="<?= htmlspecialchars($dSearch) ?>">
                <input type="hidden" name="dcounter" value="<?= (int)$dCounter ?>">
                <input type="hidden" name="dregion" value="<?= htmlspecialchars($dRegion) ?>">

                <div style="background:var(--navy);padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="brand-icon" style="width:32px;height:32px;font-size:0.95rem;background:rgba(29,88,216,0.2);color:var(--blue);">
                            <i class="bi bi-arrow-left-right"></i>
                        </div>
                        <span style="font-weight:700;font-size:0.95rem;color:#ffffff;" id="reassignCounterModalLabel">Reassign Attendee Counter</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div style="padding:1.5rem;background:#ffffff;">
                    <p class="text-muted small mb-3">
                        Reassign <strong id="reassignVisitorName" class="text-dark">Attendee</strong> to a different COE Kit distribution counter:
                    </p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-dark">TARGET COUNTER <span class="text-danger">*</span></label>
                        <select name="new_counter_id" id="reassignTargetCounter" class="form-select" required>
                            <?php foreach ($countersList as $cOpt): ?>
                                <option value="<?= (int)$cOpt['id'] ?>">
                                    <?= htmlspecialchars($cOpt['name']) ?><?= !empty($cOpt['name_range']) ? " ({$cOpt['name_range']})" : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold text-dark">REASON / NOTE <span class="text-muted fw-normal">(Optional)</span></label>
                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="e.g. Pune queue rebalance, attendee arrived at counter 2">
                    </div>
                </div>
                <div style="padding:0.85rem 1.4rem;background:#f8fafc;border-top:1px solid var(--border-light);display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" style="background:var(--blue);border:none;">
                        <i class="bi bi-check2-circle me-1"></i> Confirm Move
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($_SESSION['counter_pwd_reset'])): 
    $pwdInfo = $_SESSION['counter_pwd_reset'];
    unset($_SESSION['counter_pwd_reset']);
?>
<!-- Temporary Password Display Modal -->
<div class="modal fade show" id="tempPwdModal" tabindex="-1" style="display:block;background:rgba(15,28,48,0.6);" aria-modal="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;margin:1rem auto;padding:0 12px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:1px solid var(--border-light);box-shadow:0 12px 30px rgba(0,0,0,0.15);">
            <div style="background:var(--navy);padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="brand-icon" style="width:32px;height:32px;font-size:0.95rem;background:rgba(16,185,129,0.2);color:#10b981;">
                        <i class="bi bi-key-fill"></i>
                    </div>
                    <span style="font-weight:700;font-size:0.95rem;color:#ffffff;">New Counter Credentials</span>
                </div>
                <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('tempPwdModal').style.display='none';"></button>
            </div>
            <div style="padding:1.5rem;background:#ffffff;">
                <div class="alert alert-warning border-warning-subtle text-warning-emphasis py-2 px-3 mb-3 small">
                    <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>
                    <strong>Copy password now!</strong> This password will not be shown again.
                </div>
                <div class="mb-2">
                    <span class="text-muted small">Region:</span> <strong><?= htmlspecialchars($pwdInfo['region']) ?></strong>
                </div>
                <div class="mb-3">
                    <span class="text-muted small">Username:</span> <code class="fw-bold px-2 py-0.5 bg-light rounded border text-dark"><?= htmlspecialchars($pwdInfo['username']) ?></code>
                </div>
                <div class="mb-3">
                    <label class="small text-muted fw-bold d-block mb-1">Generated Password:</label>
                    <div class="input-group">
                        <input type="text" id="copyPasswordInput" class="form-control font-monospace fw-bold text-primary" value="<?= htmlspecialchars($pwdInfo['password']) ?>" readonly>
                        <button class="btn btn-outline-primary" type="button" id="copyPwdBtn" onclick="copyCounterPwd()">
                            <i class="bi bi-clipboard me-1"></i> Copy
                        </button>
                    </div>
                </div>
            </div>
            <div style="padding:0.85rem 1.4rem;background:#f8fafc;border-top:1px solid var(--border-light);display:flex;justify-content:flex-end;">
                <button type="button" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold" onclick="document.getElementById('tempPwdModal').style.display='none';" style="background:var(--blue);border:none;">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'super_admin'): ?>
<!-- ================================================================
     Add Single Visitor Modal (Super Admin ONLY)
     ================================================================ -->
<div class="modal fade" id="addVisitorModal" tabindex="-1" aria-labelledby="addVisitorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width:680px;margin:1rem auto;padding:0 12px;">
        <div class="modal-content" style="border-radius:18px;overflow:hidden;border:1px solid var(--border-light);box-shadow:0 16px 40px rgba(15,28,48,0.18);">
            <form id="addVisitorForm" method="POST" action="admin.php" novalidate>
                <input type="hidden" name="action" value="add_visitor">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="ajax" value="1">

                <!-- Header -->
                <div style="background:var(--navy);padding:1.1rem 1.4rem;display:flex;align-items:center;justify-content:space-between;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div class="brand-icon" style="width:34px;height:34px;font-size:1rem;background:rgba(29,88,216,0.25);color:var(--blue);">
                            <i class="bi bi-person-plus-fill"></i>
                        </div>
                        <div>
                            <span style="font-weight:700;font-size:1rem;color:#ffffff;display:block;line-height:1.2;" id="addVisitorModalLabel">Add Visitor</span>
                            <span style="font-size:0.75rem;color:rgba(255,255,255,0.7);">Single Attendee Registration &bull; Gate &amp; COE Kit</span>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Body -->
                <div class="modal-body" style="padding:1.4rem;background:#ffffff;max-height:calc(85vh - 130px);overflow-y:auto;">
                    
                    <div id="addVisGeneralErr" class="alert alert-danger py-2 px-3 small d-none mb-3"></div>

                    <div class="row g-3">
                        <!-- Full Name * -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. Dr. Chetan Sonawane" required style="height:44px;border-radius:9px;">
                            <div class="text-danger small mt-1 d-none" id="err_name"></div>
                        </div>

                        <!-- Register Id * -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Register Id <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="user_id" id="add_user_id" class="form-control" placeholder="e.g. OS_123456 or AttendeeId" required style="height:44px;border-radius:9px;">
                            <div class="text-danger small mt-1 d-none" id="err_user_id"></div>
                        </div>

                        <!-- Region * -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Region <span class="text-danger">*</span>
                            </label>
                            <select name="region" id="add_region" class="form-select" required style="height:44px;border-radius:9px;">
                                <option value="" disabled selected>— Select Region —</option>
                                <?php foreach ($allRegions as $reg): ?>
                                    <option value="<?= htmlspecialchars($reg) ?>"><?= htmlspecialchars($reg) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="text-danger small mt-1 d-none" id="err_region"></div>
                            <div id="addVisCounterHint" class="small text-muted mt-1.5" style="font-size:0.8rem;line-height:1.3;">
                                <i class="bi bi-shop text-primary me-1"></i> Counter: <span class="fw-semibold text-secondary">Select a region</span>
                            </div>
                        </div>

                        <!-- FinCert / Id no. (Optional) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                FinCert / Id no. <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="text" name="fincert" id="add_fincert" class="form-control" placeholder="e.g. NTC-2026/PUN/0042" style="height:44px;border-radius:9px;text-transform:uppercase;">
                            <div class="form-text text-muted" style="font-size:0.75rem;line-height:1.3;margin-top:4px;">
                                Shown to the visitor as "Your Id no." when they check in. If left empty the Id line is hidden.
                            </div>
                            <div class="text-danger small mt-1 d-none" id="err_fincert"></div>
                            <div id="warn_fincert_container" class="d-none"></div>
                        </div>

                        <!-- Mobile Number (Optional) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Mobile Number <span class="text-muted fw-normal">(Optional, 8–10 digits)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted small" style="border-radius:9px 0 0 9px;font-size:0.85rem;">+91</span>
                                <input type="tel" name="mobile" id="add_mobile" class="form-control" placeholder="9876543210" maxlength="15" style="height:44px;border-radius:0 9px 9px 0;">
                            </div>
                            <div class="text-danger small mt-1 d-none" id="err_mobile"></div>
                            <div id="warn_mobile_container" class="d-none"></div>
                        </div>

                        <!-- Email (Optional) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Email <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="email" name="email" id="add_email" class="form-control" placeholder="attendee@example.com" style="height:44px;border-radius:9px;">
                            <div class="text-danger small mt-1 d-none" id="err_email"></div>
                        </div>

                        <!-- School Name (Optional) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                School Name <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="text" name="school_name" id="add_school_name" class="form-control" placeholder="e.g. Delhi Public School" style="height:44px;border-radius:9px;">
                        </div>

                        <!-- Designation (Optional) -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark mb-1">
                                Designation <span class="text-muted fw-normal">(Optional)</span>
                            </label>
                            <input type="text" name="designation" id="add_designation" class="form-control" placeholder="e.g. Principal / Teacher" style="height:44px;border-radius:9px;">
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer" style="padding:0.9rem 1.4rem;background:#f8fafc;border-top:1px solid var(--border-light);display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-sm fw-semibold" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" id="btnAddAnother" class="btn btn-sm rounded-pill px-3 fw-bold" style="background:#e2e8f0;color:#1e293b;border:none;">
                        <i class="bi bi-plus-lg me-1"></i> Add &amp; Add Another
                    </button>
                    <button type="button" id="btnSubmitAddVisitor" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" style="background:var(--blue);border:none;">
                        <i class="bi bi-person-plus-fill me-1"></i> Add Visitor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// Sidebar Toggle (Mobile)
const sidebar        = document.getElementById('sidebar');
const sidebarToggle  = document.getElementById('sidebarToggle');
const sidebarOverlay = document.getElementById('sidebarOverlay');

function openSidebar() {
    sidebar.classList.add('open');
    sidebarOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeSidebar() {
    sidebar.classList.remove('open');
    sidebarOverlay.classList.remove('open');
    document.body.style.overflow = '';
}

if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

// Close sidebar on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeSidebar();
});

// Auto-close sidebar on mobile when navigation links or buttons are clicked
document.querySelectorAll('#sidebar .nav-item-link, #sidebar .btn-logout-sidebar').forEach(el => {
    el.addEventListener('click', () => {
        if (window.innerWidth <= 768) {
            closeSidebar();
        }
    });
});

// =========================================================================
// Flash Notice Auto-Dismiss & URL Cleanup
// =========================================================================
const NOTIFICATION_DURATION_DEFAULT = 1000; // 1 second auto-dismiss
const NOTIFICATION_DURATION_ERROR   = 3000; // 3 seconds for genuine error alerts

let flashNoticeTimer = null;

function dismissFlashNotice(el) {
    if (!el || el.classList.contains('flash-dismissing')) return;
    if (flashNoticeTimer) {
        clearTimeout(flashNoticeTimer);
        flashNoticeTimer = null;
    }
    el.classList.add('flash-dismissing');
    setTimeout(() => {
        if (el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }, 260);
}
window.dismissFlashNotice = dismissFlashNotice;

function initFlashNoticeDismissal() {
    const notice = document.querySelector('.flash-notice');
    if (!notice) return;

    if (flashNoticeTimer) {
        clearTimeout(flashNoticeTimer);
        flashNoticeTimer = null;
    }

    const isError = notice.dataset.isError === 'true';
    const duration = isError ? NOTIFICATION_DURATION_ERROR : NOTIFICATION_DURATION_DEFAULT;

    flashNoticeTimer = setTimeout(() => {
        dismissFlashNotice(notice);
    }, duration);

    // Clean `msg` and related query parameters from browser URL address bar immediately
    // so manual refresh will never re-trigger or stick the banner
    try {
        const url = new URL(window.location.href);
        let changed = false;
        ['msg', 'count', 'skipped', 'import_success'].forEach(p => {
            if (url.searchParams.has(p)) {
                url.searchParams.delete(p);
                changed = true;
            }
        });
        if (changed) {
            const query = url.searchParams.toString();
            const newUrl = url.pathname + (query ? '?' + query : '') + url.hash;
            window.history.replaceState(null, '', newUrl);
        }
    } catch (e) {
        // Fallback safety
    }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFlashNoticeDismissal);
} else {
    initFlashNoticeDismissal();
}

// =========================================================================
// Client-Side Live Sorting & Polling Engine
// =========================================================================

function parseUtcEpoch(str) {
    if (!str) return 0;
    let s = String(str).trim();
    if (s === '' || s === '0000-00-00 00:00:00' || s === '0000-00-00') return 0;
    if (!/[Zz]|[+-]\d{2}:?\d{2}$/.test(s)) {
        s = s.replace(' ', 'T') + 'Z';
    }
    const d = new Date(s);
    return isNaN(d.getTime()) ? 0 : d.getTime();
}

/**
 * Re-sorts table rows in the DOM so that:
 * Gate: latest activity (max of Day 1 & Day 2) comes FIRST, pending comes AFTER (A-Z), ties broken by name A-Z.
 * Gift: collected gifts come FIRST (newest on top), pending comes AFTER (A-Z), ties broken by name A-Z.
 * Updates the # serial number badge to 1, 2, 3...
 */
function sortAdminRows(tbody, type = 'gate') {
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
    if (!rows.length) return;

    rows.sort((a, b) => {
        if (type === 'gate') {
            const aD1 = (a.dataset.d1Checked === '1' && a.dataset.d1Time) ? parseUtcEpoch(a.dataset.d1Time) : 0;
            const aD2 = (a.dataset.d2Checked === '1' && a.dataset.d2Time) ? parseUtcEpoch(a.dataset.d2Time) : 0;
            const aTime = Math.max(aD1, aD2);

            const bD1 = (b.dataset.d1Checked === '1' && b.dataset.d1Time) ? parseUtcEpoch(b.dataset.d1Time) : 0;
            const bD2 = (b.dataset.d2Checked === '1' && b.dataset.d2Time) ? parseUtcEpoch(b.dataset.d2Time) : 0;
            const bTime = Math.max(bD1, bD2);

            const aHas = aTime > 0;
            const bHas = bTime > 0;

            if (aHas && !bHas) return -1;
            if (!aHas && bHas) return 1;

            if (aHas && bHas) {
                if (aTime !== bTime) {
                    return bTime - aTime; // Newest first
                }
                return (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
            }
            return (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
        } else {
            const aTime = (a.dataset.giftCollected === '1' && a.dataset.giftTime) ? parseUtcEpoch(a.dataset.giftTime) : 0;
            const bTime = (b.dataset.giftCollected === '1' && b.dataset.giftTime) ? parseUtcEpoch(b.dataset.giftTime) : 0;

            const aHas = aTime > 0;
            const bHas = bTime > 0;

            if (aHas && !bHas) return -1;
            if (!aHas && bHas) return 1;

            if (aHas && bHas) {
                if (aTime !== bTime) {
                    return bTime - aTime; // Newest first
                }
                return (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
            }
            return (a.dataset.name || '').localeCompare(b.dataset.name || '', undefined, { sensitivity: 'base' });
        }
    });

    // Re-append in sorted order and update serial numbers
    const startOffset = parseInt(tbody.dataset.startOffset || '0', 10);
    rows.forEach((row, idx) => {
        tbody.appendChild(row);
        const badge = row.querySelector('.id-badge');
        if (badge) {
            badge.textContent = '#' + (startOffset + idx + 1);
        }
    });
}
window.sortAdminRows = sortAdminRows;

// Counter Management Helpers (Super Admin)
function copyCounterUsername(uname, btn) {
    if (!uname) return;
    function showOk() {
        if (!btn) return;
        const icon = btn.querySelector('i');
        if (icon) {
            const orig = icon.className;
            icon.className = 'bi bi-check2 text-success';
            setTimeout(() => { icon.className = orig; }, 1200);
        }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(uname).then(showOk).catch(() => {
            const ta = document.createElement('textarea');
            ta.value = uname;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); showOk(); } catch (err) {}
            document.body.removeChild(ta);
        });
    } else {
        const ta = document.createElement('textarea');
        ta.value = uname;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showOk(); } catch (err) {}
        document.body.removeChild(ta);
    }
}
window.copyCounterUsername = copyCounterUsername;

function confirmResetCounterPassword(id, username, region) {
    if (confirm(`Generate and reset a new password for ${region} counter (${username})?`)) {
        window.location.href = `admin.php?tab=counters&action=reset_counter_pwd&counter_id=${id}`;
    }
}
window.confirmResetCounterPassword = confirmResetCounterPassword;

async function copyCounterPwd() {
    const input = document.getElementById('copyPasswordInput');
    const btn = document.getElementById('copyPwdBtn');
    if (!input) return;
    try {
        await navigator.clipboard.writeText(input.value);
        if (btn) {
            btn.innerHTML = `<i class="bi bi-check2 text-success me-1"></i> Copied!`;
            setTimeout(() => {
                btn.innerHTML = `<i class="bi bi-clipboard me-1"></i> Copy`;
            }, 2000);
        }
    } catch (e) {
        input.select();
        document.execCommand('copy');
        if (btn) {
            btn.innerHTML = `<i class="bi bi-check2 text-success me-1"></i> Copied!`;
            setTimeout(() => {
                btn.innerHTML = `<i class="bi bi-clipboard me-1"></i> Copy`;
            }, 2000);
        }
    }
}
window.copyCounterPwd = copyCounterPwd;

function openReassignModal(id, name, currentCounterId) {
    const inputId = document.getElementById('reassignVisitorId');
    const nameEl = document.getElementById('reassignVisitorName');
    const select = document.getElementById('reassignTargetCounter');
    if (inputId) inputId.value = id;
    if (nameEl) nameEl.textContent = name;
    if (select && currentCounterId) {
        select.value = currentCounterId;
    }
    const modalEl = document.getElementById('reassignCounterModal');
    if (modalEl && window.bootstrap) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}
window.openReassignModal = openReassignModal;

// Live Polling & Action Handling
let isFetchingLive = false;

async function pollLiveUpdates(highlightId = null) {
    const currentTab = '<?= $tab ?>';
    if (currentTab === 'counters' || currentTab === 'gate_qr') return;
    if (isFetchingLive) return;
    isFetchingLive = true;
    try {
        const params = new URLSearchParams(window.location.search);
        params.delete('msg');
        params.delete('count');
        params.delete('skipped');
        params.delete('import_success');
        params.set('ajax_live', '1');
        const res = await fetch('admin.php?' + params.toString(), {
            headers: { 'Accept': 'application/json' }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || !data.success) return;

        const tableBody = document.getElementById(data.tab === 'gate' ? 'gateTableBody' : 'giftTableBody');
        const subtitle = document.getElementById(data.tab === 'gate' ? 'gateSubtitle' : 'giftSubtitle');

        if (subtitle) {
            const noun = (data.tab === 'gate' ? 'visitors' : 'attendees');
            subtitle.innerHTML = `${data.count} ${noun} &bull; Latest check-in first`;
        }

        if (tableBody) {
            if (data.startOffset !== undefined) {
                tableBody.dataset.startOffset = data.startOffset;
            }
            const prevFirstRow = tableBody.querySelector('tr[data-id]');
            const prevFirstId = prevFirstRow ? prevFirstRow.dataset.id : null;

            if (tableBody.innerHTML.trim() !== data.rowsHtml.trim()) {
                tableBody.innerHTML = data.rowsHtml;

                const newFirstRow = tableBody.querySelector('tr[data-id]');
                const newFirstId = newFirstRow ? newFirstRow.dataset.id : null;

                if (highlightId) {
                    const targetRow = tableBody.querySelector(`tr[data-id="${highlightId}"]`);
                    if (targetRow) {
                        targetRow.classList.add('row-highlight-new');
                        setTimeout(() => targetRow.classList.remove('row-highlight-new'), 3000);
                    }
                } else if (newFirstId && newFirstId !== prevFirstId) {
                    // Top visitor changed due to incoming check-in
                    newFirstRow.classList.add('row-highlight-new');
                    setTimeout(() => newFirstRow.classList.remove('row-highlight-new'), 3000);
                }
            }
        }

        // Live update filter badge counts if elements exist
        if (data.metrics) {
            const m = data.metrics;
            const bGateAll = document.getElementById('badgeGateAll');
            const bGateD1 = document.getElementById('badgeGateD1');
            const bGateD2 = document.getElementById('badgeGateD2');
            const bGatePend = document.getElementById('badgeGatePending');
            if (bGateAll) bGateAll.textContent = m.totalVisitors;
            if (bGateD1) bGateD1.textContent = m.d1Count;
            if (bGateD2) bGateD2.textContent = m.d2Count;
            if (bGatePend) bGatePend.textContent = m.pendingBothCount;

            const bGiftAll = document.getElementById('badgeGiftAll');
            const bGiftGiven = document.getElementById('badgeGiftGiven');
            const bGiftPend = document.getElementById('badgeGiftPending');
            const bGiftEntered = document.getElementById('badgeGiftEntered');
            const bGiftNotEntered = document.getElementById('badgeGiftNotEntered');
            if (bGiftAll) bGiftAll.textContent = m.totalGiftElig;
            if (bGiftGiven) bGiftGiven.textContent = m.giftsGiven;
            if (bGiftPend) bGiftPend.textContent = m.giftsPending;
            if (bGiftEntered) bGiftEntered.textContent = m.giftsEntered !== undefined ? m.giftsEntered : 0;
            if (bGiftNotEntered) bGiftNotEntered.textContent = m.giftsNotEntered !== undefined ? m.giftsNotEntered : 0;

            const metricGiftsGiven = document.getElementById('metricGiftsGiven');
            if (metricGiftsGiven) metricGiftsGiven.textContent = m.giftsGiven;

            const metricGiftsPending = document.getElementById('metricGiftsPending');
            if (metricGiftsPending) metricGiftsPending.textContent = m.giftsPending;

            const metricGiftRate = document.getElementById('metricGiftRate');
            if (metricGiftRate) metricGiftRate.textContent = m.giftRate + '%';

            const metricGiftRatePill = document.getElementById('metricGiftRatePill');
            if (metricGiftRatePill) metricGiftRatePill.textContent = m.giftRate + '%';

            const metricGiftRateBar = document.getElementById('metricGiftRateBar');
            if (metricGiftRateBar) metricGiftRateBar.style.width = m.giftRate + '%';

            const metricGiftRateBar2 = document.getElementById('metricGiftRateBar2');
            if (metricGiftRateBar2) metricGiftRateBar2.style.width = m.giftRate + '%';

            const metricTotalGiftElig = document.getElementById('metricTotalGiftElig');
            if (metricTotalGiftElig) metricTotalGiftElig.textContent = m.totalGiftElig;

            const topbarGiftsGiven = document.getElementById('topbarGiftsGiven');
            if (topbarGiftsGiven) topbarGiftsGiven.textContent = m.giftsGiven;

            const topbarTotalGiftElig = document.getElementById('topbarTotalGiftElig');
            if (topbarTotalGiftElig) topbarTotalGiftElig.textContent = m.totalGiftElig;

            const topbarGiftRate = document.getElementById('topbarGiftRate');
            if (topbarGiftRate) topbarGiftRate.textContent = m.giftRate + '%';

            const metricTotal = document.getElementById('metricTotalVisitors');
            if (metricTotal) metricTotal.textContent = m.totalVisitors;

            const topbarTotal = document.getElementById('topbarTotalCount');
            if (topbarTotal) topbarTotal.textContent = m.totalVisitors;

            document.querySelectorAll('.sidebar-gate-badge').forEach(el => {
                el.textContent = `${(m.d1Count || 0) + (m.d2Count || 0)}/${m.totalVisitors}`;
            });
            document.querySelectorAll('.sidebar-gift-badge').forEach(el => {
                el.textContent = `${m.giftsGiven}/${m.totalGiftElig}`;
            });
        }

        const tabExportBtn = document.getElementById('btnGateTabExport');
        if (tabExportBtn && data.tab === 'gate' && typeof data.count === 'number') {
            const currentFilter = '<?= $gFilter ?>';
            const currentRegion = '<?= addslashes($gRegion) ?>';
            const tabNames = {
                'all': 'All',
                'day1': 'Day 1 Inside',
                'day2': 'Day 2 Inside',
                'pending_both': 'Pending Both'
            };
            const tabName = tabNames[currentFilter] || 'All';
            const parts = [tabName];
            if (currentRegion) parts.push(currentRegion);
            parts.push(data.count);
            const newTitle = `Export CSV (${parts.join(', ')})`;
            tabExportBtn.title = newTitle;
            const span = tabExportBtn.querySelector('span');
            if (span) span.textContent = newTitle;
            if (data.count === 0) {
                tabExportBtn.classList.add('disabled');
                tabExportBtn.setAttribute('disabled', 'disabled');
            } else {
                tabExportBtn.classList.remove('disabled');
                tabExportBtn.removeAttribute('disabled');
            }
        }

        const kitTabExportBtn = document.getElementById('btnKitTabExport');
        if (kitTabExportBtn && data.tab === 'gift' && typeof data.count === 'number') {
            const currentKitFilter = '<?= $dFilter ?>';
            const kitTabNames = {
                'entered': 'Entered',
                'collected': 'Kit Collected',
                'pending': 'Pending',
                'not_entered': 'Not Entered',
                'all': 'All'
            };
            const kitTabName = kitTabNames[currentKitFilter] || 'Entered';
            const kitParts = [kitTabName];
            <?php if ($isSuperAdmin): ?>
                const currentKitCounterName = '<?= !empty($activeCounterName) ? addslashes($activeCounterName) : '' ?>';
                const currentKitRegion = '<?= addslashes($dRegion) ?>';
                if (currentKitCounterName) kitParts.push(currentKitCounterName);
                else if (currentKitRegion) kitParts.push(currentKitRegion);
            <?php endif; ?>
            kitParts.push(data.count);
            const newKitTitle = `Export CSV (${kitParts.join(', ')})`;
            kitTabExportBtn.title = newKitTitle;
            const kitSpan = kitTabExportBtn.querySelector('span');
            if (kitSpan) kitSpan.textContent = newKitTitle;
            if (data.count === 0) {
                kitTabExportBtn.classList.add('disabled');
                kitTabExportBtn.setAttribute('disabled', 'disabled');
            } else {
                kitTabExportBtn.classList.remove('disabled');
                kitTabExportBtn.removeAttribute('disabled');
                kitTabExportBtn.onclick = function() {
                    showLiveFlashBanner(`Exported ${data.count} visitor${data.count === 1 ? '' : 's'}`);
                };
            }
        }

        if (data.regionCounts) {
            const select = document.querySelector(data.tab === 'gate' ? 'select[name="gregion"]' : 'select[name="dregion"]');
            if (select) {
                for (let opt of select.options) {
                    if (opt.value && data.regionCounts[opt.value] !== undefined) {
                        opt.text = `${opt.value} (${data.regionCounts[opt.value]})`;
                    }
                }
            }
        }
    } catch (e) {
        // Safe silent fail on temporary network glitch
    } finally {
        isFetchingLive = false;
    }
}

window.regionCounterMap = <?= json_encode($regionCounterHintMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
window.escapeHtml = escapeHtml;

function showLiveFlashBanner(text, isError = false) {
    let container = document.querySelector('.content-body');
    if (!container) return;
    let existing = document.getElementById('flashNotice');
    if (existing) {
        existing.remove();
    }
    const div = document.createElement('div');
    div.className = 'flash-notice flash-' + (isError ? 'danger' : 'success');
    div.id = 'flashNotice';
    div.setAttribute('role', 'alert');
    div.dataset.type = isError ? 'danger' : 'success';
    div.dataset.isError = isError ? 'true' : 'false';
    div.innerHTML = `
        <i class="${isError ? 'bi-exclamation-octagon-fill' : 'bi-check2-circle'} flex-shrink-0 fs-5"></i>
        <div class="flex-grow-1">${escapeHtml(text)}</div>
        <button type="button" class="flash-close" aria-label="Close" onclick="dismissFlashNotice(this.closest('.flash-notice'))" title="Dismiss notice">&times;</button>
    `;
    const metricsRow = container.querySelector('.metrics-row');
    if (metricsRow) {
        container.insertBefore(div, metricsRow);
    } else {
        container.prepend(div);
    }
    initFlashNoticeDismissal();
}
window.showLiveFlashBanner = showLiveFlashBanner;

function updateAddVisitorCounterHint() {
    const regSelect = document.getElementById('add_region');
    const nameInput = document.getElementById('add_name');
    const hintEl = document.getElementById('addVisCounterHint');
    if (!regSelect || !hintEl) return;

    const reg = (regSelect.value || '').trim();
    if (!reg) {
        hintEl.innerHTML = '<i class="bi bi-shop text-primary me-1"></i> Counter: <span class="fw-semibold text-secondary">Select a region</span>';
        return;
    }

    if (reg.toLowerCase() === 'pune') {
        const rawName = (nameInput ? nameInput.value : '').trim();
        const cleanName = rawName.replace(/^(dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?|sh\.?|smt\.?|shri\.?)\s+/i, '').trim();

        let puneCounter = 'Pune Counter 4';
        if (!cleanName) {
            hintEl.innerHTML = '<i class="bi bi-shop text-primary me-1"></i> Counter: <strong>Pune Counter (assigned by name)</strong>';
            return;
        }

        if (cleanName.localeCompare('Chetan Sonawane', undefined, { sensitivity: 'base' }) <= 0) {
            puneCounter = 'Pune Counter 1';
        } else if (cleanName.localeCompare('Namira Yunus Contractor', undefined, { sensitivity: 'base' }) <= 0) {
            puneCounter = 'Pune Counter 2';
        } else if (cleanName.localeCompare('Sangeeta Rautji', undefined, { sensitivity: 'base' }) <= 0) {
            puneCounter = 'Pune Counter 3';
        }

        hintEl.innerHTML = `<i class="bi bi-shop text-primary me-1"></i> Counter: <strong>${escapeHtml(puneCounter)} (assigned by name)</strong>`;
    } else {
        const targetName = (window.regionCounterMap && window.regionCounterMap[reg]) 
            ? window.regionCounterMap[reg] 
            : (reg + ' Counter');
        hintEl.innerHTML = `<i class="bi bi-shop text-primary me-1"></i> Counter: <strong>${escapeHtml(targetName)}</strong>`;
    }
}
window.updateAddVisitorCounterHint = updateAddVisitorCounterHint;

const regEl = document.getElementById('add_region');
if (regEl) {
    regEl.addEventListener('change', updateAddVisitorCounterHint);
}
const nameEl = document.getElementById('add_name');
if (nameEl) {
    nameEl.addEventListener('input', () => {
        const regSelect = document.getElementById('add_region');
        if (regSelect && regSelect.value.trim().toLowerCase() === 'pune') {
            updateAddVisitorCounterHint();
        }
    });
}

let isAddingVisitor = false;

async function handleAddVisitorSubmit(andAddAnother = false) {
    if (isAddingVisitor) return;

    // Reset errors
    document.querySelectorAll('#addVisitorModal .text-danger[id^="err_"]').forEach(el => {
        el.textContent = '';
        el.classList.add('d-none');
    });
    const genErr = document.getElementById('addVisGeneralErr');
    if (genErr) {
        genErr.textContent = '';
        genErr.classList.add('d-none');
    }

    const form = document.getElementById('addVisitorForm');
    if (!form) return;

    const nameInput = document.getElementById('add_name');
    const uidInput = document.getElementById('add_user_id');
    const regSelect = document.getElementById('add_region');
    const mobInput = document.getElementById('add_mobile');
    const emailInput = document.getElementById('add_email');

    let hasClientErr = false;

    // Client-side quick checks
    if (!nameInput || nameInput.value.trim().length < 2) {
        const errName = document.getElementById('err_name');
        if (errName) {
            errName.textContent = 'Full Name is required (at least 2 characters).';
            errName.classList.remove('d-none');
        }
        hasClientErr = true;
    }

    if (!uidInput || uidInput.value.trim() === '') {
        const errUid = document.getElementById('err_user_id');
        if (errUid) {
            errUid.textContent = 'Register Id is required.';
            errUid.classList.remove('d-none');
        }
        hasClientErr = true;
    }

    if (!regSelect || regSelect.value.trim() === '') {
        const errReg = document.getElementById('err_region');
        if (errReg) {
            errReg.textContent = 'Please select a Region.';
            errReg.classList.remove('d-none');
        }
        hasClientErr = true;
    }

    if (mobInput && mobInput.value.trim() !== '') {
        const cleanMob = mobInput.value.replace(/\D/g, '');
        let numOnly = cleanMob;
        if (numOnly.length === 12 && numOnly.startsWith('91')) numOnly = numOnly.substring(2);
        if (numOnly.length === 11 && numOnly.startsWith('0')) numOnly = numOnly.substring(1);
        if (numOnly.length < 8 || numOnly.length > 10) {
            const errMob = document.getElementById('err_mobile');
            if (errMob) {
                errMob.textContent = 'Mobile number must be 8 to 10 digits.';
                errMob.classList.remove('d-none');
            }
            hasClientErr = true;
        }
    }

    if (emailInput && emailInput.value.trim() !== '') {
        const emailVal = emailInput.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
            const errEmail = document.getElementById('err_email');
            if (errEmail) {
                errEmail.textContent = 'Please enter a valid email address.';
                errEmail.classList.remove('d-none');
            }
            hasClientErr = true;
        }
    }

    if (hasClientErr) return;

    const formData = new FormData(form);
    const submitBtn = andAddAnother ? document.getElementById('btnAddAnother') : document.getElementById('btnSubmitAddVisitor');
    const origHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Saving…';
        submitBtn.style.pointerEvents = 'none';
    }
    isAddingVisitor = true;

    try {
        const res = await fetch('admin.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await res.json().catch(() => null);

        if (!res.ok || !data) {
            const msg = (data && data.message) ? data.message : 'Server error: unable to save visitor.';
            if (genErr) {
                genErr.textContent = msg;
                genErr.classList.remove('d-none');
            }
            return;
        }

        if (data.status === 'error') {
            if (data.errors) {
                for (const [field, errorMsg] of Object.entries(data.errors)) {
                    const errEl = document.getElementById('err_' + field);
                    if (errEl) {
                        errEl.textContent = errorMsg;
                        errEl.classList.remove('d-none');
                    }
                }
            }
            if (data.message && genErr) {
                genErr.textContent = data.message;
                genErr.classList.remove('d-none');
            }
            return;
        }

        if (data.status === 'warning') {
            if (data.warnings && data.warnings.mobile) {
                const w = data.warnings.mobile;
                const container = document.getElementById('warn_mobile_container');
                if (container) {
                    container.innerHTML = `
                        <div class="alert alert-warning py-2 px-3 small mt-1 mb-0" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
                            <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                            ${escapeHtml(w.message)}
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="confirm_duplicate_mobile" id="confirm_duplicate_mobile" value="1" checked>
                                <label class="form-check-label fw-bold text-dark" for="confirm_duplicate_mobile" style="cursor:pointer;">Add anyway</label>
                            </div>
                        </div>
                    `;
                    container.classList.remove('d-none');
                }
            }
            if (data.warnings && data.warnings.fincert) {
                const w = data.warnings.fincert;
                const container = document.getElementById('warn_fincert_container');
                if (container) {
                    container.innerHTML = `
                        <div class="alert alert-warning py-2 px-3 small mt-1 mb-0" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;">
                            <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                            ${escapeHtml(w.message)}
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="confirm_duplicate_fincert" id="confirm_duplicate_fincert" value="1" checked>
                                <label class="form-check-label fw-bold text-dark" for="confirm_duplicate_fincert" style="cursor:pointer;">Add anyway</label>
                            </div>
                        </div>
                    `;
                    container.classList.remove('d-none');
                }
            }
            return;
        }

        if (data.status === 'success') {
            // Show auto-dismissing flash banner
            showLiveFlashBanner("Visitor " + data.name + " added");

            // Update live tables and metrics
            await pollLiveUpdates(data.visitor_id);

            // Clear warnings
            const warnMob = document.getElementById('warn_mobile_container');
            if (warnMob) { warnMob.innerHTML = ''; warnMob.classList.add('d-none'); }
            const warnFc = document.getElementById('warn_fincert_container');
            if (warnFc) { warnFc.innerHTML = ''; warnFc.classList.add('d-none'); }

            if (andAddAnother) {
                // Clear fields except region
                nameInput.value = '';
                uidInput.value = '';
                if (mobInput) mobInput.value = '';
                if (emailInput) emailInput.value = '';
                const fcInput = document.getElementById('add_fincert');
                if (fcInput) fcInput.value = '';
                const schInput = document.getElementById('add_school_name');
                if (schInput) schInput.value = '';
                const desInput = document.getElementById('add_designation');
                if (desInput) desInput.value = '';

                updateAddVisitorCounterHint();
                nameInput.focus();
            } else {
                const modalEl = document.getElementById('addVisitorModal');
                if (modalEl && window.bootstrap) {
                    const inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                form.reset();
                updateAddVisitorCounterHint();
            }
        }

    } catch (err) {
        if (genErr) {
            genErr.textContent = 'Network or connection error. Please try again.';
            genErr.classList.remove('d-none');
        }
    } finally {
        isAddingVisitor = false;
        if (submitBtn) {
            submitBtn.innerHTML = origHtml;
            submitBtn.style.pointerEvents = '';
        }
    }
}

const btnSub = document.getElementById('btnSubmitAddVisitor');
if (btnSub) {
    btnSub.addEventListener('click', () => handleAddVisitorSubmit(false));
}
const btnAddAn = document.getElementById('btnAddAnother');
if (btnAddAn) {
    btnAddAn.addEventListener('click', () => handleAddVisitorSubmit(true));
}

// Action Button Interceptor (Async check-in / reset with smooth top jump)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.action-btn');
    if (!btn || !btn.href || btn.href.startsWith('#')) return;

    if (e.defaultPrevented) return;

    e.preventDefault();
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" style="width:0.75rem;height:0.75rem;"></span>';
    btn.style.pointerEvents = 'none';

    try {
        const res = await fetch(btn.href, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (res.ok) {
            const resData = await res.json().catch(() => null);
            const actedId = resData && resData.id ? resData.id : null;
            await pollLiveUpdates(actedId);
        } else {
            const errData = await res.json().catch(() => null);
            if (errData && errData.error) {
                showLiveFlashBanner(errData.error, true);
                await pollLiveUpdates();
            } else {
                window.location.href = btn.href;
            }
        }
    } catch (err) {
        window.location.href = btn.href;
    } finally {
        btn.innerHTML = originalHtml;
        btn.style.pointerEvents = '';
    }
});

// Render Gate QR if container exists
const qrContainer = document.getElementById('adminGateQrContainer');
if (qrContainer && typeof QRCode !== 'undefined') {
    const qrUrl = <?= json_encode($gateCheckinUrl ?? '') ?>;
    if (qrUrl) {
        new QRCode(qrContainer, {
            text: qrUrl,
            width: 220,
            height: 220,
            colorDark: '#0f172a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });
    }
}

function copyGateUrl() {
    const input = document.getElementById('gateCheckinUrlInput');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        const btn = document.getElementById('btnCopyUrl');
        if (btn) {
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg me-1 text-success"></i>Copied!';
            setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
        }
    }).catch(() => {
        document.execCommand('copy');
    });
}
window.copyGateUrl = copyGateUrl;

function downloadQrPng() {
    const container = document.getElementById('adminGateQrContainer');
    if (!container) return;
    const img = container.querySelector('img');
    const canvas = container.querySelector('canvas');
    let src = '';
    if (img && img.src) {
        src = img.src;
    } else if (canvas) {
        src = canvas.toDataURL('image/png');
    }
    if (!src) return;
    const a = document.createElement('a');
    a.href = src;
    a.download = 'gate_checkin_qr.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
window.downloadQrPng = downloadQrPng;

// Periodic live poll every 2.5 seconds
setInterval(() => {
    pollLiveUpdates();
}, 2500);
</script>
</body>
</html>
