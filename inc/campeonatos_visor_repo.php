<?php
declare(strict_types=1);
require_once __DIR__ . '/campeonatos_sin_identificar_repo.php';
require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

require_once __DIR__ . '/campeonatos_import_edit_repo.php';
require_once __DIR__ . '/campeonatos_relacion_sobres_repo.php';
require_once __DIR__ . '/campeonatos_entidades_repo.php';

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
    return cmp_ent_normalize_name($text);
}

function cmp_visor_list_years(): array {
    $db = cmp_visor_db();

    $years = [];

    $sqlImports = "
        SELECT DISTINCT temporada_detectada AS year_value
        FROM cmp_importaciones
        WHERE temporada_detectada IS NOT NULL
          AND temporada_detectada <> ''
          AND temporada_detectada <> '0'
    ";

    $res = $db->query($sqlImports);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    while ($row = $res->fetch_assoc()) {
        $val = trim((string)($row['year_value'] ?? ''));
        if ($val !== '') {
            $years[$val] = true;
        }
    }
    $res->free();

    $sqlNodes = "
        SELECT DISTINCT TRIM(n.label) AS year_value
        FROM cmp_importacion_nodos n
        INNER JOIN cmp_importaciones i ON i.id = n.importacion_id
        WHERE COALESCE(n.is_deleted,0)=0
          AND TRIM(n.label) REGEXP '^(19|20)[0-9]{2}$'
          AND EXISTS (
              SELECT 1
              FROM cmp_importacion_partidos p
              WHERE p.importacion_id = n.importacion_id
                AND p.nodo_id = n.id
                AND COALESCE(p.estado,'activo') <> 'ignorado'
          )
    ";

    $res = $db->query($sqlNodes);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    while ($row = $res->fetch_assoc()) {
        $val = trim((string)($row['year_value'] ?? ''));
        if ($val !== '') {
            $years[$val] = true;
        }
    }
    $res->free();

    $rows = array_keys($years);
    rsort($rows, SORT_NATURAL);

    return $rows;
}

function cmp_visor_list_teams(): array {
    $entities = cmp_ent_list_used_in_matches(null);
    $rows = [];

    foreach ($entities as $entity) {
        $name = trim((string)($entity['nombre_mostrable'] ?? ''));
        if ($name !== '') {
            $rows[] = $name;
        }
    }

    natcasesort($rows);

    return array_values($rows);
}

function cmp_visor_list_years_for_filters(array $filters): array {
    $selectedId = (int)($filters['id'] ?? 0);
    $team1 = trim((string)($filters['team1'] ?? ''));

    if ($selectedId > 0) {
        $import = cmp_edit_get_import($selectedId);
        $year = trim((string)($import['temporada_detectada'] ?? ''));
        return $year !== '' ? [$year] : cmp_visor_list_years();
    }

    if ($team1 === '') {
        return cmp_visor_list_years();
    }

    $imports = cmp_visor_list_imports([
        'year' => '',
        'team1' => $team1,
        'team2' => '',
        'id' => 0,
        'node_id' => 0,
        'only_linked' => '0',
    ]);

    $years = [];
    foreach ($imports as $row) {
        $year = trim((string)($row['temporada_detectada'] ?? ''));
        if ($year !== '') {
            $years[$year] = true;
        }
    }

    $rows = array_keys($years);
    rsort($rows, SORT_NATURAL);

    return $rows;
}

function cmp_visor_list_teams_for_filters(array $filters): array {
    $selectedId = (int)($filters['id'] ?? 0);
    $year = trim((string)($filters['year'] ?? ''));

    if ($selectedId > 0) {
        $entities = cmp_ent_list_used_in_matches($selectedId);
        $rows = [];

        foreach ($entities as $entity) {
            $name = trim((string)($entity['nombre_mostrable'] ?? ''));
            if ($name !== '') {
                $rows[$name] = true;
            }
        }

        $out = array_keys($rows);
        natcasesort($out);

        return array_values($out);
    }

    if ($year === '') {
        return cmp_visor_list_teams();
    }

    $imports = cmp_visor_list_imports([
        'year' => $year,
        'team1' => '',
        'team2' => '',
        'id' => 0,
        'node_id' => 0,
        'only_linked' => '0',
    ]);

    $rows = [];

    foreach ($imports as $import) {
        $importId = (int)($import['id'] ?? 0);
        if ($importId <= 0) {
            continue;
        }

        $entities = cmp_ent_list_used_in_matches($importId);
        foreach ($entities as $entity) {
            $name = trim((string)($entity['nombre_mostrable'] ?? ''));
            if ($name !== '') {
                $rows[$name] = true;
            }
        }
    }

    $out = array_keys($rows);
    natcasesort($out);

    return array_values($out);
}

