<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verify_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'] ?? '',
        'secure' => (bool)$p['secure'], 'httponly' => (bool)$p['httponly'], 'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;
