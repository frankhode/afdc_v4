<?php
declare(strict_types=1);

$mainClass = 'container-fluid';

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth_v2.php';

afdc_v2_session_start();

$u = afdc_v2_current_user();
if (!$u) {
    die('Acceso no autorizado');
}

$usuario_id    = (int)($u['id'] ?? 0);
$usuario_label = (string)($u['display_name'] ?? ($u['username'] ?? ''));

if ($usuario_id <= 0) {
    die('Usuario inválido');
}

$pageTitle = 'Mis recortes';

if (!function_exists('mr_h')) {
    function mr_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('mr_norm01')) {
    function mr_norm01(float $v): float {
        if ($v < 0.0) return 0.0;
        if ($v > 1.0) return 1.0;
        return $v;
    }
}

if (!function_exists('mr_normalize_edimpresa_url')) {
    function mr_normalize_edimpresa_url(string $path): string {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') return '';

        $pathOnly = parse_url($path, PHP_URL_PATH);
        if (is_string($pathOnly) && $pathOnly !== '') {
            $path = $pathOnly;
        }

        $baseUrl = defined('AFDC_EDICION_IMPRESA_URL') ? (string)AFDC_EDICION_IMPRESA_URL : '';
        $basePath = $baseUrl !== '' ? (string)parse_url($baseUrl, PHP_URL_PATH) : '/Edicion_impresa';
        $basePath = '/' . trim(str_replace('\\', '/', $basePath), '/');

        $path = preg_replace('#^/?Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));
        $path = preg_replace('#^/?afdc_v2/Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));
        $path = preg_replace('#^/?archivocronica/Edicion(%20|[ _])impresa/#i', $basePath . '/', ltrim($path, '/'));

        if ($path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }

        $quotedBase = preg_quote(trim($basePath, '/'), '#');
        $path = preg_replace('#/(?:' . $quotedBase . '/)+#i', '/' . trim($basePath, '/') . '/', $path);

        return preg_replace('#/+#', '/', $path);
    }
}

if (!function_exists('mr_view_data_from_recorte')) {
    function mr_view_data_from_recorte(array $r): array {
        $tipo = (string)($r['tipo'] ?? '');
        $x    = mr_norm01((float)($r['xval'] ?? 0));
        $y    = mr_norm01((float)($r['yval'] ?? 0));
        $w    = mr_norm01((float)($r['ancho'] ?? 0));
        $h    = mr_norm01((float)($r['alto'] ?? 0));

        if (($x + $w) > 1.0) $w = max(0.0, 1.0 - $x);
        if (($y + $h) > 1.0) $h = max(0.0, 1.0 - $y);

        $raw = (string)($r['recortadoDe'] ?? '');
        $parts = array_values(array_filter(array_map('trim', explode('|', $raw)), 'strlen'));
        $parts = array_map('mr_normalize_edimpresa_url', $parts);

        $mode = 'single';
        $imgA = $parts[0] ?? '';
        $imgB = $parts[1] ?? '';

        // Importante:
        // para simple_izq / simple_der asumimos que recortadoDe ya apunta a la página correcta
        // y que x/y/w/h están en coordenadas de ESA página.
        // para doble sí se usan sobre el pliego completo.
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
}

$sql = "
SELECT r.*,
       COUNT(v.id) AS vinculos
FROM recortes r
LEFT JOIN recorte_vinculos v
  ON v.recorte_id = r.id
WHERE r.usuario_id = ?
GROUP BY r.id
ORDER BY r.id DESC
";

$recortes = q($sql, 'i', [$usuario_id]);

$recorteSelId = (int)($_GET['recorte'] ?? 0);
$modo = (string)($_GET['modo'] ?? '');
if ($modo === '' && ((int)($_GET['contexto'] ?? 0) === 1)) {
    $modo = 'contexto';
}
if (!in_array($modo, ['recorte', 'contexto', 'ajustar'], true)) {
    $modo = 'recorte';
}

$recorteSel = null;
if ($recorteSelId > 0) {
    $rr = q(
        "SELECT * FROM recortes WHERE id = ? AND usuario_id = ? LIMIT 1",
        'ii',
        [$recorteSelId, $usuario_id]
    );
    if ($rr) {
        $recorteSel = $rr[0];
    }
}

if (!$recorteSel && $recortes) {
    $recorteSel = $recortes[0];
    $recorteSelId = (int)$recorteSel['id'];
}

$view = $recorteSel ? mr_view_data_from_recorte($recorteSel) : null;

require_once __DIR__ . '/inc/header.php';
?>

<style>
html, body{
  overflow-x:hidden;
}

:root{
  --mr-surface: var(--afdc-card, rgba(255,255,255,.06));
  --mr-surface-2: var(--afdc-btn, rgba(255,255,255,.06));
  --mr-text: var(--afdc-text, rgba(255,255,255,.92));
  --mr-muted: var(--afdc-muted, rgba(255,255,255,.65));
  --mr-border: var(--afdc-border, rgba(255,255,255,.10));
  --mr-shadow: var(--afdc-shadow, 0 10px 30px rgba(0,0,0,.35));
  --mr-btn-bg: var(--afdc-btn, rgba(255,255,255,.06));
  --mr-btn-bg-hover: var(--afdc-btn-hover, rgba(255,255,255,.10));
  --mr-btn-primary-bg: var(--afdc-btn, rgba(255,255,255,.08));
  --mr-btn-primary-hover: var(--afdc-btn-hover, rgba(255,255,255,.14));
  --mr-pill-bg: var(--afdc-btn, rgba(255,255,255,.06));
  --mr-accent: #ca3a25;
  --mr-stage-bg: #111;
  --mr-shell-bg: var(--afdc-btn, rgba(255,255,255,.06));
  --mr-radius: 16px;
}

.mr-wrap{
  width:100%;
  max-width:none;
  margin:2px 0 8px;
  padding:0 6px 0 0;
  box-sizing:border-box;
  color:var(--mr-text);
}

.mr-layout{
  display:grid;
  grid-template-columns:320px minmax(0,1fr);
  gap:14px;
  align-items:start;
}

.mr-card{
  background:var(--mr-surface);
  color:var(--mr-text);
  border:1px solid var(--mr-border);
  border-radius:var(--mr-radius);
  overflow:hidden;
  box-shadow:var(--mr-shadow);
  backdrop-filter: blur(14px);
}

.mr-head{
  padding:12px 14px;
  border-bottom:1px solid var(--mr-border);
}

.mr-title{
  margin:0;
  font-size:22px;
  color:var(--mr-text);
}

.mr-sub{
  margin-top:4px;
  color:var(--mr-muted);
  font-size:13px;
}

.mr-list{
  max-height:calc(100vh - 185px);
  overflow:auto;
}

.mr-item{
  display:block;
  padding:14px 16px;
  border-bottom:1px solid var(--mr-border);
  text-decoration:none;
  color:inherit;
  background:transparent;
  transition:background-color .15s ease;
}

.mr-item:hover{
  background:var(--mr-btn-hover);
  color:inherit;
  text-decoration:none;
}

.mr-item.is-active{
  background:var(--mr-btn-bg);
}

.mr-item-top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  margin-bottom:6px;
}

.mr-item-id{
  font-weight:700;
  color:var(--mr-text);
}

.mr-item-date{
  font-size:12px;
  color:var(--mr-muted);
}

.mr-item-meta{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  font-size:13px;
  color:var(--mr-muted);
}

.mr-pill{
  display:inline-block;
  padding:2px 8px;
  border-radius:999px;
  background:var(--mr-pill-bg);
  color:var(--mr-text);
  font-size:12px;
  border:1px solid var(--mr-border);
}

.mr-empty{
  padding:24px 16px;
  color:var(--mr-muted);
}

.mr-preview{
  padding:12px 14px 12px;
}

.mr-preview-head{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
  margin-bottom:8px;
}

.mr-preview-title{
  margin:0;
  font-size:24px;
  color:var(--mr-text);
}

.mr-preview-meta{
  margin-top:4px;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  font-size:13px;
  color:var(--mr-muted);
}

.mr-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}

.mr-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:8px 12px;
  border-radius:12px;
  border:1px solid var(--mr-border);
  background:var(--mr-btn-bg);
  color:var(--mr-text);
  text-decoration:none;
  font-size:13px;
  cursor:pointer;
  transition:background-color .15s ease, border-color .15s ease, transform .05s ease;
}

