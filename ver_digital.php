<?php
// afdc_v2/ver_digital.php
/**
 * Ver digital (visor) — soporta 2 modos:
 *  - MODO SOBRE (default): ?barcode=FO0064054&i=0
 *  - MODO COLECCIÓN: ?collection_id=18&i=0   (o usando from_collection_id)
 *    También soporta salto exacto: ?image_key=FO0069829_016
 *
 * En modo colección:
 *  - La tira/carrusel muestra SOLO los items de la colección
 *  - Botón "Ver en el sobre original" para retomar contexto
 *  - "Volver a la colección ..." si viene from_collection_id
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php'; // incluye config.php + helpers h(), q(), url_with(), db()
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}

// -------------------- helpers --------------------
function qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null) unset($q[$k]);
    }
    return basename(__FILE__) . (count($q) ? ('?' . http_build_query($q)) : '');
}
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
/**
 * Parse fecha YYYYMMDD (ej: 19740421) -> DateTimeImmutable o null.
 */
function parse_yyyymmdd(?string $raw): ?DateTimeImmutable {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!preg_match('/^\d{8}$/', $raw)) return null;
    $y = (int)substr($raw, 0, 4);
    $m = (int)substr($raw, 4, 2);
    $d = (int)substr($raw, 6, 2);
    if (!checkdate($m, $d, $y)) return null;
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d));
}

