<?php
declare(strict_types=1);

/**
 * Repo de colecciones v2 (DB: collections_v2 / collection_items_v2)
 * Campos reales:
 *  - collections_v2: id, title, description, created_by_user_id, is_public, created_at
 *  - collection_items_v2: collection_id, image_key, position, added_at
 *
 * Convención:
 *  - "Curadas (públicas)" => is_public=1 (sin importar el dueño; típicamente admin)
 *  - "Mis colecciones"    => created_by_user_id = usuario actual
 */

/** @return array<int,array<string,mixed>> */
function v2_collections_list_public(): array {
        $rows = q(
            "SELECT c.id, c.title, c.description, c.is_public, c.created_by_user_id,
                (SELECT COUNT(*) FROM collection_items_v2 ci WHERE ci.collection_id=c.id) AS count_items
         FROM collections_v2 c
         WHERE c.is_public=1
         ORDER BY c.title ASC"
    );
    return $rows ?: [];
}

/** @return array<int,array<string,mixed>> */
function v2_collections_list_my(int $uid): array {
        $rows = q(
            "SELECT c.id, c.title, c.description, c.is_public, c.created_by_user_id,
                (SELECT COUNT(*) FROM collection_items_v2 ci WHERE ci.collection_id=c.id) AS count_items
         FROM collections_v2 c
         WHERE c.created_by_user_id=?
         ORDER BY c.id DESC",
        "i",
        [$uid]
    );
    return $rows ?: [];
}

function v2_collection_get_accessible(int $collectionId, int $uid): ?array {
        // pública
    $pub = q(
            "SELECT id, title, description, is_public, created_by_user_id
         FROM collections_v2
         WHERE id=? AND is_public=1
         LIMIT 1",
        "i",
        [$collectionId]
    );
    if ($pub) return $pub[0];

    // privada (requiere login)
    if ($uid > 0) {
            $my = q(
                "SELECT id, title, description, is_public, created_by_user_id
             FROM collections_v2
             WHERE id=? AND created_by_user_id=?
             LIMIT 1",
            "ii",
            [$collectionId, $uid]
        );
        if ($my) return $my[0];
    }
    return null;
}

function v2_collection_count_items(int $collectionId): int {
        $r = q("SELECT COUNT(*) AS c FROM collection_items_v2 WHERE collection_id=?", "i", [$collectionId]);
    return (int)($r[0]['c'] ?? 0);
}

/** @return array<int,array{item_type:string,item_key:string,image_key:string,position:int}> */
function v2_collection_get_items_page(int $collectionId, int $limit, int $offset): array {

    $rows = q(
        "SELECT
            COALESCE(item_type,'foto') AS item_type,
            COALESCE(item_key,image_key) AS item_key,
            image_key,
            position
         FROM collection_items_v2
         WHERE collection_id=?
         ORDER BY position ASC, COALESCE(item_key,image_key) ASC
         LIMIT ? OFFSET ?",
        "iii",
        [$collectionId, $limit, $offset]
    );

    return $rows ?: [];
}

/**
 * Resolver on-the-fly: image_key FOxxxxxx_010 -> digitales -> /bajas/cajon/barcode/nombramiento
 * Estrategia: agrupar por barcode y traer TODAS las filas de digitales para ese inv (solo Bajas),
 * luego mapear por label extraída del nombramiento.
 *
 * @param array<int,array<string,mixed>> $items rows con image_key
 * @return array<string,array<string,mixed>> map image_key => [barcode,label,cajon,nombramiento,url,ok]
 */
