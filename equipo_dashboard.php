<?php
/**
 * AFDC v1 - Dashboard por equipo
 *
 * Ajustes:
 * - usa helpers/globales existentes del proyecto
 * - una sola vista integrada, sin tarjetas flotantes
 * - scroll interno del workspace
 * - "Ver sobre" con barcode=
 * - año "Todos" por default
 */

declare(strict_types=1);

// Bootstrap portable
$bootstrap = __DIR__ . '/inc/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = __DIR__ . '/../inc/bootstrap.php';
}
require_once $bootstrap;

$pageTitle = 'Dashboard por equipo';
$mainClass = 'container-fluid';

// --------------------------------------------------
// Helpers locales mínimos
// --------------------------------------------------
function reqv(string $key, $default = null) {
    return $_GET[$key] ?? $default;
}

function fetch_all_assoc_stmt(mysqli_stmt $stmt): array {
    $result = $stmt->get_result();
    if (!$result) return [];
    return $result->fetch_all(MYSQLI_ASSOC);
}

function fetch_col_stmt(mysqli_stmt $stmt, string $col): array {
    $rows = fetch_all_assoc_stmt($stmt);
    $out = [];
    foreach ($rows as $r) {
        if (isset($r[$col]) && $r[$col] !== '' && $r[$col] !== null) {
            $out[] = (string)$r[$col];
        }
    }
    return $out;
}

function formatea_fecha_eq(?string $fecha): string {
    if (!$fecha) return '';
    $fecha = trim($fecha);

    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    if ($dt) return $dt->format('d/m/Y');

    $solo = preg_replace('/\D+/', '', $fecha);
    if ($solo !== '') {
        $dt = DateTime::createFromFormat('Ymd', $solo);
        if ($dt) return $dt->format('d/m/Y');
    }

    return $fecha;
}

function build_url_eq(array $changes = []): string {
    $params = array_merge($_GET, $changes);
    foreach ($params as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        }
    }
    return '?' . http_build_query($params);
}

function nombre_rival_eq(array $p, string $equipo): string {
    return strcasecmp(trim((string)$p['equipo1']), trim($equipo)) === 0
        ? (string)$p['equipo2']
        : (string)$p['equipo1'];
}

function condicion_equipo_eq(array $p, string $equipo): string {
    return strcasecmp(trim((string)$p['equipo1']), trim($equipo)) === 0
        ? 'Local'
        : 'Visitante';
}

function torneo_legible_eq(array $p): string {
    $t = trim((string)($p['tituloReg'] ?? ''));
    if ($t !== '') return $t;

    $titulo = trim((string)($p['titulo'] ?? ''));
    if ($titulo !== '') return $titulo;

    return 'Sin torneo';
}

function tiene_digital_eq(mysqli $db, string $barcode): bool {
    $sql = "SELECT 1 FROM digitales WHERE inv = ? LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $barcode);
    $st->execute();
    $res = $st->get_result();
    return $res && $res->num_rows > 0;
}

function cantidad_recortes_eq(mysqli $db, string $barcode): int {
    $sql = "SELECT COUNT(*) AS c FROM recortes WHERE barcode = ? OR barcode_izq = ? OR barcode_der = ?";
    $st = $db->prepare($sql);
    if (!$st) return 0;
    $st->bind_param('sss', $barcode, $barcode, $barcode);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return (int)($row['c'] ?? 0);
}

// --------------------------------------------------
// Datos
// --------------------------------------------------
$dbx = db();

// Equipos
$sqlEquipos = "
    SELECT equipo
    FROM (
        SELECT TRIM(equipo1) AS equipo FROM partidos
        UNION
        SELECT TRIM(equipo2) AS equipo FROM partidos
    ) q
    WHERE equipo IS NOT NULL AND equipo <> ''
    ORDER BY equipo
