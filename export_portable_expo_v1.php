<?php
declare(strict_types=1);

/**
 * export_portable.php
 *
 * Exporta una colección a una carpeta portable (HTML offline + imágenes originales copiadas).
 *
 * Uso (desde browser):
 *   http://localhost/afdc_v2/export_portable.php?collection_id=2
 *   http://localhost/afdc_v2/export_portable.php?expo_id=1
 *   http://localhost/afdc_v2/export_portable.php?expo_id=1&collection_id=2
 *
 * Requiere:
 * - Acceso a tu DB (usa q() desde inc/bootstrap.php)
 * - Tablas base: collections_v2, collection_items_v2, digitales, titulos, registros, partidos (esta última opcional)
 * - Tablas expo v1: expo_v1, expo_piece_v1
 *
 * Config:
 * - BAJAS_FS_ROOT: carpeta raíz donde están las imágenes originales (filesystem)
 *   Estructura esperada: BAJAS_FS_ROOT\<cajon>\<barcode>\<nombramiento>
 * - EXPORTS_ROOT: carpeta donde se escribirán los exports
 */

require_once __DIR__ . '/inc/bootstrap.php';
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}

/* =========================
   CONFIG (AJUSTADO A TU RUTA)
   ========================= */
const BAJAS_FS_ROOT = 'G:\\AFDC_PORTABLE\\Bajas';
const EXPORTS_ROOT  = 'G:\\AFDC_PORTABLE\\Exports';

/* =========================
   RUNTIME SETTINGS
   ========================= */
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '1024M');

header('Content-Type: text/html; charset=utf-8');

/* =========================
   HELPERS
   ========================= */

function safe_seg(string $s): string {
  $s = trim($s);
  $s = str_replace(["..", "/", "\\", "\0"], '', $s);
  $s = preg_replace('/[<>:"|?*]/', '_', $s); // win forbidden chars
  return (string)$s;
}

function ensure_dir(string $dir): bool {
  if (is_dir($dir)) return true;
  return @mkdir($dir, 0775, true);
}

function now_stamp(): string {
  return date('Y-m-d_His');
}

function slug(string $s): string {
  $s = trim($s);
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);
  $s = trim($s, '_');
  if ($s === '') $s = 'coleccion';
  return substr($s, 0, 60);
}

function parse_image_key(string $imageKey): array {
  $imageKey = trim($imageKey);
  if ($imageKey === '' || strpos($imageKey, '_') === false) return ['', ''];
  [$b, $lab] = explode('_', $imageKey, 2);
  $b = safe_seg($b);
  $lab = preg_replace('/\D+/', '', (string)$lab);
  $lab = str_pad($lab, 3, '0', STR_PAD_LEFT);
  return [$b, $lab];
}

function label_from_filename(string $name): string {
  $lab = '999';
  if (preg_match('/_(\d{1,4})\.(jpe?g|png)$/i', $name, $m)) {
    $lab = str_pad((string)(int)$m[1], 3, '0', STR_PAD_LEFT);
  }
  return $lab;
}

function parse_yyyymmdd_to_iso(?string $raw): array {
  $raw = trim((string)$raw);
  if ($raw === '' || !preg_match('/^\d{8}$/', $raw)) return ['', 0];
  $y = (int)substr($raw, 0, 4);
  $m = (int)substr($raw, 4, 2);
  $d = (int)substr($raw, 6, 2);
  if (!checkdate($m, $d, $y)) return ['', 0];
  return [sprintf('%04d-%02d-%02d', $y, $m, $d), $y];
}

function out(string $s): void {
  echo $s;
  @ob_flush();
  @flush();
}

function log_line(string $logFile, string $line): void {
  @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function fs_join(string ...$parts): string {
  $p = [];
  foreach ($parts as $x) {
    $x = (string)$x;
    if ($x === '') continue;
    $p[] = rtrim($x, "\\/");
  }
  return implode(DIRECTORY_SEPARATOR, $p);
}

function table_exists(string $table): bool {
  $table = trim($table);
  if ($table === '') return false;

  // nombre de tabla controlado: solo letras, números y guion bajo
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;

  $rows = q(
    "SELECT 1
     FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = ?
     LIMIT 1",
    "s",
    [$table]
  );

  return !empty($rows);
}

function fetch_table_columns(string $table): array {
  if (!table_exists($table)) return [];
  $rows = q("SHOW COLUMNS FROM `{$table}`") ?: [];
  $cols = [];
  foreach ($rows as $r) {
    $f = (string)($r['Field'] ?? '');
    if ($f !== '') $cols[$f] = true;
  }
  return $cols;
}

function first_existing_key(array $row, array $candidates, string $default = ''): string {
  foreach ($candidates as $c) {
    if (array_key_exists($c, $row) && trim((string)$row[$c]) !== '') {
      return trim((string)$row[$c]);
    }
  }
  return $default;
}

/**
 * Devuelve rows de digitales para un barcode (inv).
 * Espera campos: cajon, nombramiento.
 */
function fetch_digitales_rows(string $barcode): array {
  $barcode = safe_seg($barcode);
  if ($barcode === '') return [];

  $rows = q(
    "SELECT cajon, nombramiento
     FROM digitales
     WHERE inv=?
       AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
       AND nombramiento IS NOT NULL
       AND nombramiento<>''",
    "s",
    [$barcode]
  ) ?: [];

  $out = [];
  foreach ($rows as $r) {
    $cj = safe_seg((string)($r['cajon'] ?? ''));
    $nm = safe_seg((string)($r['nombramiento'] ?? ''));
    if ($cj === '' || $nm === '') continue;
    $lab = label_from_filename($nm);
    $out[] = ['cajon'=>$cj, 'nombramiento'=>$nm, 'label'=>$lab];
  }

  usort($out, fn($a, $b) => (int)$a['label'] <=> (int)$b['label']);
  return $out;
}

function fetch_envelope_meta(string $barcode): array {
  $barcode = safe_seg($barcode);
  if ($barcode === '') return ['sys'=>'', 'date_iso'=>'', 'year'=>0, 'group'=>''];

  $t = q("SELECT sys, fecha FROM titulos WHERE barcode=? LIMIT 1", "s", [$barcode]);
  $sys = $t ? trim((string)($t[0]['sys'] ?? '')) : '';
  $fecha = $t ? trim((string)($t[0]['fecha'] ?? '')) : '';

  [$dateIso, $year] = parse_yyyymmdd_to_iso($fecha);

  $group = '';
  if ($sys !== '') {
    $r = q("SELECT titulo245 FROM registros WHERE sys=? AND titulo245 IS NOT NULL AND titulo245<>'' LIMIT 1", "s", [$sys]);
    $group = $r ? trim((string)($r[0]['titulo245'] ?? '')) : '';
  }

  return ['sys'=>$sys, 'date_iso'=>$dateIso, 'year'=>(int)$year, 'group'=>$group];
}

function group_has_partidos(string $titulo245): bool {
  $titulo245 = trim($titulo245);
  if ($titulo245 === '') return false;
  if (!table_exists('partidos')) return false;
  $x = q("SELECT 1 FROM partidos WHERE tituloReg=? LIMIT 1", "s", [$titulo245]);
  return (bool)$x;
}

function fetch_partidos_for_group(string $titulo245, array $allowedBarcodesSet): array {
  $titulo245 = trim($titulo245);
  if ($titulo245 === '' || !table_exists('partidos')) return [];

  $rows = q(
    "SELECT barcode, tituloSobre, fecha, equipo1, equipo2
     FROM partidos
     WHERE tituloReg=?
     ORDER BY fecha ASC, barcode ASC",
    "s",
    [$titulo245]
  ) ?: [];

  $out = [];
  foreach ($rows as $r) {
    $barcode = safe_seg((string)($r['barcode'] ?? ''));
    if ($barcode === '' || !isset($allowedBarcodesSet[$barcode])) continue;

    $fechaRaw = trim((string)($r['fecha'] ?? ''));
    [$dateIso, $year] = parse_yyyymmdd_to_iso($fechaRaw);

    $out[] = [
      'barcode' => $barcode,
      'title' => trim((string)($r['tituloSobre'] ?? '')),
      'date_iso' => $dateIso,
      'year' => (int)$year,
      'equipo1' => trim((string)($r['equipo1'] ?? '')),
      'equipo2' => trim((string)($r['equipo2'] ?? '')),
    ];
  }
  return $out;
}

function fetch_expo_row(int $expoId): ?array {
  if ($expoId <= 0 || !table_exists('expo_v1')) return null;
  $rows = q("SELECT * FROM expo_v1 WHERE id=? LIMIT 1", 'i', [$expoId]) ?: [];
  return $rows ? $rows[0] : null;
}

function fetch_expo_piece_rows(int $expoId): array {
  if ($expoId <= 0 || !table_exists('expo_piece_v1')) return [];
  return q(
    "SELECT *
     FROM expo_piece_v1
     WHERE expo_id=? AND is_hidden=0
     ORDER BY sort_order ASC, id ASC",
    'i',
    [$expoId]
  ) ?: [];
}

function resolve_collection_item_image_key(string $refId): string {
  return trim($refId);
}

function resolve_recorte_record(int $refId): ?array {
  if ($refId <= 0) return null;

  $candidates = [
    'recortes_edicion_impresa',
    'edicion_impresa_recortes',
    'impresos_recortes',
  ];

  foreach ($candidates as $table) {
    $cols = fetch_table_columns($table);
    if (!$cols) continue;
    if (!isset($cols['id'])) continue;

    $rows = q("SELECT * FROM `{$table}` WHERE id=? LIMIT 1", 'i', [$refId]) ?: [];
    if (!$rows) continue;
    $row = $rows[0];

    $path = first_existing_key($row, ['hero_src', 'src', 'ruta', 'ruta_archivo', 'archivo', 'file_path', 'image_path', 'img_path', 'url', 'imagen']);
    $title = first_existing_key($row, ['title', 'titulo', 'titulo_recorte', 'nombre']);
    $caption = first_existing_key($row, ['caption_html', 'epigrafe_html', 'epigrafe', 'descripcion', 'texto']);
    $dateRaw = first_existing_key($row, ['fecha', 'date_raw']);
    $dateIso = '';
    $year = 0;
    if (preg_match('/^\d{8}$/', $dateRaw)) {
      [$dateIso, $year] = parse_yyyymmdd_to_iso($dateRaw);
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw)) {
      $dateIso = $dateRaw;
      $year = (int)substr($dateIso, 0, 4);
    }

    return [
      'table' => $table,
      'id' => $refId,
      'src' => $path,
      'title' => $title,
      'caption_html' => $caption,
      'date_iso' => $dateIso,
      'year' => $year,
      'group' => first_existing_key($row, ['grupo', 'group_title', 'seccion', 'publicacion']),
      'barcode' => first_existing_key($row, ['barcode', 'inv', 'sobre']),
    ];
  }

  return null;
}

