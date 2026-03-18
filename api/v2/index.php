<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$method = api_method();
$path   = api_path();

/* =========================================================
   Helpers específicos colecciones
   ========================================================= */
function api_collection_row_with_count(array $row): array {
    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'description' => (string)($row['description'] ?? ''),
        'count' => (int)($row['count_items'] ?? 0),
    ];
}

function api_require_my_collection(int $uid, int $collectionId): array {
    $rows = q(
        "SELECT id, owner_user_id, title, description, is_curated
         FROM collections_v2
         WHERE id=? AND owner_user_id=? LIMIT 1",
        "ii",
        [$collectionId, $uid]
    );
    if (!$rows) {
        api_json(['ok' => false, 'error' => 'Colección inexistente o no autorizada'], 404);
    }
    return $rows[0];
}

function api_require_public_curated_collection(int $collectionId): array {
    $rows = q(
        "SELECT id, title, description
         FROM collections_v2
         WHERE id=? AND is_curated=1 AND owner_user_id IS NULL
         LIMIT 1",
        "i",
        [$collectionId]
    );
    if (!$rows) {
        api_json(['ok' => false, 'error' => 'Colección pública no encontrada'], 404);
    }
    return $rows[0];
}

function api_make_copy_title_unique(int $uid, string $baseTitle): string {
    $baseTitle = trim($baseTitle);
    if ($baseTitle === '') $baseTitle = 'Colección';

    $title = $baseTitle . ' (copia)';
    $n = 1;

    while (true) {
        $exists = q(
            "SELECT 1 FROM collections_v2 WHERE owner_user_id=? AND title=? LIMIT 1",
            "is",
            [$uid, $title]
        );
        if (!$exists) return $title;
        $n++;
        $title = $baseTitle . " (copia $n)";
        if ($n > 200) return $baseTitle . ' (copia ' . time() . ')';
    }
}

/* =========================================================
   ROUTES
   ========================================================= */

/* ---------- ME ---------- */
if ($method === 'GET' && $path === '/me') {
    $u = afdc_v2_current_user();
    if (!$u) {
        api_json(['ok' => true, 'logged_in' => false]);
    }
    api_json([
        'ok' => true,
        'logged_in' => true,
        'user' => [
            'id' => (int)$u['id'],
            'username' => (string)$u['username'],
            'role' => (string)$u['role'],
            'display_name' => (string)($u['display_name'] ?? ''),
        ],
        'csrf_token' => afdc_v2_csrf_token(),
    ]);
}

/* ---------- FAVORITES ---------- */
if ($method === 'GET' && $path === '/favorites/status') {
    $u = afdc_v2_current_user();
    $imageKey = (string)($_GET['image_key'] ?? '');
    if (!$u || !$imageKey || !api_validate_image_key($imageKey)) {
        api_json(['ok' => true, 'is_favorite' => false]);
    }
    $rows = q(
        "SELECT 1 FROM user_favorites WHERE user_id=? AND image_key=? LIMIT 1",
        "is",
        [(int)$u['id'], $imageKey]
    );
    api_json(['ok' => true, 'is_favorite' => (bool)$rows]);
}

if ($method === 'POST' && $path === '/favorites/status-bulk') {
    $u = afdc_v2_current_user();
    if (!$u) api_json(['ok' => true, 'map' => (object)[]]);

    $data = api_read_json_body();
    $keys = $data['image_keys'] ?? [];
    if (!is_array($keys) || !$keys) api_json(['ok' => true, 'map' => (object)[]]);

    $keys2 = [];
    foreach ($keys as $k) {
        if (!is_string($k)) continue;
        $k = trim($k);
        if ($k === '' || !api_validate_image_key($k)) continue;
        $keys2[$k] = true;
        if (count($keys2) >= 1500) break;
    }
    $keys2 = array_keys($keys2);
    if (!$keys2) api_json(['ok' => true, 'map' => (object)[]]);

    $placeholders = implode(',', array_fill(0, count($keys2), '?'));
    $sql = "SELECT image_key FROM user_favorites WHERE user_id=? AND image_key IN ($placeholders)";
    $types = 'i' . str_repeat('s', count($keys2));
    $params = array_merge([(int)$u['id']], $keys2);

    $db = db();
    $stmt = $db->prepare($sql);

    $refs = [];
    foreach ($params as $i => $v) $refs[$i] = &$params[$i];
    $stmt->bind_param($types, ...$refs);
    $stmt->execute();
    $res = $stmt->get_result();

    $fav = [];
    while ($row = $res->fetch_assoc()) $fav[(string)$row['image_key']] = true;
    $stmt->close();

    $map = [];
    foreach ($keys2 as $k) $map[$k] = !empty($fav[$k]);

    api_json(['ok' => true, 'map' => $map]);
}

