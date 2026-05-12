<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Fútbol · Sobres · AFDC';
$mainClass = 'container-fluid';

$base = rtrim(BASE_URL, '/');

$tiposValidos = [
    '' => 'Todos',
    'competencia' => 'Competencias',
    'club_equipo' => 'Clubes / selecciones',
    'jugador' => 'Jugadores',
    'arbitro' => 'Árbitros',
    'entrenador' => 'Entrenadores',
    'general' => 'Fútbol general',
    'arbitro_general' => 'Árbitros / general',
    'entrenador_general' => 'Entrenadores / general',
];

$digitalValidos = [
    '' => 'Todos',
    '1' => 'Digitalizados',
    '0' => 'Sin digitalizar',
];

$fechaValidos = [
    '' => 'Todas',
    'completa' => 'Fecha completa',
    'sin_fecha' => 'Sin fecha',
    'parcial_dudosa' => 'Fecha parcial / dudosa',
];

$ordenValidos = [
    'fecha_desc' => 'Fecha descendente',
    'fecha_asc' => 'Fecha ascendente',
    'titulo_asc' => 'Título',
    'barcode_asc' => 'Barcode',
    'registro_asc' => 'Registro',
];

$tipo = (string)g('tipo', '');
$anio = trim((string)g('anio', ''));
$digital = (string)g('digital', '');
$fechaFiltro = (string)g('fecha', '');
$qTexto = trim((string)g('q', ''));
$orden = (string)g('orden', 'fecha_desc');

if (!array_key_exists($tipo, $tiposValidos)) {
    $tipo = '';
}

if (!array_key_exists($digital, $digitalValidos)) {
    $digital = '';
}

if (!array_key_exists($fechaFiltro, $fechaValidos)) {
    $fechaFiltro = '';
}

if (!array_key_exists($orden, $ordenValidos)) {
    $orden = 'fecha_desc';
}

if ($anio !== '' && !preg_match('/^\d{4}$/', $anio)) {
    $anio = '';
}

function futbol_tipo_label(string $tipo): string {
    return match ($tipo) {
        'competencia' => 'Competencia',
        'club_equipo' => 'Club / selección',
        'jugador' => 'Jugador',
        'arbitro' => 'Árbitro',
        'entrenador' => 'Entrenador',
        'general' => 'Fútbol general',
        'arbitro_general' => 'Árbitro / general',
        'entrenador_general' => 'Entrenador / general',
        default => 'Fútbol',
    };
}

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

function futbol_fecha_es_completa(?string $fecha): bool {
    $fecha = trim((string)$fecha);

    if (!preg_match('/^\d{8}$/', $fecha)) {
        return false;
    }

    $y = (int)substr($fecha, 0, 4);
    $m = (int)substr($fecha, 4, 2);
    $d = (int)substr($fecha, 6, 2);

    return $y >= 1900 && $y <= 2100 && checkdate($m, $d, $y);
}

function futbol_num($n): string {
    return number_format((int)$n, 0, ',', '.');
}

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

$where = "WHERE b.tipo_futbol <> 'otro'";
$types = "";
$params = [];

if ($tipo !== '') {
    $where .= " AND b.tipo_futbol = ?";
    $types .= "s";
    $params[] = $tipo;
}

if ($anio !== '') {
    $where .= " AND t.fecha LIKE ?";
    $types .= "s";
    $params[] = $anio . '%';
}

if ($digital === '1') {
    $where .= " AND d.inv IS NOT NULL";
} elseif ($digital === '0') {
    $where .= " AND d.inv IS NULL";
}

if ($fechaFiltro === 'completa') {
    $where .= "
        AND t.fecha REGEXP '^[0-9]{8}$'
        AND CAST(SUBSTRING(t.fecha, 1, 4) AS UNSIGNED) BETWEEN 1900 AND 2100
        AND CAST(SUBSTRING(t.fecha, 5, 2) AS UNSIGNED) BETWEEN 1 AND 12
        AND CAST(SUBSTRING(t.fecha, 7, 2) AS UNSIGNED) BETWEEN 1 AND 31
    ";
} elseif ($fechaFiltro === 'sin_fecha') {
    $where .= " AND (t.fecha IS NULL OR t.fecha = '')";
} elseif ($fechaFiltro === 'parcial_dudosa') {
    $where .= "
        AND t.fecha IS NOT NULL
        AND t.fecha <> ''
        AND NOT (
            t.fecha REGEXP '^[0-9]{8}$'
            AND CAST(SUBSTRING(t.fecha, 1, 4) AS UNSIGNED) BETWEEN 1900 AND 2100
            AND CAST(SUBSTRING(t.fecha, 5, 2) AS UNSIGNED) BETWEEN 1 AND 12
            AND CAST(SUBSTRING(t.fecha, 7, 2) AS UNSIGNED) BETWEEN 1 AND 31
        )
    ";
}

