<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Fútbol · Registro · AFDC';
$mainClass = 'container-fluid';

$base = rtrim(BASE_URL, '/');

$sys = trim((string)g('sys', ''));
if ($sys === '') {
    http_response_code(400);
    echo 'Falta parámetro sys.';
    exit;
}
$filtro = (string)g('filtro', 'todos');
$orden = (string)g('orden', 'fecha_asc');
$anioFiltro = trim((string)g('anio', ''));

$filtrosValidos = [
    'todos',
    'digitalizados',
    'sin_digitalizar',
    'fecha_completa',
    'sin_fecha',
];

if (!in_array($filtro, $filtrosValidos, true)) {
    $filtro = 'todos';
}

$ordenesValidos = [
    'fecha_asc',
    'fecha_desc',
    'titulo_asc',
    'barcode_asc',
];

if (!in_array($orden, $ordenesValidos, true)) {
    $orden = 'fecha_asc';
}

$sqlMaterias = "
    SELECT campo, materia, linea
    FROM materias
    WHERE sys = ?
    ORDER BY campo ASC, linea ASC, materia ASC
";

$materias = q($sqlMaterias, 's', [$sys]);

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
    WHERE m.sys = ?
    GROUP BY m.sys
";

$clasRows = q($sqlClasificacion, 's', [$sys]);
$clas = $clasRows[0] ?? null;

function futbol_tipo_desde_clasificacion(?array $c): string {
    if (!$c) return 'otro';

    $has600 = (int)($c['has_600'] ?? 0) === 1;
    $has610 = (int)($c['has_610'] ?? 0) === 1;
    $has611 = (int)($c['has_611'] ?? 0) === 1;
    $hasFutbolistas = (int)($c['has_futbolistas'] ?? 0) === 1;
    $hasArbitros = (int)($c['has_arbitros'] ?? 0) === 1;
    $hasEntrenadores = (int)($c['has_entrenadores'] ?? 0) === 1;
    $hasFutbol = (int)($c['has_futbol'] ?? 0) === 1;

    if ($has611 && $hasFutbol) return 'competencia';
    if ($has610 && ($hasFutbolistas || $hasFutbol)) return 'club_equipo';
    if ($has600 && $hasArbitros) return 'arbitro';
    if ($has600 && $hasEntrenadores) return 'entrenador';
    if ($has600 && $hasFutbolistas) return 'jugador';
    if ($hasArbitros) return 'arbitro_general';
    if ($hasEntrenadores) return 'entrenador_general';
    if ($hasFutbolistas || $hasFutbol) return 'general';

    return 'otro';
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
        default => 'Otro',
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

function futbol_num($n): string {
    return number_format((int)$n, 0, ',', '.');
}

function futbol_sobres_busqueda_url(string $texto, string $tipo = ''): string {
    $params = [];

    if ($tipo !== '' && $tipo !== 'otro') {
        $params['tipo'] = $tipo;
    }

    if (trim($texto) !== '') {
        $params['q'] = trim($texto);
    }

    $qs = http_build_query($params);

    return rtrim(BASE_URL, '/') . '/futbol_sobres.php' . ($qs ? ('?' . $qs) : '');
}

function futbol_registro_url(string $sys, array $params = []): string {
    $base = rtrim(BASE_URL, '/') . '/futbol_registro.php';

    $query = [
        'sys' => $sys,
    ];

    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }

    return $base . '?' . http_build_query($query);
}

function futbol_anio_desde_fecha(?string $fecha): string {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return 'Sin fecha';
    }

    if (preg_match('/^\d{8}$/', $fecha)) {
        $y = substr($fecha, 0, 4);
        if ((int)$y >= 1900 && (int)$y <= 2100) {
            return $y;
        }
    }

    return 'Fecha dudosa';
}

function futbol_anio_key(?string $fecha): string {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return 'sin_fecha';
    }

    if (preg_match('/^\d{8}$/', $fecha)) {
        $y = substr($fecha, 0, 4);
        if ((int)$y >= 1900 && (int)$y <= 2100) {
            return $y;
        }
    }

    return 'fecha_dudosa';
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

