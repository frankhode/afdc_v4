<?php
declare(strict_types=1);

require_once __DIR__ . '/../../inc/auth_v2.php';

function api_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function api_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function api_path(): string {
    // con rewrite: /api/v2/<algo>
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    // recorta hasta /api/v2/
    $pos = strpos($path, '/api/v2/');
    if ($pos === false) return '/';
    $sub = substr($path, $pos + strlen('/api/v2'));
    return $sub ?: '/';
}

function api_validate_image_key(string $k): bool {
    // BARCODE_000 (barcode alfanum + '_' + 3 dígitos)
    return (bool)preg_match('/^[A-Za-z0-9]+_\d{3}$/', $k);
}