function copy_absolute_image_to_export(string $srcPath, string $dstDir, string $dstBaseName, string $logFile): array {
  $srcPath = trim($srcPath);
  if ($srcPath === '') return ['ok' => false, 'filename' => '', 'rel_url' => '', 'reason' => 'empty_src'];
  if (!is_file($srcPath)) {
    log_line($logFile, "[MISSING] $srcPath");
    return ['ok' => false, 'filename' => '', 'rel_url' => '', 'reason' => 'missing_src'];
  }

  if (!ensure_dir($dstDir)) {
    log_line($logFile, "[ERROR] No puedo crear dir destino: $dstDir");
    return ['ok' => false, 'filename' => '', 'rel_url' => '', 'reason' => 'mkdir_fail'];
  }

  $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
  if ($ext === '') $ext = 'jpg';
  $dstName = safe_seg($dstBaseName) . '.' . $ext;
  $dstPath = fs_join($dstDir, $dstName);
  if (!is_file($dstPath) && !@copy($srcPath, $dstPath)) {
    log_line($logFile, "[COPY_FAIL] $srcPath -> $dstPath");
    return ['ok' => false, 'filename' => '', 'rel_url' => '', 'reason' => 'copy_fail'];
  }

  return [
    'ok' => true,
    'filename' => $dstName,
    'rel_url' => 'img/' . rawurlencode($dstName),
    'reason' => '',
  ];
}

function hero_url_from_expo(?array $expo, array $items): string {
  if (!$expo) return '';

  $src = trim((string)($expo['hero_src'] ?? ''));
  if ($src !== '') {
    return $src;
  }

  $heroType = trim((string)($expo['hero_type'] ?? 'imagen'));
  $heroRefId = trim((string)($expo['hero_ref_id'] ?? ''));
  if ($heroRefId === '') {
    foreach ($items as $it) {
      if (!empty($it['url'])) return (string)$it['url'];
    }
    return '';
  }

    foreach ($items as $it) {
    if ((string)($it['ref_id'] ?? '') === $heroRefId && (string)($it['piece_type'] ?? '') === $heroType && !empty($it['url'])) {
      return (string)$it['url'];
    }
  }

  foreach ($items as $it) {
    if (!empty($it['url'])) return (string)$it['url'];
  }
  return '';
}

