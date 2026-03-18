<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/afdc-table-engine.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_body(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

/**
 * baseQuery llega como array de pares [[k,v],[k,v]...]
 * donde k puede ser "field[]" / "term[]" / etc.
 * Convertimos a estructura tipo $_GET (arrays reales).
 */
function pairs_to_params($pairs): array {
  $out = [];
  if (!is_array($pairs)) return $out;

  foreach ($pairs as $p) {
    if (!is_array($p) || count($p) < 2) continue;
    $k = (string)$p[0];
    $v = (string)$p[1];

    $isArray = false;
    if (str_ends_with($k, '[]')) {
      $isArray = true;
      $k = substr($k, 0, -2);
    }

    if ($isArray) {
      if (!isset($out[$k]) || !is_array($out[$k])) $out[$k] = [];
      $out[$k][] = $v;
    } else {
      // si se repite, lo convertimos a array
      if (isset($out[$k])) {
        if (!is_array($out[$k])) $out[$k] = [$out[$k]];
        $out[$k][] = $v;
      } else {
        $out[$k] = $v;
      }
    }
  }
  return $out;
}

function isTruthy($v): bool {
  return $v === '1' || $v === 1 || $v === true || $v === 'on' || $v === 'true';
}

function buildCondition(string $field, string $term): array {
  $field = trim($field);
  $term  = trim($term);
  $like  = '%' . $term . '%';

  switch ($field) {
    case 'Titulo':
      return ['(t.titulo LIKE ?)', 's', [$like]];

    // compat: Tema viejo => Tema650
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

function render_pager_ajax(int $page, int $totalPages): string {
  if ($totalPages <= 1) return '';

  $page = max(1, min($totalPages, $page));
  $prev = max(1, $page - 1);
  $next = min($totalPages, $page + 1);

  $html = '<div class="pager" aria-label="Paginación" data-afdc-pager>';
  $html .= '<a class="pagebtn '.($page<=1?'disabled':'').'" href="#" data-afdc-page="'.$prev.'">←</a>';

  $start = max(1, $page - 3);
  $end   = min($totalPages, $page + 3);

  if ($start > 1) {
    $html .= '<a class="pagebtn" href="#" data-afdc-page="1">1</a>';
    if ($start > 2) $html .= '<span class="small">…</span>';
  }
  for ($p = $start; $p <= $end; $p++) {
    $cls = $p === $page ? 'pagebtn active' : 'pagebtn';
    $html .= '<a class="'.$cls.'" href="#" data-afdc-page="'.$p.'">'.$p.'</a>';
  }
  if ($end < $totalPages) {
    if ($end < $totalPages - 1) $html .= '<span class="small">…</span>';
    $html .= '<a class="pagebtn" href="#" data-afdc-page="'.$totalPages.'">'.$totalPages.'</a>';
  }

  $html .= '<a class="pagebtn '.($page>=$totalPages?'disabled':'').'" href="#" data-afdc-page="'.$next.'">→</a>';
  $html .= '</div>';
  return $html;
}

function run_basico(array $params): array {
  $q = trim((string)($params['q'] ?? ''));
  $filter = trim((string)($params['filter'] ?? ''));
  $page = max(1, (int)($params['page'] ?? 1));
  $perPage = (int)($params['per_page'] ?? 50);
  $allowedPerPage = [25,50,100,200];
  if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
  $offset = ($page - 1) * $perPage;

  if ($q === '') {
    return ['rows'=>[], 'totalRows'=>0, 'totalPages'=>1, 'page'=>1, 'perPage'=>$perPage];
  }

  $mysqli = db();

  $where = [];
  $types = '';
  $bind = [];

  $like = '%' . $q . '%';
  $where[] = '(t.titulo LIKE ? OR EXISTS (
                SELECT 1 FROM materias m2
                WHERE m2.sys = t.sys AND m2.materia LIKE ?
              ))';
  $types .= 'ss';
  $bind[] = $like;
  $bind[] = $like;

  if ($filter !== '') {
    $f = '%' . $filter . '%';
    $where[] = '(
      t.titulo LIKE ?
      OR t.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR EXISTS (SELECT 1 FROM materias mf WHERE mf.sys = t.sys AND mf.materia LIKE ?)
    )';
    $types .= 'sssss';
    array_push($bind, $f, $f, $f, $f, $f);
  }

  $whereSql = 'WHERE ' . implode(' AND ', $where);

  // count
  $totalRows = 0;
  $sqlCount = "SELECT COUNT(*) AS c FROM titulos t {$whereSql}";
  if ($stmt = $mysqli->prepare($sqlCount)) {
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $totalRows = (int)($row['c'] ?? 0);
    $stmt->close();
  }

  $totalPages = max(1, (int)ceil($totalRows / $perPage));

  $rows = [];
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
        WHERE d.inv = t.barcode AND d.carpeta='Bajas'
      ) AS digital_count,
      EXISTS(
        SELECT 1 FROM digitales d
        WHERE d.inv = t.barcode AND d.carpeta='Altas' LIMIT 1
      ) AS has_alta
    FROM titulos t
    {$whereSql}
    ORDER BY t.fecha DESC, t.sys DESC
    LIMIT ? OFFSET ?
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $bt = $types . 'ii';
    $bp = $bind;
    $bp[] = $perPage;
    $bp[] = $offset;
    $stmt->bind_param($bt, ...$bp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
  }
  return compact('rows','totalRows','totalPages','page','perPage','filter','q');
}

