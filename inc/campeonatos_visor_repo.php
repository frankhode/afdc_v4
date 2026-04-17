<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

require_once __DIR__ . '/campeonatos_import_edit_repo.php';
require_once __DIR__ . '/campeonatos_relacion_sobres_repo.php';

function cmp_visor_db(): mysqli {
    return cmp_db();
}

function cmp_visor_h(string $value): string {
    return cmp_h($value);
}

function cmp_visor_filters_from_request(): array {
    return [
        'year'  => trim((string)($_GET['year'] ?? $_POST['year'] ?? '')),
        'team1' => trim((string)($_GET['team1'] ?? $_POST['team1'] ?? '')),
        'team2' => trim((string)($_GET['team2'] ?? $_POST['team2'] ?? '')),
        'id'    => (int)($_GET['id'] ?? $_POST['id'] ?? 0),
        'node_id' => (int)($_GET['node_id'] ?? $_POST['node_id'] ?? 0),
        'only_linked' => trim((string)($_GET['only_linked'] ?? $_POST['only_linked'] ?? '0')),
    ];
}

function cmp_visor_normalize_text(string $text): string {
    return cmp_edit_normalize_team_name($text);
}

function cmp_visor_list_years(): array {
    $db = cmp_visor_db();
    $sql = "
        SELECT DISTINCT temporada_detectada
        FROM cmp_importaciones
        WHERE temporada_detectada IS NOT NULL
          AND temporada_detectada <> ''
        ORDER BY temporada_detectada DESC
    ";
    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $val = trim((string)($row['temporada_detectada'] ?? ''));
        if ($val !== '') {
            $rows[] = $val;
        }
    }
    $res->free();

    return $rows;
}

function cmp_visor_list_teams(): array {
    $db = cmp_visor_db();

    $sql = "
        SELECT nombre
        FROM (
            SELECT TRIM(COALESCE(local_texto, '')) AS nombre
            FROM cmp_importacion_partidos
            WHERE COALESCE(estado,'activo') <> 'ignorado'

            UNION

            SELECT TRIM(COALESCE(visitante_texto, '')) AS nombre
            FROM cmp_importacion_partidos
            WHERE COALESCE(estado,'activo') <> 'ignorado'
        ) t
        WHERE nombre <> ''
        ORDER BY nombre ASC
    ";

    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $name = trim((string)($row['nombre'] ?? ''));
        if ($name !== '') {
            $rows[] = $name;
        }
    }
    $res->free();

    return $rows;
}

function cmp_visor_has_global_filters(array $filters): bool {
    return $filters['year'] !== '' || $filters['team1'] !== '';
}

function cmp_visor_list_imports(array $filters): array {
    if (!cmp_visor_has_global_filters($filters)) {
        return [];
    }

    $db = cmp_visor_db();

    $where = [];
    $types = '';
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = 'i.temporada_detectada = ?';
        $types .= 's';
        $params[] = $filters['year'];
    }

    if ($filters['team1'] !== '') {
        $like = '%' . $filters['team1'] . '%';
        $where[] = "EXISTS (
            SELECT 1
            FROM cmp_importacion_partidos p
            WHERE p.importacion_id = i.id
              AND COALESCE(p.estado,'activo') <> 'ignorado'
              AND (
                    p.local_texto LIKE ?
                 OR p.visitante_texto LIKE ?
              )
        )";
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "
        SELECT
            i.*,
            (
                SELECT COUNT(*)
                FROM cmp_importacion_partidos p
                WHERE p.importacion_id = i.id
                  AND COALESCE(p.estado,'activo') <> 'ignorado'
            ) AS matches_count,
            (
                SELECT COUNT(*)
                FROM cmp_importacion_partido_vinculos v
                WHERE v.importacion_id = i.id
            ) AS linked_sobres_count
        FROM cmp_importaciones i
    ";

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY i.temporada_detectada DESC, i.id DESC';

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

function cmp_visor_match_visible(array $match, string $team1, string $team2): bool {
    if (($match['estado'] ?? 'activo') === 'ignorado') {
        return false;
    }

    $home = cmp_visor_normalize_text((string)($match['local_texto'] ?? ''));
    $away = cmp_visor_normalize_text((string)($match['visitante_texto'] ?? ''));

    $ok1 = true;
    $ok2 = true;

    if ($team1 !== '') {
        $f1 = cmp_visor_normalize_text($team1);
        $ok1 = str_contains($home, $f1) || str_contains($away, $f1);
    }

    if ($team2 !== '') {
        $f2 = cmp_visor_normalize_text($team2);
        $ok2 = str_contains($home, $f2) || str_contains($away, $f2);
    }

    return $ok1 && $ok2;
}