if ($method === 'POST' && $path === '/favorites/toggle') {
    $u = afdc_v2_require_login();
    afdc_v2_csrf_check_from_header();

    $data = api_read_json_body();
    $imageKey = (string)($data['image_key'] ?? '');
    if (!api_validate_image_key($imageKey)) {
        api_json(['ok' => false, 'error' => 'image_key inválido'], 400);
    }

    $uid = (int)$u['id'];

    $exists = q(
        "SELECT 1 FROM user_favorites WHERE user_id=? AND image_key=? LIMIT 1",
        "is",
        [$uid, $imageKey]
    );

    if ($exists) {
        q("DELETE FROM user_favorites WHERE user_id=? AND image_key=?", "is", [$uid, $imageKey]);
        api_json(['ok' => true, 'is_favorite' => false]);
    } else {
        q("INSERT INTO user_favorites (user_id, image_key) VALUES (?, ?)", "is", [$uid, $imageKey]);
        api_json(['ok' => true, 'is_favorite' => true]);
    }
}

/* ---------- COLLECTIONS (v2) ---------- */

/* Lista colecciones (públicas + mías si logueado) */
if ($method === 'GET' && $path === '/collections') {
    $u = afdc_v2_current_user();
    $uid = $u ? (int)$u['id'] : 0;

    $publicRows = q(
        "SELECT c.id, c.title, c.description,
                (SELECT COUNT(*) FROM collection_items_v2 ci WHERE ci.collection_id=c.id) AS count_items
         FROM collections_v2 c
         WHERE c.is_curated=1 AND c.owner_user_id IS NULL
         ORDER BY c.title ASC"
    );

    $myRows = [];
    if ($uid) {
        $myRows = q(
            "SELECT c.id, c.title, c.description,
                    (SELECT COUNT(*) FROM collection_items_v2 ci WHERE ci.collection_id=c.id) AS count_items
             FROM collections_v2 c
             WHERE c.owner_user_id=?
             ORDER BY c.title ASC",
            "i",
            [$uid]
        );
    }

    $public = array_map('api_collection_row_with_count', $publicRows ?: []);
    $my = array_map('api_collection_row_with_count', $myRows ?: []);

    api_json(['ok' => true, 'public' => $public, 'my' => $my]);
}

/* Para la imagen actual: qué colecciones mías la contienen */
if ($method === 'GET' && $path === '/collections/contains') {
    $u = afdc_v2_require_login();
    $uid = (int)$u['id'];

    $imageKey = (string)($_GET['image_key'] ?? '');
    if (!api_validate_image_key($imageKey)) {
        api_json(['ok' => false, 'error' => 'image_key inválido'], 400);
    }

    $rows = q(
        "SELECT ci.collection_id
         FROM collection_items_v2 ci
         INNER JOIN collections_v2 c ON c.id = ci.collection_id
         WHERE c.owner_user_id=? AND ci.image_key=?",
        "is",
        [$uid, $imageKey]
    );

    $map = [];
    foreach ($rows ?: [] as $r) $map[(string)$r['collection_id']] = true;

    api_json(['ok' => true, 'map' => $map]);
}

/* Crear colección privada */
if ($method === 'POST' && $path === '/collections/create') {
    $u = afdc_v2_require_login();
    afdc_v2_csrf_check_from_header();
    $uid = (int)$u['id'];

    $data = api_read_json_body();
    $title = trim((string)($data['title'] ?? ''));
    $desc = (string)($data['description'] ?? '');

    if ($title === '') api_json(['ok' => false, 'error' => 'Falta título'], 400);
    if (mb_strlen($title) > 160) api_json(['ok' => false, 'error' => 'Título muy largo'], 400);

    // unique (owner_user_id, title) lo fuerza el índice; damos error amable
    try {
        q(
            "INSERT INTO collections_v2 (owner_user_id, title, description, is_curated, created_by_user_id)
             VALUES (?, ?, ?, 0, ?)",
            "issi",
            [$uid, $title, $desc, $uid]
        );
    } catch (\Throwable $e) {
        api_json(['ok' => false, 'error' => 'Ya existe una colección con ese nombre'], 409);
    }

    $idRow = q("SELECT LAST_INSERT_ID() AS id");
    $newId = (int)($idRow[0]['id'] ?? 0);

    api_json(['ok' => true, 'id' => $newId, 'title' => $title]);
}