function cmp_visor_render_year_options_html(array $years, string $selectedYear = ''): string {
    ob_start();

    echo '<option value="">Todos</option>';
    foreach ($years as $year) {
        $year = trim((string)$year);
        if ($year === '') {
            continue;
        }

        echo '<option value="' . cmp_visor_h($year) . '"' . ($year === $selectedYear ? ' selected' : '') . '>';
        echo cmp_visor_h($year);
        echo '</option>';
    }

    return (string)ob_get_clean();
}

function cmp_visor_render_import_options_html(array $imports, int $selectedId = 0): string {
    ob_start();

    echo '<option value="">Seleccionar…</option>';

    foreach ($imports as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $title = trim((string)($row['titulo_fuente'] ?? ''));
        $year = trim((string)($row['temporada_detectada'] ?? ''));

        $label = $title !== '' ? $title : ('Campeonato #' . $id);
        if ($year !== '' && stripos($label, $year) === false) {
            $label .= ' · ' . $year;
        }

        echo '<option value="' . $id . '"' . ($id === $selectedId ? ' selected' : '') . '>';
        echo cmp_visor_h($label);
        echo '</option>';
    }

    return (string)ob_get_clean();
}

function cmp_visor_render_team_options_html(array $teams, string $selectedTeam = ''): string {
    ob_start();

    echo '<option value="">Todos</option>';

    foreach ($teams as $team) {
        $team = trim((string)$team);
        if ($team === '') {
            continue;
        }

        echo '<option value="' . cmp_visor_h($team) . '"' . ($team === $selectedTeam ? ' selected' : '') . '>';
        echo cmp_visor_h($team);
        echo '</option>';
    }

    return (string)ob_get_clean();
}

function cmp_visor_has_global_filters(array $filters): bool {
    return trim((string)($filters['year'] ?? '')) !== ''
        || trim((string)($filters['team1'] ?? '')) !== ''
        || (int)($filters['id'] ?? 0) > 0;
}

