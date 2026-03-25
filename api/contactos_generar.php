<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';
require_once __DIR__ . '/../inc/contactos.php';

afdc_v2_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Método no permitido';
    exit;
}

afdc_v2_session_start();
$csrfPost = (string)($_POST['csrf'] ?? '');
$csrfSess = (string)($_SESSION['csrf'] ?? '');

if ($csrfPost === '' || $csrfSess === '' || !hash_equals($csrfSess, $csrfPost)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CSRF inválido';
    exit;
}

$barcode    = trim((string)p('barcode', ''));
$lista      = trim((string)p('lista_barcodes', ''));
$outputMode = trim((string)p('output_mode', ''));

$barcodes = afdc_contactos_normalizar_barcodes($barcode, $lista);

if (!$barcodes) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se recibió ningún barcode';
    exit;
}

$allowedModes = ['jpg_per_sobre', 'pdf_per_sobre', 'pdf_lote'];
if (!in_array($outputMode, $allowedModes, true)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Modo de salida inválido';
    exit;
}

try {
    $result = afdc_contactos_generar($barcodes, $outputMode);

    $file = (string)$result['path'];
    $name = (string)$result['downloadName'];
    $mime = (string)$result['mime'];
    $cleanupDirs = (array)($result['cleanupDirs'] ?? []);

    register_shutdown_function(static function () use ($cleanupDirs) {
        foreach ($cleanupDirs as $dir) {
            if (is_string($dir) && $dir !== '') {
                afdc_contactos_rrmdir($dir);
            }
        }
    });

    if (!is_file($file)) {
        throw new RuntimeException('El archivo generado no existe');
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
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error al generar hojas de contacto: ' . $e->getMessage();
    exit;
}