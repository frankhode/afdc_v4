<?php
declare(strict_types=1);

/**
 * Hojas de contacto AFDC
 * - Fuente inicial: solo Bajas
 * - Salidas:
 *   - jpg_per_sobre
 *   - pdf_per_sobre
 *   - pdf_lote
 *
 * En esta versión las imágenes se cargan desde:
 * - barcode AFDC (Bajas vía URL pública)
 * - ruta local de carpeta en Windows (solo JPG de primer nivel)
 */

if (!defined('AFDC_CONTACTOS_A4_W')) define('AFDC_CONTACTOS_A4_W', 1754); // A4 landscape ~150dpi
if (!defined('AFDC_CONTACTOS_A4_H')) define('AFDC_CONTACTOS_A4_H', 1240);

function afdc_contactos_debug_file(): string
{
    $dir = __DIR__ . '/../tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/contactos_debug.log';
}

function afdc_contactos_debug(string $message): void
{
    $file = afdc_contactos_debug_file();
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

function afdc_contactos_slug(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^A-Za-z0-9._-]+/', '_', $s) ?? 'archivo';
    $s = trim($s, '_');
    return $s !== '' ? $s : 'archivo';
}

function afdc_contactos_temp_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'afdc_contactos_' . bin2hex(random_bytes(6));
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear el directorio temporal');
    }
    return $dir;
}

function afdc_contactos_rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    if (!$items) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) afdc_contactos_rrmdir($path);
        else @unlink($path);
    }
    @rmdir($dir);
}

function afdc_contactos_normalizar_barcodes(?string $barcode, ?string $lista): array
{
    $out = [];

    $barcode = trim((string)$barcode);
    if ($barcode !== '') {
        $out[] = $barcode;
    }

    $lista = str_replace(["\r\n", "\r"], "\n", (string)$lista);
    foreach (preg_split('/[\n,;]+/', $lista) ?: [] as $part) {
        $part = trim($part);
        if ($part !== '') $out[] = $part;
    }

    $clean = [];
    $seen = [];
    foreach ($out as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (!isset($seen[$b])) {
            $seen[$b] = true;
            $clean[] = $b;
        }
    }
    return $clean;
}

function afdc_contactos_normalizar_entradas(?string $texto): array
{
    $texto = str_replace(["\r\n", "\r"], "\n", (string)$texto);
    $out = [];
    $seen = [];

    foreach (explode("\n", $texto) as $linea) {
        $linea = trim($linea);
        if ($linea === '') {
            continue;
        }

        if (!isset($seen[$linea])) {
            $seen[$linea] = true;
            $out[] = $linea;
        }
    }

    return $out;
}

function afdc_contactos_es_ruta_local(string $entrada): bool
{
    $entrada = trim($entrada);

    if ($entrada === '') {
        return false;
    }

    if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $entrada)) {
        return true;
    }

    if (str_starts_with($entrada, '\\\\')) {
        return true;
    }

    return false;
}

function afdc_contactos_etiqueta_desde_ruta(string $ruta): string
{
    $ruta = rtrim(str_replace('\\', '/', trim($ruta)), '/');
    $base = basename($ruta);
    return $base !== '' ? $base : 'CARPETA_LOCAL';
}

function afdc_contactos_public_bajas_base_url(): string
{
    return rtrim((string)BASE_URL, '/') . '/bajas';
}

function afdc_contactos_fetch_binary(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $data = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (is_string($data) && $data !== '' && $code >= 200 && $code < 300) {
            return $data;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 30,
            'follow_location' => 1,
        ],
    ]);

    $data = @file_get_contents($url, false, $ctx);
    return is_string($data) ? $data : '';
}

function afdc_contactos_image_from_source(string $source)
{
    if (preg_match('#^https?://#i', $source)) {
        $bin = afdc_contactos_fetch_binary($source);
        if ($bin === '') return null;
        return @imagecreatefromstring($bin);
    }

    $info = @getimagesize($source);
    if (!$info || empty($info[2])) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($source);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($source);
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($source);
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : null;
        case IMAGETYPE_BMP:
            return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($source) : null;
        default:
            return null;
    }
}

function afdc_contactos_ellipsis(string $text, int $maxChars = 28): string
{
    $text = trim($text);
    if (mb_strlen($text, 'UTF-8') <= $maxChars) return $text;
    return rtrim(mb_substr($text, 0, $maxChars - 3, 'UTF-8')) . '...';
}

function afdc_contactos_write_text($img, int $x, int $y, string $text, int $color): void
{
    imagestring($img, 4, $x, $y, $text, $color);
}

function afdc_contactos_resolver_imagenes_por_barcode(string $barcode): array
{
    $rows = q(
        "SELECT nombramiento, inv, cajon, carpeta
           FROM digitales
          WHERE inv = ?
            AND (carpeta = 'Bajas' OR carpeta LIKE '%Bajas%')
          ORDER BY nombramiento ASC",
        "s",
        [$barcode]
    );

    if (!$rows) {
        return [];
    }

    $baseUrl = afdc_contactos_public_bajas_base_url();
    $items = [];

    foreach ($rows as $r) {
        $nom = trim((string)($r['nombramiento'] ?? ''));
        $inv = trim((string)($r['inv'] ?? ''));
        $cajon = trim((string)($r['cajon'] ?? ''));

        if ($nom === '' || $inv === '' || $cajon === '') {
            continue;
        }

        $url = $baseUrl
            . '/' . rawurlencode($cajon)
            . '/' . rawurlencode($inv)
            . '/' . rawurlencode($nom);

        $items[] = [
            'barcode'      => $barcode,
            'nombramiento' => $nom,
            'source'       => $url,
            'ext'          => strtolower((string)pathinfo($nom, PATHINFO_EXTENSION)),
        ];
    }

    return $items;
}