.mr-btn:hover{
  background:var(--mr-btn-bg-hover);
  color:var(--mr-text);
  text-decoration:none;
}

.mr-btn:active{
  transform:translateY(1px);
}

.mr-btn-close{
  width:36px;
  height:36px;
  padding:0;
  font-size:20px;
  font-weight:700;
}

.mr-btn-primary{
  background:var(--mr-btn-primary-bg);
  font-weight:700;
}

.mr-btn-primary:hover{
  background:var(--mr-btn-primary-hover);
}

.mr-section{
  margin-top:8px;
}

.mr-section h3{
  margin:0 0 8px;
  font-size:16px;
  color:var(--mr-text);
}

.mr-muted{
  font-size:12px;
  color:var(--mr-muted);
  margin-top:6px;
}

.mr-stage-shell{
  width:100%;
  background:var(--mr-shell-bg);
  border:1px solid var(--mr-border);
  border-radius:12px;
  overflow:hidden;
}

.mr-stage{
  position:relative;
  width:100%;
  height:520px;
  overflow:hidden;
  background:var(--mr-stage-bg);
  cursor:grab;
  user-select:none;
}

.mr-stage.is-dragging{
  cursor:grabbing;
}

.mr-spread{
  position:absolute;
  left:0;
  top:0;
  transform-origin:top left;
  will-change:transform;
}

.mr-spread img{
  position:absolute;
  top:0;
  left:0;
  display:block;
  max-width:none;
  user-select:none;
  -webkit-user-drag:none;
  pointer-events:none;
}

