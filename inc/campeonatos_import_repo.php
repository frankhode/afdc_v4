<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';

function cmp_import_create(array $parsed, string $sourceType, ?string $sourceUrl, string $sourceValue): int {
    $db = cmp_db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare('INSERT INTO cmp_importaciones (fuente_tipo, fuente_url, titulo_fuente, temporada_detectada, estado, texto_crudo, tree_json, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $estado = 'parseado';
        $season = $parsed['season'];
        $treeJson = json_encode($parsed['tree'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->bind_param('sssisss', $sourceType, $sourceUrl, $parsed['title'], $season, $estado, $sourceValue, $treeJson);
        $stmt->execute();
        $importId = (int)$stmt->insert_id;
        $stmt->close();

        $order = 1;
        cmp_import_store_node($db, $importId, null, $parsed['tree'], 0, $order);

        $db->commit();
        return $importId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function cmp_import_store_node(mysqli $db, int $importId, ?int $parentId, array $node, int $level, int &$orderCounter): int {
    $stmt = $db->prepare('
        INSERT INTO cmp_importacion_nodos
        (importacion_id, parent_id, tipo, subtipo, label, orden, nivel, texto_original, meta_json, creado_en, actualizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $tipo = (string)($node['type'] ?? 'nota');
    $subtipo = $node['subtype'] ?? null;
    $label = (string)($node['label'] ?? 'Nodo');
    $textOriginal = $node['text_original'] ?? null;
    $orden = $orderCounter++;

    $meta = $node['meta'] ?? [];
    if (!is_array($meta)) {
        $meta = [];
    }

    $extraKeys = [
        'home_team_raw',
        'home_team_normalized',
        'home_team_canonical',
        'home_team_match_status',
        'away_team_raw',
        'away_team_normalized',
        'away_team_canonical',
        'away_team_match_status',
        'goal_text_raw',
        'goal_events',
        'goal_events_count',
        'import_warnings',
    ];

    foreach ($extraKeys as $k) {
        if (array_key_exists($k, $node)) {
            $meta[$k] = $node[$k];
        }
    }

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt->bind_param('iisssiiss', $importId, $parentId, $tipo, $subtipo, $label, $orden, $level, $textOriginal, $metaJson);
    $stmt->execute();
    $nodeId = (int)$stmt->insert_id;
    $stmt->close();

    foreach (($node['matches'] ?? []) as $match) {
        cmp_import_store_match($db, $importId, $nodeId, $match);
    }
    foreach (($node['children'] ?? []) as $child) {
        cmp_import_store_node($db, $importId, $nodeId, $child, $level + 1, $orderCounter);
    }

    return $nodeId;
}

function cmp_import_store_match(mysqli $db, int $importId, int $nodeId, array $match): void {
    $stmt = $db->prepare('INSERT INTO cmp_importacion_partidos (importacion_id, nodo_id, orden, local_texto, visitante_texto, goles_local, goles_visitante, fuente_linea, meta_json, creado_en, actualizado_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $meta = [
        'home_team_raw' => $match['home_team_raw'] ?? null,
        'home_team_normalized' => $match['home_team_normalized'] ?? null,
        'home_team_canonical' => $match['home_team_canonical'] ?? null,
        'home_team_match_status' => $match['home_team_match_status'] ?? null,

        'away_team_raw' => $match['away_team_raw'] ?? null,
        'away_team_normalized' => $match['away_team_normalized'] ?? null,
        'away_team_canonical' => $match['away_team_canonical'] ?? null,
        'away_team_match_status' => $match['away_team_match_status'] ?? null,

        'goal_text_raw' => $match['goal_text_raw'] ?? null,
        'goal_events' => $match['goal_events'] ?? null,
        'goal_events_count' => $match['goal_events_count'] ?? null,
        'import_warnings' => $match['import_warnings'] ?? null,

        'scorers_home_raw' => $match['scorers_home_raw'] ?? null,
        'scorers_away_raw' => $match['scorers_away_raw'] ?? null,
    ];

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt->bind_param(
        'iiissiiss',
        $importId,
        $nodeId,
        $match['order'],
        $match['home'],
        $match['away'],
        $match['home_goals'],
        $match['away_goals'],
        $match['source_line'],
        $metaJson
    );
    $stmt->execute();
    $partidoId = (int)$stmt->insert_id;
    $stmt->close();

    cmp_import_store_goal_events($db, $importId, $partidoId, $match['goal_events'] ?? []);
}

function cmp_import_store_goal_events(mysqli $db, int $importId, int $partidoId, array $events): void {
    if (!$events) {
        return;
    }

    $stmt = $db->prepare('
        INSERT INTO cmp_importacion_goles
        (importacion_id, partido_id, orden, team_side, team_name, jugador_raw, jugador_normalizado, minuto, goal_type, raw_fragment, creado_en, actualizado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    foreach ($events as $ev) {
        if (!is_array($ev)) {
            continue;
        }

        $orden = (int)($ev['order'] ?? 0);
        $teamSide = (string)($ev['team_side'] ?? 'desconocido');
        $teamName = isset($ev['team_name']) ? (string)$ev['team_name'] : null;
        $jugadorRaw = trim((string)($ev['player_raw'] ?? ''));
        $jugadorNorm = isset($ev['player_normalized']) ? (string)$ev['player_normalized'] : null;
        $minuto = isset($ev['minute']) && $ev['minute'] !== '' ? (int)$ev['minute'] : null;
        $goalType = (string)($ev['goal_type'] ?? 'normal');
        $rawFragment = isset($ev['raw_fragment']) ? (string)$ev['raw_fragment'] : null;

        if ($jugadorRaw === '') {
            continue;
        }

        $stmt->bind_param(
            'iiissssiss',
            $importId,
            $partidoId,
            $orden,
            $teamSide,
            $teamName,
            $jugadorRaw,
            $jugadorNorm,
            $minuto,
            $goalType,
            $rawFragment
        );
        $stmt->execute();
    }

    $stmt->close();
}

function cmp_import_list(): array {
    $db = cmp_db();
    $sql = 'SELECT i.*, (SELECT COUNT(*) FROM cmp_importacion_nodos n WHERE n.importacion_id = i.id) AS nodos_count, (SELECT COUNT(*) FROM cmp_importacion_partidos p WHERE p.importacion_id = i.id) AS partidos_count FROM cmp_importaciones i ORDER BY i.id DESC';
    $res = $db->query($sql);
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function cmp_import_get(int $id): ?array {
    $db = cmp_db();
    $stmt = $db->prepare('SELECT * FROM cmp_importaciones WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function cmp_import_get_nodes(int $importId): array {
    $db = cmp_db();
    $stmt = $db->prepare('SELECT * FROM cmp_importacion_nodos WHERE importacion_id = ? ORDER BY orden ASC, id ASC');
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        if (!empty($row['meta_json'])) {
            $meta = json_decode((string)$row['meta_json'], true);
            if (is_array($meta)) {
                $row = array_merge($row, $meta);
            }
        }
    }
    unset($row);

    return $rows;
}

function cmp_import_get_matches(int $importId): array {
    $db = cmp_db();

    $sql = '
        SELECT
            p.*,
            n.label AS nodo_label,
            n.tipo AS nodo_tipo,
            n.subtipo AS nodo_subtipo,
            n.meta_json AS nodo_meta_json
        FROM cmp_importacion_partidos p
        INNER JOIN cmp_importacion_nodos n ON n.id = p.nodo_id
        WHERE p.importacion_id = ?
        ORDER BY p.id ASC
    ';

    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $metaPartido = [];
        if (!empty($row['meta_json'])) {
            $tmp = json_decode((string)$row['meta_json'], true);
            if (is_array($tmp)) {
                $metaPartido = $tmp;
            }
        }

        $metaNodo = [];
        if (!empty($row['nodo_meta_json'])) {
            $tmp = json_decode((string)$row['nodo_meta_json'], true);
            if (is_array($tmp)) {
                $metaNodo = $tmp;
            }
        }

        // primero merge del partido
        if ($metaPartido) {
            $row = array_merge($row, $metaPartido);
        }

        // fallback desde nodo si el partido no trae esos datos
        $fallbackKeys = [
            'home_team_raw',
            'home_team_normalized',
            'home_team_canonical',
            'home_team_match_status',
            'away_team_raw',
            'away_team_normalized',
            'away_team_canonical',
            'away_team_match_status',
            'goal_text_raw',
            'goal_events',
            'goal_events_count',
            'import_warnings',
        ];

        foreach ($fallbackKeys as $k) {
            if (
                (!isset($row[$k]) || $row[$k] === null || $row[$k] === '' || $row[$k] === [])
                && array_key_exists($k, $metaNodo)
            ) {
                $row[$k] = $metaNodo[$k];
            }
        }
    }
    unset($row);

    return $rows;
}

function cmp_import_build_tree(array $nodes): array {
    $byId = [];
    foreach ($nodes as $node) {
        $node['children'] = [];
        $byId[(int)$node['id']] = $node;
    }
    $root = [];
    foreach ($byId as $id => &$node) {
        $parentId = $node['parent_id'] !== null ? (int)$node['parent_id'] : null;
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$node;
        } else {
            $root[] = &$node;
        }
    }
    unset($node);
    return $root;
}


function cmp_import_get_goal_events(int $importId): array {
    $db = cmp_db();
    $sql = '
        SELECT g.*
        FROM cmp_importacion_goles g
        WHERE g.importacion_id = ?
        ORDER BY g.partido_id ASC, g.orden ASC, g.id ASC
    ';
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}