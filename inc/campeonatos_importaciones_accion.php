<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';

cmp_require_bootstrap_if_available();

require_once __DIR__ . '/../inc/campeonatos_import_dashboard_repo.php';

function cmp_dashboard_redirect(?string $msg = null, ?string $error = null): never {
    $params = [];

    $preserve = ['q', 'temporada', 'estado', 'fuente_tipo'];
    foreach ($preserve as $key) {
        $value = trim((string)($_POST[$key] ?? $_GET[$key] ?? ''));
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    if ($msg !== null && $msg !== '') {
        $params['msg'] = $msg;
    }

    if ($error !== null && $error !== '') {
        $params['error'] = $error;
    }

    header('Location: campeonatos_importaciones.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$importId = (int)($_POST['import_id'] ?? 0);

try {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    switch ($action) {
        case 'set_state':
            $newState = trim((string)($_POST['new_state'] ?? ''));
            cmp_dashboard_import_update_state($importId, $newState);
            cmp_dashboard_redirect('Estado actualizado.');
            break;

        case 'soft_delete':
            cmp_dashboard_import_soft_delete($importId);
            cmp_dashboard_redirect('Importación ocultada.');
            break;

        default:
            throw new InvalidArgumentException('Acción no reconocida.');
    }
} catch (Throwable $e) {
    cmp_dashboard_redirect(null, $e->getMessage());
}