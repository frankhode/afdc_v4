<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';

$scope = (string)($_GET['scope'] ?? 'my');      // my | public
$imageKey = trim((string)($_GET['image_key'] ?? ''));

try {
  if ($scope === 'public') {
    // públicas: visibles sin login
    $rows = q(
      "SELECT c.id, c.title, c.description, c.is_public,
              (SELECT COUNT(*) FROM collection_items_v2 i WHERE i.collection_id=c.id) AS item_count
       FROM collections_v2 c
       WHERE c.is_public=1
       ORDER BY c.title ASC
       LIMIT 200"
    );
  } else {
    // privadas: requieren login
    $u = require_user_api();
    $rows = q(
      "SELECT c.id, c.title, c.description, c.is_public,
              (SELECT COUNT(*) FROM collection_items_v2 i WHERE i.collection_id=c.id) AS item_count
       FROM collections_v2 c
       WHERE c.created_by_user_id=? AND c.is_public=0
       ORDER BY c.created_at DESC
       LIMIT 200",
      "i",
      [(int)$u['id']]
    );
  }

  // Marcar cuáles ya contienen la imagen actual (si viene image_key)
  $inIds = [];
  if ($imageKey !== '' && $rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $place = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $hit = q(
      "SELECT collection_id FROM collection_items_v2
       WHERE image_key=? AND collection_id IN ($place)",
      "s" . $types,
      array_merge([$imageKey], $ids)
    );
    $inIds = array_map(fn($r) => (int)$r['collection_id'], $hit);
  }

  $out = [];
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $out[] = [
      'id' => $id,
      'title' => (string)$r['title'],
      'description' => (string)($r['description'] ?? ''),
      'is_public' => (int)$r['is_public'],
      'item_count' => (int)($r['item_count'] ?? 0),
      'is_in' => in_array($id, $inIds, true),
      'has_current' => in_array($id, $inIds, true),
    ];
  }

  jexit(['ok'=>true, 'collections'=>$out]);
} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