/**
 * Resolver on-the-fly:
 *  image_key FOxxxxxx_016 -> digitales (inv=FOxxxxxx, nombramiento *_016.jpg)
 * Devuelve slides listos para el visor.
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

    // Para cada barcode: traigo todas las bajas (del sobre) y armo map label->(cajon, nombramiento)
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
            'name' => $name,
            'cajon' => $cj,
            'url' => $url,
            'exists' => $ok,
            'label' => $lab !== '' ? $lab : str_pad((string)$idx, 3, '0', STR_PAD_LEFT),
            'isSobre' => (($lab !== '' ? $lab : '999') === '000'),
        ];
    }

    return $slides;
}

/**
 * Resolver items mixtos de colección:
 *  - foto    => item_key/image_key -> digitales -> slide normal
 *  - recorte => item_key           -> api/recorte_render.php
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function resolve_collection_items_to_slides(array $items): array {
    $base = rtrim((string)BASE_URL, '/');

    // 1) recolectar solo fotos para reutilizar el resolvedor existente
    $photoKeys = [];
    foreach ($items as $it) {
        $type = (string)($it['item_type'] ?? 'foto');
        $itemKey = trim((string)($it['item_key'] ?? ''));
        $imageKey = trim((string)($it['image_key'] ?? ''));
        $key = $itemKey !== '' ? $itemKey : $imageKey;

        if ($type !== 'recorte' && $key !== '') {
            $photoKeys[] = $key;
        }
    }

    $photoSlides = $photoKeys ? resolve_image_keys_to_slides($photoKeys) : [];
    $photoMap = [];
    foreach ($photoSlides as $ps) {
        $k = (string)($ps['image_key'] ?? '');
        if ($k !== '') $photoMap[$k] = $ps;
    }

    // 2) reconstruir en el orden original
    $slides = [];
    foreach ($items as $idx => $it) {
        $type = (string)($it['item_type'] ?? 'foto');
        $itemKey = trim((string)($it['item_key'] ?? ''));
        $imageKey = trim((string)($it['image_key'] ?? ''));
        $key = $itemKey !== '' ? $itemKey : $imageKey;

        if ($type === 'recorte') {
            $rid = (int)$key;

            $slides[] = [
                'i' => (int)$idx,
                'type' => 'recorte',
                'image_key' => '',
                'item_key' => (string)$key,
                'recorte_id' => $rid,
                'barcode' => '',
                'name' => 'Recorte #' . $rid,
                'cajon' => '',
                'url' => $rid > 0 ? ($base . '/api/recorte_render.php?id=' . $rid . '&modo=crop&maxw=1400&q=88') : '',
                'exists' => ($rid > 0),
                'label' => 'REC',
                'isSobre' => false,
            ];
            continue;
        }

        $s = $photoMap[$key] ?? null;

        if ($s) {
            $s['i'] = (int)$idx;
            $s['type'] = 'foto';
            $s['item_key'] = $key;
            $s['recorte_id'] = 0;
            $slides[] = $s;
        } else {
            [$barcode, $lab] = parse_image_key($key);
            $slides[] = [
                'i' => (int)$idx,
                'type' => 'foto',
                'image_key' => $key,
                'item_key' => $key,
                'recorte_id' => 0,
                'barcode' => $barcode,
                'name' => '',
                'cajon' => '',
                'url' => '',
                'exists' => false,
                'label' => $lab !== '' ? $lab : str_pad((string)$idx, 3, '0', STR_PAD_LEFT),
                'isSobre' => (($lab !== '' ? $lab : '999') === '000'),
            ];
        }
    }

    return $slides;
}

// -------------------- input --------------------
$imageKey = trim((string)($_GET['image_key'] ?? ''));
$barcode  = safe_seg((string)($_GET['barcode'] ?? ''));
$i        = (int)($_GET['i'] ?? 0);
if ($i < 0) $i = 0;

$collectionId = (int)($_GET['collection_id'] ?? 0);

// soporte de vuelta (lo que ya usás desde colecciones.php)
$fromColId    = (int)($_GET['from_collection_id'] ?? 0);
$fromColTitle = (string)($_GET['from_collection_title'] ?? '');

if ($collectionId <= 0 && $fromColId > 0) $collectionId = $fromColId;

[$ikBarcode, $ikLabel] = parse_image_key($imageKey);
if ($barcode === '' && $ikBarcode !== '') $barcode = $ikBarcode;

// Determinar modo
$mode = ($collectionId > 0) ? 'collection' : 'sobre';

// -------------------- fetch datos + armar slides --------------------
$slides = [];
$err = null;

$titulo = null;          // título principal arriba del visor
$subLineLeft = '';       // línea inferior (izq) "cajón..." etc

if ($mode === 'collection') {
    // Título colección
    if ($fromColTitle !== '') {
        $titulo = $fromColTitle;
    } else {
        $row = q("SELECT title FROM collections_v2 WHERE id=? LIMIT 1", "i", [$collectionId]);
        $titulo = $row ? trim((string)$row[0]['title']) : ('Colección #' . $collectionId);
    }

    // Items mixtos de la colección (foto + recorte)
    $rows = q(
        "SELECT
            COALESCE(item_type, 'foto') AS item_type,
            COALESCE(item_key, image_key) AS item_key,
            image_key,
            position
         FROM collection_items_v2
         WHERE collection_id=?
         ORDER BY position ASC, COALESCE(item_key, image_key) ASC",
        "i",
        [$collectionId]
    );

    $slides = resolve_collection_items_to_slides($rows ?: []);

    // si vino image_key, fijar i al índice exacto en la colección
    if ($imageKey !== '') {
        foreach ($slides as $idx => $s) {
            if ((string)($s['image_key'] ?? '') === $imageKey) {
                $i = (int)$idx;
                break;
            }
        }
    }

    // soporte opcional: salto directo a recorte dentro de colección
    $recorteIdParam = (int)($_GET['recorte_id'] ?? 0);
    if ($recorteIdParam > 0) {
        foreach ($slides as $idx => $s) {
            if ((int)($s['recorte_id'] ?? 0) === $recorteIdParam) {
                $i = (int)$idx;
                break;
            }
        }
    }

    // clamp i
    $total = count($slides);
    if ($total === 0) $i = 0;
    else if ($i >= $total) $i = $total - 1;

    $cur = $slides[$i] ?? null;
    $subLineLeft = 'colección: <strong>' . h((string)$titulo) . '</strong>';

} else {
    // MODO SOBRE: título del sobre
    $tituloSobre = null;
    if ($barcode !== '') {
        $r = q("SELECT titulo FROM titulos WHERE barcode=? LIMIT 1", "s", [$barcode]);
        if ($r) {
            $tituloSobre = trim((string)($r[0]['titulo'] ?? ''));
            if ($tituloSobre === '') $tituloSobre = null;
        }
    }
    $titulo = $tituloSobre ?: 'Sobre';

    if ($barcode === '') {
        http_response_code(400);
        echo "Falta parámetro barcode (o collection_id).";
        exit;
    }

    $items = q(
        "SELECT DISTINCT cajon, inv, nombramiento
         FROM digitales
         WHERE inv=?
           AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
           AND nombramiento IS NOT NULL
           AND nombramiento <> ''
         ORDER BY nombramiento ASC",
        "s",
        [$barcode]
    );

    $base = rtrim((string)BASE_URL, '/');
    foreach (($items ?: []) as $idx => $it) {
        $name = safe_seg((string)($it['nombramiento'] ?? ''));
        $cj   = safe_seg((string)($it['cajon'] ?? ''));
        $url  = $base . '/bajas/' . rawurlencode($cj) . '/' . rawurlencode($barcode) . '/' . rawurlencode($name);

        $num = null;
        if (preg_match('/_(\d{1,4})\.(jpe?g|png)$/i', $name, $m)) $num = (int)$m[1];
        $label = $num !== null ? str_pad((string)$num, 3, '0', STR_PAD_LEFT) : str_pad((string)$idx, 3, '0', STR_PAD_LEFT);

        $slides[] = [
            'i' => (int)$idx,
            'image_key' => ($barcode . '_' . $label),
            'barcode' => $barcode,
            'name' => $name,
            'cajon' => $cj,
            'url' => $url,
            'exists' => true,
            'label' => $label,
            'isSobre' => ($label === '000'),
        ];
    }

    // si vino image_key, saltar a label exacto dentro del sobre
    if ($imageKey !== '' && $ikLabel !== '') {
        foreach ($slides as $idx => $s) {
            if ((string)($s['label'] ?? '') === $ikLabel) { $i = (int)$idx; break; }
        }
    }

    $total = count($slides);
    if ($total === 0) $i = 0;
    else if ($i >= $total) $i = $total - 1;

    $cur = $slides[$i] ?? null;
    $subLineLeft = 'cajón: <strong>' . h((string)($cur['cajon'] ?? '—')) . '</strong> · inv: <strong>' . h($barcode) . '</strong>';
}

$current = $slides[$i] ?? null;
$currentType = (string)($current['type'] ?? 'foto');
$currentUrl = (string)($current['url'] ?? '');
$currentName = (string)($current['name'] ?? '');
$currentLabel = (string)($current['label'] ?? '—');

// -------------------- Botón Edición impresa (día siguiente) --------------------
$paperEnabled = false;
$paperHref = '#';
$paperTitle = 'Edición impresa (sin datos)';

// En modo colección, el barcode real es el del slide actual
$paperBarcode = (string)($current['barcode'] ?? '');
if ($paperBarcode === '' && $barcode !== '') $paperBarcode = $barcode;

if ($paperBarcode !== '') {
    $r = q("SELECT fecha FROM titulos WHERE barcode=? LIMIT 1", "s", [$paperBarcode]);
    $rawFecha = $r ? (string)($r[0]['fecha'] ?? '') : '';
    $dt = parse_yyyymmdd($rawFecha);

    if ($dt) {
        $next = $dt->modify('1 day');
        $nextIso = $next->format('Y-m-d'); // para el visor
        $nextKey = $next->format('Ymd');   // para edicionimpresa.fechaiso (YYYYMMDD)

        // Si NO hay registros ese día, deshabilitamos
        $has = q("SELECT 1 FROM edicionimpresa WHERE fechaiso=? LIMIT 1", "s", [$nextKey]);
        if ($has) {
            $paperEnabled = true;
            // rz=1 => Edición Impresa abre siempre "fit" (no restaura zoom guardado)
            $paperHref = 'edicion_impresa.php?p=1&fecha=' . urlencode($nextIso) . '&ed=U&rz=1';
            $paperTitle = 'Edición impresa — 1º edición del día siguiente (' . $nextIso . ')';
        } else {
            $paperTitle = 'Edición impresa — sin edición para ' . $nextIso;
        }
    } else {
        $paperTitle = 'Edición impresa — sin fecha en titulos para ' . $paperBarcode;
    }
} else {
    $paperTitle = 'Edición impresa — sin barcode';
}
$pageTitle = 'Ver digital';
$mainClass = 'container-fluid';

include __DIR__ . '/inc/header.php';
?>

<section class="card vd">
<style>
  .vd{
    padding: 18px;
    --vd-strip-h: 150px;
    --vd-thumb-h: 114px;
    --vd-thumb-w: 150px;
  }

  @media (max-width: 980px){
    .vd-title-text{ font-size: 18px; }
    .vd-title-sub{ font-size: 13px; }
  }

  .vd .error{
    margin: 12px 0;
    padding: 12px 14px;
    border-radius: 14px;
    background: rgba(220, 38, 38, .10);
    border: 1px solid rgba(220, 38, 38, .18);
    color: #7f1d1d;
  }

  .vd .viewerPro{ display:grid; grid-template-rows:auto 1fr auto; gap:12px; }

  .vd .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    padding: 10px 12px;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.06);
    background: rgba(255,255,255,.45);
    backdrop-filter: blur(6px);
    position: relative;
    z-index: 30;
  }

  .vd .navBtns{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}

.vd .titleInline{
  font-size: 16px;
  font-weight: 900;
  letter-spacing: -0.01em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: min(52vw, 680px);
}

.vd .titleInlineSub{
  font-size: 13px;
  opacity: .68;
  font-weight: 800;
  margin-left: 6px;
}

@media (max-width: 980px){
  .vd .titleInline{ max-width: 92vw; font-size: 15px; }
}

  html.theme-dark .vd .topbar{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.10); }
  html.theme-vintage .vd .topbar{ background: rgba(252,246,232,.72); border-color: rgba(55,40,25,.18); }

  .vd .navBtns{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

  .vd .btn{
    height: 38px;
    padding: 0 14px;
    border-radius: 14px;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.60);
    cursor:pointer;
    font-weight: 650;
    text-decoration:none;
    color: inherit;
    display:inline-flex;
    align-items:center;
    gap:8px;
  }
  .vd .btn:disabled{ opacity:.55; cursor:not-allowed; }
  html.theme-dark .vd .btn{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.12); color: rgba(255,255,255,.92); }
  html.theme-vintage .vd .btn{ background: rgba(55,40,25,.10); border-color: rgba(55,40,25,.22); color: rgba(43,32,22,.95); }

  .vd .counter{
    min-width: 86px;
    text-align:center;
    font-weight: 800;
    border-radius: 14px;
    padding: 7px 10px;
    border: 1px solid rgba(0,0,0,.08);
    background: rgba(255,255,255,.55);
  }
  html.theme-dark .vd .counter{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.12); color: rgba(255,255,255,.92); }
  html.theme-vintage .vd .counter{ background: rgba(252,246,232,.75); border-color: rgba(55,40,25,.18); color: rgba(43,32,22,.90); }

  .vd .badgeSobre{
    display:inline-flex;
    align-items:center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .02em;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.55);
  }
  html.theme-vintage .vd .badgeSobre{ background: rgba(55,40,25,.10); border-color: rgba(55,40,25,.22); color: rgba(43,32,22,.95); }

  .vd .metaRight{ display:flex; gap:10px; align-items:center; }

  /* Menú ⋯ */
  .vd .menuWrap{ position:relative; }
  .vd .menuBtn{
    width: 44px;
    padding: 0;
    display:inline-grid;
    place-items:center;
    font-size: 18px;
  }
  .vd .menu{
    position:absolute;
    right:0;
    top: 44px;
    min-width: 260px;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.92);
    box-shadow: 0 18px 50px rgba(0,0,0,.12);
    padding: 8px;
    display:none;
    z-index: 50;
  }
  .vd .menu.open{ display:block; }
  .vd .menuSep{ height:1px; margin:6px 8px; background: rgba(0,0,0,.10); }
  html.theme-dark .vd .menuSep{ background: rgba(255,255,255,.12); }
  html.theme-vintage .vd .menuSep{ background: rgba(55,40,25,.18); }
  html.theme-dark .vd .menu{
    background: rgba(17,24,39,.92);
    border-color: rgba(255,255,255,.12);
    box-shadow: 0 18px 60px rgba(0,0,0,.35);
  }
  html.theme-vintage .vd .menu{
    background: rgba(252,246,232,.96);
    border-color: rgba(55,40,25,.18);
    box-shadow: 0 18px 60px rgba(55,40,25,.20);
  }
  .vd .menu a, .vd .menu button{
    width:100%;
    text-align:left;
    border:0;
    background: transparent;
    padding: 10px 10px;
    border-radius: 12px;
    cursor:pointer;
    font-weight: 700;
    color: inherit;
  }
  .vd .menu a:hover, .vd .menu button:hover{ background: rgba(0,0,0,.06); }
  html.theme-dark .vd .menu a:hover, html.theme-dark .vd .menu button:hover{ background: rgba(255,255,255,.08); }
  html.theme-vintage .vd .menu a:hover, html.theme-vintage .vd .menu button:hover{ background: rgba(55,40,25,.08); }

  .vd .menuSection{ padding: 6px 8px 8px; }
  .vd .menuLabel{ font-size: 12px; font-weight: 900; opacity: .75; margin-bottom: 6px; }
  .vd .menuRow{ display:flex; align-items:center; gap: 8px; }
  .vd .menuSmall{ font-size: 11px; opacity: .7; white-space: nowrap; }
  .vd .menuRange{ flex: 1 1 auto; }

  /* Stage */
  .vd .stage{
    border-radius: 18px;
    border: 1px solid rgba(0,0,0,.08);
    background: rgba(0,0,0,.86);
    overflow:hidden;
    position:relative;
    z-index: 1;
    touch-action:none;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height: 54vh;
  }
  html.theme-vintage .vd .stage{ background: rgba(35,25,15,.92); border-color: rgba(55,40,25,.22); }
  html.theme-light .vd .stage{ background: rgba(15,23,42,.92); }

  .vd #mainImg{
    max-width: 100%;
    max-height: calc(100vh - 260px);
    object-fit: contain;
    display:block;
    will-change: transform;
    transform-origin: 50% 50%;
    cursor: grab;
  }
  .vd .viewerPro.strip-hidden #mainImg{
    max-height: calc(100vh - 130px);
  }
  @media (max-width: 980px){
    .vd #mainImg{ max-height: calc(100vh - 310px); }
    .vd .viewerPro.strip-hidden #mainImg{ max-height: calc(100vh - 170px); }
  }
  .vd .stage.panning #mainImg,
  .vd .stage.is-dragging #mainImg{ cursor: grabbing; }

  /* Flechas laterales (hover) */
  .vd .stageNav{
    position:absolute;
    top:0; bottom:0;
    width: 18%;
    min-width: 90px;
    display:flex;
    align-items:center;
    justify-content:center;
    opacity: 0;
    transition: opacity .18s ease;
    pointer-events:none;
  }
  .vd .stage:hover .stageNav{ opacity: 1; }
  .vd .stageNav button{
    pointer-events:auto;
    width: 56px;
    height: 56px;
    border-radius: 999px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.42);
    color: rgba(255,255,255,.92);
    font-size: 26px;
    font-weight: 900;
    display:grid;
    place-items:center;
    cursor:pointer;
    box-shadow: 0 10px 26px rgba(0,0,0,.30);
  }
  .vd .stageNav button:hover{ background: rgba(0,0,0,.56); }
  .vd .stageNav.left{ left: 0; }
  .vd .stageNav.right{ right: 0; }

  /* Filmstrip */
  .vd .filmstrip{
    border-radius: 18px;
    border: 1px solid rgba(0,0,0,.08);
    background: rgba(255,255,255,.45);
    padding: 10px;
    overflow:hidden;
    height: var(--vd-strip-h);
    display:flex;
    flex-direction: column;
    gap: 8px;
  }
  .vd .viewerPro.strip-hidden{
    grid-template-rows: auto 1fr;
  }
  .vd .viewerPro.strip-hidden .filmstrip{
    display:none;
  }
  html.theme-dark .vd .filmstrip{ background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.10); }
  html.theme-vintage .vd .filmstrip{ background: rgba(252,246,232,.70); border-color: rgba(55,40,25,.18); }

  .vd .strip{
    display:flex;
    gap:10px;
    overflow-x:auto;
    overflow-y:hidden;
    padding-bottom: 6px;
    scroll-behavior:smooth;
    height: 100%;
    align-items: center;
    flex: 1 1 auto;
    min-height: 0;
  }
  .vd .strip::-webkit-scrollbar{ height: 10px; }
  .vd .strip::-webkit-scrollbar-thumb{ background: rgba(0,0,0,.20); border-radius: 999px; }
  html.theme-dark .vd .strip::-webkit-scrollbar-thumb{ background: rgba(255,255,255,.18); }
  html.theme-vintage .vd .strip::-webkit-scrollbar-thumb{ background: rgba(55,40,25,.22); }

  .vd .thumb{
    position:relative;
    height: var(--vd-thumb-h);
    width: var(--vd-thumb-w);
    border-radius: 14px;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(0,0,0,.40);
    overflow:hidden;
    cursor:pointer;
    flex: 0 0 auto;
    padding: 0;
  }
  .vd .thumb img{
    width:100%;
    height:100%;
    object-fit: cover;
    display:block;
    filter: contrast(1.02) saturate(1.02);
    transform: scale(1.02);
  }
  .vd .thumb .tlabel{
    position:absolute;
    left: 8px;
    bottom: 8px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .04em;
    color: rgba(255,255,255,.92);
    background: rgba(0,0,0,.55);
    border: 1px solid rgba(255,255,255,.14);
  }
  .vd .thumb .tsobre{
    position:absolute;
    right: 8px;
    top: 8px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 900;
    letter-spacing: .04em;
    color: rgba(255,255,255,.92);
    background: rgba(124,45,18,.70);
    border: 1px solid rgba(255,255,255,.14);
  }

  .vd .thumb.active{
    outline: 3px solid rgba(37,99,235,.55);
    box-shadow: 0 10px 24px rgba(0,0,0,.22);
  }
  html.theme-vintage .vd .thumb.active{ outline-color: rgba(95,55,25,.55); }
  html.theme-dark .vd .thumb.active{ outline-color: rgba(185,199,255,.55); }

  .vd .belowMeta{ margin-top: 6px; font-size: 13px; opacity: .78; }

  /* ===== Fullscreen ===== */
  .vd .viewerPro:fullscreen{
    background: rgba(0,0,0,.92);
    padding: 16px;
    border-radius: 18px;
  }
  .vd .viewerPro:fullscreen .topbar{
    position: absolute;
    top: 14px;
    right: 14px;
    left: auto;
    padding: 0;
    border: 0;
    background: transparent;
    backdrop-filter: none;
    z-index: 999;
  }
  .vd .viewerPro:fullscreen .stage{
    min-height: 0;
    height: calc(100vh - var(--vd-strip-h) - 42px);
  }
  .vd .viewerPro.strip-hidden:fullscreen .stage{
    height: calc(100vh - 32px);
  }
  .vd .viewerPro:fullscreen #mainImg{ max-height: 100%; }
  .vd .viewerPro:fullscreen .belowMeta{ display:none; }
  .vd .collectionsWrap{ position:relative; }

