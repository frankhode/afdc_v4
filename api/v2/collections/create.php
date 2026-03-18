<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';

$u = require_user_api();
require_csrf();

$in = read_json();
$title = trim((string)($in['title'] ?? ''));
$desc  = trim((string)($in['description'] ?? ''));
$imageKey = trim((string)($in['image_key'] ?? ''));
$addCurrent = !empty($in['add_current']);

if ($title === '') jexit(['ok'=>false,'error'=>'title_required'], 400);

q(
  "INSERT INTO collections_v2 (created_by_user_id, title, description, is_public, created_at)
   VALUES (?,?,?,?,NOW())",
  "issi",
  [(int)$u['id'], $title, $desc, 0]
);

$newIdRow = q("SELECT LAST_INSERT_ID() AS id");
$newId = (int)($newIdRow[0]['id'] ?? 0);

if ($newId > 0 && $addCurrent && $imageKey !== '') {
  q(
    "INSERT INTO collection_items_v2 (collection_id, image_key, position, added_at)
     VALUES (?,?,1,NOW())",
    "is",
    [$newId, $imageKey]
  );
}

jexit(['ok'=>true, 'id'=>$newId]);