if ($qTexto !== '') {
    $where .= "
        AND (
            b.materia_principal LIKE ?
            OR b.sys LIKE ?
            OR t.titulo LIKE ?
            OR t.barcode LIKE ?
            OR t.nroA LIKE ?
            OR t.ufi LIKE ?
        )
    ";

    $like = '%' . $qTexto . '%';
    $types .= "ssssss";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$orderBy = match ($orden) {
    'fecha_asc' => "
        CASE WHEN t.fecha IS NULL OR t.fecha = '' THEN 1 ELSE 0 END ASC,
        t.fecha ASC,
        t.titulo ASC
    ",
    'titulo_asc' => "t.titulo ASC, t.fecha ASC",
    'barcode_asc' => "t.barcode ASC, t.fecha ASC",
    'registro_asc' => "b.materia_principal ASC, t.fecha ASC",
    default => "
        CASE WHEN t.fecha IS NULL OR t.fecha = '' THEN 1 ELSE 0 END ASC,
        t.fecha DESC,
        t.titulo ASC
    ",
};

$sqlSobres = "
    SELECT
        b.tipo_futbol,
        b.sys,
        b.materia_principal,
        t.titulo,
        t.nroA,
        t.barcode,
        t.ufi,
        t.fecha,
        CASE WHEN d.inv IS NOT NULL THEN 1 ELSE 0 END AS digitalizado
    FROM ($sqlBase) b
    INNER JOIN titulos t
        ON t.sys = b.sys
    LEFT JOIN (
        SELECT DISTINCT inv
        FROM digitales
        WHERE inv IS NOT NULL AND inv <> ''
    ) d
        ON d.inv = t.barcode
    $where
    ORDER BY $orderBy
    LIMIT 1000
";

$rows = q($sqlSobres, $types, $params);

$sqlResumen = "
    SELECT
        COUNT(*) AS sobres,
        COUNT(DISTINCT NULLIF(t.barcode, '')) AS barcodes,
        COUNT(DISTINCT CASE WHEN d.inv IS NOT NULL THEN t.barcode ELSE NULL END) AS digitalizados,
        COUNT(DISTINCT CASE WHEN d.inv IS NULL AND NULLIF(t.barcode, '') IS NOT NULL THEN t.barcode ELSE NULL END) AS sin_digitalizar
    FROM ($sqlBase) b
    INNER JOIN titulos t
        ON t.sys = b.sys
    LEFT JOIN (
        SELECT DISTINCT inv
        FROM digitales
        WHERE inv IS NOT NULL AND inv <> ''
    ) d
        ON d.inv = t.barcode
    $where
";

$resumenRows = q($sqlResumen, $types, $params);
$resumen = $resumenRows[0] ?? [
    'sobres' => 0,
    'barcodes' => 0,
    'digitalizados' => 0,
    'sin_digitalizar' => 0,
];

include __DIR__ . '/inc/header.php';
?>

<style>
.futbol-sobres {
  display: grid;
  gap: 16px;
  color: var(--afdc-text);
}

.futbol-sobres__head,
.futbol-card,
.futbol-panel {
  border: 1px solid var(--afdc-border);
  background: var(--afdc-card);
  color: var(--afdc-text);
}

.futbol-sobres__head {
  padding: 16px;
}

.futbol-sobres__kicker {
  color: var(--afdc-muted);
  font-size: .85rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 6px;
}

.futbol-sobres__title {
  margin: 0;
  font-size: clamp(1.6rem, 2.5vw, 2.4rem);
  color: var(--afdc-text);
}

.futbol-muted {
  color: var(--afdc-muted);
}

.futbol-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 12px;
}

.futbol-cards {
  display: grid;
  grid-template-columns: repeat(4, minmax(140px, 1fr));
  gap: 12px;
}

.futbol-card {
  padding: 14px;
}

.futbol-card__label {
  font-size: .82rem;
  color: var(--afdc-muted);
  margin-bottom: 8px;
}

.futbol-card__value {
  font-size: 1.55rem;
  font-weight: 700;
  color: var(--afdc-text);
}

.futbol-panel {
  padding: 14px;
}

.futbol-panel h2 {
  margin: 0 0 12px;
  font-size: 1.15rem;
  color: var(--afdc-text);
}

.futbol-form {
  display: grid;
  gap: 12px;
}

.futbol-form-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: end;
}

.futbol-field {
  display: grid;
  gap: 4px;
  flex: 0 1 180px;
}

