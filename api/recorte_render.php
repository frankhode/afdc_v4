<?php
declare(strict_types=1);
if (!defined('AFDC_EDICION_IMPRESA_FS')) {
    define('AFDC_EDICION_IMPRESA_FS', 'G:/AFDC_PORTABLE/Bajas/Edicion impresa');
}
// --------------------------------------------------
// AFDC v2 - Render dinámico de recortes
// Ruta sugerida: /api/recorte_render.php
//
// Soporta:
// - modo=crop    -> devuelve solo el recorte real
// - modo=context -> devuelve imagen completa con borde del recorte
// - modo=focus   -> devuelve imagen completa con exterior oscurecido
//
// Entrada principal:
//   ?recorte=123
//
// Parámetros opcionales:
//   &modo=crop|context|focus
//   &maxw=1600
//   &maxh=0
//   &q=90
//
// Requiere GD habilitado.
// --------------------------------------------------

// Bootstrap portable
$bootstrap = __DIR__ . '/../inc/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = __DIR__ . '/inc/bootstrap.php';
}
require_once $bootstrap;

// Auth portable
$auth = __DIR__ . '/../inc/auth_v2.php';
if (!is_file($auth)) {
    $auth = __DIR__ . '/inc/auth_v2.php';
}
require_once $auth;

afdc_v2_session_start();
$u = afdc_v2_current_user();

if (!extension_loaded('gd')) {
    rr_fail(500, 'La extensión GD no está habilitada en PHP.');
}

$usuarioId = (int)($u['id'] ?? 0);
if ($usuarioId <= 0) {
    rr_fail(401, 'Usuario no autenticado.');
}

$recorteId = (int)($_GET['recorte'] ?? $_GET['id'] ?? 0);
$modo      = rr_str($_GET, 'modo', 'crop');
$maxW      = rr_int($_GET, 'maxw', 0);
$maxH      = rr_int($_GET, 'maxh', 0);
$quality   = rr_int($_GET, 'q', 90);

if ($recorteId <= 0) {
    rr_fail(400, 'Falta el parámetro recorte.');
}

if (!in_array($modo, ['crop', 'context', 'focus'], true)) {
    rr_fail(400, 'Modo inválido.');
}

if ($quality < 30) $quality = 30;
if ($quality > 100) $quality = 100;

$rows = q(
    "SELECT * FROM recortes WHERE id = ? AND usuario_id = ? LIMIT 1",
    'ii',
    [$recorteId, $usuarioId]
);

if (!$rows) {
    rr_fail(404, 'Recorte no encontrado.');
}

$recorte = $rows[0];
$view = rr_view_data_from_recorte($recorte);

if ($view['imgA'] === '' && $view['imgB'] === '') {
    rr_fail(404, 'No se pudo resolver la imagen de origen del recorte.');
}

$src = null;
$out = null;

try {
    $src = rr_build_source_surface($view);

    $x = rr_clamp01((float)($view['x'] ?? 0));
    $y = rr_clamp01((float)($view['y'] ?? 0));
    $w = rr_clamp01((float)($view['w'] ?? 0));
    $h = rr_clamp01((float)($view['h'] ?? 0));

    if (($x + $w) > 1.0) $w = max(0.0, 1.0 - $x);
    if (($y + $h) > 1.0) $h = max(0.0, 1.0 - $y);

    if ($w <= 0 || $h <= 0) {
        rr_fail(422, 'El recorte quedó fuera de rango.');
    }

    $rect = rr_normalized_rect_to_pixels($src['w'], $src['h'], $x, $y, $w, $h);

    switch ($modo) {
        case 'crop':
            $out = rr_render_crop($src['im'], $rect);
            break;

        case 'context':
            $out = rr_render_context($src['im'], $rect);
            break;

        case 'focus':
            $out = rr_render_focus($src['im'], $rect);
            break;
    }

    $out = rr_scale_down_if_needed($out, $maxW, $maxH);

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=300');
    header('Pragma: private');

    imagejpeg($out['im'], null, $quality);
} catch (\Throwable $e) {
    rr_fail(500, 'Error al renderizar el recorte: ' . $e->getMessage());
} finally {
    if (is_array($src) && isset($src['im']) && $src['im'] instanceof GdImage) {
        imagedestroy($src['im']);
    }
    if (is_array($out) && isset($out['im']) && $out['im'] instanceof GdImage) {
        imagedestroy($out['im']);
    }
}
exit;


// --------------------------------------------------
// Helpers base
// --------------------------------------------------

function rr_fail(int $status, string $msg): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