.vd .collectionsPanel{
  position:absolute;
  right:0;
  top: 44px;
  min-width: 320px;
  max-width: min(92vw, 420px);
  border-radius: 16px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.92);
  box-shadow: 0 18px 50px rgba(0,0,0,.12);
  padding: 8px;
  display:none;
  z-index: 60;
}

.vd .recortesWrap{ position:relative; }

.vd .recortesPanel{
  position:absolute;
  right:0;
  top: 44px;
  min-width: 320px;
  max-width: min(92vw, 460px);
  border-radius: 16px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.92);
  box-shadow: 0 18px 50px rgba(0,0,0,.12);
  padding: 8px;
  display:none;
  z-index: 60;
}

html.theme-dark .vd .recortesPanel{
  background: rgba(17,24,39,.92);
  border-color: rgba(255,255,255,.12);
  box-shadow: 0 18px 60px rgba(0,0,0,.35);
}
html.theme-vintage .vd .recortesPanel{
  background: rgba(252,246,232,.96);
  border-color: rgba(55,40,25,.18);
  box-shadow: 0 18px 60px rgba(55,40,25,.20);
}

.vd .rpHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding: 6px 8px 10px;
}

.vd .rpTitle{ font-weight: 900; }

.vd .rpClose{
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.65);
  border-radius: 12px;
  padding: 6px 10px;
  cursor:pointer;
  font-weight:800;
}
html.theme-dark .vd .rpClose{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
}

