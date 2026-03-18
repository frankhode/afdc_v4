<?php
// buscador_avanzado.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';
$mainClass = 'container-fluid';
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

/**
 * Buscador avanzado:
 * - Múltiples condiciones AND/OR (izq->der con paréntesis)
 * - Refino remoto AJAX (sin tocar URL)
 * - En avanzado, el refinador (Auto/Aplicar/Limpiar/CSV) vive arriba junto a botones generales.
 */

// -------------------- helpers --------------------
function qs(array $overrides = []): string {
  $q = array_merge($_GET, $overrides);
  foreach ($q as $k => $v) {
    if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) unset($q[$k]);
  }
  return '?' . http_build_query($q);
}

function isTruthy($v): bool {
  return $v === '1' || $v === 1 || $v === true || $v === 'on' || $v === 'true';
}

/** Render tabla e inyecta data-afdc-tools si hace falta */
function render_table_with_tools(string $profile, array $rows, array $opts, string $toolsSel): void {
  ob_start();
  afdc_table_render($profile, $rows, $opts);
  $html = ob_get_clean();
  $html = preg_replace('/\bdata-afdc-table\b/', 'data-afdc-table data-afdc-tools="'.htmlspecialchars($toolsSel, ENT_QUOTES).'"', $html, 1);
  echo $html;
}

// -------------------- inputs --------------------
$perPage = (int)($_GET['per_page'] ?? 50);
$page    = max(1, (int)($_GET['page'] ?? 1));
$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
$offset = ($page - 1) * $perPage;

// filtro refinador (remoto)
$filter = trim((string)($_GET['filter'] ?? ''));

$fields = $_GET['field'] ?? [];
$terms  = $_GET['term'] ?? [];
$nots   = $_GET['not'] ?? [];
$ops    = $_GET['op'] ?? [];

if (!is_array($fields)) $fields = [];
if (!is_array($terms))  $terms  = [];
if (!is_array($nots))   $nots   = [];
if (!is_array($ops))    $ops    = [];

$maxRows = max(count($fields), count($terms));
$fields = array_pad($fields, $maxRows, '');
$terms  = array_pad($terms,  $maxRows, '');
$nots   = array_pad($nots,   $maxRows, '');

// -------------------- mapping conditions --------------------
function buildCondition(string $field, string $term): array {
  $field = trim($field);
  $term  = trim($term);
  $like  = '%' . $term . '%';

  switch ($field) {
    case 'Titulo':
      return ['(t.titulo LIKE ?)', 's', [$like]];

    // materias controladas
    case 'Tema':
    case 'Tema650':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 650 AND m.materia LIKE ?))', 's', [$like]];

    case 'Titulo630':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 630 AND m.materia LIKE ?))', 's', [$like]];

    case 'Genero655':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 655 AND m.materia LIKE ?))', 's', [$like]];

    case 'Materias6XX':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo IN (600,610,611,630,650,651,655) AND m.materia LIKE ?))', 's', [$like]];

    case 'Lugar':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 651 AND m.materia LIKE ?))', 's', [$like]];

    case 'Persona':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 600 AND m.materia LIKE ?))', 's', [$like]];

    case 'Entidad':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 610 AND m.materia LIKE ?))', 's', [$like]];

    case 'Evento':
      return ['(EXISTS (SELECT 1 FROM materias m WHERE m.sys = t.sys AND m.campo = 611 AND m.materia LIKE ?))', 's', [$like]];

    case 'Anio':
      return ['(SUBSTRING(t.fecha, 1, 4) LIKE ?)', 's', ['%' . $term . '%']];

    case 'Barcode':
      return ['(t.barcode LIKE ?)', 's', [$like]];

    case 'NroOriginal':
      return ['(t.nroA LIKE ?)', 's', [$like]];

    default:
      return ['(1=0)', '', []];
  }
}

// -------------------- build WHERE (izq->der) --------------------
$conds = [];
for ($i = 0; $i < $maxRows; $i++) {
  $f = (string)$fields[$i];
  $t = trim((string)$terms[$i]);
  if ($t === '') continue;

  [$frag, $tps, $prs] = buildCondition($f, $t);
  $isNot = isset($nots[$i]) && isTruthy($nots[$i]);
  $sql = $isNot ? '(NOT ' . $frag . ')' : $frag;

  $opPrev = '';
  if (count($conds) > 0) {
    $opPrev = strtoupper((string)($ops[$i-1] ?? 'AND'));
    if ($opPrev !== 'OR') $opPrev = 'AND';
  }

  $conds[] = ['sql' => $sql, 'types' => $tps, 'params' => $prs, 'opPrev' => $opPrev];
}

