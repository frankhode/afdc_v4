<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';
require_once __DIR__ . '/../inc/contactos.php';

afdc_v2_require_admin();

$jobId = trim((string)g('id', ''));

try {
    $data = afdc_contactos_job_read($jobId);

    if (($data['status'] ?? '') !== 'done') {
        throw new RuntimeException('La tarea todavía no terminó');
    }

    $file = (string)($data['file_path'] ?? '');
    $name = (string)($data['download_name'] ?? 'contactos.pdf');
    $mime = (string)($data['mime'] ?? 'application/octet-stream');

    if ($file === '' || !is_file($file)) {
        throw new RuntimeException('El archivo generado ya no está disponible');
    }

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($name) . '"');
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: public');

    readfile($file);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo descargar: ' . $e->getMessage();
    exit;
}