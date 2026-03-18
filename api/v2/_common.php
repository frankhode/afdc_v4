<?php
declare(strict_types=1);

/**
 * API v2 common helpers
 * - Incluye bootstrap + auth_v2
 * - JSON output consistente
 * - CSRF unificado con auth_v2.php (usa $_SESSION['csrf'])
 * - Helpers: jexit(), read_json(), require_user_api(), require_csrf()
 */

require_once __DIR__ . '/../../inc/bootstrap.php';
require_once __DIR__ . '/../../inc/auth_v2.php';

// Asegurar sesión (LAN-friendly)
if (function_exists('afdc_v2_session_start')) {
    afdc_v2_session_start();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Para no quedar ciegos cuando explota algo en producción LAN
set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'server_error',
        'detail' => $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

function jexit(array $payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json(): array {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw ?: 'null', true);
    return is_array($j) ? $j : [];
}

/**
 * ✅ Para endpoints API: NO redirige a login.
 * Devuelve 401 JSON si no está autenticado.
 */
function require_user_api(): array {
    if (function_exists('afdc_v2_require_login')) {
        return afdc_v2_require_login(); // ya responde JSON 401
    }

    // Fallback (por si cambia auth_v2)
    if (!function_exists('afdc_v2_current_user')) {
        jexit(['ok' => false, 'error' => 'auth_missing'], 500);
    }
    $u = afdc_v2_current_user();
    if (!$u || empty($u['id'])) {
        jexit(['ok' => false, 'error' => 'login_required'], 401);
    }
    return $u;
}

/**
 * CSRF unificado con auth_v2.php
 * - Token: afdc_v2_csrf_token() => $_SESSION['csrf']
 */
function csrf_token(): string {
    if (function_exists('afdc_v2_csrf_token')) {
        return afdc_v2_csrf_token();
    }
    // fallback mínimo
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

/**
 * Exigir CSRF para métodos que modifican.
 * Usa el mismo chequeo que auth_v2.php.
 */
function require_csrf(): void {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'GET') return;

    if (function_exists('afdc_v2_csrf_check_from_header')) {
        afdc_v2_csrf_check_from_header(); // corta con JSON 403 si falla
        return;
    }

    // fallback mínimo
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($hdr) || $hdr === '' || !hash_equals(csrf_token(), $hdr)) {
        jexit(['ok' => false, 'error' => 'bad_csrf'], 403);
    }
}
