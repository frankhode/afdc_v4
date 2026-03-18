<?php
declare(strict_types=1);

/**
 * Helpers compartidos para /api/v2/conjuntos/*
 * Requiere que ya exista q(), db() desde bootstrap.
 */

function v2_barcode_sanitize(string $barcode): string {
    $barcode = strtoupper(trim($barcode));
    if ($barcode === '' || strlen($barcode) > 32) return '';
    if (!preg_match('/^[A-Z0-9]+$/', $barcode)) return '';
    return $barcode;
}

function v2_photo_idx_from_name(string $nombramiento): ?int {
    // FO0064054_016.jpg -> 16
    if (preg_match('/_(\d{1,4})\.(jpe?g|png|webp)$/i', $nombramiento, $m)) {
        return (int)$m[1];
    }
    return null;
}

function v2_photo_idx_from_image_key(string $imageKey): ?int {
    // FO0064054_016 -> 16
    if (preg_match('/_(\d{1,4})$/', $imageKey, $m)) return (int)$m[1];
    return null;
}

function v2_sets_get_owned(int $setId, int $uid): ?array {
    if ($setId <= 0) return null;
    $rows = q(
        "SELECT id, owner_user_id, name, description, kind
         FROM sets_v2
         WHERE id=? AND owner_user_id=?
         LIMIT 1",
        "ii",
        [$setId, $uid]
    );
    return $rows ? $rows[0] : null;
}

function v2_sets_ensure_arevisar(int $uid): array {
    $name = 'A revisar';

    $rows = q(
        "SELECT id, name, description, kind
         FROM sets_v2
         WHERE owner_user_id=? AND kind='def' AND name=?
         LIMIT 1",
        "is",
        [$uid, $name]
    );

    if ($rows) return $rows[0];

    q(
        "INSERT INTO sets_v2 (owner_user_id, name, description, kind, created_at, updated_at)
         VALUES (?,?,?, 'def', NOW(), NOW())",
        "iss",
        [$uid, $name, 'Conjunto default para revisión']
    );

    $idRow = q("SELECT LAST_INSERT_ID() AS id");
    $newId = (int)($idRow[0]['id'] ?? 0);

    $rows2 = q(
        "SELECT id, name, description, kind
         FROM sets_v2
         WHERE id=? AND owner_user_id=?
         LIMIT 1",
        "ii",
        [$newId, $uid]
    );

    return $rows2 ? $rows2[0] : ['id'=>$newId,'name'=>$name,'description'=>'','kind'=>'def'];
}

function v2_sets_list(int $uid): array {
    return q(
        "SELECT id, name, description, kind, created_at, updated_at
         FROM sets_v2
         WHERE owner_user_id=?
         ORDER BY (kind='def') DESC, updated_at DESC, id DESC
         LIMIT 200",
        "i",
        [$uid]
    ) ?: [];
}

function v2_set_next_position(int $setId): int {
    $mx = q("SELECT COALESCE(MAX(position),0) AS m FROM set_items_v2 WHERE set_id=?", "i", [$setId]);
    return (int)($mx[0]['m'] ?? 0) + 1;
}

function v2_set_ensure_sobre_membership(int $setId, string $barcode): void {
    // Inserta si no existe (posición al final). Ignora si ya está.
    $barcode = v2_barcode_sanitize($barcode);
    if ($barcode === '') return;

    $exists = q(
        "SELECT 1 FROM set_items_v2 WHERE set_id=? AND item_type='sobre' AND item_key=? LIMIT 1",
        "is",
        [$setId, $barcode]
    );
    if ($exists) return;

    $pos = v2_set_next_position($setId);
    q(
        "INSERT IGNORE INTO set_items_v2 (set_id, item_type, item_key, position, added_at)
         VALUES (?, 'sobre', ?, ?, NOW())",
        "isi",
        [$setId, $barcode, $pos]
    );
}

function v2_set_recalc_progress(int $setId, string $barcode): array {
    $barcode = v2_barcode_sanitize($barcode);
    if ($barcode === '') return ['total'=>0,'seen'=>0,'completed'=>false];

    $tot = q(
        "SELECT COUNT(*) AS c
         FROM set_sobre_photos_v2
         WHERE set_id=? AND barcode=?",
        "is",
        [$setId, $barcode]
    );
    $seen = q(
        "SELECT COUNT(*) AS c
         FROM set_sobre_photos_v2
         WHERE set_id=? AND barcode=? AND seen_at IS NOT NULL",
        "is",
        [$setId, $barcode]
    );

    $total = (int)($tot[0]['c'] ?? 0);
    $seenN = (int)($seen[0]['c'] ?? 0);
    $completed = ($total > 0 && $seenN >= $total);

    // Upsert progress
    q(
        "INSERT INTO set_sobre_progress_v2 (set_id, barcode, total_photos, seen_photos, completed_at, created_at, updated_at)
         VALUES (?,?,?,?,?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
           total_photos=VALUES(total_photos),
           seen_photos=VALUES(seen_photos),
           completed_at=VALUES(completed_at),
           updated_at=NOW()",
        "isiis",
        [
            $setId,
            $barcode,
            $total,
            $seenN,
            $completed ? date('Y-m-d H:i:s') : null
        ]
    );

    return ['total'=>$total,'seen'=>$seenN,'completed'=>$completed];
}
