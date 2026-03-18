<?php
/**
 * thumb.php - Generador/servidor de miniaturas cacheadas para AFDC
 *
 * Uso:
 *   thumb.php?cajon=...&inv=...&nom=FO012345_000.jpg&h=160
 *   thumb.php?cajon=...&inv=...&nom=...&h=160&precache=1   (solo genera cache, responde 204)
 *
 * Cache en disco:
 *   <proyecto>/thumbs/<cajon>/<inv>/<filename>_h160.jpg
 *
 * Nota de performance:
 * - En modo normal (sin precache), este script redirige al JPG estatico en /thumbs.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// -------------------- helpers --------------------
function safe_seg(string $v, int $max = 128): string {
    $v = trim($v);
    if ($v === '' || strlen($v) > $max) return '';
    if (str_contains($v, '/') || str_contains($v, '\\')) return '';
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $v)) return '';
    if ($v === '.' || $v === '..' || str_contains($v, '..')) return '';
    return $v;
}

function send_svg_placeholder(string $label = 'sin imagen'): void {
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store');
    $label = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="260" height="180" viewBox="0 0 260 180">
  <defs>
    <linearGradient id="g" x1="0" x2="0" y1="0" y2="1">
      <stop offset="0" stop-color="#2b2b2b"/>
      <stop offset="1" stop-color="#1f1f1f"/>
    </linearGradient>
  </defs>
  <rect x="0" y="0" width="260" height="180" rx="14" fill="url(#g)" stroke="#444"/>
  <path d="M75 120 L110 85 L132 107 L162 77 L197 112" fill="none" stroke="#666" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>
  <circle cx="96" cy="70" r="10" fill="#666"/>
  <text x="130" y="150" text-anchor="middle" font-family="system-ui,Segoe UI,Arial" font-size="14" fill="#9a9a9a">{$label}</text>
</svg>
SVG;
    exit;
}

function send_204(): void {
    http_response_code(204);
    header('Cache-Control: no-store');
    exit;
}

function redirect_static(string $cajon, string $inv, string $baseName, int $h): void {
    $public = rtrim(BASE_URL, '/') . '/thumbs/' . rawurlencode($cajon) . '/' . rawurlencode($inv) . '/' . rawurlencode($baseName . "_h{$h}.jpg");
     header('Location: ' . $public, true, 302);
     exit;
}

// -------------------- params --------------------
$cajon = safe_seg((string)($_GET['cajon'] ?? ''));
$inv   = safe_seg((string)($_GET['inv'] ?? ''));
$nom   = safe_seg((string)($_GET['nom'] ?? ''));

$h = (int)($_GET['h'] ?? 160);
if ($h < 60)  $h = 60;
if ($h > 260) $h = 260;

$precache = ((int)($_GET['precache'] ?? 0) === 1);

if ($cajon === '' || $inv === '' || $nom === '') {
    if ($precache) send_204();
    send_svg_placeholder('params');
}

$srcUrl = rtrim(AFDC_BAJAS_URL, '/') . '/' . rawurlencode($cajon) . '/' . rawurlencode($inv) . '/' . rawurlencode($nom);

// -------------------- cache --------------------
$thumbRoot = __DIR__ . DIRECTORY_SEPARATOR . 'thumbs';
$cacheDir  = $thumbRoot . DIRECTORY_SEPARATOR . $cajon . DIRECTORY_SEPARATOR . $inv;

$baseName = pathinfo($nom, PATHINFO_FILENAME);
$cache    = $cacheDir . DIRECTORY_SEPARATOR . $baseName . "_h{$h}.jpg";

if (is_file($cache)) {
     if ($precache) send_204();
     redirect_static($cajon, $inv, $baseName, $h);
 }

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

// -------------------- generar --------------------
if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromjpeg')) {
    if ($precache) send_204();
    send_svg_placeholder('GD faltante');
}

$img = null;
$bytes = @file_get_contents($srcUrl);
if ($bytes !== false && $bytes !== '') {
    $img = @imagecreatefromstring($bytes);
} else {
    if ($precache) send_204();
    send_svg_placeholder('no encontrado');
}

if (!$img) {
    if ($precache) send_204();
    send_svg_placeholder('formato');
}

$w  = imagesx($img);
$hh = imagesy($img);
if ($w <= 0 || $hh <= 0) {
    imagedestroy($img);
    if ($precache) send_204();
    send_svg_placeholder('imagen');
}

$scale = $h / $hh;
$newW = (int)max(1, round($w * $scale));
$newH = (int)$h;

$thumb = imagecreatetruecolor($newW, $newH);
imagealphablending($thumb, true);
imagesavealpha($thumb, true);
imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newW, $newH, $w, $hh);

@imagejpeg($thumb, $cache, 78);

imagedestroy($thumb);
imagedestroy($img);



if ($precache) send_204();
redirect_static($cajon, $inv, $baseName, $h);