.vd .rpBody{ padding: 0 8px 8px; }
.vd .rpHint{ font-size: 12px; opacity:.75; margin: 0 0 10px; }
.vd .rpList{ display:flex; flex-direction:column; gap:10px; }

.vd .rpItem{
  display:grid;
  grid-template-columns: 78px 1fr;
  gap:10px;
  padding: 8px;
  border-radius: 12px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.03);
  text-decoration:none;
  color: inherit;
}
html.theme-dark .vd .rpItem{
  border-color: rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
}

.vd .rpThumb{
  width: 78px;
  height: 78px;
  border-radius: 10px;
  overflow:hidden;
  background: rgba(0,0,0,.08);
}
.vd .rpThumb img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.vd .rpText{ min-width:0; }
.vd .rpItemTitle{
  font-weight: 800;
  line-height: 1.25;
}
.vd .rpItemSub{
  margin-top: 4px;
  font-size: 12px;
  opacity: .75;
}

html.theme-dark .vd .collectionsPanel{
  background: rgba(17,24,39,.92);
  border-color: rgba(255,255,255,.12);
  box-shadow: 0 18px 60px rgba(0,0,0,.35);
}
html.theme-vintage .vd .collectionsPanel{
  background: rgba(252,246,232,.96);
  border-color: rgba(55,40,25,.18);
  box-shadow: 0 18px 60px rgba(55,40,25,.20);
}


.vd .cpHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding: 6px 8px 10px;
}

.vd .cpTitle{ font-weight: 900; }
.vd .cpClose{
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.65);
  border-radius: 12px;
  padding: 6px 10px;
  cursor:pointer;
  font-weight:800;
}

html.theme-dark .vd .cpClose{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
}

.vd .cpBody{ padding: 0 8px 8px; }
.vd .cpHint{ font-size: 12px; opacity:.75; margin: 0 0 10px; }

.vd .cpSectionTitle{
  font-size: 12px;
  font-weight: 900;
  opacity: .75;
  margin: 10px 0 6px;
}

.vd .cpList{ display:flex; flex-direction:column; gap:8px; }

.vd .cpItem{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding: 8px 10px;
  border-radius: 12px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.03);
}

html.theme-dark .vd .cpItem{
  border-color: rgba(255,255,255,.12);
  background: rgba(255,255,255,.06);
}

.vd .cpLeft{ display:flex; align-items:center; gap:10px; min-width:0; }
.vd .cpLeft label{
  display:flex; align-items:center; gap:10px;
  font-weight: 800;
  cursor:pointer;
  min-width:0;
}
.vd .cpLeft .cpName{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  max-width: 210px;
}

.vd .cpCount{ font-size: 12px; opacity:.7; white-space:nowrap; }

.vd .cpCreateRow{
  display:flex; gap:8px; margin-top:10px;
}

.vd .cpCreateRow input{
  flex:1 1 auto;
  height: 34px;
  border-radius: 12px;
  border: 1px solid rgba(0,0,0,.12);
  padding: 0 10px;
  background: rgba(255,255,255,.75);
}
html.theme-dark .vd .cpCreateRow input{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
  color: rgba(255,255,255,.90);
}

.vd .cpCreateRow button{
  height: 34px;
  border-radius: 12px;
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.06);
  padding: 0 12px;
  cursor:pointer;
  font-weight: 900;
}
html.theme-dark .vd .cpCreateRow button{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
  color: rgba(255,255,255,.92);
}

