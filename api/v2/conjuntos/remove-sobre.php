<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
$u = require_user_api();
require_csrf();

$in = read_json();
$uid = (int)$u['id'];
$setId = (int)($in['set_id'] ?? 0);
$barcode = trim((string)($in['barcode'] ?? ''));

if ($setId <= 0 || $barcode === '') jexit(['ok'=>false,'error'=>'bad_params'], 400);

$db = db();

// check ownership
$own = false;
if ($st = $db->prepare("SELECT id FROM sets_v2 WHERE id=? AND owner_user_id=? LIMIT 1")) {
  $st->bind_param('ii', $setId, $uid);
  $st->execute();
  $rs = $st->get_result();
  $own = (bool)$rs->fetch_assoc();
  $st->close();
}
if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);

// borrar item
if ($st = $db->prepare("DELETE FROM set_items_v2 WHERE set_id=? AND item_type='sobre' AND item_key=?")) {
  $st->bind_param('is', $setId, $barcode);
  $st->execute();
  $st->close();
}

jexit(['ok'=>true]);