function run_avanzado(array $params): array {
  $filter = trim((string)($params['filter'] ?? ''));
  $page = max(1, (int)($params['page'] ?? 1));
  $perPage = (int)($params['per_page'] ?? 50);
  $allowedPerPage = [25,50,100,200];
  if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
  $offset = ($page - 1) * $perPage;

  $fields = $params['field'] ?? [];
  $terms  = $params['term'] ?? [];
  $ops    = $params['op'] ?? [];
  $nots   = $params['not'] ?? [];

  if (!is_array($fields)) $fields = [];
  if (!is_array($terms)) $terms = [];
  if (!is_array($ops)) $ops = [];
  if (!is_array($nots)) $nots = [];

  $maxRows = max(count($fields), count($terms));
  $fields = array_pad($fields, $maxRows, '');
  $terms  = array_pad($terms,  $maxRows, '');
  $nots   = array_pad($nots,   $maxRows, '');

  $conds = [];
  for ($i=0; $i<$maxRows; $i++) {
    $f = (string)$fields[$i];
    $t = trim((string)$terms[$i]);
    if ($t === '') continue;

    [$frag, $tps, $prs] = buildCondition($f, $t);
    $isNot = isset($nots[$i]) && isTruthy($nots[$i]);
    $sql = $isNot ? '(NOT '.$frag.')' : $frag;

    $opPrev = '';
    if (count($conds) > 0) {
      $opPrev = strtoupper((string)($ops[$i-1] ?? 'AND'));
      if ($opPrev !== 'OR') $opPrev = 'AND';
    }

    $conds[] = ['sql'=>$sql,'types'=>$tps,'params'=>$prs,'opPrev'=>$opPrev];
  }

  $whereSql = '';
  $types = '';
  $bind = [];

  if (count($conds) > 0) {
    $expr = $conds[0]['sql'];
    $types .= $conds[0]['types'];
    $bind = array_merge($bind, $conds[0]['params']);

    for ($i=1; $i<count($conds); $i++) {
      $op = $conds[$i]['opPrev'];
      $expr = '(' . $expr . ' ' . $op . ' ' . $conds[$i]['sql'] . ')';
      $types .= $conds[$i]['types'];
      $bind = array_merge($bind, $conds[$i]['params']);
    }

    $whereSql = 'WHERE ' . $expr;
  } else {
    return ['rows'=>[], 'totalRows'=>0, 'totalPages'=>1, 'page'=>1, 'perPage'=>$perPage];
  }

  if ($filter !== '') {
    $f = '%' . $filter . '%';
    $whereSql .= ' AND (
      t.titulo LIKE ?
      OR t.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR EXISTS (SELECT 1 FROM materias mf WHERE mf.sys = t.sys AND mf.materia LIKE ?)
    )';
    $types .= 'sssss';
    array_push($bind, $f,$f,$f,$f,$f);
  }

  $mysqli = db();

  $totalRows = 0;
  $sqlCount = "SELECT COUNT(*) AS c FROM titulos t {$whereSql}";
  if ($stmt = $mysqli->prepare($sqlCount)) {
    if ($types !== '') $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $totalRows = (int)($row['c'] ?? 0);
    $stmt->close();
  }

  $totalPages = max(1, (int)ceil($totalRows / $perPage));

  $rows = [];
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
        WHERE d.inv = t.barcode AND d.carpeta='Bajas'
      ) AS digital_count,
      EXISTS(
        SELECT 1 FROM digitales d
        WHERE d.inv = t.barcode AND d.carpeta='Altas' LIMIT 1
      ) AS has_alta
    FROM titulos t
    {$whereSql}
    ORDER BY t.fecha DESC, t.sys DESC
    LIMIT ? OFFSET ?
  ";
  if ($stmt = $mysqli->prepare($sql)) {
    $bt = $types . 'ii';
    $bp = $bind;
    $bp[] = $perPage;
    $bp[] = $offset;
    $stmt->bind_param($bt, ...$bp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
  }

  return compact('rows','totalRows','totalPages','page','perPage','filter');
}

