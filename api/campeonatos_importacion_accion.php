<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';

function cmp_redirect_back(int $importId, ?int $nodeId = null, ?string $message = null, ?string $error = null): never {
    $params = ['id' => $importId];
    if ($nodeId !== null && $nodeId > 0) {
        $params['node_id'] = $nodeId;
    }
    if ($message !== null && $message !== '') {
        $params['msg'] = $message;
    }
    if ($error !== null && $error !== '') {
        $params['error'] = $error;
    }
    header('Location: campeonatos_importacion_editar.php?' . http_build_query($params));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$action = (string)($_POST['action'] ?? '');
$importId = (int)($_POST['import_id'] ?? 0);
$nodeId = (int)($_POST['node_id'] ?? 0);

try {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    switch ($action) {
        case 'create_node':
            $parentNodeId = (int)($_POST['parent_node_id'] ?? 0);
            $type = trim((string)($_POST['type'] ?? ''));
            $subtype = trim((string)($_POST['subtype'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $order = trim((string)($_POST['sort_order'] ?? ''));
            $newId = cmp_edit_create_node(
                $importId,
                $parentNodeId,
                $type,
                $subtype !== '' ? $subtype : null,
                $label,
                $order !== '' ? (int)$order : null
            );
            cmp_redirect_back($importId, $newId, 'Nodo creado.');
            break;

        case 'update_node':
            $nodeId = (int)($_POST['node_id'] ?? 0);
            $label = trim((string)($_POST['label'] ?? ''));
            $subtype = trim((string)($_POST['subtype'] ?? ''));
            $order = trim((string)($_POST['sort_order'] ?? ''));
            cmp_edit_update_node($nodeId, $label, $subtype !== '' ? $subtype : null, $order !== '' ? (int)$order : null);
            cmp_redirect_back($importId, $nodeId, 'Nodo actualizado.');
            break;

        case 'delete_empty_node':
            $nodeId = (int)($_POST['node_id'] ?? 0);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            cmp_edit_delete_empty_node($nodeId);
            cmp_redirect_back($importId, $parentId > 0 ? $parentId : null, 'Nodo vacío eliminado.');
            break;

        case 'update_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $home = (string)($_POST['local_texto'] ?? '');
            $glRaw = trim((string)($_POST['goles_local'] ?? ''));
            $gvRaw = trim((string)($_POST['goles_visitante'] ?? ''));
            $away = (string)($_POST['visitante_texto'] ?? '');
            $obs = trim((string)($_POST['observacion_manual'] ?? ''));
            cmp_edit_update_match(
                $matchId,
                $home,
                $glRaw !== '' ? (int)$glRaw : null,
                $gvRaw !== '' ? (int)$gvRaw : null,
                $away,
                $obs !== '' ? $obs : null
            );
            cmp_redirect_back($importId, $nodeId > 0 ? $nodeId : null, 'Partido actualizado.');
            break;

        case 'move_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            $targetNodeId = (int)($_POST['target_node_id'] ?? 0);
            cmp_edit_move_match($matchId, $targetNodeId);
            cmp_redirect_back($importId, $targetNodeId, 'Partido movido.');
            break;

        case 'ignore_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            cmp_edit_set_match_state($matchId, 'ignorado');
            cmp_redirect_back($importId, $nodeId > 0 ? $nodeId : null, 'Partido ignorado.');
            break;

        case 'restore_match':
            $matchId = (int)($_POST['match_id'] ?? 0);
            cmp_edit_set_match_state($matchId, 'activo');
            cmp_redirect_back($importId, $nodeId > 0 ? $nodeId : null, 'Partido restaurado.');
            break;

        default:
            throw new InvalidArgumentException('Acción no reconocida.');
    }
} catch (Throwable $e) {
    cmp_redirect_back($importId, $nodeId > 0 ? $nodeId : null, null, $e->getMessage());
}
