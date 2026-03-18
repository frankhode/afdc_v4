/* AFDC Table - client engine (sorting, filtering, remote refine)
 *
 * Soporta 3 modos:
 *  - Filtro cliente (solo la página visible)
 *  - Filtro remoto por reload (agrega ?filter=... y recarga)
 *  - Filtro remoto por AJAX (POST a endpoint; reemplaza [data-afdc-results])
 *
 * También soporta herramientas externas (compactas) usando:
 *   data-afdc-tools="#idDeTools"
 * en el wrapper [data-afdc-table]
 */

(function(){
  'use strict';

  function ready(fn){
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  function debounce(fn, ms){
    let t = null;
    return function(){
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(null, args), ms);
    };
  }

  function escapeCsv(s){
    const str = String(s ?? '');
    if (/[",\r\n]/.test(str)) return '"' + str.replace(/"/g,'""') + '"';
    return str;
  }

  function compareFactory(type, idx, dir){
    const mul = (dir === 'desc') ? -1 : 1;
    return function(a, b){
      const ta = (a.cells[idx]?.innerText || '').trim();
      const tb = (b.cells[idx]?.innerText || '').trim();

      if (type === 'num') {
        const na = parseFloat(ta.replace(/[^0-9.-]/g,'')) || 0;
        const nb = parseFloat(tb.replace(/[^0-9.-]/g,'')) || 0;
        return (na - nb) * mul;
      }
      if (type === 'date') {
        const da = Date.parse(ta) || 0;
        const db = Date.parse(tb) || 0;
        return (da - db) * mul;
      }
      return ta.localeCompare(tb, 'es', {sensitivity:'base'}) * mul;
    };
  }

  function getToolRoot(wrap){
    const sel = wrap.getAttribute('data-afdc-tools');
    if (sel) {
      const el = document.querySelector(sel);
      if (el) return el;
    }
    return wrap;
  }

  async function postJson(endpoint, payload){
    const r = await fetch(endpoint, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const j = await r.json().catch(() => null);
    if (!j || j.ok !== true) throw new Error((j && j.error) ? j.error : 'Error');
    return j;
  }

  function attachTableModule(wrap){
    if (!wrap) return;

    const toolRoot = getToolRoot(wrap);

    // Si las herramientas son externas, las bindeamos una sola vez y vamos
    // actualizando a qué "wrap" apuntan.
    const isExternalTools = toolRoot !== wrap;
    if (isExternalTools) {
      toolRoot.__afdcWrap = wrap;
    }

    const table = wrap.querySelector('table[data-afdc-target]') || wrap.querySelector('table');
    const tbody = table ? (table.tBodies[0] || table.querySelector('tbody')) : null;
    if (!table || !tbody) return;

    // ------------------------------------------------------------
    // Sorting (siempre por-wrap, porque el table cambia con AJAX)
    // ------------------------------------------------------------
    const ths = Array.from(table.querySelectorAll('thead th'));
    let sortedIdx = -1;
    let sortedDir = 'asc';

    function clearIndicators(){
      ths.forEach(th => {
        th.classList.remove('afdc-sorted');
        const ind = th.querySelector('.afdc-sort-ind');
        if (ind) ind.textContent = '';
      });
    }

    ths.forEach((th, idx) => {
      const type = th.getAttribute('data-sort');
      if (!type) return;

      let ind = th.querySelector('.afdc-sort-ind');
      if (!ind){
        ind = document.createElement('span');
        ind.className = 'afdc-sort-ind';
        th.appendChild(ind);
      }

      th.addEventListener('click', () => {
        const rows = Array.from(tbody.rows);
        if (sortedIdx === idx){
          sortedDir = (sortedDir === 'asc') ? 'desc' : 'asc';
        } else {
          sortedIdx = idx;
          sortedDir = 'asc';
        }

        rows.sort(compareFactory(type, idx, sortedDir));
        const frag = document.createDocumentFragment();
        rows.forEach(r => frag.appendChild(r));
        tbody.appendChild(frag);

        clearIndicators();
        th.classList.add('afdc-sorted');
        ind.textContent = (sortedDir === 'asc') ? '▲' : '▼';
      });
    });

    const defTh = ths.find(th => th.hasAttribute('data-afdc-default'));
    if (defTh) {
      const idx = ths.indexOf(defTh);
      const type = defTh.getAttribute('data-sort') || 'text';
      sortedIdx = idx;
      sortedDir = (defTh.getAttribute('data-afdc-default') === 'desc') ? 'desc' : 'asc';

      const rows = Array.from(tbody.rows);
      rows.sort(compareFactory(type, idx, sortedDir));

      const frag = document.createDocumentFragment();
      rows.forEach(r => frag.appendChild(r));
      tbody.appendChild(frag);

      clearIndicators();
      defTh.classList.add('afdc-sorted');
      const ind = defTh.querySelector('.afdc-sort-ind');
      if (ind) ind.textContent = (sortedDir === 'asc') ? '▲' : '▼';
    }

    // ------------------------------------------------------------
    // Tools / Filtering
    // ------------------------------------------------------------
    const filterInput = toolRoot.querySelector('[data-afdc-filter]');
    const btnApply   = toolRoot.querySelector('[data-afdc-apply]');
    const btnClear   = toolRoot.querySelector('[data-afdc-clear]');
    const btnCsv     = toolRoot.querySelector('[data-afdc-csv]');
    const chkAuto    = toolRoot.querySelector('[data-afdc-auto]');
    const statusEl   = toolRoot.querySelector('[data-afdc-status]');

    const remote = filterInput ? filterInput.hasAttribute('data-afdc-remote') : false;
    const isAjax = filterInput ? filterInput.hasAttribute('data-afdc-remote-filter') : false;

    const resultsBlock = wrap.closest('[data-afdc-results]');

    function setStatus(msg){
      if (!statusEl) return;
      statusEl.textContent = msg || '';
    }

    function applyClientFilter(){
      if (!filterInput) return;
      const q = (filterInput.value || '').trim().toLowerCase();
      const rows = Array.from(tbody.rows);
      let shown = 0;
      rows.forEach(tr => {
        const t = (tr.innerText || '').toLowerCase();
        const ok = q === '' ? true : t.includes(q);
        tr.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      setStatus(q ? (shown + ' filas') : '');
    }

    async function runAjax(page){
      if (!filterInput || !resultsBlock) return;

      const endpoint = wrap.getAttribute('data-afdc-endpoint') || 'api/refinar.php';
      const param = filterInput.getAttribute('data-afdc-param') || 'filter';
      const minLen = parseInt(filterInput.getAttribute('data-afdc-minlen') || '2', 10);

      const raw = (filterInput.value || '').trim();
      if (raw !== '' && raw.length < minLen) {
        setStatus('…');
        return;
      }

      // Captura foco/caret para que no “salte” al reemplazar la tabla
      const active = document.activeElement === filterInput;
      const selStart = filterInput.selectionStart;
      const selEnd = filterInput.selectionEnd;

      setStatus('Buscando…');

      const basePairs = [];
      const sp = new URLSearchParams(window.location.search);
      sp.forEach((v, k) => basePairs.push([k, v]));

      const payload = {
        baseQuery: basePairs,
        filter: raw,
        page: page || 1
      };

      try {
        const j = await postJson(endpoint, payload);
        resultsBlock.innerHTML = j.html;

        // Actualiza meta (si existe afuera del resultsBlock)
        const meta = document.querySelector('[data-afdc-meta]');
        if (meta && typeof j.totalRows !== 'undefined' && typeof j.totalPages !== 'undefined' && typeof j.page !== 'undefined') {
          meta.innerHTML =
            '<span class="chip">Resultados: <strong>' + (j.totalRows|0) + '</strong></span>' +
            '<span class="crumbs">Página ' + (j.page|0) + ' / ' + (j.totalPages|0) + '</span>';
        }

        // Re-attach módulos sobre el nuevo wrapper
        const newWrap = resultsBlock.querySelector('[data-afdc-table]');
        if (newWrap) attachTableModule(newWrap);

        // Restaura foco/caret
        if (active) {
          filterInput.focus({preventScroll:true});
          if (typeof selStart === 'number' && typeof selEnd === 'number') {
            try { filterInput.setSelectionRange(selStart, selEnd); } catch(e) {}
          }
        }

        setStatus('');
      } catch (e) {
        setStatus('Error');
        // eslint-disable-next-line no-console
        console.warn('[AFDC] refine error:', e);
      }
    }

    // Bind tools:
    // - si son externas => bind 1 vez y listo
    // - si son internas => bind por-wrap (wrap cambia en AJAX)
    if (isExternalTools && toolRoot.__afdcBound) {
      // ya bindeado: nada
    } else {
      if (isExternalTools) toolRoot.__afdcBound = true;

      if (btnApply) btnApply.addEventListener('click', () => runAjax(1));

      if (btnClear) btnClear.addEventListener('click', () => {
        if (!filterInput) return;
        filterInput.value = '';
        if (isAjax) runAjax(1);
        else if (remote) {
          const u = new URL(window.location.href);
          u.searchParams.delete(param);
          u.searchParams.set('page', '1');
          window.location.href = u.toString();
        } else {
          applyClientFilter();
        }
      });

      if (btnCsv) btnCsv.addEventListener('click', () => {
        const curWrap = isExternalTools ? (toolRoot.__afdcWrap || wrap) : wrap;
        const curTable = curWrap.querySelector('table[data-afdc-target]') || curWrap.querySelector('table');
        const curBody = curTable ? (curTable.tBodies[0] || curTable.querySelector('tbody')) : null;
        if (!curTable || !curBody) return;

        const rows = Array.from(curBody.rows).filter(r => r.style.display !== 'none');
        const heads = Array.from(curTable.tHead ? curTable.tHead.rows[0].cells : []).map(th => (th.innerText||'').trim());
        const lines = [];
        if (heads.length) lines.push(heads.map(escapeCsv).join(','));
        rows.forEach(tr => {
          const tds = Array.from(tr.cells).map(td => (td.innerText || '').trim());
          lines.push(tds.map(escapeCsv).join(','));
        });

        const blob = new Blob([lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        const ts = new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
        a.href = url;
        a.download = 'afdc_table_' + ts + '.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      });

      if (filterInput) {
        if (isAjax) {
          const applyDebounced = debounce(() => runAjax(1), 250);
          filterInput.addEventListener('input', () => {
            if (chkAuto && chkAuto.checked) applyDebounced();
          });
        } else if (remote) {
          const param = filterInput.getAttribute('data-afdc-param') || 'filter';
          const resetKey = filterInput.getAttribute('data-afdc-reset-page') || 'page';
          const applyRemoteReload = debounce(() => {
            const raw = (filterInput.value || '').trim();
            const u = new URL(window.location.href);
            if (raw) u.searchParams.set(param, raw); else u.searchParams.delete(param);
            u.searchParams.set(resetKey, '1');
            window.location.href = u.toString();
          }, 280);
          filterInput.addEventListener('input', applyRemoteReload);
        } else {
          filterInput.addEventListener('input', applyClientFilter);
        }
      }

      // Pager AJAX (si existe)
      if (resultsBlock) {
        resultsBlock.addEventListener('click', (ev) => {
          const a = ev.target.closest('[data-afdc-page]');
          if (!a) return;
          ev.preventDefault();
          const p = parseInt(a.getAttribute('data-afdc-page') || '1', 10);
          runAjax(p);
        });
      }
    }

    // Si es filtro cliente, aplica al cargar
    if (!remote && !isAjax) applyClientFilter();
  }

  ready(() => {
    document.querySelectorAll('[data-afdc-table]').forEach(attachTableModule);
  });
})();