";
$stEquipos = $dbx->prepare($sqlEquipos);
$stEquipos->execute();
$equipos = fetch_col_stmt($stEquipos, 'equipo');

$equipo = trim((string)reqv('equipo', $equipos[0] ?? ''));

// Años disponibles
$aniosDb = [];
if ($equipo !== '') {
    $sqlAnios = "
        SELECT DISTINCT
            CASE
                WHEN LENGTH(REPLACE(t.fecha, '-', '')) >= 4
                THEN SUBSTRING(REPLACE(t.fecha, '-', ''), 1, 4)
                ELSE ''
            END AS anio
        FROM titulos t
        INNER JOIN partidos p ON p.barcode = t.barcode
        WHERE (TRIM(p.equipo1) = ? OR TRIM(p.equipo2) = ?)
          AND t.fecha IS NOT NULL
          AND t.fecha <> ''
        ORDER BY anio DESC
    ";
    $stAnios = $dbx->prepare($sqlAnios);
    $stAnios->bind_param('ss', $equipo, $equipo);
    $stAnios->execute();
    $aniosDb = array_values(array_filter(fetch_col_stmt($stAnios, 'anio')));
}

$anio = trim((string)reqv('anio', ''));
$modo = (string)reqv('modo', 'cronologico');
if (!in_array($modo, ['agrupado', 'cronologico'], true)) {
    $modo = 'cronologico';
}
$torneoFiltro = trim((string)reqv('torneo', ''));
$partidoSelId = (string)reqv('partido', '');

// Partidos
$partidos = [];

if ($equipo !== '') {
    $sqlPartidos = "
        SELECT
            t.sys,
            t.nroA,
            t.titulo,
            t.fecha,
            t.barcode,
            p.equipo1,
            p.equipo2,
            p.cancha,
            p.tituloReg
        FROM titulos t
        INNER JOIN partidos p ON p.barcode = t.barcode
        WHERE (TRIM(p.equipo1) = ? OR TRIM(p.equipo2) = ?)
          AND t.fecha IS NOT NULL
          AND t.fecha <> ''
    ";

    if ($anio !== '') {
        $sqlPartidos .= " AND SUBSTRING(REPLACE(t.fecha, '-', ''), 1, 4) = ? ";
    }

    $sqlPartidos .= " ORDER BY REPLACE(t.fecha, '-', '') ASC, t.titulo ASC ";

    $stPartidos = $dbx->prepare($sqlPartidos);
    if ($anio !== '') {
        $stPartidos->bind_param('sss', $equipo, $equipo, $anio);
    } else {
        $stPartidos->bind_param('ss', $equipo, $equipo);
    }
    $stPartidos->execute();
    $partidos = fetch_all_assoc_stmt($stPartidos);
}

foreach ($partidos as &$p) {
    $p['torneo'] = torneo_legible_eq($p);
    $p['fecha_mostrada'] = formatea_fecha_eq((string)$p['fecha']);
    $p['rival'] = nombre_rival_eq($p, $equipo);
    $p['condicion'] = condicion_equipo_eq($p, $equipo);
    $p['id_ui'] = md5((string)$p['barcode'] . '|' . (string)$p['sys']);
}
unset($p);

// Torneos: usar TODOS los torneos del equipo para la barra izquierda,
// incluso si al filtrar año/torneo alguno queda en 0.
$torneosGlobales = [];
foreach ($partidos as $p) {
    $torneosGlobales[$p['torneo']] = true;
}
$torneosGlobales = array_keys($torneosGlobales);
sort($torneosGlobales, SORT_NATURAL | SORT_FLAG_CASE);

