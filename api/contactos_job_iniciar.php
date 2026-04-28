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

afdc_v2_session_start();

$csrfPost = (string)($_POST['csrf'] ?? '');
$csrfSess = (string)($_SESSION['csrf'] ?? '');

if ($csrfPost === '' || $csrfSess === '' || !hash_equals($csrfSess, $csrfPost)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'CSRF inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

afdc_contactos_jobs_cleanup_old(24);

$materiaTexto = trim((string)p('materia_texto', ''));
$materiaCampo = afdc_contactos_materia_campo_valido((string)p('materia_campo', 'todos'));
$materiaExacta = (string)p('materia_exacta', '') === '1';
$maxImagenesPorPdf = max(1, (int)p('max_images_per_pdf', '200'));

if ($materiaTexto === '') {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Ingresá una materia o texto de búsqueda'], JSON_UNESCAPED_UNICODE);
    exit;
}

$jobId = afdc_contactos_job_id();

afdc_contactos_job_write($jobId, [
    'ok' => true,
    'status' => 'created',
    'percent' => 0,
    'message' => 'Tarea creada. Esperando inicio...',
    'created_at' => date('c'),
    'params' => [
        'materia_texto' => $materiaTexto,
        'materia_campo' => $materiaCampo,
        'materia_exacta' => $materiaExacta,
        'max_images_per_pdf' => $maxImagenesPorPdf,
    ],
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'job_id' => $jobId,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;