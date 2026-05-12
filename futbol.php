<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Fútbol · AFDC';
$mainClass = 'container-fluid';

$base = rtrim(BASE_URL, '/');

/**
 * Clasificación futbolística basada en materias 6XX.
 *
 * Criterios principales:
 * - 600 + 650 Futbolistas...              => jugador
 * - 600 + 650 Árbitros de fútbol...       => árbitro
 * - 600 + 650 Entrenadores de fútbol...   => entrenador
 * - 610 + 650 Futbolistas / Clubes fútbol => club_equipo
 * - 611 + 650 Fútbol...                   => competencia
 * - 650 futbolística sin entidad clara    => general
 */

$sqlClasificacion = "
    SELECT
        m.sys,

        MAX(CASE WHEN m.campo = '600' THEN 1 ELSE 0 END) AS has_600,
        MAX(CASE WHEN m.campo = '610' THEN 1 ELSE 0 END) AS has_610,
        MAX(CASE WHEN m.campo = '611' THEN 1 ELSE 0 END) AS has_611,

        MAX(CASE
            WHEN m.campo = '650'
             AND (
                m.materia LIKE 'Futbolistas%'
             )
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

$sqlStats = "
    SELECT
        b.tipo_futbol,
        COUNT(DISTINCT b.sys) AS registros,
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
    WHERE b.tipo_futbol <> 'otro'
    GROUP BY b.tipo_futbol
    ORDER BY
        FIELD(
            b.tipo_futbol,
            'competencia',
            'club_equipo',
            'jugador',
            'arbitro',
            'entrenador',
            'general',
            'arbitro_general',
            'entrenador_general'
        )
";

$sqlTotales = "
    SELECT
        COUNT(DISTINCT b.sys) AS registros,
        COUNT(t.barcode) AS sobres,
        COUNT(DISTINCT NULLIF(t.barcode, '')) AS barcodes,
        COUNT(DISTINCT CASE WHEN d.inv IS NOT NULL THEN t.barcode ELSE NULL END) AS digitalizados,
        COUNT(DISTINCT CASE WHEN d.inv IS NULL AND NULLIF(t.barcode, '') IS NOT NULL THEN t.barcode ELSE NULL END) AS sin_digitalizar
    FROM ($sqlBase) b
    LEFT JOIN titulos t
        ON t.sys = b.sys
    LEFT JOIN (
        SELECT DISTINCT inv
        FROM digitales
        WHERE inv IS NOT NULL AND inv <> ''
    ) d
        ON d.inv = t.barcode
    WHERE b.tipo_futbol <> 'otro'
";

$sqlTop = "
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
    WHERE b.tipo_futbol <> 'otro'
    GROUP BY b.tipo_futbol, b.sys, b.materia_principal
    HAVING barcodes > 0
    ORDER BY barcodes DESC, digitalizados DESC, b.materia_principal ASC
    LIMIT 80
";

$stats = q($sqlStats);
$totalesRows = q($sqlTotales);
$totales = $totalesRows[0] ?? [
    'registros' => 0,
    'sobres' => 0,
    'barcodes' => 0,
    'digitalizados' => 0,
    'sin_digitalizar' => 0,
];

$top = q($sqlTop);

function futbol_tipo_label(string $tipo): string {
    return match ($tipo) {
        'competencia' => 'Competencias',
        'club_equipo' => 'Clubes / selecciones',
        'jugador' => 'Jugadores',
        'arbitro' => 'Árbitros',
        'entrenador' => 'Entrenadores',
        'general' => 'Fútbol general',
        'arbitro_general' => 'Árbitros / general',
        'entrenador_general' => 'Entrenadores / general',
        default => $tipo,
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

    if ($y < 1900 || $y > 2100) {
        return $fecha;
    }

    if ($m < 0 || $m > 12) {
        return $fecha;
    }

    if ($d < 0 || $d > 31) {
        return $fecha;
    }

    if ($m === 0 && $d === 0) {
        return (string)$y;
    }

    if ($m > 0 && $d === 0) {
        return str_pad((string)$m, 2, '0', STR_PAD_LEFT) . '/' . $y;
    }

    if ($m === 0 && $d > 0) {
        return $fecha;
    }

    return str_pad((string)$d, 2, '0', STR_PAD_LEFT)
        . '/'
        . str_pad((string)$m, 2, '0', STR_PAD_LEFT)
        . '/'
        . $y;
}

function futbol_tipo_url(string $tipo): string {
    return rtrim(BASE_URL, '/') . '/futbol_listado.php?tipo=' . urlencode($tipo);
}

function futbol_num($n): string {
    return number_format((int)$n, 0, ',', '.');
}

$digitalizados = (int)($totales['digitalizados'] ?? 0);
$barcodes = (int)($totales['barcodes'] ?? 0);
$porcentajeDigital = $barcodes > 0 ? round(($digitalizados / $barcodes) * 100, 1) : 0;

include __DIR__ . '/inc/header.php';
?>

<style>  
.futbol-v4 {
  display: grid;
  gap: 18px;
}

.futbol-link {
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.futbol-v4__head {
  display: grid;
  gap: 8px;
  padding: 18px;
  border: 1px solid var(--border);
  background: var(--panel);
}

.futbol-v4__eyebrow {
  font-size: .85rem;
  opacity: .75;
  text-transform: uppercase;
  letter-spacing: .08em;
}

.futbol-v4__title {
  margin: 0;
  font-size: clamp(1.8rem, 3vw, 3rem);
}

.futbol-v4__lead {
  max-width: 900px;
  margin: 0;
  opacity: .85;
  line-height: 1.5;
}

.futbol-v4__cards {
  display: grid;
  grid-template-columns: repeat(5, minmax(150px, 1fr));
  gap: 12px;
}

.futbol-card {
  border: 1px solid var(--border);
  background: var(--panel);
  padding: 14px;
}

.futbol-card__label {
  font-size: .82rem;
  opacity: .75;
  margin-bottom: 8px;
}

.futbol-card__value {
  font-size: 1.7rem;
  font-weight: 700;
}

.futbol-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 18px;
}

.futbol-panel {
  border: 1px solid var(--border);
  background: var(--panel);
  padding: 14px;
}

.futbol-panel h2 {
  margin: 0 0 12px;
  font-size: 1.2rem;
}

.futbol-table-wrap {
  overflow-x: auto;
}

.futbol-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .92rem;
}

.futbol-table th,
.futbol-table td {
  border-bottom: 1px solid var(--border);
  padding: 8px 10px;
  text-align: left;
  vertical-align: top;
}

.futbol-table th {
  font-size: .8rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  opacity: .75;
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
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: .8rem;
  white-space: nowrap;
}

.futbol-muted {
  opacity: .72;
}

.futbol-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 8px;
}