.mr-overlay{
  position:absolute;
  border:3px solid rgba(202,58,37,.95);
  background:rgba(202,58,37,.12);
  box-sizing:border-box;
  pointer-events:none;
}

.mr-edit-rect{
  position:absolute;
  border:3px solid rgba(202,58,37,.95);
  background:rgba(202,58,37,.12);
  box-sizing:border-box;
}

.mr-edit-rect .handle{
  position:absolute;
  width:14px;
  height:14px;
  border-radius:50%;
  background:#fff;
  border:2px solid rgba(202,58,37,.95);
  box-sizing:border-box;
}

.mr-edit-rect .handle.nw{ left:-7px; top:-7px; cursor:nwse-resize; }
.mr-edit-rect .handle.ne{ right:-7px; top:-7px; cursor:nesw-resize; }
.mr-edit-rect .handle.sw{ left:-7px; bottom:-7px; cursor:nesw-resize; }
.mr-edit-rect .handle.se{ right:-7px; bottom:-7px; cursor:nwse-resize; }
.mr-edit-rect .handle.n{ left:calc(50% - 7px); top:-7px; cursor:ns-resize; }
.mr-edit-rect .handle.s{ left:calc(50% - 7px); bottom:-7px; cursor:ns-resize; }
.mr-edit-rect .handle.w{ left:-7px; top:calc(50% - 7px); cursor:ew-resize; }
.mr-edit-rect .handle.e{ right:-7px; top:calc(50% - 7px); cursor:ew-resize; }

.mr-render-box{
  width:100%;
  background:var(--mr-stage-bg);
  border:1px solid var(--mr-border);
  border-radius:12px;
  overflow:auto;
}

.mr-render-box img{
  display:block;
  width:100%;
  height:auto;
}

.mr-render-shell{
  width:100%;
  background:var(--mr-shell-bg);
  border:1px solid var(--mr-border);
  border-radius:12px;
  overflow:hidden;
}

.mr-render-stage{
  position:relative;
  width:100%;
  height:520px;
  overflow:hidden;
  background:var(--mr-stage-bg);
  cursor:grab;
  user-select:none;
}

.mr-render-stage.is-dragging{
  cursor:grabbing;
}

.mr-render-stage img{
  position:absolute;
  left:0;
  top:0;
  display:block;
  max-width:none;
  width:auto;
  height:auto;
  transform-origin:top left;
  user-select:none;
  -webkit-user-drag:none;
  pointer-events:none;
}

@media (max-width: 1100px){
  .mr-wrap{
    padding:0 2px;
    margin:2px 0 8px;
  }

  .mr-layout{
    grid-template-columns:1fr;
  }

  .mr-list{
    max-height:none;
  }

  .mr-stage{
    height:420px;
  }

  .mr-render-stage{
    height:420px;
  }
}
</style>

