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

$entradas   = trim((string)p('entradas', ''));
$outputMode = trim((string)p('output_mode', ''));

$items = afdc_contactos_normalizar_entradas($entradas);

if (!$items) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se recibió ninguna entrada';
    exit;
}

$allowedModes = ['jpg_per_sobre', 'pdf_per_sobre', 'pdf_lote'];
if (!in_array($outputMode, $allowedModes, true)) {
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Modo de salida inválido';
    exit;
}
$debugFile = __DIR__ . '/../tmp/contactos_debug.log';
if (!is_dir(dirname($debugFile))) {
    @mkdir(dirname($debugFile), 0777, true);
}
@file_put_contents($debugFile, "=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);
@file_put_contents($debugFile, "Entradas:\n" . $entradas . "\n", FILE_APPEND);
@file_put_contents($debugFile, "Modo: " . $outputMode . "\n", FILE_APPEND);

try {
    $result = afdc_contactos_generar_entradas($items, $outputMode);

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
    @file_put_contents($debugFile, "Antes de generar\n", FILE_APPEND);
$result = afdc_contactos_generar_entradas($items, $outputMode);
@file_put_contents($debugFile, "Despues de generar\n", FILE_APPEND);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error al generar hojas de contacto: ' . $e->getMessage();
    @file_put_contents($debugFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}