@media (max-width: 1050px) {
  .futbol-v4__cards {
    grid-template-columns: repeat(2, minmax(150px, 1fr));
  }
}

@media (max-width: 620px) {
  .futbol-v4__cards {
    grid-template-columns: 1fr;
  }
}

.futbol-badge--link {
  color: inherit;
  text-decoration: none;
}

.futbol-badge--link:hover {
  text-decoration: underline;
}
</style>

<section class="futbol-v4">
  <header class="futbol-v4__head">
    <div class="futbol-v4__eyebrow">AFDC · diagnóstico por materias 6XX</div>
    <h1 class="futbol-v4__title">Fútbol</h1>
    <p class="futbol-v4__lead">
      Primera capa de recuperación futbolística basada en materias, títulos y digitalización.
      Esta vista no modifica datos: resume el universo ya cargado y permite definir las próximas
      entradas de navegación por competencias, clubes, selecciones, jugadores, árbitros y entrenadores.
    </p>

    <div class="futbol-actions">
      <a class="btn" href="<?= h($base) ?>/futbol_sobres.php">Ver sobres futbolísticos</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=competencia">Competencias</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=club_equipo">Clubes / selecciones</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=jugador">Jugadores</a>
    </div>
  </header>

  <div class="futbol-v4__cards">
    <div class="futbol-card">
      <div class="futbol-card__label">Registros fútbol</div>
      <div class="futbol-card__value"><?= futbol_num($totales['registros'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Sobres / títulos</div>
      <div class="futbol-card__value"><?= futbol_num($totales['sobres'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Barcodes únicos</div>
      <div class="futbol-card__value"><?= futbol_num($totales['barcodes'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Digitalizados</div>
      <div class="futbol-card__value"><?= futbol_num($totales['digitalizados'] ?? 0) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Digitalización</div>
      <div class="futbol-card__value"><?= h((string)$porcentajeDigital) ?>%</div>
    </div>
  </div>

  <div class="futbol-grid">
    <section class="futbol-panel">
      <h2>Resumen por tipo de registro</h2>

      <div class="futbol-table-wrap">
        <table class="futbol-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th class="num">Registros</th>
              <th class="num">Sobres</th>
              <th class="num">Barcodes</th>
              <th class="num">Digitalizados</th>
              <th class="num">Sin digitalizar</th>
              <th>Rango</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stats as $row): ?>
              <tr>
                <td>
                  <a class="futbol-badge futbol-badge--link" href="<?= h(futbol_tipo_url((string)$row['tipo_futbol'])) ?>">
                    <?= h(futbol_tipo_label((string)$row['tipo_futbol'])) ?>
                  </a>
                  <div class="futbol-muted" style="margin-top:6px;">
                    <a class="futbol-link" href="<?= h($base) ?>/futbol_sobres.php?tipo=<?= urlencode((string)$row['tipo_futbol']) ?>">
                      ver sobres
                    </a>
                  </div>
                </td>
                <td class="num"><?= futbol_num($row['registros']) ?></td>
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

            <?php if (!$stats): ?>
              <tr>
                <td colspan="7" class="futbol-muted">No se detectaron registros futbolísticos con las reglas actuales.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="futbol-panel">
      <h2>Registros con mayor volumen</h2>

      <div class="futbol-table-wrap">
        <table class="futbol-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>SYS</th>
              <th>Materia principal</th>
              <th class="num">Sobres</th>
              <th class="num">Digitalizados</th>
              <th class="num">Sin digitalizar</th>
              <th>Rango</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($top as $row): ?>
              <tr>
                <td>
                  <span class="futbol-badge">
                    <?= h(futbol_tipo_label((string)$row['tipo_futbol'])) ?>
                  </span>
                </td>
                <td><?= h((string)$row['sys']) ?></td>
                <td><?= h((string)$row['materia_principal']) ?></td>
                <td class="num"><?= futbol_num($row['sobres']) ?></td>
                <td class="num"><?= futbol_num($row['digitalizados']) ?></td>
                <td class="num"><?= futbol_num($row['sin_digitalizar']) ?></td>
                <td>
                  <?= h(futbol_fecha_humana($row['fecha_min'] ?? '')) ?>
                  <span class="futbol-muted">→</span>
                  <?= h(futbol_fecha_humana($row['fecha_max'] ?? '')) ?>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$top): ?>
              <tr>
                <td colspan="7" class="futbol-muted">No hay registros para listar.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>