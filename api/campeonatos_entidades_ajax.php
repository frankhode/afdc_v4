<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

header('Content-Type: application/json; charset=utf-8');

function cmp_ent_ajax_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cmp_ent_ajax_require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cmp_ent_ajax_json([
            'ok' => false,
            'error' => 'Método no permitido.',
        ], 405);
    }
}

function cmp_ent_ajax_format_entity(array $row): array {
    return [
        'id' => (int)$row['id'],
        'nombre_mostrable' => (string)($row['nombre_mostrable'] ?? ''),
        'nombre_oficial' => (string)($row['nombre_oficial'] ?? ''),
        'nombre_normalizado' => (string)($row['nombre_normalizado'] ?? ''),
        'tipo' => (string)($row['tipo'] ?? ''),
        'alias_match' => (string)($row['alias_match'] ?? ''),
        'label' => (string)($row['nombre_mostrable'] ?? ''),
        'value' => (string)($row['nombre_mostrable'] ?? ''),
    ];
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

    if ($action === 'search') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));
        $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 12);
        $limit = max(1, min(30, $limit));

        $length = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
        if ($length < 2) {
            cmp_ent_ajax_json([
                'ok' => true,
                'results' => [],
                'items' => [],
            ]);
        }

        $rows = cmp_ent_search_entities($q, $limit);
        $formatted = array_map('cmp_ent_ajax_format_entity', $rows);

        cmp_ent_ajax_json([
            'ok' => true,
            'results' => $formatted,
            'items' => $formatted,
        ]);
    }

    if ($action === 'resolve') {
        $q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

        if ($q === '') {
            cmp_ent_ajax_json([
                'ok' => true,
                'resolved' => false,
                'entity' => null,
                'normalized' => '',
            ]);
        }

        $entity = cmp_ent_resolve_name($q);

        if (!$entity) {
            cmp_ent_ajax_json([
                'ok' => true,
                'resolved' => false,
                'entity' => null,
                'normalized' => cmp_ent_normalize_name($q),
            ]);
        }

        cmp_ent_ajax_json([
            'ok' => true,
            'resolved' => true,
            'entity' => cmp_ent_ajax_format_entity($entity),
            'normalized' => cmp_ent_normalize_name($q),
        ]);
    }

    if ($action === 'create_entity') {
        cmp_ent_ajax_require_post();

        $name = trim((string)($_POST['name'] ?? ''));
        $type = trim((string)($_POST['type'] ?? 'club'));

        if ($name === '') {
            throw new InvalidArgumentException('El nombre del equipo no puede estar vacío.');
        }

        if (!in_array($type, ['club', 'seleccion', 'combinado'], true)) {
            $type = 'club';
        }

        $existing = cmp_ent_resolve_name($name);
        if ($existing) {
            cmp_ent_ajax_json([
                'ok' => true,
                'created' => false,
                'entity' => cmp_ent_ajax_format_entity($existing),
                'message' => 'Ya existía una entidad compatible.',
            ]);
        }

        $entityId = cmp_ent_create($name, $name, $type);
        $entity = cmp_ent_get($entityId);

        if (!$entity) {
            throw new RuntimeException('No se pudo recuperar la entidad creada.');
        }

        cmp_ent_ajax_json([
            'ok' => true,
            'created' => true,
            'entity' => cmp_ent_ajax_format_entity($entity),
        ]);
    }

    if ($action === 'create_alias') {
        cmp_ent_ajax_require_post();

        $alias = trim((string)($_POST['alias'] ?? ''));
        $entityId = (int)($_POST['entity_id'] ?? 0);

        if ($alias === '') {
            throw new InvalidArgumentException('El alias no puede estar vacío.');
        }

        if ($entityId <= 0) {
            throw new InvalidArgumentException('Seleccioná una entidad de destino.');
        }

        $entity = cmp_ent_get($entityId);
        if (!$entity) {
            throw new InvalidArgumentException('La entidad de destino no existe.');
        }

        cmp_ent_add_alias($entityId, $alias, 'Alias creado desde carga manual de importación', 'manual');

        cmp_ent_ajax_json([
            'ok' => true,
            'created' => true,
            'entity' => cmp_ent_ajax_format_entity($entity),
        ]);
    }

    throw new InvalidArgumentException('Acción inválida.');
} catch (Throwable $e) {
    cmp_ent_ajax_json([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}