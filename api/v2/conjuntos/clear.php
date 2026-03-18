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

$db = db();
$db->begin_transaction();

try {
    q("DELETE FROM set_items_v2 WHERE set_id=? AND item_type='sobre'", "i", [$setId]);
    q("DELETE FROM set_sobre_photos_v2 WHERE set_id=?", "i", [$setId]);
    q("DELETE FROM set_sobre_progress_v2 WHERE set_id=?", "i", [$setId]);

    $db->commit();
    jexit(['ok'=>true]);
} catch (Throwable $e) {
    $db->rollback();
    jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