// Si estamos en Todos los años conviene incorporar torneos de otros años también
if ($equipo !== '' && $anio === '') {
    $sqlTodosTorneos = "
        SELECT DISTINCT
            COALESCE(NULLIF(TRIM(p.tituloReg), ''), NULLIF(TRIM(t.titulo), ''), 'Sin torneo') AS torneo
        FROM titulos t
        INNER JOIN partidos p ON p.barcode = t.barcode
        WHERE (TRIM(p.equipo1) = ? OR TRIM(p.equipo2) = ?)
          AND t.fecha IS NOT NULL
          AND t.fecha <> ''
        ORDER BY torneo
    ";
    $stTodosTorneos = $dbx->prepare($sqlTodosTorneos);
    $stTodosTorneos->bind_param('ss', $equipo, $equipo);
    $stTodosTorneos->execute();
    $torneosGlobales = fetch_col_stmt($stTodosTorneos, 'torneo');
}

// Conteos por torneo antes del filtro puntual
$conteosTorneo = [];
foreach ($partidos as $p) {
    $conteosTorneo[$p['torneo']] = ($conteosTorneo[$p['torneo']] ?? 0) + 1;
}

// Aplicar filtro por torneo
if ($torneoFiltro !== '') {
    $partidos = array_values(array_filter($partidos, static function(array $p) use ($torneoFiltro): bool {
        return (string)$p['torneo'] === $torneoFiltro;
    }));
}

// Selección actual
if ($partidoSelId === '' && !empty($partidos)) {
    $partidoSelId = (string)$partidos[0]['id_ui'];
}

$partidoSeleccionado = null;
foreach ($partidos as $p) {
    if ((string)$p['id_ui'] === $partidoSelId) {
        $partidoSeleccionado = $p;
        break;
    }
}
if (!$partidoSeleccionado && !empty($partidos)) {
    $partidoSeleccionado = $partidos[0];
    $partidoSelId = (string)$partidoSeleccionado['id_ui'];
}

// Agrupados
$agrupados = [];
foreach ($partidos as $p) {
    $agrupados[$p['torneo']][] = $p;
}

$pj = count($partidos);

// Links auxiliares
$barcodeSel = $partidoSeleccionado['barcode'] ?? '';
$sysSel = $partidoSeleccionado['sys'] ?? '';
$hasDigital = $barcodeSel !== '' ? tiene_digital_eq($dbx, (string)$barcodeSel) : false;
$recortesCount = $barcodeSel !== '' ? cantidad_recortes_eq($dbx, (string)$barcodeSel) : 0;

$linkRegistro = $sysSel !== '' ? ('opac.php?sys=' . urlencode((string)$sysSel)) : '#';
$linkSobre    = $barcodeSel !== '' ? ('ver_digital.php?barcode=' . urlencode((string)$barcodeSel)) : '#';
$linkRecortes = $barcodeSel !== '' ? ('misrecortes.php?barcode=' . urlencode((string)$barcodeSel)) : '#';

include __DIR__ . '/inc/header.php';
?>