function afdc_contactos_resolver_imagenes_desde_ruta(string $ruta): array
{
    $rutaOriginal = trim($ruta);
    afdc_contactos_debug('Resolver ruta local (raw): ' . $rutaOriginal);

    if ($rutaOriginal === '') {
        afdc_contactos_debug('Ruta vacía');
        return [];
    }

    $rutaReparada = afdc_contactos_reparar_mojibake_utf8($rutaOriginal);
    if ($rutaReparada !== $rutaOriginal) {
        afdc_contactos_debug('Ruta reparada UTF-8: ' . $rutaReparada);
    }

    $rutaFs = afdc_contactos_normalizar_ruta_windows($rutaReparada);
    if ($rutaFs !== $rutaReparada) {
        afdc_contactos_debug('Ruta convertida para filesystem: ' . $rutaFs);
    }

    if (!@is_dir($rutaFs)) {
        afdc_contactos_debug('No es directorio o no existe: ' . $rutaFs);
        return [];
    }

    $label = afdc_contactos_etiqueta_desde_ruta($rutaReparada);
    $items = [];

    $scan = @scandir($rutaFs);
    if ($scan === false) {
        afdc_contactos_debug('scandir() devolvió false para: ' . $rutaFs);
        return [];
    }

    foreach ($scan as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $full = rtrim($rutaFs, "\\/") . DIRECTORY_SEPARATOR . $name;

        if (!@is_file($full)) {
            continue;
        }

        if (!preg_match('/\.jpg$/i', $name)) {
            continue;
        }

        $type = function_exists('exif_imagetype') ? @exif_imagetype($full) : 0;
        if ($type !== IMAGETYPE_JPEG) {
            afdc_contactos_debug('Se omitió archivo no JPEG válido: ' . $full);
            continue;
        }

        $items[] = [
            'barcode'      => $label,
            'nombramiento' => $name,
            'source'       => $full,
            'ext'          => 'jpg',
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strnatcasecmp((string)$a['nombramiento'], (string)$b['nombramiento']);
    });

    afdc_contactos_debug('Ruta local OK: ' . $rutaFs . ' | JPG válidos: ' . count($items));
    return $items;
}

function afdc_contactos_render_sheet_pages(string $barcode, array $items, string $tempDir, string $theme = 'dark'): array
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('La extensión GD no está disponible en PHP');
    }

    $pageW = AFDC_CONTACTOS_A4_W;
    $pageH = AFDC_CONTACTOS_A4_H;

    $marginX = 40;
    $marginTop = 42;
    $headerH = 68;
    $footerH = 28;
    $gutterX = 18;
    $gutterY = 26;

    $cols = 5;
    $rows = 4;
    $perPage = $cols * $rows;

    $usableW = $pageW - ($marginX * 2) - ($gutterX * ($cols - 1));
    $cellW = (int)floor($usableW / $cols);

    $gridTop = $marginTop + $headerH;
    $usableH = $pageH - $gridTop - $footerH - ($gutterY * ($rows - 1)) - 10;
    $cellH = (int)floor($usableH / $rows);

    $labelH = 20;
    $imgBoxH = $cellH - $labelH - 8;

    $pages = [];
    $warnings = [];
    $total = count($items);
    $chunks = array_chunk($items, $perPage);

    afdc_contactos_debug('Render páginas para [' . $barcode . '] | imágenes: ' . $total . ' | páginas esperadas: ' . count($chunks));

    foreach ($chunks as $pageIdx => $chunk) {
        $im = imagecreatetruecolor($pageW, $pageH);
        if (!$im) {
            throw new RuntimeException('No se pudo crear el lienzo GD');
        }

        if ($theme === 'light') {
            $bg        = imagecolorallocate($im, 255, 255, 255);
            $fg        = imagecolorallocate($im, 20, 20, 20);
            $muted     = imagecolorallocate($im, 90, 90, 90);
            $thumbBg   = imagecolorallocate($im, 245, 245, 245);
            $thumbLine = imagecolorallocate($im, 120, 120, 120);
            $rule      = imagecolorallocate($im, 200, 200, 200);
        } else {
            $bg        = imagecolorallocate($im, 0, 0, 0);
            $fg        = imagecolorallocate($im, 255, 255, 255);
            $muted     = imagecolorallocate($im, 180, 180, 180);
            $thumbBg   = imagecolorallocate($im, 35, 35, 35);
            $thumbLine = imagecolorallocate($im, 255, 255, 255);
            $rule      = imagecolorallocate($im, 70, 70, 70);
        }

        imagefilledrectangle($im, 0, 0, $pageW, $pageH, $bg);

        afdc_contactos_write_text($im, $marginX, $marginTop, 'AFDC - Hoja de contacto', $fg);
        afdc_contactos_write_text($im, $marginX, $marginTop + 22, 'Barcode: ' . $barcode . '   |   Imágenes: ' . $total, $muted);
        afdc_contactos_write_text($im, $pageW - 150, $marginTop + 22, 'Página ' . ($pageIdx + 1), $muted);
        imageline($im, $marginX, $marginTop + 52, $pageW - $marginX, $marginTop + 52, $rule);

        foreach ($chunk as $i => $item) {
            $pos = $i;
            $col = $pos % $cols;
            $row = intdiv($pos, $cols);

            $x = $marginX + ($col * ($cellW + $gutterX));
            $y = $gridTop + ($row * ($cellH + $gutterY));

            $boxX1 = $x;
            $boxY1 = $y;
            $boxX2 = $x + $cellW;
            $boxY2 = $y + $imgBoxH;

            imagefilledrectangle($im, $boxX1, $boxY1, $boxX2, $boxY2, $thumbBg);
            imagerectangle($im, $boxX1, $boxY1, $boxX2, $boxY2, $thumbLine);

            $src = afdc_contactos_image_from_source((string)$item['source']);
            if (!$src) {
                $warnings[] = '[' . $barcode . '] No se pudo cargar la imagen: ' . $item['nombramiento'];
                afdc_contactos_write_text($im, $x + 10, $y + 10, 'No compatible', $muted);
            } else {
                $sw = imagesx($src);
                $sh = imagesy($src);

                if ($sw > 0 && $sh > 0) {
                    $fitW = $cellW - 8;
                    $fitH = $imgBoxH - 8;

                    $ratio = min($fitW / $sw, $fitH / $sh);
                    $dw = max(1, (int)floor($sw * $ratio));
                    $dh = max(1, (int)floor($sh * $ratio));
                    $dx = $x + (int)floor(($cellW - $dw) / 2);
                    $dy = $y + (int)floor(($imgBoxH - $dh) / 2);

                    imagecopyresampled($im, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
                }
                imagedestroy($src);
            }

            $label = afdc_contactos_ellipsis((string)$item['nombramiento'], 30);
            afdc_contactos_write_text($im, $x + 2, $y + $imgBoxH + 6, $label, $fg);
        }

        $file = $tempDir . DIRECTORY_SEPARATOR
              . afdc_contactos_slug($barcode)
              . '_contacto_'
              . str_pad((string)($pageIdx + 1), 2, '0', STR_PAD_LEFT)
              . '.jpg';

        imagejpeg($im, $file, 90);
        imagedestroy($im);
        $pages[] = $file;
    }

    afdc_contactos_debug('Render finalizado para [' . $barcode . '] | páginas generadas: ' . count($pages));

    return [
        'pages'    => $pages,
        'warnings' => $warnings,
    ];
}

