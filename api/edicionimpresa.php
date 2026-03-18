<?php
/**
 * AFDC v1 - Edición impresa (Etapa 1)
 *
 * Ruta: /api/edicionimpresa.php
 *
 * Etapa 1:
 * - Panel izquierdo angosto con selector de fecha (rango min/max según DB)
 * - Selector de edición (validado contra tabla edicionimpresa)
 * - Visor doble página (izq simulada, der pag=001)
 */

declare(strict_types=1);

// Bootstrap puede vivir en /api o en raíz (portable). Soportamos ambas.
$bootstrap = __DIR__ . '/../inc/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = __DIR__ . '/inc/bootstrap.php';
}
require_once $bootstrap;

if (!defined('AFDC_EDICION_IMPRESA_URL')) {
    define('AFDC_EDICION_IMPRESA_URL', '/afdc_v2/Edicion_impresa');
}

// -------------------- helpers locales --------------------
function ymd8_to_html_date(string $ymd8): string {
    if (!preg_match('/^\d{8}$/', $ymd8)) return '';
    return substr($ymd8, 0, 4) . '-' . substr($ymd8, 4, 2) . '-' . substr($ymd8, 6, 2);
}

function html_date_to_ymd8(string $htmlDate): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $htmlDate, $m)) return '';
    return $m[1] . $m[2] . $m[3];
}

function pag_to_num(string $pag): int {
    if (preg_match('/(\d+)\s*$/', $pag, $m)) {
        return (int)$m[1];
    }
    return PHP_INT_MAX;
}

function build_edimpresa_image_url(string $folder, string $barcode): string {
    $base = defined('AFDC_EDICION_IMPRESA_URL')
        ? rtrim(AFDC_EDICION_IMPRESA_URL, '/')
        : rtrim(AFDC_BAJAS_URL, '/');

    $folder = str_replace('\\', '/', $folder);
    $folder = trim($folder, '/');

    $parts = [];
    if ($folder !== '') {
        $parts = array_values(array_filter(array_map('trim', explode('/', $folder)), 'strlen'));
    }

    while (!empty($parts) && mb_strtolower((string)$parts[0]) === 'edicion_impresa') {
        array_shift($parts);
    }

    $barcodeBase = preg_replace('~\.(jpg|jpeg|png|webp)$~i', '', $barcode);
    $barcodeStem = preg_replace('~_pg\d+_\d+$~i', '', $barcodeBase);

    if (!empty($parts)) {
        $last = (string)$parts[count($parts) - 1];
        if (
            mb_strtolower($last) === mb_strtolower($barcodeBase) ||
            mb_strtolower($last) === mb_strtolower($barcodeStem)
        ) {
            array_pop($parts);
        }
    }

    $segments = array_merge($parts, [$barcodeBase . '.jpg']);
    $encoded = array_map('rawurlencode', $segments);

    return $base . '/' . implode('/', $encoded);
}

function normalize_p_param($pRaw): int {
    $p = (int)$pRaw;
    if ($p < 1) $p = 1;
    if (($p % 2) === 0) $p -= 1;
    if ($p < 1) $p = 1;
    return $p;
}

// -------------------- DB: rango de fechas --------------------
$range = q("SELECT MIN(fechaIso) AS minFecha, MAX(fechaIso) AS maxFecha FROM edicionimpresa");
$minFecha = $range[0]['minFecha'] ?? '';
$maxFecha = $range[0]['maxFecha'] ?? '';

$pageTitle = 'Edición impresa';

if (!$minFecha || !$maxFecha) {
    $header = __DIR__ . '/../inc/header.php';
    if (!is_file($header)) $header = __DIR__ . '/../inc/header.php';
    include $header;
    ?>
    <div class="card">
      <h2 class="card__title">Edición impresa</h2>
      <p class="muted">No hay registros en <code>edicionimpresa</code>.</p>
    </div>
    <?php
    $footer = __DIR__ . '/../inc/footer.php';
    if (!is_file($footer)) $footer = __DIR__ . '/../inc/footer.php';
    include $footer;
    exit;
}

// -------------------- Estado --------------------
$reqRecorteId = (int)g('recorte', 0);
$reqEditar    = ((int)g('editar', 0) === 1);

$recorteActivo = null;
if ($reqRecorteId > 0) {
    $rr = q("SELECT * FROM recortes WHERE id=? LIMIT 1", 'i', [$reqRecorteId]);
    if ($rr) {
        $recorteActivo = $rr[0];
    }
}

$reqFechaHtml = (string)g('fecha', '');
$reqFechaIso  = $reqFechaHtml ? html_date_to_ymd8($reqFechaHtml) : '';

if ($recorteActivo) {
    $fRec = (string)($recorteActivo['fechalso'] ?? '');
    if ($fRec !== '' && preg_match('/^\d{8}$/', $fRec)) {
        $reqFechaIso = $fRec;
    }
}

if ($reqFechaIso === '' || !preg_match('/^\d{8}$/', $reqFechaIso)) {
    $reqFechaIso = $minFecha;
}

