<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth_v2.php';
afdc_v2_session_start();

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"] ?? '/',
        $params["domain"] ?? '',
        (bool)($params["secure"] ?? false),
        (bool)($params["httponly"] ?? true)
    );
}
session_destroy();

$return = (string)($_GET['return'] ?? 'index.php');
if (preg_match('~^https?://~i', $return)) $return = 'index.php';

header('Location: ' . $return);
exit;