$whereSql = '';
$types = '';
$params = [];

if (count($conds) > 0) {
  $expr = $conds[0]['sql'];
  $types .= $conds[0]['types'];
  $params = array_merge($params, $conds[0]['params']);

  for ($i = 1; $i < count($conds); $i++) {
    $op = $conds[$i]['opPrev'];
    $expr = '(' . $expr . ' ' . $op . ' ' . $conds[$i]['sql'] . ')';
    $types .= $conds[$i]['types'];
    $params = array_merge($params, $conds[$i]['params']);
  }

  $whereSql = 'WHERE ' . $expr;
}

if ($whereSql !== '' && $filter !== '') {
  $f = '%' . $filter . '%';
  $whereSql .= ' AND (
      t.titulo LIKE ?
      OR t.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR EXISTS (SELECT 1 FROM materias mf WHERE mf.sys = t.sys AND mf.materia LIKE ?)
  )';
  $types .= 'sssss';
  array_push($params, $f, $f, $f, $f, $f);
}

// -------------------- db + queries --------------------
$rows = [];
$err = null;
$countErr = null;
$totalRows = 0;
$totalPages = 1;

if ($whereSql !== '') {
  $mysqli = db();

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

  $sql = "
    SELECT
      t.sys, t.titulo, t.barcode, t.fecha,
      COALESCE((
        SELECT GROUP_CONCAT(DISTINCT m.materia ORDER BY m.materia SEPARATOR ' | ')
        FROM materias m
        WHERE m.sys = t.sys
      ), '') AS materias,
      (
        SELECT COUNT(*) FROM digitales d
        WHERE d.inv = t.barcode AND d.carpeta = 'Bajas'
      ) AS digital_count,
      EXISTS(
        SELECT 1 FROM digitales d
        WHERE d.inv = t.barcode AND d.carpeta = 'Altas' LIMIT 1
      ) AS has_alta
    FROM titulos t
    {$whereSql}
    ORDER BY t.fecha DESC, t.sys DESC
    LIMIT ? OFFSET ?
  ";

  if ($stmt = $mysqli->prepare($sql)) {
    $bindTypes = $types . 'ii';
    $bindParams = $params;
    $bindParams[] = $perPage;
    $bindParams[] = $offset;

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
$pageTitle = 'Buscador avanzado';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/afdc-table.php';
require_once __DIR__ . '/inc/afdc-table-engine.php';

$fieldOptions = [
  'Titulo'      => 'Título (Sobre)',
  'Materias6XX' => 'Todas (6XX)',
  'Persona'     => 'Persona (600)',
  'Entidad'     => 'Entidad (610)',
  'Evento'      => 'Evento (611)',
  'Titulo630'   => 'Título (630)',
  'Tema650'     => 'Tema (650)',
  'Lugar'       => 'Lugar (651)',
  'Genero655'   => 'Género/forma (655)',
  'Anio'        => 'Año (YYYY)',
  'Barcode'     => 'Barcode',
  'NroOriginal' => 'Nro original (nroA)',
];

$renderFields = $fields;
$renderTerms  = $terms;
$renderOps    = $ops;

$renderRows = 0;
for ($i=0;$i<$maxRows;$i++){
  if (trim((string)($renderTerms[$i] ?? '')) !== '' || trim((string)($renderFields[$i] ?? '')) !== '') $renderRows = $i+1;
}
if ($renderRows === 0) {
  $renderRows = 1;
  $renderFields = ['Titulo'];
  $renderTerms  = [''];
  $renderOps    = [];
}

?>
<section class="card adv">

<style>
.adv h1{
  margin:0;
  font-size: 22px;
  display:flex;
  align-items:center;
  gap:10px;
}
.adv-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
  margin-bottom: 12px;
}

/* Tooltip */
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

/* Builder */
.builder{ margin-top: 8px; display:flex; flex-direction:column; gap: 8px; }
.builder-head{
  display:grid;
  grid-template-columns: 86px 210px 1fr 46px;
  gap: 10px;
  align-items:end;
  margin-bottom: 2px;
}
.builder-head .lbl{ font-size: 12px; opacity: .75; }
.rowq{
  display:grid;
  grid-template-columns: 86px 210px 1fr 46px;
  gap: 10px;
  align-items:center;
}
.rowq .op select{ min-width: 86px; width: 86px; }
.rowq select, .rowq input{
  height: 36px; padding: 0 10px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: #e8eaf0;
  outline: none;
}
.rowq select{ min-width: 210px; }
.rowq input{ width: 100%; min-width: 280px; }
.rowq .del{
  height: 36px; width: 46px; border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.03);
  color: rgba(255,255,255,.90);
}
.rowq .del:hover{ background: rgba(255,255,255,.06); }

