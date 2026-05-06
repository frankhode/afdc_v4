<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/campeonatos_sin_identificar_repo.php';
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_visor_repo.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));
    $filters = cmp_visor_filters_from_request();
    
    if ($action === 'filters') {
        $selectedId = (int)($filters['id'] ?? 0);
        $selectedYear = trim((string)($filters['year'] ?? ''));
        $selectedTeam1 = trim((string)($filters['team1'] ?? ''));

        if ($selectedId > 0) {
            $selectedImport = cmp_edit_get_import($selectedId);
            if ($selectedImport) {
                $importYear = trim((string)($selectedImport['temporada_detectada'] ?? ''));
                if ($importYear !== '') {
                    $selectedYear = $importYear;
                }
            } else {
                $selectedId = 0;
            }
        }

        $optionFilters = [
            'year' => $selectedYear,
            'team1' => $selectedTeam1,
            'team2' => '',
            'id' => 0,
            'node_id' => 0,
            'only_linked' => '0',
        ];

        $imports = cmp_visor_list_imports($optionFilters);

        if ($selectedId > 0) {
            $validSelectedId = false;
            foreach ($imports as $row) {
                if ((int)$row['id'] === $selectedId) {
                    $validSelectedId = true;
                    break;
                }
            }

            if (!$validSelectedId) {
                $selectedId = 0;
            }
        }

        $filtersForLists = [
            'year' => $selectedYear,
            'team1' => $selectedTeam1,
            'team2' => '',
            'id' => $selectedId,
            'node_id' => 0,
            'only_linked' => '0',
        ];

        $years = cmp_visor_list_years_for_filters($filtersForLists);
        $teams = cmp_visor_list_teams_for_filters($filtersForLists);

        echo json_encode([
            'ok' => true,
            'selected_year' => $selectedYear,
            'selected_id' => $selectedId,
            'selected_team1' => $selectedTeam1,
            'years_html' => cmp_visor_render_year_options_html($years, $selectedYear),
            'imports_html' => cmp_visor_render_import_options_html($imports, $selectedId),
            'teams_html' => cmp_visor_render_team_options_html($teams, $selectedTeam1),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
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