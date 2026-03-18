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

$barcodes = [];
if (isset($in['barcodes']) && is_array($in['barcodes'])) {
    $barcodes = $in['barcodes'];
}

$clean = [];
foreach ($barcodes as $b) {
    $bc = v2_barcode_sanitize((string)$b);
    if ($bc !== '') $clean[$bc] = true;
}
$clean = array_keys($clean);

if (!$clean) jexit(['ok'=>true, 'added'=>0, 'total'=>0]);

$db = db();
$db->begin_transaction();

try {
    $pos = v2_set_next_position($setId);
    $added = 0;

    foreach ($clean as $bc) {
        // IGNORE por PK (set_id,item_type,item_key)
        q(
            "INSERT IGNORE INTO set_items_v2 (set_id, item_type, item_key, position, added_at)
             VALUES (?, 'sobre', ?, ?, NOW())",
            "isi",
            [$setId, $bc, $pos]
        );
        // Si insertó, afectadas=1; si ignoró, 0. (MySQLi report error/strict ya está)
        // Como q() no devuelve affected_rows, hacemos conteo a lo seguro:
        // incrementamos posición igual (mantener orden estable)
        $pos++;
    }

    // total items del set (sobres)
    $cnt = q(
        "SELECT COUNT(*) AS c FROM set_items_v2 WHERE set_id=? AND item_type='sobre'",
        "i",
        [$setId]
    );

    $db->commit();
    jexit([
        'ok'=>true,
        'added'=>(int)count($clean), // "pedidos" (puede haber duplicados ignorados)
        'total'=>(int)($cnt[0]['c'] ?? 0)
    ]);
} catch (Throwable $e) {
    $db->rollback();
    jexit(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}
