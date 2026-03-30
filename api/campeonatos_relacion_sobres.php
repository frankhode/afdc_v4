<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_relacion_sobres_repo.php';

$id = (int)($_GET['id'] ?? 0);
$tituloReg = trim((string)($_GET['tituloReg'] ?? ''));
$view = trim((string)($_GET['view'] ?? 'pending'));
$qSobres = trim((string)($_GET['q_sobres'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));
$isAjax = !empty($_GET['ajax']) || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

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
    $rightPanelHtml = cmp_rel_render_right_panel_html($id, $tituloRegOptions, $tituloReg, $view, $qSobres);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Relación de sobres del campeonato');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<style>
html,body{height:100%}
body{margin:0;overflow:hidden}
.cmp-rel-page{display:flex;flex-direction:column;gap:10px;min-height:0;padding-bottom:0;overflow:hidden}
.cmp-rel-topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 0 0 0;flex:0 0 auto}
.cmp-rel-topbar-left{display:flex;align-items:baseline;gap:12px;min-width:0;overflow:hidden;flex-wrap:nowrap}
.cmp-rel-topbar h1{margin:0;font-size:18px;line-height:1.1;white-space:nowrap}
.cmp-rel-meta{color:#5b6472;display:flex;align-items:center;gap:10px;min-width:0;overflow:hidden;white-space:nowrap}
.cmp-rel-actions{display:flex;gap:8px;flex-wrap:nowrap}
.cmp-rel-wrap{display:grid;grid-template-columns:minmax(420px,1fr) minmax(420px,1fr);gap:14px;align-items:stretch;flex:1 1 auto;min-height:0;overflow:hidden}
.cmp-rel-panel{background:#fff;border:1px solid #d9dde4;border-radius:14px;padding:12px;display:flex;flex-direction:column;min-height:0;overflow:hidden}
.cmp-rel-panel-head{display:flex;flex-direction:column;gap:10px;flex:0 0 auto}
.cmp-rel-panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.cmp-rel-panel-title h3{margin:0;font-size:16px;line-height:1.1}
.cmp-rel-panel-body,.cmp-rel-tree-wrap{flex:1 1 auto;min-height:0;overflow:auto;padding-right:4px}

.cmp-rel-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:nowrap}
.cmp-rel-toolbar input[type="text"],
.cmp-rel-toolbar select{
  padding:5px 8px;
  border:1px solid #c8d0da;
  border-radius:8px;
  min-height:32px;
  background:#fff;
}
.cmp-rel-toolbar .cmp-field{display:flex;flex-direction:column;gap:4px;min-width:0}
.cmp-rel-toolbar .cmp-field-grow{flex:0 0 auto;min-width:0}
.cmp-rel-toolbar-rightline{align-items:center}

#cmpRelTreeFilter{
  width:20ch;
  max-width:20ch;
  height:32px;
  line-height:32px;
}

#cmpRelRightPanel #q_sobres{
  width:20ch;
  max-width:20ch;
  height:32px;
  line-height:32px;
}

#cmpRelRightPanel #tituloReg{
  width:22ch;
  max-width:22ch;
}

#cmpRelRightPanel #view{
  width:12ch;
  max-width:12ch;
}

