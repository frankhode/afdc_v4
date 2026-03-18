<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();

$setId = (int)($_GET['set_id'] ?? 0);
$barcode = v2_barcode_sanitize((string)($_GET['barcode'] ?? ''));

if ($barcode === '') jexit(['ok'=>false,'error'=>'bad_barcode'], 400);

if ($setId <= 0) {
    $def = v2_sets_ensure_arevisar((int)$u['id']);
    $setId = (int)$def['id'];
} else {
    $own = v2_sets_get_owned($setId, (int)$u['id']);
    if (!$own) jexit(['ok'=>false,'error'=>'not_allowed'], 403);
}

// aseguramos que el sobre esté dentro del set (por si abrís directo)
v2_set_ensure_sobre_membership($setId, $barcode);

// snapshot existe?
$has = q(
    "SELECT COUNT(*) AS c FROM set_sobre_photos_v2 WHERE set_id=? AND barcode=?",
    "is",
    [$setId, $barcode]
);
$exists = ((int)($has[0]['c'] ?? 0) > 0);

$base = rtrim((string)BASE_URL, '/');

if (!$exists) {
    // crear snapshot desde digitales (Bajas)
    $rows = q(
        "SELECT cajon, nombramiento
         FROM digitales
         WHERE inv=?
           AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
           AND nombramiento IS NOT NULL
           AND nombramiento <> ''
         ORDER BY nombramiento ASC",
        "s",
        [$barcode]
    );

    $toIns = [];
    foreach ($rows as $r) {
        $nom = (string)($r['nombramiento'] ?? '');
        $idx = $nom !== '' ? v2_photo_idx_from_name($nom) : null;
        if ($idx === null) continue;

        $lab = str_pad((string)$idx, 3, '0', STR_PAD_LEFT);
        $imageKey = $barcode . '_' . $lab;

        $toIns[] = [
            'photo_idx' => $idx,
            'image_key' => $imageKey,
            'cajon' => (string)($r['cajon'] ?? ''),
            'nombramiento' => $nom,
        ];
    }

    // dedupe por idx
    $byIdx = [];
    foreach ($toIns as $it) {
        $i = (int)$it['photo_idx'];
        if (!isset($byIdx[$i])) $byIdx[$i] = $it;
    }
    ksort($byIdx, SORT_NUMERIC);

    foreach ($byIdx as $it) {
        q(
            "INSERT IGNORE INTO set_sobre_photos_v2
             (set_id, barcode, photo_idx, image_key, cajon, nombramiento, seen_at, created_at)
             VALUES (?,?,?,?,?,?,NULL, NOW())",
            "isisss",
            [$setId, $barcode, (int)$it['photo_idx'], (string)$it['image_key'], (string)$it['cajon'], (string)$it['nombramiento']]
        );
    }

    // recalcular progreso luego de snapshot
    v2_set_recalc_progress($setId, $barcode);
}

// traer info de título
$tit = q("SELECT titulo, fecha FROM titulos WHERE barcode=? LIMIT 1", "s", [$barcode]);
$titulo = $tit ? (string)($tit[0]['titulo'] ?? '') : '';
$fecha  = $tit ? (string)($tit[0]['fecha'] ?? '') : '';

// traer fotos
$photos = q(
    "SELECT photo_idx, image_key, cajon, nombramiento, (seen_at IS NOT NULL) AS seen
     FROM set_sobre_photos_v2
     WHERE set_id=? AND barcode=?
     ORDER BY photo_idx ASC",
    "is",
    [$setId, $barcode]
) ?: [];

$progress = q(
    "SELECT total_photos, seen_photos, completed_at
     FROM set_sobre_progress_v2
     WHERE set_id=? AND barcode=?
     LIMIT 1",
    "is",
    [$setId, $barcode]
);

$total = (int)($progress[0]['total_photos'] ?? 0);
$seenN = (int)($progress[0]['seen_photos'] ?? 0);
$completedAt = !empty($progress[0]['completed_at']) ? (string)$progress[0]['completed_at'] : null;

$outPhotos = [];
foreach ($photos as $p) {
    $cj = (string)($p['cajon'] ?? '');
    $nom = (string)($p['nombramiento'] ?? '');
    $ik  = (string)($p['image_key'] ?? '');

    $thumb = ($cj && $nom)
        ? ($base . '/thumb.php?cajon=' . rawurlencode($cj) . '&inv=' . rawurlencode($barcode) . '&nom=' . rawurlencode($nom) . '&h=140')
        : '';

    $full = ($cj && $nom)
        ? ($base . '/bajas/' . rawurlencode($cj) . '/' . rawurlencode($barcode) . '/' . rawurlencode($nom))
        : '';

    $outPhotos[] = [
        'photo_idx' => (int)$p['photo_idx'],
        'image_key' => $ik,
        'thumb_url' => $thumb,
        'full_url' => $full,
        'seen' => ((int)$p['seen'] === 1),
    ];
}

jexit([
    'ok' => true,
    'set_id' => $setId,
    'barcode' => $barcode,
    'titulo' => $titulo,
    'fecha' => $fecha,
    'progress' => [
        'total' => $total,
        'seen' => $seenN,
        'completed_at' => $completedAt,
    ],
    'photos' => $outPhotos
]);
