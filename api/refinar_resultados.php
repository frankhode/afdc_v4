<?php
declare(strict_types=1);

// api/refinar_resultados.php
// Endpoint AJAX para "Refinar" en resultados.php (fútbol/índices)

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

$data = read_json_body();
$basePairs = $data['baseQuery'] ?? [];
$params = pairs_to_params($basePairs);

$filter = trim((string)($data['filter'] ?? ''));
$page = max(1, (int)($data['page'] ?? 1));

$campeonato = trim((string)($params['campeonato'] ?? ''));
$equipo     = trim((string)($params['equipo'] ?? ''));
$campo      = trim((string)($params['campo'] ?? ''));
$termino    = trim((string)($params['termino'] ?? ''));

$modo = ($campo !== '' && $termino !== '') ? 'indice' : (($campeonato !== '') ? 'campeonato' : (($equipo !== '') ? 'equipo' : ''));

$perPage = (int)($params['per_page'] ?? 50);
$allowedPerPage = [25,50,100,200];
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 50;
$offset = ($page - 1) * $perPage;

if ($modo === '') {
  json_out(['ok'=>false, 'error'=>'Faltan parámetros de búsqueda.']);
}

$mysqli = db();

$whereExtra = '';
$typesExtra = '';
$bindExtra = [];

if ($filter !== '') {
  $f = '%' . $filter . '%';
  $whereExtra = " AND (
      t.titulo LIKE ?
      OR t.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR EXISTS (SELECT 1 FROM materias mf WHERE mf.sys = t.sys AND mf.materia LIKE ?)
    )";
  $typesExtra = 'sssss';
  $bindExtra = [$f,$f,$f,$f,$f];
}

$totalRows = 0;
$totalPages = 1;
$rows = [];
$err = null;

if ($modo === 'indice') {

  // count
  $sqlCount = "
    SELECT COUNT(DISTINCT t.barcode) AS c
    FROM titulos t
    INNER JOIN materias mx ON mx.sys = t.sys
    WHERE mx.campo = ? AND mx.materia = ?
    {$whereExtra}
  ";
  if ($stmt = $mysqli->prepare($sqlCount)) {
    $types = 'ss' . $typesExtra;
    $bind = array_merge([$campo, $termino], $bindExtra);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res->fetch_assoc();
    $totalRows = (int)($r['c'] ?? 0);
    $stmt->close();
  } else {
    $err = $mysqli->error;
  }

  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

  $sql = "
    SELECT
      t.sys,
      t.titulo,
      t.barcode,
      t.fecha,
      COALESCE(GROUP_CONCAT(DISTINCT mm.materia ORDER BY mm.materia SEPARATOR ' | '), '') AS materias,
      SUM(CASE WHEN d.carpeta='Bajas' THEN 1 ELSE 0 END) AS digital_count,
      MAX(CASE WHEN d.carpeta='Altas' THEN 1 ELSE 0 END) AS has_alta
    FROM titulos t
    INNER JOIN materias mx ON mx.sys = t.sys AND mx.campo = ? AND mx.materia = ?
    LEFT JOIN materias mm ON mm.sys = t.sys
    LEFT JOIN digitales d ON d.inv = t.barcode AND d.carpeta IN ('Bajas','Altas')
    WHERE 1=1
    {$whereExtra}
    GROUP BY t.sys, t.titulo, t.barcode, t.fecha
    ORDER BY t.titulo ASC, t.sys DESC
    LIMIT ? OFFSET ?
  ";

  if ($stmt = $mysqli->prepare($sql)) {
    $types = 'ss' . $typesExtra . 'ii';
    $bind = array_merge([$campo, $termino], $bindExtra, [$perPage, $offset]);
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
  } else {
    $err = $mysqli->error;
  }

} else {

  $isCamp = ($modo === 'campeonato');
  $filterSql = $isCamp ? "p.tituloReg = ?" : "(p.equipo1 = ? OR p.equipo2 = ?)";

  // count
  $sqlCount = "
    SELECT COUNT(DISTINCT p.barcode) AS c
    FROM partidos p
    LEFT JOIN titulos t ON t.barcode = p.barcode
    WHERE {$filterSql}
    {$whereExtra}
  ";
  if ($stmt = $mysqli->prepare($sqlCount)) {
    if ($isCamp) {
      $types = 's' . $typesExtra;
      $bind = array_merge([$campeonato], $bindExtra);
    } else {
      $types = 'ss' . $typesExtra;
      $bind = array_merge([$equipo, $equipo], $bindExtra);
    }
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res->fetch_assoc();
    $totalRows = (int)($r['c'] ?? 0);
    $stmt->close();
  } else {
    $err = $mysqli->error;
  }

  $totalPages = max(1, (int)ceil($totalRows / $perPage));
  if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

  $sql = "
    SELECT
      t.sys,
      t.titulo,
      p.barcode,
      t.fecha,
      COALESCE(GROUP_CONCAT(DISTINCT mm.materia ORDER BY mm.materia SEPARATOR ' | '), '') AS materias,
      SUM(CASE WHEN d.carpeta='Bajas' THEN 1 ELSE 0 END) AS digital_count,
      MAX(CASE WHEN d.carpeta='Altas' THEN 1 ELSE 0 END) AS has_alta
    FROM partidos p
    LEFT JOIN titulos t ON t.barcode = p.barcode
    LEFT JOIN materias mm ON mm.sys = t.sys
    LEFT JOIN digitales d ON d.inv = p.barcode AND d.carpeta IN ('Bajas','Altas')
    WHERE {$filterSql}
    {$whereExtra}
    GROUP BY p.barcode, t.sys, t.titulo, t.fecha
    ORDER BY t.fecha DESC, t.sys DESC
    LIMIT ? OFFSET ?
  ";

  if ($stmt = $mysqli->prepare($sql)) {
    if ($isCamp) {
      $types = 's' . $typesExtra . 'ii';
      $bind = array_merge([$campeonato], $bindExtra, [$perPage, $offset]);
    } else {
      $types = 'ss' . $typesExtra . 'ii';
      $bind = array_merge([$equipo, $equipo], $bindExtra, [$perPage, $offset]);
    }
    $stmt->bind_param($types, ...$bind);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
  } else {
    $err = $mysqli->error;
  }
}


if ($err) {
  json_out(['ok'=>false, 'error'=>$err], 500);
}

// Render HTML del bloque de resultados (tabla + pager)
ob_start();

ob_start();
afdc_table_render('buscador', $rows, [
  'filter_param' => 'filter',
  'filter_value' => '',
  'filter_label' => 'Refinar resultados',
  'filter_placeholder' => 'Refinar…',
  'remote_mode' => 'ajax',
  'context' => 'resultados',
  'total_rows' => $totalRows,
  'show_toolbar' => false,
]);
$tableHtml = ob_get_clean();

$tableHtml = preg_replace(
  '/\bdata-afdc-table\b/',
  'data-afdc-table data-afdc-tools="#resTools" data-afdc-endpoint="api/refinar_resultados.php"',
  $tableHtml,
  1
);

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
