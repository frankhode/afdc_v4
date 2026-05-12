<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Fútbol · Listado · AFDC';
$mainClass = 'container-fluid';

$base = rtrim(BASE_URL, '/');

$tiposValidos = [
    'competencia' => 'Competencias',
    'club_equipo' => 'Clubes / selecciones',
    'jugador' => 'Jugadores',
    'arbitro' => 'Árbitros',
    'entrenador' => 'Entrenadores',
    'general' => 'Fútbol general',
    'arbitro_general' => 'Árbitros / general',
    'entrenador_general' => 'Entrenadores / general',
];

$tipo = (string)g('tipo', 'competencia');
if (!array_key_exists($tipo, $tiposValidos)) {
    $tipo = 'competencia';
}

$qTexto = trim((string)g('q', ''));
$soloDigitalizados = (string)g('digitalizados', '') === '1';

$sqlClasificacion = "
    SELECT
        m.sys,

        MAX(CASE WHEN m.campo = '600' THEN 1 ELSE 0 END) AS has_600,
        MAX(CASE WHEN m.campo = '610' THEN 1 ELSE 0 END) AS has_610,
        MAX(CASE WHEN m.campo = '611' THEN 1 ELSE 0 END) AS has_611,

        MAX(CASE
            WHEN m.campo = '650'
             AND m.materia LIKE 'Futbolistas%'
            THEN 1 ELSE 0 END
        ) AS has_futbolistas,

        MAX(CASE
            WHEN m.campo = '650'
             AND (
                m.materia LIKE 'Árbitros de fútbol%'
                OR m.materia LIKE 'Arbitros de futbol%'
                OR m.materia LIKE 'Arbitros de fútbol%'
                OR m.materia LIKE 'Árbitros de futbol%'
             )
            THEN 1 ELSE 0 END
        ) AS has_arbitros,

        MAX(CASE
            WHEN m.campo = '650'
             AND (
                m.materia LIKE 'Entrenadores de fútbol%'
                OR m.materia LIKE 'Entrenadores de futbol%'
             )
            THEN 1 ELSE 0 END
        ) AS has_entrenadores,

        MAX(CASE
            WHEN m.campo = '650'
             AND (
                m.materia LIKE 'Fútbol%'
                OR m.materia LIKE 'Futbol%'
                OR m.materia LIKE 'Clubes de fútbol%'
                OR m.materia LIKE 'Clubes de futbol%'
                OR m.materia LIKE 'Hinchas de fútbol%'
                OR m.materia LIKE 'Hinchas de futbol%'
                OR m.materia LIKE 'Violencia en el fútbol%'
                OR m.materia LIKE 'Violencia en el futbol%'
                OR m.materia LIKE 'Fútbol infantil%'
                OR m.materia LIKE 'Futbol infantil%'
                OR m.materia LIKE 'Fútbol femenino%'
                OR m.materia LIKE 'Futbol femenino%'
                OR m.materia LIKE 'Fútbol sala%'
                OR m.materia LIKE 'Futbol sala%'
             )
            THEN 1 ELSE 0 END
        ) AS has_futbol,

        MIN(CASE WHEN m.campo IN ('600','610','611') THEN m.materia ELSE NULL END) AS materia_principal
    FROM materias m
    GROUP BY m.sys
";

$sqlBase = "
    SELECT
        c.sys,
        CASE
            WHEN c.has_611 = 1 AND c.has_futbol = 1 THEN 'competencia'
            WHEN c.has_610 = 1 AND (c.has_futbolistas = 1 OR c.has_futbol = 1) THEN 'club_equipo'
            WHEN c.has_600 = 1 AND c.has_arbitros = 1 THEN 'arbitro'
            WHEN c.has_600 = 1 AND c.has_entrenadores = 1 THEN 'entrenador'
            WHEN c.has_600 = 1 AND c.has_futbolistas = 1 THEN 'jugador'
            WHEN c.has_arbitros = 1 THEN 'arbitro_general'
            WHEN c.has_entrenadores = 1 THEN 'entrenador_general'
            WHEN c.has_futbolistas = 1 OR c.has_futbol = 1 THEN 'general'
            ELSE 'otro'
        END AS tipo_futbol,
        COALESCE(c.materia_principal, c.sys) AS materia_principal
    FROM ($sqlClasificacion) c
    WHERE
        c.has_futbolistas = 1
        OR c.has_arbitros = 1
        OR c.has_entrenadores = 1
        OR c.has_futbol = 1