function v2_resolve_imagekeys_to_digital(array $items): array {
        $out = [];
    if (!$items) return $out;

    $byBarcode = [];
    foreach ($items as $it) {
            $k = (string)($it['image_key'] ?? '');
        if ($k === '' || strpos($k, '_') === false) continue;
        [$barcode, $label] = explode('_', $k, 2);
        $barcode = trim($barcode);
        $label = trim($label);
        if ($barcode === '' || $label === '') continue;
        $label = str_pad(preg_replace('/\\D+/', '', $label), 3, '0', STR_PAD_LEFT);
        $byBarcode[$barcode][] = $label;
        $out[$k] = ['barcode'=>$barcode,'label'=>$label,'ok'=>false,'url'=>''];
    }

    $base = rtrim((string)BASE_URL, '/');

    foreach ($byBarcode as $barcode => $labels) {
            // Traer todas las imágenes de BAJAS para el sobre (inv)
        $rows = q(
                "SELECT cajon, nombramiento
             FROM digitales
             WHERE inv=?
               AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
               AND nombramiento IS NOT NULL
               AND nombramiento <> ''",
            "s",
            [$barcode]
        );
        if (!$rows) continue;

        // Mapear label -> (cajon, nombramiento)
        $map = [];
        foreach ($rows as $r) {
                $name = (string)($r['nombramiento'] ?? '');
            if ($name === '') continue;
            if (preg_match('/_(\\d{1,4})\\.(jpe?g|png)$/i', $name, $m)) {
                    $lab = str_pad((string)(int)$m[1], 3, '0', STR_PAD_LEFT);
                if (!isset($map[$lab])) {
                        $map[$lab] = [
                            'cajon' => (string)($r['cajon'] ?? ''),
                        'nombramiento' => $name,
                    ];
                }
            }
        }

        foreach ($out as $k => $info) {
                if (($info['barcode'] ?? '') !== $barcode) continue;
            $lab = (string)($info['label'] ?? '');
            if (!isset($map[$lab])) continue;

            $cj = trim((string)$map[$lab]['cajon']);
            $nm = (string)$map[$lab]['nombramiento'];

            $url = $base . '/bajas/' . rawurlencode($cj) . '/' . rawurlencode($barcode) . '/' . rawurlencode($nm);
            $out[$k]['cajon'] = $cj;
            $out[$k]['nombramiento'] = $nm;
            $out[$k]['url'] = $url;
            $out[$k]['ok'] = true;
        }
    }

    return $out;
}
function v2_collection_rename(int $collectionId, int $uid, string $title): bool {

    $title = trim($title);
    if ($title === '') return false;

    $r = q(
        "UPDATE collections_v2
         SET title=?
         WHERE id=? AND created_by_user_id=?",
        "sii",
        [$title, $collectionId, $uid]
    );

    return $r !== false;
}
function v2_collection_update_meta(
    int $collectionId,
    int $uid,
    string $title,
    string $description,
    int $isPublic
): bool {

    $title = trim($title);
    $description = trim($description);
    $isPublic = $isPublic ? 1 : 0;

    if ($title === '') return false;

    $r = q(
        "UPDATE collections_v2
         SET title=?, description=?, is_public=?
         WHERE id=? AND created_by_user_id=?",
        "ssiii",
        [$title, $description, $isPublic, $collectionId, $uid]
    );

    return $r !== false;
}

function v2_collection_delete(int $collectionId, int $uid): bool {

    // borrar items primero
    q(
        "DELETE ci
         FROM collection_items_v2 ci
         JOIN collections_v2 c ON c.id=ci.collection_id
         WHERE ci.collection_id=? AND c.created_by_user_id=?",
        "ii",
        [$collectionId, $uid]
    );

    // borrar colección
    $r = q(
        "DELETE FROM collections_v2
         WHERE id=? AND created_by_user_id=?",
        "ii",
        [$collectionId, $uid]
    );

    return $r !== false;
}

function v2_collection_clear(int $collectionId, int $uid): bool {

    $r = q(
        "DELETE ci
         FROM collection_items_v2 ci
         JOIN collections_v2 c ON c.id=ci.collection_id
         WHERE ci.collection_id=? AND c.created_by_user_id=?",
        "ii",
        [$collectionId, $uid]
    );

    return $r !== false;
}

function v2_collection_reorder_items(int $collectionId, int $uid, array $items): bool {

    if (!$items) return false;

    // seguridad: verificar que la colección pertenece al usuario
    $check = q(
        "SELECT id FROM collections_v2 WHERE id=? AND created_by_user_id=? LIMIT 1",
        "ii",
        [$collectionId, $uid]
    );

    if (!$check) return false;

    $pos = 1;

    foreach ($items as $it) {

        $itemKey = (string)($it['item_key'] ?? '');
        if ($itemKey === '') continue;

        q(
            "UPDATE collection_items_v2
             SET position=?
             WHERE collection_id=? AND (item_key=? OR image_key=?)",
            "iiss",
            [$pos, $collectionId, $itemKey, $itemKey]
        );

        $pos++;
    }

    return true;
}


