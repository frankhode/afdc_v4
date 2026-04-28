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

$debugFile = __DIR__ . '/../tmp/contactos_debug.log';
if (!is_dir(dirname($debugFile))) {
    @mkdir(dirname($debugFile), 0777, true);
}

@file_put_contents($debugFile, "=== " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

$sourceMode = trim((string)p('source_mode', 'manual'));
$outputMode = trim((string)p('output_mode', ''));

try {
    if ($sourceMode === 'materia') {
        $materiaTexto = trim((string)p('materia_texto', ''));
        $materiaCampo = afdc_contactos_materia_campo_valido((string)p('materia_campo', 'todos'));
        $materiaExacta = (string)p('materia_exacta', '') === '1';
        $maxImagenesPorPdf = (int)p('max_images_per_pdf', '200');
        $dryRun = (string)p('dry_run', '') === '1';

        if ($materiaTexto === '') {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ingresá una materia o texto de búsqueda';
            exit;
        }

        $maxImagenesPorPdf = max(1, $maxImagenesPorPdf);

        @file_put_contents($debugFile, "Modo: materia\n", FILE_APPEND);
        @file_put_contents($debugFile, "Materia: {$materiaTexto}\n", FILE_APPEND);
        @file_put_contents($debugFile, "Campo: {$materiaCampo}\n", FILE_APPEND);
        @file_put_contents($debugFile, "Máximo imágenes PDF: {$maxImagenesPorPdf}\n", FILE_APPEND);

        $sobres = afdc_contactos_buscar_sobres_por_materia($materiaTexto, $materiaCampo, $materiaExacta);
        $estimacion = afdc_contactos_estimar_lotes_por_imagenes($sobres, $maxImagenesPorPdf);

        if ($dryRun) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'materia' => $materiaTexto,
                'campo' => $materiaCampo,
                'exacta' => $materiaExacta,
                'max_images_per_pdf' => $maxImagenesPorPdf,
                'sobres' => $estimacion['sobres'],
                'imagenes' => $estimacion['imagenes'],
                'pdfs' => $estimacion['pdfs'],
                'preview' => array_slice($sobres, 0, 20),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if (!$sobres) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No se encontraron sobres con imágenes para esa materia';
            exit;
        }

        $labelParts = ['materia', $materiaTexto];
        if ($materiaCampo !== 'todos') {
            $labelParts[] = $materiaCampo;
        }

        $label = implode('_', $labelParts);

        $result = afdc_contactos_generar_materia_loteada($sobres, $maxImagenesPorPdf, $label);
    } else {
        $entradas = trim((string)p('entradas', ''));

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

        @file_put_contents($debugFile, "Modo: manual\n", FILE_APPEND);
        @file_put_contents($debugFile, "Entradas:\n" . $entradas . "\n", FILE_APPEND);
        @file_put_contents($debugFile, "Salida: " . $outputMode . "\n", FILE_APPEND);

        $result = afdc_contactos_generar_entradas($items, $outputMode);
    }

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
    @file_put_contents($debugFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    exit;
}