$tipo = futbol_tipo_desde_clasificacion($clas);
$materiaPrincipal = trim((string)($clas['materia_principal'] ?? ''));
if ($materiaPrincipal === '') {
    foreach ($materias as $m) {
        if (in_array((string)$m['campo'], ['600','610','611','650'], true)) {
            $materiaPrincipal = (string)$m['materia'];
            break;
        }
    }
}
if ($materiaPrincipal === '') {
    $materiaPrincipal = $sys;
}

$sqlSobres = "
    SELECT
        t.sys,
        t.titulo,
        t.nroA,
        t.barcode,
        t.ufi,
        t.fecha,
        CASE WHEN d.inv IS NOT NULL THEN 1 ELSE 0 END AS digitalizado
    FROM titulos t
    LEFT JOIN (
        SELECT DISTINCT inv
        FROM digitales
        WHERE inv IS NOT NULL AND inv <> ''
    ) d
        ON d.inv = t.barcode
    WHERE t.sys = ?
    ORDER BY
        CASE
            WHEN t.fecha REGEXP '^[0-9]{8}$' THEN 0
            ELSE 1
        END ASC,
        t.fecha ASC,
        t.nroA ASC,
        t.titulo ASC
";

$sobres = q($sqlSobres, 's', [$sys]);

$totalSobres = count($sobres);
$barcodes = [];
$digitalizados = 0;
$sinDigitalizar = 0;
$fechaMin = '';
$fechaMax = '';
$conFechaCompleta = 0;
$sinFecha = 0;
$porAnio = [];

foreach ($sobres as $s) {
    $barcode = trim((string)($s['barcode'] ?? ''));
    $digital = (int)($s['digitalizado'] ?? 0) === 1;
    $fecha = trim((string)($s['fecha'] ?? ''));

    if ($barcode !== '') {
        $barcodes[$barcode] = true;

        if ($digital) {
            $digitalizados++;
        } else {
            $sinDigitalizar++;
        }
    }

    if (futbol_fecha_es_completa($fecha)) {
        $conFechaCompleta++;
    }

    if ($fecha === '') {
        $sinFecha++;
    }

    if ($fecha !== '') {
        if ($fechaMin === '' || strcmp($fecha, $fechaMin) < 0) $fechaMin = $fecha;
        if ($fechaMax === '' || strcmp($fecha, $fechaMax) > 0) $fechaMax = $fecha;
    }

    $anio = futbol_anio_desde_fecha($fecha);
    $anioKey = futbol_anio_key($fecha);

    if (!isset($porAnio[$anioKey])) {
        $porAnio[$anioKey] = [
            'label' => $anio,
            'total' => 0,
            'digitalizados' => 0,
            'sin_digitalizar' => 0,
        ];
    }

    $porAnio[$anioKey]['total']++;

    if ($digital) {
        $porAnio[$anioKey]['digitalizados']++;
    } else {
        $porAnio[$anioKey]['sin_digitalizar']++;
    }
}

$totalBarcodes = count($barcodes);

uksort($porAnio, function (string $a, string $b): int {
    if ($a === 'sin_fecha') return 1;
    if ($b === 'sin_fecha') return -1;
    if ($a === 'fecha_dudosa') return 1;
    if ($b === 'fecha_dudosa') return -1;
    return strcmp($a, $b);
});

$sobresFiltrados = array_values(array_filter($sobres, function (array $s) use ($filtro, $anioFiltro): bool {
    $digital = (int)($s['digitalizado'] ?? 0) === 1;
    $fecha = trim((string)($s['fecha'] ?? ''));

    $okFiltro = match ($filtro) {
        'digitalizados' => $digital,
        'sin_digitalizar' => !$digital,
        'fecha_completa' => futbol_fecha_es_completa($fecha),
        'sin_fecha' => $fecha === '',
        default => true,
    };

    if (!$okFiltro) {
        return false;
    }

    if ($anioFiltro !== '') {
        return futbol_anio_key($fecha) === $anioFiltro;
    }

    return true;
}));

usort($sobresFiltrados, function (array $a, array $b) use ($orden): int {
    $fechaA = trim((string)($a['fecha'] ?? ''));
    $fechaB = trim((string)($b['fecha'] ?? ''));
    $tituloA = trim((string)($a['titulo'] ?? ''));
    $tituloB = trim((string)($b['titulo'] ?? ''));
    $barcodeA = trim((string)($a['barcode'] ?? ''));
    $barcodeB = trim((string)($b['barcode'] ?? ''));

    return match ($orden) {
        'fecha_desc' => strcmp($fechaB, $fechaA),
        'titulo_asc' => strcasecmp($tituloA, $tituloB),
        'barcode_asc' => strcasecmp($barcodeA, $barcodeB),
        default => strcmp($fechaA, $fechaB),
    };
});