$existsFecha = q("SELECT 1 AS ok FROM edicionimpresa WHERE fechaIso=? LIMIT 1", 's', [$reqFechaIso]);
if (!$existsFecha) {
    $reqFechaIso = $minFecha;
}

$eds = q("SELECT DISTINCT ed FROM edicionimpresa WHERE fechaIso=? ORDER BY ed ASC", 's', [$reqFechaIso]);
$edList = array_values(array_filter(array_map(static fn($r) => (string)($r['ed'] ?? ''), $eds), 'is_nonempty_string'));

$reqEd = (string)g('ed', '');
if ($recorteActivo) {
    $reqEd = (string)($recorteActivo['ed'] ?? '');
}
if ($reqEd === '' || !in_array($reqEd, $edList, true)) {
    $reqEd = $edList[0] ?? '';
}

$reqP = normalize_p_param(g('p', 1));

if ($recorteActivo) {
    $pDerRec = (int)($recorteActivo['pag_der'] ?? 0);
    $pIzqRec = (int)($recorteActivo['pag_izq'] ?? 0);

    if ($pDerRec > 0) {
        $reqP = normalize_p_param($pDerRec);
    } elseif ($pIzqRec > 0) {
        $reqP = normalize_p_param(($pIzqRec % 2 === 1) ? $pIzqRec : ($pIzqRec + 1));
    }
}

$pages = q(
    "SELECT folder, barcode, pag
     FROM edicionimpresa
     WHERE fechaIso=? AND ed=?",
    'ss',
    [$reqFechaIso, $reqEd]
);

if (!$pages) {
    $pages = [];
}

usort($pages, function($a, $b) {
    return pag_to_num((string)($a['pag'] ?? '')) <=> pag_to_num((string)($b['pag'] ?? ''));
});

$pageByNum = [];
$nums = [];
foreach ($pages as $r) {
    $n = pag_to_num((string)($r['pag'] ?? ''));
    if ($n === PHP_INT_MAX) continue;
    if (!isset($pageByNum[$n])) {
        $pageByNum[$n] = $r;
        $nums[] = $n;
    }
}

sort($nums);
$maxN = $nums ? $nums[count($nums)-1] : 1;
$maxRight = ($maxN % 2 === 0) ? ($maxN + 1) : $maxN;

$reqP = normalize_p_param($reqP);
if ($reqP < 1) $reqP = 1;
if ($reqP > $maxRight) $reqP = $maxRight;
if (($reqP % 2) === 0) $reqP -= 1;
if ($reqP < 1) $reqP = 1;

$rightNum = $reqP;
$leftNum  = $reqP - 1;

if (($maxN % 2) === 0 && $rightNum === ($maxN + 1)) {
    $rightRow = null;
    $leftRow  = $pageByNum[$maxN] ?? null;
} else {
    $rightRow = $pageByNum[$rightNum] ?? null;
    $leftRow  = ($leftNum >= 1) ? ($pageByNum[$leftNum] ?? null) : null;

    if (!$rightRow) {
        $fallback = null;
        foreach ($nums as $n) {
            if ($n >= $reqP && ($n % 2) === 1) { $fallback = $n; break; }
        }
        if ($fallback === null) {
            foreach ($nums as $n) {
                if (($n % 2) === 1) { $fallback = $n; break; }
            }
        }

        if ($fallback !== null) {
            $rightNum = $fallback;
            $leftNum  = $rightNum - 1;
            $reqP     = $rightNum;

            $rightRow = $pageByNum[$rightNum] ?? null;
            $leftRow  = ($leftNum >= 1) ? ($pageByNum[$leftNum] ?? null) : null;
        }
    }
}

$rightUrl = ($rightRow && !empty($rightRow['folder']) && !empty($rightRow['barcode']))
    ? build_edimpresa_image_url((string)$rightRow['folder'], (string)$rightRow['barcode'])
    : '';

$leftUrl = ($leftRow && !empty($leftRow['folder']) && !empty($leftRow['barcode']))
    ? build_edimpresa_image_url((string)$leftRow['folder'], (string)$leftRow['barcode'])
    : '';

$minHtml = ymd8_to_html_date($minFecha);
$maxHtml = ymd8_to_html_date($maxFecha);
$curHtml = ymd8_to_html_date($reqFechaIso);

$leftBarcode  = (string)($leftRow['barcode'] ?? '');
$rightBarcode = (string)($rightRow['barcode'] ?? '');

$leftPagDb  = isset($leftRow['pag']) ? (string)$leftRow['pag'] : '';
$rightPagDb = isset($rightRow['pag']) ? (string)$rightRow['pag'] : '';

$leftRecortadoDe = '';
if ($leftRow && !empty($leftRow['barcode'])) {
    $leftRecortadoDe = build_edimpresa_image_url(
        (string)($leftRow['folder'] ?? ''),
        (string)$leftRow['barcode']
    );
}

$rightRecortadoDe = '';
if ($rightRow && !empty($rightRow['barcode'])) {
    $rightRecortadoDe = build_edimpresa_image_url(
        (string)($rightRow['folder'] ?? ''),
        (string)$rightRow['barcode']
    );
}

