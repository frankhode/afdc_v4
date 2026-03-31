<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_relacion_sobres_repo.php';

$id = (int)($_GET['id'] ?? 0);
$tituloReg = trim((string)($_GET['tituloReg'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'pending'));
if (!in_array($view, ['pending','loaded','assigned','all'], true)) {
    $view = 'pending';
}
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$otrosSys = trim((string)($_GET['otros_sys'] ?? ''));
$otrosTitulo = trim((string)($_GET['otros_titulo'] ?? ''));

$import = null;
$tituloRegOptions = [];
$leftTreeHtml = '';
$rightPanelHtml = '';

try {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }

    $import = cmp_rel_get_import($id);
    if (!$import) {
        throw new RuntimeException('La importación no existe.');
    }

    $tituloRegOptions = cmp_rel_get_tituloreg_options();
    $leftTreeHtml = cmp_rel_render_left_tree_html($id, $tituloReg);
    $rightPanelHtml = cmp_rel_render_right_panel_html($id, $tituloRegOptions, $tituloReg, $view, '', $otrosSys, $otrosTitulo, $view);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Relación de sobres del campeonato', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<style>
html,body{height:100%}
body{margin:0;overflow:hidden}
main.container-fluid{width:calc(100% - 16px);margin:12px 8px 16px;padding:0;flex:1;min-width:0}
.app main.container-fluid{max-width:none}

.cmp-rel-page{
  display:flex;
  flex-direction:column;
  gap:10px;
  min-height:0;
  padding:0;
  overflow:hidden;
  width:100%;
}

.cmp-rel-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:4px 0 0 0;
  flex:0 0 auto;
}

.cmp-rel-topbar-left{
  display:flex;
  align-items:baseline;
  gap:12px;
  min-width:0;
  overflow:hidden;
  flex-wrap:nowrap;
}

.cmp-rel-topbar h1{
  margin:0;
  font-size:18px;
  line-height:1.1;
  white-space:nowrap;
}

.cmp-rel-meta{
  color:#5b6472;
  display:flex;
  align-items:center;
  gap:10px;
  min-width:0;
  overflow:hidden;
  white-space:nowrap;
}

.cmp-rel-actions{
  display:flex;
  gap:8px;
  flex-wrap:nowrap;
}

.cmp-rel-wrap{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr);
  gap:10px;
  align-items:stretch;
  flex:1 1 auto;
  min-height:0;
  overflow:hidden;
}

.cmp-rel-panel{
  background:#fff;
  border:1px solid #d9dde4;
  border-radius:14px;
  padding:10px;
  display:flex;
  flex-direction:column;
  min-height:0;
  overflow:hidden;
}

.cmp-rel-panel-head{
  display:flex;
  flex-direction:column;
  gap:8px;
  flex:0 0 auto;
}

.cmp-rel-panel-title{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}

.cmp-rel-panel-title h3{
  margin:0;
  font-size:16px;
  line-height:1.1;
}

.cmp-rel-panel-body,
.cmp-rel-tree-wrap{
  flex:1 1 auto;
  min-height:0;
  overflow:auto;
  padding-right:2px;
}

.cmp-rel-toolbar{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.cmp-rel-toolbar input[type="text"],
.cmp-rel-toolbar select{
  padding:5px 8px;
  border:1px solid #c8d0da;
  border-radius:8px;
  min-height:32px;
  background:#fff;
  width:100%;
  box-sizing:border-box;
}

.cmp-rel-toolbar .cmp-field{
  display:flex;
  flex-direction:column;
  gap:4px;
  min-width:0;
}

.cmp-rel-toolbar .cmp-field-grow{
  flex:1 1 150px;
  min-width:0;
}

#cmpRelRightPanel .cmp-rel-toolbar{
  display:grid;
  grid-template-columns: minmax(0,23%) minmax(0,15%) minmax(0,27%) minmax(0,27%);
  gap:8px;
  align-items:center;
  justify-content:stretch;
}

#cmpRelRightPanel .cmp-rel-toolbar .cmp-field,
#cmpRelRightPanel .cmp-rel-toolbar .cmp-field-grow{
  width:100%;
  min-width:0;
}

#cmpRelRightPanel .cmp-rel-toolbar-rightline{
  justify-content:stretch;
}

@media (max-width: 1100px){
  #cmpRelRightPanel .cmp-rel-toolbar{
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 700px){
  #cmpRelRightPanel .cmp-rel-toolbar{
    grid-template-columns: 1fr;
  }
}