include __DIR__ . '/inc/header.php';
?>

<style>
.futbol-reg {
  display: grid;
  gap: 16px;
  color: var(--afdc-text);
}

.futbol-reg__head,
.futbol-card,
.futbol-panel {
  border: 1px solid var(--afdc-border);
  background: var(--afdc-card);
  color: var(--afdc-text);
}

.futbol-reg__head {
  padding: 16px;
}

.futbol-reg__kicker {
  color: var(--afdc-muted);
  font-size: .85rem;
  text-transform: uppercase;
  letter-spacing: .08em;
  margin-bottom: 6px;
}

.futbol-reg__title {
  margin: 0;
  font-size: clamp(1.5rem, 2.4vw, 2.3rem);
  color: var(--afdc-text);
}

.futbol-reg__meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
}

.futbol-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 9px;
  border: 1px solid var(--afdc-border);
  border-radius: 999px;
  font-size: .82rem;
  white-space: nowrap;
  background: var(--afdc-btn);
  color: var(--afdc-text);
}

.futbol-reg__cards {
  display: grid;
  grid-template-columns: repeat(5, minmax(140px, 1fr));
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

.futbol-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 12px;
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

.futbol-materias {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.futbol-materia {
  border: 1px solid var(--afdc-border);
  background: var(--afdc-btn);
  color: var(--afdc-text);
  padding: 6px 9px;
  border-radius: 999px;
  font-size: .86rem;
}

.futbol-materia__campo {
  color: var(--afdc-muted);
  font-weight: 700;
  margin-right: 4px;
}

.futbol-link {
  color: var(--afdc-link);
}

.futbol-digital-ok {
  font-weight: 700;
  color: var(--afdc-text);
}

.futbol-digital-no {
  color: var(--afdc-muted);
}

.futbol-filterbar {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  margin-bottom: 12px;
}

.futbol-filterbar .futbol-badge {
  text-decoration: none;
}

.futbol-filterbar .futbol-badge:hover {
  background: var(--afdc-btn-hover);
  text-decoration: none;
}

.futbol-filterbar .is-active {
  font-weight: 800;
  background: var(--afdc-btn-hover);
  border-color: var(--afdc-link);
}

.futbol-filterbar select {
  min-height: 36px;
  padding: 6px 8px;
  border: 1px solid var(--afdc-border);
  background: var(--afdc-btn);
  color: var(--afdc-text);
  border-radius: 10px;
}

.futbol-filterbar select:focus {
  outline: 2px solid var(--afdc-link);
  outline-offset: 1px;
}

.futbol-year-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
  gap: 8px;
}

.futbol-year-card {
  display: block;
  border: 1px solid var(--afdc-border);
  border-radius: 12px;
  padding: 10px;
  background: var(--afdc-btn);
  color: var(--afdc-text);
  text-decoration: none;
}

.futbol-year-card:hover {
  text-decoration: none;
  background: var(--afdc-btn-hover);
}

.futbol-year-card.is-active {
  outline: 2px solid var(--afdc-link);
  outline-offset: 1px;
  background: var(--afdc-btn-hover);
}

.futbol-year-card__year {
  font-size: 1.15rem;
  font-weight: 800;
  margin-bottom: 4px;
  color: var(--afdc-text);
}

.futbol-year-card__meta {
  font-size: .85rem;
  color: var(--afdc-muted);
}

@media (max-width: 1050px) {
  .futbol-reg__cards {
    grid-template-columns: repeat(2, minmax(140px, 1fr));
  }
}

@media (max-width: 620px) {
  .futbol-reg__cards {
    grid-template-columns: 1fr;
  }
}
</style>

<section class="futbol-reg">
  <header class="futbol-reg__head">
    <div class="futbol-reg__kicker">AFDC · Fútbol · Registro</div>
    <h1 class="futbol-reg__title"><?= h($materiaPrincipal) ?></h1>

    <div class="futbol-reg__meta">
      <span class="futbol-badge"><?= h(futbol_tipo_label($tipo)) ?></span>
      <span class="futbol-badge">SYS <?= h($sys) ?></span>
      <span class="futbol-badge">
        <?= h(futbol_fecha_humana($fechaMin)) ?>
        →
        <?= h(futbol_fecha_humana($fechaMax)) ?>
      </span>
    </div>

    <div class="futbol-actions">
      <a class="btn" href="<?= h($base) ?>/futbol.php">Volver a fútbol</a>
      <a class="btn" href="<?= h($base) ?>/futbol_listado.php?tipo=<?= urlencode($tipo) ?>">Volver al listado</a>
      <a class="btn" href="<?= h($base) ?>/futbol_sobres.php?tipo=<?= urlencode($tipo) ?>">Sobres de este tipo</a>
      <a class="btn" href="<?= h(futbol_sobres_busqueda_url($materiaPrincipal, $tipo)) ?>">Buscar en sobres fútbol</a>
    </div>
  </header>

  <div class="futbol-reg__cards">
    <div class="futbol-card">
      <div class="futbol-card__label">Sobres / títulos</div>
      <div class="futbol-card__value"><?= futbol_num($totalSobres) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Barcodes únicos</div>
      <div class="futbol-card__value"><?= futbol_num($totalBarcodes) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Digitalizados</div>
      <div class="futbol-card__value"><?= futbol_num($digitalizados) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Sin digitalizar</div>
      <div class="futbol-card__value"><?= futbol_num($sinDigitalizar) ?></div>
    </div>

    <div class="futbol-card">
      <div class="futbol-card__label">Materias</div>
      <div class="futbol-card__value"><?= futbol_num(count($materias)) ?></div>
    </div>
  </div>

    <section class="futbol-panel">
    <h2>Distribución por año</h2>

    <?php if ($porAnio): ?>
      <div class="futbol-year-grid">
        <?php foreach ($porAnio as $anioKey => $info): ?>
          <a
            class="futbol-year-card <?= $anioFiltro === (string)$anioKey ? 'is-active' : '' ?>"
            href="<?= h(futbol_registro_url($sys, [
                'filtro' => $filtro,
                'orden' => $orden,
                'anio' => (string)$anioKey,
            ])) ?>"
          >
            <div class="futbol-year-card__year"><?= h((string)$info['label']) ?></div>
            <div class="futbol-year-card__meta">
              <?= futbol_num($info['total']) ?> sobres ·
              <?= futbol_num($info['digitalizados']) ?> digitales ·
              <?= futbol_num($info['sin_digitalizar']) ?> sin digitalizar
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="futbol-muted">No hay fechas para agrupar.</p>
    <?php endif; ?>
  </section>

  <section class="futbol-panel">
    <h2>Materias 6XX</h2>

    <?php if ($materias): ?>
      <div class="futbol-materias">
        <?php foreach ($materias as $m): ?>
          <span class="futbol-materia">
            <span class="futbol-materia__campo"><?= h((string)$m['campo']) ?></span>
            <?= h((string)$m['materia']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="futbol-muted">No se encontraron materias para este SYS.</p>
    <?php endif; ?>
  </section>

  <section class="futbol-panel">
    <h2>Sobres vinculados</h2>
      <div class="futbol-actions" style="margin-bottom:12px;">
        <a class="btn" href="<?= h(futbol_sobres_busqueda_url($materiaPrincipal, $tipo)) ?>">
          Ver en tablero de sobres
        </a>
      </div>
        <div class="futbol-filterbar">
      <a class="futbol-badge <?= $filtro === 'todos' ? 'is-active' : '' ?>"
         href="<?= h(futbol_registro_url($sys, ['filtro' => 'todos', 'orden' => $orden, 'anio' => $anioFiltro])) ?>">
        Todos <?= futbol_num($totalSobres) ?>
      </a>

      <a class="futbol-badge <?= $filtro === 'digitalizados' ? 'is-active' : '' ?>"
         href="<?= h(futbol_registro_url($sys, ['filtro' => 'digitalizados', 'orden' => $orden, 'anio' => $anioFiltro])) ?>">
        Digitalizados <?= futbol_num($digitalizados) ?>
      </a>

      <a class="futbol-badge <?= $filtro === 'sin_digitalizar' ? 'is-active' : '' ?>"
         href="<?= h(futbol_registro_url($sys, ['filtro' => 'sin_digitalizar', 'orden' => $orden, 'anio' => $anioFiltro])) ?>">
        Sin digitalizar <?= futbol_num($sinDigitalizar) ?>
      </a>

      <a class="futbol-badge <?= $filtro === 'fecha_completa' ? 'is-active' : '' ?>"
         href="<?= h(futbol_registro_url($sys, ['filtro' => 'fecha_completa', 'orden' => $orden, 'anio' => $anioFiltro])) ?>">
        Fecha completa <?= futbol_num($conFechaCompleta) ?>
      </a>

      <a class="futbol-badge <?= $filtro === 'sin_fecha' ? 'is-active' : '' ?>"
         href="<?= h(futbol_registro_url($sys, ['filtro' => 'sin_fecha', 'orden' => $orden, 'anio' => $anioFiltro])) ?>">
        Sin fecha <?= futbol_num($sinFecha) ?>
      </a>
    </div>
    <?php if ($anioFiltro !== ''): ?>
  <div class="futbol-filterbar">
    <span class="futbol-muted">
      Año activo:
      <strong>
        <?= h((string)($porAnio[$anioFiltro]['label'] ?? $anioFiltro)) ?>
      </strong>
    </span>

    <a class="futbol-badge" href="<?= h(futbol_registro_url($sys, [
        'filtro' => $filtro,
        'orden' => $orden,
    ])) ?>">
      Quitar año
    </a>
  </div>