<div class="mr-wrap">
  <div class="mr-layout">

    <div class="mr-card">
      <div class="mr-head">
        <h1 class="mr-title">Mis recortes</h1>
        <div class="mr-sub">Usuario: <?= mr_h($usuario_label) ?></div>
      </div>

      <?php if (!$recortes): ?>
        <div class="mr-empty">Todavía no tenés recortes guardados.</div>
      <?php else: ?>
        <div class="mr-list">
          <?php foreach ($recortes as $r): ?>
            <?php
              $rid = (int)$r['id'];
              $active = ($rid === $recorteSelId);
              $pagIzq = (int)($r['pag_izq'] ?? 0);
              $pagDer = (int)($r['pag_der'] ?? 0);
              $pagTxt = $pagDer > 0 ? ($pagIzq . '-' . $pagDer) : (string)$pagIzq;
            ?>
            <a class="mr-item <?= $active ? 'is-active' : '' ?>" href="misrecortes.php?recorte=<?= $rid ?>">
              <div class="mr-item-top">
                <div class="mr-item-id">Recorte #<?= $rid ?></div>
                <div class="mr-item-date"><?= mr_h((string)($r['fechalso'] ?? '')) ?></div>
              </div>
              <div class="mr-item-meta">
                <span class="mr-pill"><?= mr_h((string)($r['tipo'] ?? '')) ?></span>
                <span>Pág. <?= mr_h($pagTxt) ?></span>
                <span>Vínculos: <?= (int)($r['vinculos'] ?? 0) ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="mr-card">
      <?php if (!$recorteSel || !$view): ?>
        <div class="mr-empty">Seleccioná un recorte para ver el detalle.</div>
      <?php else: ?>
        <?php
          $tipo = (string)($recorteSel['tipo'] ?? '');
          $pagIzq = (int)($recorteSel['pag_izq'] ?? 0);
          $pagDer = (int)($recorteSel['pag_der'] ?? 0);
          $pagTxt = $pagDer > 0 ? ($pagIzq . '-' . $pagDer) : (string)$pagIzq;
          $recorteId = (int)$recorteSel['id'];
          $linkBase = 'misrecortes.php?recorte=' . $recorteId;
        ?>
        <div class="mr-preview">
          <div class="mr-preview-head">
            <div>
              <h2 class="mr-preview-title">Recorte #<?= $recorteId ?></h2>              
            </div>

            <div class="mr-actions">
              <?php if ($modo === 'recorte'): ?>
                <a class="mr-btn" href="<?= mr_h($linkBase . '&modo=contexto') ?>">Ver contexto</a>
                <a class="mr-btn" href="<?= mr_h($linkBase . '&modo=ajustar') ?>">Ajustar</a>
              <?php elseif ($modo === 'contexto'): ?>
                <a class="mr-btn mr-btn-close" href="<?= mr_h($linkBase) ?>" title="Cerrar contexto">×</a>
                <a class="mr-btn" href="<?= mr_h($linkBase . '&modo=ajustar') ?>">Ajustar</a>
              <?php else: ?>
                <button type="button" class="mr-btn mr-btn-primary js-save-adjust" data-recorte-id="<?= $recorteId ?>">Guardar ajuste</button>
                <a class="mr-btn" href="<?= mr_h($linkBase) ?>">Cancelar</a>
              <?php endif; ?>
              <a class="mr-btn" href="vincular_recorte.php?id=<?= $recorteId ?>">Vincular</a>
              <a class="mr-btn" href="edicion_impresa.php?recorte=<?= $recorteId ?>" target="_blank" rel="noopener">Abrir edición</a>
            </div>
          </div>

          <?php if ($view['imgA'] === '' && $view['imgB'] === ''): ?>
            <div class="mr-empty">No se pudo resolver la imagen de origen del recorte.</div>
          <?php elseif ($modo === 'recorte'): ?>
            <div class="mr-section">              
              <div class="mr-render-shell">
                <div class="mr-render-stage js-render-stage">
                  <img
                    src="api/recorte_render.php?recorte=<?= $recorteId ?>&modo=crop&maxw=1800"
                    alt="Recorte #<?= $recorteId ?>"
                    class="js-render-img"
                  >
                </div>
              </div>
              <div class="mr-muted">Rueda del mouse para zoom. Arrastrá con click izquierdo para mover.</div>
            </div>
          <?php elseif ($modo === 'contexto'): ?>
            <div class="mr-section">
              <h3>Contexto</h3>
              <div class="mr-render-box">
                <img
                  src="api/recorte_render.php?recorte=<?= $recorteId ?>&modo=focus&maxw=2200"
                  alt="Contexto del recorte #<?= $recorteId ?>"
                >
              </div>
              <div class="mr-muted">El área del recorte queda resaltada dentro de la página.</div>
            </div>
          <?php else: ?>
            <div class="mr-section">
              <h3>Ajustar recorte</h3>
              <div class="mr-stage-shell">
                <div
                  class="mr-stage js-stage-adjust"
                  data-mode="<?= mr_h($view['mode']) ?>"
                  data-x="<?= mr_h((string)$view['x']) ?>"
                  data-y="<?= mr_h((string)$view['y']) ?>"
                  data-w="<?= mr_h((string)$view['w']) ?>"
                  data-h="<?= mr_h((string)$view['h']) ?>"
                  data-recorte-id="<?= $recorteId ?>"
                  data-fecha="<?= mr_h((string)($recorteSel['fechalso'] ?? '')) ?>"
                  data-ed="<?= mr_h((string)($recorteSel['ed'] ?? '')) ?>"
                  data-tipo="<?= mr_h((string)($recorteSel['tipo'] ?? '')) ?>"
                  data-barcode="<?= mr_h((string)($recorteSel['barcode'] ?? '')) ?>"
                  data-barcode-izq="<?= mr_h((string)($recorteSel['barcode_izq'] ?? '')) ?>"
                  data-barcode-der="<?= mr_h((string)($recorteSel['barcode_der'] ?? '')) ?>"
                  data-pag-izq="<?= (int)($recorteSel['pag_izq'] ?? 0) ?>"
                  data-pag-der="<?= (int)($recorteSel['pag_der'] ?? 0) ?>"
                  data-recortadode="<?= mr_h((string)($recorteSel['recortadoDe'] ?? '')) ?>"
                >
                  <div class="mr-spread js-spread">
                    <?php if ($view['imgA'] !== ''): ?>
                      <img src="<?= mr_h($view['imgA']) ?>" alt="Página A" class="js-img-a">
                    <?php endif; ?>
                    <?php if ($view['mode'] === 'double' && $view['imgB'] !== ''): ?>
                      <img src="<?= mr_h($view['imgB']) ?>" alt="Página B" class="js-img-b">
                    <?php endif; ?>
                    <div class="mr-edit-rect js-edit-rect">
                      <div class="handle nw" data-handle="nw"></div>
                      <div class="handle ne" data-handle="ne"></div>
                      <div class="handle sw" data-handle="sw"></div>
                      <div class="handle se" data-handle="se"></div>
                      <div class="handle n" data-handle="n"></div>
                      <div class="handle s" data-handle="s"></div>
                      <div class="handle w" data-handle="w"></div>
                      <div class="handle e" data-handle="e"></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="mr-muted">Arrastrá el rectángulo o sus bordes. La rueda hace zoom sobre el contexto.</div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