$header = __DIR__ . '/../inc/header.php';
if (!is_file($header)) $header = __DIR__ . '/../inc/header.php';
include $header;
?>

<style>
  #ei-page{
    width: calc(100vw - 40px);
    margin-left: calc(50% - 50vw + 20px);
  }

  .ei-wrap{
    display:flex;
    gap:14px;
    align-items:stretch;
    height: calc(100vh - 140px);
    min-height: 520px;
  }

  .ei-side{ width:280px; flex:0 0 280px; }
  .ei-side .card{
    position: sticky;
    top: 12px;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 170px);
  }

  .ei-controls{ flex: 0 0 auto; }
  .ei-nav{ margin-top: auto; }

  .ei-view{ flex:1 1 auto; min-width:0; height:100%; }

  .ei-table{
    height:100%;
    padding:12px;
    border-radius:18px;
    background:
      radial-gradient(1100px 700px at 18% 12%, rgba(255,255,255,.10), rgba(255,255,255,0) 60%),
      radial-gradient(900px 650px at 82% 28%, rgba(255,255,255,.07), rgba(255,255,255,0) 65%),
      linear-gradient(135deg, rgba(62,46,34,.92), rgba(28,22,16,.92));
    box-shadow:
      0 18px 60px rgba(0,0,0,.45) inset,
      0 12px 30px rgba(0,0,0,.25);
  }

  .ei-spread{
    height:auto;
    display:flex;
    gap:0;
    align-items:center;
    position: relative;
  }

  .ei-page{
    flex:0 0 auto;
    height:auto;
    min-width:0;
    background:transparent;
    border:none;
    border-radius:0;
    box-shadow:none;
    overflow:visible;
    display:flex;
    align-items:center;
    position: relative;
  }

  .ei-page--left{ justify-content:flex-end; }
  .ei-page--right{ justify-content:flex-start; }

  .ei-imgwrap{
    position: relative;
    display: inline-block;
    line-height: 0;
  }

  .ei-img{
    height:auto;
    width:auto;
    max-height:none;
    max-width:none;
    object-fit:unset;
    display:block;
    margin:0;
    box-shadow: 0 10px 24px rgba(0,0,0,.35);
  }

  .ei-controls label{display:block; font-size:12px; opacity:.8; margin:10px 0 6px;}
  .ei-controls select,.ei-controls input[type="date"]{width:100%;}
  .ei-hint{margin-top:10px; font-size:12px; opacity:.75;}
  .ei-hint kbd{
    font: inherit;
    padding: 2px 6px;
    border-radius: 6px;
    border: 1px solid rgba(255,255,255,.15);
    background: rgba(0,0,0,.25);
  }

  @media (max-width: 980px){
    #ei-page{
      width: calc(100vw - 20px);
      margin-left: calc(50% - 50vw + 10px);
    }
    .ei-wrap{ flex-direction:column; height:auto; min-height:0; }
    .ei-side{ width:auto; flex:0 0 auto; }
    .ei-side .card{ position:static; }
    .ei-table{ height:auto; }
    .ei-spread{ height:auto; }
    .ei-page{ min-height:55vh; }
  }

  .ei-viewport{
    height: 100%;
    overflow: hidden;
    position: relative;
  }

  #eiZoom{
    position: absolute;
    left: 50%;
    top: 50%;
    height: auto;
    width: auto;
    will-change: transform;
    transform-origin: center center;
    cursor: grab;
    user-select: none;
    touch-action: none;
    opacity: 0;
  }

  #eiZoom.is-grabbing{ cursor: grabbing; }

  body.ei-ready #eiZoom{
    opacity: 1;
    transition: opacity 120ms ease;
  }

  .ei-spread-overlay{
    position:absolute;
    left:0;
    top:0;
    width:0;
    height:0;
    cursor:default;
    z-index:50;
    background:transparent;
  }

  .ei-spread-overlay.is-armed{
    cursor:crosshair;
  }

  .ei-spread-rect{
    position:absolute;
    display:none;
    z-index:60;
    pointer-events:none;
    border: 3px solid #ffe066;
    background: rgba(255, 224, 102, 0.18);
    outline: 1px solid rgba(0,0,0,.65);
    box-shadow:
      0 0 0 1px rgba(0,0,0,.35) inset,
      0 0 18px rgba(0,0,0,.45);
  }

  .ei-seam-guide{
    position:absolute;
    top:0;
    bottom:0;
    width:2px;
    background: rgba(255,255,255,.22);
    z-index: 40;
    pointer-events:none;
    display:none;
  }
</style>

<?php
$prevP = $reqP - 2;
$nextP = $reqP + 2;

if ($prevP < 1) $prevP = 1;
if ($nextP > $maxRight) $nextP = $maxRight;

$hasPrev = ($reqP > 1);
$hasNext = false;

if (($maxN % 2) === 0 && $nextP === ($maxN + 1)) {
    $hasNext = true;
} else {
    foreach ($nums as $n) {
        if (($n % 2) === 1 && $n >= $nextP) { $hasNext = true; break; }
    }
}
?>

