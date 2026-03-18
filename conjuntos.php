<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}

$u = function_exists('afdc_v2_current_user') ? afdc_v2_current_user() : null;
$logged = (bool)($u && !empty($u['id']));
$base = rtrim((string)BASE_URL, '/');
$return = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/conjuntos.php'));

if (!$logged) {
  header('Location: ' . $base . '/login.php?return=' . urlencode($return));
  exit;
}

$csrf = function_exists('afdc_v2_csrf_token') ? afdc_v2_csrf_token() : '';

$pageTitle = 'Conjuntos';
$mainClass = 'container-fluid';
include __DIR__ . '/inc/header.php';
?>
<style>
  .cj-toolbar{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:stretch;
  }

  /* Bloque de controles que afectan la lista */
  .cj-toolbar-left{
    flex:1 1 560px;
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
    gap:8px;
    align-items:center;
  }

  /* Bloque general (conjunto) a la derecha con estilo distinto */
  .cj-general{
    flex:0 0 auto;
    display:flex;
    gap:8px;
    align-items:center;
    justify-content:flex-end;
    padding:6px 8px;
    border:1px solid rgba(0,0,0,.12);
    border-radius:14px;
    background:rgba(0,0,0,.035);
  }
  .cj-general > *{
    min-width:160px;
  }

  /* Estado a la derecha, en línea propia si hace falta */
  .cj-status{
    flex:1 1 100%;
    text-align:right;
    opacity:.75;
    padding-top:2px;
  }

  /* En pantallas chicas, que el bloque general se estire y se vea prolijo */
  @media (max-width: 900px){
    .cj-general{
      flex:1 1 100%;
      justify-content:stretch;
    }
    .cj-general > *{
      flex:1 1 160px;
      min-width:160px;
    }
  }
</style>

<section class="card cj" style="padding:14px;">
  <!-- Toolbar única -->
<!-- Toolbar única (responsive + acciones generales a la derecha) -->
<div class="cj-toolbar">
  <div class="cj-toolbar-left">
    <select id="statusSelect" class="btn secondary">
      <option value="pending">Pendientes</option>
      <option value="inprogress">En curso</option>
      <option value="done">Completos</option>
      <option value="all">Todos</option>
    </select>

    <button class="btn secondary" id="btnRemoveDone" type="button" title="Quitar del conjunto todos los sobres completados">
      Quitar completados
    </button>

    <select id="colSelect" class="btn secondary">
      <option value="0">— Colección —</option>
    </select>

    <select id="colMode" class="btn secondary" disabled>
      <option value="all">Todos</option>
      <option value="in">En colección</option>
      <option value="out">Fuera de colección</option>
    </select>

    <select id="sortSelect" class="btn secondary" title="Orden">
      <option value="date_desc">Fecha ↓</option>
      <option value="date_asc">Fecha ↑</option>
      <option value="barcode_asc">Barcode A→Z</option>
    </select>
  </div>

  <div class="cj-toolbar-right cj-general">
    <select id="setSelect" class="btn"></select>
    <button id="btnNewSet" class="btn" type="button">Nuevo conjunto</button>
    <button id="btnClear" class="btn secondary" type="button" title="Vaciar el conjunto (borra sobres + vistos)">Vaciar</button>
  </div>

  <div id="listStatus" class="small cj-status"></div>
</div>


  <div style="display:grid; grid-template-columns: clamp(320px, 30vw, 480px) 1fr; gap:14px; margin-top:12px;">
    <!-- Left -->
    <div style="border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:10px; height:64vh; display:flex; flex-direction:column; min-height:520px;">
      <!-- lista con scroll propio -->
      <div id="sobresScroll" style="flex:1; overflow-y:auto; padding-right:6px;">
        <div id="sobresList" style="display:flex; flex-direction:column; gap:8px;"></div>
      </div>
    </div>

    <!-- Right -->
    <div style="border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:10px; min-height:64vh;">
      <div id="rightEmpty" style="opacity:.7; padding:18px;">
        Seleccioná un sobre a la izquierda.
      </div>

      <div id="rightWrap" style="display:none;">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;">
          <div>
            <div id="sobreTitle" style="font-weight:800; font-size:16px;"></div>
            <div id="sobreMeta" class="small" style="opacity:.75;"></div>
          </div>

          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <div style="display:flex; gap:6px; align-items:center;">
              <label class="small" style="opacity:.75;">Por página</label>
              <select id="perPage" class="btn secondary">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
              </select>
            </div>

            <div style="display:flex; gap:6px; align-items:center;">
              <button id="btnPrevPage" class="btn secondary" type="button">◀</button>
              <div id="pageInfo" class="small" style="opacity:.75; min-width:88px; text-align:center;"></div>
              <button id="btnNextPage" class="btn secondary" type="button">▶</button>
            </div>
          </div>
        </div>

        <div id="photosWrap" style="margin-top:10px;"></div>
      </div>
    </div>
  </div>
