<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();
require_csrf();

$in = read_json();
$setId = (int)($in['set_id'] ?? 0);
$barcode = v2_barcode_sanitize((string)($in['barcode'] ?? ''));

$photoIdx = isset($in['photo_idx']) ? (int)$in['photo_idx'] : 0;
if ($photoIdx <= 0) {
    $ik = trim((string)($in['image_key'] ?? ''));
    $pi = $ik !== '' ? v2_photo_idx_from_image_key($ik) : null;
    $photoIdx = $pi ? (int)$pi : 0;
}

if ($barcode === '' || $photoIdx <= 0) jexit(['ok'=>false,'error'=>'bad_params'], 400);

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
    // marcar vista (idempotente)
    q(
        "UPDATE set_sobre_photos_v2
         SET seen_at=COALESCE(seen_at, NOW())
         WHERE set_id=? AND barcode=? AND photo_idx=?",
        "isi",
        [$setId, $barcode, $photoIdx]
    );

    // recalcular progreso (simple y consistente)
    $p = v2_set_recalc_progress($setId, $barcode);

    $db->commit();
    jexit([
        'ok' => true,
        'progress' => [
            'total' => (int)$p['total'],
            'seen' => (int)$p['seen'],
            'completed' => (bool)$p['completed']
        ]
    ]);
} catch (Throwable $e) {
    $db->rollback();
    jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
