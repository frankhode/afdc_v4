<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/campeonatos_import_edit_repo.php';

function cmp_rel_get_import(int $importId): ?array {
    return cmp_edit_get_import($importId);
}

function cmp_rel_get_tituloreg_options(): array {
    return cmp_edit_get_distinct_tituloreg_options();
}

function cmp_rel_get_nodes_tree(int $importId): array {
    $nodesFlat = cmp_edit_get_nodes_flat($importId);
    $matchesFlat = cmp_edit_get_matches_flat($importId);
    $tree = cmp_edit_build_tree($nodesFlat, $matchesFlat);

    $assignedByMatch = cmp_rel_get_assigned_links_by_match($importId);
    foreach ($tree as $idx => $_node) {
        cmp_rel_attach_matches_to_tree($tree[$idx], $matchesFlat, $assignedByMatch);
    }

    return $tree;
}

function cmp_rel_attach_matches_to_tree(array &$node, array $matchesFlat, array $assignedByMatch): int {
    $nodeId = (int)($node['id'] ?? 0);
    $node['match_leaves'] = [];
    $assignedTotal = 0;

    foreach ($matchesFlat as $match) {
        if ((int)$match['nodo_id'] !== $nodeId) {
            continue;
        }
        if (($match['estado'] ?? 'activo') === 'ignorado') {
            continue;
        }

        $matchId = (int)$match['id'];
        $links = $assignedByMatch[$matchId] ?? [];
        $match['assigned_links'] = $links;
        $match['assigned_count'] = count($links);
        $match['drop_label'] = trim((string)$match['local_texto']) . ' vs ' . trim((string)$match['visitante_texto']);
        $node['match_leaves'][] = $match;
        $assignedTotal += count($links);
    }

    if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
    }

    foreach ($node['children'] as $idx => $_child) {
        $assignedTotal += cmp_rel_attach_matches_to_tree($node['children'][$idx], $matchesFlat, $assignedByMatch);
    }

    $node['assigned_total'] = $assignedTotal;
    return $assignedTotal;
}

function cmp_rel_get_assigned_links_by_match(int $importId): array {
    $db = cmp_db();
    $sql = "SELECT v.*, p.tituloSobre, p.fecha, p.equipo1, p.equipo2, p.cancha,
                   m.local_texto AS match_local_texto, m.visitante_texto AS match_visitante_texto
            FROM cmp_importacion_partido_vinculos v
            INNER JOIN partidos p ON p.barcode = v.partido_barcode
            INNER JOIN cmp_importacion_partidos m ON m.id = v.importacion_partido_id
            WHERE v.importacion_id = ?
            ORDER BY v.importacion_partido_id ASC, p.fecha ASC, p.barcode ASC";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $matchId = (int)$row['importacion_partido_id'];
        if (!isset($rows[$matchId])) {
            $rows[$matchId] = [];
        }
        $rows[$matchId][] = $row;
    }
    $stmt->close();
    return $rows;
}