function afdc_contactos_zip(array $entries, string $zipPath): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive no está disponible en PHP');
    }

    $zip = new ZipArchive();
    $ok = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($ok !== true) {
        throw new RuntimeException('No se pudo crear el ZIP');
    }

    foreach ($entries as $entry) {
        $zip->addFile($entry['path'], $entry['name']);
    }

    $zip->close();
}

function afdc_contactos_build_pdf_from_jpegs(array $jpegFiles, string $pdfPath): void
{
    afdc_contactos_debug('Construyendo PDF: ' . $pdfPath . ' | páginas JPEG: ' . count($jpegFiles));

    if (!$jpegFiles) {
        throw new RuntimeException('No hay páginas JPEG para generar PDF');
    }

    $pageW = 841.89; // A4 landscape points
    $pageH = 595.28;

    $objects = [];
    $addObj = function (string $data) use (&$objects): int {
        $objects[] = $data;
        return count($objects);
    };

    $pageIds = [];
    $imgObjIds = [];
    $contentObjIds = [];

    foreach ($jpegFiles as $idx => $jpegPath) {
        $jpegData = @file_get_contents($jpegPath);
        if ($jpegData === false) {
            throw new RuntimeException('No se pudo leer la página JPEG temporal');
        }

        $info = @getimagesize($jpegPath);
        if (!$info) {
            throw new RuntimeException('No se pudo inspeccionar la página JPEG temporal');
        }

        $w = (int)$info[0];
        $h = (int)$info[1];

        $imgObj = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpegData) . " >>\nstream\n";
        $imgObj .= $jpegData . "\nendstream";
        $imgObjIds[$idx] = $addObj($imgObj);

        $stream = "q\n{$pageW} 0 0 {$pageH} 0 0 cm\n/Im" . ($idx + 1) . " Do\nQ";
        $contentObjIds[$idx] = $addObj("<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream");
    }

    $pagesKids = [];
    $pagesRootId = $addObj('<< >>'); // placeholder

    foreach ($jpegFiles as $idx => $_) {
        $pageObj = "<< /Type /Page /Parent {$pagesRootId} 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /XObject << /Im" . ($idx + 1) . " " . $imgObjIds[$idx] . " 0 R >> >> /Contents " . $contentObjIds[$idx] . " 0 R >>";
        $pageIds[$idx] = $addObj($pageObj);
        $pagesKids[] = $pageIds[$idx] . ' 0 R';
    }

    $objects[$pagesRootId - 1] = "<< /Type /Pages /Count " . count($pageIds) . " /Kids [" . implode(' ', $pagesKids) . "] >>";
    $catalogId = $addObj("<< /Type /Catalog /Pages {$pagesRootId} 0 R >>");

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $i => $obj) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    $written = @file_put_contents($pdfPath, $pdf);
    if ($written === false) {
        throw new RuntimeException('No se pudo escribir el PDF final');
    }

    afdc_contactos_debug('PDF escrito: ' . $pdfPath . ' | bytes: ' . $written);
}

