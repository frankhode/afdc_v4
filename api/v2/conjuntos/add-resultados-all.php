<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();
require_csrf();

$in = read_json();

$modo       = trim((string)($in['modo'] ?? ''));
$campo      = trim((string)($in['campo'] ?? ''));
$termino    = trim((string)($in['termino'] ?? ''));
$campeonato = trim((string)($in['campeonato'] ?? ''));
$equipo     = trim((string)($in['equipo'] ?? ''));
$filter     = trim((string)($in['filter'] ?? ''));

// set default A revisar
$def = v2_sets_ensure_arevisar((int)$u['id']);
$setId = (int)$def['id'];

if ($setId <= 0) jexit(['ok'=>false,'error'=>'set_missing'], 500);

$types = '';
$params = [];
$where = '';

if ($modo === 'indice') {
  if ($campo === '' || $termino === '') jexit(['ok'=>false,'error'=>'bad_params'], 400);

  $where = "FROM titulos t
            INNER JOIN materias mf ON mf.sys = t.sys
            WHERE mf.campo = ? AND mf.materia = ?";
  $types .= 'ss';
  $params[] = $campo;
  $params[] = $termino;

} else {
  // fútbol
  if ($campeonato !== '') $modo = 'campeonato';
  else if ($equipo !== '') $modo = 'equipo';

  if ($modo === 'campeonato') {
    $where = "FROM partidos p
              LEFT JOIN titulos t ON t.barcode = p.barcode
              LEFT JOIN materias mm ON mm.sys = t.sys
              WHERE p.tituloReg = ?";
    $types .= 's';
    $params[] = $campeonato;

  } else if ($modo === 'equipo') {
    $where = "FROM partidos p
              LEFT JOIN titulos t ON t.barcode = p.barcode
              LEFT JOIN materias mm ON mm.sys = t.sys
              WHERE (p.equipo1 = ? OR p.equipo2 = ?)";
    $types .= 'ss';
    $params[] = $equipo;
    $params[] = $equipo;

  } else {
    jexit(['ok'=>false,'error'=>'bad_params'], 400);
  }
}

// filtro refinador (misma idea que el resto)
if ($filter !== '') {
  $f = '%' . $filter . '%';

  if ($modo === 'indice') {
    $where .= " AND (
      t.titulo LIKE ?
      OR t.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR EXISTS (SELECT 1 FROM materias mx WHERE mx.sys = t.sys AND mx.materia LIKE ?)
    )";
    $types .= 'sssss';
    array_push($params, $f, $f, $f, $f, $f);

  } else {
    // fútbol: sumamos p.* además de t/mm
    $where .= " AND (
      t.titulo LIKE ?
      OR p.barcode LIKE ?
      OR CAST(t.sys AS CHAR) LIKE ?
      OR t.fecha LIKE ?
      OR mm.materia LIKE ?
      OR p.tituloReg LIKE ?
      OR p.equipo1 LIKE ?
      OR p.equipo2 LIKE ?
    )";
    $types .= 'ssssssss';
    array_push($params, $f, $f, $f, $f, $f, $f, $f, $f);
  }
}

// SELECT DISTINCT barcode según modo
if ($modo === 'indice') {
  $sqlInsert = "
    INSERT IGNORE INTO set_items_v2 (set_id, item_type, item_key, position, added_at)
    SELECT ?, 'sobre', z.barcode, 0, NOW()
    FROM (
      SELECT DISTINCT t.barcode AS barcode
      {$where}
    ) z
    WHERE z.barcode IS NOT NULL AND z.barcode <> ''
  ";
} else {
  $sqlInsert = "
    INSERT IGNORE INTO set_items_v2 (set_id, item_type, item_key, position, added_at)
    SELECT ?, 'sobre', z.barcode, 0, NOW()
    FROM (
      SELECT DISTINCT p.barcode AS barcode
      {$where}
    ) z
    WHERE z.barcode IS NOT NULL AND z.barcode <> ''
  ";
}

q($sqlInsert, 'i' . $types, array_merge([$setId], $params));

$cnt = q("SELECT COUNT(*) AS c FROM set_items_v2 WHERE set_id=? AND item_type='sobre'", "i", [$setId]);
jexit(['ok'=>true, 'set_id'=>$setId, 'total'=>(int)($cnt[0]['c'] ?? 0)]);
