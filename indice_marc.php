<?php
// afdc_v1/indice_marc.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';

/**
 * Índice MARC (cursor)
 * - Campos fijos: 600, 610, 611, 630, 650, 651
 * - Default: 650
 * - Default per_page: 25
 * - Lógica de índice real: lista desde "start" aunque no exista término exacto (>= start)
 * - Navegación: -N / +N (por bloques)
 * - Sin COUNT para performance (trae N+1 para saber si hay siguiente)
 */

$labels = [
  '600' => '600 — Materia (persona)',
  '610' => '610 — Materia (entidad)',
  '611' => '611 — Materia (evento)',
  '630' => '630 — Materia (título uniforme)',
  '650' => '650 — Materia (tema)',
  '651' => '651 — Materia (geográfico)',
];

$campo = trim((string)($_GET['campo'] ?? '650'));
if (!isset($labels[$campo])) $campo = '650';

$start = trim((string)($_GET['start'] ?? ''));

// El índice no debería cargar “todo” al abrir.
// Listamos si: el usuario tocó "Listar" (go=1) o si hay start (por navegación).
$go = (int)($_GET['go'] ?? 0);
$hasQuery = ($go === 1) || ($start !== '');

// per_page: default 25 (vuela)
$perPage = (int)($_GET['per_page'] ?? 25);
$allowedPerPage = [25, 50, 100, 200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;

// after=1 se usa para "Siguiente": arranca estrictamente después del último término (>)
$after = (int)($_GET['after'] ?? 0); // 0 => >= start ; 1 => > start
$cmpOp = ($after === 1) ? '>' : '>=';

// Defaults de salida
$rows = [];
$err = null;

// Cursores
$firstTerm = null;
$lastTerm  = null;
$prevStart = null;
$nextStart = null;

// View
$pageTitle = 'Índice MARC';
include __DIR__ . '/inc/header.php';
include __DIR__ . '/inc/afdc-table.php';

// -------------------- DB (solo si hay query) --------------------
if ($hasQuery) {

    $mysqli = db();

    // Traemos N+1 para saber si existe "siguiente"
    $limitPlus = $perPage + 1;

    if ($start === '') {
        // Sin start: desde el inicio del índice
        $sql = "
            SELECT
                m.materia AS termino,
                COUNT(DISTINCT m.sys) AS sobres,
                MAX(CASE WHEN d.inv IS NULL THEN 0 ELSE 1 END) AS has_digital
            FROM materias m
            LEFT JOIN titulos t ON t.sys = m.sys
            LEFT JOIN digitales d
              ON d.inv = t.barcode
             AND d.carpeta IN ('Bajas','Altas')
            WHERE m.campo = ?
              AND m.materia <> ''
            GROUP BY m.materia
            ORDER BY m.materia ASC
            LIMIT ?
        ";

        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('si', $campo, $limitPlus);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            $stmt->close();
        } else {
            $err = $mysqli->error;
        }

    } else {
        // Con start: índice real desde ese punto (>= start) o ( > start ) para "siguiente"
        $sql = "
            SELECT
                m.materia AS termino,
                COUNT(DISTINCT m.sys) AS sobres,
                MAX(CASE WHEN d.inv IS NULL THEN 0 ELSE 1 END) AS has_digital
            FROM materias m
            LEFT JOIN titulos t ON t.sys = m.sys
            LEFT JOIN digitales d
              ON d.inv = t.barcode
             AND d.carpeta IN ('Bajas','Altas')
            WHERE m.campo = ?
              AND m.materia <> ''
              AND m.materia {$cmpOp} ?
            GROUP BY m.materia
            ORDER BY m.materia ASC
            LIMIT ?
        ";

        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('ssi', $campo, $start, $limitPlus);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            $stmt->close();
        } else {
            $err = $mysqli->error;
        }
    }

    // Detectar si hay "siguiente" (N+1)
    $hasNext = (count($rows) > $perPage);
    if ($hasNext) {
        array_pop($rows); // removemos el N+1 (solo era para detectar)
    }

    if ($rows) {
        $firstTerm = (string)$rows[0]['termino'];
        $lastTerm  = (string)$rows[count($rows) - 1]['termino'];
    }

    // Calcular prevStart: buscamos los N términos anteriores al firstTerm
    if ($firstTerm !== null) {
        $sqlPrev = "
            SELECT DISTINCT m.materia AS termino
            FROM materias m
            WHERE m.campo = ?
              AND m.materia <> ''
              AND m.materia < ?
            ORDER BY m.materia DESC
            LIMIT ?
        ";

        if ($stmt = $mysqli->prepare($sqlPrev)) {
            $stmt->bind_param('ssi', $campo, $firstTerm, $perPage);
            $stmt->execute();
            $res = $stmt->get_result();
            $prevBlock = [];
            while ($r = $res->fetch_assoc()) $prevBlock[] = (string)$r['termino'];
            $stmt->close();

            // Viene en DESC; para setear el start del bloque anterior,
            // queremos el más “chico” dentro de ese bloque (último del array DESC)
            if ($prevBlock) {
                $prevStart = $prevBlock[count($prevBlock) - 1];
            }
        }
    }

    // nextStart: si hay next, arrancamos desde lastTerm con after=1 (>)
    if ($hasNext && $lastTerm !== null) {
        $nextStart = $lastTerm;
    }
}
?>

