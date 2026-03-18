<?php
declare(strict_types=1);

/**
 * coleccion_viewer_beta.php
 * Viewer “beta” (SIN export) que usa imágenes del sitio (/bajas/...) y arma un manifest JSON en memoria.
 *
 * Modo dinámico por grupo (registros.titulo245):
 *  - Si el grupo existe en tabla `partidos` (partidos.tituloReg) => modo “campeonato”:
 *      - se mantiene la GRILLA y muestra TODAS las imágenes del grupo (sys)
 *      - la lista de partidos actúa como FILTRO por sobre (barcode)
 *  - Si NO existe => modo “genérico/persona/equipo/tema”:
 *      - se muestra filtro por AÑO (solo años disponibles para el grupo elegido)
 *
 * Mini endpoints (mismo archivo):
 *  - ?action=matches&sys=001590842         => lista partidos (tabla `partidos`) para ese grupo (sys)
 *  - ?action=envelope_images&barcode=FO..  => lista imágenes del sobre (tabla `digitales`)
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}

/* -------------------- helpers -------------------- */
function safe_seg(string $s): string {
  $s = trim($s);
  $s = str_replace(["..", "/", "\\", "\0"], '', $s);
  return $s;
}

function parse_image_key(string $imageKey): array {
  $imageKey = trim($imageKey);
  if ($imageKey === '' || strpos($imageKey, '_') === false) return ['', ''];
  [$b, $lab] = explode('_', $imageKey, 2);
  $b = safe_seg($b);
  $lab = str_pad(preg_replace('/\D+/', '', (string)$lab), 3, '0', STR_PAD_LEFT);
  return [$b, $lab];
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

/**
 * Resolver image_keys FOxxxxxx_016 -> digitales (Bajas) para armar URLs del sitio.
 * Devuelve slides en el orden original.
 */
function resolve_image_keys_to_slides(array $imageKeys): array {
  $base = rtrim((string)BASE_URL, '/');

  // agrupar por barcode
  $byBarcode = [];
  foreach ($imageKeys as $k) {
    $k = (string)$k;
    if ($k === '' || strpos($k, '_') === false) continue;
    [$barcode, $lab] = parse_image_key($k);
    if ($barcode === '' || $lab === '') continue;
    $byBarcode[$barcode][] = $lab;
  }

  // Map por barcode: label -> (cajon, nombramiento)
  $maps = []; // barcode => [label => ['cajon'=>..,'nombramiento'=>..]]
  foreach ($byBarcode as $barcode => $labels) {
    $rows = q(
      "SELECT cajon, nombramiento
       FROM digitales
       WHERE inv=?
         AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
         AND nombramiento IS NOT NULL
         AND nombramiento <> ''",
      "s",
      [$barcode]
    );

    if (!$rows) { $maps[$barcode] = []; continue; }

    $map = [];
    foreach ($rows as $r) {
      $name = (string)($r['nombramiento'] ?? '');
      if ($name === '') continue;
      if (preg_match('/_(\d{1,4})\.(jpe?g|png)$/i', $name, $m)) {
        $lab = str_pad((string)(int)$m[1], 3, '0', STR_PAD_LEFT);
        if (!isset($map[$lab])) {
          $map[$lab] = [
            'cajon' => (string)($r['cajon'] ?? ''),
            'nombramiento' => $name,
          ];
        }
      }
    }
    $maps[$barcode] = $map;
  }

  // construir slides en el orden original
  $slides = [];
  foreach ($imageKeys as $idx => $k) {
    $k = (string)$k;
    [$barcode, $lab] = parse_image_key($k);

    $cj = '';
    $name = '';
    $url = '';
    $ok = false;

    if ($barcode !== '' && $lab !== '' && isset($maps[$barcode][$lab])) {
      $cj = safe_seg((string)$maps[$barcode][$lab]['cajon']);
      $name = safe_seg((string)$maps[$barcode][$lab]['nombramiento']);
      $url = $base . '/bajas/' . rawurlencode($cj) . '/' . rawurlencode($barcode) . '/' . rawurlencode($name);
      $ok = true;
    }

    $slides[] = [
      'i' => (int)$idx,
      'image_key' => $k,
      'barcode' => $barcode,
      'label' => $lab !== '' ? $lab : str_pad((string)$idx, 3, '0', STR_PAD_LEFT),
      'name' => $name,
      'cajon' => $cj,
      'url' => $url,
      'exists' => $ok,
    ];
  }

  return $slides;
}

/**
 * Trae TODAS las imágenes (Bajas) de un sobre (barcode) para el “resto del sobre”.
 * Devuelve lista ordenada por label numérico.
 */
function fetch_envelope_images(string $barcode): array {
  $base = rtrim((string)BASE_URL, '/');
  $barcode = safe_seg($barcode);
  if ($barcode === '') return [];

  $rows = q(
    "SELECT cajon, nombramiento
     FROM digitales
     WHERE inv=?
       AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
       AND nombramiento IS NOT NULL
       AND nombramiento <> ''",
    "s",
    [$barcode]
  );

  $tmp = [];
  foreach ($rows ?: [] as $r) {
    $cj = safe_seg((string)($r['cajon'] ?? ''));
    $name = safe_seg((string)($r['nombramiento'] ?? ''));
    if ($name === '') continue;

    $lab = '999';
    if (preg_match('/_(\d{1,4})\.(jpe?g|png)$/i', $name, $m)) {
      $lab = str_pad((string)(int)$m[1], 3, '0', STR_PAD_LEFT);
    }

    $tmp[] = [
      'label' => $lab,
      'name' => $name,
      'cajon' => $cj,
      'url' => $base . '/bajas/' . rawurlencode($cj) . '/' . rawurlencode($barcode) . '/' . rawurlencode($name),
    ];
  }

  usort($tmp, fn($a, $b) => (int)$a['label'] <=> (int)$b['label']);
  return $tmp;
}

/**
 * Cache sys->titulo245
 */
function fetch_sys_title245_cached(string $sys): string {
  static $cache = [];
  $sys = trim($sys);
  if ($sys === '') return '';
  if (isset($cache[$sys])) return $cache[$sys];

  $r = q("SELECT titulo245 FROM registros WHERE sys=? AND titulo245 IS NOT NULL AND titulo245<>'' LIMIT 1", "s", [$sys]);
  $cache[$sys] = $r ? trim((string)($r[0]['titulo245'] ?? '')) : '';
  return $cache[$sys];
}

/**
 * Meta del sobre desde titulos + registros:
 *  - sys, fecha (yyyymmdd), titulo245 (grupo)
 */
function fetch_envelope_meta(string $barcode): array {
  $barcode = safe_seg($barcode);
  if ($barcode === '') return [
    'sys' => '',
    'date_raw' => '',
    'date_iso' => '',
    'year' => 0,
    'group' => '',
  ];

  $t = q("SELECT sys, fecha FROM titulos WHERE barcode=? LIMIT 1", "s", [$barcode]);
  $sys = $t ? trim((string)($t[0]['sys'] ?? '')) : '';
  $dateRaw = $t ? trim((string)($t[0]['fecha'] ?? '')) : '';

  [$dateIso, $year] = parse_yyyymmdd_to_iso($dateRaw);
  $group = $sys !== '' ? fetch_sys_title245_cached($sys) : '';

  return [
    'sys' => $sys,
    'date_raw' => $dateRaw,
    'date_iso' => $dateIso,
    'year' => $year,
    'group' => $group,
  ];
}

/**
 * ¿Existe ese grupo en tabla partidos? (match exacto por tituloReg)
 */
function group_has_partidos(string $titulo245): bool {
  $titulo245 = trim($titulo245);
  if ($titulo245 === '') return false;
  $x = q("SELECT 1 FROM partidos WHERE tituloReg=? LIMIT 1", "s", [$titulo245]);
  return (bool)$x;
}

/**
 * Lista de partidos para un grupo (tituloReg = titulo245 exacto).
 * Devuelve filas con barcode/tituloSobre/fecha/equipo1/equipo2 (sin cancha).
 */
function fetch_partidos_for_group(string $titulo245): array {
  $titulo245 = trim($titulo245);
  if ($titulo245 === '') return [];

  $rows = q(
    "SELECT barcode, tituloSobre, tituloReg, fecha, equipo1, equipo2
     FROM partidos
     WHERE tituloReg=?
     ORDER BY fecha ASC, barcode ASC",
    "s",
    [$titulo245]
  ) ?: [];

  $out = [];
  foreach ($rows as $r) {
    $barcode = safe_seg((string)($r['barcode'] ?? ''));
    $fechaRaw = trim((string)($r['fecha'] ?? ''));
    [$dateIso, $year] = parse_yyyymmdd_to_iso($fechaRaw);

    $out[] = [
      'barcode' => $barcode,
      'title' => trim((string)($r['tituloSobre'] ?? '')),
      'date_raw' => $fechaRaw,
      'date_iso' => $dateIso,
      'year' => (int)$year,
      'equipo1' => trim((string)($r['equipo1'] ?? '')),
      'equipo2' => trim((string)($r['equipo2'] ?? '')),
    ];
  }
  return $out;
}

/* -------------------- mini endpoints (AJAX) -------------------- */
$action = (string)($_GET['action'] ?? '');
if ($action !== '') {
  header('Content-Type: application/json; charset=utf-8');

  if ($action === 'envelope_images') {
    $barcode = safe_seg((string)($_GET['barcode'] ?? ''));
    echo json_encode([
      'ok' => true,
      'barcode' => $barcode,
      'images' => fetch_envelope_images($barcode),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  if ($action === 'matches') {
    $sys = preg_replace('/\D+/', '', (string)($_GET['sys'] ?? ''));
    $sys = trim($sys);
    $titulo245 = $sys !== '' ? fetch_sys_title245_cached($sys) : '';
    $matches = $titulo245 !== '' ? fetch_partidos_for_group($titulo245) : [];

    echo json_encode([
      'ok' => true,
      'sys' => $sys,
      'group' => $titulo245,
      'matches' => $matches,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }

  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* -------------------- input -------------------- */
$collectionId = (int)($_GET['collection_id'] ?? ($_GET['id'] ?? 0));
if ($collectionId <= 0) {
  http_response_code(400);
  echo "Falta collection_id (o id). Ej: ?collection_id=2";
  exit;
}

/* -------------------- fetch colección + items -------------------- */
$colRow = q("SELECT id, title, description FROM collections_v2 WHERE id=? LIMIT 1", "i", [$collectionId]);
if (!$colRow) {
  http_response_code(404);
  echo "No existe la colección #{$collectionId}.";
  exit;
}
$col = $colRow[0];

$itemRows = q(
  "SELECT image_key
   FROM collection_items_v2
   WHERE collection_id=?
   ORDER BY position ASC, image_key ASC",
  "i",
  [$collectionId]
) ?: [];

$imageKeys = array_map(fn($r) => (string)$r['image_key'], $itemRows);
$slides = resolve_image_keys_to_slides($imageKeys);

/* -------------------- barcodes únicos -------------------- */
$barcodes = [];
foreach ($slides as $s) {
  $b = (string)($s['barcode'] ?? '');
  if ($b !== '') $barcodes[$b] = true;
}
$barcodes = array_keys($barcodes);

/* -------------------- envelopes meta + groups -------------------- */
$envelopes = []; // barcode => meta
$groupsBySys = []; // sys => {sys,title245,has_matches,kind,year_min,year_max,count_envelopes}

foreach ($barcodes as $b) {
  $meta = fetch_envelope_meta($b);

  $envelopes[$b] = [
    'barcode' => $b,
    'sys' => $meta['sys'],
    'group' => $meta['group'],
    'date_iso' => $meta['date_iso'],
    'year' => (int)$meta['year'],
  ];

  $sys = (string)$meta['sys'];
  $g = (string)$meta['group'];
  if ($sys !== '') {
    if (!isset($groupsBySys[$sys])) {
      $hasMatches = $g !== '' ? group_has_partidos($g) : false;
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

/* -------------------- items (imágenes de colección) -------------------- */
$items = [];
foreach ($slides as $s) {
  $b = (string)($s['barcode'] ?? '');
  $env = $envelopes[$b] ?? null;

  $items[] = [
    'image_key' => (string)$s['image_key'],
    'barcode' => $b,
    'label' => (string)$s['label'],
    'url' => (string)$s['url'],
    'exists' => (bool)$s['exists'],
    'sys' => $env ? (string)$env['sys'] : '',
    'group' => $env ? (string)$env['group'] : '',
    'date_iso' => $env ? (string)$env['date_iso'] : '',
    'year' => $env ? (int)$env['year'] : 0,
  ];
}

/* -------------------- manifest final -------------------- */
$manifest = [
  'collection' => [
    'id' => (int)$col['id'],
    'title' => (string)$col['title'],
    'description' => (string)($col['description'] ?? ''),
    'count_items' => count($items),
  ],
  'groups' => array_values($groupsBySys),
  'items' => $items,
  'envelopes' => $envelopes,
];

?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h((string)$col['title']) ?> — Viewer beta</title>
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

    /* Matches list (competition mode) */
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

    /* Modal viewer */
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
      background:var(--card);
      border:1px solid var(--line);
      border-radius:16px;
      overflow:hidden;
      display:grid;
      grid-template-rows: auto 1fr auto;
      min-height: 0;
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
      min-height: 0; /* clave para que el hijo con overflow funcione */
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
      overflow:hidden; /* manejamos scroll adentro */
      display:flex;
      flex-direction:column;
      gap:10px;
      min-height: 0; /* clave: deja que thumbPane scrollee */
    }
    .vsideTitle{
      font-size:12px; color:var(--muted);
      display:flex; align-items:center; justify-content:space-between;
      gap:10px;
      flex: 0 0 auto;
    }

    /* Pin _000 arriba */
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

    /* Scrollpane de thumbnails */
    .thumbPane{
      border:1px solid var(--line);
      border-radius:12px;
      overflow:auto;
      padding:10px;
      background: color-mix(in srgb, var(--bg) 60%, transparent);
      flex: 1 1 auto;
      min-height: 0; /* important flex scroll */
      height: 0; /* fuerza a que flex calcule el alto real y el scroll quede acá */
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

    /* Bottom strip (useful in mobile) 
    .strip{
      display:flex; gap:8px; overflow:auto;
      padding:10px; border-top:1px solid var(--line);
      background: color-mix(in srgb, var(--bg) 65%, transparent);
    }*/
    .strip{ display:none !important; }
    .simg{
      width:92px; height:68px; object-fit:cover; border-radius:10px;
      border:2px solid transparent; cursor:pointer; opacity:.92;
    }
    .simg:hover{ opacity:1; }
    .simg.on{ border-color: var(--accent); opacity:1; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="topbar">
    <div>
      <div class="title">
        <?= h((string)$col['title']) ?>
        <span class="pill">viewer beta</span>
      </div>
      <div class="sub"><?= (int)count($items) ?> imgs</div>
    </div>
    <div class="row">
      <a class="btn" href="colecciones.php?id=<?= (int)$collectionId ?>">Volver</a>
      <a class="btn" href="colecciones.php">Colecciones</a>
    </div>
  </div>

  <div class="layout">
    <aside class="panel">
      <label>Grupo</label>
      <select id="fGroup">
        <option value="">Todos</option>
      </select>

      <!-- Competition mode -->
      <div id="boxCompetition" style="display:none;">
        <label>Equipo</label>
        <select id="fTeam">
          <option value="">Todos</option>
        </select>

        <label>Buscar partido</label>
        <input id="fMatchText" type="text" placeholder="boca · river · 1974..." />

        <div class="matchesBox">
          <div class="matchesHead">
            <div class="left">
              <span>Partidos</span>
              <span class="badge ok" id="matchesStat">0</span>
              <span class="badge warn" id="matchFilterBadge" style="display:none;">
                Sobre <b id="matchFilterBarcode" style="margin-left:6px;"></b>
              </span>
            </div>
            <button class="linkbtn" id="btnClearMatch" type="button" style="display:none;">Quitar</button>
          </div>
          <div class="matchesList" id="matchesList"></div>
        </div>
      </div>

      <!-- Generic mode -->
      <div id="boxGeneric" style="display:none;">
        <label>Año</label>
        <select id="fYear">
          <option value="">Todos</option>
        </select>

        <label>Buscar</label>
        <input id="fText" type="text" placeholder="FO067408 · metropolitano · 1974" />
      </div>

      <div class="row" style="margin-top:10px;">
        <button class="btn" id="btnReset" type="button">Reset</button>
        <span class="muted" id="stat" style="font-size:12px;"></span>
      </div>
    </aside>

    <main>
      <div class="grid" id="grid"></div>
      <div class="muted" id="empty" style="display:none; padding:18px; text-align:center;">
        No hay resultados.
      </div>
    </main>
  </div>
</div>

<!-- Modal viewer -->
<div class="modal" id="modal">
  <div class="viewer">
    <div class="vtop">
      <div class="vinfo" id="vinfo"></div>
      <button class="btn" id="closeBtn" type="button">Cerrar</button>
    </div>

    <div class="vmain">
      <div class="vimgwrap" id="vimgwrap">
        <img class="vimg" id="vimg" alt="" title="Doble click: abrir imagen completa en una nueva pestaña" />
      </div>

      <div class="vside">
        <div class="vsideTitle">
          <span>Resto del sobre</span>
          <span class="muted" id="vsideCount" style="font-size:12px;"></span>
        </div>

        <!-- Pin _000 -->
        <div class="pinBox" id="pinBox" title="Click: ver _000 · Doble click: abrir en nueva pestaña">
          <img class="pinImg" id="pinImg" alt="">
          <div class="pinMeta">
            <div><b id="pinLabel">_000</b></div>
            <div class="muted" id="pinBadge" style="font-size:12px;"></div>
          </div>
        </div>

        <!-- Scroll de thumbs -->
        <div class="thumbPane">
          <div class="thumbGrid" id="thumbGrid"></div>
        </div>
      </div>
    </div>

    <!-- strip inferior (especialmente útil en mobile) -->
    <div class="strip" id="strip"></div>
  </div>
</div>

<script>
window.__MANIFEST__ = <?= json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<script>
(function(){
  const M = window.__MANIFEST__;

  const grid = document.getElementById('grid');
  const empty = document.getElementById('empty');
  const stat = document.getElementById('stat');

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

  // Modal
  const modal = document.getElementById('modal');
  const vimg = document.getElementById('vimg');
  const vimgwrap = document.getElementById('vimgwrap');
  const vinfo = document.getElementById('vinfo');
  const strip = document.getElementById('strip');
  const closeBtn = document.getElementById('closeBtn');

  // Right panel
  const vsideCount = document.getElementById('vsideCount');
  const pinBox = document.getElementById('pinBox');
  const pinImg = document.getElementById('pinImg');
  const pinLabel = document.getElementById('pinLabel');
  const pinBadge = document.getElementById('pinBadge');
  const thumbGrid = document.getElementById('thumbGrid');

  const barcodesInCollection = new Set(Object.keys(M.envelopes || {}));

  // groups index
  const groupsBySys = {};
  (M.groups || []).forEach(g => { groupsBySys[g.sys] = g; });

  // cache ajax results
  const cacheMatchesBySys = new Map();      // sys => matches[]
  const cacheEnvelopeImages = new Map();    // barcode => images[]

  // collection index (para destacar thumbs)
  const colLabelsByBarcode = new Map(); // barcode => Set(labels)
  (M.items || []).forEach(it => {
    const b = (it.barcode || '').trim();
    const lab = (it.label || '').trim();
    if (!b || !lab) return;
    if (!colLabelsByBarcode.has(b)) colLabelsByBarcode.set(b, new Set());
    colLabelsByBarcode.get(b).add(lab);
  });

  function norm(s){ return (s||'').toString().toLowerCase().trim(); }
  function escapeHtml(s){
    return (s||'').toString().replace(/[&<>"']/g, m => (
      m === '&' ? '&amp;' :
      m === '<' ? '&lt;' :
      m === '>' ? '&gt;' :
      m === '"' ? '&quot;' : '&#039;'
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

  // doble click en la imagen principal => nueva pestaña
  vimg.addEventListener('dblclick', () => {
    openImageNewTab(vimg.src || '');
  });

  // populate group select
  const groupsSorted = (M.groups || [])
    .filter(g => (g.title245||'').trim() !== '')
    .sort((a,b)=> (a.title245||'').localeCompare(b.title245||'', 'es'));

  groupsSorted.forEach(g => {
    const opt = document.createElement('option');
    opt.value = g.sys;
    opt.textContent = g.title245 + (g.has_matches ? '  • partidos' : '');
    fGroup.appendChild(opt);
  });

  // ----- mode switching -----
  let mode = 'generic'; // generic | competition
  let currentSys = '';
  let currentMatches = [];
  let currentMatchBarcode = '';

  // viewer state
  let currentBarcode = '';
  let currentEnvelopeImages = []; // fetchEnvelopeImages result

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

  async function fetchMatches(sys){
    if (!sys) return [];
    if (cacheMatchesBySys.has(sys)) return cacheMatchesBySys.get(sys);

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'matches');
    url.searchParams.set('sys', sys);

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const data = await res.json();

    let matches = (data && data.matches) ? data.matches : [];
    matches = matches.filter(m => m && m.barcode && barcodesInCollection.has(m.barcode));

    cacheMatchesBySys.set(sys, matches);
    return matches;
  }

  async function fetchEnvelopeImages(barcode){
    if (!barcode) return [];
    if (cacheEnvelopeImages.has(barcode)) return cacheEnvelopeImages.get(barcode);

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'envelope_images');
    url.searchParams.set('barcode', barcode);

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const data = await res.json();
    const images = (data && data.images) ? data.images : [];

    cacheEnvelopeImages.set(barcode, images);
    return images;
  }

  /* -------------------- YEAR options (GENERIC) -------------------- */
  function rebuildYearOptions(sys){
    const ys = new Set();
    (M.items || []).forEach(it => {
      if (sys && String(it.sys||'') !== String(sys)) return;
      if (it.year) ys.add(it.year);
    });

    const years = Array.from(ys).sort((a,b)=>a-b);
    const current = fYear.value || '';

    fYear.innerHTML = `<option value="">Todos</option>`;
    years.forEach(y => {
      const opt = document.createElement('option');
      opt.value = String(y);
      opt.textContent = String(y);
      fYear.appendChild(opt);
    });

    if (current && years.includes(parseInt(current, 10))) fYear.value = current;
    else fYear.value = '';
  }

  /* -------------------- GRID rendering -------------------- */
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
      img.alt = it.image_key || '';
      card.appendChild(img);

      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.innerHTML = `
        <div><b>${escapeHtml(it.barcode||'')}</b> · ${escapeHtml(it.label||'')}</div>
        <div>${escapeHtml(it.group||'')} ${it.year ? '· ' + it.year : ''}</div>
      `;
      card.appendChild(meta);

      card.addEventListener('click', () => openViewerFromItem(it));
      grid.appendChild(card);
    });
  }

  /* -------------------- GENERIC filters -------------------- */
  function applyGenericFilters(){
    const sys = (fGroup.value || '').trim();
    const year = parseInt(fYear.value || '0', 10);
    const txt = norm(fText.value);

    const out = (M.items || []).filter(it => {
      if (sys && String(it.sys||'') !== sys) return false;
      if (year && parseInt(it.year||0,10) !== year) return false;

      if (txt) {
        const hay = [it.barcode||'', it.group||'', it.date_iso||'', String(it.year||'')].join(' ');
        if (!norm(hay).includes(txt)) return false;
      }
      return true;
    });

    renderGrid(out);
    stat.textContent = out.length ? (out.length + ' resultados') : '';
  }

  /* -------------------- COMPETITION grid for whole group + barcode filter -------------------- */
  function renderCompetitionGrid(sys, onlyBarcode = ''){
    const out = (M.items || []).filter(it => {
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

  /* -------------------- MATCHES list -------------------- */
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
      matchesList.innerHTML = `<div class="muted" style="padding:12px;">No hay partidos.</div>`;
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
        <div class="mtitle">${escapeHtml(main)}</div>
        ${extraTitle ? `<div class="msub">${escapeHtml(extraTitle)}</div>` : ''}
        <div class="msub">${escapeHtml(sub)}</div>
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

  async function enterCompetitionMode(sys){
    setMode('competition');
    currentSys = sys;
    currentMatchBarcode = '';
    updateMatchFilterUI();

    // reset filtros
    fTeam.value = '';
    fMatchText.value = '';

    matchesList.innerHTML = `<div class="muted" style="padding:12px;">Cargando...</div>`;
    currentMatches = await fetchMatches(sys);

    // Teams
    const teams = buildTeams(currentMatches);
    fTeam.innerHTML = `<option value="">Todos</option>`;
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

  /* -------------------- VIEWER: SIDE PANEL -------------------- */
  function setMainImage(url){
    if (!url) return;
    vimg.src = url;
    resetViewerScroll();

    // marcar selección en thumbs y strip
    Array.from(thumbGrid.querySelectorAll('img.timg')).forEach(n => {
      n.classList.toggle('on', (n.dataset.url || '') === url);
    });
    Array.from(strip.querySelectorAll('img.simg')).forEach(n => {
      n.classList.toggle('on', (n.dataset.url || '') === url);
    });
  }

  function renderRightPanel(barcode, images, currentUrl){
    currentBarcode = barcode;
    currentEnvelopeImages = images || [];

    // set de labels que están en la colección (para destacar)
    const colSet = colLabelsByBarcode.get(barcode) || new Set();

    // pin: buscar _000 (si no, la primera)
    const pin = (images || []).find(x => (x.label || '') === '000') || (images && images.length ? images[0] : null);
    const pinUrl = pin ? (pin.url || '') : '';

    pinImg.src = pinUrl || '';
    pinImg.alt = barcode + '_000';
    pinLabel.textContent = '_' + ((pin && pin.label) ? pin.label : '000');
    pinBadge.textContent = colSet.has('000') ? 'en colección' : '';

    vsideCount.textContent = images && images.length ? (images.length + ' imgs') : '';

    // click pin => ver _000
    pinBox.onclick = () => { if (pinUrl) setMainImage(pinUrl); };
    pinBox.ondblclick = () => { if (pinUrl) openImageNewTab(pinUrl); };

    // grid thumbs (sin duplicar 000)
    thumbGrid.innerHTML = '';
    const list = (images || []).filter(x => (x.label || '') !== '000');

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

      // destacado
      if (colSet.has(lab)) im.classList.add('incol');
      else im.classList.add('notcol');

      if (url === currentUrl) im.classList.add('on');

      im.addEventListener('click', () => setMainImage(url));
      im.addEventListener('dblclick', () => openImageNewTab(url));

      thumbGrid.appendChild(im);
    });
  }

  async function fillStripAndSide(barcode, currentUrl){
    strip.innerHTML = '';
    thumbGrid.innerHTML = '';
    pinImg.src = '';
    pinBadge.textContent = '';
    vsideCount.textContent = '';

    const imgs = await fetchEnvelopeImages(barcode);
    renderRightPanel(barcode, imgs, currentUrl);

    // strip inferior (incluye 000 también)
    /*imgs.forEach(x => {
      const im = document.createElement('img');
      im.className = 'simg';
      im.loading = 'lazy';
      im.src = x.url || '';
      im.alt = barcode + '_' + (x.label||'');
      im.dataset.url = x.url || '';
      if ((x.url||'') === (currentUrl||'')) im.classList.add('on');

      im.addEventListener('click', () => setMainImage(x.url || ''));
      im.addEventListener('dblclick', () => openImageNewTab(x.url || ''));

      strip.appendChild(im);
    });*/
  }

  /* -------------------- VIEWER open -------------------- */
  async function openViewerFromItem(it){
    const barcode = (it.barcode||'').trim();
    const env = (M.envelopes && M.envelopes[barcode]) ? M.envelopes[barcode] : null;
    const groupTitle = (it.group || (env ? env.group : '') || '').trim();
    const date = (it.date_iso || (env ? env.date_iso : '') || '').trim();

    vinfo.innerHTML =
      `Sobre <b>${escapeHtml(barcode)}</b>` +
      (date ? ` · Fecha <b>${escapeHtml(date)}</b>` : '') +
      (groupTitle ? ` · <b>${escapeHtml(groupTitle)}</b>` : '');

    vimg.src = it.url || '';
    vimg.alt = it.image_key || '';
    resetViewerScroll();

    await fillStripAndSide(barcode, it.url || '');
    modal.classList.add('on');
  }

  async function openViewerFromBarcode(barcode, extra = {}){
    barcode = (barcode||'').trim();
    if (!barcode) return;

    const env = (M.envelopes && M.envelopes[barcode]) ? M.envelopes[barcode] : null;
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

    const images = await fetchEnvelopeImages(barcode);
    const first = images.length ? images[0].url : '';
    vimg.src = first;
    vimg.alt = barcode;
    resetViewerScroll();

    // strip + side
    renderRightPanel(barcode, images, first);

    strip.innerHTML = '';
    /*images.forEach(x => {
      const im = document.createElement('img');
      im.className = 'simg';
      im.loading = 'lazy';
      im.src = x.url || '';
      im.alt = barcode + '_' + (x.label||'');
      im.dataset.url = x.url || '';
      if ((x.url||'') === (first||'')) im.classList.add('on');
      im.addEventListener('click', () => setMainImage(x.url || ''));
      im.addEventListener('dblclick', () => openImageNewTab(x.url || ''));
      strip.appendChild(im);
    });*/

    modal.classList.add('on');
  }

  function closeViewer(){ modal.classList.remove('on'); }
  closeBtn.addEventListener('click', closeViewer);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeViewer(); });
  document.addEventListener('keydown', (e)=>{ if (e.key === 'Escape') closeViewer(); });

  /* -------------------- group change handler -------------------- */
  async function onGroupChange(){
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

    if (hasMatches) await enterCompetitionMode(sys);
    else enterGenericMode(sys);
  }

  /* -------------------- listeners -------------------- */
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

  // init
  enterGenericMode('');
})();
</script>
</body>
</html>
