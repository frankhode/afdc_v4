<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';
require_once __DIR__ . '/../inc/contactos.php';

afdc_v2_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

afdc_v2_session_start();

$csrfPost = (string)($_POST['csrf'] ?? '');
$csrfSess = (string)($_SESSION['csrf'] ?? '');

if ($csrfPost === '' || $csrfSess === '' || !hash_equals($csrfSess, $csrfPost)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * Fundamental: liberar la sesión para que estado.php pueda responder
 * mientras este request largo está procesando.
 */
session_write_close();

$jobId = trim((string)p('job_id', ''));

try {
    $job = afdc_contactos_job_read($jobId);

    if (($job['status'] ?? '') === 'running') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'message' => 'La tarea ya está en ejecución'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($job['status'] ?? '') === 'done') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'job_id' => $jobId, 'message' => 'La tarea ya terminó'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $params = $job['params'] ?? [];
    if (!is_array($params)) {
        throw new RuntimeException('La tarea no tiene parámetros válidos');
    }

    $materiaTexto = trim((string)($params['materia_texto'] ?? ''));
    $materiaCampo = afdc_contactos_materia_campo_valido((string)($params['materia_campo'] ?? 'todos'));
    $materiaExacta = !empty($params['materia_exacta']);
    $maxImagenesPorPdf = max(1, (int)($params['max_images_per_pdf'] ?? 200));

    if ($materiaTexto === '') {
        throw new RuntimeException('La tarea no tiene materia de búsqueda');
    }

    afdc_contactos_job_write($jobId, array_merge($job, [
        'ok' => true,
        'status' => 'running',
        'percent' => 1,
        'message' => 'Buscando sobres por materia...',
    ]));

    $sobres = afdc_contactos_buscar_sobres_por_materia($materiaTexto, $materiaCampo, $materiaExacta);
    $estimacion = afdc_contactos_estimar_lotes_por_imagenes($sobres, $maxImagenesPorPdf);

    if (!$sobres) {
        throw new RuntimeException('No se encontraron sobres con imágenes para esa materia');
    }

    afdc_contactos_job_write($jobId, array_merge($job, [
        'ok' => true,
        'status' => 'running',
        'percent' => 5,
        'message' => 'Se encontraron ' . $estimacion['sobres'] . ' sobres / ' . $estimacion['imagenes'] . ' imágenes. PDFs estimados: ' . $estimacion['pdfs'] . '.',
        'sobres_total' => $estimacion['sobres'],
        'imagenes_total' => $estimacion['imagenes'],
        'pdfs_total' => $estimacion['pdfs'],
    ]));

    $labelParts = ['materia', $materiaTexto];
    if ($materiaCampo !== 'todos') {
        $labelParts[] = $materiaCampo;
    }

    $label = implode('_', $labelParts);

    $progress = static function (array $state) use ($jobId, $estimacion): void {
        try {
            $current = afdc_contactos_job_read($jobId);
        } catch (Throwable $e) {
            $current = [];
        }

        $data = array_merge($current, $state);
        $data['ok'] = true;
        $data['created_at'] = $current['created_at'] ?? date('c');

        if (!isset($data['sobres_total'])) {
            $data['sobres_total'] = $estimacion['sobres'];
        }

        if (!isset($data['imagenes_total'])) {
            $data['imagenes_total'] = $estimacion['imagenes'];
        }

        if (!isset($data['pdfs_total'])) {
            $data['pdfs_total'] = $estimacion['pdfs'];
        }

        afdc_contactos_job_write($jobId, $data);
    };

    $result = afdc_contactos_generar_materia_loteada($sobres, $maxImagenesPorPdf, $label, $progress);

    $final = afdc_contactos_job_read($jobId);

    afdc_contactos_job_write($jobId, array_merge($final, [
        'ok' => true,
        'status' => 'done',
        'percent' => 100,
        'message' => 'Listo. Ya podés descargar el archivo.',
        'download_name' => (string)$result['downloadName'],
        'mime' => (string)$result['mime'],
        'file_path' => (string)$result['path'],
        'cleanup_dirs' => (array)($result['cleanupDirs'] ?? []),
    ]));

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'status' => 'done',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    try {
        $current = afdc_contactos_job_read($jobId);
    } catch (Throwable $ignored) {
        $current = [];
    }

    afdc_contactos_job_write($jobId, array_merge($current, [
        'ok' => false,
        'status' => 'error',
        'percent' => 100,
        'message' => $e->getMessage(),
    ]));

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}