function cmp_visor_list_imports(array $filters): array {
    $db = cmp_visor_db();

    $where = [];
    $types = '';
    $params = [];

    if ($filters['year'] !== '') {
        $where[] = "(
            i.temporada_detectada = ?
            OR EXISTS (
                SELECT 1
                FROM cmp_importacion_nodos n_year
                INNER JOIN cmp_importacion_partidos p_year
                    ON p_year.importacion_id = n_year.importacion_id
                   AND p_year.nodo_id = n_year.id
                   AND COALESCE(p_year.estado,'activo') <> 'ignorado'
                WHERE n_year.importacion_id = i.id
                  AND COALESCE(n_year.is_deleted,0)=0
                  AND TRIM(n_year.label) = ?
            )
        )";
        $types .= 'ss';
        $params[] = $filters['year'];
        $params[] = $filters['year'];
    }

    if ($filters['team1'] !== '') {
        $resolved = cmp_ent_resolve_name($filters['team1']);
        $filterNorm = cmp_ent_normalize_name($filters['team1']);
        $likeNorm = '%' . $filterNorm . '%';

        if ($resolved) {
            $entityId = (int)$resolved['id'];

            $where[] = "EXISTS (
                SELECT 1
                FROM cmp_importacion_partidos p
                WHERE p.importacion_id = i.id
                  AND COALESCE(p.estado,'activo') <> 'ignorado'
                  AND (
                        p.local_entidad_id = ?
                     OR p.visitante_entidad_id = ?
                     OR COALESCE(NULLIF(p.local_normalizado,''), '') LIKE ?
                     OR COALESCE(NULLIF(p.visitante_normalizado,''), '') LIKE ?
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(p.local_texto, ''))),'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ü','u'),'ñ','n') LIKE ?
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(p.visitante_texto, ''))),'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ü','u'),'ñ','n') LIKE ?
                  )
            )";

            $types .= 'iissss';
            $params[] = $entityId;
            $params[] = $entityId;
            $params[] = $likeNorm;
            $params[] = $likeNorm;
            $params[] = $likeNorm;
            $params[] = $likeNorm;
        } else {
            $where[] = "EXISTS (
                SELECT 1
                FROM cmp_importacion_partidos p
                WHERE p.importacion_id = i.id
                  AND COALESCE(p.estado,'activo') <> 'ignorado'
                  AND (
                        COALESCE(NULLIF(p.local_normalizado,''), '') LIKE ?
                     OR COALESCE(NULLIF(p.visitante_normalizado,''), '') LIKE ?
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(p.local_texto, ''))),'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ü','u'),'ñ','n') LIKE ?
                     OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(TRIM(COALESCE(p.visitante_texto, ''))),'á','a'),'é','e'),'í','i'),'ó','o'),'ú','u'),'ü','u'),'ñ','n') LIKE ?
                  )
            )";

            $types .= 'ssss';
            $params[] = $likeNorm;
            $params[] = $likeNorm;
            $params[] = $likeNorm;
            $params[] = $likeNorm;
        }
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

    $ok1 = true;
    $ok2 = true;

    if ($team1 !== '') {
        $ok1 = cmp_ent_match_team_filter($match, $team1);
    }

    if ($team2 !== '') {
        $ok2 = cmp_ent_match_team_filter($match, $team2);
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

    echo '<details class="cmp-visor-structure">';
    echo '<summary>Estructura del campeonato / navegación alternativa</summary>';

    if ($steps === []) {
        echo '<div class="cmp-empty" style="padding:0 12px 12px;">No hay etapas visibles para este filtro.</div>';
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

    echo '</details>';

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

    echo '<section class="cmp-visor-shell">';
    echo cmp_visor_render_tree_panel_html($context['tree'], $team1, $team2);
    echo cmp_visor_render_empty_detail_html();
    echo '</section>';

    echo cmp_visor_render_structure_html($steps);

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
    $isSinIdentificar = false;

    if (!empty($_GET['id'])) {
        $isSinIdentificar = cmp_si_is_import((int)$_GET['id']);
    } elseif (!empty($_POST['id'])) {
        $isSinIdentificar = cmp_si_is_import((int)$_POST['id']);
    }

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

        $matchId = (int)($match['id'] ?? 0);

        echo '<article class="cmp-visor-match-card">';
        echo '  <div class="cmp-visor-match-line">';
        echo '      <strong>' . cmp_visor_h((string)$match['local_texto']) . '</strong>';
        echo '      <span class="cmp-visor-score">' . cmp_visor_h(cmp_visor_match_score_label($match)) . '</span>';
        echo '      <strong>' . cmp_visor_h((string)$match['visitante_texto']) . '</strong>';
        echo '  </div>';

        echo '  <div class="cmp-visor-match-meta" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">';
        if (count($assignedLinks) > 0) {
            echo '      <span class="cmp-visor-match-has-links">Con sobres vinculados</span>';
        }
        if ($isSinIdentificar && $matchId > 0) {
            echo '      <button type="button" class="cmp-btn cmp-btn-sm" data-move-match="' . $matchId . '">Mover a campeonato</button>';
        }
        echo '  </div>';

        if ($isSinIdentificar && $matchId > 0) {
            echo '  <div class="cmp-visor-move-box cmp-rel-hidden" id="cmpMoveBox' . $matchId . '" style="margin:10px 0; padding:10px; border:1px solid #d9dde4; border-radius:10px; background:#fafbfd;">';
            echo '      <div style="display:grid; gap:8px; grid-template-columns:1fr 1fr auto; align-items:end;">';
            echo '          <label style="display:grid; gap:4px;">';
            echo '              <span>Campeonato</span>';
            echo '              <select data-move-import="' . $matchId . '"><option value="">Seleccionar…</option></select>';
            echo '          </label>';
            echo '          <label style="display:grid; gap:4px;">';
            echo '              <span>Fecha destino</span>';
            echo '              <select data-move-node="' . $matchId . '"><option value="">Seleccionar…</option></select>';
            echo '          </label>';
            echo '          <button type="button" class="cmp-btn cmp-btn-sm" data-move-confirm="' . $matchId . '">Confirmar</button>';
            echo '      </div>';
            echo '      <div data-move-status="' . $matchId . '" style="margin-top:8px; font-size:13px;"></div>';
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