function rr_is_debug(): bool {
    return isset($_GET['debug']) && (string)$_GET['debug'] === '1';
}

function rr_str(array $src, string $key, string $default = ''): string {
    return isset($src[$key]) ? trim((string)$src[$key]) : $default;
}

function rr_int(array $src, string $key, int $default = 0): int {
    if (!isset($src[$key]) || $src[$key] === '') {
        return $default;
    }
    return (int)$src[$key];
}

function rr_clamp01(float $v): float {
    if ($v < 0.0) return 0.0;
    if ($v > 1.0) return 1.0;
    return $v;
}

function rr_normalize_edimpresa_url(string $path): string {
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') return '';

    // quitar host si vino URL absoluta
    $pathOnly = parse_url($path, PHP_URL_PATH);
    if (is_string($pathOnly) && $pathOnly !== '') {
        $path = $pathOnly;
    }

    $baseUrl = defined('AFDC_EDICION_IMPRESA_URL') ? (string)AFDC_EDICION_IMPRESA_URL : '';
    $basePath = $baseUrl !== '' ? (string)parse_url($baseUrl, PHP_URL_PATH) : '/Edicion_impresa';
    $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');

    // Canonizar variantes viejas
    $path = preg_replace('#^/?Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));
    $path = preg_replace('#^/?afdc_v2/Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));
    $path = preg_replace('#^/?archivocronica/Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));

    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }

    // deduplicar /Edicion_impresa/Edicion_impresa/
    $quotedBase = preg_quote(trim($basePath, '/'), '#');
    $path = preg_replace('#/(?:' . $quotedBase . '/)+#i', '/' . trim($basePath, '/') . '/', $path);

    return preg_replace('#/+#', '/', $path);
}

function rr_view_data_from_recorte(array $r): array {
    $tipo = (string)($r['tipo'] ?? '');
    $x    = rr_clamp01((float)($r['xval'] ?? 0));
    $y    = rr_clamp01((float)($r['yval'] ?? 0));
    $w    = rr_clamp01((float)($r['ancho'] ?? 0));
    $h    = rr_clamp01((float)($r['alto'] ?? 0));

    if (($x + $w) > 1.0) $w = max(0.0, 1.0 - $x);
    if (($y + $h) > 1.0) $h = max(0.0, 1.0 - $y);

    $raw = (string)($r['recortadoDe'] ?? '');
    $parts = array_values(array_filter(array_map('trim', explode('|', $raw)), 'strlen'));
    $parts = array_map('rr_normalize_edimpresa_url', $parts);

    $mode = 'single';
    $imgA = $parts[0] ?? '';
    $imgB = $parts[1] ?? '';

    if ($tipo !== 'simple_izq' && $tipo !== 'simple_der') {
        $mode = 'double';
    } else {
        $mode = 'single';
        $imgB = '';
    }

    return [
        'mode' => $mode,
        'imgA' => $imgA,
        'imgB' => $imgB,
        'x'    => $x,
        'y'    => $y,
        'w'    => $w,
        'h'    => $h,
    ];
}

function rr_app_root(): string {
    return dirname(__DIR__);
}

function rr_public_url_to_fs_prefix_map(): array {
    $map = [];

    if (defined('AFDC_EDICION_IMPRESA_URL') && defined('AFDC_EDICION_IMPRESA_FS')) {
        $pub = (string)AFDC_EDICION_IMPRESA_URL;
        $fs  = (string)AFDC_EDICION_IMPRESA_FS;

        $pubPath = parse_url($pub, PHP_URL_PATH);
        if (is_string($pubPath) && $pubPath !== '' && $fs !== '') {
            $map[rtrim(str_replace('\\', '/', $pubPath), '/') . '/'] =
                rtrim(str_replace('\\', '/', $fs), '/') . '/';
        }
    }

    if (defined('AFDC_BAJAS_URL') && defined('AFDC_BAJAS_FS')) {
        $pub = (string)AFDC_BAJAS_URL;
        $fs  = (string)AFDC_BAJAS_FS;

        $pubPath = parse_url($pub, PHP_URL_PATH);
        if (is_string($pubPath) && $pubPath !== '' && $fs !== '') {
            $map[rtrim(str_replace('\\', '/', $pubPath), '/') . '/'] =
                rtrim(str_replace('\\', '/', $fs), '/') . '/';
        }
    }

    return $map;
}

function rr_resolve_fs_path(string $path, array &$debugInfo = []): ?string {
    $origPath = $path;

    $path = trim($path);
    if ($path === '') {
        $debugInfo[] = 'Path vacío.';
        return null;
    }

    $urlPath = parse_url($path, PHP_URL_PATH);
    $path = is_string($urlPath) && $urlPath !== '' ? $urlPath : $path;
    $path = urldecode($path);
    $path = str_replace('\\', '/', $path);

    $appRoot = str_replace('\\', '/', rr_app_root());
    $docRoot = str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $scriptFilename = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptDir = $scriptFilename !== '' ? dirname($scriptFilename) : '';
    $appBaseName = basename($appRoot);

    $debugInfo[] = 'origPath       = ' . $origPath;
    $debugInfo[] = 'parsedPath     = ' . $path;
    $debugInfo[] = 'DOCUMENT_ROOT  = ' . $docRoot;
    $debugInfo[] = 'SCRIPT_FILENAME= ' . $scriptFilename;
    $debugInfo[] = 'SCRIPT_DIR     = ' . $scriptDir;
    $debugInfo[] = 'APP_ROOT       = ' . $appRoot;
    $debugInfo[] = 'APP_BASENAME   = ' . $appBaseName;
    if (defined('AFDC_EDICION_IMPRESA_URL')) {
        $debugInfo[] = 'AFDC_EDICION_IMPRESA_URL = ' . AFDC_EDICION_IMPRESA_URL;
    }
    if (defined('AFDC_EDICION_IMPRESA_FS')) {
        $debugInfo[] = 'AFDC_EDICION_IMPRESA_FS  = ' . AFDC_EDICION_IMPRESA_FS;
    }
    $debugInfo[] = '';

    if (is_file($path)) {
        $debugInfo[] = 'MATCH directo: ' . $path;
        return realpath($path) ?: $path;
    }

    foreach (rr_public_url_to_fs_prefix_map() as $urlPrefix => $fsBase) {
        if (stripos($path, $urlPrefix) === 0) {
            $rest = substr($path, strlen($urlPrefix));
            $candidate = $fsBase . ltrim(str_replace('\\', '/', (string)$rest), '/');
            $candidate = preg_replace('#/+#', '/', $candidate);

            $exists = is_file($candidate) ? 'YES' : 'no';
            $debugInfo[] = '[CFG ' . $exists . '] ' . $candidate;

            if ($exists === 'YES') {
                return realpath($candidate) ?: $candidate;
            }
        }
    }

    // compatibilidad extra: si quedó una ruta arrancando solo en /Edicion_impresa/...
    if (defined('AFDC_EDICION_IMPRESA_FS') && preg_match('#^/Edicion_impresa/#i', $path)) {
        $candidate = rtrim(str_replace('\\', '/', (string)AFDC_EDICION_IMPRESA_FS), '/')
            . '/'
            . ltrim(preg_replace('#^/Edicion_impresa/#i', '', $path), '/');

        $candidate = preg_replace('#/+#', '/', $candidate);
        $exists = is_file($candidate) ? 'YES' : 'no';
        $debugInfo[] = '[EDIMP ' . $exists . '] ' . $candidate;

        if ($exists === 'YES') {
            return realpath($candidate) ?: $candidate;
        }
    }

    $candidates = [];

    if ($path !== '' && $path[0] === '/' && $docRoot !== '') {
        $candidates[] = rtrim($docRoot, '/') . $path;
    }

    if ($path !== '' && $path[0] === '/' && preg_match('#^/' . preg_quote($appBaseName, '#') . '/#i', $path)) {
        $trimmed = preg_replace('#^/' . preg_quote($appBaseName, '#') . '/#i', '', $path);
        $candidates[] = $appRoot . '/' . ltrim((string)$trimmed, '/');
    }

    $candidates[] = $appRoot . '/' . ltrim($path, '/');

    if ($scriptDir !== '') {
        $candidates[] = rtrim($scriptDir, '/') . '/' . ltrim($path, '/');
        $candidates[] = dirname($scriptDir) . '/' . ltrim($path, '/');
    }

    if ($path !== '' && $path[0] === '/') {
        $trimmed = ltrim($path, '/');
        $parts = explode('/', $trimmed, 2);
        if (count($parts) === 2) {
            $candidates[] = $appRoot . '/' . $parts[1];
        }
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = preg_replace('#/+#', '/', str_replace('\\', '/', $candidate));
        if (isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;

        $exists = is_file($candidate) ? 'YES' : 'no';
        $debugInfo[] = '[' . $exists . '] ' . $candidate;

        if ($exists === 'YES') {
            return realpath($candidate) ?: $candidate;
        }
    }

    $debugInfo[] = '';
    $debugInfo[] = 'No hubo match.';
    return null;
}

function rr_open_image(string $publicPath): array {
    $debugInfo = [];
    $fs = rr_resolve_fs_path($publicPath, $debugInfo);

    if ($fs === null || !is_file($fs)) {
        if (rr_is_debug()) {
            rr_fail(404, "No se encontró la imagen.\n\n" . implode("\n", $debugInfo));
        }
        throw new RuntimeException('No se encontró la imagen: ' . $publicPath);
    }

    $info = @getimagesize($fs);
    if (!$info || !isset($info[2])) {
        if (rr_is_debug()) {
            rr_fail(500, "No se pudo leer la imagen.\n\nFS: {$fs}\n\n" . implode("\n", $debugInfo));
        }
        throw new RuntimeException('No se pudo leer la imagen: ' . $publicPath);
    }

    [$w, $h, $type] = $info;

    switch ($type) {
        case IMAGETYPE_JPEG:
            $im = @imagecreatefromjpeg($fs);
            break;
        case IMAGETYPE_PNG:
            $im = @imagecreatefrompng($fs);
            break;
        case IMAGETYPE_GIF:
            $im = @imagecreatefromgif($fs);
            break;
        case IMAGETYPE_WEBP:
            $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fs) : false;
            break;
        default:
            throw new RuntimeException('Formato de imagen no soportado: ' . $publicPath);
    }

    if (!$im instanceof GdImage) {
        throw new RuntimeException('No se pudo abrir la imagen: ' . $publicPath);
    }

    imagealphablending($im, true);
    imagesavealpha($im, true);

    return [
        'im' => $im,
        'w'  => (int)$w,
        'h'  => (int)$h,
        'fs' => $fs,
    ];
}

function rr_create_truecolor(int $w, int $h, int $bgR = 255, int $bgG = 255, int $bgB = 255): GdImage {
    $im = imagecreatetruecolor($w, $h);
    if (!$im instanceof GdImage) {
        throw new RuntimeException('No se pudo crear el canvas.');
    }

    imagealphablending($im, true);
    imagesavealpha($im, true);

    $bg = imagecolorallocate($im, $bgR, $bgG, $bgB);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    return $im;
}

function rr_resample_copy(GdImage $src, int $srcW, int $srcH, int $dstW, int $dstH): GdImage {
    $dst = rr_create_truecolor($dstW, $dstH, 255, 255, 255);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    return $dst;
}

function rr_build_source_surface(array $view): array {
    $imgA = trim((string)($view['imgA'] ?? ''));
    $imgB = trim((string)($view['imgB'] ?? ''));
    $mode = (string)($view['mode'] ?? 'single');

    if ($imgA === '') {
        throw new RuntimeException('No hay imagen base para el recorte.');
    }

    $a = rr_open_image($imgA);

    if ($mode !== 'double' || $imgB === '') {
        return [
            'im' => $a['im'],
            'w'  => $a['w'],
            'h'  => $a['h'],
        ];
    }

    $b = rr_open_image($imgB);

    $targetH = min($a['h'], $b['h']);
    if ($targetH <= 0) {
        imagedestroy($a['im']);
        imagedestroy($b['im']);
        throw new RuntimeException('Alturas inválidas al componer doble página.');
    }

    $aScaled = $a['im'];
    $bScaled = $b['im'];
    $aW = $a['w'];
    $aH = $a['h'];
    $bW = $b['w'];
    $bH = $b['h'];

    if ($aH > $targetH) {
        $newW = (int)round($aW * ($targetH / $aH));
        $aScaled = rr_resample_copy($a['im'], $aW, $aH, $newW, $targetH);
        imagedestroy($a['im']);
        $aW = $newW;
        $aH = $targetH;
    }

    if ($bH > $targetH) {
        $newW = (int)round($bW * ($targetH / $bH));
        $bScaled = rr_resample_copy($b['im'], $bW, $bH, $newW, $targetH);
        imagedestroy($b['im']);
        $bW = $newW;
        $bH = $targetH;
    }

    $spreadW = $aW + $bW;
    $spreadH = $targetH;

    $canvas = rr_create_truecolor($spreadW, $spreadH, 255, 255, 255);
    imagecopy($canvas, $aScaled, 0,   0, 0, 0, $aW, $aH);
    imagecopy($canvas, $bScaled, $aW, 0, 0, 0, $bW, $bH);

    if ($aScaled !== $a['im']) {
        imagedestroy($aScaled);
    }
    if ($bScaled !== $b['im']) {
        imagedestroy($bScaled);
    }

    // Si no fueron destruidas antes
    if ($a['im'] instanceof GdImage) {
        @imagedestroy($a['im']);
    }
    if ($b['im'] instanceof GdImage) {
        @imagedestroy($b['im']);
    }

    return [
        'im' => $canvas,
        'w'  => $spreadW,
        'h'  => $spreadH,
    ];
}

function rr_normalized_rect_to_pixels(int $srcW, int $srcH, float $x, float $y, float $w, float $h): array {
    $x1 = (int)floor($srcW * $x);
    $y1 = (int)floor($srcH * $y);
    $x2 = (int)ceil($srcW * ($x + $w));
    $y2 = (int)ceil($srcH * ($y + $h));

    if ($x1 < 0) $x1 = 0;
    if ($y1 < 0) $y1 = 0;
    if ($x2 > $srcW) $x2 = $srcW;
    if ($y2 > $srcH) $y2 = $srcH;

    $cw = max(1, $x2 - $x1);
    $ch = max(1, $y2 - $y1);

    return [
        'x' => $x1,
        'y' => $y1,
        'w' => $cw,
        'h' => $ch,
    ];
}

function rr_render_crop(GdImage $src, array $rect): array {
    $dst = rr_create_truecolor((int)$rect['w'], (int)$rect['h'], 255, 255, 255);
    imagecopy(
        $dst,
        $src,
        0,
        0,
        (int)$rect['x'],
        (int)$rect['y'],
        (int)$rect['w'],
        (int)$rect['h']
    );

    return [
        'im' => $dst,
        'w'  => (int)$rect['w'],
        'h'  => (int)$rect['h'],
    ];
}

function rr_draw_rect(GdImage $im, int $x, int $y, int $w, int $h, int $thickness = 3): void {
    $color = imagecolorallocate($im, 202, 58, 37);

    for ($i = 0; $i < $thickness; $i++) {
        imagerectangle(
            $im,
            $x - $i,
            $y - $i,
            $x + $w - 1 + $i,
            $y + $h - 1 + $i,
            $color
        );
    }
}

function rr_render_context(GdImage $src, array $rect): array {
    $w = imagesx($src);
    $h = imagesy($src);

    $dst = rr_create_truecolor($w, $h, 255, 255, 255);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

    rr_draw_rect($dst, (int)$rect['x'], (int)$rect['y'], (int)$rect['w'], (int)$rect['h'], 3);

    return [
        'im' => $dst,
        'w'  => $w,
        'h'  => $h,
    ];
}

function rr_render_focus(GdImage $src, array $rect): array {
    $w = imagesx($src);
    $h = imagesy($src);

    $dst = rr_create_truecolor($w, $h, 255, 255, 255);
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

    imagealphablending($dst, true);
    imagesavealpha($dst, true);

    $shade = imagecolorallocatealpha($dst, 0, 0, 0, 72);

    $x = (int)$rect['x'];
    $y = (int)$rect['y'];
    $rw = (int)$rect['w'];
    $rh = (int)$rect['h'];

    // arriba
    if ($y > 0) {
        imagefilledrectangle($dst, 0, 0, $w - 1, $y - 1, $shade);
    }
    // abajo
    if (($y + $rh) < $h) {
        imagefilledrectangle($dst, 0, $y + $rh, $w - 1, $h - 1, $shade);
    }
    // izquierda
    if ($x > 0) {
        imagefilledrectangle($dst, 0, $y, $x - 1, $y + $rh - 1, $shade);
    }
    // derecha
    if (($x + $rw) < $w) {
        imagefilledrectangle($dst, $x + $rw, $y, $w - 1, $y + $rh - 1, $shade);
    }

    rr_draw_rect($dst, $x, $y, $rw, $rh, 3);

    return [
        'im' => $dst,
        'w'  => $w,
        'h'  => $h,
    ];
}

function rr_scale_down_if_needed(array $img, int $maxW = 0, int $maxH = 0): array {
    $srcW = (int)$img['w'];
    $srcH = (int)$img['h'];

    if ($srcW <= 0 || $srcH <= 0) {
        return $img;
    }

    $ratio = 1.0;

    if ($maxW > 0 && $srcW > $maxW) {
        $ratio = min($ratio, $maxW / $srcW);
    }

    if ($maxH > 0 && $srcH > $maxH) {
        $ratio = min($ratio, $maxH / $srcH);
    }

    if ($ratio >= 1.0) {
        return $img;
    }

    $dstW = max(1, (int)round($srcW * $ratio));
    $dstH = max(1, (int)round($srcH * $ratio));

    $scaled = rr_resample_copy($img['im'], $srcW, $srcH, $dstW, $dstH);
    imagedestroy($img['im']);

    return [
        'im' => $scaled,
        'w'  => $dstW,
        'h'  => $dstH,
    ];
}