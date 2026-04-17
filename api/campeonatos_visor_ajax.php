<?php
declare(strict_types=1);

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

    throw new InvalidArgumentException('Acción inválida.');
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}