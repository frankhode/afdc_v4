<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

function cmp_norm_redirect(
    int $importId,
    ?string $message = null,
    ?string $error = null,
    array $extra = []
): void {
    $params = ['id' => $importId];

    if ($message !== null && $message !== '') {
        $params['msg'] = $message;
    }

    if ($error !== null && $error !== '') {
        $params['error'] = $error;
    }

    foreach ($extra as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $params[(string)$key] = $value;
    }

    header('Location: campeonatos_normalizar_equipos.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$importId = (int)($_POST['import_id'] ?? 0);
$filter = trim((string)($_POST['filter'] ?? 'pending'));
$q = trim((string)($_POST['q'] ?? ''));

try {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    if ($action === 'add_alias') {
        $entidadId = (int)($_POST['entidad_id'] ?? 0);
        $alias = trim((string)($_POST['alias'] ?? ''));

        if ($entidadId <= 0) {
            throw new InvalidArgumentException('Seleccioná una entidad.');
        }

        if ($alias === '') {
            throw new InvalidArgumentException('Alias inválido.');
        }

        $entity = cmp_ent_get($entidadId);
        if (!$entity) {
            throw new RuntimeException('La entidad seleccionada no existe.');
        }

        if (!cmp_ent_alias_exists_for_entity($entidadId, $alias)) {
            cmp_ent_add_alias(
                $entidadId,
                $alias,
                'Alias detectado en importación #' . $importId,
                'detected'
            );
        }

        $stats = cmp_ent_backfill_all_matches($importId);

        $msg = sprintf(
            'Alias agregado: "%s" → %s. Backfill: partidos %d · locales resueltos %d · visitantes resueltos %d.',
            $alias,
            (string)$entity['nombre_mostrable'],
            (int)$stats['total'],
            (int)$stats['local_resueltos'],
            (int)$stats['visitante_resueltos']
        );

        cmp_norm_redirect($importId, $msg, null, [
            'filter' => $filter,
            'q' => $q,
        ]);
    }

        if ($action === 'create_entity') {
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $tipo = trim((string)($_POST['tipo'] ?? 'club'));

        if ($nombre === '') {
            throw new InvalidArgumentException('Nombre de entidad inválido.');
        }

        if (!in_array($tipo, ['club', 'seleccion', 'combinado'], true)) {
            $tipo = 'club';
        }

        $existing = cmp_ent_resolve_name($nombre);

        if ($existing) {
            $entityId = (int)$existing['id'];
            $entity = $existing;

            if (!cmp_ent_alias_exists_for_entity($entityId, $nombre)) {
                cmp_ent_add_alias(
                    $entityId,
                    $nombre,
                    'Alias detectado en importación #' . $importId,
                    'detected'
                );
            }

            $actionLabel = 'Ya existía entidad; alias vinculado';
        } else {
            $entityId = cmp_ent_create(
                $nombre,
                $nombre,
                $tipo,
                [
                    'notas' => 'Entidad creada desde normalizador de equipos, importación #' . $importId,
                ]
            );

            $entity = cmp_ent_get($entityId);
            if (!$entity) {
                throw new RuntimeException('La entidad fue creada pero no pudo recuperarse.');
            }

            $actionLabel = 'Entidad creada';
        }

        $stats = cmp_ent_backfill_all_matches($importId);

        $msg = sprintf(
            '%s: "%s" → %s (%s). Backfill: partidos %d · locales resueltos %d · visitantes resueltos %d.',
            $actionLabel,
            $nombre,
            (string)$entity['nombre_mostrable'],
            $tipo,
            (int)$stats['total'],
            (int)$stats['local_resueltos'],
            (int)$stats['visitante_resueltos']
        );

        cmp_norm_redirect($importId, $msg, null, [
            'filter' => $filter,
            'q' => $q,
        ]);
    }

    throw new InvalidArgumentException('Acción no reconocida.');
} catch (Throwable $e) {
    cmp_norm_redirect($importId, null, $e->getMessage(), [
        'filter' => $filter,
        'q' => $q,
    ]);
}