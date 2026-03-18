<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';

// Auth/CSRF para Conjuntos
$logged = false;
$csrf = '';
$base = rtrim((string)BASE_URL, '/');
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
  $u = function_exists('afdc_v2_current_user') ? afdc_v2_current_user() : null;
  $logged = (bool)($u && !empty($u['id']));
  $csrf = $logged && function_exists('afdc_v2_csrf_token') ? afdc_v2_csrf_token() : '';
}
$mainClass = 'container-fluid';

// helper qs
function qs(array $overrides = []): string {
    $cur = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($cur[$k]);
        else $cur[$k] = $v;
    }
    $qs = http_build_query($cur);
    return 'buscador_basico.php' . ($qs ? ('?' . $qs) : '');
}

/**
 * Render de tabla + inyección del selector de tools externo.
 * Esto permite tener el refinado fuera de la tabla (como en el avanzado).
 */
function render_table_with_tools(string $profile, array $rows, array $opts, string $toolsSel): void {
    ob_start();
    afdc_table_render($profile, $rows, $opts);
    $html = ob_get_clean();
    // inyecta data-afdc-tools="#basicTools" en el primer data-afdc-table
    $html = preg_replace('/\bdata-afdc-table\b/', 'data-afdc-table data-afdc-tools="'.htmlspecialchars($toolsSel, ENT_QUOTES).'"', $html, 1);
    echo $html;
}

// -------------------- inputs --------------------
$q = trim((string)($_GET['q'] ?? ''));
$hasSearch = ($q !== '');

// Flag UI compact (para que el endpoint refine devuelva tabla sin toolbar interna)
$ui = (string)($_GET['ui'] ?? 'compact');
if ($ui === '') $ui = 'compact';

// Filtro "refinar" (opera sobre el total)
$filter = trim((string)($_GET['filter'] ?? ''));

$perPage = (int)($_GET['per_page'] ?? 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;

$offset = ($page - 1) * $perPage;

// -------------------- defaults --------------------
$rows = [];
$totalRows = 0;
$totalPages = 1;
$err = $countErr = null;

// -------------------- DB + queries (solo si hay búsqueda) --------------------
if ($hasSearch) {

    $mysqli = db();

    $where = [];
    $params = [];
    $types = '';

    // Búsqueda base: título o materias
    $like = '%' . $q . '%';
    $where[] = '(t.titulo LIKE ? OR EXISTS (
                    SELECT 1 FROM materias m2
                    WHERE m2.sys = t.sys AND m2.materia LIKE ?
                ))';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';

    // Filtro refinador (server-side sobre el total)
    if ($filter !== '') {
        $f = '%' . $filter . '%';
        $where[] = '(
            t.titulo LIKE ?
            OR t.barcode LIKE ?
            OR CAST(t.sys AS CHAR) LIKE ?
            OR t.fecha LIKE ?
            OR EXISTS (
                SELECT 1 FROM materias mf
                WHERE mf.sys = t.sys AND mf.materia LIKE ?
            )
        )';
        array_push($params, $f, $f, $f, $f, $f);
        $types .= 'sssss';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // COUNT
    $sqlCount = "SELECT COUNT(*) AS c FROM titulos t {$whereSql}";
    if ($stmt = $mysqli->prepare($sqlCount)) {
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $totalRows = (int)($row['c'] ?? 0);
        $stmt->close();
    } else {
        $countErr = $mysqli->error;
    }

    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    // SELECT principal
    $sql = "
        SELECT
            t.sys,
            t.titulo,
            t.barcode,
            t.fecha,
            COALESCE((
                SELECT GROUP_CONCAT(DISTINCT m.materia ORDER BY m.materia SEPARATOR ' | ')
                FROM materias m
                WHERE m.sys = t.sys
            ), '') AS materias,

            (
                SELECT COUNT(*)
                FROM digitales d
                WHERE d.inv = t.barcode AND d.carpeta = 'Bajas'
            ) AS digital_count,

            EXISTS(
                SELECT 1 FROM digitales d
                WHERE d.inv = t.barcode AND d.carpeta = 'Altas'
                LIMIT 1
            ) AS has_alta

        FROM titulos t
        {$whereSql}
        ORDER BY t.fecha DESC, t.sys DESC
        LIMIT ? OFFSET ?
    ";

    if ($stmt = $mysqli->prepare($sql)) {
        $bindTypes = $types . 'ii';
        $bindParams = [...$params, $perPage, $offset];
        $stmt->bind_param($bindTypes, ...$bindParams);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    } else {
        $err = $mysqli->error;
    }
}