function cmp_visor_node_has_visible_content(array $node, string $team1, string $team2): bool {
    foreach (($node['match_leaves'] ?? []) as $match) {
        if (cmp_visor_match_visible($match, $team1, $team2)) {
            return true;
        }
    }

    foreach (($node['children'] ?? []) as $child) {
        if (cmp_visor_node_has_visible_content($child, $team1, $team2)) {
            return true;
        }
    }

    return false;
}

function cmp_visor_get_import_context(int $importId, string $team1 = '', string $team2 = ''): array {
    $import = cmp_edit_get_import($importId);
    if (!$import) {
        throw new RuntimeException('La importación no existe.');
    }

    $tree = cmp_rel_get_nodes_tree($importId);
    $matches = cmp_edit_get_matches_flat($importId);
    $linksByMatch = cmp_rel_get_assigned_links_by_match($importId);

    $activeMatches = 0;
    $linkedSobres = 0;

    foreach ($matches as $match) {
        if (cmp_visor_match_visible($match, $team1, $team2)) {
            $activeMatches++;
        }
    }

    foreach ($linksByMatch as $matchId => $rows) {
        foreach ($matches as $m) {
            if ((int)$m['id'] === (int)$matchId && cmp_visor_match_visible($m, $team1, $team2)) {
                $linkedSobres += count($rows);
                break;
            }
        }
    }

    return [
        'import' => $import,
        'tree' => $tree,
        'active_matches' => $activeMatches,
        'linked_sobres' => $linkedSobres,
    ];
}

function cmp_visor_node_anchor_id(array $node): string {
    return 'cmp-visor-node-' . (int)($node['id'] ?? 0);
}

function cmp_visor_tree_children_id(array $node): string {
    return 'cmp-visor-children-' . (int)($node['id'] ?? 0);
}

function cmp_visor_collect_structure_steps(array $tree, string $team1 = '', string $team2 = ''): array {
    $rootNodes = $tree;
    if (count($tree) === 1 && (string)($tree[0]['tipo'] ?? '') === 'temporada') {
        $rootNodes = $tree[0]['children'] ?? [];
    }

    $steps = [];
    foreach ($rootNodes as $node) {
        if (!cmp_visor_node_has_visible_content($node, $team1, $team2)) {
            continue;
        }

        $tipo = trim((string)($node['tipo'] ?? ''));
        if ($tipo === '' || $tipo === 'nota' || $tipo === 'fecha') {
            continue;
        }

        $subtipo = trim((string)($node['subtipo'] ?? ''));
        $label = trim((string)($node['label'] ?? ''));

        $caption = $label !== '' ? $label : ucfirst($tipo);
        if ($subtipo !== '') {
            $caption .= ' · ' . $subtipo;
        }

        $steps[] = [
            'node_id' => (int)($node['id'] ?? 0),
            'label' => $caption,
            'anchor' => cmp_visor_node_anchor_id($node),
        ];
    }

    return $steps;
}

function cmp_visor_render_import_cards_html(array $imports, int $selectedId = 0): string {
    ob_start();

    if ($imports === []) {
        echo '<div class="cmp-empty">No hay campeonatos para esos filtros.</div>';
        return (string)ob_get_clean();
    }

    echo '<div class="cmp-visor-import-strip">';
    foreach ($imports as $row) {
        $id = (int)$row['id'];
        $isCurrent = $id === $selectedId;

        echo '<button type="button" class="cmp-visor-import-card' . ($isCurrent ? ' is-current' : '') . '" data-import-id="' . $id . '">';
        echo '<div class="cmp-visor-import-title">' . cmp_visor_h((string)($row['titulo_fuente'] ?? '')) . '</div>';
        echo '<div class="cmp-visor-import-meta">';
        echo '<span class="cmp-chip">#' . $id . '</span>';
        if (!empty($row['temporada_detectada'])) {
            echo '<span class="cmp-chip">' . cmp_visor_h((string)$row['temporada_detectada']) . '</span>';
        }
        echo '<span class="cmp-chip">partidos: ' . (int)($row['matches_count'] ?? 0) . '</span>';
        echo '<span class="cmp-chip">sobres: ' . (int)($row['linked_sobres_count'] ?? 0) . '</span>';
        echo '</div>';
        echo '</button>';
    }
    echo '</div>';

    return (string)ob_get_clean();
}

