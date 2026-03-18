<?php
/**
 * dia_como_hoy.php
 *
 * Cambios:
 * - sin autoplay
 * - sin cambios automáticos en la URL
 * - navegación manual a cargo del usuario con botones − y +
 */

function afdc_dch_url_path(array $parts): string {
  $p = [];
  foreach ($parts as $seg) {
    $seg = (string)$seg;
    $seg = str_replace('\\', '/', $seg);
    $seg = trim($seg, '/');
    if ($seg === '') continue;
    $p[] = rawurlencode($seg);
  }
  return '/' . implode('/', $p);
}

function afdc_dch_build_url(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = (string)$v;
  }
  $base = strtok($_SERVER['REQUEST_URI'], '?');
  return $base . (empty($q) ? '' : ('?' . http_build_query($q)));
}

function afdc_dch_parse_ddmm(?string $ddmmRaw): array {
  $ddmmRaw = (string)$ddmmRaw;
  if (!preg_match('/^\d{4}$/', $ddmmRaw)) {
    $dd = (int)date('d');
    $mm = (int)date('m');
    return [sprintf('%02d%02d', $dd, $mm), $dd, $mm];
  }
  $dd = (int)substr($ddmmRaw, 0, 2);
  $mm = (int)substr($ddmmRaw, 2, 2);
  if ($dd < 1 || $dd > 31 || $mm < 1 || $mm > 12) {
    $dd = (int)date('d');
    $mm = (int)date('m');
    return [sprintf('%02d%02d', $dd, $mm), $dd, $mm];
  }
  return [$ddmmRaw, $dd, $mm];
}

function afdc_dch_base_path(): string {
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  if ($base === '' || $base === '.') $base = '';
  return $base;
}

function afdc_dch_ed_priority(string $ed): int {
  $e = strtoupper(trim($ed));
  if ($e === '1') return 0;
  if ($e === 'U') return 1;
  if ($e === 'M') return 2;
  if (ctype_digit($e)) return 10 + (int)$e;
  return 999;
}

function afdc_dch_ed_label(string $ed): string {
  $e = strtoupper(trim($ed));
  if ($e === 'U') return 'Edición U';
  if ($e === 'M') return 'Edición M';
  if ($e === '1') return 'Edición 1';
  return 'Edición ' . $ed;
}

function afdc_dch_page_num(array $row): int {
  $pag = (string)($row['pag'] ?? '');
  $barcode = (string)($row['barcode'] ?? '');
  if (preg_match('/(\d{1,4})/', $pag, $m)) return (int)$m[1];
  if (preg_match('/_pg(\d{1,4})_/i', $barcode, $m)) return (int)$m[1];
  if (preg_match('/_(\d{1,4})_\d+$/', $barcode, $m)) return (int)$m[1];
  return 99999;
}

