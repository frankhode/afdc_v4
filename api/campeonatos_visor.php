<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_visor_repo.php';

$years = [];
$teams = [];
$error = '';

try {
    $years = cmp_visor_list_years();
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
  grid-template-columns:minmax(140px,180px) minmax(220px,1fr);
  gap:10px;
  align-items:end;
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
          <option value="">Todos</option>
          <?php foreach ($years as $year): ?>
            <option value="<?= cmp_visor_h((string)$year) ?>"><?= cmp_visor_h((string)$year) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        Equipo 1
        <select name="team1" id="cmpVisorTeam1">
          <option value="">Todos</option>
          <?php foreach ($teams as $team): ?>
            <option value="<?= cmp_visor_h((string)$team) ?>"><?= cmp_visor_h((string)$team) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </div>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_visor_h($error) ?></div>
  <?php endif; ?>

  <section id="cmpVisorImportsWrap">
    <div class="cmp-visor-empty-start">
      Seleccioná un año o un equipo para empezar.
    </div>
  </section>

  <section id="cmpVisorShellWrap"></section>
</section>

<script>
(() => {
  const importsWrap = document.getElementById('cmpVisorImportsWrap');
  const shellWrap = document.getElementById('cmpVisorShellWrap');
  const yearInput = document.getElementById('cmpVisorYear');
  const team1Input = document.getElementById('cmpVisorTeam1');

  let selectedImportId = 0;
  let selectedNodeId = 0;

  function hasGlobalFilters() {
    return (yearInput.value || '').trim() !== '' || (team1Input.value || '').trim() !== '';
  }

  function setImportsEmpty() {
    importsWrap.innerHTML = '<div class="cmp-visor-empty-start">Seleccioná un año o un equipo para empezar.</div>';
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

  async function loadChampionships() {
    if (!hasGlobalFilters()) {
      setImportsEmpty();
      setShellEmpty();
      return;
    }

    setLoading(importsWrap, 'Buscando campeonatos...');
    setShellEmpty();

    try {
      const json = await postAjax({
        action: 'search',
        year: yearInput.value || '',
        team1: team1Input.value || ''
      });

      if (!json.ok) {
        importsWrap.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (json.error || 'Error') + '</div>';
        return;
      }

      importsWrap.innerHTML = json.championships_html || '<div class="cmp-empty">Sin resultados.</div>';
      bindImportCards();
    } catch (err) {
      importsWrap.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (err && err.message ? err.message : 'Error buscando campeonatos.') + '</div>';
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
      markCurrentImport();
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
    } catch (err) {
      rightPanel.innerHTML = '<div class="cmp-alert cmp-alert-error">' + (err && err.message ? err.message : 'Error cargando el nodo.') + '</div>';
    }
  }

  function markCurrentImport() {
    document.querySelectorAll('[data-import-id]').forEach(el => {
      const id = parseInt(el.getAttribute('data-import-id') || '0', 10);
      el.classList.toggle('is-current', id === selectedImportId);
    });
  }

  function bindImportCards() {
    document.querySelectorAll('[data-import-id]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = parseInt(btn.getAttribute('data-import-id') || '0', 10);
        if (!id) return;
        loadChampionship(id);
      });
    });
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
  }

  yearInput.addEventListener('change', loadChampionships);
  team1Input.addEventListener('change', loadChampionships);
})();
</script>

<?php cmp_render_footer(); ?>