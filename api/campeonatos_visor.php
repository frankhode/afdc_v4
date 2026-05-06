<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_visor_repo.php';

$years = [];
$imports = [];
$teams = [];
$error = '';

try {
    $years = cmp_visor_list_years();
    $imports = cmp_visor_list_imports([
        'year' => '',
        'team1' => '',
        'team2' => '',
        'id' => 0,
        'node_id' => 0,
        'only_linked' => '0',
    ]);
    $teams = cmp_visor_list_teams();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Visor de campeonatos', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
  .cmp-visor-match-group {
  margin: 10px 0 4px;
  font-size: 13px;
  color: #6b7280;
  font-weight: 600;
}
.cmp-visor-page { display:grid; gap:14px; }
.cmp-visor-head { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; }
.cmp-visor-head h1 { margin:0; }

.cmp-visor-filters {
  display:grid;
  grid-template-columns:minmax(120px,160px) minmax(260px,1fr) minmax(220px,320px);
  gap:10px;
  align-items:end;
}

.cmp-visor-status {
  border:1px solid #d9dde4;
  border-radius:12px;
  background:#fff;
  padding:10px 12px;
  color:#5e6877;
}

.cmp-visor-current {
  border:1px solid #d9dde4;
  border-radius:14px;
  background:#fff;
  padding:14px;
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  flex-wrap:wrap;
}

.cmp-visor-current h2 {
  margin:0;
  font-size:24px;
  line-height:1.15;
}

.cmp-visor-current-meta {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-top:8px;
}
.cmp-visor-filters label { display:grid; gap:4px; font-weight:600; }
.cmp-visor-filters input,
.cmp-visor-filters select { width:100%; min-width:0; box-sizing:border-box; }

.cmp-visor-empty-start {
  border:1px dashed #cfd6df;
  border-radius:14px;
  padding:28px 20px;
  background:#fafbfd;
  color:#5e6877;
}

.cmp-visor-import-strip {
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:10px;
}
.cmp-visor-import-card {
  display:grid;
  gap:8px;
  padding:12px;
  border:1px solid #d9dde4;
  border-radius:12px;
  background:#fff;
  text-align:left;
  cursor:pointer;
}
.cmp-visor-import-card.is-current {
  outline:2px solid #3366cc;
  background:#eef4ff;
}
.cmp-visor-import-title { font-weight:700; }
.cmp-visor-import-meta { display:flex; gap:8px; flex-wrap:wrap; }

.cmp-visor-structure {
  border:1px solid #d9dde4;
  border-radius:14px;
  background:#fff;
  padding:12px;
}
.cmp-visor-structure h3 { margin:0 0 10px 0; }

.cmp-visor-steps {
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:center;
}
.cmp-visor-step { display:inline-flex; align-items:center; gap:10px; }
.cmp-visor-step-box {
  border:1px solid #ccd5df;
  border-radius:999px;
  padding:8px 12px;
  background:#f8fafc;
  white-space:nowrap;
  cursor:pointer;
}
.cmp-visor-step-arrow { color:#6b7280; }

.cmp-visor-shell {
  display:grid;
  grid-template-columns:minmax(320px,34%) minmax(0,1fr);
  gap:12px;
  align-items:start;
}
.cmp-visor-panel {
  border:1px solid #d9dde4;
  border-radius:14px;
  background:#fff;
  padding:12px;
}
.cmp-visor-panel-head {
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
  margin-bottom:10px;
  flex-wrap:wrap;
}
.cmp-visor-panel h3 { margin:0; }

.cmp-visor-tree-tools {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:10px;
}

.cmp-visor-tree,
.cmp-visor-tree ul {
  list-style:none;
  margin:0;
  padding:0;
}
.cmp-visor-tree { display:grid; gap:6px; }
.cmp-visor-tree-item { display:grid; gap:6px; }
.cmp-visor-tree-row { display:flex; align-items:flex-start; gap:8px; }

.cmp-visor-tree-toggle,
.cmp-visor-tree-toggle-spacer {
  width:24px;
  min-width:24px;
  height:24px;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  margin-top:2px;
}
.cmp-visor-tree-toggle {
  border:1px solid #ccd5df;
  border-radius:7px;
  background:#fff;
  cursor:pointer;
}
.cmp-visor-tree-link {
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  text-decoration:none;
  color:inherit;
  padding:6px 8px;
  border-radius:10px;
  background:#f8fafc;
  width:100%;
}
.cmp-visor-tree-link.is-current {
  outline:2px solid #3366cc;
  background:#eef4ff;
}
.cmp-visor-tree-type {
  font-size:11px;
  text-transform:uppercase;
  color:#6b7280;
  min-width:86px;
}
.cmp-visor-tree-label { font-weight:600; }
.cmp-visor-tree-has-links {
  margin-left:auto;
  font-size:12px;
  color:#3366cc;
}

.cmp-visor-tree-children {
  margin-left:32px;
  display:grid;
  gap:6px;
}

.cmp-visor-node-section { display:grid; gap:8px; margin-bottom:14px; scroll-margin-top:90px; }
.cmp-visor-node-head {
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  flex-wrap:wrap;
}
.cmp-visor-node-head h3 { margin:0; }

.cmp-visor-node-title {
  font-size:30px;
  line-height:1.05;
  font-weight:700;
  margin:0;
}
.cmp-visor-node-subtitle {
  font-size:15px;
  color:#6b7280;
  margin-top:4px;
}
.cmp-visor-node-stats {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.cmp-visor-node-stats .cmp-chip {
  white-space:nowrap;
}

.cmp-visor-match-list { display:grid; gap:12px; }
.cmp-visor-match-card {
  border:1px solid #e1e6ed;
  border-radius:12px;
  padding:10px 12px;
  display:grid;
  gap:8px;
}
.cmp-visor-match-line {
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
  font-size:15px;
}
.cmp-visor-score {
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:52px;
  padding:4px 8px;
  border-radius:999px;
  background:#f1f5f9;
  font-weight:700;
}
.cmp-visor-match-meta { display:flex; gap:8px; flex-wrap:wrap; }
.cmp-visor-match-has-links {
  font-size:13px;
  color:#3366cc;
  font-weight:600;
}

.cmp-visor-only-linked {
  display:inline-flex;
  align-items:center;
  gap:6px;
  font-weight:500;
  font-size:13px;
}

.cmp-visor-linked-list { display:grid; gap:8px; }
.cmp-visor-linked-card {
  border:1px solid #edf1f5;
  border-radius:10px;
  background:#fafbfd;
  padding:10px;
}
.cmp-visor-linked-head {
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
.cmp-visor-linked-title { margin-top:4px; font-weight:600; }
.cmp-visor-linked-actions { margin-top:6px; }
.cmp-visor-muted, .cmp-visor-no-links { color:#6b7280; }

.cmp-visor-loading {
  padding:20px;
  border:1px solid #d9dde4;
  border-radius:12px;
  background:#fff;
}

.cmp-rel-hidden { display:none !important; }

@media (max-width: 1100px) {
  .cmp-visor-shell { grid-template-columns:1fr; }
  .cmp-visor-node-title { font-size:24px; }
}
@media (max-width: 780px) {
  .cmp-visor-filters { grid-template-columns:1fr; }
}

.cmp-visor-shell {
  margin-top: 0;
}

.cmp-visor-structure {
  margin-top: 12px;
  padding: 0;
  overflow: hidden;
}

.cmp-visor-structure summary {
  cursor: pointer;
  padding: 10px 12px;
  font-weight: 700;
}

.cmp-visor-structure .cmp-visor-steps {
  padding: 0 12px 12px;
}
</style>

<section class="cmp-wrap cmp-visor-page" id="cmpVisorPage">
  <div class="cmp-visor-head">
    <div>
      <p class="cmp-kicker">Campeonatos</p>
      <h1>Visor</h1>
      <div class="cmp-meta">Navegación cruzada por año y equipos</div>
    </div>

    <form id="cmpVisorFilters" class="cmp-visor-filters" onsubmit="return false;">
      <label>
        Año
        <select name="year" id="cmpVisorYear">
          <?= cmp_visor_render_year_options_html($years, '') ?>
        </select>
      </label>

      <label>
        Campeonato
        <select name="id" id="cmpVisorImport">
          <?= cmp_visor_render_import_options_html($imports, 0) ?>
        </select>
      </label>

      <label>
        Equipo
        <select name="team1" id="cmpVisorTeam1">
          <?= cmp_visor_render_team_options_html($teams, '') ?>
        </select>
      </label>
    </form>
  </div>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_visor_h($error) ?></div>
  <?php endif; ?>

  <section id="cmpVisorImportsWrap">
    <div class="cmp-visor-empty-start">
      Seleccioná un campeonato para ver la estructura. También podés acotar primero por año o equipo.
    </div>
  </section>

  <section id="cmpVisorShellWrap"></section>
</section>

<script>
(() => {
  const importsWrap = document.getElementById('cmpVisorImportsWrap');
  const shellWrap = document.getElementById('cmpVisorShellWrap');
  const yearInput = document.getElementById('cmpVisorYear');
  const importInput = document.getElementById('cmpVisorImport');
  const team1Input = document.getElementById('cmpVisorTeam1');

  let selectedImportId = parseInt(importInput.value || '0', 10) || 0;
  let selectedNodeId = 0;
  let refreshingFilters = false;

  function setImportsEmpty(message) {
    importsWrap.innerHTML = '<div class="cmp-visor-empty-start">' + (message || 'Seleccioná un campeonato para ver la estructura.') + '</div>';
  }

  function setImportsStatus(message) {
    importsWrap.innerHTML = '<div class="cmp-visor-status">' + message + '</div>';
  }

  function setShellEmpty() {
    shellWrap.innerHTML = '';
    selectedImportId = 0;
    selectedNodeId = 0;
  }

  function setLoading(target, msg = 'Cargando...') {
    target.innerHTML = '<div class="cmp-visor-loading">' + msg + '</div>';
  }

  async function postAjax(params) {
    const body = new URLSearchParams(params);
    const res = await fetch('campeonatos_visor_ajax.php', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    });

    const raw = await res.text();
    let json = null;
    try {
      json = JSON.parse(raw);
    } catch (_e) {
      throw new Error(raw || 'Respuesta inválida del servidor.');
    }
    return json;
  }

  async function refreshFilters(changedBy) {
    if (refreshingFilters) return;
    refreshingFilters = true;

    if (changedBy === 'year') {
      importInput.value = '';
      selectedImportId = 0;
    }

    if (changedBy === 'import') {
      selectedImportId = parseInt(importInput.value || '0', 10) || 0;
    }

    const previousImportId = selectedImportId;

    try {
      const json = await postAjax({
        action: 'filters',
        year: yearInput.value || '',
        id: String(selectedImportId || 0),
        team1: team1Input.value || ''
      });

      if (!json.ok) {
        setImportsEmpty(json.error || 'Error actualizando filtros.');
        setShellEmpty();
        return;
      }

      yearInput.innerHTML = json.years_html || '<option value="">Todos</option>';
      importInput.innerHTML = json.imports_html || '<option value="">Seleccionar…</option>';
      team1Input.innerHTML = json.teams_html || '<option value="">Todos</option>';

      yearInput.value = json.selected_year || '';
      importInput.value = String(json.selected_id || '');
      team1Input.value = json.selected_team1 || '';

      selectedImportId = parseInt(json.selected_id || '0', 10) || 0;

      if (selectedImportId > 0) {
        importsWrap.innerHTML = '';
        await loadChampionship(selectedImportId);
      } else {
        setShellEmpty();

        if ((team1Input.value || '') !== '' || (yearInput.value || '') !== '') {
          setImportsStatus('Seleccioná un campeonato de la lista para ver la estructura.');
        } else {
          setImportsEmpty('Seleccioná un campeonato para ver la estructura. También podés acotar primero por año o equipo.');
        }
      }
    } catch (err) {
      setImportsEmpty(err && err.message ? err.message : 'Error actualizando filtros.');
      setShellEmpty();
    } finally {
      refreshingFilters = false;
    }
  }

  async function loadChampionship(importId) {
    selectedImportId = parseInt(importId || 0, 10) || 0;
    selectedNodeId = 0;

    if (!selectedImportId) {
      setShellEmpty();
      return;
    }

    setLoading(shellWrap, 'Cargando campeonato...');

    try {
      const json = await postAjax({
        action: 'championship',
        id: String(selectedImportId),
        team1: team1Input.value || ''
      });

      if (!json.ok) {
        shellWrap.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (json.error || 'Error') + '</div>';
        return;
      }

      shellWrap.innerHTML = json.shell_html || '';
      bindShellInteractions();
    } catch (err) {
      shellWrap.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (err && err.message ? err.message : 'Error cargando el campeonato.') + '</div>';
    }
  }

  async function loadNodeDetail(nodeId) {
    if (!selectedImportId || !nodeId) return;
    selectedNodeId = parseInt(nodeId, 10) || 0;

    const rightPanel = shellWrap.querySelector('.cmp-visor-shell .cmp-visor-panel:last-child');
    if (!rightPanel) return;

    rightPanel.innerHTML = '<div class="cmp-visor-loading">Cargando nodo...</div>';

    try {
      const onlyLinkedInput = document.getElementById('cmpVisorOnlyLinked');

      const json = await postAjax({
        action: 'node_detail',
        id: String(selectedImportId),
        node_id: String(nodeId),
        team1: team1Input.value || '',
        only_linked: onlyLinkedInput && onlyLinkedInput.checked ? '1' : '0'
      });

      if (!json.ok) {
        rightPanel.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (json.error || 'Error') + '</div>';
        return;
      }

      rightPanel.outerHTML = json.detail_html || '<section class="cmp-visor-panel"><div class="cmp-empty">Sin contenido.</div></section>';
      bindNodeSelectionState(nodeId);
      bindOnlyLinkedFilter();
      bindMoveMatchButtons();
    } catch (err) {
      rightPanel.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (err && err.message ? err.message : 'Error cargando el nodo.') + '</div>';
    }
  }

  function bindTreeToggles() {
    document.querySelectorAll('[data-tree-toggle]').forEach(btn => {
      btn.addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();

        const targetId = btn.getAttribute('data-target');
        if (!targetId) return;

        const box = document.getElementById(targetId);
        if (!box) return;

        const expanded = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        btn.textContent = expanded ? '+' : '−';
        box.classList.toggle('cmp-rel-hidden', expanded);
      });
    });

    document.querySelectorAll('[data-expand-all]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-tree-toggle]').forEach(toggle => {
          const targetId = toggle.getAttribute('data-target');
          const box = targetId ? document.getElementById(targetId) : null;
          toggle.setAttribute('aria-expanded', 'true');
          toggle.textContent = '−';
          if (box) box.classList.remove('cmp-rel-hidden');
        });
      });
    });

    document.querySelectorAll('[data-collapse-all]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('[data-tree-toggle]').forEach(toggle => {
          const targetId = toggle.getAttribute('data-target');
          const box = targetId ? document.getElementById(targetId) : null;
          toggle.setAttribute('aria-expanded', 'false');
          toggle.textContent = '+';
          if (box) box.classList.add('cmp-rel-hidden');
        });
      });
    });
  }

  function bindNodeSelectionState(nodeId) {
    document.querySelectorAll('[data-node-link]').forEach(link => {
      const id = parseInt(link.getAttribute('data-node-id') || '0', 10);
      link.classList.toggle('is-current', id === nodeId);
    });
  }

  function bindNodeLinks() {
    document.querySelectorAll('[data-node-link]').forEach(link => {
      link.addEventListener('click', (ev) => {
        ev.preventDefault();
        const nodeId = parseInt(link.getAttribute('data-node-id') || '0', 10);
        if (!nodeId) return;
        loadNodeDetail(nodeId);
      });
    });
  }

  function bindStageAnchors() {
    document.querySelectorAll('[data-stage-node-id]').forEach(btn => {
      btn.addEventListener('click', () => {
        const nodeId = parseInt(btn.getAttribute('data-stage-node-id') || '0', 10);
        const targetAnchor = btn.getAttribute('data-stage-target') || '';

        if (targetAnchor) {
          const target = document.getElementById(targetAnchor);
          if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }

        if (nodeId) {
          loadNodeDetail(nodeId);
        }
      });
    });
  }

  function bindOnlyLinkedFilter() {
    const input = document.getElementById('cmpVisorOnlyLinked');
    if (!input) return;

    input.addEventListener('change', () => {
      if (selectedNodeId) {
        loadNodeDetail(selectedNodeId);
      }
    });
  }

  function bindShellInteractions() {
    bindTreeToggles();
    bindNodeLinks();
    bindStageAnchors();
    bindOnlyLinkedFilter();
    bindMoveMatchButtons();
  }

    function bindMoveMatchButtons() {
    document.querySelectorAll('[data-move-match]').forEach(btn => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';

      btn.addEventListener('click', async () => {
        const matchId = btn.getAttribute('data-move-match');
        const box = document.getElementById('cmpMoveBox' + matchId);
        if (!box) return;

        box.classList.toggle('cmp-rel-hidden');
        if (box.dataset.loaded === '1') return;

        const json = await postAjax({
          action: 'move_targets',
          id: String(selectedImportId)
        });

        const selectImport = box.querySelector('[data-move-import="' + matchId + '"]');
        const status = box.querySelector('[data-move-status="' + matchId + '"]');

        if (!json.ok) {
          status.textContent = json.error || 'Error cargando campeonatos.';
          status.style.color = '#b91c1c';
          return;
        }

        const imports = Array.isArray(json.imports) ? json.imports : [];
        selectImport.innerHTML = '<option value="">Seleccionar…</option>' + imports.map(row => {
          const season = row.temporada_detectada ? ' (' + row.temporada_detectada + ')' : '';
          return '<option value="' + row.id + '">' + String(row.titulo_fuente || '') + season + '</option>';
        }).join('');

        box.dataset.loaded = '1';
      });
    });

    document.querySelectorAll('[data-move-import]').forEach(select => {
      if (select.dataset.bound === '1') return;
      select.dataset.bound = '1';

      select.addEventListener('change', async () => {
        const matchId = select.getAttribute('data-move-import');
        const targetImportId = select.value;
        const box = document.getElementById('cmpMoveBox' + matchId);
        if (!box) return;

        const nodeSelect = box.querySelector('[data-move-node="' + matchId + '"]');
        const status = box.querySelector('[data-move-status="' + matchId + '"]');
        nodeSelect.innerHTML = '<option value="">Cargando…</option>';

        if (!targetImportId) {
          nodeSelect.innerHTML = '<option value="">Seleccionar…</option>';
          return;
        }

        const json = await postAjax({
          action: 'move_nodes',
          target_import_id: targetImportId
        });

        if (!json.ok) {
          nodeSelect.innerHTML = '<option value="">Seleccionar…</option>';
          status.textContent = json.error || 'Error cargando nodos.';
          status.style.color = '#b91c1c';
          return;
        }

        const nodes = Array.isArray(json.nodes) ? json.nodes : [];
        nodeSelect.innerHTML = '<option value="">Seleccionar…</option>' + nodes.map(row => {
          return '<option value="' + row.id + '">' + String(row.label || '') + '</option>';
        }).join('');
      });
    });

    document.querySelectorAll('[data-move-confirm]').forEach(btn => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';

      btn.addEventListener('click', async () => {
        const matchId = btn.getAttribute('data-move-confirm');
        const box = document.getElementById('cmpMoveBox' + matchId);
        if (!box) return;

        const importSelect = box.querySelector('[data-move-import="' + matchId + '"]');
        const nodeSelect = box.querySelector('[data-move-node="' + matchId + '"]');
        const status = box.querySelector('[data-move-status="' + matchId + '"]');

        if (!importSelect.value || !nodeSelect.value) {
          status.textContent = 'Elegí campeonato y fecha destino.';
          status.style.color = '#b91c1c';
          return;
        }

        status.textContent = 'Moviendo…';
        status.style.color = '';

        const json = await postAjax({
          action: 'move_match',
          id: String(selectedImportId),
          match_id: String(matchId),
          target_import_id: String(importSelect.value),
          target_node_id: String(nodeSelect.value)
        });

        if (!json.ok) {
          status.textContent = json.error || 'Error moviendo el partido.';
          status.style.color = '#b91c1c';
          return;
        }

        status.textContent = json.message || 'Partido movido.';
        status.style.color = '#0f766e';

        setTimeout(() => {
          loadChampionship(selectedImportId);
        }, 500);
      });
    });
  }

  yearInput.addEventListener('change', () => refreshFilters('year'));
  importInput.addEventListener('change', () => refreshFilters('import'));
  team1Input.addEventListener('change', () => refreshFilters('team'));

  if (selectedImportId > 0) {
    loadChampionship(selectedImportId);
  }
})();
</script>

<?php cmp_render_footer(); ?>