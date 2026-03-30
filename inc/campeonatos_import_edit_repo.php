<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
require_once __DIR__ . '/campeonatos_import_repo.php';

cmp_require_bootstrap_if_available();

function cmp_edit_get_import(int $importId): ?array {
    return cmp_import_get($importId);
}

function cmp_edit_get_nodes_flat(int $importId): array {
    $db = cmp_edit_db();
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
    $db = cmp_edit_db();
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
    $db = cmp_edit_db();
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

function cmp_edit_get_match_row(int $matchId): ?array {
    $db = cmp_edit_db();
    $sql = 'SELECT p.*, n.label AS nodo_label, n.tipo AS nodo_tipo
            FROM cmp_importacion_partidos p
            INNER JOIN cmp_importacion_nodos n ON n.id = p.nodo_id
            WHERE p.id = ? LIMIT 1';
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

function cmp_edit_get_goal_events_for_match(int $matchId): array {
    $db = cmp_edit_db();

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

function cmp_edit_decode_goal_events(array $matchRow): array {
    $matchId = (int)($matchRow['id'] ?? 0);
    if ($matchId > 0) {
        $events = cmp_edit_get_goal_events_for_match($matchId);
        if ($events !== []) {
            return $events;
        }
    }

    $decoded = null;

    if (isset($matchRow['goal_events']) && is_string($matchRow['goal_events']) && trim($matchRow['goal_events']) !== '') {
        $tmp = json_decode($matchRow['goal_events'], true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    } elseif (isset($matchRow['goal_events']) && is_array($matchRow['goal_events'])) {
        $decoded = $matchRow['goal_events'];
    }

    if ($decoded === null && !empty($matchRow['meta_json']) && is_string($matchRow['meta_json'])) {
        $meta = json_decode($matchRow['meta_json'], true);
        if (is_array($meta) && isset($meta['goal_events']) && is_array($meta['goal_events'])) {
            $decoded = $meta['goal_events'];
        }
    }

    if (!is_array($decoded)) {
        return [];
    }

    $events = [];
    foreach ($decoded as $idx => $event) {
        if (!is_array($event)) {
            continue;
        }

        $player = trim((string)($event['player_raw'] ?? $event['player'] ?? ''));
        $minuteRaw = $event['minute'] ?? null;
        $minute = null;

        if ($minuteRaw !== null && $minuteRaw !== '' && is_numeric((string)$minuteRaw)) {
            $minute = (int)$minuteRaw;
        }

        $teamSide = cmp_edit_normalize_goal_side((string)($event['team_side'] ?? $event['side'] ?? 'desconocido'));

        if ($player === '' && $minute === null && $teamSide === 'desconocido') {
            continue;
        }

        $events[] = [
            'order' => (int)($event['order'] ?? ($idx + 1)),
            'player_raw' => $player,
            'player_normalized' => $event['player_normalized'] ?? null,
            'minute' => $minute,
            'team_side' => $teamSide,
            'team_name' => $event['team_name'] ?? null,
            'goal_type' => $event['goal_type'] ?? 'normal',
            'raw_fragment' => $event['raw_fragment'] ?? null,
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
        if ($minuteRaw !== '' && is_numeric($minuteRaw)) {
            $minute = (int)$minuteRaw;
        }

        if ($player === '' && $minute === null && $teamSide === 'desconocido') {
            continue;
        }

        $events[] = [
            'player_raw' => $player,
            'player_normalized' => null,
            'minute' => $minute,
            'team_side' => $teamSide,
            'team_name' => null,
            'goal_type' => 'normal',
            'raw_fragment' => null,
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

function cmp_edit_sync_match_goal_events_meta(int $matchId, array $events): void {
    $db = cmp_edit_db();

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

function cmp_edit_update_goal_events(int $matchId, array $events): void {
    $db = cmp_edit_db();

    $stmt = $db->prepare('SELECT importacion_id FROM cmp_importacion_partidos WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $res = $stmt->get_result();
    $match = $res->fetch_assoc() ?: null;
    $stmt->close();

    if (!$match) {
        throw new RuntimeException('Partido inválido.');
    }

    $importId = (int)$match['importacion_id'];

    $db->begin_transaction();
    try {
        $stmt = $db->prepare('DELETE FROM cmp_importacion_goles WHERE partido_id = ?');
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $stmt->close();

        $sql = 'INSERT INTO cmp_importacion_goles
                (importacion_id, partido_id, orden, team_side, team_name, jugador_raw, jugador_normalizado, minuto, goal_type, raw_fragment, creado_en, actualizado_en)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }

        $order = 1;
        foreach ($events as $ev) {
            $playerRaw = trim((string)($ev['player_raw'] ?? ''));
            if ($playerRaw === '') {
                continue;
            }

            $teamSide = cmp_edit_normalize_goal_side((string)($ev['team_side'] ?? 'desconocido'));
            $teamName = $ev['team_name'] ?? null;
            $playerNorm = $ev['player_normalized'] ?? null;
            $minute = (isset($ev['minute']) && $ev['minute'] !== '' && $ev['minute'] !== null) ? (int)$ev['minute'] : null;
            $goalType = (string)($ev['goal_type'] ?? 'normal');
            $rawFragment = $ev['raw_fragment'] ?? null;
            $orden = $order++;

            $stmt->bind_param(
                'iiissssiss',
                $importId,
                $matchId,
                $orden,
                $teamSide,
                $teamName,
                $playerRaw,
                $playerNorm,
                $minute,
                $goalType,
                $rawFragment
            );
            $stmt->execute();
        }
        $stmt->close();

        cmp_edit_sync_match_goal_events_meta($matchId, $events);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
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

    $db = cmp_edit_db();
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
    $db = cmp_edit_db();
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
    $db = cmp_edit_db();
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
    $db = cmp_edit_db();

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
    $db = cmp_edit_db();
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

    $db = cmp_edit_db();
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
    $db = cmp_edit_db();
    $stmt = $db->prepare('UPDATE cmp_importacion_partidos SET estado = ?, actualizado_en = NOW() WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('si', $state, $matchId);
    $stmt->execute();
    $stmt->close();
}

function cmp_edit_get_distinct_tituloreg_options(): array {
    $db = cmp_edit_db();

    $sql = "
        SELECT TRIM(tituloReg) AS tituloReg
        FROM partidos
        WHERE TRIM(COALESCE(tituloReg, '')) <> ''
        GROUP BY TRIM(tituloReg)
        ORDER BY TRIM(tituloReg) ASC
    ";

    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException('Error cargando tituloReg: ' . $db->error);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $value = trim((string)($row['tituloReg'] ?? ''));
        if ($value !== '') {
            $rows[] = $value;
        }
    }
    $res->free();

    return $rows;
}

function cmp_edit_get_match_links_for_matches(array $matchIds): array {
    if ($matchIds === []) {
        return [];
    }

    $db = cmp_edit_db();
    $ids = array_values(array_unique(array_map('intval', $matchIds)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    $sql = "SELECT *
            FROM cmp_importacion_partido_vinculos
            WHERE importacion_partido_id IN ($placeholders)
            ORDER BY importacion_partido_id ASC, score DESC, id ASC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();

    $grouped = [];
    while ($row = $res->fetch_assoc()) {
        $matchId = (int)$row['importacion_partido_id'];
        if (!isset($grouped[$matchId])) {
            $grouped[$matchId] = [];
        }
        $grouped[$matchId][] = $row;
    }

    $stmt->close();
    return $grouped;
}

function cmp_edit_attach_match_link_summary(array $matches): array {
    $ids = [];
    foreach ($matches as $m) {
        $ids[] = (int)$m['id'];
    }

    $grouped = cmp_edit_get_match_links_for_matches($ids);

    foreach ($matches as &$match) {
        $matchId = (int)$match['id'];
        $links = $grouped[$matchId] ?? [];
        $match['match_links'] = $links;
        $match['match_link_count'] = count($links);
        $match['match_link_best'] = $links[0] ?? null;
        $match['match_link_status'] = cmp_edit_summarize_match_link_status($links);
    }
    unset($match);

    return $matches;
}

function cmp_edit_summarize_match_link_status(array $links): string {
    if ($links === []) {
        return 'sin_evaluar';
    }

    $validated = 0;
    $proposed = 0;
    foreach ($links as $link) {
        $state = (string)($link['estado'] ?? 'propuesto');
        if ($state === 'validado') {
            $validated++;
        } elseif ($state === 'propuesto') {
            $proposed++;
        }
    }

    if ($validated > 0) {
        return 'validado';
    }
    if ($proposed === 1) {
        return 'propuesta_unica';
    }
    if ($proposed > 1) {
        return 'ambiguo';
    }
    return 'rechazado';
}

function cmp_edit_set_match_link_state(int $linkId, string $state): void {
    $allowed = ['validado', 'rechazado', 'propuesto'];
    if (!in_array($state, $allowed, true)) {
        throw new InvalidArgumentException('Estado de vínculo inválido.');
    }

    $db = cmp_edit_db();

    $sql = "SELECT id, importacion_partido_id
            FROM cmp_importacion_partido_vinculos
            WHERE id = ?
            LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $linkId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('No existe el vínculo.');
    }

    $matchId = (int)$row['importacion_partido_id'];

    if ($state === 'validado') {
        $sqlReset = "UPDATE cmp_importacion_partido_vinculos
                     SET estado = 'rechazado'
                     WHERE importacion_partido_id = ?";
        $stmtReset = $db->prepare($sqlReset);
        if (!$stmtReset) {
            throw new RuntimeException($db->error);
        }
        $stmtReset->bind_param('i', $matchId);
        $stmtReset->execute();
        $stmtReset->close();
    }

    $sqlUpdate = "UPDATE cmp_importacion_partido_vinculos
                  SET estado = ?
                  WHERE id = ?";
    $stmtUpdate = $db->prepare($sqlUpdate);
    if (!$stmtUpdate) {
        throw new RuntimeException($db->error);
    }
    $stmtUpdate->bind_param('si', $state, $linkId);
    $stmtUpdate->execute();
    $stmtUpdate->close();
}

function cmp_edit_generate_match_links(
    int $importId,
    int $nodeId,
    string $tituloReg,
    bool $includeDescendants = true,
    bool $allowSwapped = true
): array {
    $node = cmp_edit_get_node_row($nodeId);
    if (!$node || (int)$node['importacion_id'] !== $importId) {
        throw new RuntimeException('Nodo inválido para esta importación.');
    }

    $nodesFlat = cmp_edit_get_nodes_flat($importId);
    $matchesFlat = cmp_edit_get_matches_flat($importId);
    $tree = cmp_edit_build_tree($nodesFlat, $matchesFlat);
    $currentNode = cmp_edit_find_node_in_tree($tree, $nodeId);

    if (!$currentNode) {
        throw new RuntimeException('No se pudo reconstruir el nodo seleccionado.');
    }

    $targetMatches = cmp_edit_get_matches_for_node($currentNode, $matchesFlat, $includeDescendants);
    if ($targetMatches === []) {
        return [
            'evaluados' => 0,
            'unicos' => 0,
            'ambiguos' => 0,
            'sin_candidato' => 0,
        ];
    }

    $validatedRows = cmp_edit_get_validated_matches_by_tituloreg($tituloReg);

    $db = cmp_edit_db();

    $targetMatchIds = [];
    foreach ($targetMatches as $m) {
        $targetMatchIds[] = (int)$m['id'];
    }

    if ($targetMatchIds !== []) {
        $ids = array_values(array_unique($targetMatchIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $sqlDelete = "DELETE FROM cmp_importacion_partido_vinculos
                      WHERE importacion_partido_id IN ($placeholders)";
        $stmtDelete = $db->prepare($sqlDelete);
        if (!$stmtDelete) {
            throw new RuntimeException($db->error);
        }
        $stmtDelete->bind_param($types, ...$ids);
        $stmtDelete->execute();
        $stmtDelete->close();
    }

    $stats = [
        'evaluados' => 0,
        'unicos' => 0,
        'ambiguos' => 0,
        'sin_candidato' => 0,
    ];

    foreach ($targetMatches as $match) {
        $stats['evaluados']++;

        $matchDate = cmp_edit_extract_match_fecha($match);
        $candidates = cmp_edit_find_validated_candidates_for_import_match(
            $match,
            $validatedRows,
            $allowSwapped,
            $matchDate
        );

        if ($candidates === []) {
            $stats['sin_candidato']++;
            continue;
        }

        if (count($candidates) === 1) {
            $stats['unicos']++;
        } else {
            $stats['ambiguos']++;
        }

        foreach ($candidates as $cand) {
            cmp_edit_insert_match_link_candidate(
                $importId,
                (int)$match['id'],
                $tituloReg,
                $matchDate,
                $cand
            );
        }
    }

    return $stats;
}

function cmp_edit_get_validated_matches_by_tituloreg(string $tituloReg): array {
    $db = cmp_edit_db();

    $sql = "SELECT barcode, tituloSobre, tituloReg, fecha, equipo1, equipo2, cancha
            FROM partidos
            WHERE tituloReg = ?
            ORDER BY fecha ASC, equipo1 ASC, equipo2 ASC, barcode ASC";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('s', $tituloReg);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['norm_equipo1'] = cmp_edit_normalize_team_name((string)$row['equipo1']);
        $row['norm_equipo2'] = cmp_edit_normalize_team_name((string)$row['equipo2']);
        $row['pair_key'] = cmp_edit_make_pair_key($row['norm_equipo1'], $row['norm_equipo2']);
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function cmp_edit_extract_match_fecha(array $match): ?string {
    $meta = [];
    if (!empty($match['meta_json'])) {
        $decoded = json_decode((string)$match['meta_json'], true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }

    $candidates = [
        $meta['fecha_iso'] ?? null,
        $meta['date_default'] ?? null,
        $meta['fecha'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '' && preg_match('/^\d{8}$/', $value)) {
            return $value;
        }
    }

    return null;
}

function cmp_edit_find_validated_candidates_for_import_match(
    array $importMatch,
    array $validatedRows,
    bool $allowSwapped,
    ?string $matchDate
): array {
    $homeNorm = cmp_edit_normalize_team_name((string)$importMatch['local_texto']);
    $awayNorm = cmp_edit_normalize_team_name((string)$importMatch['visitante_texto']);
    $pairKey = cmp_edit_make_pair_key($homeNorm, $awayNorm);

    $results = [];

    foreach ($validatedRows as $row) {
        if ($pairKey !== (string)$row['pair_key']) {
            continue;
        }

        $isSwapped = false;
        if ($homeNorm === (string)$row['norm_equipo2'] && $awayNorm === (string)$row['norm_equipo1']) {
            $isSwapped = true;
            if (!$allowSwapped) {
                continue;
            }
        }

        $score = 100;
        $obs = ['equipos coinciden'];

        $fechaValidada = trim((string)($row['fecha'] ?? ''));
        $fechaCoincide = 0;
        if ($matchDate !== null && $fechaValidada !== '' && preg_match('/^\d{8}$/', $fechaValidada)) {
            if ($matchDate === $fechaValidada) {
                $score += 40;
                $fechaCoincide = 1;
                $obs[] = 'fecha coincide';
            } else {
                $score -= 15;
                $obs[] = 'fecha distinta';
            }
        }

        if ($isSwapped) {
            $score -= 5;
            $obs[] = 'localía invertida';
        }

        $results[] = [
            'partido_barcode' => (string)$row['barcode'],
            'equipo1_validado' => (string)$row['equipo1'],
            'equipo2_validado' => (string)$row['equipo2'],
            'fecha_validada' => $fechaValidada !== '' ? $fechaValidada : null,
            'cancha_validada' => (string)$row['cancha'],
            'es_localia_invertida' => $isSwapped ? 1 : 0,
            'fecha_coincide' => $fechaCoincide,
            'score' => $score,
            'observacion' => implode('; ', $obs),
            'meta_json' => json_encode([
                'import_local_norm' => $homeNorm,
                'import_visitante_norm' => $awayNorm,
                'validado_equipo1_norm' => (string)$row['norm_equipo1'],
                'validado_equipo2_norm' => (string)$row['norm_equipo2'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    usort($results, static function (array $a, array $b): int {
        $cmp = (int)$b['score'] <=> (int)$a['score'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string)$a['partido_barcode'], (string)$b['partido_barcode']);
    });

    return $results;
}

function cmp_edit_insert_match_link_candidate(
    int $importId,
    int $importMatchId,
    string $tituloReg,
    ?string $fechaImportada,
    array $candidate
): void {
    $db = cmp_edit_db();

    $sql = "INSERT INTO cmp_importacion_partido_vinculos
            (importacion_id, importacion_partido_id, partido_barcode, tituloReg, estado, score,
             es_localia_invertida, fecha_importada, fecha_validada, fecha_coincide,
             equipo1_validado, equipo2_validado, cancha_validada, observacion, meta_json)
            VALUES (?, ?, ?, ?, 'propuesto', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                es_localia_invertida = VALUES(es_localia_invertida),
                fecha_importada = VALUES(fecha_importada),
                fecha_validada = VALUES(fecha_validada),
                fecha_coincide = VALUES(fecha_coincide),
                equipo1_validado = VALUES(equipo1_validado),
                equipo2_validado = VALUES(equipo2_validado),
                cancha_validada = VALUES(cancha_validada),
                observacion = VALUES(observacion),
                meta_json = VALUES(meta_json),
                actualizado_en = CURRENT_TIMESTAMP()";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $barcode = (string)$candidate['partido_barcode'];
    $score = (int)$candidate['score'];
    $invertida = (int)$candidate['es_localia_invertida'];
    $fechaValidada = $candidate['fecha_validada'];
    $fechaCoincide = (int)$candidate['fecha_coincide'];
    $equipo1Validado = (string)$candidate['equipo1_validado'];
    $equipo2Validado = (string)$candidate['equipo2_validado'];
    $canchaValidada = (string)$candidate['cancha_validada'];
    $observacion = (string)$candidate['observacion'];
    $metaJson = (string)$candidate['meta_json'];

    $stmt->bind_param(
        'iissiississsss',
        $importId,
        $importMatchId,
        $barcode,
        $tituloReg,
        $score,
        $invertida,
        $fechaImportada,
        $fechaValidada,
        $fechaCoincide,
        $equipo1Validado,
        $equipo2Validado,
        $canchaValidada,
        $observacion,
        $metaJson
    );

    $stmt->execute();
    $stmt->close();
}

function cmp_edit_normalize_team_name(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $name = mb_strtolower($name, 'UTF-8');
    } else {
        $name = strtolower($name);
    }

    $name = cmp_edit_remove_accents($name);
    $name = str_replace(["'", '.', ',', ';', ':', '/', '\\', '-', '_'], ' ', $name);
    $name = preg_replace('/[()\\[\\]{}]/', ' ', $name);

    $aliases = cmp_edit_team_alias_map();
    if (isset($aliases[$name])) {
        $name = $aliases[$name];
    }

    $tokens = preg_split('/\s+/', $name) ?: [];
    $stopwords = [
        'club',
        'atletico',
        'asociacion',
        'de',
        'del',
        'la',
        'las',
        'los',
        'y',
    ];

    $clean = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        if (in_array($token, $stopwords, true)) {
            continue;
        }
        $clean[] = $token;
    }

    $name = implode(' ', $clean);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim((string)$name);

    if (isset($aliases[$name])) {
        $name = $aliases[$name];
    }

    return $name;
}

function cmp_edit_make_pair_key(string $a, string $b): string {
    $pair = [$a, $b];
    sort($pair, SORT_STRING);
    return implode('||', $pair);
}

function cmp_edit_remove_accents(string $text): string {
    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];
    return strtr($text, $map);
}

function cmp_edit_team_alias_map(): array {
    return [
        'newell s old boys' => 'newells old boys',
        'newells old boys' => 'newells old boys',
        'club atletico newells old boys' => 'newells old boys',

        'estudiantes lp' => 'estudiantes plata',
        'estudiantes de la plata' => 'estudiantes plata',
        'club estudiantes de la plata' => 'estudiantes plata',
        'club atletico estudiantes de la plata' => 'estudiantes plata',

        'gimnasia lp' => 'gimnasia plata',
        'gimnasia y esgrima la plata' => 'gimnasia plata',
        'club de gimnasia y esgrima la plata' => 'gimnasia plata',

        'belgrano c' => 'belgrano cordoba',
        'belgrano de cordoba' => 'belgrano cordoba',
        'club atletico belgrano' => 'belgrano cordoba',

        'rosario central' => 'rosario central',
        'club atletico rosario central' => 'rosario central',

        'boca juniors' => 'boca juniors',
        'club atletico boca juniors' => 'boca juniors',

        'river plate' => 'river plate',
        'club atletico river plate' => 'river plate',

        'argentinos juniors' => 'argentinos juniors',
        'asociacion atletica argentinos juniors' => 'argentinos juniors',

        'huracan' => 'huracan',
        'club atletico huracan' => 'huracan',

        'banfield' => 'banfield',
        'club atletico banfield' => 'banfield',

        'racing club' => 'racing club',
        'club racing' => 'racing club',

        'san lorenzo' => 'san lorenzo',
        'san lorenzo de almagro' => 'san lorenzo',
        'club atletico san lorenzo de almagro' => 'san lorenzo',

        'velez sarsfield' => 'velez sarsfield',
        'club atletico velez sarsfield' => 'velez sarsfield',
    ];
}

function cmp_edit_db(): mysqli {
    return cmp_db();
}

function cmp_edit_filter_matches_by_link_status(array $matches, string $filter): array {
    $filter = trim($filter);
    if ($filter === '' || $filter === 'all') {
        return $matches;
    }

    $out = [];
    foreach ($matches as $match) {
        $status = (string)($match['match_link_status'] ?? 'sin_evaluar');
        switch ($filter) {
            case 'pending':
                if (in_array($status, ['propuesta_unica', 'ambiguo', 'sin_evaluar'], true)) {
                    $out[] = $match;
                }
                break;
            case 'unique':
                if ($status === 'propuesta_unica') {
                    $out[] = $match;
                }
                break;
            case 'ambiguous':
                if ($status === 'ambiguo') {
                    $out[] = $match;
                }
                break;
            case 'none':
                if ($status === 'sin_evaluar') {
                    $out[] = $match;
                }
                break;
            case 'validated':
                if ($status === 'validado') {
                    $out[] = $match;
                }
                break;
            default:
                $out[] = $match;
                break;
        }
    }

    return $out;
}