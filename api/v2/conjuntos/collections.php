<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
$u = require_user_api();

$uid = (int)$u['id'];
$db = db();

$sql = "
  SELECT id, title, is_public, is_curated,
         CASE WHEN (owner_user_id = ? OR created_by_user_id = ?) THEN 1 ELSE 0 END AS is_mine
  FROM collections_v2
  WHERE (is_public=1 AND is_curated=1)
     OR owner_user_id = ?
     OR created_by_user_id = ?
  ORDER BY is_mine DESC, is_curated DESC, title ASC
";

$items = [];
if ($st = $db->prepare($sql)) {
  $st->bind_param('iiii', $uid, $uid, $uid, $uid);
  $st->execute();
  $rs = $st->get_result();
  while ($r = $rs->fetch_assoc()) {
    $items[] = [
      'id' => (int)$r['id'],
      'title' => (string)$r['title'],
      'is_public' => (int)$r['is_public'],
      'is_curated' => (int)$r['is_curated'],
      'is_mine' => (int)$r['is_mine'],
    ];
  }
  $st->close();
}

jexit(['ok'=>true, 'items'=>$items]);
