<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';

$u = require_user_api();
require_csrf();

$in = read_json();
$collectionId = (int)($in['collection_id'] ?? 0);
$imageKey = trim((string)($in['image_key'] ?? ''));

if ($collectionId <= 0 || $imageKey === '') jexit(['ok'=>false,'error'=>'bad_params'], 400);

// validar ownership
$own = q("SELECT id FROM collections_v2 WHERE id=? AND created_by_user_id=? LIMIT 1", "ii", [$collectionId, (int)$u['id']]);
if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);

$db = db();
$db->begin_transaction();

try {
  $exists = q(
    "SELECT 1 FROM collection_items_v2 WHERE collection_id=? AND image_key=? LIMIT 1",
    "is",
    [$collectionId, $imageKey]
  );

  if ($exists) {
    q("DELETE FROM collection_items_v2 WHERE collection_id=? AND image_key=?", "is", [$collectionId, $imageKey]);
    $isIn = false;
  } else {
    $mx = q("SELECT COALESCE(MAX(position),0) AS m FROM collection_items_v2 WHERE collection_id=?", "i", [$collectionId]);
    $pos = (int)($mx[0]['m'] ?? 0) + 1;
    q(
      "INSERT INTO collection_items_v2 (collection_id, image_key, position, added_at)
       VALUES (?,?,?,NOW())",
      "isi",
      [$collectionId, $imageKey, $pos]
    );
    $isIn = true;
  }

  $cnt = q("SELECT COUNT(*) AS c FROM collection_items_v2 WHERE collection_id=?", "i", [$collectionId]);
  $db->commit();

  jexit(['ok'=>true, 'is_in'=>$isIn, 'item_count'=>(int)($cnt[0]['c'] ?? 0)]);
} catch (Throwable $e) {
  $db->rollback();
  jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
