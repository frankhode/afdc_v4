<?php

declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
if (file_exists(__DIR__ . '/inc/auth_v2.php')) require __DIR__ . '/inc/auth_v2.php';
if (function_exists('afdc_v2_require_admin')) afdc_v2_require_admin();
require __DIR__ . '/inc/exposiciones_helpers_v2.php';

$expoId = (int)($_GET['id'] ?? $_POST['expo_id'] ?? 0);
if ($expoId <= 0) {
    http_response_code(400);
    echo 'Falta id de exposición.';
    exit;
}

$flash = '';
$activePieceId = (int)($_GET['piece_id'] ?? $_POST['piece_id'] ?? 0);
$csrf = function_exists('afdc_v2_csrf_token') ? afdc_v2_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('afdc_v2_csrf_token')) {
        $postedCsrf = (string)($_POST['csrf'] ?? '');
        if ($postedCsrf === '' || !hash_equals(afdc_v2_csrf_token(), $postedCsrf)) {
            throw new RuntimeException('CSRF inválido.');
        }
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_meta') {
        expo_save_meta($expoId, $_POST);
        $flash = 'Portada actualizada.';
    }

    if ($action === 'import_collection') {
        $inserted = expo_import_collection(
            $expoId,
            (int)($_POST['source_collection_id'] ?? 0),
            !empty($_POST['append_existing'])
        );
        $flash = $inserted > 0
            ? "Se incorporaron {$inserted} pieza(s) nuevas."
            : 'No había novedades para incorporar.';
    }

    if ($action === 'save_piece') {
        $pieceId = (int)($_POST['piece_id'] ?? 0);
        if ($pieceId > 0) {
            expo_save_piece($pieceId, $_POST);
            $activePieceId = $pieceId;
            $flash = 'Pieza guardada.';
        }
    }

    if ($action === 'bulk_delete') {
        $deleted = expo_delete_pieces($expoId, (array)($_POST['piece_ids'] ?? []));
        expo_resequence_pieces($expoId);
        $flash = "Se descartaron {$deleted} pieza(s).";
        $activePieceId = 0;
    }

    if ($action === 'set_hero') {
        $pieceId = (int)($_POST['piece_id'] ?? 0);
        if ($pieceId > 0) {
            expo_set_hero_by_piece($expoId, $pieceId);
            $activePieceId = $pieceId;
            $flash = 'Hero actualizado.';
        }
    }

    if ($action === 'reorder') {
        $order = json_decode((string)($_POST['ordered_ids_json'] ?? '[]'), true);
        if (is_array($order)) {
            expo_reorder_pieces($expoId, $order);
            expo_resequence_pieces($expoId);
            $flash = 'Orden actualizado.';
        }
    }

    header('Location: expo_editar_v2.php?id=' . $expoId . ($activePieceId > 0 ? '&piece_id=' . $activePieceId : '') . '&ok=' . rawurlencode($flash));
    exit;
}

$expo = expo_get($expoId);
if (!$expo) {
    http_response_code(404);
    echo 'No existe la exposición.';
    exit;
}

