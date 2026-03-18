<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();

$setId = (int)($_GET['set_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'all'); // pending | inprogress | done | all

if ($setId <= 0) {
    $def = v2_sets_ensure_arevisar((int)$u['id']);
    $setId = (int)$def['id'];
} else {
    $own = v2_sets_get_owned($setId, (int)$u['id']);
    if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);
}

$where = "si.set_id=? AND si.item_type='sobre'";
$params = [$setId];
$types = "i";

if ($status === 'done') {
    $where .= " AND p.completed_at IS NOT NULL";
} elseif ($status === 'inprogress') {
    $where .= " AND p.completed_at IS NULL AND COALESCE(p.seen_photos,0) > 0";
} elseif ($status === 'pending') {
    $where .= " AND p.completed_at IS NULL AND COALESCE(p.seen_photos,0) = 0";
}

$rows = q(
    "SELECT
        si.item_key AS barcode,
        si.added_at,
        COALESCE(p.total_photos,0) AS total_photos,
        COALESCE(p.seen_photos,0) AS seen_photos,
        p.completed_at,
        t.titulo,
        t.fecha
     FROM set_items_v2 si
     LEFT JOIN set_sobre_progress_v2 p
       ON p.set_id=si.set_id AND p.barcode=si.item_key
     LEFT JOIN titulos t
       ON t.barcode=si.item_key
     WHERE {$where}
     ORDER BY
       (p.completed_at IS NOT NULL) ASC,
       COALESCE(p.seen_photos,0) DESC,
       si.added_at DESC
     LIMIT 500",
    $types,
    $params
);

$out = [];
foreach ($rows as $r) {
    $total = (int)$r['total_photos'];
    $seen  = (int)$r['seen_photos'];
    $done  = !empty($r['completed_at']);
    $state = $done ? 'done' : (($seen > 0) ? 'inprogress' : 'pending');

    $out[] = [
        'barcode' => (string)$r['barcode'],
        'state' => $state,
        'total_photos' => $total,
        'seen_photos' => $seen,
        'completed_at' => $r['completed_at'] ? (string)$r['completed_at'] : null,
        'titulo' => (string)($r['titulo'] ?? ''),
        'fecha' => (string)($r['fecha'] ?? ''),
    ];
}

jexit(['ok'=>true, 'set_id'=>$setId, 'items'=>$out]);
