<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();
require_csrf();

$in = read_json();

$setId = (int)($in['set_id'] ?? 0);
if ($setId <= 0) {
  $def = v2_sets_ensure_arevisar((int)$u['id']);
  $setId = (int)$def['id'];
} else {
  $own = v2_sets_get_owned($setId, (int)$u['id']);
  if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);
}

$fields = $in['field'] ?? [];
$terms  = $in['term'] ?? [];
$ops    = $in['op'] ?? [];
$nots   = $in['not'] ?? [];
$filter = trim((string)($in['filter'] ?? ''));

if (!is_array($fields)) $fields = [];
if (!is_array($terms))  $terms  = [];
if (!is_array($ops))    $ops    = [];
if (!is_array($nots))   $nots   = [];

$maxRows = max(count($fields), count($terms));
$fields = array_pad($fields, $maxRows, '');
$terms  = array_pad($terms,  $maxRows, '');
$nots   = array_pad($nots,   $maxRows, '');

// --- helpers (copiados del buscador_avanzado.php, pero SOLO lo necesario) ---
function isTruthyLocal($v): bool {
  return $v === '1' || $v === 1 || $v === true || $v === 'on' || $v === 'true';
}
function buildConditionLocal(string $field, string $term): array {
  $field = trim($field);
  $term  = trim($term);
  $like  = '%' . $term . '%';

  switch ($field) {
    case 'Titulo':
      return ['(t.titulo LIKE ?)', 's', [$like]];
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

// --- build WHERE (igual que avanzado) ---
$conds = [];
for ($i = 0; $i < $maxRows; $i++) {
  $f = (string)$fields[$i];
  $t = trim((string)$terms[$i]);
  if ($t === '') continue;

  [$frag, $tps, $prs] = buildConditionLocal($f, $t);
  $isNot = isset($nots[$i]) && isTruthyLocal($nots[$i]);
  $sql = $isNot ? '(NOT ' . $frag . ')' : $frag;

  $opPrev = '';
  if (count($conds) > 0) {
    $opPrev = strtoupper((string)($ops[$i-1] ?? 'AND'));
    if ($opPrev !== 'OR') $opPrev = 'AND';
  }

  $conds[] = ['sql' => $sql, 'types' => $tps, 'params' => $prs, 'opPrev' => $opPrev];
}

$whereSql = '';
$types = '';
$params = [];

if (count($conds) > 0) {
  $expr = $conds[0]['sql'];
  $types .= $conds[0]['types'];
  $params = array_merge($params, $conds[0]['params']);

  for ($i = 1; $i < count($conds); $i++) {
    $op = $conds[$i]['opPrev'];
    $expr = '(' . $expr . ' ' . $op . ' ' . $conds[$i]['sql'] . ')';
    $types .= $conds[$i]['types'];
    $params = array_merge($params, $conds[$i]['params']);
  }
  $whereSql = 'WHERE ' . $expr;
}

if ($whereSql === '') {
  jexit(['ok'=>false,'error'=>'empty_query'], 400);
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
  array_push($params, $f, $f, $f, $f, $f);
}

// --- INSERT IGNORE masivo (position=0, alcanza) ---
$sqlInsert = "
  INSERT IGNORE INTO set_items_v2 (set_id, item_type, item_key, position, added_at)
  SELECT ?, 'sobre', z.barcode, 0, NOW()
  FROM (
    SELECT DISTINCT t.barcode AS barcode
    FROM titulos t
    {$whereSql}
  ) z
  WHERE z.barcode IS NOT NULL AND z.barcode <> ''
";

$bindTypes = 'i' . $types;
$bindParams = array_merge([$setId], $params);

q($sqlInsert, $bindTypes, $bindParams);

$cnt = q(
  "SELECT COUNT(*) AS c FROM set_items_v2 WHERE set_id=? AND item_type='sobre'",
  "i",
  [$setId]
);

jexit(['ok'=>true, 'set_id'=>$setId, 'total'=>(int)($cnt[0]['c'] ?? 0)]);
