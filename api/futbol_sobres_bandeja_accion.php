<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/futbol_sobres_clasificacion_repo.php';

function fsb_redirect(array $params = []): void {
    $base = 'futbol_sobres_bandeja.php';
    $query = http_build_query(array_filter($params, static fn($v) => $v !== null && $v !== ''));
    header('Location: ' . $base . ($query !== '' ? '?' . $query : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Método no permitido';
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$returnQ = trim((string)($_POST['return_q'] ?? ''));
$returnEstado = trim((string)($_POST['return_estado'] ?? ''));

try {
    switch ($action) {
        case 'add':
            $barcode = trim((string)($_POST['barcode'] ?? ''));
            fsb_agregar_a_bandeja($barcode);
            fsb_redirect([
                'q' => $returnQ,
                'estado' => $returnEstado,
                'msg' => 'Sobre agregado a la bandeja.',
            ]);

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            fsb_actualizar_clasificacion($id, [
                'estado' => $_POST['estado'] ?? '',
                'equipo1_texto' => $_POST['equipo1_texto'] ?? '',
                'equipo2_texto' => $_POST['equipo2_texto'] ?? '',
                'equipo_principal_texto' => $_POST['equipo_principal_texto'] ?? '',
                'fecha_sugerida' => $_POST['fecha_sugerida'] ?? '',
                'fecha_precision' => $_POST['fecha_precision'] ?? '',
                'campeonato_sugerido_texto' => $_POST['campeonato_sugerido_texto'] ?? '',
                'notas' => $_POST['notas'] ?? '',
            ]);
            fsb_redirect([
                'q' => $returnQ,
                'estado' => $returnEstado,
                'msg' => 'Clasificación actualizada.',
            ]);
        case 'autocomplete':
            $id = (int)($_POST['id'] ?? 0);
            fsb_autocompletar_desde_existente($id);
            fsb_redirect([
                'q' => $returnQ,
                'estado' => $returnEstado,
                'msg' => 'Clasificación autocompletada desde datos existentes.',
            ]);
        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            fsb_eliminar_de_bandeja($id);
            fsb_redirect([
                'q' => $returnQ,
                'estado' => $returnEstado,
                'msg' => 'Sobre quitado de la bandeja.',
            ]);

        default:
            throw new InvalidArgumentException('Acción no reconocida.');
    }
} catch (Throwable $e) {
    fsb_redirect([
        'q' => $returnQ,
        'estado' => $returnEstado,
        'error' => $e->getMessage(),
    ]);
}