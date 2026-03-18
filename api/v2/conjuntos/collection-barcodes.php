<?php
declare(strict_types=1);

require_once __DIR__ . '/../_common.php';
$u = require_user_api();

$uid = (int)$u['id'];
$cid = (int)($_GET['collection_id'] ?? 0);
if ($cid <= 0) jexit(['ok'=>false,'error'=>'bad_params'], 400);

$db = db();

// permiso: curada+public o propia
$ok = false;
if ($st = $db->prepare("
  SELECT id, is_public, is_curated, owner_user_id, created_by_user_id
  FROM collections_v2
  WHERE id=?
  LIMIT 1
")) {
  $st->bind_param('i', $cid);
  $st->execute();
  $rs = $st->get_result();
  $r = $rs->fetch_assoc();
  $st->close();

  if ($r) {
    $isCur = ((int)$r['is_curated'] === 1 && (int)$r['is_public'] === 1);
    $isMine = ((int)$r['owner_user_id'] === $uid || (int)$r['created_by_user_id'] === $uid);
    $ok = ($isCur || $isMine);
  }
}
if (!$ok) jexit(['ok'=>false,'error'=>'not_allowed'], 403);

$barcodes = [];
if ($st = $db->prepare("
  SELECT DISTINCT SUBSTRING_INDEX(image_key,'_',1) AS barcode
  FROM collection_items_v2
  WHERE collection_id=?
")) {
  $st->bind_param('i', $cid);
  $st->execute();
  $rs = $st->get_result();
  while ($row = $rs->fetch_assoc()) {
    $bc = trim((string)($row['barcode'] ?? ''));
    if ($bc !== '') $barcodes[] = $bc;
  }
  $st->close();
}

jexit(['ok'=>true, 'barcodes'=>$barcodes]);
