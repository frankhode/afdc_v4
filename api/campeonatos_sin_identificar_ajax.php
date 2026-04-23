<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_sin_identificar_repo.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

    if ($action === 'search_registros') {
        $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
        $rows = cmp_si_search_registros($q, 50);

        echo json_encode([
            'ok' => true,
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'search_entities') {
        $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
        $rows = cmp_ent_list_all(['q' => $q]);

        echo json_encode([
            'ok' => true,
            'rows' => array_slice($rows, 0, 20),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'list_sobres') {
        $tituloReg = trim((string)($_POST['tituloReg'] ?? $_GET['tituloReg'] ?? ''));
        if ($tituloReg === '') {
            throw new InvalidArgumentException('Falta tituloReg.');
        }

        $sobres = cmp_si_list_sobres_by_registro($tituloReg);

        echo json_encode([
            'ok' => true,
            'sobres' => $sobres,
            'import_id' => cmp_si_ensure_import(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'create_match') {
        $payload = [
            'barcode' => (string)($_POST['barcode'] ?? ''),
            'tituloReg' => (string)($_POST['tituloReg'] ?? ''),
            'tituloSobre' => (string)($_POST['tituloSobre'] ?? ''),
            'fecha_texto' => (string)($_POST['fecha_texto'] ?? ''),
            'local_texto' => (string)($_POST['local_texto'] ?? ''),
            'visitante_texto' => (string)($_POST['visitante_texto'] ?? ''),
            'local_entidad_id' => (int)($_POST['local_entidad_id'] ?? 0),
            'visitante_entidad_id' => (int)($_POST['visitante_entidad_id'] ?? 0),
            'observacion_manual' => (string)($_POST['observacion_manual'] ?? ''),
        ];

        $matchId = cmp_si_create_match($payload);

        echo json_encode([
            'ok' => true,
            'match_id' => $matchId,
            'message' => 'Partido cargado en Sin identificar.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'debug_import') {
        echo json_encode([
            'ok' => true,
            'import_id' => cmp_si_ensure_import(),
            'node_id' => cmp_si_ensure_target_node(cmp_si_ensure_import()),
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