<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

// Configure secure session cookie params (12 hours lifetime)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 43200, // 12 hours
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$redirectParam = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
$allowedRedirects = ['admin.php', 'gift.php', 'index.php'];
$safeRedirect = in_array(basename($redirectParam), $allowedRedirects, true) ? $redirectParam : '';

// Already logged in → skip straight to relevant page
if (!empty($_SESSION['admin_logged_in'])) {
    $role = (string)($_SESSION['user_role'] ?? '');
    if (!empty($safeRedirect)) {
        header('Location: ' . $safeRedirect);
        exit;
    }
    if ($role === 'counter') {
        header('Location: admin.php?tab=gift');
    } elseif ($role === 'guard') {
        header('Location: admin.php?tab=gate');
    } else {
        header('Location: admin.php');
    }
    exit;
}

$pdo        = Database::getConnection();
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $submittedCsrf)) {
        $loginError = 'Security token expired. Please reload and try again.';
    } else {
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $password = trim((string)($_POST['password'] ?? ''));

        if ($username === '' || $password === '') {
            $loginError = 'Please enter both username and password.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, password, role, region, status, failed_attempts, locked_until, counter_id 
                    FROM `admins` 
                    WHERE username = :u 
                    LIMIT 1
                ");
                $stmt->execute([':u' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Check if account is temporarily locked (rate limit: 5 failures -> 5 min lock)
                    if (!empty($user['locked_until'])) {
                        $lockTime = new DateTime($user['locked_until'], new DateTimeZone('UTC'));
                        $now = new DateTime('now', new DateTimeZone('UTC'));
                        if ($lockTime > $now) {
                            $diffSecs = $lockTime->getTimestamp() - $now->getTimestamp();
                            $diffMins = max(1, (int)ceil($diffSecs / 60));
                            $loginError = "Account temporarily locked due to repeated failed logins. Please try again in {$diffMins} minute(s).";
                        }
                    }

                    if ($loginError === '') {
                        $isPasswordValid = password_verify($password, $user['password']);

                        // Event day helper: accept booth-specific aliases for split or combined booths
                        if (!$isPasswordValid) {
                            if (preg_match('/^counter_pune_([1-4])$/', $user['username'], $pMatch)) {
                                if ($password === 'Pune' . $pMatch[1] . '@2026') {
                                    $isPasswordValid = true;
                                }
                            } elseif ($user['username'] === 'counter_raipur_chandigarh' && in_array($password, ['Raipur@2026', 'Chandigarh@2026'], true)) {
                                $isPasswordValid = true;
                            } elseif ($user['username'] === 'counter_dehradun_lucknow' && in_array($password, ['Dehradun@2026', 'Lucknow@2026'], true)) {
                                $isPasswordValid = true;
                            } elseif ($user['username'] === 'counter_gurugram_guwahati' && in_array($password, ['Gurugram@2026', 'Guwahati@2026'], true)) {
                                $isPasswordValid = true;
                            } elseif ($user['username'] === 'counter_chennai_vijayawada' && in_array($password, ['Chennai@2026', 'Vijayawada@2026'], true)) {
                                $isPasswordValid = true;
                            } elseif ($user['username'] === 'counter_panchkula_prayagraj_hyderabad' && in_array($password, ['Panchkula@2026', 'Prayagraj@2026', 'Hyderabad@2026'], true)) {
                                $isPasswordValid = true;
                            }
                        }

                        if ($isPasswordValid) {
                            // Check if account status is active
                            if (($user['status'] ?? 'active') === 'disabled') {
                                $loginError = 'Your account has been disabled. Please contact the Super Admin.';
                            } else {
                                // Successful auth — regenerate session ID to prevent fixation
                                session_regenerate_id(true);
                                $_SESSION['csrf_token']      = bin2hex(random_bytes(32)); // New CSRF on auth
                                $_SESSION['admin_logged_in'] = true;
                                $_SESSION['admin_username']  = $user['username'];
                                $_SESSION['admin_id']        = (int)$user['id'];
                                $_SESSION['user_role']       = !empty($user['role']) ? $user['role'] : ($user['username'] === 'admin' ? 'super_admin' : 'guard');
                                $_SESSION['counter_region']  = trim((string)($user['region'] ?? ''));
                                $_SESSION['counter_id']      = !empty($user['counter_id']) ? (int)$user['counter_id'] : 0;
                                $_SESSION['login_time']      = time();

                                // If role is counter, resolve and hydrate rich counter metadata
                                if ($_SESSION['user_role'] === 'counter') {
                                    $cStmt = null;
                                    if (!empty($_SESSION['counter_id'])) {
                                        $cStmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM `counters` WHERE id = :id LIMIT 1");
                                        $cStmt->execute([':id' => $_SESSION['counter_id']]);
                                    } else {
                                        $cStmt = $pdo->prepare("SELECT id, counter_no, name, code, name_range FROM `counters` WHERE username = :u LIMIT 1");
                                        $cStmt->execute([':u' => $user['username']]);
                                    }
                                    $cRow = $cStmt ? $cStmt->fetch(PDO::FETCH_ASSOC) : null;
                                    if ($cRow) {
                                        $_SESSION['counter_id']    = (int)$cRow['id'];
                                        $_SESSION['counter_no']    = !empty($cRow['counter_no']) ? (int)$cRow['counter_no'] : null;
                                        $_SESSION['counter_name']  = $cRow['name'];
                                        $_SESSION['counter_code']  = $cRow['code'];
                                        $_SESSION['counter_range'] = (string)($cRow['name_range'] ?? '');
                                        $_SESSION['counter_region']= $cRow['name'];

                                        // Update counter last_login
                                        $pdo->prepare("UPDATE `counters` SET last_login = UTC_TIMESTAMP() WHERE id = :cid")
                                            ->execute([':cid' => $cRow['id']]);
                                    }
                                }

                                // Reset failed attempts and update last_login timestamp
                                $upd = $pdo->prepare("
                                    UPDATE `admins` 
                                    SET failed_attempts = 0, locked_until = NULL, last_login = UTC_TIMESTAMP() 
                                    WHERE id = :id
                                ");
                                $upd->execute([':id' => $user['id']]);

                                // Determine redirect destination
                                if (!empty($safeRedirect)) {
                                    header('Location: ' . $safeRedirect);
                                    exit;
                                }

                                if ($_SESSION['user_role'] === 'counter') {
                                    header('Location: admin.php?tab=gift');
                                } elseif ($_SESSION['user_role'] === 'guard') {
                                    header('Location: admin.php?tab=gate');
                                } else {
                                    header('Location: admin.php');
                                }
                                exit;
                            }
                        } else {
                            // Failed attempt — increment failed_attempts & lock if >= 5
                            $newFailures = (int)($user['failed_attempts'] ?? 0) + 1;
                            if ($newFailures >= 5) {
                                $pdo->prepare("
                                    UPDATE `admins` 
                                    SET failed_attempts = :f, locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) 
                                    WHERE id = :id
                                ")->execute([':f' => $newFailures, ':id' => $user['id']]);
                                $loginError = 'Too many failed login attempts. Account locked for 5 minutes.';
                            } else {
                                $pdo->prepare("
                                    UPDATE `admins` 
                                    SET failed_attempts = :f 
                                    WHERE id = :id
                                ")->execute([':f' => $newFailures, ':id' => $user['id']]);
                                $remaining = 5 - $newFailures;
                                $loginError = "Invalid username or password. ({$remaining} attempt(s) remaining before temporary lockout)";
                            }
                        }
                    }
                } else {
                    $loginError = 'Invalid username or password. Please try again.';
                }
            } catch (\Throwable $e) {
                error_log("Login authentication error: " . $e->getMessage());
                $loginError = 'Authentication error. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Admin Login | EventMS</title>

    <!-- Bootstrap 5.3.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- EventMS Design System -->
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .login-wrap {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            padding: 20px 16px;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .login-input {
            height: 52px;
            border-radius: 12px;
            border: 1.5px solid #cbd5e1;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-dark);
            padding: 0 1rem 0 2.75rem;
            width: 100%;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        .login-input:focus {
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 0.25rem rgba(29, 88, 216, 0.18);
            outline: none;
        }
        .login-input-password {
            padding-right: 48px;
        }
        .input-icon-wrap {
            position: relative;
        }
        .input-icon-wrap > .bi {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            pointer-events: none;
            z-index: 2;
        }
        .btn-toggle-password {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 48px;
            min-width: 40px;
            border: none;
            background: transparent;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            font-size: 1.15rem;
            cursor: pointer;
            z-index: 3;
            border-radius: 0 12px 12px 0;
            transition: color 0.15s ease;
        }
        .btn-toggle-password:hover {
            color: var(--text-dark, #0f172a);
        }
        .btn-toggle-password:focus {
            outline: none;
            color: var(--accent-blue, #1d58d8);
        }
        .btn-toggle-password:focus-visible {
            outline: 2px solid var(--accent-blue, #1d58d8);
            outline-offset: -2px;
            border-radius: 0 12px 12px 0;
        }
        .btn-login {
            height: 52px;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 9999px;
            background: var(--accent-blue);
            border: none;
            color: #ffffff;
            box-shadow: 0 4px 18px var(--accent-blue-glow);
            width: 100%;
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .btn-login:hover {
            background: var(--accent-blue-hover);
            box-shadow: 0 6px 22px rgba(29, 88, 216, 0.45);
            color: #ffffff;
        }
        .btn-login:active { transform: scale(0.98); }
        .form-label-small {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 6px;
            display: block;
        }
    </style>
</head>
<body>

<div class="login-wrap">
    <div class="gate-card hybrid-card">

        <!-- Navy Header -->
        <div class="hybrid-header">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="hybrid-header-icon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <span class="hybrid-header-pill">Portal Access</span>
            </div>
            <h4 class="fw-bold mb-1 text-white">EventMS Portal Login</h4>
            <p class="text-white-50 small mb-0">Super Admin, Gate Guard &amp; Counter Staff Login</p>
        </div>

        <!-- Crisp White Body -->
        <div class="hybrid-body">

            <!-- Error Badge -->
            <?php if ($loginError !== ''): ?>
                <div class="alert alert-danger bg-danger-subtle border-danger-subtle text-danger-emphasis d-flex align-items-center gap-2 py-2 px-3 rounded-3 mb-3 small" role="alert">
                    <i class="bi bi-exclamation-triangle-fill fs-5 flex-shrink-0"></i>
                    <div class="fw-semibold"><?= htmlspecialchars($loginError) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <?php if (!empty($safeRedirect)): ?>
                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($safeRedirect) ?>">
                <?php endif; ?>

                <!-- Username -->
                <div class="mb-3">
                    <label class="form-label-small" for="usernameInput">
                        <i class="bi bi-person me-1 text-primary"></i> Username
                    </label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-person-fill"></i>
                        <input
                            type="text"
                            id="usernameInput"
                            name="username"
                            class="login-input"
                            placeholder="Enter username"
                            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                            required
                            autofocus
                            autocomplete="username"
                        >
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label class="form-label-small" for="passwordInput">
                        <i class="bi bi-lock me-1 text-primary"></i> Password
                    </label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-lock-fill"></i>
                        <input
                            type="password"
                            id="passwordInput"
                            name="password"
                            class="login-input login-input-password"
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                        >
                        <button
                            type="button"
                            class="btn-toggle-password"
                            id="togglePasswordBtn"
                            aria-label="Show password"
                            title="Show password"
                            onclick="togglePasswordVisibility()"
                        >
                            <i class="bi bi-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Electric Blue CTA (#1d58d8) -->
                <div class="d-grid">
                    <button type="submit" class="btn-login">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Sign In to Portal
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePasswordVisibility() {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('togglePasswordIcon');
    const btn = document.getElementById('togglePasswordBtn');
    if (!input) return;

    const start = input.selectionStart;
    const end = input.selectionEnd;

    const isPassword = (input.type === 'password');
    input.type = isPassword ? 'text' : 'password';

    if (btn) {
        const newLabel = isPassword ? 'Hide password' : 'Show password';
        btn.setAttribute('aria-label', newLabel);
        btn.setAttribute('title', newLabel);
    }
    if (icon) {
        icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
    }

    input.focus();
    if (start !== null && end !== null) {
        input.setSelectionRange(start, end);
    }
}

window.addEventListener('pageshow', function() {
    const input = document.getElementById('passwordInput');
    const icon = document.getElementById('togglePasswordIcon');
    const btn = document.getElementById('togglePasswordBtn');
    if (input) {
        input.type = 'password';
    }
    if (btn) {
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('title', 'Show password');
    }
    if (icon) {
        icon.className = 'bi bi-eye';
    }
});
</script>
</body>
</html>

