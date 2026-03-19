<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
require_once __DIR__ . '/campeonatos_import_repo.php';

function cmp_edit_get_import(int $importId): ?array {
    return cmp_import_get($importId);
}

function cmp_edit_get_nodes_flat(int $importId): array {
    $db = cmp_db();
    $sql = 'SELECT * FROM cmp_importacion_nodos WHERE importacion_id = ? AND COALESCE(is_deleted,0)=0 ORDER BY nivel ASC, orden ASC, id ASC';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cmp_edit_get_matches_flat(int $importId): array {
    $db = cmp_db();
    $sql = 'SELECT p.*, n.label AS nodo_label, n.tipo AS nodo_tipo, n.subtipo AS nodo_subtipo
            FROM cmp_importacion_partidos p
            INNER JOIN cmp_importacion_nodos n ON n.id = p.nodo_id
            WHERE p.importacion_id = ?
            ORDER BY p.nodo_id ASC, p.orden ASC, p.id ASC';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cmp_edit_build_tree(array $nodes, array $matches): array {
    $directMatchCounts = [];
    foreach ($matches as $match) {
        if (($match['estado'] ?? 'activo') === 'ignorado') {
            continue;
        }
        $nodeId = (int)$match['nodo_id'];
        $directMatchCounts[$nodeId] = ($directMatchCounts[$nodeId] ?? 0) + 1;
    }

    $byId = [];
    foreach ($nodes as $node) {
        $id = (int)$node['id'];
        $node['children'] = [];
        $node['match_count_direct'] = $directMatchCounts[$id] ?? 0;
        $node['match_count_total'] = 0;
        $byId[$id] = $node;
    }

    foreach ($byId as $id => &$node) {
        $parentId = $node['parent_id'] !== null ? (int)$node['parent_id'] : null;
        if ($parentId !== null && isset($byId[$parentId])) {
            $byId[$parentId]['children'][] = &$node;
        }
    }
    unset($node);

    $roots = [];
    foreach ($byId as $id => &$node) {
        $parentId = $node['parent_id'] !== null ? (int)$node['parent_id'] : null;
        if ($parentId === null || !isset($byId[$parentId])) {
            $roots[] = &$node;
        }
    }
    unset($node);

    foreach ($roots as &$root) {
        cmp_edit_compute_match_totals($root);
    }
    unset($root);

    return $roots;
}

function cmp_edit_compute_match_totals(array &$node): int {
    $total = (int)($node['match_count_direct'] ?? 0);
    foreach (($node['children'] ?? []) as &$child) {
        $total += cmp_edit_compute_match_totals($child);
    }
    unset($child);
    $node['match_count_total'] = $total;
    return $total;
}

function cmp_edit_find_node_in_tree(array $tree, int $nodeId): ?array {
    foreach ($tree as $node) {
        if ((int)$node['id'] === $nodeId) {
            return $node;
        }
        $found = cmp_edit_find_node_in_tree($node['children'] ?? [], $nodeId);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function cmp_edit_first_real_node(array $tree): ?array {
    foreach ($tree as $node) {
        if (($node['tipo'] ?? '') !== 'temporada') {
            return $node;
        }
        $found = cmp_edit_first_real_node($node['children'] ?? []);
        if ($found !== null) {
            return $found;
        }
    }
    return $tree[0] ?? null;
}

function cmp_edit_build_breadcrumbs(array $nodesFlat, int $nodeId): array {
    $map = [];
    foreach ($nodesFlat as $row) {
        $map[(int)$row['id']] = $row;
    }

    $trail = [];
    $current = $nodeId;
    while (isset($map[$current])) {
        $trail[] = $map[$current];
        $parentId = $map[$current]['parent_id'];
        if ($parentId === null) {
            break;
        }
        $current = (int)$parentId;
    }
    return array_reverse($trail);
}

function cmp_edit_get_child_nodes(array $treeNode): array {
    return $treeNode['children'] ?? [];
}

function cmp_edit_collect_descendant_ids(array $node): array {
    $ids = [(int)$node['id']];
    foreach (($node['children'] ?? []) as $child) {
        $ids = array_merge($ids, cmp_edit_collect_descendant_ids($child));
    }
    return $ids;
}

function cmp_edit_get_matches_for_node(array $node, array $matchesFlat, bool $includeDescendants = false): array {
    $ids = $includeDescendants ? array_fill_keys(cmp_edit_collect_descendant_ids($node), true) : [(int)$node['id'] => true];
    $rows = [];
    foreach ($matchesFlat as $match) {
        if (!isset($ids[(int)$match['nodo_id']])) {
            continue;
        }
        $rows[] = $match;
    }
    return $rows;
}

function cmp_edit_list_destination_dates(array $tree, int $excludeNodeId = 0): array {
    $destinations = [];
    cmp_edit_collect_destination_dates($tree, [], $destinations, $excludeNodeId);
    return $destinations;
}

function cmp_edit_collect_destination_dates(array $nodes, array $trail, array &$destinations, int $excludeNodeId): void {
    foreach ($nodes as $node) {
        $currentTrail = array_merge($trail, [$node['label']]);
        if (($node['tipo'] ?? '') === 'fecha' && (int)$node['id'] !== $excludeNodeId && ((int)($node['is_deleted'] ?? 0) === 0)) {
            $destinations[] = [
                'id' => (int)$node['id'],
                'label' => implode(' > ', $currentTrail),
                'subtipo' => (string)($node['subtipo'] ?? ''),
            ];
        }
        cmp_edit_collect_destination_dates($node['children'] ?? [], $currentTrail, $destinations, $excludeNodeId);
    }
}

function cmp_edit_get_node_row(int $nodeId): ?array {
    $db = cmp_db();
    $sql = 'SELECT * FROM cmp_importacion_nodos WHERE id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $nodeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function cmp_edit_get_goal_events_for_match(int $matchId): array {
    $db = cmp_db();

    $sql = 'SELECT orden, team_side, team_name, jugador_raw, jugador_normalizado, minuto, goal_type, raw_fragment
            FROM cmp_importacion_goles
            WHERE partido_id = ?
            ORDER BY orden ASC, id ASC';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $events = [];
    foreach ($rows as $row) {
        $events[] = [
            'order' => isset($row['orden']) ? (int)$row['orden'] : 0,
            'player_raw' => trim((string)($row['jugador_raw'] ?? '')),
            'player_normalized' => $row['jugador_normalizado'] ?? null,
            'minute' => ($row['minuto'] !== null && $row['minuto'] !== '') ? (int)$row['minuto'] : null,
            'team_side' => cmp_edit_normalize_goal_side((string)($row['team_side'] ?? 'desconocido')),
            'team_name' => $row['team_name'] ?? null,
            'goal_type' => $row['goal_type'] ?? 'normal',
            'raw_fragment' => $row['raw_fragment'] ?? null,
        ];
    }

    return $events;
}

function cmp_edit_sync_match_goal_events_meta(int $matchId, array $events): void {
    $db = cmp_db();

    $stmt = $db->prepare('SELECT meta_json FROM cmp_importacion_partidos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();

    $meta = [];
    if ($row && !empty($row['meta_json'])) {
        $tmp = json_decode((string)$row['meta_json'], true);
        if (is_array($tmp)) {
            $meta = $tmp;
        }
    }

    $meta['goal_events'] = array_values($events);
    $meta['goal_events_count'] = count($events);

    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        throw new RuntimeException('No se pudo serializar meta_json del partido.');
    }

    $stmt = $db->prepare('UPDATE cmp_importacion_partidos
                          SET meta_json = ?, is_manual_edit = 1, actualizado_en = NOW()
                          WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('si', $metaJson, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_get_match_row(int $matchId): ?array {
    $db = cmp_db();
    $sql = 'SELECT p.*, n.label AS nodo_label, n.tipo AS nodo_tipo FROM cmp_importacion_partidos p INNER JOIN cmp_importacion_nodos n ON n.id = p.nodo_id WHERE p.id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function cmp_edit_normalize_goal_side(string $side): string {
    $side = strtolower(trim($side));

    return match ($side) {
        'local', 'home' => 'local',
        'visitante', 'away', 'visitor' => 'visitante',
        default => 'desconocido',
    };
}

function cmp_edit_decode_goal_events(array $matchRow): array {
    $raw = $matchRow['goal_events'] ?? null;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $events = [];
    foreach ($decoded as $event) {
        if (!is_array($event)) {
            continue;
        }

        $player = trim((string)($event['player_raw'] ?? $event['player'] ?? ''));
        $minuteRaw = $event['minute'] ?? null;
        $minute = null;

        if ($minuteRaw !== null && $minuteRaw !== '') {
            if (is_numeric((string)$minuteRaw)) {
                $minute = (int)$minuteRaw;
            }
        }

        $teamSide = cmp_edit_normalize_goal_side((string)($event['team_side'] ?? $event['side'] ?? 'desconocido'));

        if ($player === '' && $minute === null && $teamSide === 'desconocido') {
            continue;
        }

        $events[] = [
            'player_raw' => $player,
            'minute' => $minute,
            'team_side' => $teamSide,
        ];
    }

    return $events;
}

function cmp_edit_normalize_goal_events(array $players, array $minutes, array $sides): array {
    $max = max(count($players), count($minutes), count($sides));
    $events = [];

    for ($i = 0; $i < $max; $i++) {
        $player = trim((string)($players[$i] ?? ''));
        $minuteRaw = trim((string)($minutes[$i] ?? ''));
        $teamSide = cmp_edit_normalize_goal_side((string)($sides[$i] ?? 'desconocido'));

        $minute = null;
        if ($minuteRaw !== '') {
            if (is_numeric($minuteRaw)) {
                $minute = (int)$minuteRaw;
            }
        }

        if ($player === '' && $minute === null && $teamSide === 'desconocido') {
            continue;
        }

        $events[] = [
            'player_raw' => $player,
            'minute' => $minute,
            'team_side' => $teamSide,
        ];
    }

    return $events;
}

function cmp_edit_goal_event_counts(array $events): array {
    $counts = [
        'local' => 0,
        'visitante' => 0,
        'desconocido' => 0,
        'total' => 0,
    ];

    foreach ($events as $event) {
        $side = cmp_edit_normalize_goal_side((string)($event['team_side'] ?? 'desconocido'));
        if (!isset($counts[$side])) {
            $side = 'desconocido';
        }
        $counts[$side]++;
        $counts['total']++;
    }

    return $counts;
}

function cmp_edit_update_goal_events(int $matchId, array $events): void {
    $db = cmp_db();

    $json = json_encode(
        array_values($events),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('No se pudieron serializar los goleadores.');
    }

    $sql = 'UPDATE cmp_importacion_partidos
            SET goal_events = ?, is_manual_edit = 1, actualizado_en = NOW()
            WHERE id = ?';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('si', $json, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_create_node(int $importId, int $parentNodeId, string $type, ?string $subtype, string $label, ?int $sortOrder = null): int {
    $parent = cmp_edit_get_node_row($parentNodeId);
    if (!$parent || (int)$parent['importacion_id'] !== $importId) {
        throw new InvalidArgumentException('Nodo padre inválido.');
    }

    $type = trim($type);
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('El label no puede estar vacío.');
    }

    $allowed = cmp_edit_allowed_child_types((string)$parent['tipo']);
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Ese tipo de nodo no está permitido en este contexto.');
    }

    $db = cmp_db();
    if ($sortOrder === null) {
        $sortOrder = cmp_edit_next_child_order($parentNodeId);
    }

    $level = ((int)$parent['nivel']) + 1;
    $metaJson = json_encode(['created_manually' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sql = 'INSERT INTO cmp_importacion_nodos (importacion_id, parent_id, tipo, subtipo, label, orden, nivel, texto_original, meta_json, is_manual, is_deleted, creado_en, actualizado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, 1, 0, NOW(), NOW())';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('iisssiis', $importId, $parentNodeId, $type, $subtype, $label, $sortOrder, $level, $metaJson);
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();
    return $newId;
}

function cmp_edit_allowed_child_types(string $parentType): array {
    return match ($parentType) {
        'ronda' => ['serie', 'fecha', 'nota'],
        'serie' => ['fecha', 'nota'],
        'fase', 'grupo' => ['fecha', 'nota'],
        'fecha' => [],
        default => ['nota'],
    };
}

function cmp_edit_next_child_order(int $parentNodeId): int {
    $db = cmp_db();
    $sql = 'SELECT COALESCE(MAX(orden), 0) + 1 AS next_order FROM cmp_importacion_nodos WHERE parent_id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $parentNodeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: ['next_order' => 1];
    $stmt->close();
    return (int)$row['next_order'];
}

function cmp_edit_update_node(int $nodeId, string $label, ?string $subtype, ?int $sortOrder = null): void {
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('El label no puede estar vacío.');
    }
    $db = cmp_db();
    if ($sortOrder === null) {
        $sql = 'UPDATE cmp_importacion_nodos SET label = ?, subtipo = ?, is_manual = 1, actualizado_en = NOW() WHERE id = ?';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('ssi', $label, $subtype, $nodeId);
    } else {
        $sql = 'UPDATE cmp_importacion_nodos SET label = ?, subtipo = ?, orden = ?, is_manual = 1, actualizado_en = NOW() WHERE id = ?';
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('ssii', $label, $subtype, $sortOrder, $nodeId);
    }
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_delete_empty_node(int $nodeId): void {
    $db = cmp_db();

    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM cmp_importacion_nodos WHERE parent_id = ? AND COALESCE(is_deleted,0)=0');
    $stmt->bind_param('i', $nodeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        throw new RuntimeException('El nodo tiene hijos y no puede eliminarse.');
    }

    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM cmp_importacion_partidos WHERE nodo_id = ? AND estado <> "ignorado"');
    $stmt->bind_param('i', $nodeId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    if ((int)($row['c'] ?? 0) > 0) {
        throw new RuntimeException('El nodo tiene partidos activos y no puede eliminarse.');
    }

    $stmt = $db->prepare('UPDATE cmp_importacion_nodos SET is_deleted = 1, actualizado_en = NOW() WHERE id = ?');
    $stmt->bind_param('i', $nodeId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_update_match(int $matchId, string $home, ?int $gl, ?int $gv, string $away, ?string $obs): void {
    $home = trim($home);
    $away = trim($away);
    if ($home === '' || $away === '') {
        throw new InvalidArgumentException('Local y visitante son obligatorios.');
    }
    $db = cmp_db();
    $sql = 'UPDATE cmp_importacion_partidos
            SET local_texto = ?, goles_local = ?, goles_visitante = ?, visitante_texto = ?, observacion_manual = ?, is_manual_edit = 1, actualizado_en = NOW()
            WHERE id = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('siissi', $home, $gl, $gv, $away, $obs, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_move_match(int $matchId, int $targetNodeId): void {
    $target = cmp_edit_get_node_row($targetNodeId);
    if (!$target || (string)$target['tipo'] !== 'fecha' || (int)($target['is_deleted'] ?? 0) === 1) {
        throw new InvalidArgumentException('El destino debe ser una fecha activa.');
    }

    $db = cmp_db();
    $stmt = $db->prepare('UPDATE cmp_importacion_partidos SET nodo_id = ?, is_manual_edit = 1, actualizado_en = NOW() WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('ii', $targetNodeId, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_set_match_state(int $matchId, string $state): void {
    if (!in_array($state, ['activo', 'ignorado'], true)) {
        throw new InvalidArgumentException('Estado de partido inválido.');
    }
    $db = cmp_db();
    $stmt = $db->prepare('UPDATE cmp_importacion_partidos SET estado = ?, actualizado_en = NOW() WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('si', $state, $matchId);
    $stmt->execute();
    $stmt->close();
}
