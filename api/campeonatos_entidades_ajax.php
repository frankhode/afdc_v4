<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

        $rows = cmp_ent_search_entities($q, 12);

        echo json_encode([
            'ok' => true,
            'results' => array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'nombre_mostrable' => (string)$row['nombre_mostrable'],
                    'nombre_oficial' => (string)$row['nombre_oficial'],
                    'nombre_normalizado' => (string)$row['nombre_normalizado'],
                    'tipo' => (string)$row['tipo'],
                    'alias_match' => (string)($row['alias_match'] ?? ''),
                ];
            }, $rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new InvalidArgumentException('Acción inválida.');
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}