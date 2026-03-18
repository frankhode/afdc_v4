<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function out($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function bad(string $msg, int $code = 400): void {
  out(['ok' => false, 'error' => $msg], $code);
}

function fich_root_fs(): string {
  $root = AFDC_BAJAS_FS . DIRECTORY_SEPARATOR . 'FICHERO CROAF';
  if (!is_dir($root)) {
    bad('No existe la carpeta: ' . $root, 500);
  }
  return $root;
}

function cache_dir(): string {
  $dir = __DIR__ . '/../cache/fichero';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function norm_folder(string $folder): string {
  $folder = trim($folder);
  // Seguridad anti traversal: sólo letras/dígitos/_/-
  if ($folder === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $folder)) {
    bad('Carpeta inválida', 400);
  }
  return $folder;
}

function build_cache(string $folder): array {
  $root = fich_root_fs();
  $dir = $root . DIRECTORY_SEPARATOR . $folder;
  if (!is_dir($dir)) bad('No existe el cajón: ' . $folder, 404);

  $dirMtime = @filemtime($dir) ?: 0;

  $re = '/^CROAF_F0*(\d+)([FR])\.(jpe?g)$/i';

  $minCard = null;
  $maxCard = 0;
  $cards = []; // "123" => ["F"=>"CROAF_F....jpg","R"=>"..."]

  $it = new DirectoryIterator($dir);
  foreach ($it as $f) {
    if ($f->isDot() || !$f->isFile()) continue;

    $name = $f->getFilename();
    if (!preg_match($re, $name, $m)) continue;

    $fileNum = (int)$m[1];
    $side = strtoupper($m[2]); // F o R

    // misma lógica que Java:
    // impar => (n+1)/2, par => n/2
    $card = ($fileNum % 2 === 1) ? intdiv($fileNum + 1, 2) : intdiv($fileNum, 2);

    if (!isset($cards[$card])) $cards[$card] = [];
    // si hay duplicados raros, preferimos el primer filename que llegue
    if (!isset($cards[$card][$side])) $cards[$card][$side] = $name;

    if ($minCard === null || $card < $minCard) $minCard = $card;
    if ($card > $maxCard) $maxCard = $card;
  }

  if ($minCard === null) $minCard = 0;

  // ordenar por card para consistencia
  ksort($cards, SORT_NUMERIC);

  return [
    'folder' => $folder,
    'dir_mtime' => $dirMtime,
    'scanned_at' => time(),
    'minCard' => (int)$minCard,
    'maxCard' => (int)$maxCard,
    'cards' => $cards,
  ];
}

function load_cache(string $folder): array {
  $folder = norm_folder($folder);

  $root = fich_root_fs();
  $dir = $root . DIRECTORY_SEPARATOR . $folder;
  if (!is_dir($dir)) bad('No existe el cajón: ' . $folder, 404);
  $dirMtime = @filemtime($dir) ?: 0;

  $cf = cache_dir() . '/' . sha1($folder) . '.json';

  if (is_file($cf)) {
    $raw = @file_get_contents($cf);
    $data = $raw ? json_decode($raw, true) : null;
    if (is_array($data) && (int)($data['dir_mtime'] ?? -1) === (int)$dirMtime) {
      return $data;
    }
  }

  $data = build_cache($folder);
  @file_put_contents($cf, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  return $data;
}

function file_url(string $folder, string $filename): string {
  $base = rtrim(AFDC_BAJAS_URL, '/');
  // FICHERO%20CROAF/<folder>/<filename>
  return $base . '/FICHERO%20CROAF/' . rawurlencode($folder) . '/' . rawurlencode($filename);
}

$action = (string)($_GET['action'] ?? 'letters');

if ($action === 'letters') {
  $root = fich_root_fs();
  $letters = [];
  foreach (new DirectoryIterator($root) as $d) {
    if ($d->isDot() || !$d->isDir()) continue;
    $name = $d->getFilename();
    $ch = strtoupper(substr($name, 0, 1));
    if ($ch >= 'A' && $ch <= 'Z') $letters[$ch] = true;
  }
  $out = array_keys($letters);
  sort($out);
  out(['ok' => true, 'letters' => $out]);
}

if ($action === 'folders') {
  $letter = strtoupper(trim((string)($_GET['letter'] ?? '')));
  if (!preg_match('/^[A-Z]$/', $letter)) bad('Letra inválida');

  $root = fich_root_fs();
  $folders = [];
  foreach (new DirectoryIterator($root) as $d) {
    if ($d->isDot() || !$d->isDir()) continue;
    $name = $d->getFilename();
    if (strtoupper(substr($name, 0, 1)) === $letter) $folders[] = $name;
  }
  sort($folders, SORT_NATURAL);
  out(['ok' => true, 'letter' => $letter, 'folders' => $folders]);
}

if ($action === 'range') {
  $folder = (string)($_GET['folder'] ?? '');
  $cache = load_cache($folder);
  out([
    'ok' => true,
    'folder' => $cache['folder'],
    'minCard' => (int)$cache['minCard'],
    'maxCard' => (int)$cache['maxCard'],
    'totalCards' => (int)$cache['maxCard'],
    'cardCount' => is_array($cache['cards']) ? count($cache['cards']) : 0,
  ]);
}

if ($action === 'card') {
  $folder = (string)($_GET['folder'] ?? '');
  $card = (int)($_GET['card'] ?? 0);
  if ($card < 1) $card = 1;

  $cache = load_cache($folder);
  $min = (int)$cache['minCard'];
  $max = (int)$cache['maxCard'];

  if ($min > 0 && $card < $min) $card = $min;
  if ($max > 0 && $card > $max) $card = $max;

  $entry = $cache['cards'][(string)$card] ?? $cache['cards'][$card] ?? null;
  $front = is_array($entry) ? ($entry['F'] ?? null) : null;
  $back  = is_array($entry) ? ($entry['R'] ?? null) : null;

  out([
    'ok' => true,
    'folder' => $cache['folder'],
    'card' => (int)$card,
    'minCard' => $min,
    'maxCard' => $max,
    'front' => $front,
    'back' => $back,
    'frontUrl' => $front ? file_url($cache['folder'], $front) : null,
    'backUrl'  => $back  ? file_url($cache['folder'], $back) : null,
  ]);
}
if ($action === 'folders_all') {
  $root = fich_root_fs();
  $folders = [];
  foreach (new DirectoryIterator($root) as $d) {
    if ($d->isDot() || !$d->isDir()) continue;
    $name = $d->getFilename();
    // Sólo carpetas “normales” (las tuyas son AAAA-BBBB, etc.)
    if (preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
      $folders[] = $name;
    }
  }
  sort($folders, SORT_NATURAL);
  out(['ok' => true, 'folders' => $folders]);
}

bad('Acción no soportada', 404);
