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
        'otros_sys' => trim((string)($_POST['otros_sys'] ?? '')),
        'otros_titulo' => trim((string)($_POST['otros_titulo'] ?? '')),
    ];
}
function cmp_rel_payload(array $state, string $message = ''): array {
    return [
        'ok' => true,
        'message' => $message,
        'left_tree_html' => cmp_rel_render_left_tree_html((int)$state['import_id'], (string)$state['tituloReg']),
        'right_panel_html' => cmp_rel_render_right_panel_html((int)$state['import_id'], cmp_rel_get_tituloreg_options(), (string)$state['tituloReg'], (string)$state['view'], '', (string)$state['otros_sys'], (string)$state['otros_titulo'], (string)$state['view']),
    ];
}
function cmp_rel_redirect_back(array $state, ?string $message = null, ?string $error = null): void {
    $params = ['id' => (int)$state['import_id']];
    if ($state['tituloReg'] !== '') $params['tituloReg'] = $state['tituloReg'];
    if ($state['view'] !== '') $params['view'] = $state['view'];
    if ($state['otros_sys'] !== '') $params['otros_sys'] = $state['otros_sys'];
    if ($state['otros_titulo'] !== '') $params['otros_titulo'] = $state['otros_titulo'];
    if ($message) $params['msg'] = $message;
    if ($error) $params['error'] = $error;
    header('Location: campeonatos_relacion_sobres.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Método no permitido'; exit; }
$ajax = cmp_rel_is_ajax_request();
$state = cmp_rel_state_from_request();
$action = trim((string)($_POST['action'] ?? ''));

try {
    if ($state['import_id'] <= 0) throw new InvalidArgumentException('Importación inválida.');

    switch ($action) {
        case 'assign':
            cmp_rel_assign_barcode($state['import_id'], (int)($_POST['match_id'] ?? 0), trim((string)($_POST['barcode'] ?? '')), $state['tituloReg'], trim((string)($_POST['origin'] ?? 'manual_boton')));
            $payload = cmp_rel_payload($state, 'Sobre asignado.');
            break;
        case 'save_and_assign_other':
            if ($state['tituloReg'] !== '__otros__' || $state['otros_titulo'] === '') {
                throw new InvalidArgumentException('Acción inválida fuera del modo Otros.');
            }
            $row = [
                'barcode' => trim((string)($_POST['barcode'] ?? '')),
                'tituloSobre' => trim((string)($_POST['tituloSobre'] ?? '')),
                'fecha' => trim((string)($_POST['fecha'] ?? '')),
                'equipo1' => trim((string)($_POST['equipo1'] ?? '')),
                'equipo2' => trim((string)($_POST['equipo2'] ?? '')),
            ];
            if ($row['equipo1'] === '' || $row['equipo2'] === '') {
                throw new InvalidArgumentException('Debe asignar los equipos primero.');
            }
            cmp_edit_insert_manual_partidos_for_tituloreg($state['otros_titulo'], [$row]);
            cmp_rel_assign_barcode($state['import_id'], (int)($_POST['match_id'] ?? 0), $row['barcode'], $state['otros_titulo'], 'manual_drag');
            $payload = cmp_rel_payload($state, 'Sobre guardado y asignado.');
            break;
        case 'unassign':
            cmp_rel_unassign_barcode($state['import_id'], trim((string)($_POST['barcode'] ?? '')));
            $payload = cmp_rel_payload($state, 'Sobre desasignado.');
            break;
        case 'refresh':
            $payload = cmp_rel_payload($state);
            break;
        default:
            throw new InvalidArgumentException('Acción no reconocida.');
    }

    if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
    cmp_rel_redirect_back($state, $payload['message'] ?? 'Actualizado.');
} catch (Throwable $e) {
    if ($ajax) { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
    cmp_rel_redirect_back($state, null, $e->getMessage());
}