";

$where = "WHERE b.tipo_futbol = ?";
$types = "s";
$params = [$tipo];

if ($qTexto !== '') {
    $where .= " AND (b.materia_principal LIKE ? OR b.sys LIKE ?)";
    $like = '%' . $qTexto . '%';
    $types .= "ss";
    $params[] = $like;
    $params[] = $like;
}

$having = "";
if ($soloDigitalizados) {
    $having = "HAVING digitalizados > 0";
}

$sqlListado = "
    SELECT
        b.tipo_futbol,
        b.sys,
        b.materia_principal,
        COUNT(t.barcode) AS sobres,
        COUNT(DISTINCT NULLIF(t.barcode, '')) AS barcodes,
        COUNT(DISTINCT CASE WHEN d.inv IS NOT NULL THEN t.barcode ELSE NULL END) AS digitalizados,
        COUNT(DISTINCT CASE WHEN d.inv IS NULL AND NULLIF(t.barcode, '') IS NOT NULL THEN t.barcode ELSE NULL END) AS sin_digitalizar,
        MIN(NULLIF(t.fecha, '')) AS fecha_min,
        MAX(NULLIF(t.fecha, '')) AS fecha_max
    FROM ($sqlBase) b
    LEFT JOIN titulos t
        ON t.sys = b.sys
    LEFT JOIN (
        SELECT DISTINCT inv
        FROM digitales
        WHERE inv IS NOT NULL AND inv <> ''
    ) d
        ON d.inv = t.barcode
    $where
    GROUP BY b.tipo_futbol, b.sys, b.materia_principal
    $having
    ORDER BY barcodes DESC, digitalizados DESC, b.materia_principal ASC
    LIMIT 500
";

$rows = q($sqlListado, $types, $params);

function futbol_fecha_humana(?string $fecha): string {
    $fecha = trim((string)$fecha);
    if ($fecha === '') return '—';

    if (!preg_match('/^\d{8}$/', $fecha)) {
        return $fecha;
    }

    $y = (int)substr($fecha, 0, 4);
    $m = (int)substr($fecha, 4, 2);
    $d = (int)substr($fecha, 6, 2);

    if ($y < 1900 || $y > 2100) return $fecha;
    if ($m < 0 || $m > 12) return $fecha;
    if ($d < 0 || $d > 31) return $fecha;

    if ($m === 0 && $d === 0) return (string)$y;
    if ($m > 0 && $d === 0) return str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '/' . $y;
    if ($m === 0 && $d > 0) return $fecha;

    return str_pad((string)$d, 2, '0', STR_PAD_LEFT)
        . '/'
        . str_pad((string)$m, 2, '0', STR_PAD_LEFT)
        . '/'
        . $y;
}

function futbol_num($n): string {
    return number_format((int)$n, 0, ',', '.');
}
function futbol_sobres_tipo_url(string $tipo): string {
    return rtrim(BASE_URL, '/') . '/futbol_sobres.php?tipo=' . urlencode($tipo);
}

include __DIR__ . '/inc/header.php';
?>

<style>
.futbol-list {
  display: grid;
  gap: 16px;
  color: var(--afdc-text);
}

.futbol-list__head,
.futbol-list__toolbar,
.futbol-panel {
  border: 1px solid var(--afdc-border);
  background: var(--afdc-card);
  color: var(--afdc-text);
}

.futbol-list__head {
  padding: 16px;
}

.futbol-list__kicker {
  color: var(--afdc-muted);
  font-size: .85rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 6px;
}

.futbol-list__title {
  margin: 0;
  font-size: clamp(1.6rem, 2.5vw, 2.4rem);
  color: var(--afdc-text);
}

.futbol-list__toolbar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: end;
  padding: 12px;
}

.futbol-field {
  display: grid;
  gap: 4px;
}

.futbol-field label {
  font-size: .8rem;
  color: var(--afdc-muted);
}

.futbol-field input,
.futbol-field select {
  min-height: 38px;
  padding: 6px 8px;
  border: 1px solid var(--afdc-border);
  background: var(--afdc-btn);
  color: var(--afdc-text);
  border-radius: 10px;
}

.futbol-field input::placeholder {
  color: var(--afdc-muted);
}