.vd .cpPubBtn{
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(0,0,0,.06);
  padding: 6px 10px;
  border-radius: 12px;
  cursor:pointer;
  font-weight: 900;
}
html.theme-dark .vd .cpPubBtn{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.12);
  color: rgba(255,255,255,.92);
}

</style>

  <?php if ($err): ?>
    <div class="error"><strong>Error:</strong> <?= h($err) ?></div>
  <?php endif; ?>

  <div class="viewerPro" id="viewerPro"
       data-mode="<?= h($mode) ?>"
       data-barcode="<?= h($barcode) ?>"
       data-collection-id="<?= (int)$collectionId ?>"
       data-initial="<?= (int)$i ?>">

    <!-- TOP BAR -->
    <div class="topbar">
      <div class="navBtns">
        <div class="titleInline" title="<?= h((string)$titulo) ?>">
          <?= h((string)$titulo) ?>
          <span class="titleInlineSub">
            <?php if ($mode === 'collection'): ?>
              · colección #<?= (int)$collectionId ?>
            <?php else: ?>
              · <?= h($barcode) ?>
            <?php endif; ?>
          </span>
        </div>

        <div class="counter" id="counter">—</div>

        <?php if ($mode === 'collection'): ?>
          <a class="btn" id="btnOpenOriginal" href="#" title="Ver este ítem en el sobre original">📦 Ver en el sobre original</a>
        <?php endif; ?>

        <?php if ($fromColId > 0): ?>
          <a class="btn" href="colecciones.php?id=<?= (int)$fromColId ?>" title="Volver a la colección">← Colección</a>
        <?php endif; ?>

        <span class="badgeSobre" id="sobreBadge" style="display:none;">SOBRE</span>
      </div>


      <div class="metaRight">
        <button class="btn menuBtn" id="btnFs" title="Pantalla completa" aria-label="Pantalla completa">⛶</button>
        <button class="btn menuBtn" id="btnToggleStrip" title="Ocultar tira inferior" aria-label="Ocultar tira inferior">▤</button>
                <div class="recortesWrap" data-recortes>
          <button class="btn menuBtn" id="btnRecortes" title="Recortes asociados" aria-label="Recortes asociados">✂️</button>

          <div class="recortesPanel" id="recortesPanel" style="display:none;">
            <div class="rpHead">
              <div class="rpTitle">Recortes asociados</div>
              <button type="button" class="rpClose" id="rpClose">Cerrar</button>
            </div>

            <div class="rpBody">
              <div class="rpHint">Se muestran tus recortes vinculados a la foto actual.</div>
              <div id="rpList" class="rpList"></div>
            </div>
          </div>
        </div>
        <div class="collectionsWrap" data-collections>
          <button class="btn menuBtn" id="btnCollections" title="Colecciones" aria-label="Colecciones">📁</button>
          <?php if ($paperEnabled): ?>
            <a class="btn menuBtn" id="btnPaper" href="<?= h($paperHref) ?>" target="_blank" rel="noopener"
               title="<?= h($paperTitle) ?>" aria-label="Edición impresa">📰</a>
          <?php else: ?>
            <button class="btn menuBtn" id="btnPaper" title="<?= h($paperTitle) ?>" aria-label="Edición impresa" disabled>📰</button>
          <?php endif; ?>

          <div class="collectionsPanel" id="collectionsPanel" style="display:none;">
            <div class="cpHead">
              <div class="cpTitle">Colecciones</div>
              <button type="button" class="cpClose" id="cpClose">Cerrar</button>
            </div>

            <div class="cpBody">
              <div class="cpHint">Tip: marcá dónde querés guardar esta imagen.</div>

              <div class="cpSectionTitle">Mis colecciones</div>
              <div id="cpMyList" class="cpList"></div>

              <div class="cpCreateRow">
                <input id="cpNewTitle" type="text" placeholder="Nueva colección…" />
                <button type="button" id="cpCreateBtn">Crear</button>
              </div>

              <div class="cpSectionTitle" style="margin-top:10px;">Curadas (públicas)</div>
              <div id="cpPublicList" class="cpList"></div>
            </div>
          </div>
        </div>        
      </div>
    </div>

    <!-- STAGE -->
    <div class="stage" id="stage">
      <?php if ($currentUrl): ?>
        <img id="mainImg"
             src="<?= h($currentUrl) ?>"
             alt="<?= h($currentName) ?>"
             loading="eager"
             decoding="async"
             draggable="false">
      <?php else: ?>
        <div style="color:#fff;opacity:.75;padding:24px;">No hay imagen para mostrar.</div>
      <?php endif; ?>

      <div class="stageNav left" id="stagePrev"><button type="button" aria-label="Anterior">‹</button></div>
      <div class="stageNav right" id="stageNext"><button type="button" aria-label="Siguiente">›</button></div>
    </div>

    <!-- FILMSTRIP -->
    <div class="filmstrip">
      <div class="strip" id="strip">
        <?php foreach ($slides as $s): ?>
          <button class="thumb <?= ((int)$s['i'] === (int)$i) ? 'active' : '' ?>"
                  type="button"
                  data-i="<?= (int)$s['i'] ?>"
                  title="<?= h((string)($s['image_key'] ?? $s['name'])) ?>">
            <?php if (!empty($s['url'])): ?>
              <img src="<?= h((string)$s['url']) ?>" alt="<?= h((string)($s['name'] ?? '')) ?>" loading="lazy" decoding="async">
            <?php else: ?>
              <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="" loading="lazy">
            <?php endif; ?>
            <span class="tlabel">
              <?php if (($s['type'] ?? 'foto') === 'recorte'): ?>
                REC
              <?php else: ?>
                <?= h((string)$s['label']) ?>
              <?php endif; ?>
            </span>
            <?php if (($s['type'] ?? 'foto') === 'recorte'): ?>
              <span class="tsobre">RECORTE</span>
            <?php elseif (!empty($s['isSobre'])): ?>
              <span class="tsobre">SOBRE</span>
            <?php endif; ?>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="belowMeta" id="belowMeta">
        <?= $subLineLeft ?>
      </div>
    </div>
  </div>
