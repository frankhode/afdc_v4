<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_relacion_sobres_repo.php';

function cmp_rel_is_ajax_request(): bool {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || (($_POST['ajax'] ?? '') === '1');
}

function cmp_rel_state_from_request(): array {
    return [
        'import_id' => (int)($_POST['import_id'] ?? 0),
        'tituloReg' => trim((string)($_POST['tituloReg'] ?? '')),
        'view' => trim((string)($_POST['view'] ?? 'pending')),
        'q_sobres' => trim((string)($_POST['q_sobres'] ?? '')),
    ];
}

function cmp_rel_payload(int $importId, string $tituloReg, string $view, string $qSobres, string $message = ''): array {
    return [
        'ok' => true,
        'message' => $message,
        'left_tree_html' => cmp_rel_render_left_tree_html($importId, $tituloReg),
        'right_panel_html' => cmp_rel_render_right_panel_html($importId, cmp_rel_get_tituloreg_options(), $tituloReg, $view, $qSobres),
    ];
}

function cmp_rel_redirect_back(int $importId, string $tituloReg = '', string $view = 'pending', string $qSobres = '', ?string $message = null, ?string $error = null): void {
    $params = ['id' => $importId];
    if ($tituloReg !== '') $params['tituloReg'] = $tituloReg;
    if ($view !== '') $params['view'] = $view;
    if ($qSobres !== '') $params['q_sobres'] = $qSobres;
    if ($message !== null && $message !== '') $params['msg'] = $message;
    if ($error !== null && $error !== '') $params['error'] = $error;
    header('Location: campeonatos_relacion_sobres.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$ajax = cmp_rel_is_ajax_request();
$state = cmp_rel_state_from_request();
$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($state['import_id'] <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    switch ($action) {
        case 'assign':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            $origin = trim((string)($_POST['origin'] ?? 'manual_boton'));
            cmp_rel_assign_barcode($state['import_id'], $matchId, $barcode, $state['tituloReg'], $origin);
            $payload = cmp_rel_payload($state['import_id'], $state['tituloReg'], $state['view'], $state['q_sobres'], 'Sobre asignado.');
            break;

        case 'unassign':
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            cmp_rel_unassign_barcode($state['import_id'], $barcode);
            $payload = cmp_rel_payload($state['import_id'], $state['tituloReg'], $state['view'], $state['q_sobres'], 'Sobre desasignado.');
            break;

        case 'refresh':
            $payload = cmp_rel_payload($state['import_id'], $state['tituloReg'], $state['view'], $state['q_sobres']);
            break;

        default:
            throw new InvalidArgumentException('Acción no reconocida.');
    }

    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    cmp_rel_redirect_back($state['import_id'], $state['tituloReg'], $state['view'], $state['q_sobres'], $payload['message'] ?? 'Actualizado.');
} catch (Throwable $e) {
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    cmp_rel_redirect_back($state['import_id'], $state['tituloReg'], $state['view'], $state['q_sobres'], null, $e->getMessage());
}
