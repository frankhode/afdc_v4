<?php
declare(strict_types=1);

/**
 * Auth v2 (LAN-friendly)
 * - Sesión segura básica (HttpOnly + SameSite=Lax)
 * - current_user(), require_login(), require_admin()
 * - CSRF por sesión
 */

require_once __DIR__ . '/bootstrap.php';

function afdc_v2_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // PHP 7.3+ soporta array con samesite
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,     // solo si HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function afdc_v2_csrf_token(): string {
    afdc_v2_session_start();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function afdc_v2_csrf_check_from_header(): void {
    afdc_v2_session_start();
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sess = $_SESSION['csrf'] ?? '';
    if (!is_string($hdr) || !is_string($sess) || $hdr === '' || $sess === '' || !hash_equals($sess, $hdr)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function afdc_v2_user_id(): ?int {
    afdc_v2_session_start();
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) return null;
    return (int)$id;
}

function afdc_v2_current_user(): ?array {
    $uid = afdc_v2_user_id();
    if (!$uid) return null;

    $rows = q(
        "SELECT id, username, role, display_name, is_active
         FROM users
         WHERE id=? LIMIT 1",
        "i",
        [$uid]
    );
    if (!$rows) return null;
    $u = $rows[0];
    if ((int)($u['is_active'] ?? 0) !== 1) return null;
    return $u;
}

function afdc_v2_require_login(): array {
    $u = afdc_v2_current_user();
    if (!$u) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}

function afdc_v2_require_admin(): array {
    $u = afdc_v2_require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autorizado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}