@media (max-width: 900px){
  .builder-head{ grid-template-columns: 86px 1fr 46px; }
  .rowq{ grid-template-columns: 86px 1fr 46px; }
  .rowq select{ min-width: 0; width: 100%; }
  .rowq input{ min-width: 0; }
  .rowq .field{ grid-column: 2; }
  .rowq .text{ grid-column: 2; }
}

/* Barra inferior: botones + refinador en la misma zona */
.adv-actions-bottom{
  margin-top: 10px;
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap: 12px;
  flex-wrap:wrap;
}
.adv-actions-bottom .group{
  display:flex;
  gap:10px;
  align-items:flex-end;
  flex-wrap:wrap;
}
.adv-actions-bottom label{
  display:block;
  font-size:12px;
  opacity:.75;
  margin:0 0 6px;
}
.adv-actions-bottom select{
  height: 36px;
  padding: 0 10px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: #e8eaf0;
  min-width: 120px;
}

/* Refinador compacto */
#advTools.afdc-table-tools{
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
}
#advTools .left{
  min-width: 0;
  flex-direction: row;
  align-items: center;
  gap: 10px;
}
#advTools input[type="text"]{
  height: 36px;
  width: min(460px, 42vw);
  min-width: 240px;
}
#advTools .afdc-auto{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:12px;
  color: rgba(255,255,255,.70);
}
</style>

<div class="adv-header">
  <div style="display:flex; align-items:center; gap:10px;">
    <h1>Buscador avanzado</h1>
    <span class="tip" id="helpTip">
      <button type="button" class="tip-btn" aria-label="Ayuda" title="Ayuda">ⓘ</button>
      <div class="tip-panel" role="tooltip">
        <ul>
          <li>Las filas vacías se ignoran.</li>
          <li>AND/OR se evalúa de izquierda a derecha.</li>
          <li>El refinado es remoto (consulta a la base) y no toca la URL.</li>
        </ul>
      </div>
    </span>
  </div>
</div>