/* Agregar imagen a colección mía */
if ($method === 'POST' && $path === '/collections/add-item') {
    $u = afdc_v2_require_login();
    afdc_v2_csrf_check_from_header();
    $uid = (int)$u['id'];

    $data = api_read_json_body();
    $collectionId = (int)($data['collection_id'] ?? 0);
    $imageKey = (string)($data['image_key'] ?? '');

    if ($collectionId <= 0) api_json(['ok' => false, 'error' => 'collection_id inválido'], 400);
    if (!api_validate_image_key($imageKey)) api_json(['ok' => false, 'error' => 'image_key inválido'], 400);

    api_require_my_collection($uid, $collectionId);

    // position: último + 1 (simple)
    $posRow = q(
        "SELECT COALESCE(MAX(position), 0) AS mx FROM collection_items_v2 WHERE collection_id=?",
        "i",
        [$collectionId]
    );
    $pos = (int)($posRow[0]['mx'] ?? 0) + 1;

    // INSERT IGNORE para no duplicar
    q(
        "INSERT IGNORE INTO collection_items_v2 (collection_id, image_key, position)
         VALUES (?, ?, ?)",
        "isi",
        [$collectionId, $imageKey, $pos]
    );

    api_json(['ok' => true]);
}

/* Quitar imagen de colección mía */
if ($method === 'POST' && $path === '/collections/remove-item') {
    $u = afdc_v2_require_login();
    afdc_v2_csrf_check_from_header();
    $uid = (int)$u['id'];

    $data = api_read_json_body();
    $collectionId = (int)($data['collection_id'] ?? 0);
    $imageKey = (string)($data['image_key'] ?? '');

    if ($collectionId <= 0) api_json(['ok' => false, 'error' => 'collection_id inválido'], 400);
    if (!api_validate_image_key($imageKey)) api_json(['ok' => false, 'error' => 'image_key inválido'], 400);

    api_require_my_collection($uid, $collectionId);

    q(
        "DELETE FROM collection_items_v2 WHERE collection_id=? AND image_key=?",
        "is",
        [$collectionId, $imageKey]
    );

    api_json(['ok' => true]);
}

/* Copiar colección pública curada a mis colecciones */
if ($method === 'POST' && $path === '/collections/copy-public') {
    $u = afdc_v2_require_login();
    afdc_v2_csrf_check_from_header();
    $uid = (int)$u['id'];

    $data = api_read_json_body();
    $publicId = (int)($data['public_collection_id'] ?? 0);
    if ($publicId <= 0) api_json(['ok' => false, 'error' => 'public_collection_id inválido'], 400);

    $pub = api_require_public_curated_collection($publicId);
    $baseTitle = (string)$pub['title'];
    $newTitle = api_make_copy_title_unique($uid, $baseTitle);

    // crear nueva colección
    q(
        "INSERT INTO collections_v2 (owner_user_id, title, description, is_curated, created_by_user_id)
         VALUES (?, ?, ?, 0, ?)",
        "issi",
        [$uid, $newTitle, (string)($pub['description'] ?? ''), $uid]
    );
    $idRow = q("SELECT LAST_INSERT_ID() AS id");
    $newId = (int)($idRow[0]['id'] ?? 0);

    // copiar items preservando position (ignora duplicados)
    q(
        "INSERT IGNORE INTO collection_items_v2 (collection_id, image_key, position)
         SELECT ?, image_key, position
         FROM collection_items_v2
         WHERE collection_id=?
         ORDER BY position ASC",
        "ii",
        [$newId, $publicId]
    );

    api_json(['ok' => true, 'new_collection_id' => $newId, 'new_title' => $newTitle]);
}

/* ---------- fallback ---------- */
api_json(['ok' => false, 'error' => 'Not found', 'path' => $path], 404);