// -------------------- view --------------------
$pageTitle = 'Buscador básico';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/afdc-table.php';
require_once __DIR__ . '/inc/afdc-table-engine.php';
?>

<section class="card bb">

<style>
/* Header + tooltip (misma idea que avanzado) */
.bb .bb-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
  margin-bottom: 10px;
}
.bb h1{
  margin:0;
  font-size:22px;
  display:flex;
  align-items:center;
  gap:10px;
}
.tip{ position:relative; display:inline-flex; align-items:center; justify-content:center; }
.tip-btn{
  width: 22px; height: 22px; border-radius: 999px;
  border: 1px solid rgba(255,255,255,.18);
  background: rgba(255,255,255,.05);
  color: rgba(255,255,255,.85);
  font-size: 13px; line-height: 1;
  cursor: help;
}
.tip-panel{
  position:absolute; top: 28px; left: 0; z-index: 50;
  width: min(420px, 78vw);
  padding: 10px 12px; border-radius: 12px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(0,0,0,.72);
  color: rgba(255,255,255,.92);
  font-size: 12px; line-height: 1.35;
  display:none;
  box-shadow: 0 8px 24px rgba(0,0,0,.35);
}
.tip-panel ul{ margin:0; padding-left: 16px; }
.tip-panel li{ margin: 3px 0; }
.tip:hover .tip-panel{ display:block; }
.tip.open .tip-panel{ display:block; }

/* Form layout */
.bb .row{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  align-items:flex-end;
}
.bb label{
  display:block;
  font-size:12px;
  color:rgba(255,255,255,.65);
  margin:0 0 6px;
}
.bb input[type="text"], .bb select{
  height:38px;
  padding:0 10px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.12);
  background:rgba(255,255,255,.04);
  color:#e8eaf0;
}
.bb input[type="text"]{ width:100%; }

/* Barra inferior: botones + refinado (como avanzado) */
.bb .bb-actions{
  margin-top: 10px;
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap: 12px;
  flex-wrap:wrap;
}
.bb .bb-actions .group{
  display:flex;
  gap:10px;
  align-items:flex-end;
  flex-wrap:wrap;
}

/* Refinado compacto externo */
#basicTools.afdc-table-tools{
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
}
#basicTools .left{
  display:flex;
  gap:10px;
  align-items:center;
  flex-wrap:wrap;
}
#basicTools input[type="text"]{
  height: 38px;
  width: min(460px, 42vw);
  min-width: 240px;
}
#basicTools .afdc-auto{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:12px;
  color: rgba(255,255,255,.70);
}
/* --- fuerza layout horizontal en tools externos (gana a estilos globales) --- */
#basicTools.afdc-table-tools .left{
  display:flex !important;
  flex-direction:row !important;
  align-items:center !important;
  gap:10px !important;
  flex-wrap:wrap !important;
}

#basicTools.afdc-table-tools .left button,
#basicTools.afdc-table-tools .left .btn{
  width:auto !important;
  white-space:nowrap !important;
}

#basicTools.afdc-table-tools input[data-afdc-filter]{
  width: min(520px, 42vw) !important;
  min-width: 240px !important;
}

</style>