<form method="get" action="buscador_avanzado.php" id="advForm">
  <input type="hidden" name="page" value="1" />

  <div class="builder-head" aria-hidden="true">
    <div></div>
    <div class="lbl">Campo</div>
    <div class="lbl">Texto</div>
    <div></div>
  </div>

  <div class="builder" id="advRows">
    <?php for ($i = 0; $i < $renderRows; $i++): ?>
      <?php
        $f = (string)($renderFields[$i] ?? 'Titulo');
        if (!isset($fieldOptions[$f])) $f = 'Titulo';
        $t = (string)($renderTerms[$i] ?? '');
        $opPrev = strtoupper((string)($renderOps[$i-1] ?? 'AND'));
        if ($opPrev !== 'OR') $opPrev = 'AND';
      ?>
      <div class="rowq" data-row>
        <div class="op">
          <?php if ($i > 0): ?>
            <select name="op[]">
              <option value="AND" <?= $opPrev === 'AND' ? 'selected' : '' ?>>AND</option>
              <option value="OR"  <?= $opPrev === 'OR'  ? 'selected' : '' ?>>OR</option>
            </select>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </div>

        <div class="field">
          <select name="field[]">
            <?php foreach ($fieldOptions as $val => $lab): ?>
              <option value="<?= h($val) ?>" <?= $f === $val ? 'selected' : '' ?>><?= h($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="text">
          <input type="text" name="term[]" value="<?= h($t) ?>" placeholder="Escribí un término…" />
        </div>

        <div>
          <button type="button" class="del" data-del title="Eliminar fila">✕</button>
        </div>
      </div>
    <?php endfor; ?>
  </div>

  <div class="adv-actions-bottom">
    <div class="group">
      <button type="button" class="btn secondary" id="btnAdd">+ Agregar condición</button>
      <button type="submit" class="btn">Buscar</button>
      <a class="btn secondary" href="buscador_avanzado.php">Limpiar</a>

      <div>
        <label for="per_page">Por página</label>
        <select id="per_page" name="per_page">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= (int)$n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= (int)$n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php if ($whereSql !== ''): ?>
      <div id="advTools" class="afdc-table-tools">
        <div class="left">
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

<?php if (!empty($countErr)): ?>
  <div class="error" style="margin-top:10px;"><strong>Error:</strong> <?= h($countErr) ?></div>
<?php endif; ?>

<?php if ($err): ?>
  <div class="error" style="margin-top:10px;"><strong>Error:</strong> <?= h($err) ?></div>
<?php endif; ?>

<?php if ($whereSql === ''): ?>
  <p class="small" style="margin-top:12px; opacity:.75;">
    Agregá una condición y presioná <strong>Buscar</strong>.
  </p>
<?php else: ?>
  <div data-afdc-results>
    <div class="meta small" style="margin:10px 0;" data-afdc-meta>
      Resultados: <strong><?= (int)$totalRows ?></strong> — Página <?= (int)$page ?> / <?= (int)$totalPages ?>
    </div>

    <?php
      // En avanzado: toolbar interna OFF, y tools externos #advTools
      render_table_with_tools('buscador', $rows, [
        'remote_mode' => 'ajax',
        'context' => 'avanzado',
        'total_rows' => $totalRows,
        'show_toolbar' => false,
      ], '#advTools');
    ?>

    <?php if ($totalPages > 1): ?>
      <div class="pager" aria-label="Paginación">
        <?php
          $prev = max(1, $page - 1);
          $next = min($totalPages, $page + 1);
        ?>
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
  const container = document.getElementById('advRows');
  const addBtn = document.getElementById('btnAdd');
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
          // TODOS: estado ACTUAL del builder + filtro refinador
          const rows = Array.from(document.querySelectorAll('#advRows [data-row]'));
          const field = [];
          const term  = [];
          const op    = [];
          const nots  = [];

          rows.forEach((row, idx)=>{
            const f = row.querySelector('select[name="field[]"]')?.value || '';
            const t = row.querySelector('input[name="term[]"]')?.value || '';
            field.push(f);
            term.push(t);
            if (idx > 0) op.push(row.querySelector('select[name="op[]"]')?.value || 'AND');
          });

          const filter = document.querySelector('#advTools [data-afdc-filter]')?.value || '';

          const r = await fetch('api/v2/conjuntos/add-adv-all.php', {
            method:'POST',
            credentials:'same-origin',
            headers: { 'Content-Type':'application/json', 'X-CSRF-Token': cs },
            body: JSON.stringify({ field, term, op, not: nots, filter })
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

  const fieldOptions = <?= json_encode($fieldOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  function el(html){
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  function esc(s){
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function makeFieldOptions(selected){
    let out = '';
    for (const [val, lab] of Object.entries(fieldOptions)){
      const sel = (val === selected) ? ' selected' : '';
      out += `<option value="${esc(val)}"${sel}>${esc(lab)}</option>`;
    }
    return out;
  }

  function rowCount(){
    return container ? container.querySelectorAll('[data-row]').length : 0;
  }

  function addRow(){
    const i = rowCount();
    const hasOp = i > 0;

    const row = el(`
      <div class="rowq" data-row>
        <div class="op">
          ${hasOp ? `
            <select name="op[]">
              <option value="AND">AND</option>
              <option value="OR">OR</option>
            </select>
          ` : '<span></span>'}
        </div>

        <div class="field">
          <select name="field[]">
            ${makeFieldOptions('Titulo')}
          </select>
        </div>

        <div class="text">
          <input type="text" name="term[]" value="" placeholder="Escribí un término…" />
        </div>

        <div>
          <button type="button" class="del" data-del title="Eliminar fila">✕</button>
        </div>
      </div>
    `);

    container.appendChild(row);
    const inp = row.querySelector('input[type="text"]');
    if (inp) inp.focus();
  }

  function normalizeOps(){
    const rows = Array.from(container.querySelectorAll('[data-row]'));

    rows.forEach((r, idx) => {
      const opCell = r.querySelector('.op');
      if (!opCell) return;

      const hasSelect = !!opCell.querySelector('select[name="op[]"]');

      if (idx === 0) {
        if (hasSelect) opCell.innerHTML = '<span></span>';
      } else {
        if (!hasSelect) {
          opCell.innerHTML = `
            <select name="op[]">
              <option value="AND">AND</option>
              <option value="OR">OR</option>
            </select>
          `;
        }
      }
    });

    if (rows.length === 0) addRow();
  }

  function deleteRow(btn){
    const row = btn.closest('[data-row]');
    if (!row) return;
    row.remove();
    normalizeOps();
  }

  addBtn?.addEventListener('click', addRow);

  container?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-del]');
    if (!btn) return;
    deleteRow(btn);
  });

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

  // Focus inicial
  const first = container?.querySelector('[data-row] input[type="text"]');
  if (first && first.value.trim() === '') first.focus();
})();
</script>

</section>
<?php include __DIR__ . '/inc/footer.php'; ?>
