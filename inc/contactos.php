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
 * En esta versión las imágenes se cargan desde la URL pública
 * BASE_URL + '/bajas/...', alineado con el alias Apache del proyecto.
 */

if (!defined('AFDC_CONTACTOS_A4_W')) define('AFDC_CONTACTOS_A4_W', 1754); // A4 landscape ~150dpi
if (!defined('AFDC_CONTACTOS_A4_H')) define('AFDC_CONTACTOS_A4_H', 1240);

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

    file_put_contents($pdfPath, $pdf);
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