function cmp_visor_render_structure_html(array $steps): string {
    ob_start();

    echo '<section class="cmp-visor-structure">';
    echo '<h3>Estructura del campeonato</h3>';

    if ($steps === []) {
        echo '<div class="cmp-empty">No hay etapas visibles para este filtro.</div>';
    } else {
        echo '<div class="cmp-visor-steps">';
        foreach ($steps as $idx => $step) {
            echo '<div class="cmp-visor-step">';
            echo '<button type="button" class="cmp-visor-step-box" data-stage-target="' . cmp_visor_h((string)$step['anchor']) . '" data-stage-node-id="' . (int)$step['node_id'] . '">' . cmp_visor_h((string)$step['label']) . '</button>';
            if ($idx < count($steps) - 1) {
                echo '<span class="cmp-visor-step-arrow">→</span>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    echo '</section>';

    return (string)ob_get_clean();
}

function cmp_visor_render_tree_nodes(array $nodes, string $team1, string $team2): void {
    if ($nodes === []) {
        return;
    }

    echo '<ul class="cmp-visor-tree">';

    foreach ($nodes as $node) {
        if (!cmp_visor_node_has_visible_content($node, $team1, $team2)) {
            continue;
        }

        $visibleChildren = [];
        foreach (($node['children'] ?? []) as $child) {
            if (cmp_visor_node_has_visible_content($child, $team1, $team2)) {
                $visibleChildren[] = $child;
            }
        }

        $hasVisibleChildren = $visibleChildren !== [];
        $anchor = cmp_visor_node_anchor_id($node);
        $childrenId = cmp_visor_tree_children_id($node);

        $tipo = trim((string)($node['tipo'] ?? ''));
        $subtipo = trim((string)($node['subtipo'] ?? ''));
        $label = trim((string)($node['label'] ?? ''));
        $fullType = $tipo . ($subtipo !== '' ? ':' . $subtipo : '');

        echo '<li class="cmp-visor-tree-item" data-tree-node data-node-id="' . (int)$node['id'] . '">';
        echo '  <div class="cmp-visor-tree-row">';
        if ($hasVisibleChildren) {
            echo '    <button type="button" class="cmp-visor-tree-toggle" data-tree-toggle data-target="' . cmp_visor_h($childrenId) . '" aria-expanded="false">+</button>';
        } else {
            echo '    <span class="cmp-visor-tree-toggle-spacer"></span>';
        }

        echo '    <a class="cmp-visor-tree-link" href="#' . cmp_visor_h($anchor) . '" data-node-link data-node-id="' . (int)$node['id'] . '" data-node-anchor="' . cmp_visor_h($anchor) . '">';
        echo '      <span class="cmp-visor-tree-type">' . cmp_visor_h($fullType) . '</span>';
        echo '      <span class="cmp-visor-tree-label">' . cmp_visor_h($label) . '</span>';
        if ((int)($node['assigned_total'] ?? 0) > 0) {
            echo '  <span class="cmp-visor-tree-has-links" title="Hay sobres vinculados">●</span>';
        }
        echo '    </a>';
        echo '  </div>';

        if ($hasVisibleChildren) {
            echo '  <div class="cmp-visor-tree-children cmp-rel-hidden" id="' . cmp_visor_h($childrenId) . '">';
            cmp_visor_render_tree_nodes($visibleChildren, $team1, $team2);
            echo '  </div>';
        }

        echo '</li>';
    }

    echo '</ul>';
}

function cmp_visor_render_tree_panel_html(array $tree, string $team1, string $team2): string {
    ob_start();

    echo '<aside class="cmp-visor-panel">';
    echo '<div class="cmp-visor-panel-head">';
    echo '<h3>Navegación</h3>';
    echo '</div>';

    if ($tree === []) {
        echo '<div class="cmp-empty">Sin estructura.</div>';
    } else {
        echo '<div class="cmp-visor-tree-tools">';
        echo '  <button type="button" class="cmp-btn cmp-btn-sm" data-expand-all>Expandir</button>';
        echo '  <button type="button" class="cmp-btn cmp-btn-sm" data-collapse-all>Colapsar</button>';
        echo '</div>';
        cmp_visor_render_tree_nodes($tree, $team1, $team2);
    }

    echo '</aside>';

    return (string)ob_get_clean();
}

function cmp_visor_render_empty_detail_html(): string {
    return '<section class="cmp-visor-panel"><h3>Partidos y sobres</h3><div class="cmp-empty">Seleccioná un nodo del árbol para ver su contenido.</div></section>';
}

function cmp_visor_match_score_label(array $match): string {
    $gl = $match['goles_local'] ?? null;
    $gv = $match['goles_visitante'] ?? null;

    if ($gl === null && $gv === null) {
        return '—';
    }

    return (string)$gl . ' - ' . (string)$gv;
}

function cmp_visor_render_match_links(array $assignedLinks): void {
    if ($assignedLinks === []) {
        echo '<div class="cmp-visor-no-links">Sin sobres vinculados todavía.</div>';
        return;
    }

    echo '<div class="cmp-visor-linked-list">';
    foreach ($assignedLinks as $link) {
        $barcode = trim((string)($link['partido_barcode'] ?? ''));
        $titulo = trim((string)($link['tituloSobre'] ?? ''));
        $fecha = trim((string)($link['fecha'] ?? ''));
        $equipo1 = trim((string)($link['equipo1'] ?? ''));
        $equipo2 = trim((string)($link['equipo2'] ?? ''));

        echo '<article class="cmp-visor-linked-card">';
        echo '<div class="cmp-visor-linked-head">';
        echo '<strong>' . cmp_visor_h($barcode) . '</strong>';
        if ($fecha !== '') {
            echo '<span class="cmp-visor-muted">' . cmp_visor_h($fecha) . '</span>';
        }
        echo '</div>';

        if ($titulo !== '') {
            echo '<div class="cmp-visor-linked-title">' . cmp_visor_h($titulo) . '</div>';
        }

        if ($equipo1 !== '' || $equipo2 !== '') {
            echo '<div class="cmp-visor-muted">' . cmp_visor_h($equipo1) . ' vs ' . cmp_visor_h($equipo2) . '</div>';
        }

        if ($barcode !== '') {
            echo '<div class="cmp-visor-linked-actions">';
            echo '<a href="../ver_digital.php?barcode=' . rawurlencode($barcode) . '&i=0" target="_blank" rel="noopener">Ver digital</a>';
            echo '</div>';
        }

        echo '</article>';
    }
    echo '</div>';
}

function cmp_visor_find_node_by_id(array $nodes, int $nodeId): ?array {
    foreach ($nodes as $node) {
        if ((int)($node['id'] ?? 0) === $nodeId) {
            return $node;
        }
        $found = cmp_visor_find_node_by_id($node['children'] ?? [], $nodeId);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

function cmp_visor_render_content_nodes(array $nodes, string $team1, string $team2, array $trail = [], bool $onlyLinked = false): void {
    $rows = cmp_visor_collect_visible_matches($nodes, $team1, $team2, $onlyLinked, $trail);
    cmp_visor_render_grouped_matches($rows);
}

function cmp_visor_render_node_detail_html(array $tree, int $nodeId, string $team1, string $team2, bool $onlyLinked = false): string {
    $node = cmp_visor_find_node_by_id($tree, $nodeId);
    if (!$node || !cmp_visor_node_has_visible_content($node, $team1, $team2)) {
        return '<section class="cmp-visor-panel"><h3>Partidos y sobres</h3><div class="cmp-empty">No hay contenido visible para ese nodo.</div></section>';
    }

    $contextLabel = trim((string)($node['label'] ?? ''));
    $rows = cmp_visor_collect_visible_matches([$node], $team1, $team2, $onlyLinked, []);
    $visibleMatches = count($rows);
    $visibleLinks = cmp_visor_count_visible_links_from_rows($rows);

    ob_start();
    echo '<section class="cmp-visor-panel">';
    echo '<div class="cmp-visor-panel-head">';
    echo '  <div>';
    echo '    <h3>Partidos y sobres</h3>';
    echo '    <h2 class="cmp-visor-node-title" style="margin-top:6px;">' . cmp_visor_h($contextLabel) . '</h2>';
    echo '  </div>';
    echo '  <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
    if ($visibleMatches > 0) {
        echo '<span class="cmp-chip">partidos: ' . $visibleMatches . '</span>';
    }
    if ($visibleLinks > 0) {
        echo '<span class="cmp-chip">sobres: ' . $visibleLinks . '</span>';
    }
    echo '    <label class="cmp-visor-only-linked">';
    echo '      <input type="checkbox" id="cmpVisorOnlyLinked" ' . ($onlyLinked ? 'checked' : '') . '> Solo con sobres';
    echo '    </label>';
    echo '  </div>';
    echo '</div>';

    echo '<div class="cmp-visor-match-list">';
    cmp_visor_render_grouped_matches($rows);
    echo '</div>';

    echo '</section>';

    return (string)ob_get_clean();
}

function cmp_visor_render_championship_shell_html(array $context, string $team1, string $team2): string {
    $steps = cmp_visor_collect_structure_steps($context['tree'], $team1, $team2);

    ob_start();
    echo cmp_visor_render_structure_html($steps);
    echo '<section class="cmp-visor-shell">';
    echo cmp_visor_render_tree_panel_html($context['tree'], $team1, $team2);
    echo cmp_visor_render_empty_detail_html();
    echo '</section>';

    return (string)ob_get_clean();
}

function cmp_visor_collect_visible_matches(array $nodes, string $team1, string $team2, bool $onlyLinked = false, array $trail = []): array {
    $rows = [];

    foreach ($nodes as $node) {
        if (!cmp_visor_node_has_visible_content($node, $team1, $team2)) {
            continue;
        }

        $label = trim((string)($node['label'] ?? ''));
        $currentTrail = $trail;
        if ($label !== '') {
            $currentTrail[] = $label;
        }

        foreach (($node['match_leaves'] ?? []) as $match) {
            if (!cmp_visor_match_visible($match, $team1, $team2)) {
                continue;
            }

            $assignedLinks = $match['assigned_links'] ?? [];
            if ($onlyLinked && count($assignedLinks) === 0) {
                continue;
            }

            $rows[] = [
                'group_path' => $currentTrail,
                'match' => $match,
            ];
        }

        $childRows = cmp_visor_collect_visible_matches($node['children'] ?? [], $team1, $team2, $onlyLinked, $currentTrail);
        foreach ($childRows as $r) {
            $rows[] = $r;
        }
    }

    return $rows;
}

function cmp_visor_render_grouped_matches(array $rows): void {
    if ($rows === []) {
        echo '<div class="cmp-empty">No hay partidos visibles para este nodo.</div>';
        return;
    }

    $currentGroupKey = null;

    foreach ($rows as $row) {
        $groupPath = $row['group_path'] ?? [];
        $match = $row['match'] ?? [];
        $assignedLinks = $match['assigned_links'] ?? [];

        $groupLabel = '';
        if (count($groupPath) > 1) {
            $groupLabel = implode(' / ', array_slice($groupPath, 1));
        } elseif (count($groupPath) === 1) {
            $groupLabel = (string)$groupPath[0];
        }

        $groupKey = md5($groupLabel);

        if ($groupKey !== $currentGroupKey) {
            if ($groupLabel !== '') {
                echo '<div class="cmp-visor-match-group">' . cmp_visor_h($groupLabel) . '</div>';
            }
            $currentGroupKey = $groupKey;
        }

        echo '<article class="cmp-visor-match-card">';
        echo '  <div class="cmp-visor-match-line">';
        echo '      <strong>' . cmp_visor_h((string)$match['local_texto']) . '</strong>';
        echo '      <span class="cmp-visor-score">' . cmp_visor_h(cmp_visor_match_score_label($match)) . '</span>';
        echo '      <strong>' . cmp_visor_h((string)$match['visitante_texto']) . '</strong>';
        echo '  </div>';

        if (count($assignedLinks) > 0) {
            echo '  <div class="cmp-visor-match-meta">';
            echo '      <span class="cmp-visor-match-has-links">Con sobres vinculados</span>';
            echo '  </div>';
        }

        cmp_visor_render_match_links($assignedLinks);

        echo '</article>';
    }
}

function cmp_visor_count_visible_links_from_rows(array $rows): int {
    $total = 0;
    foreach ($rows as $row) {
        $match = $row['match'] ?? [];
        $total += count($match['assigned_links'] ?? []);
    }
    return $total;
}