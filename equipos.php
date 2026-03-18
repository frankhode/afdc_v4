<?php
// afdc_v1/equipos.php
require_once __DIR__ . '/inc/bootstrap.php';

/**
 * Fútbol · Equipos
 * - Lista TODOS los equipos (UNION ALL equipo1/equipo2) agrupados
 * - Muestra: Equipo, Sobres (barcodes distintos), Tiene digital (✓/X)
 * - Acción: Ver resultados -> resultados.php?equipo=...
 * - UI: módulo afdc-table (sort/filtro/CSV)
 */

// Nota: en Equipos NO usamos búsqueda server-side; se lista todo.
// Si querés encontrar rápido, usá el filtro cliente del módulo afdc-table.

$mysqli = db();

$countErr = null;
$totalRows = 0;

$sql = "
    SELECT
        e.equipo,
        COUNT(DISTINCT e.barcode) AS sobres,
        MAX(CASE WHEN d.inv IS NULL THEN 0 ELSE 1 END) AS has_digital
    FROM (
        SELECT equipo1 AS equipo, barcode FROM partidos
        UNION ALL
        SELECT equipo2 AS equipo, barcode FROM partidos
    ) e
    LEFT JOIN digitales d
        ON d.inv = e.barcode
       AND d.carpeta IN ('Bajas','Altas')
    WHERE e.equipo <> ''
    GROUP BY e.equipo
    ORDER BY e.equipo ASC
";

$rows = [];
$err = null;

if ($stmt = $mysqli->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    $totalRows = count($rows);
} else {
    $err = $mysqli->error;
}

$pageTitle = 'Fútbol · Equipos';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/afdc-table.php';
?>

<section class="card">
  <h1 style="margin:0 0 12px; font-size:22px;">Fútbol · Equipos</h1>

  

  <?php if (!empty($countErr)): ?>
    <div class="error"><strong>Error:</strong> <?= h($countErr) ?></div>
  <?php endif; ?>

  <?php if ($err): ?>
    <div class="error"><strong>Error:</strong> <?= h($err) ?></div>
  <?php endif; ?>

  <div class="meta" style="display:flex; gap:10px; flex-wrap:wrap; margin: 8px 0 10px; color: rgba(255,255,255,.65); font-size: 13px;">
    <span class="chip">Equipos: <strong><?= (int)$totalRows ?></strong></span>
  </div>

  <div class="afdc-table-wrap" data-afdc-table>

    <div class="afdc-table-tools">
      <div class="left">
        <label>Filtro de tabla (cliente) · no consulta a la base</label>
        <input data-afdc-filter type="text" placeholder="Filtrar equipos (en esta lista)" />
      </div>
      <div class="right">
        <button class="btn" type="button" data-afdc-csv>Exportar CSV</button>
        <button class="btn secondary" type="button" data-afdc-clear>Limpiar</button>
      </div>
    </div>

    <table class="afdc-table" data-afdc-target>
      <thead>
        <tr>
          <th data-sort="text" data-afdc-default="asc">Equipo</th>
          <th class="nowrap" data-sort="num">Sobres</th>
          <th class="nowrap" data-sort="text">Tiene digital</th>
          <th class="nowrap">Acción</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="small">Sin resultados.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $equipo = (string)($r['equipo'] ?? '');
            $sobres = (int)($r['sobres'] ?? 0);
            $has = (int)($r['has_digital'] ?? 0);
          ?>
          <tr>
            <td><?= h($equipo) ?></td>
            <td class="nowrap"><?= (int)$sobres ?></td>
            <td class="nowrap"><?= $has ? '✓' : 'X' ?></td>
            <td class="nowrap"><a href="resultados.php?equipo=<?= urlencode($equipo) ?>">Ver resultados</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>

    <p class="afdc-table-note">Tip: ordená clickeando en los encabezados (se ordena la lista completa).</p>
  </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