// -------------------- main --------------------
$body = read_json_body();
$context = (string)($body['context'] ?? '');
$basePairs = $body['baseQuery'] ?? [];
$filter = (string)($body['filter'] ?? '');
$page = (int)($body['page'] ?? 1);

$params = pairs_to_params($basePairs);

// Inferir contexto si el JS no lo manda.
// - Avanzado: viene con field/term (arrays)
// - Básico: viene con q
if ($context === '') {
  if (isset($params['field']) || isset($params['term'])) {
    $context = 'avanzado';
  } elseif (isset($params['q'])) {
    $context = 'basico';
  } else {
    $context = 'basico';
  }
}

if ($context !== 'basico' && $context !== 'avanzado') {
  json_out(['ok'=>false,'error'=>'context inválido'], 400);
}

// NO tocamos URL, así que todo viene del baseQuery + overrides del refinado
$params['filter'] = $filter;
$params['page'] = $page;

// Ejecutar consulta
$data = ($context === 'basico') ? run_basico($params) : run_avanzado($params);

$rows = $data['rows'] ?? [];
$totalRows = (int)($data['totalRows'] ?? 0);
$totalPages = (int)($data['totalPages'] ?? 1);
$page = (int)($data['page'] ?? 1);
$perPage = (int)($data['perPage'] ?? 50);

// UI flag (basico compact)
$ui = trim((string)($params['ui'] ?? ''));
$isCompact = ($ui === 'compact');

// Render HTML del bloque de resultados (meta + tabla + pager)
ob_start();

echo '<div class="meta small" style="margin:10px 0;" data-afdc-meta>';
echo 'Resultados: <strong>'.(int)$totalRows.'</strong> — Página '.(int)$page.' / '.(int)$totalPages;
echo '</div>';

// 1) Render tabla UNA sola vez a string
ob_start();
afdc_table_render('buscador', $rows, [
  'filter_param' => 'filter',
  'filter_value' => $filter,
  'filter_label' => 'Refinar resultados',
  'filter_placeholder' => 'Refinar…',
  'remote_mode' => 'ajax',
  'context' => $context,
  'total_rows' => $totalRows,

  // ✅ Toolbar interna SOLO en basico cuando NO es compact
  // (avanzado siempre usa #advTools externo)
  'show_toolbar' => ($context === 'basico' && !$isCompact),
]);
$tableHtml = ob_get_clean();

// 2) Inyectar tools externos según contexto
if ($context === 'basico' && $isCompact) {
  $tableHtml = preg_replace(
    '/\bdata-afdc-table\b/',
    'data-afdc-table data-afdc-tools="#basicTools"',
    $tableHtml,
    1
  );
}

if ($context === 'avanzado') {
  $tableHtml = preg_replace(
    '/\bdata-afdc-table\b/',
    'data-afdc-table data-afdc-tools="#advTools"',
    $tableHtml,
    1
  );
}

// 3) Output final
echo $tableHtml;
echo render_pager_ajax($page, $totalPages);

$html = ob_get_clean();

json_out([
  'ok' => true,
  'html' => $html,
  'totalRows' => $totalRows,
  'totalPages' => $totalPages,
  'page' => $page,
]);