function afdc_contactos_manifest_text(array $warnings): string
{
    if (!$warnings) return "Sin observaciones.\n";
    return implode("\n", $warnings) . "\n";
}

function afdc_contactos_generar(array $barcodes, string $outputMode): array
{
    if (!$barcodes) {
        throw new RuntimeException('No se recibieron barcodes');
    }

    $tempDir = afdc_contactos_temp_dir();
    $cleanup = [$tempDir];
    $warnings = [];
    $pagesByBarcode = [];

    afdc_contactos_debug('afdc_contactos_generar() | barcodes: ' . count($barcodes) . ' | modo: ' . $outputMode);

    foreach ($barcodes as $barcode) {
        $items = afdc_contactos_resolver_imagenes_por_barcode($barcode);
        if (!$items) {
            $warnings[] = '[' . $barcode . '] Sin imágenes en Bajas';
            continue;
        }

        $theme = ($outputMode === 'jpg_per_sobre') ? 'dark' : 'light';
        $render = afdc_contactos_render_sheet_pages($barcode, $items, $tempDir, $theme);
        $pages = $render['pages'] ?? [];
        $warnings = array_merge($warnings, $render['warnings'] ?? []);

        if ($pages) {
            $pagesByBarcode[$barcode] = $pages;
        } else {
            $warnings[] = '[' . $barcode . '] No se pudieron generar páginas';
        }
    }

    if (!$pagesByBarcode) {
        afdc_contactos_rrmdir($tempDir);
        throw new RuntimeException('No se pudo generar ninguna hoja de contacto');
    }

    $stamp = date('Ymd_His');

    if ($outputMode === 'jpg_per_sobre') {
        if (count($pagesByBarcode) === 1) {
            $barcode = array_key_first($pagesByBarcode);
            $pages = $pagesByBarcode[$barcode];

            if (count($pages) === 1) {
                return [
                    'path'         => $pages[0],
                    'downloadName' => afdc_contactos_slug((string)$barcode) . '_contacto_01.jpg',
                    'mime'         => 'image/jpeg',
                    'cleanupDirs'  => $cleanup,
                ];
            }
        }

        $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_jpg_' . $stamp . '.zip';
        $entries = [];

        foreach ($pagesByBarcode as $barcode => $pages) {
            foreach ($pages as $pagePath) {
                $entries[] = [
                    'path' => $pagePath,
                    'name' => afdc_contactos_slug((string)$barcode) . '/' . basename($pagePath),
                ];
            }
        }

        $manifest = $tempDir . DIRECTORY_SEPARATOR . 'errores.txt';
        file_put_contents($manifest, afdc_contactos_manifest_text($warnings));
        $entries[] = ['path' => $manifest, 'name' => 'errores.txt'];

        afdc_contactos_zip($entries, $zipPath);

        return [
            'path'         => $zipPath,
            'downloadName' => 'contactos_jpg_' . $stamp . '.zip',
            'mime'         => 'application/zip',
            'cleanupDirs'  => $cleanup,
        ];
    }

    if ($outputMode === 'pdf_per_sobre') {
        if (count($pagesByBarcode) === 1) {
            $barcode = array_key_first($pagesByBarcode);
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . afdc_contactos_slug((string)$barcode) . '_contacto.pdf';
            afdc_contactos_build_pdf_from_jpegs($pagesByBarcode[$barcode], $pdfPath);

            return [
                'path'         => $pdfPath,
                'downloadName' => afdc_contactos_slug((string)$barcode) . '_contacto.pdf',
                'mime'         => 'application/pdf',
                'cleanupDirs'  => $cleanup,
            ];
        }

        $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_pdf_' . $stamp . '.zip';
        $entries = [];

        foreach ($pagesByBarcode as $barcode => $pages) {
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . afdc_contactos_slug((string)$barcode) . '_contacto.pdf';
            afdc_contactos_build_pdf_from_jpegs($pages, $pdfPath);
            $entries[] = [
                'path' => $pdfPath,
                'name' => afdc_contactos_slug((string)$barcode) . '_contacto.pdf',
            ];
        }

        $manifest = $tempDir . DIRECTORY_SEPARATOR . 'errores.txt';
        file_put_contents($manifest, afdc_contactos_manifest_text($warnings));
        $entries[] = ['path' => $manifest, 'name' => 'errores.txt'];

        afdc_contactos_zip($entries, $zipPath);

        return [
            'path'         => $zipPath,
            'downloadName' => 'contactos_pdf_' . $stamp . '.zip',
            'mime'         => 'application/zip',
            'cleanupDirs'  => $cleanup,
        ];
    }

    if ($outputMode === 'pdf_lote') {
        $allPages = [];
        foreach ($pagesByBarcode as $barcode => $pages) {
            foreach ($pages as $pagePath) {
                $allPages[] = $pagePath;
            }
        }

        $pdfPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_lote_' . $stamp . '.pdf';
        afdc_contactos_build_pdf_from_jpegs($allPages, $pdfPath);

        return [
            'path'         => $pdfPath,
            'downloadName' => 'contactos_lote_' . $stamp . '.pdf',
            'mime'         => 'application/pdf',
            'cleanupDirs'  => $cleanup,
        ];
    }

    afdc_contactos_rrmdir($tempDir);
    throw new RuntimeException('Modo de salida inválido');
}