<script>
(function(){
  const slides = <?= json_encode($slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const total = slides.length;

  const app = document.getElementById('viewerPro');
  const mode = app?.getAttribute('data-mode') || 'sobre';

  const mainImg = document.getElementById('mainImg');
  const counter = document.getElementById('counter');
  const strip = document.getElementById('strip');
  const stage = document.getElementById('stage');
  const sobreBadge = document.getElementById('sobreBadge');

  const stagePrev = document.getElementById('stagePrev');
  const stageNext = document.getElementById('stageNext');

  const btnOpenOriginal = document.getElementById('btnOpenOriginal');
  const btnToggleStrip = document.getElementById('btnToggleStrip');
  const LS_STRIP_VISIBLE = 'afdc_ver_digital_strip_visible';

  const btnCollections = document.getElementById('btnCollections');
  const cp = document.getElementById('collectionsPanel');
  const cpClose = document.getElementById('cpClose');
  const cpMyList = document.getElementById('cpMyList');
  const cpPublicList = document.getElementById('cpPublicList');
  const cpNewTitle = document.getElementById('cpNewTitle');
  const cpCreateBtn = document.getElementById('cpCreateBtn');

  const btnRecortes = document.getElementById('btnRecortes');
  const rp = document.getElementById('recortesPanel');
  const rpClose = document.getElementById('rpClose');
  const rpList = document.getElementById('rpList');

  // === API endpoints (ajustá acá si tu backend usa otros nombres) ===
  const API = {
    me: "api/v2/me.php",
    fav_has: "api/v2/favorites/has.php",
    fav_toggle: "api/v2/favorites/toggle.php",
    collections_list_my: "api/v2/collections/list.php?scope=my",
    collections_list_public: "api/v2/collections/list.php?scope=public",
    collections_toggle_item: "api/v2/collections/toggle-item.php",
    collections_create: "api/v2/collections/create.php",
    collections_copy: "api/v2/collections/copy.php",
    recortes_by_photo: "api/recortes_por_foto.php"
  };

  // Rueda del mouse => scroll horizontal en la tira (sin Shift)
  if (strip) {
    strip.addEventListener('wheel', (e) => {
      if (e.shiftKey) return;
      e.preventDefault();
      strip.scrollLeft += (e.deltaY || 0) + (e.deltaX || 0);
    }, {passive:false});
  }

  // ---------------- estado visor ----------------
  let cur = Math.max(0, Math.min(total-1, parseInt(new URLSearchParams(location.search).get('i') || '0', 10) || 0));
  let scale = 1;
  let tx = 0, ty = 0;
  let baseW = 0, baseH = 0; // tamaño del <img> a escala 1 (ya “fit” por CSS)
  let dragging = false;
  let dragStartX = 0, dragStartY = 0;
  let dragBaseX = 0, dragBaseY = 0;

  // user
  let me = null;

  if (mainImg) {
  mainImg.addEventListener('load', ()=>{
    const r = mainImg.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) {
      baseW = r.width / (scale || 1);
      baseH = r.height / (scale || 1);
    }
    constrainPan();
    setTransform();
  });
}


  function clamp(v,min,max){ return Math.max(min, Math.min(max, v)); }

  function toast(msg){
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = [
      'position:fixed','right:14px','bottom:14px','z-index:9999',
      'background:rgba(0,0,0,.75)','color:#fff','padding:8px 10px',
      'border-radius:10px','font-size:12px','max-width:40vw'
    ].join(';');
    document.body.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transition='opacity .25s'; }, 1200);
    setTimeout(()=>{ t.remove(); }, 1600);
  }

  function currentImageKey(){
    if (!total) return '';
    const s = slides[cur] || {};
    return (s.image_key || s.key || s.id || '').toString();
  }

    function currentSlideType(){
    if (!total) return '';
    const s = slides[cur] || {};
    return (s.type || 'foto').toString();
  }

  function isSobre(){
    const s = slides[cur] || {};
    return !!s.isSobre;
  }

  function updateCounter(){
    if (!counter) return;
    counter.textContent = total ? `${cur+1} / ${total}` : '0 / 0';
  }

  function ensureBaseSize(){
    if (!mainImg) return;
    if (baseW > 0 && baseH > 0) return;
    const r = mainImg.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) {
      // si justo estamos con zoom aplicado, lo llevamos a “base” dividiendo por scale
      baseW = r.width / (scale || 1);
      baseH = r.height / (scale || 1);
    }
  }

  function constrainPan(){
    if (!stage || !mainImg) return;
    ensureBaseSize();
    if (!(baseW > 0 && baseH > 0)) return;

    const sr = stage.getBoundingClientRect();
    const w = baseW * scale;
    const h = baseH * scale;

    const maxX = Math.max(0, (w - sr.width) / 2);
    const maxY = Math.max(0, (h - sr.height) / 2);

    tx = clamp(tx, -maxX, maxX);
    ty = clamp(ty, -maxY, maxY);
  }

  function setTransform(){
    if (!mainImg) return;
    mainImg.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
  }

  function resetView(){
    scale = 1;
    tx = 0; ty = 0;
    setTransform();
    // medir tamaño base a escala 1 (una vez que el browser acomodó el img)
    requestAnimationFrame(() => {
      if (!mainImg) return;
      const r = mainImg.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) { baseW = r.width; baseH = r.height; }
    });
  }

  function pushUrlIndex(){
    const url = new URL(location.href);
    url.searchParams.set('i', String(cur));
    history.replaceState(null, '', url.toString());
  }

  function highlightStrip(){
    if (!strip) return;
    strip.querySelectorAll('.thumb.active').forEach(el=>el.classList.remove('active'));
    const btn = strip.querySelector(`.thumb[data-i="${cur}"]`);
    if (btn) btn.classList.add('active');
  }

    function show(i){
    if (!total) return;
    cur = clamp(i, 0, total-1);

    const s = slides[cur];
    if (mainImg) {
      mainImg.src = s.url || '';
      mainImg.alt = s.label || s.url || '';
    }
    baseW = 0; baseH = 0; // la nueva imagen puede tener otra caja "fit"
    if (sobreBadge) sobreBadge.style.display = isSobre() ? '' : 'none';

    if (btnOpenOriginal) {
      const isPhoto = (currentSlideType() === 'foto');
      if (isPhoto && s.image_key) {
        btnOpenOriginal.style.display = '';
        btnOpenOriginal.removeAttribute('aria-disabled');
        btnOpenOriginal.classList.remove('is-disabled');
        btnOpenOriginal.title = 'Ver este ítem en el sobre original';
      } else {
        btnOpenOriginal.style.display = 'none';
      }
    }

    if (btnRecortes) {
      const isPhoto = (currentSlideType() === 'foto');
      btnRecortes.style.display = (isPhoto && s.image_key) ? '' : 'none';
    }

    updateCounter();
    highlightStrip();
    resetView();
    pushUrlIndex();

        // refresco “por imagen”
    refreshCollectionsIfOpen();
    refreshRecortesIfOpen();
  }

  function prev(){ show(cur-1); }
  function next(){ show(cur+1); }

  // Click en miniaturas del carrusel
  if (strip) {
    strip.addEventListener('click', (e)=>{
      const b = e.target?.closest?.('button.thumb[data-i]');
      if (!b) return;
      const i = parseInt(b.getAttribute('data-i') || '0', 10) || 0;
      show(i);
    });
  }

  // Flechas sobre stage (botón adentro)
  const stagePrevBtn = stagePrev ? stagePrev.querySelector('button') : null;
  const stageNextBtn = stageNext ? stageNext.querySelector('button') : null;
  stagePrevBtn && stagePrevBtn.addEventListener('click', (e)=>{ e.preventDefault(); prev(); });
  stageNextBtn && stageNextBtn.addEventListener('click', (e)=>{ e.preventDefault(); next(); });

  // Teclado
  window.addEventListener('keydown', (e)=>{
    if (e.key === 'ArrowLeft') prev();
    if (e.key === 'ArrowRight') next();
  });

  // Zoom con rueda sobre stage
  if (stage) {
    stage.addEventListener('wheel', (e)=>{
      // si estás sobre la tira, no (ya lo maneja strip)
      if (strip && strip.contains(e.target)) return;

      e.preventDefault();
      const dir = (e.deltaY || 0) > 0 ? -1 : 1;
      const factor = dir > 0 ? 1.12 : 1/1.12;

      const nextScale = clamp(scale * factor, 1, 6);

      // zoom hacia el punto del mouse
      const rect = stage.getBoundingClientRect();
      const cx = e.clientX - rect.left - rect.width/2;
      const cy = e.clientY - rect.top - rect.height/2;

      // ajustamos traslado para que "sienta" natural
      const k = (nextScale/scale) - 1;
      tx -= cx * k;
      ty -= cy * k;

      scale = nextScale;
      constrainPan();
    setTransform();
    }, {passive:false});
  }

  // Drag (pan) cuando está zoom > 1
  if (stage) {
    stage.addEventListener('mousedown', (e)=>{
      if (e.button !== 0) return;
      if (scale <= 1.001) return;
      dragging = true;
      dragStartX = e.clientX;
      dragStartY = e.clientY;
      dragBaseX = tx;
      dragBaseY = ty;
      stage.classList.add('is-dragging');
    });

    window.addEventListener('mousemove', (e)=>{
      if (!dragging) return;
      tx = dragBaseX + (e.clientX - dragStartX);
      ty = dragBaseY + (e.clientY - dragStartY);
    constrainPan();
    setTransform();
    });

    window.addEventListener('mouseup', ()=>{
      if (!dragging) return;
      dragging = false;
      stage.classList.remove('is-dragging');
      constrainPan();
      setTransform();
    });
  }

  // Fullscreen
  const btnFs = document.getElementById('btnFs');
  if (btnFs && app) {
    btnFs.addEventListener('click', async ()=>{
      try{
        if (!document.fullscreenElement) await app.requestFullscreen();
        else await document.exitFullscreen();
      }catch(e){}
    });
  }

  function syncViewerLayout(){
    requestAnimationFrame(()=>{
      if (!mainImg) return;
      const r = mainImg.getBoundingClientRect();
      if (r.width > 0 && r.height > 0) {
        baseW = r.width / (scale || 1);
        baseH = r.height / (scale || 1);
      }
      constrainPan();
      setTransform();
    });
  }

  function setStripVisible(visible){
    if (!app) return;
    app.classList.toggle('strip-hidden', !visible);
    if (btnToggleStrip) {
      btnToggleStrip.textContent = visible ? '▤' : '▥';
      btnToggleStrip.title = visible ? 'Ocultar tira inferior' : 'Mostrar tira inferior';
      btnToggleStrip.setAttribute('aria-label', btnToggleStrip.title);
    }
    try {
      localStorage.setItem(LS_STRIP_VISIBLE, visible ? '1' : '0');
    } catch(_){}
    syncViewerLayout();
  }

  function isStripVisible(){
    return !app || !app.classList.contains('strip-hidden');
  }

  if (btnToggleStrip && app) {
    let savedStripVisible = '1';
    try {
      savedStripVisible = localStorage.getItem(LS_STRIP_VISIBLE) || '1';
    } catch(_){}
    setStripVisible(savedStripVisible !== '0');

    btnToggleStrip.addEventListener('click', ()=>{
      setStripVisible(!isStripVisible());
    });
  }

  // Ver en el sobre original (modo colección)
  if (btnOpenOriginal) {
    btnOpenOriginal.addEventListener('click', (e)=>{
      e.preventDefault();
      const ik = currentImageKey();
      if (!ik || currentSlideType() !== 'foto') return;

      const url = new URL(location.href);
      url.searchParams.set('image_key', ik);
      // limpiamos contexto de colección si existiera
      url.searchParams.delete('collection_id');
      url.searchParams.delete('from_collection_id');
      url.searchParams.delete('from_collection_title');
      url.searchParams.delete('recorte_id');
      // dejamos i en 0 para que el backend resuelva por image_key
      url.searchParams.set('i', '0');
      location.href = url.pathname + '?' + url.searchParams.toString();
    });
  }

  // ===== API helpers =====
  async function apiGet(url){
    const r = await fetch(url, {credentials:'same-origin'});
    const j = await r.json().catch(()=>null);
    if (!j || j.ok === false) throw new Error((j && j.error) || 'api_error');
    return j;
  }

  async function apiPost(url, data){
    // Para POST/acciones, el API v2 exige CSRF por header X-CSRF-Token
    const m = await loadMe(true); // refresca token (y sesión) siempre
    const csrf = (m && m.csrf_token) ? String(m.csrf_token) : '';

    const r = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf
      },
      body: JSON.stringify(data || {})
    });
    const j = await r.json().catch(()=>null);
    if (!j || j.ok === false) throw new Error((j && j.error) || 'api_error');
    return j;
  }

  async function loadMe(force=false){
    if (me && !force) return me;
    const r = await fetch(API.me, { credentials:"same-origin" });
    const j = await r.json().catch(()=>null);
    me = j;
    return me;
  }

  // ===== COLECCIONES =====
  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, (m)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  }

  function renderMy(list){
    if (!cpMyList) return;
    cpMyList.innerHTML = '';
    if (!list || !list.length){
      cpMyList.innerHTML = `<div style="font-size:12px;opacity:.7;">No tenés colecciones todavía.</div>`;
      return;
    }
    for (const c of list){
      const id = Number(c.id);
      const count = Number(c.item_count || 0);
      const checked = !!c.has_current;
      const row = document.createElement('div');
      row.className = 'cpItem';
      row.innerHTML = `
        <div class="cpLeft">
          <label>
            <input type="checkbox" ${checked ? 'checked':''} data-cid="${id}">
            <span class="cpName">${escapeHtml(c.title || '')}</span>
          </label>
        </div>
        <div class="cpCount"><span data-count="${id}">${count}</span> imgs</div>
      `;
      cpMyList.appendChild(row);
    }
  }

  function renderPublic(list){
    if (!cpPublicList) return;
    cpPublicList.innerHTML = '';
    if (!list || !list.length){
      cpPublicList.innerHTML = `<div style="font-size:12px;opacity:.7;">No hay colecciones curadas todavía.</div>`;
      return;
    }
    for (const c of list){
      const id = Number(c.id);
      const count = Number(c.item_count || 0);
      const row = document.createElement('div');
      row.className = 'cpItem';
      row.innerHTML = `
        <div class="cpLeft">
          <div class="cpName" title="${escapeHtml(c.description||'')}">${escapeHtml(c.title || '')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="cpCount">${count} imgs</div>
          <button type="button" class="cpPubBtn" data-copy="${id}">Copiar</button>
        </div>
      `;
      cpPublicList.appendChild(row);
    }
  }
    function renderRecortes(list){
    if (!rpList) return;
    rpList.innerHTML = '';

    if (!list || !list.length){
      rpList.innerHTML = `<div style="font-size:12px;opacity:.7;">No hay recortes vinculados a esta foto.</div>`;
      return;
    }

    for (const r of list){
      const a = document.createElement('a');
      a.className = 'rpItem';
      a.href = r.href || '#';
      a.innerHTML = `
        <div class="rpThumb">
          <img src="${escapeHtml(r.thumb || '')}" alt="${escapeHtml(r.title || '')}" loading="lazy">
        </div>
        <div class="rpText">
          <div class="rpItemTitle">${escapeHtml(r.title || '')}</div>
          <div class="rpItemSub">${escapeHtml(r.subtitle || '')}</div>
        </div>
      `;
      rpList.appendChild(a);
    }
  }

  async function openRecortesPanel(){
    const m = await loadMe(true);
    if (!m || !m.ok || !m.logged_in) {
      const ret = encodeURIComponent(location.pathname + location.search);
      location.href = "login.php?return=" + ret;
      return;
    }

    const ik = currentImageKey();
    if (!ik || currentSlideType() !== 'foto') return;

    if (rp) rp.style.display = 'block';

    try{
      const data = await apiGet(API.recortes_by_photo + "?image_key=" + encodeURIComponent(ik));
      renderRecortes(data?.items || []);
    }catch(e){
      renderRecortes([]);
    }
  }

  let _rpRefreshSeq = 0;
  async function refreshRecortesIfOpen(){
    if (!rp) return;
    if (getComputedStyle(rp).display === 'none') return;

    const ik = currentImageKey();
    if (!ik || currentSlideType() !== 'foto') return;

    const seq = ++_rpRefreshSeq;

    try{
      const data = await apiGet(API.recortes_by_photo + "?image_key=" + encodeURIComponent(ik));
      if (seq !== _rpRefreshSeq) return;
      renderRecortes(data?.items || []);
    }catch(e){
      renderRecortes([]);
    }
  }

  function closeRecortesPanel(){
    if (rp) rp.style.display = 'none';
  }
  async function openCollectionsPanel(){
    const m = await loadMe(true);
    if (!m || !m.ok || !m.logged_in) {
      const ret = encodeURIComponent(location.pathname + location.search);
      location.href = "login.php?return=" + ret;
      return;
    }

    if (cp) cp.style.display = 'block';

    const ik = currentImageKey();
    const [my, pub] = await Promise.all([
      apiGet(API.collections_list_my + "&image_key=" + encodeURIComponent(ik)),
      apiGet(API.collections_list_public)
    ]);

    renderMy(my?.collections || []);
    renderPublic(pub?.collections || []);
  }

  // Si el panel está abierto y cambia la imagen, refrescamos checks según image_key actual
  let _cpRefreshSeq = 0;
  async function refreshCollectionsIfOpen(){
    if (!cp) return;
    if (getComputedStyle(cp).display === 'none') return; // cerrado
    const ik = currentImageKey();
    if (!ik) return;

    const seq = ++_cpRefreshSeq;

    try{
      const my = await apiGet(API.collections_list_my + "&image_key=" + encodeURIComponent(ik));
      if (seq !== _cpRefreshSeq) return; // llegó tarde (cambio rápido de imagen)
      renderMy(my?.collections || []);
    }catch(e){
      renderMy([]); // si falla, no rompemos el visor
    }
  }

  function closeCollectionsPanel(){
    if (cp) cp.style.display = 'none';
  }

    btnRecortes && btnRecortes.addEventListener('click', async (e)=>{
    e.preventDefault();
    e.stopPropagation();
    if (rp && getComputedStyle(rp).display !== 'none') { closeRecortesPanel(); return; }
    await openRecortesPanel();
  });

  rpClose && rpClose.addEventListener('click', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    closeRecortesPanel();
  });

  // Click en botón de colecciones => toggle panel
  btnCollections && btnCollections.addEventListener('click', async (e)=>{
    e.preventDefault();
    e.stopPropagation();
    if (cp && getComputedStyle(cp).display !== 'none') { closeCollectionsPanel(); return; }
    await openCollectionsPanel();
  });

  cpClose && cpClose.addEventListener('click', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    closeCollectionsPanel();
  });

  // Click afuera cierra
  document.addEventListener('click', (e)=>{
    if (cp && getComputedStyle(cp).display !== 'none') {
      if (!e.target.closest('[data-collections]')) closeCollectionsPanel();
    }
    if (rp && getComputedStyle(rp).display !== 'none') {
      if (!e.target.closest('[data-recortes]')) closeRecortesPanel();
    }
  });

  document.addEventListener('keydown', (e)=>{
    if (e.key === 'Escape') {
      closeCollectionsPanel();
      closeRecortesPanel();
    }
    if (e.key === 't' || e.key === 'T') {
      if (e.target && /input|textarea|select/i.test(e.target.tagName)) return;
      e.preventDefault();
      setStripVisible(!isStripVisible());
    }
  });

  // Toggle checkbox
  cpMyList && cpMyList.addEventListener('change', async (e)=>{
    const cb = e.target;
    if (!cb || cb.tagName.toLowerCase() !== 'input') return;
    const cid = Number(cb.getAttribute('data-cid') || 0);
    const ik = currentImageKey();
    if (!cid || !ik) return;

    cb.disabled = true;
    let res = null;
    try{
      res = await apiPost(API.collections_toggle_item, { collection_id: cid, image_key: ik });
    }catch(err){
      res = null;
    }
    cb.disabled = false;

    if (!res || !res.ok){
      cb.checked = !cb.checked;
      return;
    }

    cb.checked = !!res.is_in;
    const countEl = cpMyList.querySelector(`[data-count="${cid}"]`);
    if (countEl) countEl.textContent = String(res.item_count ?? countEl.textContent);
  });

  // Crear colección
  cpCreateBtn && cpCreateBtn.addEventListener('click', async ()=>{
    const title = (cpNewTitle?.value || '').trim();
    if (!title) return;
    const ik = currentImageKey();

    cpCreateBtn.disabled = true;
    let res = null;
    try{
      res = await apiPost(API.collections_create, { title, add_current:true, image_key: ik });
    }catch(err){
      res = null;
    }
    cpCreateBtn.disabled = false;

    if (!res || !res.ok) return;

    cpNewTitle.value = '';
    const my = await apiGet(API.collections_list_my + "&image_key=" + encodeURIComponent(ik));
    renderMy(my?.collections || []);
  });

  // Copiar curada
  cpPublicList && cpPublicList.addEventListener('click', async (e)=>{
    const btn = e.target?.closest?.('button[data-copy]');
    if (!btn) return;
    const srcId = Number(btn.getAttribute('data-copy') || 0);
    if (!srcId) return;

    btn.disabled = true;
    let res = null;
    try{
      res = await apiPost(API.collections_copy, { source_collection_id: srcId });
    }catch(err){
      res = null;
    }
    btn.disabled = false;

    if (!res || !res.ok) return;

    const ik = currentImageKey();
    const my = await apiGet(API.collections_list_my + "&image_key=" + encodeURIComponent(ik));
    renderMy(my?.collections || []);
  });

  // ===== ARRANQUE =====
  updateCounter();
  loadMe().catch(()=>{});
  if (total) show(cur);
})();
</script>

</section>

<?php include __DIR__ . '/inc/footer.php'; ?>