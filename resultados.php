<?php
// afdc_v1/resultados.php
// Resultados unificado (misma tabla/UX que los buscadores) con refinado remoto (AJAX)

require_once __DIR__ . '/inc/bootstrap.php';
// auth (para CSRF + botón "Guardar para revisar")
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}
$u = function_exists('afdc_v2_current_user') ? afdc_v2_current_user() : null;
$logged = (bool)($u && !empty($u['id']));
$csrf = ($logged && function_exists('afdc_v2_csrf_token')) ? afdc_v2_csrf_token() : '';

include __DIR__ . '/inc/afdc-table.php';
require_once __DIR__ . '/inc/afdc-table-engine.php';
$mainClass = 'container-fluid';
// Header / layout
$pageTitle = 'Resultados';
include __DIR__ . '/inc/header.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

function qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) unset($q[$k]);
    }
    return '?' . http_build_query($q);
}

function render_pager_ajax(int $page, int $totalPages): string {
    if ($totalPages <= 1) return '';

    $page = max(1, min($totalPages, $page));
    $prev = max(1, $page - 1);
    $next = min($totalPages, $page + 1);

    $html = '<div class="pager" aria-label="Paginación" data-afdc-pager>';
    $html .= '<a class="pagebtn ' . ($page<=1?'disabled':'') . '" href="#" data-afdc-page="' . $prev . '">←</a>';

    $start = max(1, $page - 3);
    $end   = min($totalPages, $page + 3);

    if ($start > 1) {
        $html .= '<a class="pagebtn" href="#" data-afdc-page="1">1</a>';
        if ($start > 2) $html .= '<span class="small">…</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $cls = $p === $page ? 'pagebtn active' : 'pagebtn';
        $html .= '<a class="' . $cls . '" href="#" data-afdc-page="' . $p . '">' . $p . '</a>';
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="small">…</span>';
        $html .= '<a class="pagebtn" href="#" data-afdc-page="' . $totalPages . '">' . $totalPages . '</a>';
    }

    $html .= '<a class="pagebtn ' . ($page>=$totalPages?'disabled':'') . '" href="#" data-afdc-page="' . $next . '">→</a>';
    $html .= '</div>';
    return $html;
}

// ------------------------------------------------------------
// Params / modo
// ------------------------------------------------------------

$campeonato = trim((string)($_GET['campeonato'] ?? ''));
$equipo     = trim((string)($_GET['equipo'] ?? ''));
$campo      = trim((string)($_GET['campo'] ?? ''));
$termino    = trim((string)($_GET['termino'] ?? ''));

// Modo
$modo = ($campo !== '' && $termino !== '') ? 'indice' : (($campeonato !== '') ? 'campeonato' : (($equipo !== '') ? 'equipo' : ''));

$perPage = (int)($_GET['per_page'] ?? 50);
$page    = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
$offset = ($page - 1) * $perPage;

if ($modo === ''): ?>
<section class="card">
  <h1 style="margin:0 0 12px; font-size:22px;">Resultados</h1>
  <p class="small">
    Falta parámetro. Usá:
    <code>?campeonato=...</code> o <code>?equipo=...</code> (fútbol) /
    <code>?campo=650&amp;termino=...</code> (índices).
  </p>
  <p class="small">Volver a <a href="index.php">Home</a>.</p>
</section>
<?php include __DIR__ . '/inc/footer.php'; exit; endif;

$pageTitle = ($modo === 'indice') ? 'Índices · Resultados' : 'Fútbol · Resultados';
$tituloVista = ($modo === 'indice') ? ($campo . ' · ' . $termino) : (($modo === 'campeonato') ? $campeonato : $equipo);

// ------------------------------------------------------------
// Query (sin refinado; el refinado vive en api/refinar_resultados.php)
// ------------------------------------------------------------

$mysqli = db();
$rows = [];
$totalRows = 0;
$err = null;