<div id="ei-page">
<div class="ei-wrap">
  <aside class="ei-side">
    <div class="card">
      <div class="card__title" style="display:flex; justify-content:space-between; align-items:baseline; gap:10px;">
        <span>Edición impresa</span>
        <span class="muted" style="font-size:12px;">tapa</span>
      </div>

      <form class="ei-controls" method="get" action="<?= h($_SERVER['SCRIPT_NAME'] ?? 'edicionimpresa.php') ?>">
        <input type="hidden" name="p" value="<?= (int)$reqP ?>">
        <label for="fecha">Fecha</label>
        <input
          id="fecha"
          name="fecha"
          type="date"
          value="<?= h($curHtml) ?>"
          min="<?= h($minHtml) ?>"
          max="<?= h($maxHtml) ?>"
          required
        >

        <label for="ed">Edición</label>
        <select id="ed" name="ed" required>
          <?php foreach ($edList as $ed): ?>
            <option value="<?= h($ed) ?>" <?= $ed === $reqEd ? 'selected' : '' ?>><?= h($ed) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="ei-hint">
          Fechas disponibles: <?= h($minHtml) ?> → <?= h($maxHtml) ?>
        </div>

        <div class="ei-hint" style="margin-top:12px; line-height:1.35;">
          <strong>Atajos</strong><br>
          • Zoom: rueda del mouse (Ctrl+rueda más fino)<br>
          • Reset zoom: doble click<br>
          • Recorte: mantener <kbd>Shift</kbd> y arrastrar sobre el pliego<br>
          • Navegar: flechas ← → (cuando no estás escribiendo)
        </div>
      </form>

      <div id="ei-nav" class="ei-nav">
        <div style="display:flex; gap:10px;">
          <a class="btn <?= $hasPrev ? '' : 'btn--disabled' ?>"
             href="<?= $hasPrev ? h($_SERVER['SCRIPT_NAME'].'?fecha='.$curHtml.'&ed='.rawurlencode($reqEd).'&p='.$prevP) : 'javascript:void(0)' ?>"
             <?= $hasPrev ? '' : 'aria-disabled="true"' ?>>
             ← Anterior
          </a>

          <a class="btn <?= $hasNext ? '' : 'btn--disabled' ?>"
             href="<?= $hasNext ? h($_SERVER['SCRIPT_NAME'].'?fecha='.$curHtml.'&ed='.rawurlencode($reqEd).'&p='.$nextP) : 'javascript:void(0)' ?>"
             <?= $hasNext ? '' : 'aria-disabled="true"' ?>>
             Siguiente →
          </a>
        </div>

        <div class="ei-hint" style="margin-top:10px;">
          Página derecha: <strong><?= (int)$rightNum ?></strong>
        </div>
      </div>
    </div>
  </aside>

  <section class="ei-view">
    <div class="ei-table">
      <div class="ei-viewport" id="eiViewport">
        <div
          class="ei-spread"
          id="eiZoom"
          data-fechaiso="<?= h($reqFechaIso) ?>"
          data-ed="<?= h($reqEd) ?>"
          data-barcode-izq="<?= h($leftBarcode) ?>"
          data-barcode-der="<?= h($rightBarcode) ?>"
          data-pag-izq="<?= h($leftPagDb) ?>"
          data-pag-der="<?= h($rightPagDb) ?>"
          data-rec-izq="<?= h($leftRecortadoDe) ?>"
          data-rec-der="<?= h($rightRecortadoDe) ?>"
        data-recorte-id="<?= (int)($recorteActivo['id'] ?? 0) ?>"
        data-recorte-x="<?= h((string)($recorteActivo['xval'] ?? '')) ?>"
        data-recorte-y="<?= h((string)($recorteActivo['yval'] ?? '')) ?>"
        data-recorte-w="<?= h((string)($recorteActivo['ancho'] ?? '')) ?>"
        data-recorte-h="<?= h((string)($recorteActivo['alto'] ?? '')) ?>"
        data-recorte-editar="<?= $reqEditar ? '1' : '0' ?>"
        >
          <div class="ei-page ei-page--left" aria-label="Página izquierda">
            <?php if ($leftUrl): ?>
              <div class="ei-imgwrap ei-imgwrap--left"
                   data-side="left"
                   data-barcode="<?= h($leftBarcode) ?>"
                   data-pag="<?= h($leftPagDb) ?>"
                   data-recortadode="<?= h($leftRecortadoDe) ?>">
                <img class="ei-img" src="<?= h($leftUrl) ?>" alt="Página <?= (int)$leftNum ?>">
              </div>
            <?php endif; ?>
          </div>

          <div class="ei-page ei-page--right" aria-label="Página derecha">
            <?php if ($rightUrl): ?>
              <div class="ei-imgwrap ei-imgwrap--right"
                   data-side="right"
                   data-barcode="<?= h($rightBarcode) ?>"
                   data-pag="<?= h($rightPagDb) ?>"
                   data-recortadode="<?= h($rightRecortadoDe) ?>">
                <img class="ei-img" src="<?= h($rightUrl) ?>" alt="Página <?= (int)$rightNum ?>">
              </div>
            <?php endif; ?>
          </div>

          <div class="ei-seam-guide" id="eiSeamGuide"></div>
        </div>

        <div class="ei-spread-overlay" id="eiSpreadOverlay" title="Shift + arrastrar para recortar sobre el pliego"></div>
        <div class="ei-spread-rect" id="eiSpreadRect"></div>
      </div>
    </div>
  </section>