.cmp-rel-inline-check{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;color:#425066;flex:0 0 auto}
.cmp-rel-status{display:flex;gap:8px;flex-wrap:wrap}
.cmp-rel-tree,.cmp-rel-matches{list-style:none;margin:0;padding:0}
.cmp-rel-node{margin:0 0 8px 0}
.cmp-rel-node-row{display:flex;gap:8px;align-items:center;padding:6px 8px;border-radius:10px;background:#f8fafc}
.cmp-rel-node-children{margin-left:22px;margin-top:8px}
.cmp-rel-toggle,.cmp-rel-toggle-spacer{width:22px;display:inline-flex;justify-content:center;align-items:center}
.cmp-rel-toggle{border:1px solid #ccd5df;border-radius:7px;background:#fff;cursor:pointer;height:22px}
.cmp-rel-toggle-spacer{height:22px}
.cmp-rel-node-type{font-size:11px;color:#6c7280;text-transform:uppercase;min-width:90px}
.cmp-rel-node-label{font-weight:600}
.cmp-rel-matches{display:flex;flex-direction:column;gap:8px;margin-top:8px}
.cmp-rel-match-leaf{border:1px solid #e1e6ed;border-radius:12px;background:#fff}
.cmp-rel-match-dropzone{padding:10px}
.cmp-rel-match-dropzone.is-over{border:2px dashed #3366cc;background:#eef4ff;border-radius:12px}
.cmp-rel-match-head{display:flex;justify-content:space-between;gap:8px;align-items:center}
.cmp-rel-match-title{font-size:14px}
.cmp-rel-vs{color:#657085;font-weight:400}
.cmp-rel-match-meta{display:flex;gap:8px;flex-wrap:wrap;color:#657085;font-size:12px;margin-top:6px}
.cmp-rel-assigned-list{margin-top:10px;display:flex;flex-direction:column;gap:8px}
.cmp-rel-assigned-item{padding:8px;border:1px solid #edf1f5;border-radius:10px;background:#fafbfd}
.cmp-rel-assigned-actions{margin-top:6px;font-size:13px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cmp-rel-muted{color:#7a8291}
.cmp-rel-card-list{display:flex;flex-direction:column;gap:10px}
.cmp-rel-card{border:1px solid #d9dde4;border-radius:12px;padding:12px;background:#fff;cursor:grab}
.cmp-rel-card.is-selected{outline:2px solid #3366cc;background:#eef4ff}
.cmp-rel-card h4{margin:0 0 6px 0;font-size:15px}
.cmp-rel-card-meta{font-size:12px;color:#657085;display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px}
.cmp-rel-card-text{color:#525a67}
.cmp-rel-card-teams{margin-top:6px}
.cmp-rel-card-assigned{margin-top:6px;color:#657085;font-size:13px}
.cmp-rel-card-actions{margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.cmp-rel-hidden{display:none !important}
.cmp-rel-flash{margin:0;flex:0 0 auto}
@media (max-width: 1100px){
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

  <div class="cmp-rel-wrap">
    <section class="cmp-rel-panel">
      <div class="cmp-rel-panel-head">
        <div class="cmp-rel-panel-title"><h3>Partidos del campeonato</h3></div>
        <div class="cmp-rel-toolbar">
          <div class="cmp-field cmp-field-grow">
            <input type="text" id="cmpRelTreeFilter" placeholder="Equipo, fecha, grupo...">
          </div>
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
              <?php foreach ($tituloRegOptions as $opt): ?>
                <option value="<?= cmp_h($opt) ?>" <?= $tituloReg === $opt ? 'selected' : '' ?>>
                  <?= cmp_h($opt) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="cmp-field">
            <select id="view" name="view">
              <option value="pending" <?= $view === 'pending' ? 'selected' : '' ?>>Pendientes</option>
              <option value="assigned" <?= $view === 'assigned' ? 'selected' : '' ?>>Asignados</option>
              <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Todos</option>
            </select>
          </div>

          <div class="cmp-field cmp-field-grow">
            <input type="text" id="q_sobres" name="q_sobres" value="<?= cmp_h($qSobres) ?>" placeholder="barcode, equipo, fecha...">
          </div>
        </div>
      </div>

      <div class="cmp-rel-panel-body" id="cmpRelRightPanelBody">
        <?= $rightPanelHtml ?>
      </div>
    </section>
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
  let lastRightFocus = null;

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
      q_sobres: document.getElementById('q_sobres')?.value || '',
      q_tree: document.getElementById('cmpRelTreeFilter')?.value || '',
      only_empty: document.getElementById('cmpRelOnlyEmpty')?.checked ? '1' : '0'
    };
  }

  function fitPageHeight() {
    if (window.innerWidth <= 1100) {
      page.style.height = 'auto';
      return;
    }
    const top = page.getBoundingClientRect().top;
    const bottomGap = 16;
    const h = Math.max(320, window.innerHeight - top - bottomGap);
    page.style.height = h + 'px';
  }

  function setFlash(type, text) {
    return;
  }

  function bindCardSelection() {
    document.querySelectorAll('.cmp-rel-card').forEach(card => {
      card.addEventListener('click', () => {
        document.querySelectorAll('.cmp-rel-card.is-selected').forEach(el => el.classList.remove('is-selected'));
        card.classList.add('is-selected');
        selectedBarcode = card.dataset.barcode || null;
      });
      card.addEventListener('dragstart', ev => {
        selectedBarcode = card.dataset.barcode || null;
        ev.dataTransfer.setData('text/plain', selectedBarcode || '');
        ev.dataTransfer.effectAllowed = 'move';
      });
    });
  }

  function bindDropzones() {
    document.querySelectorAll('[data-drop-match-id]').forEach(zone => {
      zone.addEventListener('dragover', ev => {
        ev.preventDefault();
        zone.classList.add('is-over');
      });
      zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
      zone.addEventListener('drop', ev => {
        ev.preventDefault();
        zone.classList.remove('is-over');
        const barcode = ev.dataTransfer.getData('text/plain') || selectedBarcode;
        const matchId = zone.dataset.dropMatchId || '';
        if (!barcode || !matchId) return;
        ajaxAction('assign', {match_id: matchId, barcode: barcode, origin: 'manual_drag'});
      });
    });
  }

  function applyTreeFilter() {
    const q = (document.getElementById('cmpRelTreeFilter')?.value || '').toLowerCase().trim();
    const onlyEmpty = !!document.getElementById('cmpRelOnlyEmpty')?.checked;

    document.querySelectorAll('[data-match-leaf]').forEach(match => {
      const haySobres = parseInt(match.dataset.assignedCount || '0', 10) > 0;
      const txt = (match.dataset.nodeSearch || '').toLowerCase();
      const visible = (!q || txt.includes(q)) && (!onlyEmpty || !haySobres);
      match.classList.toggle('cmp-rel-hidden', !visible);
    });

    const blocks = Array.from(document.querySelectorAll('[data-node-block]'));
    blocks.reverse().forEach(node => {
      const txt = (node.dataset.nodeSearch || '').toLowerCase();
      const childVisible = !!node.querySelector(':scope > .cmp-rel-node-children [data-match-leaf]:not(.cmp-rel-hidden), :scope > .cmp-rel-node-children [data-node-block]:not(.cmp-rel-hidden)');
      const selfVisible = !q || txt.includes(q);
      node.classList.toggle('cmp-rel-hidden', !(selfVisible || childVisible));
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
    const s = state();
    const body = new URLSearchParams({...s, action, ajax: '1', ...extra});
    const res = await fetch('campeonatos_relacion_sobres_accion.php', {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body
    });
    const json = await res.json();
    if (!json.ok) {
      setFlash('error', json.error || 'Ocurrió un error.');
      return;
    }
    refreshPanelsFromJson(json);
    fitPageHeight();
  }

  function captureRightFocusState() {
    const active = document.activeElement;
    if (!active || !active.id) return null;

    if (active.id !== 'q_sobres' && active.id !== 'tituloReg' && active.id !== 'view') {
      return null;
    }

    return {
      id: active.id,
      value: active.value || '',
      start: typeof active.selectionStart === 'number' ? active.selectionStart : null,
      end: typeof active.selectionEnd === 'number' ? active.selectionEnd : null
    };
  }

  function restoreRightFocusState(stateObj) {
    if (!stateObj || !stateObj.id) return;

    const el = document.getElementById(stateObj.id);
    if (!el) return;

    el.focus();

    if (
      stateObj.id === 'q_sobres' &&
      typeof stateObj.start === 'number' &&
      typeof stateObj.end === 'number' &&
      typeof el.setSelectionRange === 'function'
    ) {
      const len = (el.value || '').length;
      const start = Math.min(stateObj.start, len);
      const end = Math.min(stateObj.end, len);
      el.setSelectionRange(start, end);
    }
  }

  function refreshPanelsFromJson(json) {
    const focusState = lastRightFocus;

    if (typeof json.left_tree_html === 'string') {
      document.getElementById('cmpRelLeftTree').innerHTML = json.left_tree_html;
    }
    if (typeof json.right_panel_html === 'string') {
      document.getElementById('cmpRelRightPanelBody').innerHTML = json.right_panel_html;
    }

    selectedBarcode = null;
    rebindAll();
    applyTreeFilter();
    restoreRightFocusState(focusState);
    lastRightFocus = null;
  }

  async function refreshPanels() {
    await ajaxAction('refresh');
  }

  function scheduleRightRefresh(delay = 250) {
    lastRightFocus = captureRightFocusState();
    debounce(() => {
      refreshPanels();
    }, delay, 'right');
  }

  function rebindAll() {
    bindCardSelection();
    bindDropzones();
    bindToggles();
  }

  document.addEventListener('click', ev => {
    const assignBtn = ev.target.closest('[data-assign-selected]');
    if (assignBtn) {
      if (!selectedBarcode) {
        setFlash('error', 'Seleccioná primero un sobre de la derecha.');
        return;
      }
      ajaxAction('assign', {
        match_id: assignBtn.dataset.assignSelected,
        barcode: selectedBarcode,
        origin: 'manual_boton'
      });
      return;
    }

    const unassignBtn = ev.target.closest('[data-unassign-barcode]');
    if (unassignBtn) {
      ajaxAction('unassign', {barcode: unassignBtn.dataset.unassignBarcode});
      return;
    }

    if (ev.target.id === 'cmpRelRefreshAll') {
      refreshPanels();
    }
  });

  document.addEventListener('input', ev => {
    if (ev.target.id === 'cmpRelTreeFilter') {
      debounce(() => applyTreeFilter(), 120, 'left');
      return;
    }
    if (ev.target.id === 'q_sobres') {
      scheduleRightRefresh(280);
    }
  });

  document.addEventListener('change', ev => {
    if (ev.target.id === 'cmpRelOnlyEmpty') {
      applyTreeFilter();
      return;
    }
    if (ev.target.id === 'tituloReg' || ev.target.id === 'view') {
      scheduleRightRefresh(0);
    }
  });

  rebindAll();
  applyTreeFilter();
  fitPageHeight();
  window.addEventListener('resize', fitPageHeight);
})();
</script>
<?php cmp_render_footer(); ?>