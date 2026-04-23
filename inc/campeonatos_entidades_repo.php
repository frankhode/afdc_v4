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