</div>
</div>

<script>
(function(){
  var form = document.querySelector('.ei-controls');
  if(!form) return;
  var fecha = form.querySelector('#fecha');
  var ed = form.querySelector('#ed');
  var p = form.querySelector('input[name="p"]');

  function submitReset(){
    if(p) p.value = 1;
    form.submit();
  }

  if(fecha) fecha.addEventListener('change', submitReset);
  if(ed) ed.addEventListener('change', submitReset);
})();
</script>

<script>
(function(){
  var nav = document.getElementById('ei-nav');
  if (!nav) return;

  var buttons = nav.querySelectorAll('a.btn');
  if (!buttons || buttons.length < 2) return;

  var prevLink = buttons[0];
  var nextLink = buttons[1];

  function isNavigable(a){
    if (!a) return false;
    if (a.classList.contains('btn--disabled')) return false;
    var href = (a.getAttribute('href') || '').trim();
    if (!href) return false;
    if (href === '#' || href.toLowerCase().startsWith('javascript')) return false;
    return true;
  }

  window.addEventListener('keydown', function(e){
    var t = e.target;
    if (t && (t.tagName === 'INPUT' || t.tagName === 'SELECT' || t.tagName === 'TEXTAREA')) return;

    if (e.key === 'ArrowLeft' && isNavigable(prevLink)) {
      e.preventDefault();
      window.location.href = prevLink.href;
    }
    if (e.key === 'ArrowRight' && isNavigable(nextLink)) {
      e.preventDefault();
      window.location.href = nextLink.href;
    }
  });
})();
</script>