function cmp_rel_get_pending_sobres(string $tituloReg, int $importId, string $view = 'pending', string $query = ''): array {
    if ($tituloReg === '') {
        return [];
    }

    $db = cmp_db();
    $where = ["p.tituloReg = ?"];
    $types = 'is';
    $params = [$importId, $tituloReg];

    $join = " LEFT JOIN cmp_importacion_partido_vinculos v
                   ON v.importacion_id = ?
                  AND v.partido_barcode = p.barcode
              LEFT JOIN cmp_importacion_partidos m
                   ON m.id = v.importacion_partido_id ";

    if ($view === 'pending') {
        $where[] = 'v.id IS NULL';
    } elseif ($view === 'assigned') {
        $where[] = 'v.id IS NOT NULL';
    }

    $query = trim($query);
    if ($query !== '') {
        $like = '%' . $query . '%';
        $where[] = '(p.barcode LIKE ? OR p.tituloSobre LIKE ? OR p.equipo1 LIKE ? OR p.equipo2 LIKE ? OR p.fecha LIKE ? OR COALESCE(m.local_texto, "") LIKE ? OR COALESCE(m.visitante_texto, "") LIKE ?)';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql = "SELECT p.barcode, p.tituloSobre, p.tituloReg, p.fecha, p.equipo1, p.equipo2, p.cancha,
                   v.id AS vinculo_id, v.importacion_partido_id, v.origen,
                   m.local_texto AS asignado_local, m.visitante_texto AS asignado_visitante
            FROM partidos p
            {$join}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(p.fecha, ''), COALESCE(p.equipo1, ''), COALESCE(p.equipo2, ''), p.barcode";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cmp_rel_get_sobres_summary(string $tituloReg, int $importId): array {
    if ($tituloReg === '') {
        return [
            'total' => 0,
            'pending' => 0,
            'assigned' => 0,
        ];
    }

    $db = cmp_db();
    $sql = "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN v.id IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN v.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned
            FROM partidos p
            LEFT JOIN cmp_importacion_partido_vinculos v
              ON v.importacion_id = ?
             AND v.partido_barcode = p.barcode
            WHERE p.tituloReg = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('is', $importId, $tituloReg);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc() ?: ['total' => 0, 'pending' => 0, 'assigned' => 0];
    $stmt->close();

    return [
        'total' => (int)($row['total'] ?? 0),
        'pending' => (int)($row['pending'] ?? 0),
        'assigned' => (int)($row['assigned'] ?? 0),
    ];
}

function cmp_rel_assign_barcode(int $importId, int $matchId, string $barcode, string $tituloReg, string $origin = 'manual_boton'): void {
    $barcode = trim($barcode);
    if ($importId <= 0 || $matchId <= 0 || $barcode === '' || $tituloReg === '') {
        throw new InvalidArgumentException('Faltan datos para asignar el sobre.');
    }

    $allowedOrigins = ['automatico', 'manual_drag', 'manual_boton'];
    if (!in_array($origin, $allowedOrigins, true)) {
        $origin = 'manual_boton';
    }

    $db = cmp_db();

    $sqlMatch = 'SELECT id, importacion_id FROM cmp_importacion_partidos WHERE id = ? LIMIT 1';
    $stmtMatch = $db->prepare($sqlMatch);
    if (!$stmtMatch) {
        throw new RuntimeException($db->error);
    }
    $stmtMatch->bind_param('i', $matchId);
    $stmtMatch->execute();
    $resMatch = $stmtMatch->get_result();
    $matchRow = $resMatch->fetch_assoc() ?: null;
    $stmtMatch->close();
    if (!$matchRow || (int)$matchRow['importacion_id'] !== $importId) {
        throw new RuntimeException('El partido no pertenece a la importación actual.');
    }

    $sqlSobre = 'SELECT barcode FROM partidos WHERE barcode = ? AND tituloReg = ? LIMIT 1';
    $stmtSobre = $db->prepare($sqlSobre);
    if (!$stmtSobre) {
        throw new RuntimeException($db->error);
    }
    $stmtSobre->bind_param('ss', $barcode, $tituloReg);
    $stmtSobre->execute();
    $resSobre = $stmtSobre->get_result();
    $sobreRow = $resSobre->fetch_assoc() ?: null;
    $stmtSobre->close();
    if (!$sobreRow) {
        throw new RuntimeException('El sobre no pertenece al tituloReg seleccionado.');
    }

    $db->begin_transaction();
    try {
        $sqlDelete = 'DELETE FROM cmp_importacion_partido_vinculos WHERE importacion_id = ? AND partido_barcode = ?';
        $stmtDelete = $db->prepare($sqlDelete);
        if (!$stmtDelete) {
            throw new RuntimeException($db->error);
        }
        $stmtDelete->bind_param('is', $importId, $barcode);
        $stmtDelete->execute();
        $stmtDelete->close();

        $sqlInsert = 'INSERT INTO cmp_importacion_partido_vinculos
            (importacion_id, importacion_partido_id, partido_barcode, tituloReg, origen, observacion)
            VALUES (?, ?, ?, ?, ?, NULL)';
        $stmtInsert = $db->prepare($sqlInsert);
        if (!$stmtInsert) {
            throw new RuntimeException($db->error);
        }
        $stmtInsert->bind_param('iisss', $importId, $matchId, $barcode, $tituloReg, $origin);
        $stmtInsert->execute();
        $stmtInsert->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function cmp_rel_unassign_barcode(int $importId, string $barcode): void {
    $barcode = trim($barcode);
    if ($importId <= 0 || $barcode === '') {
        throw new InvalidArgumentException('Faltan datos para desasignar el sobre.');
    }

    $db = cmp_db();
    $sql = 'DELETE FROM cmp_importacion_partido_vinculos WHERE importacion_id = ? AND partido_barcode = ?';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('is', $importId, $barcode);
    $stmt->execute();
    $stmt->close();
}

function cmp_rel_render_tree_nodes(array $nodes, int $importId, string $tituloReg): void {
    if ($nodes === []) {
        echo '<div class="cmp-empty">No hay estructura para esta importación.</div>';
        return;
    }

    echo '<ul class="cmp-rel-tree">';
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        $matches = $node['match_leaves'] ?? [];
        $hasChildren = !empty($children) || !empty($matches);
        $type = trim((string)($node['tipo'] ?? '') . (((string)($node['subtipo'] ?? '')) !== '' ? ':' . (string)$node['subtipo'] : ''));
        $label = trim((string)($node['label'] ?? ''));
        $search = mb_strtolower($type . ' ' . $label, 'UTF-8');

        echo '<li class="cmp-rel-node" data-node-block data-node-search="' . cmp_h($search) . '">';
        echo '<div class="cmp-rel-node-row">';
        if ($hasChildren) {
            echo '<button type="button" class="cmp-rel-toggle" data-rel-toggle aria-expanded="true">−</button>';
        } else {
            echo '<span class="cmp-rel-toggle-spacer"></span>';
        }
        echo '<span class="cmp-rel-node-type">' . cmp_h($type) . '</span>';
        echo '<span class="cmp-rel-node-label">' . cmp_h($label) . '</span>';
        echo '<span class="cmp-chip">' . (int)($node['match_count_total'] ?? 0) . '</span>';
        echo '<span class="cmp-chip cmp-chip-manual">sobres: ' . (int)($node['assigned_total'] ?? 0) . '</span>';
        echo '</div>';

        echo '<div class="cmp-rel-node-children">';
        if (!empty($children)) {
            cmp_rel_render_tree_nodes($children, $importId, $tituloReg);
        }

        if (!empty($matches)) {
            echo '<ul class="cmp-rel-matches">';
            foreach ($matches as $match) {
                $matchId = (int)$match['id'];
                $assignedLinks = $match['assigned_links'] ?? [];
                $matchSearch = mb_strtolower(trim((string)$match['local_texto'] . ' ' . (string)$match['visitante_texto'] . ' ' . (string)($match['nodo_label'] ?? '')), 'UTF-8');

                echo '<li class="cmp-rel-match-leaf" data-match-leaf data-node-search="' . cmp_h($matchSearch) . '" data-assigned-count="' . count($assignedLinks) . '">';
                echo '<div class="cmp-rel-match-dropzone" data-drop-match-id="' . $matchId . '">';
                echo '<div class="cmp-rel-match-head">';
                echo '<div class="cmp-rel-match-title"><strong>' . cmp_h((string)$match['local_texto']) . '</strong> <span class="cmp-rel-vs">vs</span> <strong>' . cmp_h((string)$match['visitante_texto']) . '</strong></div>';
                if ($tituloReg !== '') {
                    echo '<button type="button" class="cmp-btn cmp-btn-sm" data-assign-selected="' . $matchId . '">Asignar seleccionado</button>';
                }
                echo '</div>';
                echo '<div class="cmp-rel-match-meta">';
                echo '<span>#' . $matchId . '</span>';
                if (!empty($match['nodo_label'])) {
                    echo '<span>' . cmp_h((string)$match['nodo_label']) . '</span>';
                }
                if (($match['goles_local'] ?? null) !== null || ($match['goles_visitante'] ?? null) !== null) {
                    echo '<span>' . cmp_h((string)($match['goles_local'] ?? '')) . ' - ' . cmp_h((string)($match['goles_visitante'] ?? '')) . '</span>';
                }
                echo '<span class="cmp-chip">sobres: ' . count($assignedLinks) . '</span>';
                echo '</div>';

                
                if ($assignedLinks !== []) {
                    echo '<div class="cmp-rel-assigned-list">';
                    foreach ($assignedLinks as $link) {
                        echo '<div class="cmp-rel-assigned-item">';
                        echo '<div>';
                        echo '<strong>' . cmp_h((string)$link['partido_barcode']) . '</strong>';
                        echo ' · ' . cmp_h((string)($link['equipo1'] ?? '')) . ' vs ' . cmp_h((string)($link['equipo2'] ?? ''));
                        if (!empty($link['fecha'])) {
                            echo ' <span class="cmp-rel-muted">(' . cmp_h((string)$link['fecha']) . ')</span>';
                        }
                        echo '</div>';
                        echo '<div class="cmp-rel-assigned-actions">';
                        echo '<a href="../ver_digital.php?barcode=' . rawurlencode((string)$link['partido_barcode']) . '&i=0" target="_blank" rel="noopener">Ver sobre</a>';
                        echo '<button type="button" class="cmp-btn cmp-btn-sm" data-unassign-barcode="' . cmp_h((string)$link['partido_barcode']) . '">Quitar</button>';
                        echo '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }

                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        echo '</li>';
    }
    echo '</ul>';
}

function cmp_rel_render_left_tree_html(int $importId, string $tituloReg): string {
    $tree = cmp_rel_get_nodes_tree($importId);
    ob_start();
    cmp_rel_render_tree_nodes($tree, $importId, $tituloReg);
    return (string)ob_get_clean();
}

function cmp_rel_render_right_panel_html(int $importId, array $tituloRegOptions, string $tituloReg, string $view, string $qSobres): string {
    $sobres = [];
    $summary = ['total' => 0, 'pending' => 0, 'assigned' => 0];

    if ($tituloReg !== '') {
        $sobres = cmp_rel_get_pending_sobres($tituloReg, $importId, $view, $qSobres);
        $summary = cmp_rel_get_sobres_summary($tituloReg, $importId);
    }

    ob_start();
    ?>
    <div class="cmp-rel-status">
      <span class="cmp-chip">total: <?= (int)$summary['total'] ?></span>
      <span class="cmp-chip cmp-chip-empty">pendientes: <?= (int)$summary['pending'] ?></span>
      <span class="cmp-chip cmp-chip-manual">asignados: <?= (int)$summary['assigned'] ?></span>
    </div>

    <div class="cmp-rel-card-list" id="cmpRelCardList" style="margin-top:10px;">
      <?php if ($tituloReg === ''): ?>
        <div class="cmp-empty">Seleccioná un tituloReg para empezar.</div>
      <?php elseif ($sobres === []): ?>
        <div class="cmp-empty">No hay sobres para esta vista/filtro.</div>
      <?php else: ?>
        <?php foreach ($sobres as $sobre): ?>
          <article class="cmp-rel-card"
                   draggable="true"
                   tabindex="0"
                   data-barcode="<?= cmp_h((string)$sobre['barcode']) ?>">
            <h4><?= cmp_h((string)$sobre['barcode']) ?></h4>

            <div class="cmp-rel-card-meta">
              <?php if (!empty($sobre['fecha'])): ?>
                <span><?= cmp_h((string)$sobre['fecha']) ?></span>
              <?php endif; ?>
              <?php if (!empty($sobre['origen'])): ?>
                <span>origen: <?= cmp_h((string)$sobre['origen']) ?></span>
              <?php endif; ?>
            </div>

            <div class="cmp-rel-card-text"><?= cmp_h((string)$sobre['tituloSobre']) ?></div>

            <div class="cmp-rel-card-teams">
              <?= cmp_h((string)$sobre['equipo1']) ?> - <?= cmp_h((string)$sobre['equipo2']) ?>
              <?php if (!empty($sobre['fecha'])): ?>, <?= cmp_h((string)$sobre['fecha']) ?><?php endif; ?>
            </div>

            <?php if (!empty($sobre['asignado_local']) || !empty($sobre['asignado_visitante'])): ?>
              <div class="cmp-rel-card-assigned">
                Asignado a: <?= cmp_h((string)($sobre['asignado_local'] ?? '')) ?>
                vs
                <?= cmp_h((string)($sobre['asignado_visitante'] ?? '')) ?>
              </div>
            <?php endif; ?>

            <div class="cmp-rel-card-actions">
              <a href="../ver_digital.php?barcode=<?= rawurlencode((string)$sobre['barcode']) ?>&i=0" target="_blank" rel="noopener">Ver sobre</a>
              <?php if (!empty($sobre['vinculo_id'])): ?>
                <button type="button" class="cmp-btn cmp-btn-sm" data-unassign-barcode="<?= cmp_h((string)$sobre['barcode']) ?>">Quitar</button>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}