.futbol-field--year {
  flex-basis: 120px;
}

.futbol-field--text {
  flex: 1 1 420px;
  min-width: 320px;
}

.futbol-field label {
  font-size: .8rem;
  color: var(--afdc-muted);
}

.futbol-field input,
.futbol-field select {
  width: 100%;
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

.futbol-form .btn {
  white-space: nowrap;
}

.futbol-table-wrap {
  overflow-x: auto;
}

.futbol-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .9rem;
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
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--afdc-muted);
}

.futbol-table td.num,
.futbol-table th.num {
  text-align: right;
  white-space: nowrap;
}

.futbol-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  border: 1px solid var(--afdc-border);
  border-radius: 999px;
  font-size: .8rem;
  white-space: nowrap;
  background: var(--afdc-btn);
  color: var(--afdc-text);
}

.futbol-link {
  color: var(--afdc-link);
}

.futbol-title-cell {
  min-width: 320px;
}

.futbol-reg-cell {
  min-width: 240px;
}

.futbol-table .btn {
  height: 34px;
  padding: 0 10px;
  border-radius: 10px;
}

.futbol-row-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.futbol-digital-ok {
  font-weight: 700;
  color: var(--afdc-text);
}

.futbol-digital-no {
  color: var(--afdc-muted);
}

@media (max-width: 760px) {
  .futbol-cards {
    grid-template-columns: repeat(2, minmax(140px, 1fr));
  }

  .futbol-form-row {
    display: grid;
    grid-template-columns: 1fr;
  }

  .futbol-field,
  .futbol-field--year,
  .futbol-field--text,
  .futbol-form .btn {
    width: 100%;
    min-width: 0;
  }
}

@media (max-width: 520px) {
  .futbol-cards {
    grid-template-columns: 1fr;
  }
}
</style>