/** DB helpers */
function afdc_db_all($db, string $sql, array $params = [], string $types = ''): array {
  if ($db instanceof PDO) {
    $st = $db->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if ($db instanceof mysqli) {
    $st = $db->prepare($sql);
    if (!$st) return [];
    if (!empty($params)) {
      $types = $types !== '' ? $types : str_repeat('s', count($params));
      $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC) ?: [];
  }
  return [];
}

/** Resolver imágenes de sobres (_000) en lote */
function afdc_dch_resolve_sobres_imgs($db, array $invs): array {
  $map = [];
  $invs = array_values(array_filter(array_map('strval', $invs), fn($v) => $v !== ''));
  if (empty($invs)) return $map;

  $base = afdc_dch_base_path();
  $placeholders = implode(',', array_fill(0, count($invs), '?'));

  $sql = "
    SELECT inv, cajon
    FROM digitales
    WHERE carpeta='Bajas'
      AND nombramiento LIKE ?
      AND inv IN ($placeholders)
    GROUP BY inv, cajon
  ";
  $params = array_merge(['%_000.%'], $invs);
  $types  = str_repeat('s', count($params));

  $rows = afdc_db_all($db, $sql, $params, $types);
  foreach ($rows as $r) {
    $inv   = (string)($r['inv'] ?? '');
    $cajon = (string)($r['cajon'] ?? '');
    if ($inv === '' || $cajon === '') continue;
    $map[$inv] = afdc_dch_url_path([$base, 'bajas', $cajon, $inv, 'BNA_' . $inv . '_000.jpg']);
  }
  return $map;
}

/** Imagen de tapa */
function afdc_dch_make_ed_img(array $row): string {
  $barcode = (string)($row['barcode'] ?? '');
  $folder  = (string)($row['folder'] ?? '');
  $base = afdc_dch_base_path();

  $folderParts = preg_split('~[\\\\/]+~', $folder, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $file = $barcode;
  if ($file !== '' && !preg_match('~\.(jpg|jpeg|png|webp)$~i', $file)) $file .= '.jpg';

  return afdc_dch_url_path(array_merge([$base, 'Edicion_impresa'], $folderParts, [$file]));
}

function afdc_fmt_fecha_any($v): string {
  $s = preg_replace('/\D+/', '', (string)$v);
  if (strlen($s) === 8) return substr($s, 6, 2) . '/' . substr($s, 4, 2) . '/' . substr($s, 0, 4);
  return (string)$v;
}

/* =======================
   INPUT
======================= */
[$dcMd, $dd, $mm] = afdc_dch_parse_ddmm($_GET['dc_md'] ?? null);
$mmddStr = sprintf('%02d%02d', $mm, $dd);
$mesStr  = sprintf('%02d', $mm);
$diaStr  = sprintf('%02d', $dd);

$db = db();

/* =======================
   AÑOS
======================= */
$yearsSobres = [];
$yearsEdicion = [];

try {
  $rows = afdc_db_all($db, "
    SELECT DISTINCT CAST(LEFT(CAST(fecha AS CHAR), 4) AS UNSIGNED) AS anio
    FROM titulos
    WHERE RIGHT(CAST(fecha AS CHAR), 4) = ?
    ORDER BY anio DESC
  ", [$mmddStr], 's');
  foreach ($rows as $r) {
    $y = (int)($r['anio'] ?? 0);
    if ($y > 0) $yearsSobres[$y] = true;
  }
} catch (Throwable $e) {}

try {
  $rows = afdc_db_all($db, "
    SELECT DISTINCT anio
    FROM edicionimpresa
    WHERE mes = ? AND dia = ?
    ORDER BY anio DESC
  ", [$mesStr, $diaStr], 'ss');
  foreach ($rows as $r) {
    $y = (int)($r['anio'] ?? 0);
    if ($y > 0) $yearsEdicion[$y] = true;
  }
} catch (Throwable $e) {}

$yearsEdList = array_keys($yearsEdicion);
rsort($yearsEdList);

$yearsSobList = array_keys($yearsSobres);
rsort($yearsSobList);

$yearsBoth = array_values(array_intersect($yearsEdList, $yearsSobList));
rsort($yearsBoth);

$yearsList = !empty($yearsEdList) ? $yearsEdList : $yearsSobList;

$selYear = (int)($_GET['dc_anio'] ?? 0);
if ($selYear <= 0 || !in_array($selYear, $yearsList, true)) {
  if (!empty($yearsBoth)) $selYear = (int)$yearsBoth[0];
  elseif (!empty($yearsEdList)) $selYear = (int)$yearsEdList[0];
  elseif (!empty($yearsSobList)) $selYear = (int)$yearsSobList[0];
  else $selYear = 0;
}

$fechaIso   = $selYear > 0 ? sprintf('%04d%02d%02d', $selYear, $mm, $dd) : '';
$fechaDash  = $selYear > 0 ? sprintf('%04d-%02d-%02d', $selYear, $mm, $dd) : '';
$fechaLabel = sprintf('%02d/%02d', $dd, $mm);
$fechaLabelFull = $selYear > 0 ? sprintf('%02d/%02d/%04d', $dd, $mm, $selYear) : sprintf('%02d/%02d/—', $dd, $mm);

/* =======================
   SOBRES
======================= */
$sobres = [];
if ($selYear > 0) {
  try {
    $sobres = afdc_db_all($db, "
      SELECT barcode, titulo, nroA, fecha
      FROM titulos
      WHERE fecha = ?
      ORDER BY barcode ASC
    ", [$fechaIso], 's');
  } catch (Throwable $e) {
    $sobres = [];
  }
}

$sCount = count($sobres);
$sIndex = (int)($_GET['s_i'] ?? 0);
if ($sIndex < 0) $sIndex = 0;
if ($sCount > 0 && $sIndex > $sCount - 1) $sIndex = $sCount - 1;

$invList = array_map(fn($r) => (string)($r['barcode'] ?? ''), $sobres);
$sobreImgMap = afdc_dch_resolve_sobres_imgs($db, $invList);

$slides = [];
foreach ($sobres as $r) {
  $inv = (string)($r['barcode'] ?? '');
  if ($inv === '') continue;
  $slides[] = [
    'inv'    => $inv,
    'img'    => (string)($sobreImgMap[$inv] ?? ''),
    'open'   => 'ver_digital.php?barcode=' . rawurlencode($inv),
    'titulo' => (string)($r['titulo'] ?? ''),
    'nroA'   => (string)($r['nroA'] ?? ''),
    'fecha'  => (string)($r['fecha'] ?? ''),
  ];
}

/* =======================
   EDICIÓN IMPRESA
======================= */
$edRows = [];
if ($selYear > 0) {
  try {
    $edRows = afdc_db_all($db, "
      SELECT barcode, fechaiso, anio, mes, dia, ed, pag, folder
      FROM edicionimpresa
      WHERE fechaiso = ?
      ORDER BY ed ASC
    ", [$fechaIso], 's');
  } catch (Throwable $e) {
    $edRows = [];
  }
}

$eds = [];
foreach ($edRows as $r) {
  $ed = (string)($r['ed'] ?? '');
  if ($ed === '') continue;
  if (!isset($eds[$ed])) $eds[$ed] = ['ed' => $ed, 'cover' => null, 'rows' => []];
  $eds[$ed]['rows'][] = $r;
  $p = afdc_dch_page_num($r);
  if ($eds[$ed]['cover'] === null || $p < afdc_dch_page_num($eds[$ed]['cover'])) {
    $eds[$ed]['cover'] = $r;
  }
}

$edKeys = array_keys($eds);
usort($edKeys, function($a, $b) {
  $pa = afdc_dch_ed_priority((string)$a);
  $pb = afdc_dch_ed_priority((string)$b);
  if ($pa !== $pb) return $pa <=> $pb;
  return strcmp((string)$a, (string)$b);
});

$mainEd = !empty($edKeys) ? (string)$edKeys[0] : '';
$mainCover = ($mainEd !== '' && isset($eds[$mainEd])) ? $eds[$mainEd]['cover'] : null;
$mainImg = $mainCover ? afdc_dch_make_ed_img($mainCover) : '';
$mainOpen = ($mainCover && $fechaDash !== '') ? ('edicion_impresa.php?fecha=' . rawurlencode($fechaDash) . '&ed=' . rawurlencode((string)$mainCover['ed']) . '&p=1') : '#';

$thumbs = [];
if (!empty($edKeys)) {
  foreach ($edKeys as $k) {
    if ((string)$k === (string)$mainEd) continue;
    $c = $eds[$k]['cover'] ?? null;
    if (!$c) continue;
    $thumbs[] = [
      'ed' => (string)$k,
      'label' => afdc_dch_ed_label((string)$k),
      'img' => afdc_dch_make_ed_img($c),
      'open' => ($fechaDash !== '' ? ('edicion_impresa.php?fecha=' . rawurlencode($fechaDash) . '&ed=' . rawurlencode((string)$c['ed']) . '&p=1') : '#'),
    ];
  }
}
?>
<style>
  html, body { height: 100%; overflow: hidden; }
  body { margin: 0; }
  * { box-sizing: border-box; }

  .app{
    height: 100vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .app > .topbar{ flex: 0 0 auto; }
  .app > .footer{ flex: 0 0 auto; }
  .app > main{
    flex: 1 1 auto;
    overflow: hidden;
    padding: 0 !important;
    margin: 0 !important;
    max-width: none !important;
    width: 100%;
  }

  .dch-page{
    height: 100%;
    overflow: hidden;
  }

  .dch-muted{ opacity:.72; }

  .dch-pill{
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(0,0,0,.10);
    padding: 7px 10px;
    display:inline-flex;
    align-items:center;
    gap:10px;
    white-space:nowrap;
  }
  .dch-select{
    border:0;
    background:transparent;
    font: inherit;
    font-weight: 900;
    outline:none;
    padding: 0 2px;
  }

  .dch-grid{
    display:grid;
    grid-template-columns: 40% 60%;
    grid-template-rows: 1fr;
    gap: 16px;
    align-items: stretch;
    height: 100%;
    min-height: 0;
    padding: 10px 12px;
  }

  .dch-left{
    display:flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
  }

  .dch-topbar-left{
    display:flex;
    align-items:center;
    justify-content: space-between;
    gap: 10px;
  }
  .dch-titleline{ min-width:0; }
  .dch-h1{
    margin:0;
    font-size: 20px;
    line-height:1.1;
    font-weight: 900;
    white-space: nowrap;
    overflow:hidden;
    text-overflow: ellipsis;
  }
  .dch-topbar-controls{
    display:flex;
    gap: 10px;
    align-items:center;
    flex-wrap: wrap;
    justify-content:flex-end;
  }

  .dch-section-title{
    display:flex;
    align-items:center;
    justify-content: space-between;
    gap: 10px;
    font-weight: 900;
    opacity: .92;
    padding: 2px 2px;
  }

  .dch-s-frame{
    background: rgba(0,0,0,.06);
    border: 1px solid rgba(0,0,0,.10);
    padding: 10px;
    display:flex;
    align-items:center;
    justify-content:center;
    position: relative;
    min-height: 220px;
    flex: 1 1 auto;
    min-height: 0;
  }
  .dch-s-frame a{ display:block; width:100%; height:100%; }
  .dch-s-img{
    width:100%;
    height: 100%;
    object-fit: contain;
    display:block;
    transition: opacity 220ms ease;
    opacity: 1;
  }

  .dch-s-fallback{
    width:100%;
    height: 100%;
    display:none;
    padding: 14px 16px;
    background: rgba(255,255,255,.14);
    border: 1px dashed rgba(0,0,0,.18);
  }
  .dch-s-fb-top{
    display:flex;
    align-items:baseline;
    justify-content:space-between;
    gap:10px;
    margin-bottom: 10px;
  }
  .dch-s-fb-nroa{ font-weight: 900; opacity:.92; white-space:nowrap; }
  .dch-s-fb-date{ font-weight: 800; opacity:.75; white-space:nowrap; }
  .dch-s-fb-title{
    font-weight: 800;
    line-height: 1.25;
    margin: 0 0 10px 0;
    max-height: 5.5em;
    overflow: hidden;
  }
  .dch-s-fb-badge{
    display:inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(0,0,0,.10);
    border: 1px solid rgba(0,0,0,.10);
    font-weight: 900;
    opacity:.80;
  }
  .dch-s-fb-inv{ margin-top: 10px; opacity:.70; font-weight: 800; }

  .dch-controls-row{
    display:flex;
    align-items:center;
    justify-content: flex-start;
    gap: 10px;
    flex-wrap: wrap;
    padding: 2px 2px;
  }
  .dch-carousel-controls{
    display:flex;
    align-items:center;
    gap: 8px;
  }
  .dch-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width: 44px;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.18);
    color: inherit;
    font-weight: 900;
    user-select:none;
    cursor:pointer;
    opacity: .90;
    text-decoration:none;
    font-size: 18px;
  }
  .dch-btn:hover{ opacity: 1; }

  .dch-meta-row{
    display:flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    font-weight: 800;
    opacity:.70;
    padding: 2px 2px 0;
  }

  .dch-no-sobres{
    padding: 6px 2px 2px;
    font-weight: 900;
    opacity: .75;
  }

  .dch-right{
    min-width:0;
    display:flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    overflow: hidden;
  }
  .dch-right-inner{
    height: 100%;
    min-height: 0;
    display:flex;
    gap: 10px;
    align-items: stretch;
    justify-content: center;
  }
  .dch-cover-main{
    flex: 1 1 auto;
    min-width: 0;
    min-height: 0;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
  }
  .dch-cover-link{
    display:flex;
    align-items:center;
    justify-content:center;
    width:100%;
    height:100%;
    text-decoration:none;
  }
  .dch-cover-img{
    height: 100%;
    width: auto;
    max-height: 100%;
    max-width: 100%;
    object-fit: contain;
    display:block;
    border-radius: 0;
    cursor: pointer;
  }

  .dch-thumbs{
    width: 210px;
    height: 100%;
    min-height: 0;
    display:flex;
    flex-direction: column;
    gap: 10px;
    margin-right: 12px;
  }

  .dch-thumb{
    flex: 1 1 0;
    min-height: 0;
    border: 1px solid rgba(0,0,0,.10);
    background: rgba(255,255,255,.10);
    overflow:hidden;
    position: relative;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor: pointer;
    text-decoration:none;
  }
  .dch-thumb img{
    width:100%;
    height:100%;
    object-fit: contain;
    display:block;
    opacity: .98;
  }
  .dch-thumb::after{
    content: attr(data-label);
    position:absolute;
    inset: auto 8px 8px 8px;
    padding: 6px 8px;
    border-radius: 999px;
    background: rgba(0,0,0,.55);
    color: rgba(255,255,255,.92);
    font-weight: 900;
    font-size: 12px;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 160ms ease, transform 160ms ease;
    text-align:center;
    pointer-events:none;
    white-space: nowrap;
    overflow:hidden;
    text-overflow: ellipsis;
  }
  .dch-thumb:hover::after{
    opacity: 1;
    transform: translateY(0);
  }

  .dch-right-empty{
    padding: 14px 12px;
    opacity:.75;
  }

  @media (max-width: 1100px){
    .dch-grid{ grid-template-columns: 1fr; }
    .dch-right-inner{ flex-direction: column; }
    .dch-thumbs{ width: 100%; height: 30%; flex-direction: row; margin-right: 0; }
    .dch-thumb{ flex: 1 1 0; }
  }
</style>

<div class="dch-page">
  <div class="dch-grid">

    <div class="dch-left">
      <div class="dch-topbar-left">
        <div class="dch-titleline">
          <h1 class="dch-h1">Un día como hoy — <?= htmlspecialchars($fechaLabel) ?></h1>
        </div>

        <div class="dch-topbar-controls">
          <span class="dch-pill">
            <select class="dch-select" <?= empty($yearsList) ? 'disabled' : '' ?>
                    onchange="if(!this.disabled) location.href=this.value">
              <?php foreach ($yearsList as $y): ?>
                <option value="<?= htmlspecialchars(afdc_dch_build_url(['dc_anio' => (string)$y, 's_i' => '0'])) ?>"
                  <?= ((int)$y === (int)$selYear) ? 'selected' : '' ?>>
                  <?= (int)$y ?>
                </option>
              <?php endforeach; ?>
              <?php if (empty($yearsList)): ?>
                <option selected>Sin años</option>
              <?php endif; ?>
            </select>
          </span>
        </div>
      </div>

      <div class="dch-section-title">
        <span>Fotografías</span>
        <span id="dchCount" class="dch-muted"><?= $sCount > 0 ? (($sIndex + 1) . ' / ' . $sCount) : '' ?></span>
      </div>

      <?php if ($selYear <= 0): ?>
        <div class="dch-right-empty">No hay años disponibles para este día.</div>

      <?php elseif ($sCount === 0): ?>
        <div class="dch-right-empty">No hay fotografías (sobres) para <?= htmlspecialchars($fechaLabel) ?> de <?= (int)$selYear ?>.</div>
        <div class="dch-no-sobres">No hay fotografías procesadas para esta fecha</div>

      <?php else: ?>
        <div class="dch-s-frame">
          <a id="dchSlideLink" href="#" target="_blank" rel="noopener" title="Abrir sobre en visor">
            <img id="dchSlideImg" class="dch-s-img" src="" alt="" loading="eager" decoding="async">
          </a>

          <div id="dchFallback" class="dch-s-fallback" aria-live="polite">
            <div class="dch-s-fb-top">
              <div id="dchFbNroA" class="dch-s-fb-nroa"></div>
              <div id="dchFbDate" class="dch-s-fb-date"></div>
            </div>
            <div id="dchFbTitle" class="dch-s-fb-title"></div>
            <span class="dch-s-fb-badge">Todavía no digitalizado</span>
            <div id="dchFbInv" class="dch-s-fb-inv"></div>
          </div>
        </div>

        <div class="dch-controls-row">
          <div class="dch-carousel-controls">
            <button class="dch-btn" id="dchMinus" type="button" title="Anterior">−</button>
            <button class="dch-btn" id="dchPlus" type="button" title="Siguiente">+</button>
          </div>
        </div>

        <div class="dch-meta-row">
          <span id="dchInv" class="dch-muted"></span>
        </div>

        <script>
          (function(){
            const slides = <?= json_encode($slides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            let i = <?= (int)$sIndex ?>;
            if (!Number.isFinite(i) || i < 0) i = 0;
            if (slides.length && i >= slides.length) i = slides.length - 1;

            const $img   = document.getElementById('dchSlideImg');
            const $link  = document.getElementById('dchSlideLink');
            const $count = document.getElementById('dchCount');
            const $inv   = document.getElementById('dchInv');

            const $fallback = document.getElementById('dchFallback');
            const $fbNroA = document.getElementById('dchFbNroA');
            const $fbDate = document.getElementById('dchFbDate');
            const $fbTitle= document.getElementById('dchFbTitle');
            const $fbInv  = document.getElementById('dchFbInv');

            const $minus = document.getElementById('dchMinus');
            const $plus  = document.getElementById('dchPlus');

            function formatFecha(v){
              const s = String(v).replace(/\D+/g,'');
              if (s.length === 8) return s.slice(6,8) + '/' + s.slice(4,6) + '/' + s.slice(0,4);
              return String(v);
            }

            function showFallback(s){
              $img.style.display = 'none';
              $link.style.pointerEvents = 'none';
              $fallback.style.display = 'block';

              const nroA = (s.nroA || '').trim();
              const titulo = (s.titulo || '').trim();
              const fecha = (s.fecha || '').trim();
              const inv = (s.inv || '').trim();

              $fbNroA.textContent = nroA ? ('NroA [' + nroA + ']') : 'Sin NroA';
              $fbTitle.textContent = titulo || 'Sin título';
              $fbInv.textContent = inv;
              $fbDate.textContent = fecha ? formatFecha(fecha) : '';
            }

            function hideFallback(){
              $fallback.style.display = 'none';
              $img.style.display = '';
              $link.style.pointerEvents = '';
            }

            function fadeTo(src){
              $img.style.opacity = '0';
              const targetSrc = src || '';
              const done = () => requestAnimationFrame(() => { $img.style.opacity = '1'; });
              $img.onload = done;
              $img.onerror = done;
              setTimeout(() => { $img.src = targetSrc; }, 120);
            }

            function render(){
              if (!slides.length) return;
              const s = slides[i];

              const inv = (s.inv || '');
              const img = (s.img || '');
              const open = (s.open || '#');

              if ($count) $count.textContent = (i + 1) + ' / ' + slides.length;
              if ($inv) $inv.textContent = inv ? inv : '';

              if (!img){
                showFallback(s);
                return;
              }

              hideFallback();
              $link.href = open;
              $link.target = '_blank';
              $link.rel = 'noopener';
              $img.alt = inv ? ('Sobre ' + inv) : 'Sobre';

              fadeTo(img);
            }

            function next(){
              if (!slides.length) return;
              i = (i + 1) % slides.length;
              render();
            }

            function prev(){
              if (!slides.length) return;
              i = (i - 1 + slides.length) % slides.length;
              render();
            }

            $plus && $plus.addEventListener('click', next);
            $minus && $minus.addEventListener('click', prev);

            document.addEventListener('keydown', function(ev){
              if (ev.key === '+' || ev.key === '=') next();
              else if (ev.key === '-' || ev.key === '_') prev();
              else if (ev.key === 'ArrowRight') next();
              else if (ev.key === 'ArrowLeft') prev();
            });

            render();
          })();
        </script>
      <?php endif; ?>
    </div>

    <div class="dch-right">
      <?php if ($selYear <= 0): ?>
        <div class="dch-right-empty">No hay edición impresa disponible.</div>

      <?php elseif (empty($edKeys)): ?>
        <div class="dch-right-empty">
          No hay edición impresa para <b><?= htmlspecialchars($fechaLabel) ?></b> de <b><?= (int)$selYear ?></b>.
        </div>

      <?php else: ?>
        <div class="dch-right-inner">
          <div class="dch-cover-main">
            <?php if ($mainImg !== '' && $mainOpen !== '#'): ?>
              <a class="dch-cover-link" href="<?= htmlspecialchars($mainOpen) ?>" target="_blank" rel="noopener" title="Abrir <?= htmlspecialchars(afdc_dch_ed_label($mainEd)) ?>">
                <img class="dch-cover-img"
                     src="<?= htmlspecialchars($mainImg) ?>"
                     alt="Tapa <?= htmlspecialchars($fechaLabelFull) ?> · <?= htmlspecialchars(afdc_dch_ed_label($mainEd)) ?>"
                     loading="eager" decoding="async">
              </a>
            <?php elseif ($mainImg !== ''): ?>
              <div class="dch-right-empty">No se pudo resolver el enlace de la tapa.</div>
            <?php else: ?>
              <div class="dch-right-empty">No se pudo resolver la tapa para esta fecha.</div>
            <?php endif; ?>
          </div>

          <?php if (!empty($thumbs)): ?>
            <div class="dch-thumbs" aria-label="Otras ediciones">
              <?php foreach ($thumbs as $t): ?>
                <a class="dch-thumb"
                   href="<?= htmlspecialchars($t['open']) ?>"
                   target="_blank" rel="noopener"
                   data-label="[<?= htmlspecialchars($t['label']) ?>]"
                   title="<?= htmlspecialchars($t['label']) ?>">
                  <img src="<?= htmlspecialchars($t['img']) ?>" alt="<?= htmlspecialchars($t['label']) ?>" loading="eager" decoding="async">
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>