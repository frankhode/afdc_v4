<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

function cmp_ent_db(): mysqli {
    return cmp_db();
}

function cmp_ent_h(string $value): string {
    return cmp_h($value);
}

function cmp_ent_normalize_name(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];
    $text = strtr($text, $map);

    $text = str_replace(
        ['.', ',', ';', ':', '(', ')', '[', ']', '{', '}', '\'', '"', '´', '`', '“', '”', '‘', '’', '/', '\\', '-', '_', ' '],
        '',
        $text
    );

    $text = preg_replace('/[^a-z0-9]/', '', $text);
    return trim((string)$text);
}

function cmp_ent_get(int $id): ?array {
    $db = cmp_ent_db();
    $stmt = $db->prepare('SELECT * FROM cmp_entidades WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function cmp_ent_get_by_normalized(string $normalized): ?array {
    $normalized = cmp_ent_normalize_name($normalized);
    if ($normalized === '') {
        return null;
    }

    $db = cmp_ent_db();
    $stmt = $db->prepare('SELECT * FROM cmp_entidades WHERE nombre_normalizado = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function cmp_ent_get_by_alias(string $alias): ?array {
    $normalized = cmp_ent_normalize_name($alias);
    if ($normalized === '') {
        return null;
    }

    $db = cmp_ent_db();
    $sql = "SELECT e.*
            FROM cmp_entidades_alias a
            INNER JOIN cmp_entidades e ON e.id = a.entidad_id
            WHERE a.alias_normalizado = ?
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $normalized);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function cmp_ent_resolve_name(string $raw): ?array {
    $normalized = cmp_ent_normalize_name($raw);
    if ($normalized === '') {
        return null;
    }

    $entity = cmp_ent_get_by_alias($normalized);
    if ($entity) {
        return $entity;
    }

    return cmp_ent_get_by_normalized($normalized);
}

function cmp_ent_create(string $nombreOficial, string $nombreMostrable, string $tipo = 'club', array $extra = []): int {
    $nombreOficial = trim($nombreOficial);
    $nombreMostrable = trim($nombreMostrable);

    if ($nombreOficial === '' || $nombreMostrable === '') {
        throw new InvalidArgumentException('Nombre oficial y mostrable son obligatorios.');
    }

    $tipo = trim($tipo);
    if (!in_array($tipo, ['club', 'seleccion', 'combinado'], true)) {
        $tipo = 'club';
    }

    $normalized = cmp_ent_normalize_name($nombreMostrable);
    if ($normalized === '') {
        throw new InvalidArgumentException('No se pudo normalizar la entidad.');
    }

    $existing = cmp_ent_get_by_normalized($normalized);
    if ($existing) {
        return (int)$existing['id'];
    }

    $pais = isset($extra['pais']) ? trim((string)$extra['pais']) : null;
    $ciudad = isset($extra['ciudad']) ? trim((string)$extra['ciudad']) : null;
    $prov = isset($extra['provincia_estado']) ? trim((string)$extra['provincia_estado']) : null;
    $notas = isset($extra['notas']) ? trim((string)$extra['notas']) : null;

    $db = cmp_ent_db();
    $sql = 'INSERT INTO cmp_entidades
            (nombre_oficial, nombre_mostrable, nombre_normalizado, tipo, pais, ciudad, provincia_estado, notas, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'ssssssss',
        $nombreOficial,
        $nombreMostrable,
        $normalized,
        $tipo,
        $pais,
        $ciudad,
        $prov,
        $notas
    );
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    cmp_ent_add_alias($id, $nombreOficial, 'Alias canónico', 'manual');
    if (cmp_ent_normalize_name($nombreMostrable) !== cmp_ent_normalize_name($nombreOficial)) {
        cmp_ent_add_alias($id, $nombreMostrable, 'Alias mostrable', 'manual');
    }

    return $id;
}

function cmp_ent_add_alias(int $entidadId, string $alias, ?string $nota = null, string $origen = 'manual'): int {
    $alias = trim($alias);
    if ($entidadId <= 0 || $alias === '') {
        throw new InvalidArgumentException('Entidad o alias inválido.');
    }

    if (!in_array($origen, ['manual', 'rsssf', 'migracion', 'detected'], true)) {
        $origen = 'manual';
    }

    $aliasNorm = cmp_ent_normalize_name($alias);
    if ($aliasNorm === '') {
        throw new InvalidArgumentException('Alias inválido.');
    }

    $db = cmp_ent_db();

    $check = $db->prepare('SELECT id, entidad_id FROM cmp_entidades_alias WHERE alias_normalizado = ? LIMIT 1');
    if (!$check) {
        throw new RuntimeException($db->error);
    }
    $check->bind_param('s', $aliasNorm);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc() ?: null;
    $check->close();

    if ($existing) {
        return (int)$existing['id'];
    }

    $stmt = $db->prepare('INSERT INTO cmp_entidades_alias (entidad_id, alias, alias_normalizado, notas, origen, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, NOW(), NOW())');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('issss', $entidadId, $alias, $aliasNorm, $nota, $origen);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    return $id;
}

function cmp_ent_list_all(array $filters = []): array {
    $db = cmp_ent_db();

    $where = ['e.is_active = 1'];
    $types = '';
    $params = [];

    if (!empty($filters['tipo'])) {
        $where[] = 'e.tipo = ?';
        $types .= 's';
        $params[] = trim((string)$filters['tipo']);
    }

    if (!empty($filters['q'])) {
        $q = '%' . trim((string)$filters['q']) . '%';
        $where[] = '(e.nombre_mostrable LIKE ? OR e.nombre_oficial LIKE ?)';
        $types .= 'ss';
        $params[] = $q;
        $params[] = $q;
    }

    $sql = 'SELECT e.* FROM cmp_entidades e';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY e.nombre_mostrable ASC, e.id ASC';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_ent_list_used_in_matches(?int $importId = null): array {
    $db = cmp_ent_db();

    $where = ["COALESCE(p.estado,'activo') <> 'ignorado'"];
    $types = '';
    $params = [];

    if ($importId !== null && $importId > 0) {
        $where[] = 'p.importacion_id = ?';
        $types .= 'i';
        $params[] = $importId;
    }

    $sql = "
        SELECT DISTINCT e.id, e.nombre_mostrable, e.nombre_oficial, e.nombre_normalizado, e.tipo
        FROM cmp_entidades e
        INNER JOIN cmp_importacion_partidos p
            ON p.local_entidad_id = e.id OR p.visitante_entidad_id = e.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.nombre_mostrable ASC, e.id ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_ent_match_team_filter(array $match, string $filter): bool {
    $filter = trim($filter);
    if ($filter === '') {
        return true;
    }

    $resolved = cmp_ent_resolve_name($filter);
    $localId = isset($match['local_entidad_id']) ? (int)$match['local_entidad_id'] : 0;
    $visitId = isset($match['visitante_entidad_id']) ? (int)$match['visitante_entidad_id'] : 0;

    if ($resolved) {
        $entityId = (int)$resolved['id'];
        return $localId === $entityId || $visitId === $entityId;
    }

    $f = cmp_ent_normalize_name($filter);

    $localNorm = trim((string)($match['local_normalizado'] ?? ''));
    $visitNorm = trim((string)($match['visitante_normalizado'] ?? ''));

    if ($localNorm === '') {
        $localNorm = cmp_ent_normalize_name((string)($match['local_texto'] ?? ''));
    }
    if ($visitNorm === '') {
        $visitNorm = cmp_ent_normalize_name((string)($match['visitante_texto'] ?? ''));
    }

    return str_contains($localNorm, $f) || str_contains($visitNorm, $f);
}

function cmp_ent_assign_match_entities(int $matchId, ?int $localEntidadId, ?int $visitanteEntidadId, ?string $localNorm = null, ?string $visitanteNorm = null): void {
    if ($matchId <= 0) {
        throw new InvalidArgumentException('Partido inválido.');
    }

    $db = cmp_ent_db();
    $sql = 'UPDATE cmp_importacion_partidos
            SET local_entidad_id = ?,
                visitante_entidad_id = ?,
                local_normalizado = ?,
                visitante_normalizado = ?,
                actualizado_en = NOW()
            WHERE id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('iissi', $localEntidadId, $visitanteEntidadId, $localNorm, $visitanteNorm, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_ent_backfill_match(int $matchId): array {
    $db = cmp_ent_db();
    $stmt = $db->prepare('SELECT id, local_texto, visitante_texto FROM cmp_importacion_partidos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Partido inexistente.');
    }

    $localRaw = trim((string)($row['local_texto'] ?? ''));
    $visitRaw = trim((string)($row['visitante_texto'] ?? ''));

    $localNorm = cmp_ent_normalize_name($localRaw);
    $visitNorm = cmp_ent_normalize_name($visitRaw);

    $localEntity = $localRaw !== '' ? cmp_ent_resolve_name($localRaw) : null;
    $visitEntity = $visitRaw !== '' ? cmp_ent_resolve_name($visitRaw) : null;

    cmp_ent_assign_match_entities(
        (int)$row['id'],
        $localEntity ? (int)$localEntity['id'] : null,
        $visitEntity ? (int)$visitEntity['id'] : null,
        $localNorm !== '' ? $localNorm : null,
        $visitNorm !== '' ? $visitNorm : null
    );

    return [
        'match_id' => (int)$row['id'],
        'local_entidad_id' => $localEntity ? (int)$localEntity['id'] : null,
        'visitante_entidad_id' => $visitEntity ? (int)$visitEntity['id'] : null,
        'local_normalizado' => $localNorm,
        'visitante_normalizado' => $visitNorm,
    ];
}

function cmp_ent_backfill_all_matches(?int $importId = null): array {
    $db = cmp_ent_db();

    $sql = 'SELECT id FROM cmp_importacion_partidos';
    $types = '';
    $params = [];

    if ($importId !== null && $importId > 0) {
        $sql .= ' WHERE importacion_id = ?';
        $types = 'i';
        $params[] = $importId;
    }

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $stats = [
        'total' => 0,
        'local_resueltos' => 0,
        'visitante_resueltos' => 0,
    ];

    while ($row = $res->fetch_assoc()) {
        $stats['total']++;
        $result = cmp_ent_backfill_match((int)$row['id']);
        if (!empty($result['local_entidad_id'])) {
            $stats['local_resueltos']++;
        }
        if (!empty($result['visitante_entidad_id'])) {
            $stats['visitante_resueltos']++;
        }
    }

    $stmt->close();
    return $stats;
}

function cmp_ent_detected_team_texts_for_import(int $importId): array {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT
            equipo_texto,
            SUM(local_count) AS local_count,
            SUM(visitante_count) AS visitante_count,
            SUM(total_count) AS total_count,
            MIN(example_match_id) AS example_match_id,
            MIN(example_line) AS example_line
        FROM (
            SELECT
                TRIM(local_texto) AS equipo_texto,
                COUNT(*) AS local_count,
                0 AS visitante_count,
                COUNT(*) AS total_count,
                MIN(id) AS example_match_id,
                MIN(CONCAT(local_texto, ' vs ', visitante_texto)) AS example_line
            FROM cmp_importacion_partidos
            WHERE importacion_id = ?
              AND COALESCE(estado,'activo') <> 'ignorado'
              AND TRIM(COALESCE(local_texto,'')) <> ''
            GROUP BY TRIM(local_texto)

            UNION ALL

            SELECT
                TRIM(visitante_texto) AS equipo_texto,
                0 AS local_count,
                COUNT(*) AS visitante_count,
                COUNT(*) AS total_count,
                MIN(id) AS example_match_id,
                MIN(CONCAT(local_texto, ' vs ', visitante_texto)) AS example_line
            FROM cmp_importacion_partidos
            WHERE importacion_id = ?
              AND COALESCE(estado,'activo') <> 'ignorado'
              AND TRIM(COALESCE(visitante_texto,'')) <> ''
            GROUP BY TRIM(visitante_texto)
        ) x
        GROUP BY equipo_texto
        ORDER BY total_count DESC, equipo_texto ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ii', $importId, $importId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];

    while ($row = $res->fetch_assoc()) {
        $raw = trim((string)($row['equipo_texto'] ?? ''));
        if ($raw === '') {
            continue;
        }

        $normalized = cmp_ent_normalize_name($raw);
        $entity = cmp_ent_resolve_name($raw);

        $rows[] = [
            'equipo_texto' => $raw,
            'normalizado' => $normalized,
            'local_count' => (int)($row['local_count'] ?? 0),
            'visitante_count' => (int)($row['visitante_count'] ?? 0),
            'total_count' => (int)($row['total_count'] ?? 0),
            'example_match_id' => (int)($row['example_match_id'] ?? 0),
            'example_line' => (string)($row['example_line'] ?? ''),
            'entidad_id' => $entity ? (int)$entity['id'] : null,
            'entidad_nombre' => $entity ? (string)$entity['nombre_mostrable'] : '',
            'estado' => $entity ? 'resuelto' : 'pendiente',
        ];
    }

    $stmt->close();

    return $rows;
}

function cmp_ent_get_import_entity_stats(int $importId): array {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN local_entidad_id IS NOT NULL THEN 1 ELSE 0 END) AS local_resueltos,
            SUM(CASE WHEN visitante_entidad_id IS NOT NULL THEN 1 ELSE 0 END) AS visitante_resueltos,
            SUM(CASE WHEN TRIM(COALESCE(local_normalizado,'')) <> '' THEN 1 ELSE 0 END) AS local_normalizados,
            SUM(CASE WHEN TRIM(COALESCE(visitante_normalizado,'')) <> '' THEN 1 ELSE 0 END) AS visitante_normalizados
        FROM cmp_importacion_partidos
        WHERE importacion_id = ?
          AND COALESCE(estado,'activo') <> 'ignorado'
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'total' => (int)($row['total'] ?? 0),
        'local_resueltos' => (int)($row['local_resueltos'] ?? 0),
        'visitante_resueltos' => (int)($row['visitante_resueltos'] ?? 0),
        'local_normalizados' => (int)($row['local_normalizados'] ?? 0),
        'visitante_normalizados' => (int)($row['visitante_normalizados'] ?? 0),
    ];
}

function cmp_ent_alias_exists_for_entity(int $entidadId, string $alias): bool {
    if ($entidadId <= 0) {
        return false;
    }

    $aliasNorm = cmp_ent_normalize_name($alias);
    if ($aliasNorm === '') {
        return false;
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT id
        FROM cmp_entidades_alias
        WHERE entidad_id = ?
          AND alias_normalizado = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('is', $entidadId, $aliasNorm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (bool)$row;
}

function cmp_ent_search_entities(string $q, int $limit = 12): array {
    $q = trim($q);
    if ($q === '') {
        return [];
    }

    $limit = max(1, min(30, $limit));

    $db = cmp_ent_db();

    $norm = cmp_ent_normalize_name($q);
    $likeText = '%' . $q . '%';
    $likeNorm = '%' . $norm . '%';

    $sql = "
        SELECT
            e.id,
            e.nombre_mostrable,
            e.nombre_oficial,
            e.nombre_normalizado,
            e.tipo,
            MIN(a.alias) AS alias_match
        FROM cmp_entidades e
        LEFT JOIN cmp_entidades_alias a ON a.entidad_id = e.id
        WHERE e.is_active = 1
          AND (
                e.nombre_mostrable LIKE ?
             OR e.nombre_oficial LIKE ?
             OR e.nombre_normalizado LIKE ?
             OR a.alias LIKE ?
             OR a.alias_normalizado LIKE ?
          )
        GROUP BY e.id, e.nombre_mostrable, e.nombre_oficial, e.nombre_normalizado, e.tipo
        ORDER BY
            CASE
                WHEN e.nombre_normalizado = ? THEN 0
                WHEN MIN(a.alias_normalizado) = ? THEN 1
                WHEN e.nombre_normalizado LIKE ? THEN 2
                ELSE 3
            END,
            e.nombre_mostrable ASC,
            e.id ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'ssssssssi',
        $likeText,
        $likeText,
        $likeNorm,
        $likeText,
        $likeNorm,
        $norm,
        $norm,
        $likeNorm,
        $limit
    );

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_ent_get_alias_by_normalized(string $alias): ?array {
    $aliasNorm = cmp_ent_normalize_name($alias);
    if ($aliasNorm === '') {
        return null;
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT a.*, e.nombre_mostrable
        FROM cmp_entidades_alias a
        INNER JOIN cmp_entidades e ON e.id = a.entidad_id
        WHERE a.alias_normalizado = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $aliasNorm);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function cmp_ent_list_admin(array $filters = []): array {
    $db = cmp_ent_db();

    $q = trim((string)($filters['q'] ?? ''));
    $tipo = trim((string)($filters['tipo'] ?? ''));
    $estado = trim((string)($filters['estado'] ?? 'activos'));

    $where = [];
    $types = '';
    $params = [];

    if ($estado === 'activos') {
        $where[] = 'e.is_active = 1';
    } elseif ($estado === 'inactivos') {
        $where[] = 'e.is_active = 0';
    }

    if ($tipo !== '' && in_array($tipo, ['club', 'seleccion', 'combinado'], true)) {
        $where[] = 'e.tipo = ?';
        $types .= 's';
        $params[] = $tipo;
    }

    if ($q !== '') {
        $qNorm = cmp_ent_normalize_name($q);
        $likeText = '%' . $q . '%';
        $likeNorm = '%' . $qNorm . '%';

        $where[] = "(
            e.nombre_mostrable LIKE ?
            OR e.nombre_oficial LIKE ?
            OR e.nombre_normalizado LIKE ?
            OR EXISTS (
                SELECT 1
                FROM cmp_entidades_alias ax
                WHERE ax.entidad_id = e.id
                  AND (
                        ax.alias LIKE ?
                     OR ax.alias_normalizado LIKE ?
                  )
            )
        )";

        $types .= 'sssss';
        $params[] = $likeText;
        $params[] = $likeText;
        $params[] = $likeNorm;
        $params[] = $likeText;
        $params[] = $likeNorm;
    }

    $sql = "
        SELECT
            e.*,
            (
                SELECT COUNT(*)
                FROM cmp_entidades_alias a
                WHERE a.entidad_id = e.id
            ) AS alias_count,
            (
                SELECT COUNT(*)
                FROM cmp_importacion_partidos p
                WHERE COALESCE(p.estado,'activo') <> 'ignorado'
                  AND (
                        p.local_entidad_id = e.id
                     OR p.visitante_entidad_id = e.id
                  )
            ) AS partidos_count
        FROM cmp_entidades e
    ";

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY e.nombre_mostrable ASC, e.id ASC';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_ent_list_aliases(int $entidadId): array {
    if ($entidadId <= 0) {
        return [];
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT *
        FROM cmp_entidades_alias
        WHERE entidad_id = ?
        ORDER BY alias ASC, id ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $entidadId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_ent_usage_stats(int $entidadId): array {
    if ($entidadId <= 0) {
        throw new InvalidArgumentException('Entidad inválida.');
    }

    $db = cmp_ent_db();

    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN local_entidad_id = ? THEN 1 ELSE 0 END) AS local_count,
            SUM(CASE WHEN visitante_entidad_id = ? THEN 1 ELSE 0 END) AS visitante_count
        FROM cmp_importacion_partidos
        WHERE COALESCE(estado,'activo') <> 'ignorado'
          AND (
                local_entidad_id = ?
             OR visitante_entidad_id = ?
          )
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('iiii', $entidadId, $entidadId, $entidadId, $entidadId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'total' => (int)($row['total'] ?? 0),
        'local_count' => (int)($row['local_count'] ?? 0),
        'visitante_count' => (int)($row['visitante_count'] ?? 0),
    ];
}

function cmp_ent_update_entity(
    int $entidadId,
    string $nombreOficial,
    string $nombreMostrable,
    string $tipo,
    ?string $pais,
    ?string $ciudad,
    ?string $provinciaEstado,
    ?string $notas,
    int $isActive
): void {
    if ($entidadId <= 0) {
        throw new InvalidArgumentException('Entidad inválida.');
    }

    $nombreOficial = trim($nombreOficial);
    $nombreMostrable = trim($nombreMostrable);

    if ($nombreOficial === '' || $nombreMostrable === '') {
        throw new InvalidArgumentException('Nombre oficial y nombre mostrable son obligatorios.');
    }

    if (!in_array($tipo, ['club', 'seleccion', 'combinado'], true)) {
        $tipo = 'club';
    }

    $normalizado = cmp_ent_normalize_name($nombreMostrable);
    if ($normalizado === '') {
        throw new InvalidArgumentException('No se pudo normalizar el nombre mostrable.');
    }

    $pais = $pais !== null ? trim($pais) : null;
    $ciudad = $ciudad !== null ? trim($ciudad) : null;
    $provinciaEstado = $provinciaEstado !== null ? trim($provinciaEstado) : null;
    $notas = $notas !== null ? trim($notas) : null;

    $db = cmp_ent_db();

    $sql = "
        UPDATE cmp_entidades
        SET nombre_oficial = ?,
            nombre_mostrable = ?,
            nombre_normalizado = ?,
            tipo = ?,
            pais = ?,
            ciudad = ?,
            provincia_estado = ?,
            notas = ?,
            is_active = ?,
            updated_at = NOW()
        WHERE id = ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'ssssssssii',
        $nombreOficial,
        $nombreMostrable,
        $normalizado,
        $tipo,
        $pais,
        $ciudad,
        $provinciaEstado,
        $notas,
        $isActive,
        $entidadId
    );

    $stmt->execute();
    $stmt->close();
}

function cmp_ent_delete_alias(int $aliasId): void {
    if ($aliasId <= 0) {
        throw new InvalidArgumentException('Alias inválido.');
    }

    $db = cmp_ent_db();

    $stmt = $db->prepare('DELETE FROM cmp_entidades_alias WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $aliasId);
    $stmt->execute();
    $stmt->close();
}

function cmp_ent_get_alias(int $aliasId): ?array {
    if ($aliasId <= 0) {
        return null;
    }

    $db = cmp_ent_db();

    $stmt = $db->prepare('SELECT * FROM cmp_entidades_alias WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $aliasId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function cmp_ent_set_active(int $entidadId, int $isActive): void {
    if ($entidadId <= 0) {
        throw new InvalidArgumentException('Entidad inválida.');
    }

    $db = cmp_ent_db();

    $stmt = $db->prepare('UPDATE cmp_entidades SET is_active = ?, updated_at = NOW() WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ii', $isActive, $entidadId);
    $stmt->execute();
    $stmt->close();
}