<section class="futbol-sobres">
  <header class="futbol-sobres__head">
    <div class="futbol-sobres__kicker">AFDC · Fútbol</div>
    <h1 class="futbol-sobres__title">Sobres futbolísticos</h1>

    <p class="futbol-muted">
      Tablero transversal construido desde materias 6XX, títulos y digitalización.
      Permite revisar el universo fútbol completo, no solo un registro puntual.
    </p>

    <div class="futbol-actions">
      <a class="btn" href="<?= h($base) ?>/futbol.php">Volver a fútbol</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=competencia">Competencias</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=club_equipo">Clubes / selecciones</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=jugador">Jugadores</a>
    </div>
  </header>

  <div class="futbol-cards">
    <div class="futbol-card">
      <div class="futbol-card__label">Sobres / títulos</div>
      <div class="futbol-card__value"><?= futbol_num($resumen['sobres'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Barcodes únicos</div>
      <div class="futbol-card__value"><?= futbol_num($resumen['barcodes'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Digitalizados</div>
      <div class="futbol-card__value"><?= futbol_num($resumen['digitalizados'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Sin digitalizar</div>
      <div class="futbol-card__value"><?= futbol_num($resumen['sin_digitalizar'] ?? 0) ?></div>
    </div>
  </div>

  <section class="futbol-panel">
    <h2>Filtros</h2>

    <form class="futbol-form" method="get" action="<?= h($base) ?>/futbol_sobres.php">
      <div class="futbol-form-row">
        <div class="futbol-field">
          <label for="tipo">Tipo</label>
          <select id="tipo" name="tipo">
            <option value="" <?= $tipo === '' ? 'selected' : '' ?>>Todos</option>
            <option value="competencia" <?= $tipo === 'competencia' ? 'selected' : '' ?>>Competencias</option>
            <option value="club_equipo" <?= $tipo === 'club_equipo' ? 'selected' : '' ?>>Clubes / selecciones</option>
            <option value="jugador" <?= $tipo === 'jugador' ? 'selected' : '' ?>>Jugadores</option>
            <option value="arbitro" <?= $tipo === 'arbitro' ? 'selected' : '' ?>>Árbitros</option>
            <option value="entrenador" <?= $tipo === 'entrenador' ? 'selected' : '' ?>>Entrenadores</option>
            <option value="general" <?= $tipo === 'general' ? 'selected' : '' ?>>Fútbol general</option>
          </select>
        </div>

        <div class="futbol-field futbol-field--year">
          <label for="anio">Año</label>
          <input id="anio" type="search" name="anio" value="<?= h($anio) ?>" placeholder="1975" autocomplete="off">
        </div>

        <div class="futbol-field">
          <label for="digital">Digitalización</label>
          <select id="digital" name="digital">
            <option value="" <?= $digital === '' ? 'selected' : '' ?>>Todos</option>
            <option value="1" <?= $digital === '1' ? 'selected' : '' ?>>Digitalizados</option>
            <option value="0" <?= $digital === '0' ? 'selected' : '' ?>>Sin digitalizar</option>
          </select>
        </div>

        <div class="futbol-field">
          <label for="fecha">Fecha</label>
          <select id="fecha" name="fecha">
            <option value="" <?= $fechaFiltro === '' ? 'selected' : '' ?>>Todas</option>
            <option value="completa" <?= $fechaFiltro === 'completa' ? 'selected' : '' ?>>Fecha completa</option>
            <option value="sin_fecha" <?= $fechaFiltro === 'sin_fecha' ? 'selected' : '' ?>>Sin fecha</option>
            <option value="parcial_dudosa" <?= $fechaFiltro === 'parcial_dudosa' ? 'selected' : '' ?>>Fecha parcial / dudosa</option>
          </select>
        </div>
      </div>

      <div class="futbol-form-row">
        <div class="futbol-field futbol-field--text">
          <label for="q">Texto</label>
          <input
            id="q"
            type="search"
            name="q"
            value="<?= h($qTexto) ?>"
            placeholder="Título, materia, barcode, nroA, SYS..."
            autocomplete="off"
          >
        </div>

        <div class="futbol-field">
          <label for="orden">Orden</label>
          <select id="orden" name="orden">
            <option value="fecha_desc" <?= $orden === 'fecha_desc' ? 'selected' : '' ?>>Fecha descendente</option>
            <option value="fecha_asc" <?= $orden === 'fecha_asc' ? 'selected' : '' ?>>Fecha ascendente</option>
            <option value="titulo_asc" <?= $orden === 'titulo_asc' ? 'selected' : '' ?>>Título</option>
            <option value="barcode_asc" <?= $orden === 'barcode_asc' ? 'selected' : '' ?>>Barcode</option>
            <option value="registro_asc" <?= $orden === 'registro_asc' ? 'selected' : '' ?>>Registro</option>
          </select>
        </div>

        <button class="btn" type="submit">Aplicar</button>
      </div>
    </form>

    <div class="futbol-actions">
      <a class="btn" href="<?= h($base) ?>/futbol_sobres.php">Limpiar filtros</a>
      <span class="futbol-muted">Mostrando hasta 1.000 sobres.</span>
    </div>
  </section>

  <section class="futbol-panel">
    <h2>Resultados</h2>

    <div class="futbol-table-wrap">
      <table class="futbol-table">
        <thead>
          <tr>
            <th>Tipo</th>
            <th>Fecha</th>
            <th>Registro</th>
            <th>Título</th>
            <th>Barcode</th>
            <th>Digital</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $barcode = trim((string)($row['barcode'] ?? ''));
              $digitalizado = (int)($row['digitalizado'] ?? 0) === 1;
              $sysRow = (string)($row['sys'] ?? '');
            ?>
            <tr>
              <td>
                <span class="futbol-badge">
                  <?= h(futbol_tipo_label((string)$row['tipo_futbol'])) ?>
                </span>
              </td>

              <td><?= h(futbol_fecha_humana($row['fecha'] ?? '')) ?></td>

              <td class="futbol-reg-cell">
                <a class="futbol-link" href="<?= h($base) ?>/futbol_registro.php?sys=<?= urlencode($sysRow) ?>">
                  <?= h((string)$row['materia_principal']) ?>
                </a>
                <div class="futbol-muted">SYS <?= h($sysRow) ?></div>
              </td>

              <td class="futbol-title-cell">
                <?= h((string)($row['titulo'] ?? '')) ?>
                <?php if (!empty($row['nroA'])): ?>
                  <div class="futbol-muted"><?= h((string)$row['nroA']) ?></div>
                <?php endif; ?>
              </td>

              <td><?= h($barcode) ?></td>

              <td>
                <?php if ($digitalizado): ?>
                  <span class="futbol-digital-ok">sí</span>
                <?php else: ?>
                  <span class="futbol-digital-no">no</span>
                <?php endif; ?>
              </td>

              <td>
                <div class="futbol-row-actions">
                  <a class="btn" href="<?= h($base) ?>/futbol_registro.php?sys=<?= urlencode($sysRow) ?>">
                    Registro
                  </a>

                  <?php if ($digitalizado && $barcode !== ''): ?>
                    <a class="btn" href="<?= h($base) ?>/ver_digital.php?barcode=<?= urlencode($barcode) ?>&i=0">
                      Ver digital
                    </a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" class="futbol-muted">
                No se encontraron sobres con esos filtros.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>