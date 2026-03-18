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

$qtxt = trim((string)($in['q'] ?? ''));
$filter = trim((string)($in['filter'] ?? ''));

if ($qtxt === '') jexit(['ok'=>false,'error'=>'empty_query'], 400);

$where = [];
$params = [];
$types = '';

$like = '%' . $qtxt . '%';
$where[] = '(t.titulo LIKE ? OR EXISTS (
  SELECT 1 FROM materias m2
  WHERE m2.sys = t.sys AND m2.materia LIKE ?
))';
$params[] = $like;
$params[] = $like;
$types .= 'ss';

if ($filter !== '') {
  $f = '%' . $filter . '%';
  $where[] = '(
    t.titulo LIKE ?
    OR t.barcode LIKE ?
    OR CAST(t.sys AS CHAR) LIKE ?
    OR t.fecha LIKE ?
    OR EXISTS (SELECT 1 FROM materias mf WHERE mf.sys = t.sys AND mf.materia LIKE ?)
  )';
  array_push($params, $f, $f, $f, $f, $f);
  $types .= 'sssss';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

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