<section class="card">

  <h1 style="margin:0 0 8px; font-size:22px;">Índice MARC</h1>
  <p class="small" style="opacity:.7; margin:0 0 14px;">
    Elegí el campo y el punto de inicio. Navegá de a <?= (int)$perPage ?> términos.
  </p>

  <form method="get" action="indice_marc.php">
    <div class="row" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <label>Campo</label>
        <select name="campo">
          <?php foreach ($labels as $c => $label): ?>
            <option value="<?= h($c) ?>" <?= $campo === $c ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label>Inicio</label>
        <input type="text" name="start" value="<?= h($start) ?>"
               placeholder="Ej: bochi, boca, argentina..."
               style="min-width:260px;" />
      </div>

      <div>
        <label>Salto</label>
        <select name="per_page">
          <?php foreach ($allowedPerPage as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button class="btn" type="submit" name="go" value="1">Listar</button>
      </div>

      <div>
        <a class="btn secondary" href="indice_marc.php?campo=<?= urlencode($campo) ?>">Limpiar</a>
      </div>
    </div>
  </form>

  <?php if (!$hasQuery): ?>
    <p class="small" style="margin-top:14px; opacity:.7;">
      (Por defecto no listamos todo.) Presioná <strong>Listar</strong> para ver el índice.
    </p>
  <?php else: ?>

    <?php if ($err): ?>
      <div class="error"><strong>Error:</strong> <?= h($err) ?></div>
    <?php endif; ?>

    <div class="pager" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:12px 0;">
      <?php if ($prevStart !== null): ?>
        <a class="btn secondary"
           href="indice_marc.php?campo=<?= urlencode($campo) ?>&start=<?= urlencode($prevStart) ?>&per_page=<?= (int)$perPage ?>&go=1&after=0">
          ← −<?= (int)$perPage ?>
        </a>
      <?php else: ?>
        <span class="btn secondary" style="opacity:.45; pointer-events:none;">← −<?= (int)$perPage ?></span>
      <?php endif; ?>

      <span class="small" style="opacity:.75;">
        Campo: <strong><?= h($campo) ?></strong> · Inicio:
        <strong><?= h($start === '' ? '(inicio del índice)' : $start) ?></strong> · Mostrando:
        <strong><?= count($rows) ?></strong>
      </span>

      <?php if ($nextStart !== null): ?>
        <a class="btn secondary"
           href="indice_marc.php?campo=<?= urlencode($campo) ?>&start=<?= urlencode($nextStart) ?>&per_page=<?= (int)$perPage ?>&go=1&after=1">
          +<?= (int)$perPage ?> →
        </a>
      <?php else: ?>
        <span class="btn secondary" style="opacity:.45; pointer-events:none;">+<?= (int)$perPage ?> →</span>
      <?php endif; ?>
    </div>

    <div class="afdc-table-wrap" data-afdc-table>
      <!---<div class="afdc-table-tools">
        <div class="left">
          <label>Filtro de tabla</label>
          <input data-afdc-filter type="text" placeholder="Filtrar términos visibles" />
        </div>
        <div class="right">
          <button class="btn" type="button" data-afdc-csv>CSV</button>
          <button class="btn secondary" type="button" data-afdc-clear>Limpiar</button>
        </div>
      </div>-->

      <table class="afdc-table" data-afdc-target>
        <thead>
          <tr>
            <th data-sort="text">Término</th>
            <th data-sort="num">Sobres</th>
            <th>Tiene digital</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="small">Sin términos para ese punto de inicio.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $term = (string)($r['termino'] ?? '');
            $sobres = (int)($r['sobres'] ?? 0);
            $hasDigital = (int)($r['has_digital'] ?? 0);
          ?>
          <tr>
            <td><?= h($term) ?></td>
            <td><?= $sobres ?></td>
            <td><?= $hasDigital ? '✓' : '—' ?></td>
            <td>
              <a href="resultados.php?campo=<?= urlencode($campo) ?>&termino=<?= urlencode($term) ?>">
                Ver sobres
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
