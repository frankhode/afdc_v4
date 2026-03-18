<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';

$u = require_user_api();
require_csrf();

$in = read_json();
$srcId = (int)($in['source_collection_id'] ?? 0);
if ($srcId <= 0) jexit(['ok'=>false,'error'=>'bad_params'], 400);

$src = q("SELECT id, title, description FROM collections_v2 WHERE id=? AND is_public=1 LIMIT 1", "i", [$srcId]);
if (!$src) jexit(['ok'=>false,'error'=>'not_found'], 404);

$srcTitle = (string)$src[0]['title'];
$srcDesc  = (string)($src[0]['description'] ?? '');
$newTitle = trim((string)($in['title'] ?? ''));
if ($newTitle === '') $newTitle = $srcTitle . ' (copia)';

$db = db();
$db->begin_transaction();

try {
  q(
    "INSERT INTO collections_v2 (created_by_user_id, title, description, is_public, created_at)
     VALUES (?,?,?,?,NOW())",
    "issi",
    [(int)$u['id'], $newTitle, $srcDesc, 0]
  );
  $newIdRow = q("SELECT LAST_INSERT_ID() AS id");
  $newId = (int)($newIdRow[0]['id'] ?? 0);

  // Copiar items preservando position
  q(
    "INSERT INTO collection_items_v2 (collection_id, image_key, position, added_at)
     SELECT ?, image_key, position, NOW()
     FROM collection_items_v2
     WHERE collection_id=?
     ORDER BY position ASC",
    "ii",
    [$newId, $srcId]
  );

  $db->commit();
  jexit(['ok'=>true, 'id'=>$newId, 'title'=>$newTitle]);
} catch (Throwable $e) {
  $db->rollback();
  jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
