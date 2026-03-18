<?php
// afdc_v1/campeonatos.php
require_once __DIR__ . '/inc/bootstrap.php';

/**
 * Campeonatos (Fútbol)
 * - Lista campeonatos (partidos.tituloReg)
 * - Muestra: Campeonato, Año (regex), Sobres (barcodes distintos), Tiene digital (✓/X)
 * - Acción: Ver resultados -> resultados.php?campeonato=...
 * - UI: módulo afdc-table (sort/filtro/CSV)
 */

function qs(array $overrides = []): string {
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && count($v) === 0)) unset($q[$k]);
    }
    return '?' . http_build_query($q);
}

function extract_year(string $s): string {
    if (preg_match('/\b(\d{4})\b/u', $s, $m)) return $m[1];
    return '';
}

$q       = trim((string)($_GET['q'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 50);
$page    = max(1, (int)($_GET['page'] ?? 1));

$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;

$offset = ($page - 1) * $perPage;

$mysqli = db();

$whereSql = '';
$types = '';
$params = [];

if ($q !== '') {
    $whereSql = "WHERE p.tituloReg LIKE ?";
    $types = 's';
    $params[] = '%' . $q . '%';
}

$sqlCount = "
    SELECT COUNT(DISTINCT p.tituloReg) AS c
    FROM partidos p
    {$whereSql}
";

$totalRows = 0;
$countErr = null;

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
        p.tituloReg,
        COUNT(DISTINCT p.barcode) AS sobres,
        MAX(CASE WHEN d.inv IS NULL THEN 0 ELSE 1 END) AS has_digital
    FROM partidos p
    LEFT JOIN digitales d
        ON d.inv = p.barcode
       AND d.carpeta IN ('Bajas','Altas')
    {$whereSql}
    GROUP BY p.tituloReg
    ORDER BY p.tituloReg ASC
    LIMIT ? OFFSET ?
";

$rows = [];
$err = null;

if ($stmt = $mysqli->prepare($sql)) {
    $bindTypes = $types . 'ii';
    $bindParams = $params + [];  // copy
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

$pageTitle = 'Fútbol · Campeonatos';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/afdc-table.php';
?>

<section class="card">
  <h1 style="margin:0 0 12px; font-size:22px;">Fútbol · Campeonatos</h1>

  <form method="get" action="campeonatos.php" style="margin-bottom:12px;">
    <div class="row" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <div style="flex:1; min-width:320px;">
        <label for="q" style="display:block; font-size:12px; color:rgba(255,255,255,.65); margin:0 0 6px;">Filtrar por texto</label>
        <input id="q" name="q" type="text" value="<?= h($q) ?>" placeholder="Ej: Metropolitano 1978" style="height:38px; padding:0 10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#e8eaf0; outline:none; width:min(560px,100%);" />
      </div>

      <div>
        <label for="per_page" style="display:block; font-size:12px; color:rgba(255,255,255,.65); margin:0 0 6px;">Por página</label>
        <select id="per_page" name="per_page" style="height:38px; padding:0 10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#e8eaf0; outline:none;">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div><button class="btn" type="submit">Buscar</button></div>
      <div><a class="btn secondary" href="campeonatos.php">Limpiar</a></div>
    </div>
    <input type="hidden" name="page" value="1" />
  </form>

  <?php if (!empty($countErr)): ?>
    <div class="error"><strong>Error:</strong> <?= h($countErr) ?></div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="error"><strong>Error:</strong> <?= h($err) ?></div>
  <?php endif; ?>

  <div class="meta" style="display:flex; gap:10px; flex-wrap:wrap; margin: 8px 0 10px; color: rgba(255,255,255,.65); font-size: 13px;">
    <span class="chip">Campeonatos: <strong><?= (int)$totalRows ?></strong></span>
    <span class="crumbs">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
  </div>

  <div class="afdc-table-wrap" data-afdc-table>

    <!--<div class="afdc-table-tools">
      <div class="left">
        <label>Filtro de tabla (cliente) · no consulta a la base</label>
        <input data-afdc-filter type="text" placeholder="Filtrar filas visibles (solo esta página)" />
      </div>
      <div class="right">
        <button class="btn" type="button" data-afdc-csv>Exportar CSV</button>
        <button class="btn secondary" type="button" data-afdc-clear>Limpiar</button>
      </div>
    </div>-->

    <table class="afdc-table" data-afdc-target>
      <thead>
        <tr>
          <th data-sort="text" data-afdc-default="asc">Campeonato</th>
          <th class="nowrap" data-sort="num">Año</th>
          <th class="nowrap" data-sort="num">Sobres</th>
          <th class="nowrap" data-sort="text">Tiene digital</th>
          <th class="nowrap">Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="small">Sin resultados.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $tituloReg = (string)($r['tituloReg'] ?? '');
            $anio = extract_year($tituloReg);
            $sobres = (int)($r['sobres'] ?? 0);
            $has = (int)($r['has_digital'] ?? 0);
          ?>
          <tr>
            <td><?= h($tituloReg) ?></td>
            <td class="nowrap"><?= h($anio) ?></td>
            <td class="nowrap"><?= (int)$sobres ?></td>
            <td class="nowrap"><?= $has ? '✓' : 'X' ?></td>
            <td class="nowrap">
              <a href="resultados.php?campeonato=<?= urlencode($tituloReg) ?>">Ver resultados</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>

    <?php if ($totalPages > 1): ?>
      <div class="pager" aria-label="Paginación" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:14px;">
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

    <p class="afdc-table-note">Tip: ordená clickeando en los encabezados (se ordena solo la página actual).</p>
  </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