function afdc_contactos_generar_entradas(array $entradas, string $outputMode): array
{
    if (!$entradas) {
        throw new RuntimeException('No se recibieron entradas');
    }

    $tempDir = afdc_contactos_temp_dir();
    $cleanup = [$tempDir];
    $warnings = [];
    $pagesByKey = [];

    afdc_contactos_debug('afdc_contactos_generar_entradas() | entradas: ' . count($entradas) . ' | modo: ' . $outputMode);

    foreach ($entradas as $entrada) {
        $entrada = trim((string)$entrada);
        if ($entrada === '') {
            continue;
        }

        afdc_contactos_debug('Procesando entrada: ' . $entrada);

        if (afdc_contactos_es_ruta_local($entrada)) {
            $key = afdc_contactos_etiqueta_desde_ruta($entrada);
            $items = afdc_contactos_resolver_imagenes_desde_ruta($entrada);

            afdc_contactos_debug('Entrada interpretada como ruta local | key: ' . $key . ' | JPG válidos: ' . count($items));

            if (!$items) {
                $warnings[] = '[' . $key . '] Sin JPG válidos en la carpeta';
                continue;
            }
        } else {
            $key = $entrada;
            $items = afdc_contactos_resolver_imagenes_por_barcode($entrada);

            afdc_contactos_debug('Entrada interpretada como barcode | key: ' . $key . ' | imágenes: ' . count($items));

            if (!$items) {
                $warnings[] = '[' . $entrada . '] Sin imágenes en Bajas';
                continue;
            }
        }

        $theme = ($outputMode === 'jpg_per_sobre') ? 'dark' : 'light';
        $render = afdc_contactos_render_sheet_pages($key, $items, $tempDir, $theme);
        $pages = $render['pages'] ?? [];
        $warnings = array_merge($warnings, $render['warnings'] ?? []);

        afdc_contactos_debug('Páginas generadas para [' . $key . ']: ' . count($pages));

        if ($pages) {
            $pagesByKey[$key] = $pages;
        } else {
            $warnings[] = '[' . $key . '] No se pudieron generar páginas';
        }
    }

    if (!$pagesByKey) {
        afdc_contactos_rrmdir($tempDir);
        throw new RuntimeException('No se pudo generar ninguna hoja de contacto');
    }

    $stamp = date('Ymd_His');

    if ($outputMode === 'jpg_per_sobre') {
        if (count($pagesByKey) === 1) {
            $key = array_key_first($pagesByKey);
            $pages = $pagesByKey[$key];

            if (count($pages) === 1) {
                return [
                    'path'         => $pages[0],
                    'downloadName' => afdc_contactos_slug((string)$key) . '_contacto_01.jpg',
                    'mime'         => 'image/jpeg',
                    'cleanupDirs'  => $cleanup,
                ];
            }
        }

        $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_jpg_' . $stamp . '.zip';
        $entries = [];

        foreach ($pagesByKey as $key => $pages) {
            foreach ($pages as $pagePath) {
                $entries[] = [
                    'path' => $pagePath,
                    'name' => afdc_contactos_slug((string)$key) . '/' . basename($pagePath),
                ];
            }
        }

        $manifest = $tempDir . DIRECTORY_SEPARATOR . 'errores.txt';
        file_put_contents($manifest, afdc_contactos_manifest_text($warnings));
        $entries[] = ['path' => $manifest, 'name' => 'errores.txt'];

        afdc_contactos_zip($entries, $zipPath);

        return [
            'path'         => $zipPath,
            'downloadName' => 'contactos_jpg_' . $stamp . '.zip',
            'mime'         => 'application/zip',
            'cleanupDirs'  => $cleanup,
        ];
    }

    if ($outputMode === 'pdf_per_sobre') {
        if (count($pagesByKey) === 1) {
            $key = array_key_first($pagesByKey);
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . afdc_contactos_slug((string)$key) . '_contacto.pdf';
            afdc_contactos_build_pdf_from_jpegs($pagesByKey[$key], $pdfPath);

            return [
                'path'         => $pdfPath,
                'downloadName' => afdc_contactos_slug((string)$key) . '_contacto.pdf',
                'mime'         => 'application/pdf',
                'cleanupDirs'  => $cleanup,
            ];
        }

        $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_pdf_' . $stamp . '.zip';
        $entries = [];

        foreach ($pagesByKey as $key => $pages) {
            $pdfPath = $tempDir . DIRECTORY_SEPARATOR . afdc_contactos_slug((string)$key) . '_contacto.pdf';
            afdc_contactos_build_pdf_from_jpegs($pages, $pdfPath);
            $entries[] = [
                'path' => $pdfPath,
                'name' => afdc_contactos_slug((string)$key) . '_contacto.pdf',
            ];
        }

        $manifest = $tempDir . DIRECTORY_SEPARATOR . 'errores.txt';
        file_put_contents($manifest, afdc_contactos_manifest_text($warnings));
        $entries[] = ['path' => $manifest, 'name' => 'errores.txt'];

        afdc_contactos_zip($entries, $zipPath);

        return [
            'path'         => $zipPath,
            'downloadName' => 'contactos_pdf_' . $stamp . '.zip',
            'mime'         => 'application/zip',
            'cleanupDirs'  => $cleanup,
        ];
    }

    if ($outputMode === 'pdf_lote') {
        $allPages = [];

        foreach ($pagesByKey as $pages) {
            foreach ($pages as $pagePath) {
                $allPages[] = $pagePath;
            }
        }

        afdc_contactos_debug('Construyendo PDF lote con ' . count($allPages) . ' páginas');

        $pdfPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_lote_' . $stamp . '.pdf';
        afdc_contactos_build_pdf_from_jpegs($allPages, $pdfPath);

        return [
            'path'         => $pdfPath,
            'downloadName' => 'contactos_lote_' . $stamp . '.pdf',
            'mime'         => 'application/pdf',
            'cleanupDirs'  => $cleanup,
        ];
    }

    afdc_contactos_rrmdir($tempDir);
    throw new RuntimeException('Modo de salida inválido');
}