function build_index_html(array $expo, array $collection, array $manifest, string $heroUrl): string {
  $title = trim((string)($expo['title'] ?? 'Exposición'));
  $subtitle = trim((string)($expo['subtitle'] ?? ''));
  $kicker = trim((string)($expo['kicker'] ?? ''));
  $introHtml = trim((string)($expo['intro_html'] ?? ''));
  $ctaLabel = trim((string)($expo['cta_label'] ?? 'Explorar colección'));
  $ctaTarget = trim((string)($expo['cta_target'] ?? 'viewer.html'));
  $heroPosX = trim((string)($expo['hero_pos_x'] ?? '50%'));
  $heroPosY = trim((string)($expo['hero_pos_y'] ?? '50%'));
  $heroHeight = max(320, (int)($expo['hero_height_px'] ?? 520));
  $heroOverlay = (float)($expo['hero_overlay_opacity'] ?? 0.35);
  if ($heroOverlay < 0) $heroOverlay = 0;
  if ($heroOverlay > 0.95) $heroOverlay = 0.95;
  $heroOverlayBottom = min(0.95, $heroOverlay + 0.08);

  $countPieces = count((array)($manifest['items'] ?? []));
  $countGroups = count((array)($manifest['groups'] ?? []));
  $collectionTitle = trim((string)($collection['title'] ?? ''));
  $pageTitle = h($title) . ' — portable';
  $titleEsc = h($title);
  $subtitleEsc = h($subtitle);
  $kickerEsc = h($kicker);
  $ctaLabelEsc = h($ctaLabel);
  $ctaTargetEsc = h($ctaTarget);
  $heroCss = $heroUrl !== '' ? "background-image:url('" . h($heroUrl) . "');" : '';
  $collectionEsc = h($collectionTitle);
  $kickerHtml = $kickerEsc !== '' ? '<div class="kicker">' . $kickerEsc . '</div>' : '';
  $subtitleHtml = $subtitleEsc !== '' ? '<div class="subtitle">' . $subtitleEsc . '</div>' : '';
  $introBlockHtml = $introHtml !== '' ? $introHtml : '<p>Exposición portable generada desde AFDC.</p>';
  $collectionValueHtml = $collectionEsc !== '' ? $collectionEsc : 'Sin colección asociada';

  return <<<HTML
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{$pageTitle}</title>
  <style>
    :root{
      --bg:#0f1115;
      --panel:#151a21;
      --txt:#e8ecf2;
      --muted:#a2adba;
      --line:rgba(255,255,255,.11);
      --accent:#6aa7ff;
    }
    @media (prefers-color-scheme: light){
      :root{ --bg:#f6f7fb; --panel:#ffffff; --txt:#1b2230; --muted:#5b6677; --line:rgba(0,0,0,.10); --accent:#2b74ff; }
    }
    *{box-sizing:border-box;}
    body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--txt);}
    a{text-decoration:none;color:inherit;}
    .hero{
      min-height: {$heroHeight}px;
      position:relative;
      background-color:#111;
      background-size:cover;
      background-position: {$heroPosX} {$heroPosY};
      {$heroCss}
    }
    .hero::before{
      content:"";
      position:absolute; inset:0;
      background: linear-gradient(to bottom, rgba(0,0,0,{$heroOverlay}) 0%, rgba(0,0,0,{$heroOverlayBottom}) 60%, rgba(0,0,0,0.72) 100%);
    }
    .heroInner{
      position:relative;
      z-index:1;
      min-height: {$heroHeight}px;
      max-width: 1180px;
      margin: 0 auto;
      padding: 34px 22px 28px;
      display:flex;
      align-items:flex-end;
    }
    .heroText{max-width:780px;}
    .kicker{font-size:13px; letter-spacing:.09em; text-transform:uppercase; color:#d7dde7; opacity:.92; margin-bottom:8px;}
    h1{margin:0; font-size:clamp(34px, 6vw, 72px); line-height:.98;}
    .subtitle{margin-top:12px; font-size:clamp(16px, 2vw, 22px); color:#e6ebf2; opacity:.96;}
    .actions{margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;}
    .btn{
      display:inline-flex; align-items:center; gap:10px;
      border:1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.08);
      color:#fff;
      padding:12px 16px; border-radius:12px; font-weight:700;
    }
    .btn.primary{background:var(--accent); border-color:transparent; color:#fff;}
    .wrap{max-width:1180px; margin:0 auto; padding:24px 22px 40px;}
    .introGrid{display:grid; grid-template-columns:minmax(0, 1fr) 300px; gap:18px; align-items:start;}
    @media (max-width: 980px){ .introGrid{grid-template-columns:1fr;} }
    .panel{
      background:var(--panel);
      border:1px solid var(--line);
      border-radius:18px;
      padding:22px;
    }
    .intro{font-size:16px; line-height:1.7; color:var(--txt);}
    .intro p:first-child{margin-top:0;}
    .intro p:last-child{margin-bottom:0;}
    .meta{display:grid; gap:12px;}
    .metaItem{padding:14px 16px; border:1px solid var(--line); border-radius:14px; background:color-mix(in srgb, var(--panel) 75%, transparent);}
    .metaLabel{font-size:12px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:6px;}
    .metaValue{font-size:16px; font-weight:700;}
    .quick{margin-top:20px; display:flex; gap:12px; flex-wrap:wrap;}
    .linkCard{
      display:inline-flex; align-items:center; gap:10px;
      padding:12px 14px; border:1px solid var(--line); border-radius:12px;
      background:var(--panel); color:var(--txt);
    }
    .foot{margin-top:20px; color:var(--muted); font-size:13px;}
  </style>
</head>
<body>
  <section class="hero">
    <div class="heroInner">
      <div class="heroText">
        {$kickerHtml}
        <h1>{$titleEsc}</h1>
        {$subtitleHtml}
        <div class="actions">
          <a class="btn primary" href="{$ctaTargetEsc}">{$ctaLabelEsc}</a>
          <a class="btn" href="viewer.html">Ir al visor</a>
        </div>
      </div>
    </div>
  </section>

  <div class="wrap">
    <div class="introGrid">
      <div class="panel intro">
        {$introBlockHtml}
        <div class="quick">
          <a class="linkCard" href="viewer.html">Explorar piezas</a>
        </div>
      </div>
      <aside class="meta">
        <div class="metaItem">
          <div class="metaLabel">Piezas</div>
          <div class="metaValue">{$countPieces}</div>
        </div>
        <div class="metaItem">
          <div class="metaLabel">Grupos</div>
          <div class="metaValue">{$countGroups}</div>
        </div>
        <div class="metaItem">
          <div class="metaLabel">Colección base</div>
          <div class="metaValue">{$collectionValueHtml}</div>
        </div>
      </aside>
    </div>
    <div class="foot">Portable generado para visualización offline. Desde esta portada se accede al visor completo.</div>
  </div>
</body>
</html>
HTML;
}

/* =========================
   INPUT
   ========================= */
$collectionId = (int)($_GET['collection_id'] ?? ($_GET['id'] ?? 0));
$expoId = (int)($_GET['expo_id'] ?? 0);

if ($collectionId <= 0 && $expoId <= 0) {
  http_response_code(400);
  echo "Falta collection_id (o id) o expo_id. Ej: ?collection_id=2 · ?expo_id=1";
  exit;
}

/* =========================
   VALIDATE ROOT PATHS
   ========================= */
$bajasRoot = BAJAS_FS_ROOT;
$exportsRoot = EXPORTS_ROOT;

if (!is_dir($bajasRoot)) {
  http_response_code(500);
  echo "No existe BAJAS_FS_ROOT: <b>" . h($bajasRoot) . "</b>";
  exit;
}
if (!ensure_dir($exportsRoot)) {
  http_response_code(500);
  echo "No puedo crear EXPORTS_ROOT: <b>" . h($exportsRoot) . "</b>";
  exit;
}

/* =========================
   FETCH EXPO / COLLECTION
   ========================= */
$expo = null;
if ($expoId > 0) {
  $expo = fetch_expo_row($expoId);
  if (!$expo) {
    http_response_code(404);
    echo "No existe la exposición #{$expoId}.";
    exit;
  }
  if ($collectionId <= 0) {
    $collectionId = (int)($expo['source_collection_id'] ?? 0);
  }
}

$col = [
  'id' => 0,
  'title' => $expo ? (string)($expo['title'] ?? 'Exposición') : 'Colección',
  'description' => $expo ? (string)($expo['subtitle'] ?? '') : '',
];

if ($collectionId > 0) {
  $colRow = q("SELECT id, title, description FROM collections_v2 WHERE id=? LIMIT 1", "i", [$collectionId]);
  if (!$colRow) {
    http_response_code(404);
    echo "No existe la colección #{$collectionId}.";
    exit;
  }
  $col = $colRow[0];
} elseif (!$expo) {
  http_response_code(400);
  echo "No hay colección ni exposición válidas para exportar.";
  exit;
}

/* =========================
   FETCH SOURCE ITEMS
   ========================= */
$imageKeys = [];
$pieceRows = [];

if ($expo) {
  $pieceRows = fetch_expo_piece_rows($expoId);
  if (!$pieceRows) {
    http_response_code(404);
    echo "La exposición no tiene piezas activas.";
    exit;
  }

    foreach ($pieceRows as $piece) {
    $pieceType = trim((string)($piece['piece_type'] ?? ''));
    $refId = trim((string)($piece['ref_id'] ?? ''));
    if ($pieceType === 'imagen' && $refId !== '') {
      $ik = resolve_collection_item_image_key($refId);
      if ($ik !== '') $imageKeys[] = $ik;
    }
  }

  $imageKeys = array_values(array_unique($imageKeys));
} else {
  $itemRows = q(
    "SELECT image_key
     FROM collection_items_v2
     WHERE collection_id=?
     ORDER BY position ASC, image_key ASC",
    "i",
    [$collectionId]
  ) ?: [];
  $imageKeys = array_values(array_filter(array_map(fn($r) => (string)$r['image_key'], $itemRows)));
  if (!$imageKeys) {
    http_response_code(404);
    echo "La colección no tiene items.";
    exit;
  }
}

if (!$expo && !$imageKeys) {
  http_response_code(404);
  echo "La colección no tiene items.";
  exit;
}

/* =========================
   PREP EXPORT DIR
   ========================= */
$exportBaseLabel = $expo
  ? ('expo_' . $expoId . '_' . slug((string)$expo['title']))
  : ('export_col_' . $collectionId . '_' . slug((string)$col['title']));
$exportName = $exportBaseLabel . '_' . now_stamp();
$exportDir  = fs_join($exportsRoot, $exportName);
$imgDir     = fs_join($exportDir, 'img');

if (!ensure_dir($exportDir) || !ensure_dir($imgDir)) {
  http_response_code(500);
  echo "No puedo crear carpeta de export: <b>" . h($exportDir) . "</b>";
  exit;
}

$logFile = fs_join($exportDir, 'export.log');
@file_put_contents(
  $logFile,
  "Export: {$exportName}\n" .
  "Collection: #{$collectionId} " . (string)$col['title'] . "\n" .
  ($expo ? "Expo: #{$expoId} " . (string)$expo['title'] . "\n" : '') .
  "Bajas root: {$bajasRoot}\n\n"
);

@ob_end_flush();
@ob_implicit_flush(true);

out("<!doctype html><html><head><meta charset='utf-8'><title>Export portable</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial;margin:18px;}
pre{background:#111;color:#ddd;padding:12px;border-radius:10px;overflow:auto;}
.bad{color:#d44;} .ok{color:#4c4;}
</style>
</head><body>");
out("<h2>Export portable</h2>");
out("<div>Collection: <b>" . h((string)$col['title']) . "</b> (#" . (int)$collectionId . ")</div>");
if ($expo) {
  out("<div>Expo: <b>" . h((string)$expo['title']) . "</b> (#" . (int)$expoId . ")</div>");
}
out("<div>Output: <code>" . h($exportDir) . "</code></div>");
out("<pre>");

/* =========================
   BUILD BARCODE SET
   ========================= */
$barcodesSet = [];
$parsedKeys = []; // list of ['image_key','barcode','label']
foreach ($imageKeys as $k) {
  [$b, $lab] = parse_image_key($k);
  $parsedKeys[] = ['image_key'=>$k, 'barcode'=>$b, 'label'=>$lab];
  if ($b !== '') $barcodesSet[$b] = true;
}
$barcodes = array_keys($barcodesSet);

out("Items imagen origen: " . count($imageKeys) . "\n");
out("Sobres únicos: " . count($barcodes) . "\n");
if ($expo) out("Piezas expo: " . count($pieceRows) . "\n");
out("\n");

/* =========================
   COPY ENVELOPES + BUILD OFFLINE MAPS
   ========================= */
$envelope_images = []; // barcode => list [{label,url,name}]
$label_to_filename = []; // barcode => [label => filename]
$missing_files = 0;
$copied_files = 0;
$skipped_files = 0;

$barIdx = 0;
foreach ($barcodes as $barcode) {
  $barIdx++;
  out("[" . $barIdx . "/" . count($barcodes) . "] Sobre " . $barcode . " ... ");

  $rows = fetch_digitales_rows($barcode);
  if (!$rows) {
    out("SIN ROWS en digitales\n");
    log_line($logFile, "[WARN] Sin rows digitales para $barcode");
    $envelope_images[$barcode] = [];
    $label_to_filename[$barcode] = [];
    continue;
  }

  $dstBarcodeDir = fs_join($imgDir, $barcode);
  if (!ensure_dir($dstBarcodeDir)) {
    out("ERROR mkdir\n");
    log_line($logFile, "[ERROR] No puedo crear dir destino: $dstBarcodeDir");
    $envelope_images[$barcode] = [];
    $label_to_filename[$barcode] = [];
    continue;
  }

  $imgs = [];
  $labMap = [];

  foreach ($rows as $r) {
    $cj = (string)$r['cajon'];
    $nm = (string)$r['nombramiento'];
    $lab = (string)$r['label'];

    $src = fs_join($bajasRoot, $cj, $barcode, $nm);
    $dst = fs_join($dstBarcodeDir, $nm);

    $relUrl = 'img/' . rawurlencode($barcode) . '/' . rawurlencode($nm);

    $imgs[] = ['label'=>$lab, 'url'=>$relUrl, 'name'=>$nm];
    if (!isset($labMap[$lab])) $labMap[$lab] = $nm;

    if (is_file($dst)) {
      $skipped_files++;
      continue;
    }

    if (!is_file($src)) {
      $missing_files++;
      log_line($logFile, "[MISSING] $src");
      continue;
    }

    if (@copy($src, $dst)) {
      $copied_files++;
    } else {
      $missing_files++;
      log_line($logFile, "[COPY_FAIL] $src -> $dst");
    }
  }

  usort($imgs, fn($a,$b)=> (int)$a['label'] <=> (int)$b['label']);

  $envelope_images[$barcode] = $imgs;
  $label_to_filename[$barcode] = $labMap;

  out("ok (" . count($imgs) . " imgs)\n");
}

/* =========================
   COPY EXTRA EXPO ASSETS (recortes / hero absolutos)
   ========================= */
$extra_assets = [];
$recorteCache = [];

if ($expo) {
  $extraDir = fs_join($imgDir, '_extra');
  foreach ($pieceRows as $piece) {
    $pieceType = trim((string)($piece['piece_type'] ?? ''));
    $refId = (int)($piece['ref_id'] ?? 0);
    if ($pieceType !== 'recorte_impreso' || $refId <= 0) continue;

    $rec = resolve_recorte_record($refId);
    if (!$rec) {
      log_line($logFile, "[WARN] No pude resolver recorte_impreso ref_id={$refId}");
      continue;
    }
    $recorteCache[$refId] = $rec;

    $src = trim((string)($rec['src'] ?? ''));
    if ($src === '') {
      log_line($logFile, "[WARN] Recorte sin src ref_id={$refId}");
      continue;
    }

    $copy = copy_absolute_image_to_export($src, $extraDir, 'recorte_' . $refId, $logFile);
    if (!$copy['ok']) continue;
    $extra_assets['recorte_impreso:' . $refId] = $copy['rel_url'];
  }

  $heroSrc = trim((string)($expo['hero_src'] ?? ''));
  if ($heroSrc !== '' && preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', $heroSrc)) {
    $copy = copy_absolute_image_to_export($heroSrc, $imgDir, '_hero', $logFile);
    if ($copy['ok']) {
      $expo['hero_src'] = $copy['rel_url'];
    } else {
      log_line($logFile, "[WARN] No pude copiar hero_src absoluto: {$heroSrc}");
    }
  }
}

out("\nCopiados: $copied_files · Saltados (ya existían): $skipped_files · Faltantes/Error: $missing_files\n\n");

/* =========================
   BUILD ENVELOPES + GROUPS
   ========================= */
$envelopes = []; // barcode => meta
$groupsBySys = []; // sys => group struct
foreach ($barcodes as $barcode) {
  $meta = fetch_envelope_meta($barcode);
  $envelopes[$barcode] = [
    'barcode' => $barcode,
    'sys' => (string)$meta['sys'],
    'group' => (string)$meta['group'],
    'date_iso' => (string)$meta['date_iso'],
    'year' => (int)$meta['year'],
  ];

  $sys = (string)$meta['sys'];
  $g = (string)$meta['group'];
  if ($sys !== '') {
    if (!isset($groupsBySys[$sys])) {
      $hasMatches = ($g !== '') ? group_has_partidos($g) : false;
      $groupsBySys[$sys] = [
        'sys' => $sys,
        'title245' => $g,
        'has_matches' => $hasMatches,
        'kind' => $hasMatches ? 'competition' : 'generic',
        'year_min' => (int)$meta['year'],
        'year_max' => (int)$meta['year'],
        'count_envelopes' => 1,
      ];
    } else {
      $groupsBySys[$sys]['year_min'] = min((int)$groupsBySys[$sys]['year_min'], (int)$meta['year']);
      $groupsBySys[$sys]['year_max'] = max((int)$groupsBySys[$sys]['year_max'], (int)$meta['year']);
      $groupsBySys[$sys]['count_envelopes']++;
    }
  }
}

/* =========================
   BUILD ITEMS
   ========================= */
$items = [];

if ($expo) {
  foreach ($pieceRows as $piece) {
    $pieceId = (int)($piece['id'] ?? 0);
    $pieceType = trim((string)($piece['piece_type'] ?? ''));
    $refId = trim((string)($piece['ref_id'] ?? ''));
    $pieceTitle = trim((string)($piece['title'] ?? ''));
    $pieceSubtitle = trim((string)($piece['subtitle'] ?? ''));
    $pieceCaption = (string)($piece['caption_html'] ?? '');

    if ($pieceType === 'imagen') {
      $imageKey = resolve_collection_item_image_key($refId);
      if ($imageKey === '') {
        log_line($logFile, "[WARN] No pude resolver image_key para expo_piece #{$pieceId} ref_id={$refId}");
        continue;
      }

      [$barcode, $lab] = parse_image_key($imageKey);
      $env = $envelopes[$barcode] ?? ['sys'=>'','group'=>'','date_iso'=>'','year'=>0];
      $fname = $label_to_filename[$barcode][$lab] ?? '';
      $relUrl = $fname !== '' ? ('img/' . rawurlencode($barcode) . '/' . rawurlencode($fname)) : '';

      $items[] = [
        'piece_id' => $pieceId,
        'piece_type' => 'imagen',
        'ref_id' => $refId,
        'image_key' => $imageKey,
        'barcode' => $barcode,
        'label' => $lab,
        'url' => $relUrl,
        'exists' => ($relUrl !== ''),
        'sys' => (string)($env['sys'] ?? ''),
        'group' => $pieceSubtitle !== '' ? $pieceSubtitle : (string)($env['group'] ?? ''),
        'date_iso' => (string)($env['date_iso'] ?? ''),
        'year' => (int)($env['year'] ?? 0),
        'display_title' => $pieceTitle !== '' ? $pieceTitle : $imageKey,
        'caption_html' => $pieceCaption,
      ];
      continue;
    }

    if ($pieceType === 'recorte_impreso') {
      $rec = $recorteCache[$refId] ?? resolve_recorte_record($refId);
      if (!$rec) {
        log_line($logFile, "[WARN] No pude resolver recorte para expo_piece #{$pieceId} ref_id={$refId}");
        continue;
      }
      $relUrl = (string)($extra_assets['recorte_impreso:' . $refId] ?? '');
      if ($relUrl === '' && !empty($rec['src']) && !preg_match('/^[A-Za-z]:\\\\|^\\\\|^\//', (string)$rec['src'])) {
        $relUrl = (string)$rec['src'];
      }
      $syntheticBarcode = trim((string)($rec['barcode'] ?? ''));
      if ($syntheticBarcode === '') $syntheticBarcode = 'RECORTE-' . $refId;
      $title = $pieceTitle !== '' ? $pieceTitle : (trim((string)($rec['title'] ?? '')) ?: ('Recorte ' . $refId));
      $group = $pieceSubtitle !== '' ? $pieceSubtitle : trim((string)($rec['group'] ?? ''));

      $items[] = [
        'piece_id' => $pieceId,
        'piece_type' => 'recorte_impreso',
        'ref_id' => $refId,
        'image_key' => 'recorte_' . $refId,
        'barcode' => $syntheticBarcode,
        'label' => '000',
        'url' => $relUrl,
        'exists' => ($relUrl !== ''),
        'sys' => '',
        'group' => $group,
        'date_iso' => (string)($rec['date_iso'] ?? ''),
        'year' => (int)($rec['year'] ?? 0),
        'display_title' => $title,
        'caption_html' => $pieceCaption !== '' ? $pieceCaption : (string)($rec['caption_html'] ?? ''),
      ];
      continue;
    }

    log_line($logFile, "[WARN] piece_type no soportado en v1: {$pieceType} (piece #{$pieceId})");
  }
} else {
  foreach ($parsedKeys as $pk) {
    $barcode = (string)$pk['barcode'];
    $lab = (string)$pk['label'];
    $imageKey = (string)$pk['image_key'];

    $env = $envelopes[$barcode] ?? ['sys'=>'','group'=>'','date_iso'=>'','year'=>0];

    $fname = $label_to_filename[$barcode][$lab] ?? '';
    $relUrl = $fname !== '' ? ('img/' . rawurlencode($barcode) . '/' . rawurlencode($fname)) : '';

    $items[] = [
      'piece_id' => 0,
      'piece_type' => 'imagen',
      'ref_id' => 0,
      'image_key' => $imageKey,
      'barcode' => $barcode,
      'label' => $lab,
      'url' => $relUrl,
      'exists' => ($relUrl !== ''),
      'sys' => (string)($env['sys'] ?? ''),
      'group' => (string)($env['group'] ?? ''),
      'date_iso' => (string)($env['date_iso'] ?? ''),
      'year' => (int)($env['year'] ?? 0),
      'display_title' => $imageKey,
      'caption_html' => '',
    ];
  }
}

if (!$items) {
  http_response_code(404);
  out("\nERROR: no hay piezas exportables.\n");
  out("</pre></body></html>");
  exit;
}

/* =========================
   MATCHES (offline, por sys)
   ========================= */
$matches_by_sys = [];
foreach ($groupsBySys as $sys => $ginfo) {
  if (empty($ginfo['has_matches'])) continue;
  $titulo245 = (string)($ginfo['title245'] ?? '');
  if ($titulo245 === '') continue;
  $matches_by_sys[$sys] = fetch_partidos_for_group($titulo245, $barcodesSet);
}

$manifest = [
  'collection' => [
    'id' => (int)($col['id'] ?? 0),
    'title' => (string)($col['title'] ?? ''),
    'description' => (string)($col['description'] ?? ''),
    'count_items' => count($items),
  ],
  'expo' => $expo ? [
    'id' => (int)$expoId,
    'title' => (string)($expo['title'] ?? ''),
    'kicker' => (string)($expo['kicker'] ?? ''),
    'subtitle' => (string)($expo['subtitle'] ?? ''),
    'template_name' => (string)($expo['template_name'] ?? ''),
  ] : null,
  'groups' => array_values($groupsBySys),
  'items' => $items,
  'envelopes' => $envelopes,
  'envelope_images' => $envelope_images,
  'matches_by_sys' => $matches_by_sys,
];

/* =========================
   WRITE VIEWER.HTML (offline)
   ========================= */
$viewerHtmlPath = fs_join($exportDir, 'viewer.html');

$displayTitle = $expo ? (string)($expo['title'] ?? $col['title']) : (string)$col['title'];
$displayTitleEsc = h($displayTitle);
$pageTitleEsc = $displayTitleEsc . " — portable";
$manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$viewerHtml = <<<'HTML'
<!doctype html>
<html lang='es'>
<head>
  <meta charset='utf-8' />
  <meta name='viewport' content='width=device-width, initial-scale=1' />
  <title>__PAGE_TITLE__</title>
  <style>
    :root{
      --bg: #0f1115;
      --card: #151a21;
      --muted: #9aa4b2;
      --line: rgba(255,255,255,.10);
      --txt: #e8ecf2;
      --accent: #6aa7ff;
      --good: #22c55e;
      --warn: #f59e0b;
    }
    @media (prefers-color-scheme: light){
      :root{ --bg:#f6f7fb; --card:#ffffff; --txt:#1b2230; --muted:#5b6677; --line:rgba(0,0,0,.10); --accent:#2b74ff; }
    }
    body{ margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; background:var(--bg); color:var(--txt); }
    .wrap{ display:grid; grid-template-rows: auto 1fr; min-height:100vh; }
    .topbar{
      position:sticky; top:0; z-index:5;
      background: color-mix(in srgb, var(--bg) 88%, transparent);
      backdrop-filter: blur(10px);
      border-bottom:1px solid var(--line);
      padding:12px 14px;
      display:flex; gap:12px; align-items:center; justify-content:space-between;
    }
    .title{ font-weight:800; font-size:16px; display:flex; gap:10px; align-items:center; }
    .sub{ color:var(--muted); font-size:12px; margin-top:2px; }
    .pill{ font-size:12px; border:1px solid var(--line); padding:6px 10px; border-radius:999px; color:var(--muted); }
    .layout{ display:grid; grid-template-columns: 360px 1fr; gap:12px; padding:12px; }
    @media (max-width: 980px){ .layout{ grid-template-columns: 1fr; } }
    .panel{
      background:var(--card); border:1px solid var(--line); border-radius:14px;
      padding:12px;
      position:sticky; top:66px; align-self:start;
      max-height: calc(100vh - 84px);
      overflow:auto;
    }
    .row{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    label{ display:block; font-size:12px; color:var(--muted); margin:10px 0 6px; }
    select,input{
      width:100%;
      border:1px solid var(--line); background:transparent; color:var(--txt);
      padding:10px 10px; border-radius:10px;
      outline:none;
    }
    input::placeholder{ color: color-mix(in srgb, var(--muted) 70%, transparent); }
    .btn{
      border:1px solid var(--line); background:transparent; color:var(--txt);
      padding:10px 12px; border-radius:10px; cursor:pointer;
      text-decoration:none; display:inline-flex; gap:8px; align-items:center;
    }
    .btn:hover{ border-color: color-mix(in srgb, var(--accent) 60%, var(--line)); }
    .muted{ color:var(--muted); }

    .grid{
      display:grid;
      grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
      gap:10px;
    }
    .card{
      background:var(--card); border:1px solid var(--line); border-radius:14px;
      overflow:hidden;
      cursor:pointer;
      transition: transform .08s ease;
    }
    .card:hover{ transform: translateY(-1px); }
    .thumb{
      width:100%; aspect-ratio: 4/3; object-fit:cover; display:block; background: rgba(0,0,0,.08);
    }
    .meta{ padding:10px; font-size:12px; color:var(--muted); }
    .meta b{ color:var(--txt); }
    .meta .cap{ margin-top:4px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }

    .matchesBox{
      border:1px solid var(--line);
      border-radius:12px;
      overflow:hidden;
      margin-top:10px;
    }
    .matchesHead{
      padding:10px;
      border-bottom:1px solid var(--line);
      background: color-mix(in srgb, var(--bg) 65%, transparent);
      font-size:12px;
      color:var(--muted);
      display:flex; align-items:center; justify-content:space-between; gap:10px;
    }
    .matchesHead .left{
      display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    }
    .matchesList{
      max-height: 320px;
      overflow:auto;
    }
    .mrow{
      padding:10px;
      border-bottom:1px solid var(--line);
      cursor:pointer;
      display:flex;
      gap:10px;
      align-items:flex-start;
    }
    .mrow:hover{ background: color-mix(in srgb, var(--accent) 10%, transparent); }
    .mrow:last-child{ border-bottom:none; }
    .mrow.on{
      background: color-mix(in srgb, var(--accent) 16%, transparent);
      outline: 2px solid color-mix(in srgb, var(--accent) 55%, transparent);
      outline-offset: -2px;
    }
    .mdate{
      font-variant-numeric: tabular-nums;
      min-width: 82px;
      color: var(--muted);
      font-size:12px;
      white-space: nowrap;
    }
    .mtitle{
      font-size:12px; line-height:1.25;
      color: var(--txt);
      font-weight: 650;
    }
    .msub{
      font-size:12px; line-height:1.25;
      color: var(--muted);
      margin-top:2px;
    }
    .badge{
      display:inline-flex;
      padding:2px 8px;
      border-radius:999px;
      border:1px solid var(--line);
      font-size:11px;
      color: var(--muted);
      white-space: nowrap;
    }
    .badge.ok{ border-color: color-mix(in srgb, var(--good) 40%, var(--line)); color: color-mix(in srgb, var(--good) 80%, var(--txt)); }
    .badge.warn{ border-color: color-mix(in srgb, var(--warn) 45%, var(--line)); color: color-mix(in srgb, var(--warn) 85%, var(--txt)); }
    .linkbtn{
      border:none;
      background:transparent;
      color: var(--accent);
      cursor:pointer;
      padding:0;
      font-size:12px;
      text-decoration: underline;
    }

    .modal{
      position:fixed; inset:0; background: rgba(0,0,0,.55);
      display:none; z-index:50;
      align-items:center; justify-content:center;
      padding:14px;
    }
    .modal.on{ display:flex; }
    .viewer{
      width:min(1200px, 96vw);
      height:min(780px, 92vh);
      min-height:0;
      background:var(--card);
      border:1px solid var(--line);
      border-radius:16px;
      overflow:hidden;
      display:grid;
      grid-template-rows: auto 1fr auto;
    }
    .vtop{
      padding:10px 12px;
      border-bottom:1px solid var(--line);
      display:flex; gap:10px; align-items:center; justify-content:space-between;
    }
    .vinfo{ font-size:12px; color:var(--muted); line-height:1.35; }
    .vinfo b{ color:var(--txt); }
    .vmain{
      display:grid;
      grid-template-columns: 1fr 280px;
      gap:0;
      height:100%;
      min-height:0;
    }
    @media (max-width: 980px){ .vmain{ grid-template-columns: 1fr; } .vside{ display:none; } }

    .vimgwrap{
      background: #000;
      display:flex; align-items:center; justify-content:center;
      overflow:hidden;
      user-select:none;
    }
    .vimg{
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
      display:block;
      cursor: zoom-in;
    }

    .vside{
      border-left:1px solid var(--line);
      padding:10px;
      overflow:hidden;
      display:flex;
      flex-direction:column;
      gap:10px;
      min-height:0;
    }
    .vsideTitle{
      font-size:12px; color:var(--muted);
      display:flex; align-items:center; justify-content:space-between;
      gap:10px;
      flex: 0 0 auto;
    }

    .pinBox{
      border:1px solid var(--line);
      border-radius:12px;
      overflow:hidden;
      background: color-mix(in srgb, var(--bg) 60%, transparent);
      flex: 0 0 auto;
      cursor:pointer;
    }
    .pinImg{
      width:100%;
      height:140px;
      object-fit:cover;
      display:block;
      background: rgba(0,0,0,.08);
    }
    .pinMeta{
      padding:8px 10px;
      font-size:12px;
      color:var(--muted);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:8px;
      border-top:1px solid var(--line);
    }
    .pinMeta b{ color:var(--txt); }

    .thumbPane{
      border:1px solid var(--line);
      border-radius:12px;
      overflow:auto;
      padding:10px;
      background: color-mix(in srgb, var(--bg) 60%, transparent);
      flex: 1 1 auto;
      min-height: 0;
      height: 0;
    }
    .thumbGrid{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap:8px;
    }
    .timg{
      width:100%;
      aspect-ratio: 1 / 1;
      object-fit:cover;
      border-radius:10px;
      border:2px solid transparent;
      cursor:pointer;
      opacity:.95;
      background: rgba(0,0,0,.08);
    }
    .timg:hover{ opacity:1; }
    .timg.on{ border-color: var(--accent); opacity:1; }
    .timg.incol{
      border-color: color-mix(in srgb, var(--accent) 80%, #ffffff 0%);
      box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 35%, transparent);
      opacity:1;
      filter:none;
    }
    .timg.notcol{
      opacity:.62;
      filter: grayscale(.35) contrast(.95);
    }

    .strip{ display:none !important; }
  </style>
</head>
<body>
<div class='wrap'>
  <div class='topbar'>
    <div>
      <div class='title'>__COL_TITLE__ <span class='pill'>portable</span></div>
      <div class='sub' id='sub'></div>
    </div>
    <div class='row'>
      <a class='btn' id='btnHome' href='index.html' style='display:none;'>Portada</a>
      <button class='btn' id='btnReset' type='button'>Reset</button>
    </div>
  </div>
  <div class='layout'>
    <aside class='panel'>
      <label>Grupo</label>
      <select id='fGroup'><option value=''>Todos</option></select>

      <div id='boxCompetition' style='display:none;'>
        <label>Equipo</label>
        <select id='fTeam'><option value=''>Todos</option></select>

        <label>Buscar partido</label>
        <input id='fMatchText' type='text' placeholder='boca · river · 1974...' />

        <div class='matchesBox'>
          <div class='matchesHead'>
            <div class='left'>
              <span>Partidos</span>
              <span class='badge ok' id='matchesStat'>0</span>
              <span class='badge warn' id='matchFilterBadge' style='display:none;'>
                Sobre <b id='matchFilterBarcode' style='margin-left:6px;'></b>
              </span>
            </div>
            <button class='linkbtn' id='btnClearMatch' type='button' style='display:none;'>Quitar</button>
          </div>
          <div class='matchesList' id='matchesList'></div>
        </div>
      </div>

      <div id='boxGeneric' style='display:none;'>
        <label>Año</label>
        <select id='fYear'><option value=''>Todos</option></select>

        <label>Buscar</label>
        <input id='fText' type='text' placeholder='FO067408 · metropolitano · 1974' />
      </div>

      <div class='row' style='margin-top:10px;'>
        <span class='muted' id='stat' style='font-size:12px;'></span>
      </div>
    </aside>

    <main>
      <div class='grid' id='grid'></div>
      <div class='muted' id='empty' style='display:none; padding:18px; text-align:center;'>No hay resultados.</div>
    </main>
  </div>
</div>

<div class='modal' id='modal'>
  <div class='viewer'>
    <div class='vtop'>
      <div class='vinfo' id='vinfo'></div>
      <button class='btn' id='closeBtn' type='button'>Cerrar</button>
    </div>

    <div class='vmain'>
      <div class='vimgwrap' id='vimgwrap'>
        <img class='vimg' id='vimg' alt='' title='Doble click: abrir imagen completa en una nueva pestaña' />
      </div>

      <div class='vside'>
        <div class='vsideTitle'>
          <span>Resto del sobre</span>
          <span class='muted' id='vsideCount' style='font-size:12px;'></span>
        </div>

        <div class='pinBox' id='pinBox' title='Click: ver _000 · Doble click: abrir en nueva pestaña'>
          <img class='pinImg' id='pinImg' alt=''>
          <div class='pinMeta'>
            <div><b id='pinLabel'>_000</b></div>
            <div class='muted' id='pinBadge' style='font-size:12px;'></div>
          </div>
        </div>

        <div class='thumbPane'>
          <div class='thumbGrid' id='thumbGrid'></div>
        </div>
      </div>
    </div>

    <div class='strip' id='strip'></div>
  </div>
</div>

<script>
window.__MANIFEST__ = __MANIFEST_JSON__;
</script>

<script>
(function(){
  const M = window.__MANIFEST__ || {};
  const grid = document.getElementById('grid');
  const empty = document.getElementById('empty');
  const stat = document.getElementById('stat');
  const sub = document.getElementById('sub');
  const btnHome = document.getElementById('btnHome');

  const fGroup = document.getElementById('fGroup');

  const boxCompetition = document.getElementById('boxCompetition');
  const fTeam = document.getElementById('fTeam');
  const fMatchText = document.getElementById('fMatchText');
  const matchesList = document.getElementById('matchesList');
  const matchesStat = document.getElementById('matchesStat');
  const btnClearMatch = document.getElementById('btnClearMatch');
  const matchFilterBadge = document.getElementById('matchFilterBadge');
  const matchFilterBarcode = document.getElementById('matchFilterBarcode');

  const boxGeneric = document.getElementById('boxGeneric');
  const fYear  = document.getElementById('fYear');
  const fText  = document.getElementById('fText');

  const btnReset = document.getElementById('btnReset');

  const modal = document.getElementById('modal');
  const vimg = document.getElementById('vimg');
  const vimgwrap = document.getElementById('vimgwrap');
  const vinfo = document.getElementById('vinfo');
  const closeBtn = document.getElementById('closeBtn');

  const vsideCount = document.getElementById('vsideCount');
  const pinBox = document.getElementById('pinBox');
  const pinImg = document.getElementById('pinImg');
  const pinLabel = document.getElementById('pinLabel');
  const pinBadge = document.getElementById('pinBadge');
  const thumbGrid = document.getElementById('thumbGrid');

  const items = M.items || [];
  const envelopes = M.envelopes || {};
  const envImgs = M.envelope_images || {};
  const matchesBySys = M.matches_by_sys || {};

  if (M.expo) {
    btnHome.style.display = '';
  }

  const groupsBySys = {};
  (M.groups || []).forEach(g => { groupsBySys[g.sys] = g; });

  const colLabelsByBarcode = new Map();
  items.forEach(it => {
    const b = (it.barcode || '').trim();
    const lab = (it.label || '').trim();
    if (!b || !lab) return;
    if (!colLabelsByBarcode.has(b)) colLabelsByBarcode.set(b, new Set());
    colLabelsByBarcode.get(b).add(lab);
  });

  function norm(s){ return (s||'').toString().toLowerCase().trim(); }
  function escapeHtml(s){
    return (s||'').toString().replace(/[&<>\"]/g, m => (
      m === '&' ? '&amp;' :
      m === '<' ? '&lt;' :
      m === '>' ? '&gt;' : '&quot;'
    ));
  }
  function resetViewerScroll(){
    if (!vimgwrap) return;
    vimgwrap.scrollTop = 0;
    vimgwrap.scrollLeft = 0;
  }
  function openImageNewTab(url){
    if (!url) return;
    window.open(url, '_blank', 'noopener');
  }
  vimg.addEventListener('dblclick', () => openImageNewTab(vimg.src || ''));

  const groupsSorted = (M.groups || [])
    .filter(g => (g.title245||'').trim() !== '')
    .sort((a,b)=> (a.title245||'').localeCompare(b.title245||'', 'es'));

  groupsSorted.forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.sys;
    opt.textContent = g.title245 + (g.has_matches ? '  • partidos' : '');
    fGroup.appendChild(opt);
  });

  let mode = 'generic';
  let currentSys = '';
  let currentMatches = [];
  let currentMatchBarcode = '';

  function setMode(next){
    mode = next;
    if (mode === 'competition') {
      boxCompetition.style.display = '';
      boxGeneric.style.display = 'none';
    } else {
      boxCompetition.style.display = 'none';
      boxGeneric.style.display = '';
    }
  }

  function rebuildYearOptions(sys){
    const ys = new Set();
    items.forEach(it => {
      if (sys && String(it.sys||'') !== String(sys)) return;
      if (it.year) ys.add(it.year);
    });
    const years = Array.from(ys).sort((a,b)=>a-b);
    const current = fYear.value || '';
    fYear.innerHTML = `<option value=''>Todos</option>`;
    years.forEach(y => {
      const opt = document.createElement('option');
      opt.value = String(y);
      opt.textContent = String(y);
      fYear.appendChild(opt);
    });
    if (current && years.includes(parseInt(current, 10))) fYear.value = current;
    else fYear.value = '';
  }

  function renderGrid(list){
    grid.innerHTML = '';
    empty.style.display = list.length ? 'none' : 'block';

    list.forEach(it => {
      const card = document.createElement('div');
      card.className = 'card';

      const img = document.createElement('img');
      img.className = 'thumb';
      img.loading = 'lazy';
      img.src = it.url || '';
      img.alt = it.display_title || it.image_key || '';
      card.appendChild(img);

      const meta = document.createElement('div');
      meta.className = 'meta';
      const line1 = it.display_title ? `<b>${escapeHtml(it.display_title)}</b>` : `<b>${escapeHtml(it.barcode||'')}</b> · ${escapeHtml(it.label||'')}`;
      const line2 = [it.group||'', it.year ? String(it.year) : '', it.piece_type === 'recorte_impreso' ? 'recorte' : ''].filter(Boolean).join(' · ');
      const cap = it.caption_html ? `<div class='cap'>${it.caption_html}</div>` : '';
      meta.innerHTML = `<div>${line1}</div><div>${escapeHtml(line2)}</div>${cap}`;
      card.appendChild(meta);

      card.addEventListener('click', () => openViewerFromItem(it));
      grid.appendChild(card);
    });
  }

  function applyGenericFilters(){
    const sys = (fGroup.value || '').trim();
    const year = parseInt(fYear.value || '0', 10);
    const txt = norm(fText.value);

    const out = items.filter(it => {
      if (sys && String(it.sys||'') !== sys) return false;
      if (year && parseInt(it.year||0,10) !== year) return false;
      if (txt) {
        const hay = [it.barcode||'', it.group||'', it.date_iso||'', String(it.year||''), it.display_title||''].join(' ');
        if (!norm(hay).includes(txt)) return false;
      }
      return true;
    });

    renderGrid(out);
    stat.textContent = out.length ? (out.length + ' resultados') : '';
  }

  function renderCompetitionGrid(sys, onlyBarcode = ''){
    const out = items.filter(it => {
      if (String(it.sys||'') !== String(sys)) return false;
      if (onlyBarcode && String(it.barcode||'') !== String(onlyBarcode)) return false;
      return true;
    });
    renderGrid(out);
    stat.textContent = onlyBarcode ? `${out.length} imgs (${onlyBarcode})` : `${out.length} imgs`;
  }

  function updateMatchFilterUI(){
    const on = !!currentMatchBarcode;
    btnClearMatch.style.display = on ? '' : 'none';
    matchFilterBadge.style.display = on ? 'inline-flex' : 'none';
    matchFilterBarcode.textContent = on ? currentMatchBarcode : '';
  }

  function buildTeams(matches){
    const s = new Set();
    matches.forEach(m => {
      if (m.equipo1) s.add(m.equipo1.trim());
      if (m.equipo2) s.add(m.equipo2.trim());
    });
    return Array.from(s).filter(Boolean).sort((a,b)=>a.localeCompare(b,'es'));
  }

  function dateToShort(iso){
    if (!iso || iso.length < 10) return '';
    const mm = iso.slice(5,7);
    const dd = iso.slice(8,10);
    return `${dd}/${mm}`;
  }

  function renderMatches(){
    const team = (fTeam.value || '').trim();
    const txt = norm(fMatchText.value);

    const list = currentMatches.filter(m => {
      if (!m || !m.barcode) return false;
      if (team) {
        const e1 = (m.equipo1||'').trim();
        const e2 = (m.equipo2||'').trim();
        if (e1 !== team && e2 !== team) return false;
      }
      if (txt) {
        const hay = [m.date_iso||'', m.title||'', m.equipo1||'', m.equipo2||'', m.barcode||''].join(' ');
        if (!norm(hay).includes(txt)) return false;
      }
      return true;
    });

    matchesStat.textContent = String(list.length);

    matchesList.innerHTML = '';
    if (!list.length) {
      matchesList.innerHTML = `<div class='muted' style='padding:12px;'>No hay partidos.</div>`;
      return;
    }

    list.forEach(m => {
      const row = document.createElement('div');
      row.className = 'mrow';
      if (currentMatchBarcode && m.barcode === currentMatchBarcode) row.classList.add('on');

      const date = document.createElement('div');
      date.className = 'mdate';
      date.textContent = m.date_iso ? `${m.date_iso.slice(0,4)} · ${dateToShort(m.date_iso)}` : '';

      const info = document.createElement('div');

      const hasTeams = (m.equipo1 && m.equipo2);
      const main = hasTeams ? `${m.equipo1} vs ${m.equipo2}` : (m.title || m.barcode);

      const tituloSobre = (m.title || '').trim();
      const titleLooksLikeMatch = /(\bvs\b|\s-\s|–)/i.test(tituloSobre);
      const extraTitle = (!hasTeams || !titleLooksLikeMatch) ? tituloSobre : '';

      const sub = [m.date_iso ? m.date_iso : '', m.barcode ? `· ${m.barcode}` : ''].filter(Boolean).join(' ').trim();

      info.innerHTML = `
        <div class='mtitle'>${escapeHtml(main)}</div>
        ${extraTitle ? `<div class='msub'>${escapeHtml(extraTitle)}</div>` : ''}
        <div class='msub'>${escapeHtml(sub)}</div>
      `;

      row.appendChild(date);
      row.appendChild(info);

      row.addEventListener('click', () => {
        currentMatchBarcode = (m.barcode || '').trim();
        updateMatchFilterUI();
        renderCompetitionGrid(currentSys, currentMatchBarcode);

        Array.from(matchesList.querySelectorAll('.mrow')).forEach(n => n.classList.remove('on'));
        row.classList.add('on');
      });

      row.addEventListener('dblclick', () => {
        openViewerFromBarcode(m.barcode, { match: m });
      });

      matchesList.appendChild(row);
    });
  }

  function enterCompetitionMode(sys){
    setMode('competition');
    currentSys = sys;
    currentMatchBarcode = '';
    updateMatchFilterUI();

    fTeam.value = '';
    fMatchText.value = '';

    currentMatches = (matchesBySys[sys] || []);

    const teams = buildTeams(currentMatches);
    fTeam.innerHTML = `<option value=''>Todos</option>`;
    teams.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t;
      opt.textContent = t;
      fTeam.appendChild(opt);
    });

    renderMatches();
    renderCompetitionGrid(sys, '');

    fTeam.onchange = () => renderMatches();
    fMatchText.oninput = () => {
      window.clearTimeout(window.__mt);
      window.__mt = window.setTimeout(() => renderMatches(), 120);
    };
  }

  function enterGenericMode(sys){
    setMode('generic');
    rebuildYearOptions(sys);
    applyGenericFilters();
  }

  function setMainImage(url){
    if (!url) return;
    vimg.src = url;
    resetViewerScroll();
    Array.from(thumbGrid.querySelectorAll('img.timg')).forEach(n => {
      n.classList.toggle('on', (n.dataset.url || '') === url);
    });
  }

  function renderRightPanel(barcode, images, currentUrl, opts = {}){
    const colSet = colLabelsByBarcode.get(barcode) || new Set();
    const isStandalone = !!opts.standalone;

    const pin = (images || []).find(x => (x.label || '') === '000') || (images && images.length ? images[0] : null);
    const pinUrl = pin ? (pin.url || '') : '';

    pinImg.src = pinUrl || '';
    pinImg.alt = barcode + '_000';
    pinLabel.textContent = isStandalone ? (opts.pinLabel || 'pieza') : ('_' + ((pin && pin.label) ? pin.label : '000'));
    pinBadge.textContent = isStandalone ? '' : (colSet.has('000') ? 'en colección' : '');
    vsideCount.textContent = images && images.length ? (images.length + ' imgs') : '';

    pinBox.onclick = () => { if (pinUrl) setMainImage(pinUrl); };
    pinBox.ondblclick = () => { if (pinUrl) openImageNewTab(pinUrl); };

    thumbGrid.innerHTML = '';
    const list = isStandalone ? [] : (images || []).filter(x => (x.label || '') !== '000');

    list.forEach(x => {
      const url = x.url || '';
      const lab = (x.label || '').trim();

      const im = document.createElement('img');
      im.className = 'timg';
      im.loading = 'lazy';
      im.src = url;
      im.alt = barcode + '_' + lab;
      im.title = `${barcode}_${lab}` + (colSet.has(lab) ? ' · en colección' : '');
      im.dataset.url = url;

      if (colSet.has(lab)) im.classList.add('incol');
      else im.classList.add('notcol');

      if (url === currentUrl) im.classList.add('on');

      im.addEventListener('click', () => setMainImage(url));
      im.addEventListener('dblclick', () => openImageNewTab(url));

      thumbGrid.appendChild(im);
    });
  }

  function openViewerFromItem(it){
    const barcode = (it.barcode||'').trim();

    if ((it.piece_type || '') === 'recorte_impreso') {
      const head = [];
      if (it.display_title) head.push(`<b>${escapeHtml(it.display_title)}</b>`);
      if (it.date_iso) head.push(`Fecha <b>${escapeHtml(it.date_iso)}</b>`);
      if (it.group) head.push(escapeHtml(it.group));
      if (it.caption_html) head.push(it.caption_html);
      vinfo.innerHTML = head.join(' · ');
      vimg.src = it.url || '';
      resetViewerScroll();
      renderRightPanel(barcode || ('RECORTE-' + (it.ref_id || '')), [{ label:'000', url:it.url || '', name:it.display_title || 'recorte' }], it.url || '', { standalone:true, pinLabel:'recorte' });
      modal.classList.add('on');
      return;
    }

    const env = envelopes[barcode] || null;
    const groupTitle = (it.group || (env ? env.group : '') || '').trim();
    const date = (it.date_iso || (env ? env.date_iso : '') || '').trim();

    let head = `Sobre <b>${escapeHtml(barcode)}</b>`;
    if (it.display_title && it.display_title !== it.image_key) head = `<b>${escapeHtml(it.display_title)}</b> · ` + head;
    if (date) head += ` · Fecha <b>${escapeHtml(date)}</b>`;
    if (groupTitle) head += ` · <b>${escapeHtml(groupTitle)}</b>`;
    if (it.caption_html) head += `<br>${it.caption_html}`;
    vinfo.innerHTML = head;

    const imgs = envImgs[barcode] || [];
    let mainUrl = it.url || '';
    if (!mainUrl || !it.exists) {
      const pin = imgs.find(x => (x.label||'') === '000') || (imgs.length ? imgs[0] : null);
      mainUrl = pin ? (pin.url || '') : '';
    }

    vimg.src = mainUrl || '';
    resetViewerScroll();

    renderRightPanel(barcode, imgs, mainUrl || '');
    modal.classList.add('on');
  }

  function openViewerFromBarcode(barcode, extra = {}){
    barcode = (barcode||'').trim();
    if (!barcode) return;

    const env = envelopes[barcode] || null;
    const groupTitle = (env ? (env.group||'') : '').trim();
    const date = (env ? (env.date_iso||'') : '').trim();

    let head = `Sobre <b>${escapeHtml(barcode)}</b>`;
    if (date) head += ` · Fecha <b>${escapeHtml(date)}</b>`;
    if (groupTitle) head += ` · <b>${escapeHtml(groupTitle)}</b>`;

    if (extra.match) {
      const m = extra.match;
      const hasTeams = (m.equipo1 && m.equipo2);
      const line = hasTeams ? `${m.equipo1} vs ${m.equipo2}` : '';
      const tituloSobre = (m.title || '').trim();
      const titleLooksLikeMatch = /(\bvs\b|\s-\s|–)/i.test(tituloSobre);
      const extraTitle = (!hasTeams || !titleLooksLikeMatch) ? tituloSobre : '';
      const more = [line ? `<b>${escapeHtml(line)}</b>` : '', extraTitle ? `· ${escapeHtml(extraTitle)}` : ''].filter(Boolean).join(' ');
      if (more) head += `<br>${more}`;
    }

    vinfo.innerHTML = head;

    const imgs = envImgs[barcode] || [];
    const pin = imgs.find(x => (x.label||'') === '000') || (imgs.length ? imgs[0] : null);
    const first = pin ? (pin.url || '') : '';

    vimg.src = first;
    resetViewerScroll();
    renderRightPanel(barcode, imgs, first);
    modal.classList.add('on');
  }

  function closeViewer(){ modal.classList.remove('on'); }
  closeBtn.addEventListener('click', closeViewer);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeViewer(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeViewer(); });

  function onGroupChange(){
    const sys = (fGroup.value || '').trim();
    currentSys = sys;

    currentMatchBarcode = '';
    updateMatchFilterUI();

    if (!sys) {
      enterGenericMode('');
      return;
    }

    const g = groupsBySys[sys];
    const hasMatches = !!(g && g.has_matches);

    if (hasMatches) enterCompetitionMode(sys);
    else enterGenericMode(sys);
  }

  fYear.addEventListener('change', () => {
    if (mode === 'generic') applyGenericFilters();
  });
  fText.addEventListener('input', () => {
    if (mode !== 'generic') return;
    window.clearTimeout(window.__t);
    window.__t = window.setTimeout(applyGenericFilters, 120);
  });

  btnClearMatch.addEventListener('click', () => {
    currentMatchBarcode = '';
    updateMatchFilterUI();
    renderCompetitionGrid(currentSys, '');
    Array.from(matchesList.querySelectorAll('.mrow')).forEach(n => n.classList.remove('on'));
  });

  btnReset.addEventListener('click', () => {
    fGroup.value = '';
    fYear.value = '';
    fText.value = '';
    fTeam.value = '';
    fMatchText.value = '';
    matchesList.innerHTML = '';
    matchesStat.textContent = '0';
    currentMatches = [];
    currentSys = '';
    currentMatchBarcode = '';
    updateMatchFilterUI();
    enterGenericMode('');
  });

  fGroup.addEventListener('change', onGroupChange);

  const expoLabel = M.expo && M.expo.kicker ? ` · ${M.expo.kicker}` : '';
  sub.textContent = (items.length || 0) + ' imgs/piezas' + expoLabel;

  enterGenericMode('');
})();
</script>
</body>
</html>
HTML;

$viewerHtml = str_replace(
  ['__PAGE_TITLE__', '__COL_TITLE__', '__MANIFEST_JSON__'],
  [$pageTitleEsc, $displayTitleEsc, $manifestJson],
  $viewerHtml
);

if (@file_put_contents($viewerHtmlPath, $viewerHtml) === false) {
  out("\nERROR: no pude escribir viewer.html\n");
  log_line($logFile, "[ERROR] No pude escribir viewer.html: $viewerHtmlPath");
} else {
  out("viewer.html generado.\n");
}

/* =========================
   WRITE INDEX.HTML (expo)
   ========================= */
$indexHtmlPath = '';
if ($expo) {
  $heroUrl = hero_url_from_expo($expo, $items);
  $indexHtmlPath = fs_join($exportDir, 'index.html');
  $indexHtml = build_index_html($expo, $col, $manifest, $heroUrl);
  if (@file_put_contents($indexHtmlPath, $indexHtml) === false) {
    out("ERROR: no pude escribir index.html\n");
    log_line($logFile, "[ERROR] No pude escribir index.html: $indexHtmlPath");
  } else {
    out("index.html generado.\n");
  }
}

out("</pre>");

out("<div style='margin-top:12px;'>✅ Export creado.</div>");
out("<div>Carpeta: <code>" . h($exportDir) . "</code></div>");
out("<div>Viewer: <code>" . h($viewerHtmlPath) . "</code></div>");
if ($indexHtmlPath !== '') {
  out("<div>Portada: <code>" . h($indexHtmlPath) . "</code></div>");
  out("<div style='margin-top:10px;color:#666;'>Abrilo offline con doble click: <b>index.html</b> (desde esa carpeta).</div>");
} else {
  out("<div style='margin-top:10px;color:#666;'>Abrilo offline con doble click: <b>viewer.html</b> (desde esa carpeta).</div>");
}
out("<div style='margin-top:10px;color:#666;'>Log: <code>" . h($logFile) . "</code></div>");
out("</body></html>");