$collections = expo_get_collections();
$pieces = expo_get_pieces($expoId, true);
if ($activePieceId <= 0 && !empty($pieces)) $activePieceId = (int)$pieces[0]['id'];
$activePiece = $activePieceId > 0 ? expo_get_piece($activePieceId) : null;
$activeFlash = trim((string)($_GET['ok'] ?? ''));
$template = expo_normalize_template((string)($expo['template_name'] ?? 'hero_horizontal'));
$templateDescriptions = expo_template_descriptions();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Editar exposición - <?= expo_h((string)$expo['title']) ?></title>
  <link rel="stylesheet" href="css/exposiciones_v2.css">
  <style>
    :root { --expo-thumb-size: 180px; }
    .thumb-size-toolbar { display:flex; align-items:center; gap:12px; margin:12px 0 18px; flex-wrap:wrap; }
    .thumb-size-toolbar label { font-weight:600; }
    .thumb-size-toolbar input[type="range"] { width:220px; }
    .thumb-size-toolbar span { opacity:.85; min-width:56px; }
    .pieces-layout { display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:20px; align-items:start; }
    body.piece-editor-closed .pieces-layout { grid-template-columns:minmax(0,1fr); }
    body.piece-editor-closed .piece-editor { display:none; }
    @media (max-width:1200px){ .pieces-layout{grid-template-columns:1fr;} .piece-editor{display:block !important;} }
    .bulk-toolbar { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
    .toggle-inline { display:inline-flex; align-items:center; gap:8px; cursor:pointer; user-select:none; }
    .piece-editor-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .piece-editor-close { line-height:1; padding:8px 12px; }
    #expoPiecesGrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(var(--expo-thumb-size), 1fr)); gap:16px; }
    #expoPiecesGrid .piece-card { width:100%; }
    .piece-thumb-link { display:block; margin-bottom:10px; }
    .expo-piece-thumb { width:100%; height:var(--expo-thumb-size); object-fit:cover; display:block; border-radius:12px; }
    .thumb-fallback { width:100%; height:var(--expo-thumb-size); display:flex; align-items:center; justify-content:center; border-radius:12px; background:rgba(255,255,255,.06); color:rgba(255,255,255,.7); font-size:14px; }
    body.piece-details-hidden .piece-meta-compact { display:none; }
    body.piece-details-hidden .piece-card { padding-bottom:10px; min-height:auto; }
    body.piece-details-hidden .piece-thumb-link { margin-bottom:0; }
    body.piece-details-hidden .piece-card .badge,
    body.piece-details-hidden .piece-card .piece-order,
    body.piece-details-hidden .piece-card .piece-ref { display:none; }
    body.piece-details-hidden #expoPiecesGrid { gap:12px; }
    body.piece-details-hidden .piece-card { padding:10px; }
    body.piece-details-hidden .piece-select { top:8px; left:8px; }
    .piece-card.is-active { outline:2px solid rgba(100,149,237,.85); outline-offset:0; }
    .import-note { margin-top:10px; font-size:13px; opacity:.8; }
    .template-help { font-size:13px; line-height:1.4; color:#c9d4e6; margin-top:8px; }
    .template-control { display:flex; flex-direction:column; gap:4px; }
    .hero-conditional.is-hidden { display:none; }
    .hero-preview-chip { display:inline-flex; padding:6px 10px; border-radius:999px; background:#0a1220; border:1px solid #2f4160; color:#d8e4f8; font-size:.82rem; }
  </style>
</head>
<body class="expo-admin-body piece-details-hidden piece-editor-closed">
  <div class="expo-admin-wrap">
    <header class="expo-admin-topbar">
      <div>
        <div class="expo-kicker-top">AFDC · EXPOSICIONES</div>
        <h1><?= expo_h((string)$expo['title']) ?></h1>
        <p class="expo-admin-sub">Backoffice editorial. Acá definís portada, hero y piezas de la muestra.</p>
      </div>
      <div class="expo-top-actions">
        <a class="btn btn-secondary" href="exposiciones.php">← Volver</a>
        <a class="btn btn-secondary" href="expo_ver.php?id=<?= (int)$expoId ?>" target="_blank">Ver</a>
        <a class="btn btn-primary" href="export_portable.php?expo_id=<?= (int)$expoId ?>">Exportar</a>
      </div>
    </header>

    <?php if ($activeFlash !== ''): ?>
      <div class="expo-flash"><?= expo_h($activeFlash) ?></div>
    <?php endif; ?>

    <section class="panel panel-meta">
      <h2>Portada / identidad</h2>
      <form method="post" class="meta-grid" id="expo-meta-form">
        <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
        <input type="hidden" name="action" value="save_meta">
        <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">

        <label>Título
          <input type="text" name="title" value="<?= expo_h((string)$expo['title']) ?>">
        </label>
        <label>Slug
          <input type="text" name="slug" value="<?= expo_h((string)$expo['slug']) ?>">
        </label>
        <label>Kicker / categoría
          <input type="text" name="kicker" value="<?= expo_h((string)($expo['kicker'] ?? '')) ?>">
        </label>
        <label>Subtítulo
          <input type="text" name="subtitle" value="<?= expo_h((string)($expo['subtitle'] ?? '')) ?>">
        </label>
        <label class="template-control">Plantilla pública
          <select name="template_name" id="template_name">
            <?php foreach (expo_template_options() as $key => $label): ?>
              <option value="<?= expo_h($key) ?>" <?= ($template === expo_normalize_template($key)) ? 'selected' : '' ?>><?= expo_h($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="template-help" id="template_help"><?= expo_h($templateDescriptions[$template] ?? '') ?></div>
        </label>
        <label>Estado
          <select name="status">
            <option value="draft" <?= ((string)($expo['status'] ?? '') === 'draft') ? 'selected' : '' ?>>Borrador</option>
            <option value="published" <?= ((string)($expo['status'] ?? '') === 'published') ? 'selected' : '' ?>>Publicado</option>
          </select>
        </label>
        <label>Colección origen
          <select name="source_collection_id">
            <option value="0">Seleccionar...</option>
            <?php foreach ($collections as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)($expo['source_collection_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>#<?= (int)$c['id'] ?> · <?= expo_h((string)$c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Hero tipo
          <select name="hero_type">
            <option value="imagen" <?= ((string)($expo['hero_type'] ?? '') === 'imagen') ? 'selected' : '' ?>>Imagen</option>
            <option value="recorte_impreso" <?= ((string)($expo['hero_type'] ?? '') === 'recorte_impreso') ? 'selected' : '' ?>>Recorte impreso</option>
          </select>
        </label>
        <label>Hero ref_id
          <input type="text" name="hero_ref_id" value="<?= expo_h((string)($expo['hero_ref_id'] ?? '')) ?>">
        </label>
        <label>Posición X
          <input type="text" name="hero_pos_x" value="<?= expo_h((string)($expo['hero_pos_x'] ?? '50%')) ?>">
        </label>
        <label>Posición Y
          <input type="text" name="hero_pos_y" value="<?= expo_h((string)($expo['hero_pos_y'] ?? '50%')) ?>">
        </label>

        <label class="hero-conditional hero-horizontal-only<?= $template === 'hero_vertical_split' ? ' is-hidden' : '' ?>">Altura hero horizontal (px)
          <input type="number" name="hero_height_px" value="<?= (int)($expo['hero_height_px'] ?? 520) ?>" min="220" max="1200">
        </label>

        <label class="hero-conditional hero-vertical-only<?= $template === 'hero_vertical_split' ? '' : ' is-hidden' ?>">Ancho hero vertical (%)
          <input type="number" name="hero_width_pct" value="<?= (int)($expo['hero_width_pct'] ?? 38) ?>" min="22" max="65">
        </label>

        <label>Overlay
          <input type="text" name="hero_overlay_opacity" value="<?= expo_h((string)($expo['hero_overlay_opacity'] ?? '0.35')) ?>">
        </label>
        <label>CTA label
          <input type="text" name="cta_label" value="<?= expo_h((string)($expo['cta_label'] ?? 'Explorar colección')) ?>">
        </label>
        <label>CTA target
          <input type="text" name="cta_target" value="<?= expo_h((string)($expo['cta_target'] ?? 'viewer.html')) ?>">
        </label>
        <label class="full">Intro HTML
          <textarea name="intro_html" rows="4"><?= expo_h((string)($expo['intro_html'] ?? '')) ?></textarea>
        </label>
        <div class="full actions-row">
          <span class="hero-preview-chip">Horizontal: controlás altura. Vertical split: controlás ancho.</span>
        </div>
        <div class="full actions-row">
          <button class="btn btn-primary" type="submit">Guardar portada</button>
        </div>
      </form>
    </section>

    <section class="panel panel-import">
      <h2>Importar piezas desde colección</h2>
      <form method="post" class="import-grid">
        <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
        <input type="hidden" name="action" value="import_collection">
        <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">
        <select name="source_collection_id" required>
          <option value="">Seleccionar...</option>
          <?php foreach ($collections as $c): ?>
            <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> · <?= expo_h((string)$c['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="check-inline"><input type="checkbox" name="append_existing" value="1" checked> Incorporar solo novedades</label>
        <button class="btn btn-secondary" type="submit">Importar</button>
      </form>
      <p class="import-note">Con esta lógica, la expo recuerda lo descartado: las imágenes que sacaste no vuelven a entrar al importar novedades.</p>
    </section>

    <section class="panel panel-pieces">
      <div class="pieces-head">
        <div>
          <h2>Piezas de la exposición</h2>
          <p class="pieces-help">Curaduría masiva: seleccioná varias para descartarlas o arrastrá para reordenar.</p>
          <div class="thumb-size-toolbar">
            <label for="thumbSizeRange">Tamaño de miniaturas</label>
            <input type="range" id="thumbSizeRange" min="90" max="320" step="10" value="180">
            <span id="thumbSizeValue">180px</span>
          </div>
        </div>
        <div class="pieces-count"><?= count($pieces) ?> pieza(s)</div>
      </div>

      <div class="pieces-layout">
        <div class="pieces-main">
          <form method="post" id="bulk-form" class="bulk-toolbar" onsubmit="return confirm('¿Descartar las piezas seleccionadas? No volverán a importarse como novedades.');">
            <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
            <input type="hidden" name="action" value="bulk_delete">
            <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">
            <button type="submit" class="btn btn-danger" id="bulk-delete-btn" disabled>Descartar seleccionadas</button>
            <span class="bulk-count" id="bulk-count">0 seleccionadas</span>
            <button type="button" class="btn btn-secondary" id="togglePieceEditorBtn" aria-expanded="false">Mostrar editor</button>
            <label class="toggle-inline">
              <input type="checkbox" id="togglePieceDetails">
              <span>Ver detalles</span>
            </label>
          </form>

          <form method="post" id="reorder-form">
            <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
            <input type="hidden" name="action" value="reorder">
            <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">
            <input type="hidden" name="ordered_ids_json" id="ordered_ids_json" value="[]">
          </form>

          <div class="pieces-grid" id="expoPiecesGrid">
            <?php foreach ($pieces as $piece): ?>
              <?php $pid = (int)$piece['id']; ?>
              <article class="piece-card <?= !empty($piece['is_hero']) ? 'is-hero' : '' ?> <?= !empty($piece['is_hidden']) ? 'is-hidden' : '' ?> <?= ($activePieceId === $pid) ? 'is-active' : '' ?>" data-piece-id="<?= $pid ?>" draggable="true">
                <label class="piece-select">
                  <input type="checkbox" form="bulk-form" name="piece_ids[]" value="<?= $pid ?>" class="piece-checkbox">
                </label>
                <a class="piece-thumb-link expo-piece-open" href="expo_editar_v2.php?id=<?= (int)$expoId ?>&piece_id=<?= $pid ?>#piece-editor">
                  <?php if (!empty($piece['thumb_url'])): ?>
                    <img class="expo-piece-thumb" src="<?= expo_h($piece['thumb_url']) ?>" alt="<?= expo_h((string)$piece['ref_id']) ?>">
                  <?php else: ?>
                    <div class="thumb-fallback">Sin imagen</div>
                  <?php endif; ?>
                </a>
                <div class="piece-meta-compact">
                  <div class="piece-badges">
                    <span class="badge">#<?= $pid ?></span>
                    <span class="badge"><?= expo_h((string)$piece['piece_type']) ?></span>
                    <?php if (!empty($piece['is_hero'])): ?><span class="badge badge-hero">Hero</span><?php endif; ?>
                    <?php if (!empty($piece['is_hidden'])): ?><span class="badge badge-hidden">Oculta</span><?php endif; ?>
                  </div>
                  <div class="piece-ref"><?= expo_h((string)$piece['ref_id']) ?></div>
                  <div class="piece-order">Orden <?= (int)($piece['sort_order'] ?? 0) ?></div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>

          <div class="reorder-actions">
            <button class="btn btn-secondary" type="button" id="save-order-btn">Guardar orden arrastrado</button>
          </div>
        </div>

        <aside class="piece-editor panel-sub" id="piece-editor">
          <div class="piece-editor-head">
            <h3>Edición puntual</h3>
            <button type="button" class="btn btn-secondary piece-editor-close" id="closePieceEditorBtn">×</button>
          </div>
          <?php if ($activePiece): ?>
            <div class="piece-editor-thumb-wrap">
              <?php if (!empty($activePiece['thumb_url'])): ?>
                <img class="piece-editor-thumb" src="<?= expo_h((string)$activePiece['thumb_url']) ?>" alt="thumb grande">
              <?php endif; ?>
              <div class="piece-editor-meta">
                <div class="piece-editor-ref">ref: <?= expo_h((string)$activePiece['ref_id']) ?></div>
                <?php if ((string)($expo['hero_ref_id'] ?? '') === (string)$activePiece['ref_id']): ?>
                  <div class="hero-chip">Hero actual</div>
                <?php endif; ?>
              </div>
            </div>

            <form method="post" class="piece-form">
              <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
              <input type="hidden" name="action" value="save_piece">
              <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">
              <input type="hidden" name="piece_id" value="<?= (int)$activePiece['id'] ?>">

              <label>Título
                <input type="text" name="title" value="<?= expo_h((string)($activePiece['title'] ?? '')) ?>">
              </label>
              <label>Subtítulo
                <input type="text" name="subtitle" value="<?= expo_h((string)($activePiece['subtitle'] ?? '')) ?>">
              </label>
              <label>Orden
                <input type="number" name="sort_order" value="<?= (int)($activePiece['sort_order'] ?? 0) ?>">
              </label>
              <label class="check-inline"><input type="checkbox" name="is_featured" value="1" <?= !empty($activePiece['is_featured']) ? 'checked' : '' ?>> Destacada</label>
              <label class="check-inline"><input type="checkbox" name="is_hidden" value="1" <?= !empty($activePiece['is_hidden']) ? 'checked' : '' ?>> Oculta</label>
              <label>Caption HTML
                <textarea name="caption_html" rows="8"><?= expo_h((string)($activePiece['caption_html'] ?? '')) ?></textarea>
              </label>
              <div class="piece-form-actions">
                <button class="btn btn-primary" type="submit">Guardar pieza</button>
              </div>
            </form>

            <form method="post" class="inline-form">
              <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
              <input type="hidden" name="action" value="set_hero">
              <input type="hidden" name="expo_id" value="<?= (int)$expoId ?>">
              <input type="hidden" name="piece_id" value="<?= (int)$activePiece['id'] ?>">
              <button class="btn btn-secondary" type="submit">Usar como hero</button>
            </form>
          <?php else: ?>
            <p>Elegí una imagen de la grilla para editar sus campos.</p>
          <?php endif; ?>
        </aside>
      </div>
    </section>
  </div>

<script>
(() => {
  const grid = document.getElementById('expoPiecesGrid');
  const bulkCount = document.getElementById('bulk-count');
  const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
  const orderInput = document.getElementById('ordered_ids_json');
  const reorderForm = document.getElementById('reorder-form');
  const saveOrderBtn = document.getElementById('save-order-btn');
  if (!grid || !bulkCount || !bulkDeleteBtn || !orderInput || !reorderForm || !saveOrderBtn) return;

  function refreshBulkState() {
    const checked = [...document.querySelectorAll('.piece-checkbox:checked')];
    bulkCount.textContent = `${checked.length} seleccionadas`;
    bulkDeleteBtn.disabled = checked.length === 0;
  }
  document.querySelectorAll('.piece-checkbox').forEach(ch => ch.addEventListener('change', refreshBulkState));
  refreshBulkState();

  let dragEl = null;
  function getCards() { return [...grid.querySelectorAll('.piece-card')]; }
  function serializeOrder() {
    const ids = getCards().map(card => parseInt(card.dataset.pieceId, 10)).filter(Boolean);
    orderInput.value = JSON.stringify(ids);
  }

  getCards().forEach(card => {
    card.addEventListener('dragstart', () => {
      dragEl = card;
      card.classList.add('dragging');
    });
    card.addEventListener('dragend', () => {
      card.classList.remove('dragging');
      dragEl = null;
      serializeOrder();
    });
    card.addEventListener('dragover', e => {
      e.preventDefault();
      const target = card;
      if (!dragEl || target === dragEl) return;
      const rect = target.getBoundingClientRect();
      const before = (e.clientY - rect.top) < rect.height / 2;
      if (before) grid.insertBefore(dragEl, target);
      else grid.insertBefore(dragEl, target.nextSibling);
    });
  });

  serializeOrder();
  saveOrderBtn.addEventListener('click', () => {
    serializeOrder();
    reorderForm.submit();
  });
})();
</script>
<script>
(function () {
  const root = document.documentElement;
  const range = document.getElementById('thumbSizeRange');
  const value = document.getElementById('thumbSizeValue');
  const storageKey = 'expoThumbSize';
  if (!range || !value) return;

  const applySize = (size) => {
    const px = parseInt(size, 10) || 180;
    root.style.setProperty('--expo-thumb-size', px + 'px');
    range.value = px;
    value.textContent = px + 'px';
  };

  const saved = localStorage.getItem(storageKey);
  if (saved) applySize(saved);
  else applySize(range.value);

  range.addEventListener('input', function () {
    applySize(this.value);
    localStorage.setItem(storageKey, this.value);
  });
})();
</script>
<script>
(function () {
  const checkbox = document.getElementById('togglePieceDetails');
  const storageKey = 'expoPieceDetailsVisible';
  if (!checkbox) return;

  function applyDetailsState(visible) {
    document.body.classList.toggle('piece-details-hidden', !visible);
    checkbox.checked = !!visible;
    localStorage.setItem(storageKey, visible ? '1' : '0');
  }

  const saved = localStorage.getItem(storageKey);
  if (saved === null) applyDetailsState(false);
  else applyDetailsState(saved === '1');

  checkbox.addEventListener('change', function () { applyDetailsState(this.checked); });
})();
</script>
<script>
(function () {
  const body = document.body;
  const panel = document.getElementById('piece-editor');
  const openBtn = document.getElementById('togglePieceEditorBtn');
  const closeBtn = document.getElementById('closePieceEditorBtn');
  const storageKey = 'expoPieceEditorOpen';
  if (!body || !panel || !openBtn) return;

  function setOpen(open) {
    body.classList.toggle('piece-editor-closed', !open);
    openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    openBtn.textContent = open ? 'Ocultar editor' : 'Mostrar editor';
    localStorage.setItem(storageKey, open ? '1' : '0');
  }

  const saved = localStorage.getItem(storageKey);
  const hasPieceSelected = new URLSearchParams(window.location.search).get('piece_id');
  if (hasPieceSelected) setOpen(true);
  else if (saved === null) setOpen(false);
  else setOpen(saved === '1');

  openBtn.addEventListener('click', function () {
    const isOpen = !body.classList.contains('piece-editor-closed');
    setOpen(!isOpen);
  });

  if (closeBtn) closeBtn.addEventListener('click', function () { setOpen(false); });
  document.querySelectorAll('.expo-piece-open').forEach(function (el) {
    el.addEventListener('click', function () { localStorage.setItem(storageKey, '1'); });
  });
})();
</script>
<script>
(function () {
  const select = document.getElementById('template_name');
  const help = document.getElementById('template_help');
  const heroHorizontal = document.querySelectorAll('.hero-horizontal-only');
  const heroVertical = document.querySelectorAll('.hero-vertical-only');
  const descriptions = <?php echo json_encode($templateDescriptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

  if (!select) return;

  function normalizeTemplate(val) {
    return (val === 'hero_vertical_split' || val === 'fullscreen_story' || val === 'hero_horizontal') ? val : 'hero_horizontal';
  }

  function applyTemplateFields() {
    const template = normalizeTemplate(select.value);
    if (help) help.textContent = descriptions[template] || '';
    const isVertical = template === 'hero_vertical_split';

    heroHorizontal.forEach(el => el.classList.toggle('is-hidden', isVertical));
    heroVertical.forEach(el => el.classList.toggle('is-hidden', !isVertical));
  }

  select.addEventListener('change', applyTemplateFields);
  applyTemplateFields();
})();
</script>
</body>
</html>
