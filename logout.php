<?php
declare(strict_types=1);

session_start();

// Destroy all session data securely
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

$redirectParam = trim((string)($_GET['redirect'] ?? ''));
if ($redirectParam === 'gift.php') {
    header('Location: gift.php');
} else {
    header('Location: login.php');
}
exit;

