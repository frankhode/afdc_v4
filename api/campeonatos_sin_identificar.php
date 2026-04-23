<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_sin_identificar_repo.php';

cmp_render_header('Sin identificar', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
  .cmp-si-page { display:grid; gap:12px; }
  .cmp-si-head { display:grid; gap:4px; }
  .cmp-si-head h1 { margin:0; }
  .cmp-si-head .cmp-meta { margin:0; }

  .cmp-si-search-card,
  .cmp-si-results-card {
    border:1px solid #d9dde4;
    border-radius:14px;
    background:#fff;
    padding:12px;
  }

  .cmp-si-search-row {
    display:grid;
    grid-template-columns:minmax(320px, 1fr) auto;
    gap:8px;
    align-items:end;
  }

  .cmp-si-field {
    position:relative;
  }

  .cmp-si-field label {
    display:grid;
    gap:4px;
    font-weight:600;
  }

  .cmp-si-field input,
  .cmp-si-field textarea,
  .cmp-si-search-row button {
    width:100%;
    box-sizing:border-box;
  }

  .cmp-si-registros-list {
    margin-top:8px;
    display:grid;
    gap:6px;
  }

  .cmp-si-reg-item {
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    padding:8px 10px;
    background:#fafbfd;
  }

  .cmp-si-reg-meta {
    color:#6b7280;
    font-size:13px;
  }

  .cmp-si-current {
    margin-top:8px;
    padding:8px 10px;
    border-radius:10px;
    background:#eef4ff;
    border:1px solid #c9d8ff;
  }

  .cmp-si-toolbar {
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    margin-bottom:8px;
  }

  .cmp-si-grid {
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px;
  }

  .cmp-si-row {
    border:1px solid #e5e7eb;
    border-radius:12px;
    padding:10px;
    background:#fafbfd;
    display:grid;
    gap:8px;
    align-content:start;
  }

  .cmp-si-row-head {
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-start;
  }

  .cmp-si-row-title {
    display:grid;
    gap:2px;
    min-width:0;
  }

  .cmp-si-row-title h3 {
    margin:0;
    font-size:15px;
    line-height:1.2;
  }

  .cmp-si-row-title > div {
    line-height:1.25;
  }

  .cmp-si-row-meta {
    color:#6b7280;
    font-size:12px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  .cmp-si-row-actions {
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }

  .cmp-si-form-grid {
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
  }

  .cmp-si-form-grid .is-full {
    grid-column:1 / -1;
  }

  .cmp-si-empty {
    padding:12px;
    border:1px dashed #cfd6df;
    border-radius:12px;
    background:#fafbfd;
    color:#6b7280;
  }

  .cmp-si-status {
    font-size:13px;
    font-weight:600;
  }

  .cmp-si-status.ok { color:#0f766e; }
  .cmp-si-status.error { color:#b91c1c; }

  .cmp-si-suggest {
    position:absolute;
    left:0;
    right:0;
    top:100%;
    z-index:20;
    margin-top:2px;
    border:1px solid #d9dde4;
    border-radius:10px;
    background:#fff;
    box-shadow:0 8px 22px rgba(0,0,0,.08);
    max-height:220px;
    overflow:auto;
    display:none;
  }

  .cmp-si-suggest.is-open {
    display:block;
  }

  .cmp-si-suggest-item {
    padding:8px 10px;
    cursor:pointer;
    border-bottom:1px solid #eef2f7;
  }

  .cmp-si-suggest-item:last-child {
    border-bottom:none;
  }

  .cmp-si-suggest-item:hover,
  .cmp-si-suggest-item.is-active {
    background:#eef4ff;
  }

  .cmp-si-suggest-name {
    font-weight:600;
  }

  .cmp-si-suggest-meta {
    color:#6b7280;
    font-size:12px;
  }

  .cmp-si-selected-chip {
    font-size:12px;
    color:#0f766e;
    font-weight:600;
  }

  @media (max-width: 980px) {
    .cmp-si-grid {
      grid-template-columns:1fr;
    }
  }

  @media (max-width: 900px) {
    .cmp-si-search-row,
    .cmp-si-form-grid {
      grid-template-columns:1fr;
    }
  }
</style>

<section class="cmp-wrap cmp-si-page">
  <div class="cmp-si-head">
    <p class="cmp-kicker">Campeonatos</p>
    <h1>Sin identificar</h1>
    <div class="cmp-meta">Carga manual de partidos desde registros del equipo, con vínculo automático al sobre.</div>
  </div>

  <section class="cmp-si-search-card">
    <div class="cmp-si-search-row">
      <div class="cmp-si-field">
        <label>
          Buscar registro / títuloReg
          <input type="text" id="cmpSiQuery" placeholder="Ej. [Independiente. Club]">
        </label>
      </div>
      <button type="button" class="cmp-btn" id="cmpSiSearchBtn">Buscar</button>
    </div>

    <div id="cmpSiCurrent" class="cmp-si-current" style="display:none;"></div>
    <div id="cmpSiRegistros" class="cmp-si-registros-list"></div>
  </section>

  <section class="cmp-si-results-card">
    <div class="cmp-si-toolbar">
      <h2 style="margin:0;">Sobres pendientes para cargar</h2>
      <div class="cmp-si-muted" id="cmpSiCount"></div>
    </div>
    <div id="cmpSiSobres" class="cmp-si-empty">Elegí un registro para listar sobres no cargados en Sin identificar.</div>
  </section>
</section>

<script>
(() => {
  const qInput = document.getElementById('cmpSiQuery');
  const searchBtn = document.getElementById('cmpSiSearchBtn');
  const registrosWrap = document.getElementById('cmpSiRegistros');
  const currentWrap = document.getElementById('cmpSiCurrent');
  const sobresWrap = document.getElementById('cmpSiSobres');
  const countWrap = document.getElementById('cmpSiCount');

  let currentTituloReg = '';

  async function postAjax(params) {
    const body = new URLSearchParams(params);
    const res = await fetch('campeonatos_sin_identificar_ajax.php', {
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
      throw new Error(raw || 'Respuesta inválida.');
    }
    return json;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function searchRegistros() {
    registrosWrap.innerHTML = '<div class="cmp-si-empty">Buscando…</div>';
    currentWrap.style.display = 'none';
    sobresWrap.innerHTML = '<div class="cmp-si-empty">Elegí un registro para listar sobres no cargados en Sin identificar.</div>';
    countWrap.textContent = '';
    currentTituloReg = '';

    const json = await postAjax({
      action: 'search_registros',
      q: qInput.value || ''
    });

    if (!json.ok) {
      registrosWrap.innerHTML = '<div class="cmp-si-empty">' + escapeHtml(json.error || 'Error buscando registros.') + '</div>';
      return;
    }

    const rows = Array.isArray(json.rows) ? json.rows : [];
    if (rows.length === 0) {
      registrosWrap.innerHTML = '<div class="cmp-si-empty">No hubo resultados.</div>';
      return;
    }

    registrosWrap.innerHTML = rows.map(row => {
      const tituloReg = String(row.tituloReg || '');
      const count = parseInt(row.sobres_count || 0, 10);
      return `
        <div class="cmp-si-reg-item">
          <div>
            <div><strong>${escapeHtml(tituloReg)}</strong></div>
            <div class="cmp-si-reg-meta">sobres: ${count}</div>
          </div>
          <button type="button" class="cmp-btn cmp-btn-sm" data-tituloreg="${escapeHtml(tituloReg)}">Elegir</button>
        </div>
      `;
    }).join('');

    registrosWrap.querySelectorAll('[data-tituloreg]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tituloReg = btn.getAttribute('data-tituloreg') || '';
        loadSobres(tituloReg);
      });
    });
  }

  async function loadSobres(tituloReg) {
    currentTituloReg = tituloReg;
    currentWrap.style.display = '';
    currentWrap.innerHTML = '<strong>Registro seleccionado:</strong> ' + escapeHtml(tituloReg);

    sobresWrap.innerHTML = '<div class="cmp-si-empty">Cargando sobres…</div>';
    countWrap.textContent = '';

    const json = await postAjax({
      action: 'list_sobres',
      tituloReg: tituloReg
    });

    if (!json.ok) {
      sobresWrap.innerHTML = '<div class="cmp-si-empty">' + escapeHtml(json.error || 'Error cargando sobres.') + '</div>';
      return;
    }

    const sobres = Array.isArray(json.sobres) ? json.sobres : [];
    countWrap.textContent = sobres.length + ' pendiente(s)';

    if (sobres.length === 0) {
      sobresWrap.innerHTML = '<div class="cmp-si-empty">No hay sobres pendientes para este registro. Ya fueron cargados o no hay datos.</div>';
      return;
    }

    sobresWrap.innerHTML = '<div class="cmp-si-grid">' + sobres.map(row => {
      const barcode = String(row.barcode || '');
      const titulo = String(row.titulo || '');
      const fecha = String(row.fecha || '');
      const equipo1 = String(row.equipo1 || '');
      const equipo2 = String(row.equipo2 || '');
      const cancha = String(row.cancha || '');

      return `
        <article class="cmp-si-row" data-row data-barcode="${escapeHtml(barcode)}">
          <div class="cmp-si-row-head">
            <div class="cmp-si-row-title">
              <h3>${escapeHtml(barcode)}</h3>
              <div>${escapeHtml(titulo)}</div>
              <div class="cmp-si-row-meta">
                ${fecha ? `<span>fecha: ${escapeHtml(fecha)}</span>` : ''}
                ${cancha ? `<span>cancha: ${escapeHtml(cancha)}</span>` : ''}
                <span>${equipo1 ? escapeHtml(equipo1) : '—'} vs ${equipo2 ? escapeHtml(equipo2) : '—'}</span>
              </div>
            </div>
            <div class="cmp-si-row-actions">
              <a class="cmp-btn cmp-btn-sm" href="../ver_digital.php?barcode=${encodeURIComponent(barcode)}&i=0" target="_blank" rel="noopener">Ver digital</a>
            </div>
          </div>

          <div class="cmp-si-form-grid">
            <div class="cmp-si-field">
              <label>
                Local
                <input type="text" data-local-text value="${escapeHtml(equipo1)}">
              </label>
            </div>

            <div class="cmp-si-field">
              <label>
                Visitante
                <input type="text" data-visitante-text value="${escapeHtml(equipo2)}">
              </label>
            </div>

            <div class="cmp-si-field">
              <label>
                Entidad local
                <input type="text" data-entidad-search="local" placeholder="Escribí para buscar...">
                <input type="hidden" data-entidad-id="local" value="">
                <div class="cmp-si-selected-chip" data-entidad-chip="local"></div>
                <div class="cmp-si-suggest" data-entidad-suggest="local"></div>
              </label>
            </div>

            <div class="cmp-si-field">
              <label>
                Entidad visitante
                <input type="text" data-entidad-search="visitante" placeholder="Escribí para buscar...">
                <input type="hidden" data-entidad-id="visitante" value="">
                <div class="cmp-si-selected-chip" data-entidad-chip="visitante"></div>
                <div class="cmp-si-suggest" data-entidad-suggest="visitante"></div>
              </label>
            </div>

            <div class="cmp-si-field is-full">
              <label>
                Observación
                <textarea data-observacion rows="2" placeholder="Opcional"></textarea>
              </label>
            </div>
          </div>

          <div class="cmp-si-row-actions">
            <button type="button" class="cmp-btn" data-save>Agregar a Sin identificar</button>
            <span class="cmp-si-status" data-status></span>
          </div>

          <input type="hidden" data-titulo-sobre value="${escapeHtml(titulo)}">
          <input type="hidden" data-fecha-texto value="${escapeHtml(fecha)}">
        </article>
      `;
    }).join('') + '</div>';

    bindRowActions();
  }

  async function searchEntities(query) {
    const json = await postAjax({
      action: 'search_entities',
      q: query || ''
    });

    if (!json.ok) {
      return [];
    }

    return Array.isArray(json.rows) ? json.rows : [];
  }

  function bindAutocomplete(row, side) {
    const input = row.querySelector(`[data-entidad-search="${side}"]`);
    const hidden = row.querySelector(`[data-entidad-id="${side}"]`);
    const chip = row.querySelector(`[data-entidad-chip="${side}"]`);
    const suggest = row.querySelector(`[data-entidad-suggest="${side}"]`);

    let activeIndex = -1;
    let currentItems = [];

    function closeSuggest() {
      suggest.classList.remove('is-open');
      suggest.innerHTML = '';
      activeIndex = -1;
      currentItems = [];
    }

    function setSelected(entity) {
      if (!entity) {
        hidden.value = '';
        chip.textContent = '';
        return;
      }
      hidden.value = String(entity.id || '');
      chip.textContent = 'Seleccionado: ' + String(entity.nombre_mostrable || '');
      input.value = String(entity.nombre_mostrable || '');
      closeSuggest();
    }

    function renderSuggest(items) {
      currentItems = items.slice(0, 20);
      activeIndex = -1;

      if (currentItems.length === 0) {
        closeSuggest();
        return;
      }

      suggest.innerHTML = currentItems.map((item, idx) => `
        <div class="cmp-si-suggest-item" data-idx="${idx}">
          <div class="cmp-si-suggest-name">${escapeHtml(item.nombre_mostrable || '')}</div>
          <div class="cmp-si-suggest-meta">${escapeHtml(item.nombre_oficial || '')}</div>
        </div>
      `).join('');

      suggest.classList.add('is-open');

      suggest.querySelectorAll('[data-idx]').forEach(el => {
        el.addEventListener('mousedown', ev => {
          ev.preventDefault();
          const idx = parseInt(el.getAttribute('data-idx') || '-1', 10);
          if (idx >= 0 && currentItems[idx]) {
            setSelected(currentItems[idx]);
          }
        });
      });
    }

    async function triggerSearch() {
      const q = input.value.trim();
      hidden.value = '';
      chip.textContent = '';

      if (q.length < 2) {
        closeSuggest();
        return;
      }

      const items = await searchEntities(q);
      renderSuggest(items);
    }

    input.addEventListener('input', () => {
      triggerSearch();
    });

    input.addEventListener('keydown', ev => {
      if (!suggest.classList.contains('is-open')) {
        return;
      }

      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        activeIndex = Math.min(activeIndex + 1, currentItems.length - 1);
      } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
      } else if (ev.key === 'Enter') {
        if (activeIndex >= 0 && currentItems[activeIndex]) {
          ev.preventDefault();
          setSelected(currentItems[activeIndex]);
        }
      } else if (ev.key === 'Escape') {
        closeSuggest();
      }

      suggest.querySelectorAll('.cmp-si-suggest-item').forEach((el, idx) => {
        el.classList.toggle('is-active', idx === activeIndex);
      });
    });

    input.addEventListener('blur', () => {
      setTimeout(closeSuggest, 150);
    });
  }

  function bindRowActions() {
    sobresWrap.querySelectorAll('[data-row]').forEach(row => {
      bindAutocomplete(row, 'local');
      bindAutocomplete(row, 'visitante');

      const localInput = row.querySelector('[data-local-text]');
      const visitInput = row.querySelector('[data-visitante-text]');
      const localSearch = row.querySelector('[data-entidad-search="local"]');
      const visitSearch = row.querySelector('[data-entidad-search="visitante"]');
      const localId = row.querySelector('[data-entidad-id="local"]');
      const visitId = row.querySelector('[data-entidad-id="visitante"]');
      const saveBtn = row.querySelector('[data-save]');
      const status = row.querySelector('[data-status]');

      if (localInput.value.trim() !== '') {
        localSearch.value = localInput.value.trim();
      }
      if (visitInput.value.trim() !== '') {
        visitSearch.value = visitInput.value.trim();
      }

      saveBtn.addEventListener('click', async () => {
        status.textContent = 'Guardando…';
        status.className = 'cmp-si-status';

        const barcode = row.getAttribute('data-barcode') || '';
        const tituloSobre = row.querySelector('[data-titulo-sobre]')?.value || '';
        const fechaTexto = row.querySelector('[data-fecha-texto]')?.value || '';
        const observacion = row.querySelector('[data-observacion]')?.value || '';

        try {
          const json = await postAjax({
            action: 'create_match',
            tituloReg: currentTituloReg,
            barcode: barcode,
            tituloSobre: tituloSobre,
            fecha_texto: fechaTexto,
            local_texto: localInput.value || '',
            visitante_texto: visitInput.value || '',
            local_entidad_id: localId.value || '',
            visitante_entidad_id: visitId.value || '',
            observacion_manual: observacion || ''
          });

          if (!json.ok) {
            status.textContent = json.error || 'Error guardando.';
            status.className = 'cmp-si-status error';
            return;
          }

          status.textContent = json.message || 'Cargado.';
          status.className = 'cmp-si-status ok';
          row.style.opacity = '0.55';
          saveBtn.disabled = true;

          setTimeout(() => {
            row.remove();
            const remaining = sobresWrap.querySelectorAll('[data-row]').length;
            countWrap.textContent = remaining + ' pendiente(s)';
            if (remaining === 0) {
              sobresWrap.innerHTML = '<div class="cmp-si-empty">No quedan sobres pendientes para este registro.</div>';
            }
          }, 500);
        } catch (err) {
          status.textContent = (err && err.message) ? err.message : 'Error guardando.';
          status.className = 'cmp-si-status error';
        }
      });
    });
  }

  searchBtn.addEventListener('click', searchRegistros);
  qInput.addEventListener('keydown', ev => {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      searchRegistros();
    }
  });
})();
</script>

<?php cmp_render_footer(); ?>