.cmp-rel-inline-check{
  display:inline-flex;
  align-items:center;
  gap:6px;
  white-space:nowrap;
  color:#425066;
  flex:0 0 auto;
}

.cmp-rel-tree,
.cmp-rel-matches{
  list-style:none;
  margin:0;
  padding:0;
}

.cmp-rel-node{
  margin:0 0 8px 0;
}

.cmp-rel-node-row{
  display:flex;
  gap:8px;
  align-items:center;
  padding:6px 8px;
  border-radius:10px;
  background:#f8fafc;
}

.cmp-rel-node-children{
  margin-left:22px;
  margin-top:8px;
}

.cmp-rel-toggle,
.cmp-rel-toggle-spacer{
  width:22px;
  display:inline-flex;
  justify-content:center;
  align-items:center;
}

.cmp-rel-toggle{
  border:1px solid #ccd5df;
  border-radius:7px;
  background:#fff;
  cursor:pointer;
  height:22px;
}

.cmp-rel-toggle-spacer{
  height:22px;
}

.cmp-rel-node-type{
  font-size:11px;
  color:#6c7280;
  text-transform:uppercase;
  min-width:90px;
}

.cmp-rel-node-label{
  font-weight:600;
}

.cmp-rel-matches{
  display:flex;
  flex-direction:column;
  gap:8px;
  margin-top:8px;
}

.cmp-rel-match-leaf{
  border:1px solid #e1e6ed;
  border-radius:12px;
  background:#fff;
}

.cmp-rel-match-dropzone{
  padding:10px;
}

.cmp-rel-match-dropzone.is-over{
  border:2px dashed #3366cc;
  background:#eef4ff;
  border-radius:12px;
}

.cmp-rel-match-head{
  display:flex;
  justify-content:space-between;
  gap:8px;
  align-items:center;
}

.cmp-rel-match-title{
  font-size:14px;
}

.cmp-rel-vs{
  color:#657085;
  font-weight:400;
}

.cmp-rel-match-meta{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  color:#657085;
  font-size:12px;
  margin-top:6px;
}

.cmp-rel-assigned-list{
  margin-top:10px;
  display:flex;
  flex-direction:column;
  gap:8px;
}

.cmp-rel-assigned-item{
  padding:8px;
  border:1px solid #edf1f5;
  border-radius:10px;
  background:#fafbfd;
}