(function(){
  function waitForImages(images, cb){
    var pending = images.length;
    if (!pending) { cb(); return; }

    function done(){
      pending--;
      if (pending <= 0) cb();
    }

    images.forEach(function(img){
      if (img.complete && img.naturalWidth > 0) {
        done();
      } else {
        img.addEventListener('load', done, {once:true});
        img.addEventListener('error', done, {once:true});
      }
    });
  }

  function setDynamicStageHeight(){
    document.querySelectorAll('.mr-stage').forEach(function(stage){
      var top = stage.getBoundingClientRect().top;
      var available = window.innerHeight - top - 48;
      var height = Math.max(320, available);
      height = Math.min(680, height);
      stage.style.height = height + 'px';
    });
  }

  function layoutSpread(spread, mode){
    var imgA = spread.querySelector('.js-img-a');
    var imgB = spread.querySelector('.js-img-b');

    var w1 = imgA ? (imgA.naturalWidth || 0) : 0;
    var h1 = imgA ? (imgA.naturalHeight || 0) : 0;
    var w2 = imgB ? (imgB.naturalWidth || 0) : 0;
    var h2 = imgB ? (imgB.naturalHeight || 0) : 0;

    if (mode === 'double' && imgA && imgB) {
      var spreadW = w1 + w2;
      var spreadH = Math.max(h1, h2);

      spread.style.width = spreadW + 'px';
      spread.style.height = spreadH + 'px';

      imgA.style.left = '0px';
      imgA.style.top = '0px';
      imgA.style.width = w1 + 'px';
      imgA.style.height = h1 + 'px';

      imgB.style.left = w1 + 'px';
      imgB.style.top = '0px';
      imgB.style.width = w2 + 'px';
      imgB.style.height = h2 + 'px';

      return {w: spreadW, h: spreadH};
    }

    if (imgA) {
      spread.style.width = w1 + 'px';
      spread.style.height = h1 + 'px';

      imgA.style.left = '0px';
      imgA.style.top = '0px';
      imgA.style.width = w1 + 'px';
      imgA.style.height = h1 + 'px';

      return {w: w1, h: h1};
    }

    return {w: 0, h: 0};
  }

  function clampTranslationContext(containerW, containerH, scaledW, scaledH, tx, ty){
    if (scaledW <= containerW) {
      tx = (containerW - scaledW) / 2;
    } else {
      var minTx = containerW - scaledW;
      var maxTx = 0;
      tx = Math.max(minTx, Math.min(maxTx, tx));
    }

    if (scaledH <= containerH) {
      ty = (containerH - scaledH) / 2;
    } else {
      var minTy = containerH - scaledH;
      var maxTy = 0;
      ty = Math.max(minTy, Math.min(maxTy, ty));
    }

    return {tx: tx, ty: ty};
  }

  function makeStageBase(stage){
    var spread = stage.querySelector('.js-spread');
    if (!spread) return null;

    var imgs = Array.prototype.slice.call(spread.querySelectorAll('img'));
    var mode = stage.dataset.mode || 'single';

    var state = {
      dimsW: 0,
      dimsH: 0,
      scale: 1,
      tx: 0,
      ty: 0,
      dragging: false,
      startX: 0,
      startY: 0,
      startTx: 0,
      startTy: 0
    };

    function render(){
      spread.style.transform = 'translate(' + state.tx + 'px,' + state.ty + 'px) scale(' + state.scale + ')';
    }

    return {
      stage: stage,
      spread: spread,
      imgs: imgs,
      mode: mode,
      state: state,
      layout: function(){
        var dims = layoutSpread(spread, mode);
        state.dimsW = dims.w;
        state.dimsH = dims.h;
        return dims;
      },
      render: render
    };
  }

  function initCropStage(stage){
    var base = makeStageBase(stage);
    if (!base) return;

    var x = parseFloat(stage.dataset.x || '0');
    var y = parseFloat(stage.dataset.y || '0');
    var w = parseFloat(stage.dataset.w || '0');
    var h = parseFloat(stage.dataset.h || '0');

    function fitCrop(){
      var dims = base.layout();
      if (dims.w <= 0 || dims.h <= 0 || !(w > 0) || !(h > 0)) return;

      var cw = stage.clientWidth;
      var ch = stage.clientHeight;
      if (cw <= 0 || ch <= 0) return;

      var cropW = dims.w * w;
      var cropH = dims.h * h;

      if (cropW <= 0 || cropH <= 0) return;

      // escala para que el recorte llene el visor
      base.state.scale = Math.max(cw / cropW, ch / cropH);

      var scaledW = dims.w * base.state.scale;
      var scaledH = dims.h * base.state.scale;

      var cropLeft = dims.w * x * base.state.scale;
      var cropTop  = dims.h * y * base.state.scale;

      // mover imagen para que el recorte quede visible
      base.state.tx = -cropLeft + (cw - cropW * base.state.scale) / 2;
      base.state.ty = -cropTop  + (ch - cropH * base.state.scale) / 2;

      base.render();
    }

    function zoomAt(clientX, clientY, factor){
      var rect = stage.getBoundingClientRect();
      var px = clientX - rect.left;
      var py = clientY - rect.top;

      var oldScale = base.state.scale;
      var newScale = Math.max(0.05, Math.min(20, oldScale * factor));
      if (newScale === oldScale) return;

      var imgX = (px - base.state.tx) / oldScale;
      var imgY = (py - base.state.ty) / oldScale;

      base.state.scale = newScale;
      base.state.tx = px - imgX * newScale;
      base.state.ty = py - imgY * newScale;

      base.render();
    }

    waitForImages(base.imgs, function(){
      fitCrop();
    });

    stage.addEventListener('wheel', function(e){
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.12 : (1 / 1.12));
    }, { passive:false });

    stage.addEventListener('mousedown', function(e){
      if (e.button !== 0) return;
      base.state.dragging = true;
      stage.classList.add('is-dragging');
      base.state.startX = e.clientX;
      base.state.startY = e.clientY;
      base.state.startTx = base.state.tx;
      base.state.startTy = base.state.ty;
      e.preventDefault();
    });

    window.addEventListener('mousemove', function(e){
      if (!base.state.dragging) return;
      base.state.tx = base.state.startTx + (e.clientX - base.state.startX);
      base.state.ty = base.state.startTy + (e.clientY - base.state.startY);
      base.render();
    });

    window.addEventListener('mouseup', function(){
      if (!base.state.dragging) return;
      base.state.dragging = false;
      stage.classList.remove('is-dragging');
    });

    window.addEventListener('resize', fitCrop);
  }

  function initContextStage(stage){
    var base = makeStageBase(stage);
    if (!base) return;

    var overlay = stage.querySelector('.js-overlay');
    if (!overlay) return;

    var x = parseFloat(stage.dataset.x || '0');
    var y = parseFloat(stage.dataset.y || '0');
    var w = parseFloat(stage.dataset.w || '0');
    var h = parseFloat(stage.dataset.h || '0');

    function fitAll(){
      var dims = base.layout();
      if (dims.w <= 0 || dims.h <= 0) return;

      var cw = stage.clientWidth;
      var ch = stage.clientHeight;
      if (cw <= 0 || ch <= 0) return;

      base.state.scale = Math.min(cw / dims.w, ch / dims.h);
      var scaledW = dims.w * base.state.scale;
      var scaledH = dims.h * base.state.scale;
      base.state.tx = (cw - scaledW) / 2;
      base.state.ty = (ch - scaledH) / 2;

      overlay.style.left = (dims.w * x) + 'px';
      overlay.style.top = (dims.h * y) + 'px';
      overlay.style.width = (dims.w * w) + 'px';
      overlay.style.height = (dims.h * h) + 'px';

      base.render();
    }

    waitForImages(base.imgs, fitAll);
    window.addEventListener('resize', fitAll);
  }

  function initAdjustStage(stage){
    var base = makeStageBase(stage);
    if (!base) return;

    var rectEl = stage.querySelector('.js-edit-rect');
    if (!rectEl) return;

    var rect = {
      x: parseFloat(stage.dataset.x || '0'),
      y: parseFloat(stage.dataset.y || '0'),
      w: parseFloat(stage.dataset.w || '0'),
      h: parseFloat(stage.dataset.h || '0')
    };

    var drag = {
      active: false,
      mode: null,
      handle: null,
      startClientX: 0,
      startClientY: 0,
      startRect: null
    };

    function clampRect(){
      if (rect.w < 0.02) rect.w = 0.02;
      if (rect.h < 0.02) rect.h = 0.02;
      if (rect.x < 0) rect.x = 0;
      if (rect.y < 0) rect.y = 0;
      if (rect.x + rect.w > 1) rect.x = 1 - rect.w;
      if (rect.y + rect.h > 1) rect.y = 1 - rect.h;
      if (rect.x < 0) rect.x = 0;
      if (rect.y < 0) rect.y = 0;
    }

    function syncDataset(){
      stage.dataset.x = String(rect.x);
      stage.dataset.y = String(rect.y);
      stage.dataset.w = String(rect.w);
      stage.dataset.h = String(rect.h);
    }

    function renderRect(){
      rectEl.style.left = (base.state.dimsW * rect.x) + 'px';
      rectEl.style.top = (base.state.dimsH * rect.y) + 'px';
      rectEl.style.width = (base.state.dimsW * rect.w) + 'px';
      rectEl.style.height = (base.state.dimsH * rect.h) + 'px';
    }

    function fitAll(){
      var dims = base.layout();
      if (dims.w <= 0 || dims.h <= 0) return;

      var cw = stage.clientWidth;
      var ch = stage.clientHeight;
      if (cw <= 0 || ch <= 0) return;

      base.state.scale = Math.min(cw / dims.w, ch / dims.h);
      var scaledW = dims.w * base.state.scale;
      var scaledH = dims.h * base.state.scale;
      base.state.tx = (cw - scaledW) / 2;
      base.state.ty = (ch - scaledH) / 2;

      renderRect();
      base.render();
    }

    function zoomAt(clientX, clientY, factor){
      var rectStage = stage.getBoundingClientRect();
      var px = clientX - rectStage.left;
      var py = clientY - rectStage.top;

      var oldScale = base.state.scale;
      var newScale = Math.max(0.05, Math.min(20, oldScale * factor));
      if (newScale === oldScale) return;

      var imgX = (px - base.state.tx) / oldScale;
      var imgY = (py - base.state.ty) / oldScale;

      base.state.scale = newScale;
      base.state.tx = px - imgX * newScale;
      base.state.ty = py - imgY * newScale;

      base.render();
    }

    waitForImages(base.imgs, function(){
      fitAll();
      syncDataset();
    });

    stage.addEventListener('wheel', function(e){
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.12 : (1 / 1.12));
    }, { passive:false });

    stage.addEventListener('mousedown', function(e){
      if (e.button !== 0) return;

      var handle = e.target.closest('[data-handle]');
      var isRect = e.target === rectEl;

      if (handle || isRect) {
        drag.active = true;
        drag.mode = handle ? 'resize' : 'move';
        drag.handle = handle ? handle.getAttribute('data-handle') : null;
        drag.startClientX = e.clientX;
        drag.startClientY = e.clientY;
        drag.startRect = {x:rect.x, y:rect.y, w:rect.w, h:rect.h};
        stage.classList.add('is-dragging');
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      base.state.dragging = true;
      stage.classList.add('is-dragging');
      base.state.startX = e.clientX;
      base.state.startY = e.clientY;
      base.state.startTx = base.state.tx;
      base.state.startTy = base.state.ty;
      e.preventDefault();
    });

    window.addEventListener('mousemove', function(e){
      if (drag.active) {
        var scale = base.state.scale;
        if (scale <= 0 || base.state.dimsW <= 0 || base.state.dimsH <= 0) return;

        var dxNorm = (e.clientX - drag.startClientX) / (base.state.dimsW * scale);
        var dyNorm = (e.clientY - drag.startClientY) / (base.state.dimsH * scale);
        var sr = drag.startRect;
        if (!sr) return;

        rect.x = sr.x;
        rect.y = sr.y;
        rect.w = sr.w;
        rect.h = sr.h;

        if (drag.mode === 'move') {
          rect.x = sr.x + dxNorm;
          rect.y = sr.y + dyNorm;
        } else {
          switch (drag.handle) {
            case 'nw':
              rect.x = sr.x + dxNorm;
              rect.y = sr.y + dyNorm;
              rect.w = sr.w - dxNorm;
              rect.h = sr.h - dyNorm;
              break;
            case 'ne':
              rect.y = sr.y + dyNorm;
              rect.w = sr.w + dxNorm;
              rect.h = sr.h - dyNorm;
              break;
            case 'sw':
              rect.x = sr.x + dxNorm;
              rect.w = sr.w - dxNorm;
              rect.h = sr.h + dyNorm;
              break;
            case 'se':
              rect.w = sr.w + dxNorm;
              rect.h = sr.h + dyNorm;
              break;
            case 'n':
              rect.y = sr.y + dyNorm;
              rect.h = sr.h - dyNorm;
              break;
            case 's':
              rect.h = sr.h + dyNorm;
              break;
            case 'w':
              rect.x = sr.x + dxNorm;
              rect.w = sr.w - dxNorm;
              break;
            case 'e':
              rect.w = sr.w + dxNorm;
              break;
          }
        }

        clampRect();
        syncDataset();
        renderRect();
        return;
      }

      if (!base.state.dragging) return;
      base.state.tx = base.state.startTx + (e.clientX - base.state.startX);
      base.state.ty = base.state.startTy + (e.clientY - base.state.startY);
      base.render();
    });

    window.addEventListener('mouseup', function(){
      if (drag.active) {
        drag.active = false;
        drag.mode = null;
        drag.handle = null;
        drag.startRect = null;
        stage.classList.remove('is-dragging');
      }
      if (base.state.dragging) {
        base.state.dragging = false;
        stage.classList.remove('is-dragging');
      }
    });

    window.addEventListener('resize', fitAll);

    var saveBtn = document.querySelector('.js-save-adjust[data-recorte-id="' + stage.dataset.recorteId + '"]');
    if (saveBtn) {
      saveBtn.addEventListener('click', function(){
        saveBtn.disabled = true;
        saveBtn.textContent = 'Guardando...';

        var payload = {
          recorte_id: parseInt(stage.dataset.recorteId || '0', 10),
          barcode: stage.dataset.barcode || '',
          barcode_izq: stage.dataset.barcodeIzq || '',
          barcode_der: stage.dataset.barcodeDer || '',
          pag_izq: parseInt(stage.dataset.pagIzq || '0', 10),
          pag_der: parseInt(stage.dataset.pagDer || '0', 10),
          fechaIso: stage.dataset.fecha || '',
          ed: stage.dataset.ed || '',
          tipo: stage.dataset.tipo || '',
          recortadoDe: stage.dataset.recortadode || '',
          xval: parseFloat(stage.dataset.x || '0'),
          yval: parseFloat(stage.dataset.y || '0'),
          ancho: parseFloat(stage.dataset.w || '0'),
          alto: parseFloat(stage.dataset.h || '0')
        };

        fetch('api/recortes.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json(); })
        .then(function(json){
          if (!json || !json.ok) {
            throw new Error((json && json.msg) ? json.msg : 'Error al guardar');
          }
          window.location.href = 'misrecortes.php?recorte=' + payload.recorte_id;
        })
        .catch(function(err){
          alert(err.message || 'No se pudo guardar el ajuste');
          saveBtn.disabled = false;
          saveBtn.textContent = 'Guardar ajuste';
        });
      });
    }
  }
    function initRenderedImageStage(stage){
    var img = stage.querySelector('.js-render-img');
    if (!img) return;

    var state = {
      scale: 1,
      minScale: 1,
      tx: 0,
      ty: 0,
      dragging: false,
      startX: 0,
      startY: 0,
      startTx: 0,
      startTy: 0,
      imgW: 0,
      imgH: 0
    };

    function clamp(){
      var cw = stage.clientWidth;
      var ch = stage.clientHeight;
      var scaledW = state.imgW * state.scale;
      var scaledH = state.imgH * state.scale;

      if (scaledW <= cw) {
        state.tx = (cw - scaledW) / 2;
      } else {
        var minTx = cw - scaledW;
        var maxTx = 0;
        state.tx = Math.max(minTx, Math.min(maxTx, state.tx));
      }

      if (scaledH <= ch) {
        state.ty = (ch - scaledH) / 2;
      } else {
        var minTy = ch - scaledH;
        var maxTy = 0;
        state.ty = Math.max(minTy, Math.min(maxTy, state.ty));
      }
    }

    function render(){
      img.style.transform = 'translate(' + state.tx + 'px,' + state.ty + 'px) scale(' + state.scale + ')';
    }

    function fit(){
      state.imgW = img.naturalWidth || 0;
      state.imgH = img.naturalHeight || 0;
      if (state.imgW <= 0 || state.imgH <= 0) return;

      var cw = stage.clientWidth;
      var ch = stage.clientHeight;
      if (cw <= 0 || ch <= 0) return;

      state.minScale = Math.min(cw / state.imgW, ch / state.imgH);
      state.scale = state.minScale;
      state.tx = (cw - state.imgW * state.scale) / 2;
      state.ty = (ch - state.imgH * state.scale) / 2;

      clamp();
      render();
    }

    function zoomAt(clientX, clientY, factor){
      var rect = stage.getBoundingClientRect();
      var px = clientX - rect.left;
      var py = clientY - rect.top;

      var oldScale = state.scale;
      var newScale = Math.max(state.minScale, Math.min(20, oldScale * factor));
      if (newScale === oldScale) return;

      var imgX = (px - state.tx) / oldScale;
      var imgY = (py - state.ty) / oldScale;

      state.scale = newScale;
      state.tx = px - imgX * newScale;
      state.ty = py - imgY * newScale;

      clamp();
      render();
    }

    if (img.complete && img.naturalWidth > 0) {
      fit();
    } else {
      img.addEventListener('load', fit, {once:true});
    }

    stage.addEventListener('wheel', function(e){
      e.preventDefault();
      zoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.12 : (1 / 1.12));
    }, { passive:false });

    stage.addEventListener('mousedown', function(e){
      if (e.button !== 0) return;
      state.dragging = true;
      stage.classList.add('is-dragging');
      state.startX = e.clientX;
      state.startY = e.clientY;
      state.startTx = state.tx;
      state.startTy = state.ty;
      e.preventDefault();
    });

    window.addEventListener('mousemove', function(e){
      if (!state.dragging) return;
      state.tx = state.startTx + (e.clientX - state.startX);
      state.ty = state.startTy + (e.clientY - state.startY);
      clamp();
      render();
    });

    window.addEventListener('mouseup', function(){
      if (!state.dragging) return;
      state.dragging = false;
      stage.classList.remove('is-dragging');
    });

    window.addEventListener('resize', fit);
  }
  setDynamicStageHeight();
  document.querySelectorAll('.js-stage-crop').forEach(initCropStage);
  document.querySelectorAll('.js-stage-context').forEach(initContextStage);
  document.querySelectorAll('.js-stage-adjust').forEach(initAdjustStage);
  document.querySelectorAll('.js-render-stage').forEach(initRenderedImageStage);
  window.addEventListener('resize', function(){
    setDynamicStageHeight();
  });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>