.futbol-field input:focus,
.futbol-field select:focus {
  outline: 2px solid var(--afdc-link);
  outline-offset: 1px;
}

.futbol-check {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  min-height: 38px;
  color: var(--afdc-text);
}

.futbol-panel {
  padding: 14px;
}

.futbol-table-wrap {
  overflow-x: auto;
}

.futbol-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .92rem;
  color: var(--afdc-text);
}

.futbol-table th,
.futbol-table td {
  border-bottom: 1px solid var(--afdc-border);
  padding: 8px 10px;
  text-align: left;
  vertical-align: top;
}

.futbol-table th {
  font-size: .8rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--afdc-muted);
}

.futbol-table td.num,
.futbol-table th.num {
  text-align: right;
  white-space: nowrap;
}

.futbol-muted {
  color: var(--afdc-muted);
}

.futbol-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.futbol-link {
  color: var(--afdc-link);
}
</style>

<section class="futbol-list">
  <header class="futbol-list__head">
    <div class="futbol-list__kicker">AFDC · Fútbol</div>
    <h1 class="futbol-list__title"><?= h($tiposValidos[$tipo]) ?></h1>
    <p class="futbol-muted">
      Listado construido desde materias 6XX, cruzado con títulos y digitalización.
    </p>

    <div class="futbol-actions">
      <a class="btn" href="<?= h($base) ?>/futbol.php">Volver a fútbol</a>
      <a class="btn" href="<?= h(futbol_sobres_tipo_url($tipo)) ?>">Ver sobres de este tipo</a>
      <a class="btn" href="<?= h($base) ?>/futbol_sobres.php">Todos los sobres</a>
    </div>
  </header>

  <form class="futbol-list__toolbar" method="get" action="<?= h($base) ?>/futbol_listado.php">
    <div class="futbol-field">
      <label for="tipo">Tipo</label>
      <select id="tipo" name="tipo">
        <?php foreach ($tiposValidos as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $k === $tipo ? 'selected' : '' ?>>
            <?= h($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="futbol-field" style="min-width:260px; flex:1;">
      <label for="q">Filtrar</label>
      <input
        id="q"
        type="search"
        name="q"
        value="<?= h($qTexto) ?>"
        placeholder="Buscar por materia principal o SYS"
      >
    </div>

    <label class="futbol-check">
      <input type="checkbox" name="digitalizados" value="1" <?= $soloDigitalizados ? 'checked' : '' ?>>
      Solo con digitalización
    </label>

    <button class="btn" type="submit">Aplicar</button>
  </form>

  <section class="futbol-panel">
    <div class="futbol-table-wrap">
      <table class="futbol-table">
        <thead>
          <tr>
            <th>SYS</th>
            <th>Materia principal</th>
            <th class="num">Sobres</th>
            <th class="num">Barcodes</th>
            <th class="num">Digitalizados</th>
            <th class="num">Sin digitalizar</th>
            <th>Rango</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h((string)$row['sys']) ?></td>
              <td>
                <a class="futbol-link" href="<?= h($base) ?>/futbol_registro.php?sys=<?= urlencode((string)$row['sys']) ?>">
                  <?= h((string)$row['materia_principal']) ?>
                </a>
                <div class="futbol-muted" style="margin-top:4px;">
                  <a class="futbol-link" href="<?= h($base) ?>/futbol_sobres.php?tipo=<?= urlencode((string)$row['tipo_futbol']) ?>&q=<?= urlencode((string)$row['materia_principal']) ?>">
                    buscar sobres
                  </a>
                </div>
              </td>
              <td class="num"><?= futbol_num($row['sobres']) ?></td>
              <td class="num"><?= futbol_num($row['barcodes']) ?></td>
              <td class="num"><?= futbol_num($row['digitalizados']) ?></td>
              <td class="num"><?= futbol_num($row['sin_digitalizar']) ?></td>
              <td>
                <?= h(futbol_fecha_humana($row['fecha_min'] ?? '')) ?>
                <span class="futbol-muted">→</span>
                <?= h(futbol_fecha_humana($row['fecha_max'] ?? '')) ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" class="futbol-muted">
                No se encontraron registros para este filtro.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <p class="futbol-muted">
      Mostrando hasta 500 registros. Si hace falta, después agregamos paginación.
    </p>
  </section>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>