function afdc_contactos_normalizar_ruta_windows(string $ruta): string
{
    $ruta = trim($ruta);
    if ($ruta === '') {
        return '';
    }

    $candidatas = [$ruta];

    if (function_exists('mb_convert_encoding')) {
        $cand1 = @mb_convert_encoding($ruta, 'Windows-1252', 'UTF-8');
        if (is_string($cand1) && $cand1 !== '') {
            $candidatas[] = $cand1;
        }

        $cand2 = @mb_convert_encoding($ruta, 'ISO-8859-1', 'UTF-8');
        if (is_string($cand2) && $cand2 !== '') {
            $candidatas[] = $cand2;
        }
    }

    if (function_exists('iconv')) {
        $cand3 = @iconv('UTF-8', 'Windows-1252//IGNORE', $ruta);
        if ($cand3 !== false && $cand3 !== '') {
            $candidatas[] = $cand3;
        }

        $cand4 = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $ruta);
        if ($cand4 !== false && $cand4 !== '') {
            $candidatas[] = $cand4;
        }
    }

    foreach ($candidatas as $cand) {
        if (is_string($cand) && $cand !== '' && @is_dir($cand)) {
            return $cand;
        }
    }

    return $ruta;
}


function afdc_contactos_reparar_mojibake_utf8(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }

    $candidatos = [$texto];

    if (function_exists('mb_convert_encoding')) {
        $fix1 = @mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        if (is_string($fix1) && $fix1 !== '') {
            $candidatos[] = $fix1;
        }

        $fix2 = @mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        if (is_string($fix2) && $fix2 !== '') {
            $candidatos[] = $fix2;
        }
    }

    if (function_exists('iconv')) {
        $fix3 = @iconv('Windows-1252', 'UTF-8//IGNORE', $texto);
        if ($fix3 !== false && $fix3 !== '') {
            $candidatos[] = $fix3;
        }

        $fix4 = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $texto);
        if ($fix4 !== false && $fix4 !== '') {
            $candidatos[] = $fix4;
        }
    }

    foreach ($candidatos as $cand) {
        if (!is_string($cand) || $cand === '') {
            continue;
        }

        if (strpos($cand, 'Ã') === false && strpos($cand, 'Â') === false) {
            return $cand;
        }
    }

    return $texto;
}

function afdc_contactos_preparar_ruta_local(string $ruta): string
{
    $ruta = trim($ruta);
    if ($ruta === '') {
        return '';
    }

    $reparada = afdc_contactos_reparar_mojibake_utf8($ruta);
    return afdc_contactos_normalizar_ruta_windows($reparada);
}

function afdc_contactos_materia_campo_valido(string $campo): string
{
    $campo = trim($campo);
    $validos = ['todos', '600', '610', '611', '630', '650', '651'];

    return in_array($campo, $validos, true) ? $campo : 'todos';
}