if ($modo === 'indice') {
    $sqlCount = "
      SELECT COUNT(DISTINCT t.barcode) AS c
      FROM titulos t
      INNER JOIN materias mf ON mf.sys = t.sys
      WHERE mf.campo = ? AND mf.materia = ?
    ";
    if ($stmt = $mysqli->prepare($sqlCount)) {
        $stmt->bind_param('ss', $campo, $termino);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $totalRows = (int)($row['c'] ?? 0);
        $stmt->close();
    } else {
        $err = $mysqli->error;
    }

    $sql = "
      SELECT
        t.sys,
        t.titulo,
        t.barcode,
        t.fecha,
        COALESCE(GROUP_CONCAT(DISTINCT mm.materia ORDER BY mm.materia SEPARATOR ' | '), '') AS materias,
        SUM(CASE WHEN d.carpeta='Bajas' THEN 1 ELSE 0 END) AS digital_count,
        MAX(CASE WHEN d.carpeta='Altas' THEN 1 ELSE 0 END) AS has_alta
      FROM titulos t
      INNER JOIN materias mf ON mf.sys = t.sys AND mf.campo = ? AND mf.materia = ?
      LEFT JOIN materias mm ON mm.sys = t.sys
      LEFT JOIN digitales d ON d.inv = t.barcode AND d.carpeta IN ('Bajas','Altas')
      GROUP BY t.sys, t.titulo, t.barcode, t.fecha
      ORDER BY t.titulo ASC, t.sys DESC
      LIMIT ? OFFSET ?
    ";
    if ($stmt = $mysqli->prepare($sql)) {
        $stmt->bind_param('ssii', $campo, $termino, $perPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    } else {
        $err = $mysqli->error;
    }

} else {
    $isCamp = ($modo === 'campeonato');
    $sqlCount = $isCamp
        ? "SELECT COUNT(DISTINCT p.barcode) AS c FROM partidos p WHERE p.tituloReg = ?"
        : "SELECT COUNT(DISTINCT p.barcode) AS c FROM partidos p WHERE (p.equipo1 = ? OR p.equipo2 = ?)";

    if ($stmt = $mysqli->prepare($sqlCount)) {
        if ($isCamp) $stmt->bind_param('s', $campeonato);
        else $stmt->bind_param('ss', $equipo, $equipo);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $totalRows = (int)($row['c'] ?? 0);
        $stmt->close();
    } else {
        $err = $mysqli->error;
    }

    $filterSql = $isCamp ? "p.tituloReg = ?" : "(p.equipo1 = ? OR p.equipo2 = ?)";
    $sql = "
      SELECT
        t.sys,
        t.titulo,
        p.barcode,
        t.fecha,
        COALESCE(GROUP_CONCAT(DISTINCT mm.materia ORDER BY mm.materia SEPARATOR ' | '), '') AS materias,
        SUM(CASE WHEN d.carpeta='Bajas' THEN 1 ELSE 0 END) AS digital_count,
        MAX(CASE WHEN d.carpeta='Altas' THEN 1 ELSE 0 END) AS has_alta
      FROM partidos p
      LEFT JOIN titulos t ON t.barcode = p.barcode
      LEFT JOIN materias mm ON mm.sys = t.sys
      LEFT JOIN digitales d ON d.inv = p.barcode AND d.carpeta IN ('Bajas','Altas')
      WHERE {$filterSql}
      GROUP BY p.barcode, t.sys, t.titulo, t.fecha
      ORDER BY t.fecha DESC, t.sys DESC
      LIMIT ? OFFSET ?
    ";

    if ($stmt = $mysqli->prepare($sql)) {
        if ($isCamp) $stmt->bind_param('sii', $campeonato, $perPage, $offset);
        else $stmt->bind_param('ssii', $equipo, $equipo, $perPage, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    } else {
        $err = $mysqli->error;
    }
}

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
// Barcodes de la página (fallback; si hay refine AJAX, también leemos del DOM)
$pageBarcodes = [];
foreach ($rows as $r) { if (!empty($r['barcode'])) $pageBarcodes[] = (string)$r['barcode']; }
$pageBarcodes = array_values(array_unique($pageBarcodes));
$pageBarcodesJson = htmlspecialchars(json_encode($pageBarcodes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES);

// ------------------------------------------------------------
// UI
// ------------------------------------------------------------
?>

<section class="card">
  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <div style="flex:1; min-width:280px;">
      <h1 style="margin:0 0 6px; font-size:22px; display:flex; align-items:center; gap:10px;">
        <?= h($pageTitle) ?>
        <span class="afdc-tip" title="Tip: clic en Materias filtra por materias (6XX) • SYS abre OPAC • Ver digital abre visor" aria-label="Ayuda">ⓘ</span>
      </h1>
      <div class="small" style="color:rgba(255,255,255,.70);"><?= h($tituloVista) ?></div>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <?php if ($modo === 'indice'): ?>
        <a class="btn secondary" href="indice_marc.php?campo=<?= urlencode($campo) ?>">← Índice <?= h($campo) ?></a>
      <?php elseif ($modo === 'campeonato'): ?>
        <a class="btn secondary" href="campeonatos.php">← Campeonatos</a>
      <?php else: ?>
        <a class="btn secondary" href="equipos.php">← Equipos</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="error" style="margin-top:10px;"><strong>Error:</strong> <?= h($err) ?></div>
  <?php endif; ?>

  <div style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin-top:12px;">
    <form method="get" action="resultados.php" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
      <?php if ($modo === 'indice'): ?>
        <input type="hidden" name="campo" value="<?= h($campo) ?>" />
        <input type="hidden" name="termino" value="<?= h($termino) ?>" />
      <?php elseif ($modo === 'campeonato'): ?>
        <input type="hidden" name="campeonato" value="<?= h($campeonato) ?>" />
      <?php else: ?>
        <input type="hidden" name="equipo" value="<?= h($equipo) ?>" />
      <?php endif; ?>
      <input type="hidden" name="page" value="1" />

      <div>
        <label for="per_page" style="display:block; font-size:12px; color:rgba(255,255,255,.65); margin:0 0 6px;">Por página</label>
        <select id="per_page" name="per_page" style="height:38px; padding:0 10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#e8eaf0; outline:none;">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= (int)$n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= (int)$n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><button class="btn" type="submit">Aplicar</button></div>
    </form>

    <!-- Herramientas compactas -->
    <div id="resTools" class="afdc-compact-tools" style="margin-left:auto; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input
        type="text"
        placeholder="Refinar…"
        data-afdc-filter
        data-afdc-remote
        data-afdc-remote-filter
        data-afdc-param="filter"
        data-afdc-reset-page="page"
        data-afdc-minlen="2"
        style="height:38px; min-width:260px; padding:0 12px; border-radius:14px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#e8eaf0; outline:none;"
      />
      <label class="small" style="display:flex; gap:8px; align-items:center; color:rgba(255,255,255,.70); user-select:none;">
        <input type="checkbox" data-afdc-auto checked />
        Auto
      </label>
      <button class="btn" type="button" data-afdc-apply>Aplicar</button>
      <button class="btn secondary" type="button" data-afdc-clear title="Limpiar filtro">×</button>
      <button class="btn secondary" type="button" data-afdc-csv>CSV</button>

      <?php if ($logged): ?>
        <span class="review-dd" data-review-dd style="position:relative; display:inline-flex;">
          <button
            type="button"
            class="btn"
            data-review-toggle
            data-csrf="<?= h($csrf) ?>"
            data-page-barcodes="<?= $pageBarcodesJson ?>"
            data-modo="<?= h($modo) ?>"
            data-campo="<?= h($campo) ?>"
            data-termino="<?= h($termino) ?>"
            data-campeonato="<?= h($campeonato) ?>"
            data-equipo="<?= h($equipo) ?>"
          >Guardar para revisar ▾</button>
          <div data-review-menu style="display:none; position:absolute; left:0; top:calc(100% + 6px); z-index:9999; background:rgba(0,0,0,.82); border:1px solid rgba(255,255,255,.14); border-radius:12px; padding:6px; min-width:190px; box-shadow:0 12px 30px rgba(0,0,0,.25);">
            <button type="button" class="btn secondary" data-review-action="page" style="width:100%; text-align:left;">Esta página</button>
            <button type="button" class="btn secondary" data-review-action="all"  style="width:100%; text-align:left; margin-top:6px;">Todos</button>
          </div>
        </span>
      <?php endif; ?>

      <span class="small" data-afdc-status style="margin-left:6px; color:rgba(255,255,255,.65);"></span>
    </div>
  </div>

  <div class="meta" style="display:flex; gap:10px; flex-wrap:wrap; margin: 10px 0 10px; color: rgba(255,255,255,.65); font-size: 13px;" data-afdc-meta>
    <span class="chip">Resultados: <strong><?= (int)$totalRows ?></strong></span>
    <span class="crumbs">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
  </div>

  <div data-afdc-results>
    <?php
      ob_start();
      afdc_table_render('buscador', $rows, [
        'filter_param' => 'filter',
        'filter_value' => '',
        'filter_label' => 'Refinar resultados',
        'filter_placeholder' => 'Refinar…',
        'remote_mode' => 'ajax',
        'context' => 'resultados',
        'total_rows' => $totalRows,
        'show_toolbar' => false,
      ]);
      $tableHtml = ob_get_clean();

      $tableHtml = preg_replace(
        '/\bdata-afdc-table\b/',
        'data-afdc-table data-afdc-tools="#resTools" data-afdc-endpoint="api/refinar_resultados.php"',
        $tableHtml,
        1
      );

      echo $tableHtml;
      echo render_pager_ajax($page, $totalPages);
    ?>
  </div>
</section>
<?php if ($logged): ?>
<script>
(function(){
  const dd = document.querySelector('[data-review-dd]');
  if (!dd) return;

  const btn = dd.querySelector('[data-review-toggle]');
  const menu = dd.querySelector('[data-review-menu]');
  const actions = dd.querySelectorAll('[data-review-action]');

  function closeMenu(){ if (menu) menu.style.display = 'none'; }
  function toggleMenu(){
    if (!menu) return;
    menu.style.display = (menu.style.display === 'none' || !menu.style.display) ? 'block' : 'none';
  }

  btn?.addEventListener('click', (e)=>{
    e.preventDefault();
    e.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', ()=> closeMenu());
  menu?.addEventListener('click', (e)=> e.stopPropagation());

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

  function uniq(a){
    const s = new Set();
    const out = [];
    for (const x of (a||[])) { const k = String(x||''); if (!k) continue; if (!s.has(k)) { s.add(k); out.push(k); } }
    return out;
  }

  // Lee barcodes de la tabla visible (post-refine AJAX)
  function getVisibleBarcodesFromDom(){
    const wrap = document.querySelector('[data-afdc-results]');
    const table = wrap ? wrap.querySelector('table') : null;
    const tbody = table ? (table.tBodies[0] || table.querySelector('tbody')) : null;
    if (!tbody) return [];

    const rx = /\b[A-Z]{2}\d{6}\b/g; // FO049761, etc
    const out = [];
    for (const tr of Array.from(tbody.rows)) {
      if (tr.style && tr.style.display === 'none') continue;
      const text = (tr.innerText || '');
      const m = text.match(rx);
      if (m && m[0]) out.push(m[0]);
    }
    return uniq(out);
  }

  async function postJson(url, payload, csrf){
    const r = await fetch(url, {
      method:'POST',
      credentials:'same-origin',
      headers: { 'Content-Type':'application/json', 'X-CSRF-Token': csrf || '' },
      body: JSON.stringify(payload || {})
    });
    const j = await r.json().catch(()=>null);
    if (!j || j.ok !== true) throw (j || {error:'bad_response'});
    return j;
  }

  async function addPage(){
    const csrf = btn.getAttribute('data-csrf') || '';
    // Preferimos DOM (respeta refine AJAX), fallback al JSON server-side
    let barcodes = getVisibleBarcodesFromDom();
    if (!barcodes.length) {
      const raw = btn.getAttribute('data-page-barcodes') || '[]';
      try { barcodes = JSON.parse(raw) || []; } catch(e) { barcodes = []; }
      barcodes = uniq(barcodes);
    }
    if (!barcodes.length) { toast('No hay barcodes en la página'); return; }

    await postJson('api/v2/conjuntos/add-sobres.php', { barcodes }, csrf);
  }

  async function addAll(){
    const csrf = btn.getAttribute('data-csrf') || '';
    const modo = btn.getAttribute('data-modo') || '';
    const campo = btn.getAttribute('data-campo') || '';
    const termino = btn.getAttribute('data-termino') || '';
    const campeonato = btn.getAttribute('data-campeonato') || '';
    const equipo = btn.getAttribute('data-equipo') || '';
    const filter = document.querySelector('#resTools [data-afdc-filter]')?.value || '';

    await postJson('api/v2/conjuntos/add-resultados-all.php', {
      modo, campo, termino, campeonato, equipo, filter
    }, csrf);
  }

  async function run(scope){
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = 'Guardando...';
    try {
      if (scope === 'page') await addPage();
      else await addAll();
      btn.textContent = 'Guardado ✓';
      setTimeout(()=>{ btn.textContent = old; btn.disabled=false; }, 900);
    } catch(e) {
      console.error(e);
      btn.textContent = 'Error';
      setTimeout(()=>{ btn.textContent = old; btn.disabled=false; }, 900);
    }
  }

  actions.forEach(a=>{
    a.addEventListener('click', async (e)=>{
      e.preventDefault();
      const scope = a.getAttribute('data-review-action') || 'page';
      closeMenu();
      await run(scope);
    });
  });
})();
</script>
+<?php endif; ?>
+
<?php include __DIR__ . '/inc/footer.php'; ?>
