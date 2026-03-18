<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
$u = require_user_api();
require_csrf();

$in = read_json();
$uid = (int)$u['id'];
$setId = (int)($in['set_id'] ?? 0);

if ($setId <= 0) jexit(['ok'=>false,'error'=>'bad_params'], 400);

$db = db();

// ownership
$own = false;
if ($st = $db->prepare("SELECT id FROM sets_v2 WHERE id=? AND owner_user_id=? LIMIT 1")) {
  $st->bind_param('ii', $setId, $uid);
  $st->execute();
  $rs = $st->get_result();
  $own = (bool)$rs->fetch_assoc();
  $st->close();
}
if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);

// barcodes completados
$barcodes = [];
if ($st = $db->prepare("SELECT barcode FROM set_sobre_progress_v2 WHERE set_id=? AND completed_at IS NOT NULL")) {
  $st->bind_param('i', $setId);
  $st->execute();
  $rs = $st->get_result();
  while ($r = $rs->fetch_assoc()) {
    $bc = trim((string)($r['barcode'] ?? ''));
    if ($bc !== '') $barcodes[] = $bc;
  }
  $st->close();
}
if (!$barcodes) jexit(['ok'=>true, 'removed'=>0]);

$inClause = implode(',', array_fill(0, count($barcodes), '?'));
$types = 'i' . str_repeat('s', count($barcodes));
$params = array_merge([$setId], $barcodes);

// delete items
$sql1 = "DELETE FROM set_items_v2 WHERE set_id=? AND item_type='sobre' AND item_key IN ($inClause)";
$sql2 = "DELETE FROM set_sobre_photos_v2 WHERE set_id=? AND barcode IN ($inClause)";
$sql3 = "DELETE FROM set_sobre_progress_v2 WHERE set_id=? AND barcode IN ($inClause)";

$db->begin_transaction();
try {
  $st = $db->prepare($sql1); $st->bind_param($types, ...$params); $st->execute(); $st->close();
  $st = $db->prepare($sql2); $st->bind_param($types, ...$params); $st->execute(); $st->close();
  $st = $db->prepare($sql3); $st->bind_param($types, ...$params); $st->execute(); $st->close();
  $db->commit();
} catch (Throwable $e) {
  $db->rollback();
  jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}

jexit(['ok'=>true, 'removed'=>count($barcodes)]);