</section>

<script>
(function(){
  const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const API = {
    sets: "api/v2/conjuntos/sets.php",
    createSet: "api/v2/conjuntos/create-set.php",
    sobres: "api/v2/conjuntos/sobres.php",
    sobre: "api/v2/conjuntos/sobre.php",
    markSeen: "api/v2/conjuntos/mark-seen.php",
    clear: "api/v2/conjuntos/clear.php",
    removeSobre: "api/v2/conjuntos/remove-sobre.php",
    removeCompleted: "api/v2/conjuntos/remove-completed.php",
    collections: "api/v2/conjuntos/collections.php",
    collectionBarcodes: "api/v2/conjuntos/collection-barcodes.php"
  };

  // toolbar
  const elSet = document.getElementById('setSelect');
  const btnNewSet = document.getElementById('btnNewSet');
  const btnClear = document.getElementById('btnClear');

  const statusSelect = document.getElementById('statusSelect');
  const btnRemoveDone = document.getElementById('btnRemoveDone');

  const colSelect = document.getElementById('colSelect');
  const colMode = document.getElementById('colMode');

  const sortSelect = document.getElementById('sortSelect');

  const listStatus = document.getElementById('listStatus');
  const sobresList = document.getElementById('sobresList');

  // right
  const rightEmpty = document.getElementById('rightEmpty');
  const rightWrap = document.getElementById('rightWrap');
  const sobreTitle = document.getElementById('sobreTitle');
  const sobreMeta = document.getElementById('sobreMeta');
  const photosWrap = document.getElementById('photosWrap');

  const perPageEl = document.getElementById('perPage');
  const btnPrevPage = document.getElementById('btnPrevPage');
  const btnNextPage = document.getElementById('btnNextPage');
  const pageInfo = document.getElementById('pageInfo');

  const STATUS_LABEL = {
    pending: 'Pendientes',
    inprogress: 'En curso',
    done: 'Completos',
    all: 'Todos'
  };

  let state = {
    setId: 0,

    // filtros lista sobres
    status: 'all',
    colId: 0,
    colMode: 'all',
    colCache: new Map(),      // collection_id -> Set(barcodes)

    // orden
    sort: 'date_desc',        // date_desc | date_asc | barcode_asc

    // lista sobres (ya filtrada + ordenada para render)
    listItems: [],

    // panel derecha
    barcode: '',
    photos: [],
    page: 1,
    perPage: 25
  };

  function toast(msg){
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = [
      'position:fixed','right:14px','bottom:14px','z-index:9999',
      'background:rgba(0,0,0,.78)','color:#fff','padding:9px 11px',
      'border-radius:12px','font-size:12px','max-width:50vw'
    ].join(';');
    document.body.appendChild(t);
    setTimeout(()=>{ t.style.opacity='0'; t.style.transition='opacity .25s'; }, 1300);
    setTimeout(()=>t.remove(), 1700);
  }

  async function apiGet(url){
    const r = await fetch(url, { credentials:'same-origin' });
    const j = await r.json();
    if (!j || j.ok !== true) throw j || {error:'bad_response'};
    return j;
  }

  async function apiPost(url, body){
    const r = await fetch(url, {
      method:'POST',
      credentials:'same-origin',
      headers: {
        'Content-Type':'application/json',
        'X-CSRF-Token': CSRF
      },
      body: JSON.stringify(body || {})
    });
    const j = await r.json();
    if (!j || j.ok !== true) throw j || {error:'bad_response'};
    return j;
  }

  function escapeHtml(s){
    return (s||'').toString()
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');
  }

  function statusText(){
    const st = STATUS_LABEL[state.status] || state.status;
    const cid = state.colId || 0;
    const mode = state.colMode || 'all';
    const extra = (cid && mode !== 'all')
      ? (' · ' + (mode === 'in' ? 'En colección' : 'Fuera de colección'))
      : '';
    return `${st}${extra}`;
  }

  function updateListStatus(){
    listStatus.textContent = `Mostrando: ${statusText()} (${(state.listItems||[]).length})`;
  }

  // ====== Orden por fecha (robusto) ======
  function ymdFromFechaRaw(v){
    // espera "yyyymmdd" o "yyyy-mm-dd" o similar
    const s = String(v || '').trim();
    if (!s) return 0;

    // 8 dígitos
    const m8 = s.match(/^(\d{4})(\d{2})(\d{2})$/);
    if (m8) return parseInt(m8[1]+m8[2]+m8[3], 10);

    // yyyy-mm-dd
    const m10 = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m10) return parseInt(m10[1]+m10[2]+m10[3], 10);

    return 0;
  }

  function ymdFromTitulo(t){
    // busca dd/mm/yyyy en el título
    const s = String(t || '');
    const m = s.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
    if (!m) return 0;
    const dd = String(m[1]).padStart(2,'0');
    const mm = String(m[2]).padStart(2,'0');
    const yy = String(m[3]);
    return parseInt(yy + mm + dd, 10);
  }

  function sortKey(it){
    // prioridad: it.fecha -> título
    const k1 = ymdFromFechaRaw(it && it.fecha);
    if (k1) return k1;
    return ymdFromTitulo(it && it.titulo);
  }

  function applySort(items){
    const arr = (items || []).slice();

    if (state.sort === 'barcode_asc') {
      arr.sort((a,b)=>{
        const A = String(a.barcode || '');
        const B = String(b.barcode || '');
        return A.localeCompare(B, 'es', {numeric:true, sensitivity:'base'});
      });
      return arr;
    }

    // date sort (desc/asc) y fallback a barcode
    arr.sort((a,b)=>{
      const da = sortKey(a);
      const db = sortKey(b);

      if (da !== db) {
        return (state.sort === 'date_asc') ? (da - db) : (db - da);
      }

      const A = String(a.barcode || '');
      const B = String(b.barcode || '');
      return A.localeCompare(B, 'es', {numeric:true, sensitivity:'base'});
    });

    return arr;
  }

  async function loadCollections(){
    try {
      const j = await apiGet(API.collections);
      const items = j.items || [];
      colSelect.innerHTML = '<option value="0">— Colección —</option>';
      for (const c of items) {
        const opt = document.createElement('option');
        opt.value = String(c.id);
        opt.textContent = c.title + (c.is_curated ? ' (curada)' : '');
        colSelect.appendChild(opt);
      }
    } catch(e) {
      console.warn(e);
    }
  }

  async function getCollectionBarcodeSet(collectionId){
    const cid = parseInt(collectionId || '0', 10) || 0;
    if (!cid) return null;
    if (state.colCache.has(cid)) return state.colCache.get(cid);
    const j = await apiGet(API.collectionBarcodes + '?collection_id=' + encodeURIComponent(cid));
    const arr = j.barcodes || [];
    const s = new Set(arr.map(x => String(x || '').trim()).filter(Boolean));
    state.colCache.set(cid, s);
    return s;
  }

  function renderSobres(items){
    sobresList.innerHTML = '';

    if (!items || !items.length) {
      sobresList.innerHTML = '<div class="small" style="opacity:.7; padding:8px;">No hay sobres en este filtro.</div>';
      return;
    }

    for (const it of items) {
      const bc = String(it.barcode || '').trim();

      const row = document.createElement('div');
      row.dataset.barcode = bc;
      row.style.cssText = [
        'display:flex','gap:10px','align-items:center',
        'border:1px solid rgba(0,0,0,.08)','border-radius:12px','padding:8px 10px',
        'cursor:pointer'
      ].join(';');

      const mid = document.createElement('div');
      // Un solo bloque legible (sin columnas) y sin cortar con ellipsis
      mid.style.cssText = 'flex:1; min-width:0; white-space:normal; overflow:visible; line-height:1.25;';

      const title = (it.titulo || '').trim();
      mid.innerHTML =
        `<span style="font-weight:800;">${escapeHtml(bc)}</span>` +
        `<span style="opacity:.75;"> — </span>` +
        `<span style="font-weight:700; opacity:${title?1:.6};">${title ? escapeHtml(title) : 'Sin título'}</span>`;

      const btnX = document.createElement('button');
      btnX.type = 'button';
      btnX.textContent = '✕';
      btnX.title = 'Quitar sobre del conjunto';
      btnX.className = 'btn secondary';
      btnX.style.cssText = 'padding:4px 8px; line-height:1; border-radius:10px;';
      btnX.addEventListener('click', (ev)=>{
        ev.preventDefault();
        ev.stopPropagation();
        removeSobre(bc);
      });

      const right = document.createElement('div');
      right.style.cssText = 'display:flex; align-items:center;';
      right.appendChild(btnX);

      row.appendChild(mid);
      row.appendChild(right);

      row.addEventListener('click', ()=> openSobre(bc));
      sobresList.appendChild(row);
    }
  }

  async function loadSets(){
    const j = await apiGet(API.sets);
    const sets = j.sets || [];
    elSet.innerHTML = '';
    for (const s of sets) {
      const opt = document.createElement('option');
      opt.value = s.id;
      opt.textContent = (s.kind === 'def') ? `${s.name} (default)` : s.name;
      elSet.appendChild(opt);
    }
    state.setId = (j.default_set_id || (sets[0] && sets[0].id) || 0);
    elSet.value = String(state.setId);
  }

  async function loadSobres(){
    listStatus.textContent = 'Cargando...';

    const url = API.sobres
      + '?set_id=' + encodeURIComponent(state.setId)
      + '&status=' + encodeURIComponent(state.status);

    const j = await apiGet(url);
    let items = j.items || [];

    // Cruce con colección (cliente)
    const cid = state.colId || 0;
    const mode = state.colMode || 'all';
    if (cid && mode !== 'all') {
      const set = await getCollectionBarcodeSet(cid);
      if (set && set.size) {
        if (mode === 'in') items = items.filter(it => set.has(String(it.barcode || '')));
        else if (mode === 'out') items = items.filter(it => !set.has(String(it.barcode || '')));
      } else {
        if (mode === 'in') items = [];
      }
    }

    // Orden
    items = applySort(items);

    state.listItems = items;
    updateListStatus();
    renderSobres(items);
  }

  function closeRightIfCurrent(barcode){
    if (state.barcode === barcode) {
      state.barcode = '';
      state.photos = [];
      state.page = 1;
      rightWrap.style.display = 'none';
      rightEmpty.style.display = 'block';
      photosWrap.innerHTML = '';
    }
  }

  function removeFromListUI(barcode){
    const b = String(barcode || '').trim();
    if (!b) return;

    const before = state.listItems.length;
    state.listItems = state.listItems.filter(it => String(it.barcode || '').trim() !== b);

    // DOM
    const row = sobresList.querySelector(`[data-barcode="${CSS.escape(b)}"]`);
    if (row) row.remove();

    if (before > 0 && state.listItems.length === 0) {
      renderSobres(state.listItems);
    }

    updateListStatus();
  }

  async function removeSobre(barcode){
    const b = String(barcode || '').trim();
    if (!b || !state.setId) return;

    try {
      await apiPost(API.removeSobre, { set_id: state.setId, barcode: b });

      closeRightIfCurrent(b);
      removeFromListUI(b);

      toast('Quitado');
    } catch(e){
      console.error(e);
      toast('No se pudo quitar');
    }
  }

  async function removeCompleted(){
    if (!state.setId) return;
    try{
      const j = await apiPost(API.removeCompleted, { set_id: state.setId });
      const n = (j && typeof j.removed === 'number') ? j.removed : 0;

      // al ser masivo, recargamos lista (1 sola vez)
      closeRightIfCurrent(state.barcode);
      await loadSobres();

      toast(n > 0 ? ('Quitados: ' + n) : 'No hay completados');
    } catch(e){
      console.error(e);
      toast('No se pudo limpiar');
    }
  }

  async function openSobre(barcode){
    const b = String(barcode || '').trim();
    if (!b) return;

    state.barcode = b;
    state.page = 1;

    rightEmpty.style.display = 'none';
    rightWrap.style.display = 'block';
    photosWrap.innerHTML = '<div class="small" style="opacity:.7; padding:8px;">Cargando...</div>';

    const url = API.sobre
      + '?set_id=' + encodeURIComponent(state.setId)
      + '&barcode=' + encodeURIComponent(b);

    const j = await apiGet(url);

    const title = (j.titulo || '').trim();
    sobreTitle.textContent = `${j.barcode}${title ? ' — ' + title : ''}`;

    const p = j.progress || {total:0, seen:0, completed_at:null};
    const meta = [];
    if (j.fecha) meta.push(j.fecha);
    meta.push((p.total > 0) ? `${p.seen}/${p.total}` : `${p.seen}/—`);
    if (p.completed_at) meta.push('Completo');
    sobreMeta.textContent = meta.join(' — ');

    state.photos = j.photos || [];
    renderPhotos();
  }

  function totalPages(){
    const total = (state.photos || []).length;
    const per = Math.max(1, state.perPage|0);
    return Math.max(1, Math.ceil(total / per));
  }

  function clampPage(){
    const tp = totalPages();
    if (state.page < 1) state.page = 1;
    if (state.page > tp) state.page = tp;
  }

  function pageSlice(){
    clampPage();
    const per = Math.max(1, state.perPage|0);
    const start = (state.page - 1) * per;
    const end = start + per;
    return (state.photos || []).slice(start, end);
  }

  function updatePagerUI(){
    const tp = totalPages();
    pageInfo.textContent = `${state.page} / ${tp}`;
    btnPrevPage.disabled = (state.page <= 1);
    btnNextPage.disabled = (state.page >= tp);
  }

  function openViewerAt(photoIdx){
    if (!state.barcode) return;
    // i 0-based (asumido)
    const i = (photoIdx == null) ? 0 : (parseInt(photoIdx, 10) || 0);
    const url = 'ver_digital.php?barcode=' + encodeURIComponent(state.barcode) + '&i=' + encodeURIComponent(i);
    window.open(url, '_blank', 'noopener');
  }

  function renderPhotos(){
    const photos = state.photos || [];
    if (!photos.length) {
      photosWrap.innerHTML = '<div class="small" style="opacity:.75; padding:8px;">Este sobre no tiene bajas (o no se pudo snapshotea).</div>';
      updatePagerUI();
      return;
    }

    updatePagerUI();

    const subset = pageSlice();

    const grid = document.createElement('div');
    grid.style.cssText = 'display:grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap:10px;';

    for (const p of subset) {
      const card = document.createElement('div');
      card.style.cssText = 'border:1px solid rgba(0,0,0,.08); border-radius:14px; overflow:hidden; position:relative; cursor:pointer;';

      const img = document.createElement('img');
      img.loading = 'lazy';
      img.alt = p.image_key || '';
      img.src = p.thumb_url || '';
      img.style.cssText = 'width:100%; height:auto; display:block; background:rgba(0,0,0,.06);';

      const cap = document.createElement('div');
      cap.className = 'small';
      cap.style.cssText = 'padding:6px 8px; opacity:.85;';
      cap.textContent = p.image_key || '';

      if (p.seen) {
        const seen = document.createElement('div');
        seen.textContent = '✓';
        seen.style.cssText = 'position:absolute; top:8px; right:8px; width:22px; height:22px; border-radius:999px; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.75); color:#fff; font-weight:800;';
        card.appendChild(seen);
        img.style.opacity = '.72';
      }

      card.appendChild(img);
      card.appendChild(cap);

      card.addEventListener('click', ()=>{
        openViewerAt(p.photo_idx);
        markSeen(p).catch(()=>{});
      });

      grid.appendChild(card);
    }

    photosWrap.innerHTML = '';
    photosWrap.appendChild(grid);
  }

  async function markSeen(p){
    // aceptar photo_idx=0
    if (!p || p.photo_idx === undefined || p.photo_idx === null) return;

    // optimista local
    if (!p.seen) {
      p.seen = true;
      renderPhotos();
    }

    const j = await apiPost(API.markSeen, {
      set_id: state.setId,
      barcode: state.barcode,
      photo_idx: p.photo_idx
    });

    const pr = j.progress || {total:0, seen:0, completed:false};

    const parts = [];
    const fecha = (j.fecha || '').trim();
    if (fecha) parts.push(fecha);
    else {
      const cur = (sobreMeta.textContent || '').split(' — ').filter(Boolean);
      if (cur.length && /^\d{6,8}$/.test(cur[0])) parts.push(cur[0]);
    }

    parts.push((pr.total > 0) ? `${pr.seen}/${pr.total}` : `${pr.seen}/—`);
    if (pr.completed) parts.push('Completo');
    sobreMeta.textContent = parts.join(' — ');
  }

  // ===== events =====
  elSet.addEventListener('change', async ()=>{
    state.setId = parseInt(elSet.value || '0', 10) || 0;
    closeRightIfCurrent(state.barcode);
    await loadSobres();
  });

  statusSelect.addEventListener('change', async ()=>{
    state.status = (statusSelect.value || 'all');
    closeRightIfCurrent(state.barcode);
    await loadSobres();
  });

  btnRemoveDone?.addEventListener('click', removeCompleted);

  colSelect?.addEventListener('change', async ()=>{
    state.colId = parseInt(colSelect.value || '0', 10) || 0;
    colMode.disabled = !state.colId;
    if (!state.colId) { state.colMode = 'all'; colMode.value = 'all'; }
    closeRightIfCurrent(state.barcode);
    await loadSobres();
  });

  colMode?.addEventListener('change', async ()=>{
    state.colMode = colMode.value || 'all';
    closeRightIfCurrent(state.barcode);
    await loadSobres();
  });

  sortSelect.addEventListener('change', ()=>{
    state.sort = sortSelect.value || 'date_desc';
    // reordenamos lo ya cargado, sin re-fetch
    state.listItems = applySort(state.listItems);
    updateListStatus();
    renderSobres(state.listItems);
  });

  btnNewSet.addEventListener('click', async ()=>{
    const name = prompt('Nombre del nuevo conjunto:');
    if (!name) return;
    try {
      await apiPost(API.createSet, { name: name.trim() });
      toast('Conjunto creado');
      await loadSets();
      await loadSobres();
    } catch(e){
      console.error(e);
      toast('No se pudo crear');
    }
  });

  btnClear.addEventListener('click', async ()=>{
    if (!state.setId) return;
    if (!confirm('¿Vaciar este conjunto?\n\nEsto borra TODOS los sobres y el estado visto/no visto.')) return;
    try {
      await apiPost(API.clear, { set_id: state.setId });
      toast('Conjunto vaciado');
      closeRightIfCurrent(state.barcode);
      await loadSobres();
    } catch(e){
      console.error(e);
      toast('No se pudo vaciar');
    }
  });

  perPageEl.addEventListener('change', ()=>{
    const v = parseInt(perPageEl.value || '25', 10) || 25;
    state.perPage = v;
    state.page = 1;
    renderPhotos();
  });

  btnPrevPage.addEventListener('click', ()=>{
    state.page = Math.max(1, state.page - 1);
    renderPhotos();
  });

  btnNextPage.addEventListener('click', ()=>{
    state.page = Math.min(totalPages(), state.page + 1);
    renderPhotos();
  });

  // init
  (async ()=>{
    try{
      // soporte: ?tab=pending|inprogress|done|all
      const sp = new URLSearchParams(window.location.search || '');
      const tab = (sp.get('tab') || '').toLowerCase();
      const allowed = new Set(['pending','inprogress','done','all']);
      state.status = allowed.has(tab) ? tab : 'all';
      statusSelect.value = state.status;

      state.perPage = parseInt(perPageEl.value || '25', 10) || 25;

      // orden default: fecha ↓
      state.sort = 'date_desc';
      sortSelect.value = state.sort;

      await loadSets();
      await loadCollections();
      await loadSobres();
    } catch(e){
      console.error(e);
      toast('Error cargando conjuntos');
    }
  })();

})();
</script>

<?php include __DIR__ . '/inc/footer.php'; ?>