<style>
/* contenedor principal: sin tarjetas ni sombras */
.eqdash {
  --eq-border: var(--afdc-border, rgba(0,0,0,.12));
  --eq-text: var(--afdc-text, #2d241d);
  --eq-muted: var(--afdc-muted, #6f6258);
  --eq-bg: var(--afdc-bg, #f5efe2);
  --eq-panel: color-mix(in srgb, var(--eq-bg) 96%, transparent);
  --eq-btn: var(--afdc-btn, rgba(0,0,0,.035));
  --eq-btn-hover: var(--afdc-btn-hover, rgba(0,0,0,.06));
  --eq-link: var(--afdc-link, #8a5a2b);

  color: var(--eq-text);
  font-size: 13px;
  line-height: 1.3;
}

/* intento de neutralizar scroll global extra */
html, body {
  overflow-x: hidden;
}

.eqdash .eqwrap {
  width: min(1540px, calc(100% - 18px));
  margin: 10px auto 14px;
}

.eqdash .eqsurface {
  border: 1px solid var(--eq-border);
  background: var(--eq-panel);
}

.eqdash .eqtop {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 12px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--eq-border);
}

.eqdash .eqtitle {
  margin: 0 0 2px 0;
  font-size: 22px;
  line-height: 1.05;
}

.eqdash .eqsubtitle {
  margin: 0;
  color: var(--eq-muted);
  font-size: 12px;
}

.eqdash .eqfilters {
  display: grid;
  grid-template-columns: minmax(220px, 1fr) 180px 180px;
  gap: 10px;
  align-items: end;
}

.eqdash .eqfield {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.eqdash .eqfield label {
  font-size: 11px;
  color: var(--eq-muted);
  text-transform: uppercase;
  letter-spacing: .04em;
}

.eqdash .eqfield select {
  height: 38px;
  border: 1px solid var(--eq-border);
  background: #fff0;
  color: var(--eq-text);
  padding: 0 10px;
  font: inherit;
  outline: none;
}

.eqdash .eqbody {
  display: grid;
  grid-template-columns: 250px minmax(0, 1fr) 335px;
  min-height: calc(100vh - 210px);
  max-height: calc(100vh - 210px);
  overflow: hidden;
}

.eqdash .eqcol {
  min-width: 0;
  min-height: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.eqdash .eqcol.detail {
  min-height: 0;
}

.eqdash .eqcol.detail .eqscroll {
  padding-bottom: 14px;
}

.eqdash .eqcol + .eqcol {
  border-left: 1px solid var(--eq-border);
}

.eqdash .eqsection-head {
  padding: 10px 12px 8px;
  border-bottom: 1px solid var(--eq-border);
  flex: 0 0 auto;
}

.eqdash .eqsection-head h2 {
  margin: 0;
  font-size: 15px;
  line-height: 1.15;
}

.eqdash .eqmeta {
  margin-top: 3px;
  color: var(--eq-muted);
  font-size: 12px;
}

.eqdash .eqscroll {
  flex: 1 1 auto;
  min-height: 0;
  height: 0;
  overflow-y: auto;
  overflow-x: hidden;
}

.eqdash .eqscroll::-webkit-scrollbar {
  width: 10px;
  height: 10px;
}
.eqdash .eqscroll::-webkit-scrollbar-thumb {
  background: color-mix(in srgb, var(--eq-border) 70%, transparent);
}
.eqdash .eqscroll::-webkit-scrollbar-track {
  background: transparent;
}

.eqdash .eqlist {
  display: flex;
  flex-direction: column;
}

.eqdash .torneo-link,
.eqdash .year-link,
.eqdash .toggle a,
.eqdash .eqbtn {
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 34px;
  padding: 7px 10px;
  border: 1px solid var(--eq-border);
  background: transparent;
  color: var(--eq-text);
  text-decoration: none;
  font-size: 13px;
}

.eqdash .torneo-link:hover,
.eqdash .year-link:hover,
.eqdash .toggle a:hover,
.eqdash .eqbtn:hover {
  background: var(--eq-btn-hover);
  text-decoration: none;
}

.eqdash .torneo-link.is-active,
.eqdash .year-link.is-active,
.eqdash .toggle a.is-active,
.eqdash .match-row.is-selected {
  background: var(--eq-btn);
  border-color: color-mix(in srgb, var(--eq-link) 45%, var(--eq-border));
}

.eqdash .side-block {
  padding: 10px 12px;
  border-bottom: 1px solid var(--eq-border);
}

.eqdash .side-title {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 700;
}

.eqdash .side-links {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.eqdash .year-links {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.eqdash .counter,
.eqdash .muted {
  color: var(--eq-muted);
  font-size: 12px;
}

.eqdash .main-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}

.eqdash .toggle {
  display: inline-flex;
  gap: 6px;
}

.eqdash .match-list {
  display: flex;
  flex-direction: column;
}

.eqdash .torneo-block {
  border-bottom: 1px solid var(--eq-border);
}

.eqdash .torneo-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 10px 12px 8px;
  background: color-mix(in srgb, var(--eq-btn) 35%, transparent);
}

.eqdash .torneo-head h3 {
  margin: 0;
  font-size: 13px;
  line-height: 1.2;
}

.eqdash .match-row {
  display: grid;
  gap: 10px;
  align-items: center;
  padding: 8px 12px;
  border-top: 1px solid var(--eq-border);
  text-decoration: none;
  color: var(--eq-text);
  background: transparent;
}

.eqdash .match-row:hover {
  background: var(--eq-btn-hover);
  text-decoration: none;
}

.eqdash .match-row.crono {
  grid-template-columns: 84px 1.2fr 72px 1.9fr;
}

.eqdash .match-row.group {
  grid-template-columns: 84px 72px 1.9fr;
}

.eqdash .score {
  font-weight: 600;
  font-size: 13px;
  line-height: 1.22;
}

.eqdash .title-line {
  padding: 10px 12px 8px;
  font-size: 14px;
  font-weight: 700;
  line-height: 1.28;
  border-bottom: 1px solid var(--eq-border);
}

.eqdash .detail-grid {
  display: flex;
  flex-direction: column;
  margin: 0;
}

.eqdash .dl-row {
  display: grid;
  grid-template-columns: 82px 1fr;
  gap: 10px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--eq-border);
}

.eqdash .dl-row dt {
  color: var(--eq-muted);
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .04em;
}

.eqdash .dl-row dd {
  margin: 0;
  word-break: break-word;
  font-size: 13px;
}

.eqdash code {
  background: color-mix(in srgb, var(--eq-btn) 65%, transparent);
  border: 1px solid var(--eq-border);
  padding: 2px 6px;
  font-size: 12px;
}

.eqdash .actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  padding: 10px 12px 14px;
}

.eqdash .eqbtn.is-disabled {
  opacity: .45;
  pointer-events: none;
}

.eqdash .empty {
  color: var(--eq-muted);
  font-size: 13px;
  padding: 12px;
}

@media (max-width: 1100px) {
  .eqdash .eqtop {
    grid-template-columns: 1fr;
  }

  .eqdash .eqfilters {
    grid-template-columns: 1fr 160px 160px;
  }

  .eqdash .eqbody {
    grid-template-columns: 230px minmax(0, 1fr);
    min-height: auto;
    max-height: none;
  }

  .eqdash .eqcol.detail {
    grid-column: 1 / -1;
    max-height: 360px;
    border-top: 1px solid var(--eq-border);
  }
}

@media (max-width: 760px) {
  .eqdash .eqfilters {
    grid-template-columns: 1fr;
  }

  .eqdash .eqbody {
    grid-template-columns: 1fr;
    min-height: auto;
    max-height: none;
  }

  .eqdash .eqcol + .eqcol {
    border-left: 0;
    border-top: 1px solid var(--eq-border);
  }

  .eqdash .match-row.crono,
  .eqdash .match-row.group,
  .eqdash .dl-row {
    grid-template-columns: 1fr;
    gap: 4px;
  }
}
</style>

<div class="eqdash">
  <div class="eqwrap">
    <section class="eqsurface">
      <header class="eqtop">
        <div>
          <h1 class="eqtitle">Dashboard por equipo</h1>
          <p class="eqsubtitle">Vista centrada en equipo y año.</p>
        </div>

        <form class="eqfilters" method="get">
          <div class="eqfield">
            <label for="equipo">Equipo</label>
            <select name="equipo" id="equipo" onchange="this.form.submit()">
              <?php foreach ($equipos as $eq): ?>
                <option value="<?= h($eq) ?>" <?= ($eq === $equipo ? 'selected' : '') ?>>
                  <?= h($eq) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="eqfield">
            <label for="anio">Año</label>
            <select name="anio" id="anio" onchange="this.form.submit()">
              <option value="" <?= ($anio === '' ? 'selected' : '') ?>>Todos</option>
              <?php foreach ($aniosDb as $a): ?>
                <option value="<?= h($a) ?>" <?= ($a === $anio ? 'selected' : '') ?>>
                  <?= h($a) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="eqfield">
            <label for="jugador">Jugador</label>
            <select id="jugador" disabled>
              <option>Próximamente</option>
            </select>
          </div>

          <input type="hidden" name="modo" value="<?= h($modo) ?>">
          <?php if ($torneoFiltro !== ''): ?>
            <input type="hidden" name="torneo" value="<?= h($torneoFiltro) ?>">
          <?php endif; ?>
          <?php if ($partidoSelId !== ''): ?>
            <input type="hidden" name="partido" value="<?= h($partidoSelId) ?>">
          <?php endif; ?>
        </form>
      </header>

      <div class="eqbody">
        <!-- IZQUIERDA -->
        <aside class="eqcol">
          <div class="eqsection-head">
            <h2><?= h($equipo ?: '-') ?></h2>
            <div class="eqmeta">
              <?= ($anio !== '' ? h($anio) : 'Todos los años') ?> · <?= (int)$pj ?> partidos
            </div>
          </div>

          <div class="eqscroll">
            <div class="side-block">
              <div class="side-title">Torneos</div>
              <div class="side-links">
                <a class="torneo-link <?= ($torneoFiltro === '' ? 'is-active' : '') ?>"
                   href="<?= h(build_url_eq(['torneo' => null, 'partido' => null])) ?>">
                  <span>Todos los torneos</span>
                  <span class="counter"><?= $pj ?></span>
                </a>

                <?php foreach ($torneosGlobales as $torneo): ?>
                  <?php $countT = (int)($conteosTorneo[$torneo] ?? 0); ?>
                  <a class="torneo-link <?= ($torneoFiltro === $torneo ? 'is-active' : '') ?>"
                     href="<?= h(build_url_eq(['torneo' => $torneo, 'partido' => null])) ?>">
                    <span><?= h($torneo) ?></span>
                    <span class="counter"><?= $countT ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="side-block">
              <div class="side-title">Años</div>
              <div class="year-links">
                <a class="year-link <?= ($anio === '' ? 'is-active' : '') ?>"
                   href="<?= h(build_url_eq(['anio' => '', 'torneo' => null, 'partido' => null])) ?>">
                  Todos
                </a>
                <?php foreach ($aniosDb as $a): ?>
                  <a class="year-link <?= ($a === $anio ? 'is-active' : '') ?>"
                     href="<?= h(build_url_eq(['anio' => $a, 'torneo' => null, 'partido' => null])) ?>">
                    <?= h($a) ?>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </aside>

        <!-- CENTRO -->
        <section class="eqcol">
          <div class="eqsection-head">
            <div class="main-head">
              <div>
                <h2>Partidos</h2>
                <div class="eqmeta">
                  <?= h($equipo ?: '-') ?>
                  <?php if ($anio !== ''): ?>
                    · <?= h($anio) ?>
                  <?php else: ?>
                    · todos los años
                  <?php endif; ?>
                  <?php if ($torneoFiltro !== ''): ?>
                    · <?= h($torneoFiltro) ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="toggle">
                <a href="<?= h(build_url_eq(['modo' => 'agrupado'])) ?>" class="<?= ($modo === 'agrupado' ? 'is-active' : '') ?>">Agrupado</a>
                <a href="<?= h(build_url_eq(['modo' => 'cronologico'])) ?>" class="<?= ($modo === 'cronologico' ? 'is-active' : '') ?>">Cronológico</a>
              </div>
            </div>
          </div>

          <div class="eqscroll">
            <?php if (!$partidos): ?>
              <div class="empty">No hay partidos para ese equipo.</div>
            <?php elseif ($modo === 'cronologico'): ?>
              <div class="match-list">
                <?php foreach ($partidos as $p): ?>
                  <a class="match-row crono <?= ($partidoSelId === $p['id_ui'] ? 'is-selected' : '') ?>"
                     href="<?= h(build_url_eq(['partido' => $p['id_ui']])) ?>">
                    <div class="muted"><?= h($p['fecha_mostrada']) ?></div>
                    <div class="muted"><?= h($p['torneo']) ?></div>
                    <div class="muted"><?= h($p['condicion']) ?></div>
                    <div class="score"><?= h($p['equipo1']) ?> vs <?= h($p['equipo2']) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <?php foreach ($agrupados as $torneo => $items): ?>
                <?php if ($torneoFiltro !== '' && $torneoFiltro !== $torneo) continue; ?>
                <div class="torneo-block">
                  <div class="torneo-head">
                    <h3><?= h($torneo) ?></h3>
                    <span class="counter"><?= count($items) ?></span>
                  </div>

                  <div class="match-list">
                    <?php foreach ($items as $p): ?>
                      <a class="match-row group <?= ($partidoSelId === $p['id_ui'] ? 'is-selected' : '') ?>"
                         href="<?= h(build_url_eq(['partido' => $p['id_ui']])) ?>">
                        <div class="muted"><?= h($p['fecha_mostrada']) ?></div>
                        <div class="muted"><?= h($p['condicion']) ?></div>
                        <div class="score"><?= h($p['equipo1']) ?> vs <?= h($p['equipo2']) ?></div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>

        <!-- DERECHA -->
        <aside class="eqcol detail">
          <div class="eqsection-head">
            <h2>Detalle</h2>
          </div>

          <div class="eqscroll">
            <?php if (!$partidoSeleccionado): ?>
              <div class="empty">Seleccioná un partido.</div>
            <?php else: ?>
              <div class="title-line"><?= h($partidoSeleccionado['titulo'] ?: '[Sin título]') ?></div>

              <dl class="detail-grid">
                <div class="dl-row">
                  <dt>Fecha</dt>
                  <dd><?= h($partidoSeleccionado['fecha_mostrada']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Torneo</dt>
                  <dd><?= h($partidoSeleccionado['torneo']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Partido</dt>
                  <dd><?= h($partidoSeleccionado['equipo1']) ?> vs <?= h($partidoSeleccionado['equipo2']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Rival</dt>
                  <dd><?= h($partidoSeleccionado['rival']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Condición</dt>
                  <dd><?= h($partidoSeleccionado['condicion']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Cancha</dt>
                  <dd><?= h((string)$partidoSeleccionado['cancha']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>NroA</dt>
                  <dd><?= h((string)$partidoSeleccionado['nroA']) ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Barcode</dt>
                  <dd><code><?= h((string)$partidoSeleccionado['barcode']) ?></code></dd>
                </div>
                <div class="dl-row">
                  <dt>SYS</dt>
                  <dd><code><?= h((string)$partidoSeleccionado['sys']) ?></code></dd>
                </div>
                <div class="dl-row">
                  <dt>Digital</dt>
                  <dd><?= $hasDigital ? 'Sí' : 'No' ?></dd>
                </div>
                <div class="dl-row">
                  <dt>Recortes</dt>
                  <dd><?= (int)$recortesCount ?></dd>
                </div>
              </dl>

              <div class="actions">
                <a href="<?= h($linkRegistro) ?>" class="eqbtn <?= ($sysSel === '' ? 'is-disabled' : '') ?>">Ver registro</a>
                <a href="<?= h($linkSobre) ?>" class="eqbtn <?= ($barcodeSel === '' ? 'is-disabled' : '') ?>">Ver sobre</a>
                <a href="<?= h($linkRecortes) ?>" class="eqbtn <?= ($barcodeSel === '' ? 'is-disabled' : '') ?>">Ver recortes</a>
              </div>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    </section>
  </div>
</div>

<?php include __DIR__ . '/inc/footer.php'; ?>