<div class="bb-header">
  <h1>
    Buscador básico
    <span class="tip" id="helpTip">
      <button type="button" class="tip-btn" aria-label="Ayuda" title="Ayuda">ⓘ</button>
      <div class="tip-panel" role="tooltip">
        <ul>
          <li>Busca en <strong>título</strong> y <strong>materias</strong>.</li>
          <li>El refinado es <strong>server-side</strong> y no modifica la URL.</li>
          <li>Enter en el campo principal ejecuta la búsqueda.</li>
        </ul>
      </div>
    </span>
  </h1>
</div>

<form method="get" action="buscador_basico.php" id="basicForm">
  <!-- UI compact para que el endpoint devuelva tabla sin toolbar interna -->
  <input type="hidden" name="ui" value="compact" />
  <input type="hidden" name="page" value="1" />

  <div class="row">
    <div style="flex:1;min-width:320px;">
      <label for="q">Buscar (título o materias)</label>
      <input id="q" name="q" type="text" value="<?= h($q) ?>" placeholder="Ej: Maradona" />
    </div>
  </div>

  <div class="bb-actions">
    <div class="group">
      <div>
        <label for="per_page">Por página</label>
        <select id="per_page" name="per_page">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button class="btn" type="submit">Buscar</button>
      <a class="btn secondary" href="buscador_basico.php">Limpiar</a>
    </div>

    <?php if ($hasSearch): ?>
      <div id="basicTools" class="afdc-table-tools">
        <div class="left">
          <?php
            $pageBarcodes = [];
            foreach ($rows as $r) { if (!empty($r['barcode'])) $pageBarcodes[] = (string)$r['barcode']; }
            $pageBarcodes = array_values(array_unique($pageBarcodes));
            $pageBarcodesJson = htmlspecialchars(json_encode($pageBarcodes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES);
          ?>
          <input
            type="text"
            value="<?= h($filter) ?>"
            placeholder="Refinar…"
            data-afdc-filter
            data-afdc-remote-filter
            data-afdc-remote="ajax"
            data-afdc-minlen="2"
            data-afdc-debounce="420"
          />

          <label class="afdc-auto">
            <input type="checkbox" data-afdc-auto checked />
            Auto
          </label>

          <button type="button" class="btn secondary" data-afdc-apply>Aplicar</button>
          <button type="button" class="btn secondary" data-afdc-clear title="Limpiar refinado">✕</button>
          <button type="button" class="btn" data-afdc-csv>CSV</button>
          <?php if ($logged): ?>
            <?php
              $pageBarcodes = [];
              foreach ($rows as $r) { if (!empty($r['barcode'])) $pageBarcodes[] = (string)$r['barcode']; }
              $pageBarcodes = array_values(array_unique($pageBarcodes));
              $pageBarcodesJson = htmlspecialchars(json_encode($pageBarcodes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES);
            ?>
            <span class="review-dd" data-review-dd style="position:relative; display:inline-flex;">
              <button
                type="button"
                class="btn"
                data-review-toggle
                data-page-barcodes="<?= $pageBarcodesJson ?>"
                data-csrf="<?= h($csrf) ?>"
              >Guardar para revisar ▾</button>
              <div data-review-menu style="display:none; position:absolute; left:0; top:calc(100% + 6px); z-index:9999; background:rgba(0,0,0,.82); border:1px solid rgba(255,255,255,.14); border-radius:12px; padding:6px; min-width:190px; box-shadow:0 12px 30px rgba(0,0,0,.25);">
                <button type="button" class="btn secondary" data-review-action="page" style="width:100%; text-align:left;">Esta página</button>
                <button type="button" class="btn secondary" data-review-action="all"  style="width:100%; text-align:left; margin-top:6px;">Todos</button>
              </div>
            </span>
          <?php endif; ?>
          <span class="small" data-afdc-status style="opacity:.7;"></span>
        </div>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php if (!$hasSearch): ?>

  <p class="small" style="margin-top:14px; opacity:.7;">
    Ingresá un término y presioná <strong>Buscar</strong>.
  </p>

<?php else: ?>

  <?php if ($countErr): ?>
    <div class="error"><?= h($countErr) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="error"><?= h($err) ?></div>
  <?php endif; ?>

  <div data-afdc-results>
    <div class="meta small" style="margin:10px 0;" data-afdc-meta>
      Sobres encontrados: <strong><?= (int)$totalRows ?></strong> — Página <?= (int)$page ?> / <?= (int)$totalPages ?>
    </div>

    <?php
      // En básico (modo compact): toolbar interna OFF, tools externos #basicTools
      render_table_with_tools('buscador', $rows, [
        'filter_param' => 'filter',
        'filter_value' => $filter,
        'filter_label' => 'Refinar resultados',
        'filter_placeholder' => 'Refinar…',
        'remote_mode' => 'ajax',
        'context' => 'basico',
        'total_rows' => $totalRows,
        'show_toolbar' => false,
      ], '#basicTools');
    ?>

    <?php if ($totalPages > 1): ?>
      <div class="pager" aria-label="Paginación">
        <?php $prev = max(1, $page - 1); $next = min($totalPages, $page + 1); ?>
        <a class="pagebtn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(qs(['page' => $prev])) ?>">←</a>

        <?php
          $start = max(1, $page - 3);
          $end   = min($totalPages, $page + 3);
          if ($start > 1) {
            echo '<a class="pagebtn" href="'.h(qs(['page'=>1])).'">1</a>';
            if ($start > 2) echo '<span class="small">…</span>';
          }
          for ($p = $start; $p <= $end; $p++) {
            $cls = $p === $page ? 'pagebtn active' : 'pagebtn';
            echo '<a class="'.$cls.'" href="'.h(qs(['page'=>$p])).'">'.$p.'</a>';
          }
          if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="small">…</span>';
            echo '<a class="pagebtn" href="'.h(qs(['page'=>$totalPages])).'">'.$totalPages.'</a>';
          }
        ?>

        <a class="pagebtn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h(qs(['page' => $next])) ?>">→</a>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>

<script>
(function(){
  const tip = document.getElementById('helpTip');

  // Conjuntos: Guardar para revisar ▾ (Esta página / Todos)
  const dd = document.querySelector('[data-review-dd]');
  if (dd) {
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

    async function run(scope){
      const cs = btn?.getAttribute('data-csrf') || '';
      if (!btn) return;

      btn.disabled = true;
      const old = btn.textContent;
      btn.textContent = 'Guardando...';

      try {
        if (scope === 'page') {
          const raw = btn.getAttribute('data-page-barcodes') || '[]';
          let barcodes = [];
          try { barcodes = JSON.parse(raw) || []; } catch(e) { barcodes = []; }
          if (!barcodes.length) throw new Error('no_barcodes');

          const r = await fetch('api/v2/conjuntos/add-sobres.php', {
            method:'POST',
            credentials:'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': cs },
            body: JSON.stringify({ barcodes })
          });
          const j = await r.json();
          if (!j || j.ok !== true) throw j || {};
        } else {
          const q = document.querySelector('input[name="q"]')?.value || '';
          const filter = document.querySelector('#basicTools [data-afdc-filter]')?.value || '';
          const r = await fetch('api/v2/conjuntos/add-basic-all.php', {
            method:'POST',
            credentials:'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': cs },
            body: JSON.stringify({ q, filter })
          });
          const j = await r.json();
          if (!j || j.ok !== true) throw j || {};
        }

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
  }
  
  // Tooltip: hover (CSS) + toggle touch
  tip?.querySelector('.tip-btn')?.addEventListener('click', (e) => {
    e.preventDefault();
    tip.classList.toggle('open');
  });

  document.addEventListener('click', (e) => {
    if (!tip) return;
    if (!tip.contains(e.target)) tip.classList.remove('open');
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && tip) tip.classList.remove('open');
  });
})();
</script>

</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
