<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

function cmp_ent_admin_redirect(string $target, array $params = []): void {
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: ' . $target . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($action === 'create_entity') {
        $nombreMostrable = trim((string)($_POST['nombre_mostrable'] ?? ''));
        $nombreOficial = trim((string)($_POST['nombre_oficial'] ?? ''));
        $tipo = trim((string)($_POST['tipo'] ?? 'club'));

        if ($nombreMostrable === '') {
            throw new InvalidArgumentException('El nombre mostrable es obligatorio.');
        }

        if ($nombreOficial === '') {
            $nombreOficial = $nombreMostrable;
        }

        if (!in_array($tipo, ['club', 'seleccion', 'combinado'], true)) {
            $tipo = 'club';
        }

        $id = cmp_ent_create($nombreOficial, $nombreMostrable, $tipo, [
            'notas' => 'Entidad creada desde administración general.',
        ]);

        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'msg' => 'Entidad creada.',
        ]);
    }

    if ($action === 'update_entity') {
        $id = (int)($_POST['id'] ?? 0);

        cmp_ent_update_entity(
            $id,
            (string)($_POST['nombre_oficial'] ?? ''),
            (string)($_POST['nombre_mostrable'] ?? ''),
            (string)($_POST['tipo'] ?? 'club'),
            ($_POST['pais'] ?? '') !== '' ? (string)$_POST['pais'] : null,
            ($_POST['ciudad'] ?? '') !== '' ? (string)$_POST['ciudad'] : null,
            ($_POST['provincia_estado'] ?? '') !== '' ? (string)$_POST['provincia_estado'] : null,
            ($_POST['notas'] ?? '') !== '' ? (string)$_POST['notas'] : null,
            (int)($_POST['is_active'] ?? 1)
        );

        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'msg' => 'Entidad actualizada.',
        ]);
    }

    if ($action === 'add_alias') {
        $id = (int)($_POST['id'] ?? 0);
        $alias = trim((string)($_POST['alias'] ?? ''));
        $notas = trim((string)($_POST['notas'] ?? ''));
        $origen = trim((string)($_POST['origen'] ?? 'manual'));

        if ($id <= 0) {
            throw new InvalidArgumentException('Entidad inválida.');
        }

        if ($alias === '') {
            throw new InvalidArgumentException('El alias es obligatorio.');
        }

        $entity = cmp_ent_get($id);
        if (!$entity) {
            throw new RuntimeException('La entidad no existe.');
        }

        $existingAlias = cmp_ent_get_alias_by_normalized($alias);
        if ($existingAlias && (int)$existingAlias['entidad_id'] !== $id) {
            throw new RuntimeException(
                'Ese alias ya está asignado a otra entidad: ' . (string)$existingAlias['nombre_mostrable']
            );
        }

        if (!cmp_ent_alias_exists_for_entity($id, $alias)) {
            cmp_ent_add_alias(
                $id,
                $alias,
                $notas !== '' ? $notas : null,
                $origen !== '' ? $origen : 'manual'
            );
        }

        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'msg' => 'Alias agregado.',
        ]);
    }

    if ($action === 'delete_alias') {
        $id = (int)($_POST['id'] ?? 0);
        $aliasId = (int)($_POST['alias_id'] ?? 0);

        $alias = cmp_ent_get_alias($aliasId);
        if (!$alias || (int)$alias['entidad_id'] !== $id) {
            throw new RuntimeException('Alias inválido.');
        }

        cmp_ent_delete_alias($aliasId);

        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'msg' => 'Alias eliminado.',
        ]);
    }

    if ($action === 'backfill_all') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Entidad inválida.');
        }

        $stats = cmp_ent_backfill_all_matches(null);

        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'msg' => sprintf(
                'Reprocesamiento terminado. Partidos: %d · locales resueltos: %d · visitantes resueltos: %d.',
                (int)$stats['total'],
                (int)$stats['local_resueltos'],
                (int)$stats['visitante_resueltos']
            ),
        ]);
    }

    throw new InvalidArgumentException('Acción no reconocida.');
} catch (Throwable $e) {
    $id = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        cmp_ent_admin_redirect('campeonatos_entidad.php', [
            'id' => $id,
            'error' => $e->getMessage(),
        ]);
    }

    cmp_ent_admin_redirect('campeonatos_entidades.php', [
        'error' => $e->getMessage(),
    ]);
}