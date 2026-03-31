<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = trim((string)($_GET['action'] ?? ''));

    if ($action !== 'search_registros') {
        throw new InvalidArgumentException('Acción inválida.');
    }

    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 20);

    $items = cmp_edit_search_registros_titulo245($q, $limit);

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