<?php endif; ?>

    <form class="futbol-filterbar" method="get" action="<?= h($base) ?>/futbol_registro.php">
      <input type="hidden" name="sys" value="<?= h($sys) ?>">
      <input type="hidden" name="filtro" value="<?= h($filtro) ?>">
<?php if ($anioFiltro !== ''): ?>
  <input type="hidden" name="anio" value="<?= h($anioFiltro) ?>">
<?php endif; ?>
      <label class="futbol-muted" for="orden">Orden</label>
      <select id="orden" name="orden">
        <option value="fecha_asc" <?= $orden === 'fecha_asc' ? 'selected' : '' ?>>Fecha ascendente</option>
        <option value="fecha_desc" <?= $orden === 'fecha_desc' ? 'selected' : '' ?>>Fecha descendente</option>
        <option value="titulo_asc" <?= $orden === 'titulo_asc' ? 'selected' : '' ?>>Título</option>
        <option value="barcode_asc" <?= $orden === 'barcode_asc' ? 'selected' : '' ?>>Barcode</option>
      </select>

      <button class="btn" type="submit">Aplicar</button>

      <span class="futbol-muted">
        Mostrando <?= futbol_num(count($sobresFiltrados)) ?> de <?= futbol_num($totalSobres) ?> sobres.
      </span>
    </form>

    <div class="futbol-table-wrap">
      <table class="futbol-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Nro. A</th>
            <th>Barcode</th>
            <th>Título</th>
            <th>UFI</th>
            <th>Digital</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sobresFiltrados as $s): ?>
            <?php
              $barcode = trim((string)($s['barcode'] ?? ''));
              $digital = (int)($s['digitalizado'] ?? 0) === 1;
            ?>
            <tr>
              <td><?= h(futbol_fecha_humana($s['fecha'] ?? '')) ?></td>
              <td><?= h((string)($s['nroA'] ?? '')) ?></td>
              <td><?= h($barcode) ?></td>
              <td><?= h((string)($s['titulo'] ?? '')) ?></td>
              <td><?= h((string)($s['ufi'] ?? '')) ?></td>
              <td>
                <?php if ($digital): ?>
                  <span class="futbol-digital-ok">sí</span>
                <?php else: ?>
                  <span class="futbol-digital-no">no</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($digital && $barcode !== ''): ?>
                  <a class="btn" href="<?= h($base) ?>/ver_digital.php?barcode=<?= urlencode($barcode) ?>&i=0">
                    Ver digital
                  </a>
                <?php elseif ($barcode !== ''): ?>
                  <span class="futbol-muted">Sin imagen</span>
                <?php else: ?>
                  <span class="futbol-muted">Sin barcode</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$sobresFiltrados): ?>
            <tr>
              <td colspan="7" class="futbol-muted">
                No se encontraron sobres para este registro.
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>