<script>
(function(){
  var viewport = document.getElementById('eiViewport');
  var zoomEl   = document.getElementById('eiZoom');
  if(!viewport || !zoomEl) return;

  var slider = document.getElementById('eiZoomSlider');
  var out    = document.getElementById('eiZoomLabel');
  var btnIn  = document.getElementById('eiZoomIn');
  var btnOut = document.getElementById('eiZoomOut');
  var btnRes = document.getElementById('eiZoomReset');

  var KEY = 'afdc_ei_zoom_v1';

  try{
    var qs = new URLSearchParams(location.search || '');
    if(qs.get('rz') === '1'){
      localStorage.removeItem(KEY);
    }
  }catch(e){}

  var min = 1, max = 1;
  var fitScale = 1;
  var scale = 1;
  var x = 0, y = 0;

  var baseW = 0, baseH = 0;

  function measureBase(){
    var prev = zoomEl.style.transform;
    zoomEl.style.transform = 'none';
    var r = zoomEl.getBoundingClientRect();
    baseW = r.width;
    baseH = r.height;
    zoomEl.style.transform = prev;
  }

  function computeFitScale(){
    if(!baseW || !baseH) measureBase();
    var vr = viewport.getBoundingClientRect();
    if(!vr.width || !vr.height || !baseW || !baseH) return 1;
    var s = Math.min(vr.width / baseW, vr.height / baseH);
    return Math.min(1, s);
  }

  function clampPan(){
    if(!baseW || !baseH) measureBase();

    var vr = viewport.getBoundingClientRect();
    var maxX = Math.max(0, (baseW * scale - vr.width) / 2);
    var maxY = Math.max(0, (baseH * scale - vr.height) / 2);

    if (x >  maxX) x =  maxX;
    if (x < -maxX) x = -maxX;
    if (y >  maxY) y =  maxY;
    if (y < -maxY) y = -maxY;
  }

  function apply(){
    clampPan();
    zoomEl.style.transform =
      'translate3d(-50%, -50%, 0) translate3d(' + x + 'px,' + y + 'px,0) scale(' + scale + ')';

    var pct = Math.round(scale * 100);
    if(out) out.textContent = pct + '%';
    if(slider) slider.value = String(pct);

    try{
      localStorage.setItem(KEY, JSON.stringify({scale: scale, x: x, y: y}));
    }catch(e){}

    window.dispatchEvent(new CustomEvent('ei:zoomchange'));
  }

  function setScale(newScale){
    scale = Math.max(min, Math.min(max, newScale));
    apply();
  }

  function reset(){
    scale = min;
    x = 0;
    y = 0;
    apply();
  }

  try{
    var qs2 = new URLSearchParams(location.search || '');
    if(qs2.get('rz') === '1'){
      localStorage.removeItem(KEY);
    }
  }catch(e){}

  try{
    var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
    if(saved && typeof saved.scale === 'number'){
      scale = saved.scale;
      x = typeof saved.x === 'number' ? saved.x : 0;
      y = typeof saved.y === 'number' ? saved.y : 0;
    }
  }catch(e){}

  if(slider){
    slider.addEventListener('input', function(){
      setScale(parseInt(slider.value, 10) / 100);
    });
  }
  if(btnIn)  btnIn.addEventListener('click', function(){ setScale(scale + 0.10); });
  if(btnOut) btnOut.addEventListener('click', function(){ setScale(scale - 0.10); });
  if(btnRes) btnRes.addEventListener('click', reset);

  var dragging = false;
  var startX = 0, startY = 0, startPanX = 0, startPanY = 0;

  viewport.addEventListener('pointerdown', function(e){
    if (e.shiftKey) return;
    if(scale <= (min + 0.01)) return;

    dragging = true;
    zoomEl.classList.add('is-grabbing');
    startX = e.clientX;
    startY = e.clientY;
    startPanX = x;
    startPanY = y;
    viewport.setPointerCapture(e.pointerId);
    e.preventDefault();
  });

  viewport.addEventListener('pointermove', function(e){
    if(!dragging) return;
    x = startPanX + (e.clientX - startX);
    y = startPanY + (e.clientY - startY);
    apply();
  });

  viewport.addEventListener('pointerup', function(e){
    if(!dragging) return;
    dragging = false;
    zoomEl.classList.remove('is-grabbing');
    try{ viewport.releasePointerCapture(e.pointerId); }catch(_){}
  });

  viewport.addEventListener('pointercancel', function(){
    dragging = false;
    zoomEl.classList.remove('is-grabbing');
  });

  viewport.addEventListener('wheel', function(e){
    e.preventDefault();

    var step = e.ctrlKey ? 0.05 : 0.10;
    var delta = (e.deltaY < 0) ? step : -step;

    var vr = viewport.getBoundingClientRect();
    var px = e.clientX - (vr.left + vr.width  / 2);
    var py = e.clientY - (vr.top  + vr.height / 2);

    var prevScale = scale;
    var nextScale = Math.max(min, Math.min(max, scale + delta));
    if (nextScale === prevScale) return;

    var k = nextScale / prevScale;
    x = x + px * (1 - k);
    y = y + py * (1 - k);

    scale = nextScale;
    apply();
  }, {passive:false});

  viewport.addEventListener('dblclick', function(){
    reset();
  });

  document.body.classList.remove('ei-ready');

  function onReady(){
    measureBase();
    var oldFit = fitScale;
    fitScale = computeFitScale();
    min = fitScale;
    max = 1;

    var wasAtFit = Math.abs(scale - oldFit) <= 0.01;
    if(wasAtFit){
      scale = min;
      x = 0;
      y = 0;
    } else {
      scale = Math.max(min, Math.min(max, scale));
    }

    apply();

    requestAnimationFrame(function(){
      document.body.classList.add('ei-ready');
    });
  }

  window.addEventListener('resize', function(){
    measureBase();
    apply();
  });

  var imgs = zoomEl.querySelectorAll('img');
  function decodeImg(im){
    try{
      if(im && im.decode) return im.decode().catch(function(){});
    }catch(e){}
    return Promise.resolve();
  }

  function readyAll(){
    var ps = [];
    imgs.forEach(function(im){
      if(!im) return;
      if(im.complete){
        ps.push(decodeImg(im));
      } else {
        ps.push(new Promise(function(res){
          im.addEventListener('load', function(){ decodeImg(im).then(res); }, {once:true});
          im.addEventListener('error', function(){ res(); }, {once:true});
        }));
      }
    });
    Promise.all(ps).then(onReady);
  }

  readyAll();
})();
</script>