function afdc_contactos_buscar_sobres_por_materia(string $texto, string $campo = 'todos', bool $exacta = false): array
{
    $texto = trim($texto);
    if ($texto === '') {
        throw new RuntimeException('No se recibió texto de materia');
    }

    $campo = afdc_contactos_materia_campo_valido($campo);

    $where = [];
    $types = '';
    $params = [];

    if ($exacta) {
        $where[] = 'm.materia = ?';
        $types .= 's';
        $params[] = $texto;
    } else {
        $where[] = 'm.materia LIKE ?';
        $types .= 's';
        $params[] = '%' . $texto . '%';
    }

    if ($campo !== 'todos') {
        $where[] = 'm.campo = ?';
        $types .= 's';
        $params[] = $campo;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT
            x.barcode,
            x.titulo,
            COUNT(d.nombramiento) AS imagenes
        FROM (
            SELECT
                t.barcode,
                MIN(t.titulo) AS titulo
            FROM materias m
            INNER JOIN titulos t ON t.sys = m.sys
            WHERE {$whereSql}
              AND COALESCE(t.barcode, '') <> ''
            GROUP BY t.barcode
        ) x
        LEFT JOIN digitales d
               ON d.inv = x.barcode
              AND (d.carpeta = 'Bajas' OR d.carpeta LIKE '%Bajas%')
        GROUP BY x.barcode, x.titulo
        HAVING imagenes > 0
        ORDER BY x.barcode ASC
    ";

    $rows = q($sql, $types, $params);

    $out = [];
    foreach ($rows as $r) {
        $barcode = trim((string)($r['barcode'] ?? ''));
        if ($barcode === '') {
            continue;
        }

        $out[] = [
            'barcode'  => $barcode,
            'titulo'   => (string)($r['titulo'] ?? ''),
            'imagenes' => (int)($r['imagenes'] ?? 0),
        ];
    }

    return $out;
}

function afdc_contactos_estimar_lotes_por_imagenes(array $sobres, int $maxImagenesPorPdf): array
{
    $maxImagenesPorPdf = max(1, $maxImagenesPorPdf);

    $totalSobres = 0;
    $totalImagenes = 0;
    $pdfs = 0;

    $actualSobres = 0;
    $actualImagenes = 0;

    foreach ($sobres as $sobre) {
        $imagenes = max(0, (int)($sobre['imagenes'] ?? 0));
        if ($imagenes <= 0) {
            continue;
        }

        $totalSobres++;
        $totalImagenes += $imagenes;

        if ($actualSobres > 0 && ($actualImagenes + $imagenes) > $maxImagenesPorPdf) {
            $pdfs++;
            $actualSobres = 0;
            $actualImagenes = 0;
        }

        $actualSobres++;
        $actualImagenes += $imagenes;
    }

    if ($actualSobres > 0) {
        $pdfs++;
    }

    return [
        'sobres'   => $totalSobres,
        'imagenes' => $totalImagenes,
        'pdfs'     => $pdfs,
    ];
}

function afdc_contactos_jobs_dir(): string
{
    $dir = __DIR__ . '/../tmp/contactos_jobs';

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function afdc_contactos_job_id(): string
{
    return bin2hex(random_bytes(12));
}

function afdc_contactos_job_file(string $jobId): string
{
    if (!preg_match('/^[a-f0-9]{24}$/', $jobId)) {
        throw new RuntimeException('ID de tarea inválido');
    }

    return afdc_contactos_jobs_dir() . '/' . $jobId . '.json';
}

function afdc_contactos_job_write(string $jobId, array $data): void
{
    $data['job_id'] = $jobId;
    $data['updated_at'] = date('c');

    $file = afdc_contactos_job_file($jobId);

    @file_put_contents(
        $file,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function afdc_contactos_job_read(string $jobId): array
{
    $file = afdc_contactos_job_file($jobId);

    if (!is_file($file)) {
        throw new RuntimeException('No se encontró la tarea');
    }

    $json = @file_get_contents($file);
    $data = json_decode((string)$json, true);

    if (!is_array($data)) {
        throw new RuntimeException('Estado de tarea inválido');
    }

    return $data;
}

function afdc_contactos_jobs_cleanup_old(int $maxAgeHours = 24): void
{
    $dir = afdc_contactos_jobs_dir();
    $limit = time() - ($maxAgeHours * 3600);

    foreach (glob($dir . '/*') ?: [] as $path) {
        if (@filemtime($path) !== false && (int)@filemtime($path) < $limit) {
            if (is_dir($path)) {
                afdc_contactos_rrmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}

function afdc_contactos_progress_noop(array $state): void
{
}

function afdc_contactos_generar_materia_loteada(
    array $sobres,
    int $maxImagenesPorPdf,
    string $label,
    ?callable $progress = null
): array {
    if (!$sobres) {
        throw new RuntimeException('La búsqueda no devolvió sobres con imágenes');
    }

    $progress = $progress ?: 'afdc_contactos_progress_noop';
    $maxImagenesPorPdf = max(1, $maxImagenesPorPdf);

    $tempDir = afdc_contactos_temp_dir();
    $cleanup = [$tempDir];
    $warnings = [];

    $labelSlug = afdc_contactos_slug($label);
    $stamp = date('Ymd_His');

    $progress([
        'status' => 'running',
        'percent' => 5,
        'message' => 'Preparando sobres e imágenes...',
    ]);

    afdc_contactos_debug('Generar materia loteada | sobres estimados: ' . count($sobres) . ' | máximo imágenes PDF: ' . $maxImagenesPorPdf);

    $resueltos = [];
    $totalSobresEstimados = count($sobres);

    foreach ($sobres as $idx => $sobre) {
        $barcode = trim((string)($sobre['barcode'] ?? ''));
        if ($barcode === '') {
            continue;
        }

        $progress([
            'status' => 'running',
            'percent' => 5 + (int)floor((($idx + 1) / max(1, $totalSobresEstimados)) * 15),
            'message' => 'Revisando imágenes del sobre ' . ($idx + 1) . '/' . $totalSobresEstimados . ': ' . $barcode,
            'current_barcode' => $barcode,
        ]);

        $items = afdc_contactos_resolver_imagenes_por_barcode($barcode);
        $cantidad = count($items);

        if ($cantidad <= 0) {
            $warnings[] = '[' . $barcode . '] Sin imágenes en Bajas';
            continue;
        }

        $resueltos[] = [
            'barcode'  => $barcode,
            'items'    => $items,
            'imagenes' => $cantidad,
        ];
    }

    if (!$resueltos) {
        afdc_contactos_rrmdir($tempDir);
        throw new RuntimeException('No se encontró ningún sobre con imágenes disponibles');
    }

    $progress([
        'status' => 'running',
        'percent' => 22,
        'message' => 'Agrupando sobres en PDFs sin partir sobres...',
        'sobres_procesables' => count($resueltos),
    ]);

    $lotes = [];
    $actual = [];
    $actualImagenes = 0;

    foreach ($resueltos as $sobre) {
        $imagenes = (int)$sobre['imagenes'];

        if ($actual && ($actualImagenes + $imagenes) > $maxImagenesPorPdf) {
            $lotes[] = [
                'sobres'   => $actual,
                'imagenes' => $actualImagenes,
            ];
            $actual = [];
            $actualImagenes = 0;
        }

        $actual[] = $sobre;
        $actualImagenes += $imagenes;
    }

    if ($actual) {
        $lotes[] = [
            'sobres'   => $actual,
            'imagenes' => $actualImagenes,
        ];
    }

    $pdfEntries = [];
    $totalLotes = count($lotes);
    $totalSobres = count($resueltos);
    $sobreGlobal = 0;

    $progress([
        'status' => 'running',
        'percent' => 25,
        'message' => 'Se generarán ' . $totalLotes . ' PDF(s).',
        'pdfs_total' => $totalLotes,
        'sobres_total' => $totalSobres,
    ]);

    foreach ($lotes as $idx => $lote) {
        $parte = $idx + 1;
        $jpegPages = [];

        afdc_contactos_debug('Render lote materia parte ' . $parte . ' | sobres: ' . count($lote['sobres']) . ' | imágenes: ' . $lote['imagenes']);

        $progress([
            'status' => 'running',
            'percent' => 25 + (int)floor(($idx / max(1, $totalLotes)) * 65),
            'message' => 'Preparando PDF ' . $parte . '/' . $totalLotes . ' (' . $lote['imagenes'] . ' imágenes)...',
            'pdf_actual' => $parte,
            'pdfs_total' => $totalLotes,
        ]);

        foreach ($lote['sobres'] as $sobre) {
            $sobreGlobal++;
            $barcode = (string)$sobre['barcode'];
            $items = (array)$sobre['items'];

            $progress([
                'status' => 'running',
                'percent' => 25 + (int)floor(($sobreGlobal / max(1, $totalSobres)) * 55),
                'message' => 'Generando hoja para sobre ' . $sobreGlobal . '/' . $totalSobres . ': ' . $barcode . ' (' . count($items) . ' imágenes)',
                'current_barcode' => $barcode,
                'sobres_done' => $sobreGlobal,
                'sobres_total' => $totalSobres,
                'pdf_actual' => $parte,
                'pdfs_total' => $totalLotes,
            ]);

            $render = afdc_contactos_render_sheet_pages($barcode, $items, $tempDir, 'light');
            $pages = $render['pages'] ?? [];
            $warnings = array_merge($warnings, $render['warnings'] ?? []);

            foreach ($pages as $pagePath) {
                $jpegPages[] = $pagePath;
            }
        }

        if (!$jpegPages) {
            $warnings[] = '[Parte ' . $parte . '] No se pudieron generar páginas';
            continue;
        }

        $pdfName = 'contactos_' . $labelSlug . '_' . str_pad((string)$parte, 3, '0', STR_PAD_LEFT) . '.pdf';
        $pdfPath = $tempDir . DIRECTORY_SEPARATOR . $pdfName;

        $progress([
            'status' => 'running',
            'percent' => 82 + (int)floor(($idx / max(1, $totalLotes)) * 12),
            'message' => 'Armando PDF ' . $parte . '/' . $totalLotes . '...',
            'pdf_actual' => $parte,
            'pdfs_total' => $totalLotes,
        ]);

        afdc_contactos_build_pdf_from_jpegs($jpegPages, $pdfPath);

        $pdfEntries[] = [
            'path' => $pdfPath,
            'name' => $pdfName,
        ];
    }

    if (!$pdfEntries) {
        afdc_contactos_rrmdir($tempDir);
        throw new RuntimeException('No se pudo generar ningún PDF');
    }

    $manifest = $tempDir . DIRECTORY_SEPARATOR . 'resumen.txt';

    $lines = [];
    $lines[] = 'Hojas de contacto por materia';
    $lines[] = 'Etiqueta: ' . $label;
    $lines[] = 'Máximo de imágenes por PDF: ' . $maxImagenesPorPdf;
    $lines[] = 'Sobres procesados: ' . count($resueltos);
    $lines[] = 'PDFs generados: ' . count($pdfEntries);
    $lines[] = '';
    $lines[] = 'Observaciones:';
    $lines[] = afdc_contactos_manifest_text($warnings);

    file_put_contents($manifest, implode("\n", $lines));

    $progress([
        'status' => 'running',
        'percent' => 96,
        'message' => 'Preparando descarga...',
    ]);

    if (count($pdfEntries) === 1) {
        return [
            'path'         => $pdfEntries[0]['path'],
            'downloadName' => $pdfEntries[0]['name'],
            'mime'         => 'application/pdf',
            'cleanupDirs'  => $cleanup,
        ];
    }

    $zipPath = $tempDir . DIRECTORY_SEPARATOR . 'contactos_' . $labelSlug . '_' . $stamp . '.zip';

    $entries = $pdfEntries;
    $entries[] = [
        'path' => $manifest,
        'name' => 'resumen.txt',
    ];

    afdc_contactos_zip($entries, $zipPath);

    return [
        'path'         => $zipPath,
        'downloadName' => 'contactos_' . $labelSlug . '_' . $stamp . '.zip',
        'mime'         => 'application/zip',
        'cleanupDirs'  => $cleanup,
    ];
}