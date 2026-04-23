<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/campeonatos_sin_identificar_repo.php';
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_visor_repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
    $filters = cmp_visor_filters_from_request();

    if ($action === 'search') {
        $imports = cmp_visor_list_imports($filters);

        echo json_encode([
            'ok' => true,
            'championships_html' => cmp_visor_render_import_cards_html($imports, 0),
            'shell_html' => '',
            'detail_html' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'championship') {
        $importId = (int)$filters['id'];
        if ($importId <= 0) {
            throw new InvalidArgumentException('Importación inválida.');
        }

        $context = cmp_visor_get_import_context($importId, $filters['team1'], $filters['team2']);

        echo json_encode([
            'ok' => true,
            'shell_html' => cmp_visor_render_championship_shell_html($context, $filters['team1'], $filters['team2']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'node_detail') {
        $importId = (int)$filters['id'];
        $nodeId = (int)$filters['node_id'];

        if ($importId <= 0 || $nodeId <= 0) {
            throw new InvalidArgumentException('Nodo inválido.');
        }

        $context = cmp_visor_get_import_context($importId, $filters['team1'], $filters['team2']);

        echo json_encode([
            'ok' => true,
            'detail_html' => cmp_visor_render_node_detail_html(
                $context['tree'],
                $nodeId,
                $filters['team1'],
                $filters['team2'],
                $filters['only_linked'] === '1'
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'backfill_entities') {
        $importId = (int)$filters['id'];
        $stats = cmp_ent_backfill_all_matches($importId > 0 ? $importId : null);

        echo json_encode([
            'ok' => true,
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

        if ($action === 'move_targets') {
        $importId = (int)($filters['id'] ?? 0);
        if ($importId <= 0 || !cmp_si_is_import($importId)) {
            throw new InvalidArgumentException('La importación actual no es Sin identificar.');
        }

        $imports = cmp_si_list_destination_imports();

        echo json_encode([
            'ok' => true,
            'imports' => $imports,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'move_nodes') {
        $importId = (int)($_POST['target_import_id'] ?? $_GET['target_import_id'] ?? 0);
        if ($importId <= 0) {
            throw new InvalidArgumentException('Falta campeonato destino.');
        }

        $nodes = cmp_si_list_destination_nodes($importId);

        echo json_encode([
            'ok' => true,
            'nodes' => $nodes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'move_match') {
        $currentImportId = (int)($filters['id'] ?? 0);
        $matchId = (int)($_POST['match_id'] ?? $_GET['match_id'] ?? 0);
        $targetImportId = (int)($_POST['target_import_id'] ?? $_GET['target_import_id'] ?? 0);
        $targetNodeId = (int)($_POST['target_node_id'] ?? $_GET['target_node_id'] ?? 0);

        if ($currentImportId <= 0 || !cmp_si_is_import($currentImportId)) {
            throw new InvalidArgumentException('La importación actual no es Sin identificar.');
        }

        cmp_si_move_match_to_import_node($matchId, $targetImportId, $targetNodeId);

        echo json_encode([
            'ok' => true,
            'message' => 'Partido movido al campeonato destino.',
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