<script>
(function(){
  var viewport = document.getElementById('eiViewport');
  var spread = document.getElementById('eiZoom');
  var overlay = document.getElementById('eiSpreadOverlay');
  var rectEl = document.getElementById('eiSpreadRect');
  var seamGuide = document.getElementById('eiSeamGuide');

  if(!viewport || !spread || !overlay || !rectEl) return;

  var leftWrap  = spread.querySelector('.ei-imgwrap--left');
  var rightWrap = spread.querySelector('.ei-imgwrap--right');

  var api = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/') . '/api/recortes.php') ?>;

  function clamp(v, min, max){
    return Math.max(min, Math.min(max, v));
  }

  function toast(msg, isError){
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = [
      'position:fixed',
      'right:14px',
      'bottom:14px',
      'z-index:9999',
      'background:' + (isError ? 'rgba(140,32,32,.92)' : 'rgba(0,0,0,.78)'),
      'color:#fff',
      'padding:9px 12px',
      'border-radius:10px',
      'font-size:12px',
      'max-width:40vw',
      'box-shadow:0 10px 26px rgba(0,0,0,.28)'
    ].join(';');

    document.body.appendChild(t);
    setTimeout(function(){
      t.style.opacity = '0';
      t.style.transition = 'opacity .25s';
    }, 1800);
    setTimeout(function(){
      t.remove();
    }, 2200);
  }

  function getSpreadMetrics(){
    var spreadRect = spread.getBoundingClientRect();
    var leftImg  = leftWrap  ? leftWrap.querySelector('img.ei-img') : null;
    var rightImg = rightWrap ? rightWrap.querySelector('img.ei-img') : null;

    var leftRect  = leftImg  ? leftImg.getBoundingClientRect() : null;
    var rightRect = rightImg ? rightImg.getBoundingClientRect() : null;

    var contentLeft = null;
    var contentTop = null;
    var contentRight = null;
    var contentBottom = null;

    [leftRect, rightRect].forEach(function(r){
      if(!r) return;
      if(contentLeft === null || r.left < contentLeft) contentLeft = r.left;
      if(contentTop === null || r.top < contentTop) contentTop = r.top;
      if(contentRight === null || r.right > contentRight) contentRight = r.right;
      if(contentBottom === null || r.bottom > contentBottom) contentBottom = r.bottom;
    });

    if(contentLeft === null){
      contentLeft = spreadRect.left;
      contentTop = spreadRect.top;
      contentRight = spreadRect.right;
      contentBottom = spreadRect.bottom;
    }

    var seamXViewport = null;
    if(leftRect && rightRect){
      seamXViewport = leftRect.right - viewport.getBoundingClientRect().left;
    } else if(leftRect){
      seamXViewport = leftRect.right - viewport.getBoundingClientRect().left;
    } else if(rightRect){
      seamXViewport = rightRect.left - viewport.getBoundingClientRect().left;
    }

    return {
      spreadRect: spreadRect,
      leftRect: leftRect,
      rightRect: rightRect,
      contentLeftViewport: contentLeft - viewport.getBoundingClientRect().left,
      contentTopViewport: contentTop - viewport.getBoundingClientRect().top,
      contentRightViewport: contentRight - viewport.getBoundingClientRect().left,
      contentBottomViewport: contentBottom - viewport.getBoundingClientRect().top,
      contentWidth: contentRight - contentLeft,
      contentHeight: contentBottom - contentTop,
      seamXViewport: seamXViewport
    };
  }
  function drawStoredRect(){
    var x = parseFloat(spread.dataset.recorteX || '');
    var y = parseFloat(spread.dataset.recorteY || '');
    var w = parseFloat(spread.dataset.recorteW || '');
    var h = parseFloat(spread.dataset.recorteH || '');

    if (!isFinite(x) || !isFinite(y) || !isFinite(w) || !isFinite(h)) return;
    if (w <= 0 || h <= 0) return;

    updateOverlayBounds();

    var ow = parseFloat(overlay.style.width || '0');
    var oh = parseFloat(overlay.style.height || '0');
    if (ow <= 0 || oh <= 0) return;

    setRect(x * ow, y * oh, (x + w) * ow, (y + h) * oh);
  }
  function updateOverlayBounds(){
    var m = getSpreadMetrics();

    overlay.style.left = m.contentLeftViewport + 'px';
    overlay.style.top = m.contentTopViewport + 'px';
    overlay.style.width = m.contentWidth + 'px';
    overlay.style.height = m.contentHeight + 'px';

    if(seamGuide && m.seamXViewport !== null){
      seamGuide.style.left = (m.seamXViewport - m.contentLeftViewport) + 'px';
      seamGuide.style.display = 'block';
    } else if(seamGuide){
      seamGuide.style.display = 'none';
    }
  }

  function setRect(xa, ya, xb, yb){
    var l = Math.min(xa, xb);
    var t = Math.min(ya, yb);
    var w = Math.abs(xb - xa);
    var h = Math.abs(yb - ya);

    rectEl.style.display = 'block';
    rectEl.style.left = (parseFloat(overlay.style.left || '0') + l) + 'px';
    rectEl.style.top = (parseFloat(overlay.style.top || '0') + t) + 'px';
    rectEl.style.width = w + 'px';
    rectEl.style.height = h + 'px';
  }

  var down = false;
  var x0 = 0;
  var y0 = 0;

  window.addEventListener('keydown', function(e){
    if(e.key === 'Shift'){
      overlay.classList.add('is-armed');
    }
  });

  window.addEventListener('keyup', function(e){
    if(e.key === 'Shift'){
      overlay.classList.remove('is-armed');
    }
  });

  overlay.addEventListener('pointerdown', function(e){
    if(!e.shiftKey) return;

    updateOverlayBounds();

    var r = overlay.getBoundingClientRect();
    x0 = clamp(e.clientX - r.left, 0, r.width);
    y0 = clamp(e.clientY - r.top, 0, r.height);

    down = true;
    overlay.setPointerCapture(e.pointerId);
    setRect(x0, y0, x0, y0);

    e.preventDefault();
    e.stopPropagation();
  });

  overlay.addEventListener('pointermove', function(e){
    if(!down) return;

    var r = overlay.getBoundingClientRect();
    var x1 = clamp(e.clientX - r.left, 0, r.width);
    var y1 = clamp(e.clientY - r.top, 0, r.height);

    setRect(x0, y0, x1, y1);
    e.preventDefault();
    e.stopPropagation();
  });

  overlay.addEventListener('pointerup', function(e){
    if(!down) return;
    down = false;

    var r = overlay.getBoundingClientRect();
    var x1 = clamp(e.clientX - r.left, 0, r.width);
    var y1 = clamp(e.clientY - r.top, 0, r.height);

    var left = Math.min(x0, x1);
    var top = Math.min(y0, y1);
    var w = Math.abs(x1 - x0);
    var h = Math.abs(y1 - y0);

    if (w < 6 || h < 6){
      rectEl.style.display = 'none';
      e.stopPropagation();
      return;
    }

    var m = getSpreadMetrics();
    if (!m.contentWidth || !m.contentHeight){
      rectEl.style.display = 'none';
      toast('No se pudo medir el pliego', true);
      e.stopPropagation();
      return;
    }

    var xN = left / m.contentWidth;
    var yN = top / m.contentHeight;
    var wN = w / m.contentWidth;
    var hN = h / m.contentHeight;

    xN = clamp(xN, 0, 1);
    yN = clamp(yN, 0, 1);
    wN = clamp(wN, 0, 1);
    hN = clamp(hN, 0, 1);

    if (xN + wN > 1) wN = 1 - xN;
    if (yN + hN > 1) hN = 1 - yN;

    var hasLeft = !!leftWrap;
    var hasRight = !!rightWrap;

    var tipo = 'simple_izq';
    if (hasLeft && hasRight && m.seamXViewport !== null) {
      var seamInsideOverlay = m.seamXViewport - m.contentLeftViewport;
      if (left < seamInsideOverlay && (left + w) > seamInsideOverlay) {
        tipo = 'doble';
      } else if (left >= seamInsideOverlay) {
        tipo = 'simple_der';
      } else {
        tipo = 'simple_izq';
      }
    } else if (hasRight && !hasLeft) {
      tipo = 'simple_der';
    } else {
      tipo = 'simple_izq';
    }

    var barcodeIzq = spread.dataset.barcodeIzq || '';
    var barcodeDer = spread.dataset.barcodeDer || '';
    var pagIzq = spread.dataset.pagIzq || '';
    var pagDer = spread.dataset.pagDer || '';
    var fechaIso = spread.dataset.fechaiso || '';
    var ed = spread.dataset.ed || '';
    var recIzq = spread.dataset.recIzq || '';
    var recDer = spread.dataset.recDer || '';

    var barcode = '';
    var recortadoDe = '';

    if (tipo === 'simple_der') {
      barcode = barcodeDer;
      recortadoDe = recDer;
    } else if (tipo === 'simple_izq') {
      barcode = barcodeIzq;
      recortadoDe = recIzq;
    } else {
      barcode = barcodeIzq || barcodeDer;
      recortadoDe = [recIzq, recDer].filter(Boolean).join(' | ');
    }

    var recorteId = parseInt(spread.dataset.recorteId || '0', 10);
    var recorteEditar = spread.dataset.recorteEditar === '1';

    var payload = {
      barcode: barcode,
      barcode_izq: barcodeIzq,
      barcode_der: barcodeDer,
      pag_izq: pagIzq,
      pag_der: pagDer,
      fechaIso: fechaIso,
      ed: ed,
      tipo: tipo,
      recortadoDe: recortadoDe,
      xval: xN,
      yval: yN,
      ancho: wN,
      alto: hN
    };

    if (recorteEditar && recorteId > 0) {
      payload.recorte_id = recorteId;
    }

    fetch(api, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    })
    .then(function(res){
      return res.text().then(function(txt){
        var json = null;
        try { json = JSON.parse(txt); } catch(err) {}
        return {ok: res.ok, status: res.status, json: json, raw: txt};
      });
    })
    .then(function(resp){
      rectEl.style.display = 'none';

      if (!resp.ok) {
        toast('HTTP ' + resp.status + ' al guardar recorte', true);
        return;
      }

      if (!resp.json) {
        toast('Respuesta inválida de recortes.php', true);
        return;
      }

      if (resp.json.ok === true) {
        toast(resp.json.msg || 'Recorte guardado');
        return;
      }

      toast(resp.json.msg || resp.json.error || 'No se pudo guardar recorte', true);
    })
    .catch(function(){
      rectEl.style.display = 'none';
      toast('Error guardando recorte', true);
    });

    e.preventDefault();
    e.stopPropagation();
  });

  overlay.addEventListener('pointercancel', function(e){
    down = false;
    rectEl.style.display = 'none';
    e.preventDefault();
    e.stopPropagation();
  });

  window.addEventListener('resize', updateOverlayBounds);
  window.addEventListener('ei:zoomchange', updateOverlayBounds);

  var imgs = spread.querySelectorAll('img.ei-img');
  imgs.forEach(function(im){
    if (im.complete) {
      updateOverlayBounds();
    } else {
      im.addEventListener('load', updateOverlayBounds, {once:true});
      im.addEventListener('error', updateOverlayBounds, {once:true});
    }
  });

    updateOverlayBounds();
  drawStoredRect();
})();
</script>

<?php
$footer = __DIR__ . '/../inc/footer.php';
if (!is_file($footer)) $footer = __DIR__ . '/../inc/footer.php';
include $footer;