.cmp-rel-assigned-actions{
  margin-top:6px;
  font-size:13px;
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.cmp-rel-muted{
  color:#7a8291;
}

.cmp-rel-card-list{
  display:flex;
  flex-direction:column;
  gap:10px;
}

.cmp-rel-card{
  border:1px solid #d9dde4;
  border-radius:12px;
  padding:12px;
  background:#fff;
  cursor:grab;
}

.cmp-rel-card.is-selected,
[data-otros-row].is-selected{
  outline:2px solid #3366cc;
  background:#eef4ff;
}

.cmp-rel-card h4{
  margin:0 0 6px 0;
  font-size:15px;
}

.cmp-rel-card-meta{
  font-size:12px;
  color:#657085;
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:6px;
}

.cmp-rel-card-text{
  color:#525a67;
}

.cmp-rel-card-teams{
  margin-top:6px;
}

.cmp-rel-card-assigned{
  margin-top:6px;
  color:#657085;
  font-size:13px;
}

.cmp-rel-card-actions{
  margin-top:8px;
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.cmp-rel-hidden{
  display:none !important;
}

.cmp-rel-modal-backdrop{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.22);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:1000;
}

.cmp-rel-modal{
  width:min(720px,calc(100vw - 40px));
  max-height:80vh;
  background:#fff;
  border-radius:14px;
  border:1px solid #d9dde4;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}

.cmp-rel-modal-head{
  padding:12px 14px;
  border-bottom:1px solid #edf1f5;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
}

.cmp-rel-modal-body{
  padding:14px;
  overflow:auto;
}

.cmp-rel-search-row{
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.cmp-rel-search-results{
  margin-top:12px;
  display:flex;
  flex-direction:column;
  gap:8px;
}

.cmp-rel-search-item{
  padding:10px;
  border:1px solid #e1e6ed;
  border-radius:10px;
  background:#fff;
}

.cmp-rel-otros-table-wrap{
  margin-top:10px;
  overflow:auto;
  border:1px solid #e1e6ed;
  border-radius:10px;
}

.cmp-rel-otros-table{
  width:100%;
  min-width:0;
  border-collapse:collapse;
  table-layout:fixed;
}

.cmp-rel-otros-table th,
.cmp-rel-otros-table td{
  padding:8px 10px;
  vertical-align:top;
  border-bottom:1px solid #edf1f5;
}

.cmp-rel-otros-table .col-about{width:34%}
.cmp-rel-otros-table .col-date{width:12%}
.cmp-rel-otros-table .col-teams{width:54%}

.cmp-rel-sobre-title{
  font-weight:700;
  line-height:1.25;
}

.cmp-rel-sobre-meta{
  margin-top:6px;
  font-size:12px;
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.cmp-rel-sobre-barcode{
  font-variant-numeric:tabular-nums;
}

.cmp-rel-sobre-chip{
  display:inline-flex;
  align-items:center;
  padding:2px 8px;
  border:1px solid #ccd5df;
  border-radius:999px;
  font-size:12px;
  color:#536072;
  background:#fff;
}

.cmp-rel-team-stack{
  display:flex;
  flex-direction:column;
  gap:8px;
  width:100%;
}

.cmp-rel-team-row{
  display:grid;
  grid-template-columns:62px minmax(0,1fr);
  gap:8px;
  align-items:start;
  width:100%;
}

.cmp-rel-team-label{
  font-size:12px;
  color:#536072;
  line-height:32px;
}

.cmp-rel-team-picker{
  position:relative;
  min-width:0;
  width:100%;
}

.cmp-rel-team-input-wrap{
  position:relative;
  width:100%;
}

.cmp-rel-team-input{
  width:100%;
  max-width:100%;
  padding:5px 26px 5px 8px;
  border:1px solid #c8d0da;
  border-radius:8px;
  min-height:32px;
  background:#fff;
  box-sizing:border-box;
}

.cmp-rel-team-clear{
  position:absolute;
  right:6px;
  top:50%;
  transform:translateY(-50%);
  border:0;
  background:transparent;
  cursor:pointer;
  color:#607086;
  font-size:18px;
  line-height:1;
  padding:0 2px;
}

.cmp-rel-team-option{
  padding:7px 8px;
  cursor:pointer;
  border-bottom:1px solid #eef2f6;
}

.cmp-rel-team-option:last-child{
  border-bottom:0;
}

.cmp-rel-team-option:hover,
.cmp-rel-team-option.is-active{
  background:#eef4ff;
}

.cmp-rel-team-option.is-create{
  font-style:italic;
  color:#204a87;
}

.cmp-rel-team-results-floating{
  position:fixed;
  z-index:3000;
  background:#fff;
  border:1px solid #c8d0da;
  border-radius:8px;
  box-shadow:0 6px 18px rgba(0,0,0,.08);
  max-height:220px;
  max-width:420px;
  overflow:auto;
  display:none;
}

.cmp-rel-team-results-floating.is-open{
  display:block;
}

@media (max-width:1100px){
  body{overflow:auto}
  .cmp-rel-page{height:auto !important;overflow:visible}
  .cmp-rel-wrap{grid-template-columns:1fr;overflow:visible}
  .cmp-rel-panel,.cmp-rel-tree-wrap,.cmp-rel-panel-body{min-height:280px}
}
</style>

<div class="cmp-rel-page" id="cmpRelPage" data-import-id="<?= (int)$id ?>">
  <div class="cmp-rel-topbar">
    <div class="cmp-rel-topbar-left">
      <h1>Relación de sobres</h1>
      <div class="cmp-rel-meta">
        <span>Importación #<?= (int)$id ?></span>
        <?php if ($import): ?><span><?= cmp_h((string)($import['nombre'] ?? '')) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="cmp-rel-actions">
      <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>">Volver</a>
      <button type="button" class="cmp-btn" id="cmpRelRefreshAll">Refrescar</button>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div><?php endif; ?>

  <div class="cmp-rel-wrap" id="workspace">
    <section class="cmp-rel-panel">
      <div class="cmp-rel-panel-head">
        <div class="cmp-rel-panel-title"><h3>Partidos del campeonato</h3></div>
        <div class="cmp-rel-toolbar">
          <div class="cmp-field cmp-field-grow"><input type="text" id="cmpRelLeftTeamA" placeholder="Equipo A"></div>
          <div class="cmp-field cmp-field-grow"><input type="text" id="cmpRelLeftTeamB" placeholder="Equipo B"></div>
          <label class="cmp-rel-inline-check"><input type="checkbox" id="cmpRelOnlyEmpty"> Solo sin sobres</label>
        </div>
      </div>
      <div class="cmp-rel-tree-wrap" id="cmpRelLeftTree"><?= $leftTreeHtml ?></div>
    </section>

    <section class="cmp-rel-panel" id="cmpRelRightPanel">
      <div class="cmp-rel-panel-head">
        <div class="cmp-rel-panel-title"><h3>Sobres del campeonato</h3></div>
        <div class="cmp-rel-toolbar cmp-rel-toolbar-rightline">
          <div class="cmp-field">
            <select id="tituloReg" name="tituloReg">
              <option value="">Seleccionar…</option>
              <?php foreach ($tituloRegOptions as $opt): ?>
                <option value="<?= cmp_h($opt) ?>" <?= $tituloReg === $opt ? 'selected' : '' ?>><?= cmp_h($opt) ?></option>
              <?php endforeach; ?>
              <option value="__otros__" <?= $tituloReg === '__otros__' ? 'selected' : '' ?>>Otros…</option>
            </select>
          </div>
          <div class="cmp-field">
            <select id="view" name="view">
              <option value="pending" <?= $view === 'pending' ? 'selected' : '' ?>>Pendientes</option>
              <option value="loaded" <?= $view === 'loaded' || $view === 'assigned' ? 'selected' : '' ?>>Cargados</option>
              <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Todos</option>
            </select>
          </div>
          <div class="cmp-field cmp-field-grow"><input type="text" id="cmpRelRightTeamA" placeholder="Equipo A"></div>
          <div class="cmp-field cmp-field-grow"><input type="text" id="cmpRelRightTeamB" placeholder="Equipo B"></div>
        </div>
      </div>
      <div class="cmp-rel-panel-body" id="cmpRelRightPanelBody"><?= $rightPanelHtml ?></div>
    </section>
  </div>
</div>

<div class="cmp-rel-modal-backdrop" id="cmpRelOtrosBackdrop">
  <div class="cmp-rel-modal" role="dialog" aria-modal="true" aria-labelledby="cmpRelOtrosTitle">
    <div class="cmp-rel-modal-head"><h4 id="cmpRelOtrosTitle">Buscar campeonato en registros.titulo245</h4><button type="button" class="cmp-btn cmp-btn-sm" id="cmpRelCloseOtrosSearch">Cerrar</button></div>
    <div class="cmp-rel-modal-body">
      <div class="cmp-rel-search-row">
        <input type="text" id="cmpRelOtrosSearchInput" placeholder="Ej. Primera B 1975, Copa, Nacional..." style="flex:1 1 260px; min-height:34px; padding:6px 8px; border:1px solid #c8d0da; border-radius:8px;">
        <button type="button" class="cmp-btn" id="cmpRelOtrosSearchBtn">Buscar</button>
      </div>
      <div id="cmpRelOtrosSearchStatus" style="margin-top:10px;" class="cmp-rel-muted"></div>
      <div id="cmpRelOtrosSearchResults" class="cmp-rel-search-results"></div>
    </div>
  </div>
</div>

<script>
(() => {
  const page = document.getElementById('cmpRelPage');
  if (!page) return;
  const importId = page.dataset.importId;
  let selectedBarcode = null;
  let rightRefreshTimer = null;
  let leftFilterTimer = null;
  let rightFilterA = '';
  let rightFilterB = '';
  let teamSearchTimers = new WeakMap();

  function normalizeText(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
  }
  function debounce(fn, delay, slotName) {
    if (slotName === 'right') {
      clearTimeout(rightRefreshTimer);
      rightRefreshTimer = setTimeout(fn, delay);
      return;
    }
    clearTimeout(leftFilterTimer);
    leftFilterTimer = setTimeout(fn, delay);
  }
  function state() {
    return {
      import_id: importId,
      tituloReg: document.getElementById('tituloReg')?.value || '',
      view: document.getElementById('view')?.value || 'pending',
      otros_sys: document.getElementById('cmpRelOtrosSys')?.value || '',
      otros_titulo: document.getElementById('cmpRelOtrosTitulo')?.value || ''
    };
  }
  function fitPageHeight() {
    if (window.innerWidth <= 1100) { page.style.height = 'auto'; return; }
    const top = page.getBoundingClientRect().top;
    page.style.height = Math.max(320, window.innerHeight - top - 16) + 'px';
  }
  function escapeHtml(str){ return String(str).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
  function escapeAttr(str){ return escapeHtml(str); }

  function getOtrosRowData(barcode) {
    const row = document.querySelector(`[data-otros-row][data-barcode="${CSS.escape(barcode)}"]`);
    if (!row) return null;
    const inputs = row.querySelectorAll('input[name="equipo1[]"], input[name="equipo2[]"]');
    return {
      row,
      barcode,
      tituloSobre: row.dataset.titulo || '',
      fecha: row.dataset.fecha || '',
      equipo1: inputs[0]?.value?.trim() || '',
      equipo2: inputs[1]?.value?.trim() || ''
    };
  }

  function bindCardSelection() {
    document.querySelectorAll('.cmp-rel-card,[data-otros-row]').forEach(el => {
      el.addEventListener('click', () => {
        document.querySelectorAll('.cmp-rel-card.is-selected,[data-otros-row].is-selected').forEach(x => x.classList.remove('is-selected'));
        el.classList.add('is-selected');
        selectedBarcode = el.dataset.barcode || null;
      });
      el.addEventListener('dragstart', ev => {
        selectedBarcode = el.dataset.barcode || null;
        ev.dataTransfer.setData('text/plain', selectedBarcode || '');
        ev.dataTransfer.effectAllowed = 'move';
      });
    });
  }

  async function assignBarcodeToMatch(matchId, barcode, origin = 'manual_drag') {
    const s = state();
    if (s.tituloReg === '__otros__') {
      const data = getOtrosRowData(barcode);
      if (!data) return;
      if (!data.equipo1 || !data.equipo2) {
        alert('Debe asignar los equipos primero.');
        return;
      }
      await ajaxAction('save_and_assign_other', {
        match_id: matchId,
        barcode: data.barcode,
        tituloSobre: data.tituloSobre,
        fecha: data.fecha,
        equipo1: data.equipo1,
        equipo2: data.equipo2
      });
      return;
    }
    await ajaxAction('assign', {match_id: matchId, barcode, origin});
  }

  function bindDropzones() {
    document.querySelectorAll('[data-drop-match-id]').forEach(zone => {
      zone.addEventListener('dragover', ev => { ev.preventDefault(); zone.classList.add('is-over'); });
      zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
      zone.addEventListener('drop', ev => {
        ev.preventDefault();
        zone.classList.remove('is-over');
        const barcode = ev.dataTransfer.getData('text/plain') || selectedBarcode;
        const matchId = zone.dataset.dropMatchId || '';
        if (!barcode || !matchId) return;
        assignBarcodeToMatch(matchId, barcode, 'manual_drag');
      });
    });
  }

  function applyLeftFilters() {
    const a = normalizeText(document.getElementById('cmpRelLeftTeamA')?.value || '');
    const b = normalizeText(document.getElementById('cmpRelLeftTeamB')?.value || '');
    const onlyEmpty = !!document.getElementById('cmpRelOnlyEmpty')?.checked;
    document.querySelectorAll('[data-match-leaf]').forEach(match => {
      const haySobres = parseInt(match.dataset.assignedCount || '0', 10) > 0;
      const txt = normalizeText(match.dataset.nodeSearch || '');
      let visible = true;
      if (a) visible = visible && txt.includes(a);
      if (b) visible = visible && txt.includes(b);
      if (onlyEmpty) visible = visible && !haySobres;
      match.classList.toggle('cmp-rel-hidden', !visible);
    });
    Array.from(document.querySelectorAll('[data-node-block]')).reverse().forEach(node => {
      const txt = normalizeText(node.dataset.nodeSearch || '');
      const childVisible = !!node.querySelector(':scope > .cmp-rel-node-children [data-match-leaf]:not(.cmp-rel-hidden), :scope > .cmp-rel-node-children [data-node-block]:not(.cmp-rel-hidden)');
      const selfVisible = (!a || txt.includes(a)) && (!b || txt.includes(b));
      node.classList.toggle('cmp-rel-hidden', !(selfVisible || childVisible));
    });
  }

  function applyRightFilters() {
    const a = normalizeText(rightFilterA);
    const b = normalizeText(rightFilterB);
    document.querySelectorAll('[data-right-search]').forEach(el => {
      const txt = normalizeText(el.dataset.rightSearch || '');
      let visible = true;
      if (a) visible = visible && txt.includes(a);
      if (b) visible = visible && txt.includes(b);
      el.classList.toggle('cmp-rel-hidden', !visible);
    });
  }

  function bindToggles() {
    document.querySelectorAll('[data-rel-toggle]').forEach(btn => {
      btn.addEventListener('click', () => {
        const row = btn.closest('.cmp-rel-node-row');
        const children = row?.nextElementSibling;
        const expanded = btn.getAttribute('aria-expanded') !== 'false';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        btn.textContent = expanded ? '+' : '−';
        if (children) children.classList.toggle('cmp-rel-hidden', expanded);
      });
    });
  }

  async function ajaxAction(action, extra = {}) {
    const body = new URLSearchParams({...state(), action, ajax:'1', ...extra});
    const res = await fetch('campeonatos_relacion_sobres_accion.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body});
    const json = await res.json();
    if (!json.ok) { alert(json.error || 'Ocurrió un error.'); return; }
    if (typeof json.left_tree_html === 'string') document.getElementById('cmpRelLeftTree').innerHTML = json.left_tree_html;
    if (typeof json.right_panel_html === 'string') document.getElementById('cmpRelRightPanelBody').innerHTML = json.right_panel_html;
    selectedBarcode = null;
    rebindAll();
    applyLeftFilters();
    applyRightFilters();
    fitPageHeight();
  }

  function scheduleRightRefresh(delay = 250) { debounce(() => ajaxAction('refresh'), delay, 'right'); }
  function openOtrosModal(){ document.getElementById('cmpRelOtrosBackdrop').style.display='flex'; document.getElementById('cmpRelOtrosSearchInput')?.focus(); }
  function closeOtrosModal(){ document.getElementById('cmpRelOtrosBackdrop').style.display='none'; }

  async function doOtrosSearch() {
    const q = (document.getElementById('cmpRelOtrosSearchInput')?.value || '').trim();
    const status = document.getElementById('cmpRelOtrosSearchStatus');
    const results = document.getElementById('cmpRelOtrosSearchResults');
    if (!status || !results) return;
    results.innerHTML = '';
    if (!q) { status.textContent = 'Escribí algo para buscar.'; return; }
    status.textContent = 'Buscando…';
    try {
      const res = await fetch('campeonatos_titulo245_ajax.php?action=search_registros&q=' + encodeURIComponent(q), {credentials:'same-origin'});
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'No se pudo buscar.');
      const items = Array.isArray(json.items) ? json.items : [];
      status.textContent = items.length ? ('Resultados: ' + items.length) : 'Sin resultados.';
      const chooseBaseUrl = `campeonatos_relacion_sobres.php?id=${encodeURIComponent(importId)}&tituloReg=__otros__`;
      results.innerHTML = items.map(item => {
        const href = chooseBaseUrl + `&otros_sys=${encodeURIComponent(item.sys)}&otros_titulo=${encodeURIComponent(item.titulo245)}`;
        return `<div class="cmp-rel-search-item"><div><strong>${escapeHtml(item.titulo245)}</strong></div><div class="cmp-rel-muted" style="margin-top:4px;">sys ${escapeHtml(item.sys)}</div><div style="margin-top:8px;"><a class="cmp-btn cmp-btn-sm" href="${href}">Elegir</a></div></div>`;
      }).join('');
    } catch (err) { status.textContent = err && err.message ? err.message : 'Error buscando.'; }
  }

  function bindTeamPickers() {
    let floating = document.getElementById('cmpRelTeamResultsFloating');
    if (!floating) {
      floating = document.createElement('div');
      floating.id = 'cmpRelTeamResultsFloating';
      floating.className = 'cmp-rel-team-results-floating';
      document.body.appendChild(floating);
    }
    let activeInput = null, activeHidden = null, activeIndex = -1;
    function closeResults() { floating.classList.remove('is-open'); floating.innerHTML=''; activeInput=null; activeHidden=null; activeIndex=-1; }
    function positionResults(input) { const r=input.getBoundingClientRect(); floating.style.left=r.left+'px'; floating.style.top=(r.bottom+2)+'px'; floating.style.width=r.width+'px'; }
    function updateRowSearch() { const row = activeHidden?.closest('[data-right-search]'); if (!row) return; const vals = Array.from(row.querySelectorAll('input[name="equipo1[]"],input[name="equipo2[]"]')).map(el => el.value || '').join(' '); row.dataset.rightSearch = (row.dataset.rightSearchBase || '') + ' ' + vals; }
    function choose(name) { if (!activeHidden || !activeInput) return; activeHidden.value = name; activeInput.value = name; updateRowSearch(); closeResults(); }
    async function createTeam(name) {
      const body = new URLSearchParams({action:'create_team', name});
      const res = await fetch('campeonatos_equipos_ajax.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin', body});
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'No se pudo crear el equipo.');
      choose(json.item || json.team_name || name);
    }
    async function searchTeams(input, hidden, term) {
      if (!term || term.trim().length < 2) { closeResults(); return; }
      activeInput = input; activeHidden = hidden;
      try {
        const cleanTerm = term.trim();
        const res = await fetch('campeonatos_equipos_ajax.php?q=' + encodeURIComponent(cleanTerm), {credentials:'same-origin'});
        const json = await res.json();
        const items = Array.isArray(json.items) ? json.items : [];
        const normTerm = normalizeText(cleanTerm);
        let html = items.map((item, idx) => `<div class="cmp-rel-team-option${idx===0?' is-active':''}" data-team-option="${escapeAttr(item)}">${escapeHtml(item)}</div>`).join('');
        const exactExists = items.some(item => normalizeText(item) === normalizeText(cleanTerm));
        if (!exactExists) html += `<div class="cmp-rel-team-option is-create${items.length===0?' is-active':''}" data-team-create="${escapeAttr(cleanTerm)}">Agregar "${escapeHtml(cleanTerm)}"</div>`;
        if (!html) { closeResults(); return; }
        floating.innerHTML = html;
        floating.classList.add('is-open');
        positionResults(input);
        activeIndex = 0;
        const all = Array.from(floating.querySelectorAll('[data-team-option],[data-team-create]'));
        all.forEach((opt, idx) => opt.classList.toggle('is-active', idx === activeIndex));
        floating.querySelectorAll('[data-team-option]').forEach(opt => opt.addEventListener('mousedown', ev => { ev.preventDefault(); choose(opt.getAttribute('data-team-option') || ''); }));
        floating.querySelectorAll('[data-team-create]').forEach(opt => opt.addEventListener('mousedown', async ev => { ev.preventDefault(); try { await createTeam(opt.getAttribute('data-team-create') || ''); } catch (err) { alert(err && err.message ? err.message : 'No se pudo crear el equipo.'); } }));
      } catch (_e) { closeResults(); }
    }
    document.querySelectorAll('[data-team-picker]').forEach(picker => {
      if (picker.dataset.bound === '1') return; picker.dataset.bound = '1';
      const input = picker.querySelector('[data-team-input]'); const hidden = picker.querySelector('input[type="hidden"]'); const clearBtn = picker.querySelector('[data-team-clear]');
      input.addEventListener('input', () => { hidden.value=''; const row = hidden.closest('[data-right-search]'); if (row) { const vals = Array.from(row.querySelectorAll('input[name="equipo1[]"],input[name="equipo2[]"]')).map(el => el.value || '').join(' '); row.dataset.rightSearch = (row.dataset.rightSearchBase || '') + ' ' + vals; } const prev=teamSearchTimers.get(input); if (prev) clearTimeout(prev); const t=setTimeout(()=>searchTeams(input, hidden, input.value),180); teamSearchTimers.set(input,t); });
      input.addEventListener('focus', () => { if (input.value.trim().length >= 2) searchTeams(input, hidden, input.value); });
      input.addEventListener('keydown', async ev => {
        const options = Array.from(floating.querySelectorAll('[data-team-option],[data-team-create]'));
        if (!floating.classList.contains('is-open')) return;
        if (ev.key==='ArrowDown' && options.length) { ev.preventDefault(); activeIndex=Math.min(activeIndex+1, options.length-1); options.forEach((opt,idx)=>opt.classList.toggle('is-active', idx===activeIndex)); return; }
        if (ev.key==='ArrowUp' && options.length) { ev.preventDefault(); activeIndex=Math.max(activeIndex-1, 0); options.forEach((opt,idx)=>opt.classList.toggle('is-active', idx===activeIndex)); return; }
        if (ev.key==='Enter' && options.length && activeIndex >= 0) { ev.preventDefault(); const active=options[activeIndex]; if (active.hasAttribute('data-team-option')) choose(active.getAttribute('data-team-option') || ''); else if (active.hasAttribute('data-team-create')) { try { await createTeam(active.getAttribute('data-team-create') || ''); } catch (err) { alert(err && err.message ? err.message : 'No se pudo crear el equipo.'); } } return; }
        if (ev.key==='Escape') closeResults();
      });
      input.addEventListener('blur', () => { setTimeout(() => { if (!hidden.value) { input.value=''; const row=hidden.closest('[data-right-search]'); if (row) { const vals = Array.from(row.querySelectorAll('input[name="equipo1[]"],input[name="equipo2[]"]')).map(el => el.value || '').join(' '); row.dataset.rightSearch=(row.dataset.rightSearchBase||'') + ' ' + vals; } } closeResults(); }, 120); });
      clearBtn?.addEventListener('click', () => { hidden.value=''; input.value=''; const row=hidden.closest('[data-right-search]'); if (row) { const vals = Array.from(row.querySelectorAll('input[name="equipo1[]"],input[name="equipo2[]"]')).map(el => el.value || '').join(' '); row.dataset.rightSearch=(row.dataset.rightSearchBase||'') + ' ' + vals; } closeResults(); input.focus(); });
    });
    window.addEventListener('scroll', () => { if (floating.classList.contains('is-open') && activeInput) positionResults(activeInput); }, true);
    window.addEventListener('resize', () => { if (floating.classList.contains('is-open') && activeInput) positionResults(activeInput); });
    document.addEventListener('click', ev => { const insidePicker = ev.target.closest('[data-team-picker]'); const insideFloating = ev.target.closest('#cmpRelTeamResultsFloating'); if (!insidePicker && !insideFloating) closeResults(); });
  }

  function rebindAll() { bindCardSelection(); bindDropzones(); bindToggles(); bindTeamPickers(); }

  document.addEventListener('click', ev => {
    const assignBtn = ev.target.closest('[data-assign-selected]');
    if (assignBtn) {
      if (!selectedBarcode) { alert('Seleccioná primero un sobre de la derecha.'); return; }
      assignBarcodeToMatch(assignBtn.dataset.assignSelected, selectedBarcode, 'manual_boton');
      return;
    }
    const unassignBtn = ev.target.closest('[data-unassign-barcode]');
    if (unassignBtn) { ajaxAction('unassign', {barcode: unassignBtn.dataset.unassignBarcode}); return; }
    if (ev.target.id === 'cmpRelRefreshAll') { ajaxAction('refresh'); return; }
    if (ev.target.id === 'cmpRelCloseOtrosSearch') { closeOtrosModal(); return; }
  });

  document.addEventListener('input', ev => {
    if (ev.target.id === 'cmpRelLeftTeamA' || ev.target.id === 'cmpRelLeftTeamB') { debounce(applyLeftFilters, 120, 'left'); return; }
    if (ev.target.id === 'cmpRelRightTeamA') { rightFilterA = ev.target.value || ''; applyRightFilters(); return; }
    if (ev.target.id === 'cmpRelRightTeamB') { rightFilterB = ev.target.value || ''; applyRightFilters(); return; }
  });

  document.addEventListener('change', ev => {
    if (ev.target.id === 'cmpRelOnlyEmpty') { applyLeftFilters(); return; }
    if (ev.target.id === 'tituloReg') {
      if (ev.target.value === '__otros__') {
        ajaxAction('refresh', {tituloReg:'__otros__', otros_sys:'', otros_titulo:''}).then(() => setTimeout(openOtrosModal, 100));
      } else {
        scheduleRightRefresh(0);
      }
      return;
    }
    if (ev.target.id === 'view') { scheduleRightRefresh(0); }
  });

  document.getElementById('cmpRelOtrosSearchBtn')?.addEventListener('click', doOtrosSearch);
  document.getElementById('cmpRelOtrosSearchInput')?.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); doOtrosSearch(); } });

  rebindAll(); applyLeftFilters(); applyRightFilters(); fitPageHeight();
  if (document.getElementById('tituloReg')?.value === '__otros__' && !(document.getElementById('cmpRelOtrosSys')?.value)) setTimeout(openOtrosModal, 120);
  window.addEventListener('resize', fitPageHeight);
})();
</script>
<?php cmp_render_footer(); ?>