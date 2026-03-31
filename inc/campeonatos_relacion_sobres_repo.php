<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/campeonatos_import_edit_repo.php';

function cmp_rel_get_import(int $importId): ?array { return cmp_edit_get_import($importId); }
function cmp_rel_get_tituloreg_options(): array { return cmp_edit_get_distinct_tituloreg_options(); }

function cmp_rel_get_nodes_tree(int $importId): array {
    $nodesFlat = cmp_edit_get_nodes_flat($importId);
    $matchesFlat = cmp_edit_get_matches_flat($importId);
    $tree = cmp_edit_build_tree($nodesFlat, $matchesFlat);
    $assignedByMatch = cmp_rel_get_assigned_links_by_match($importId);
    foreach ($tree as $idx => $_node) cmp_rel_attach_matches_to_tree($tree[$idx], $matchesFlat, $assignedByMatch);
    return $tree;
}

function cmp_rel_attach_matches_to_tree(array &$node, array $matchesFlat, array $assignedByMatch): int {
    $nodeId = (int)($node['id'] ?? 0);
    $node['match_leaves'] = [];
    $assignedTotal = 0;
    foreach ($matchesFlat as $match) {
        if ((int)$match['nodo_id'] !== $nodeId) continue;
        if (($match['estado'] ?? 'activo') === 'ignorado') continue;
        $matchId = (int)$match['id'];
        $links = $assignedByMatch[$matchId] ?? [];
        $match['assigned_links'] = $links;
        $match['assigned_count'] = count($links);
        $node['match_leaves'][] = $match;
        $assignedTotal += count($links);
    }
    $node['children'] = is_array($node['children'] ?? null) ? $node['children'] : [];
    foreach ($node['children'] as $idx => $_child) $assignedTotal += cmp_rel_attach_matches_to_tree($node['children'][$idx], $matchesFlat, $assignedByMatch);
    $node['assigned_total'] = $assignedTotal;
    return $assignedTotal;
}

function cmp_rel_get_assigned_links_by_match(int $importId): array {
    $db = cmp_db();
    $sql = "SELECT v.*, p.tituloSobre, p.fecha, p.equipo1, p.equipo2, p.cancha, m.local_texto AS match_local_texto, m.visitante_texto AS match_visitante_texto
            FROM cmp_importacion_partido_vinculos v
            INNER JOIN partidos p ON p.barcode = v.partido_barcode
            INNER JOIN cmp_importacion_partidos m ON m.id = v.importacion_partido_id
            WHERE v.importacion_id = ?
            ORDER BY v.importacion_partido_id ASC, p.fecha ASC, p.barcode ASC";
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $matchId = (int)$row['importacion_partido_id'];
        $rows[$matchId] ??= [];
        $rows[$matchId][] = $row;
    }
    $stmt->close();
    return $rows;
}

function cmp_rel_get_pending_sobres(string $tituloReg, int $importId, string $view = 'pending'): array {
    if ($tituloReg === '') return [];
    $db = cmp_db();
    $where = ["p.tituloReg = ?"];
    $types = 'is';
    $params = [$importId, $tituloReg];
    $join = " LEFT JOIN cmp_importacion_partido_vinculos v ON v.importacion_id = ? AND v.partido_barcode = p.barcode
              LEFT JOIN cmp_importacion_partidos m ON m.id = v.importacion_partido_id ";
    if ($view === 'pending') $where[] = 'v.id IS NULL';
    elseif ($view === 'loaded' || $view === 'assigned') $where[] = 'v.id IS NOT NULL';
    $sql = "SELECT p.barcode, p.tituloSobre, p.tituloReg, p.fecha, p.equipo1, p.equipo2, p.cancha,
                   v.id AS vinculo_id, v.importacion_partido_id, v.origen,
                   m.local_texto AS asignado_local, m.visitante_texto AS asignado_visitante
            FROM partidos p {$join}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(p.fecha, ''), COALESCE(p.equipo1, ''), COALESCE(p.equipo2, ''), p.barcode";
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function cmp_rel_assign_barcode(int $importId, int $matchId, string $barcode, string $tituloReg, string $origin = 'manual_boton'): void {
    $barcode = trim($barcode);
    if ($importId <= 0 || $matchId <= 0 || $barcode === '' || $tituloReg === '') throw new InvalidArgumentException('Faltan datos para asignar el sobre.');
    $allowedOrigins = ['automatico', 'manual_drag', 'manual_boton'];
    if (!in_array($origin, $allowedOrigins, true)) $origin = 'manual_boton';
    $db = cmp_db();
    $stmtMatch = $db->prepare('SELECT id, importacion_id FROM cmp_importacion_partidos WHERE id = ? LIMIT 1');
    if (!$stmtMatch) throw new RuntimeException($db->error);
    $stmtMatch->bind_param('i', $matchId);
    $stmtMatch->execute();
    $matchRow = $stmtMatch->get_result()->fetch_assoc() ?: null;
    $stmtMatch->close();
    if (!$matchRow || (int)$matchRow['importacion_id'] !== $importId) throw new RuntimeException('El partido no pertenece a la importación actual.');
    $stmtSobre = $db->prepare('SELECT barcode FROM partidos WHERE barcode = ? AND tituloReg = ? LIMIT 1');
    if (!$stmtSobre) throw new RuntimeException($db->error);
    $stmtSobre->bind_param('ss', $barcode, $tituloReg);
    $stmtSobre->execute();
    $sobreRow = $stmtSobre->get_result()->fetch_assoc() ?: null;
    $stmtSobre->close();
    if (!$sobreRow) throw new RuntimeException('El sobre no pertenece al tituloReg seleccionado.');
    $db->begin_transaction();
    try {
        $stmtDelete = $db->prepare('DELETE FROM cmp_importacion_partido_vinculos WHERE importacion_id = ? AND partido_barcode = ?');
        if (!$stmtDelete) throw new RuntimeException($db->error);
        $stmtDelete->bind_param('is', $importId, $barcode);
        $stmtDelete->execute();
        $stmtDelete->close();
        $stmtInsert = $db->prepare('INSERT INTO cmp_importacion_partido_vinculos (importacion_id, importacion_partido_id, partido_barcode, tituloReg, origen, observacion) VALUES (?, ?, ?, ?, ?, NULL)');
        if (!$stmtInsert) throw new RuntimeException($db->error);
        $stmtInsert->bind_param('iisss', $importId, $matchId, $barcode, $tituloReg, $origin);
        $stmtInsert->execute();
        $stmtInsert->close();
        $db->commit();
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function cmp_rel_unassign_barcode(int $importId, string $barcode): void {
    $barcode = trim($barcode);
    if ($importId <= 0 || $barcode === '') throw new InvalidArgumentException('Faltan datos para desasignar el sobre.');
    $db = cmp_db();
    $stmt = $db->prepare('DELETE FROM cmp_importacion_partido_vinculos WHERE importacion_id = ? AND partido_barcode = ?');
    if (!$stmt) throw new RuntimeException($db->error);
    $stmt->bind_param('is', $importId, $barcode);
    $stmt->execute();
    $stmt->close();
}

function cmp_rel_render_tree_nodes(array $nodes, int $importId, string $tituloReg): void {
    if ($nodes === []) { echo '<div class="cmp-empty">No hay estructura para esta importación.</div>'; return; }
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
        echo $hasChildren ? '<button type="button" class="cmp-rel-toggle" data-rel-toggle aria-expanded="true">−</button>' : '<span class="cmp-rel-toggle-spacer"></span>';
        echo '<span class="cmp-rel-node-type">' . cmp_h($type) . '</span>';
        echo '<span class="cmp-rel-node-label">' . cmp_h($label) . '</span>';
        echo '<span class="cmp-chip">' . (int)($node['match_count_total'] ?? 0) . '</span>';
        echo '<span class="cmp-chip cmp-chip-manual">sobres: ' . (int)($node['assigned_total'] ?? 0) . '</span>';
        echo '</div><div class="cmp-rel-node-children">';
        if (!empty($children)) cmp_rel_render_tree_nodes($children, $importId, $tituloReg);
        if (!empty($matches)) {
            echo '<ul class="cmp-rel-matches">';
            foreach ($matches as $match) {
                $matchId = (int)$match['id'];
                $assignedLinks = $match['assigned_links'] ?? [];
                $matchSearch = mb_strtolower(trim((string)$match['local_texto'] . ' ' . (string)$match['visitante_texto'] . ' ' . (string)($match['nodo_label'] ?? '')), 'UTF-8');
                echo '<li class="cmp-rel-match-leaf" data-match-leaf data-node-search="' . cmp_h($matchSearch) . '" data-assigned-count="' . count($assignedLinks) . '">';
                echo '<div class="cmp-rel-match-dropzone" data-drop-match-id="' . $matchId . '">';
                echo '<div class="cmp-rel-match-head"><div class="cmp-rel-match-title"><strong>' . cmp_h((string)$match['local_texto']) . '</strong> <span class="cmp-rel-vs">vs</span> <strong>' . cmp_h((string)$match['visitante_texto']) . '</strong></div>';
                if ($tituloReg !== '') echo '<button type="button" class="cmp-btn cmp-btn-sm" data-assign-selected="' . $matchId . '">Asignar seleccionado</button>';
                echo '</div><div class="cmp-rel-match-meta"><span>#' . $matchId . '</span>';
                if (!empty($match['nodo_label'])) echo '<span>' . cmp_h((string)$match['nodo_label']) . '</span>';
                if (($match['goles_local'] ?? null) !== null || ($match['goles_visitante'] ?? null) !== null) echo '<span>' . cmp_h((string)($match['goles_local'] ?? '')) . ' - ' . cmp_h((string)($match['goles_visitante'] ?? '')) . '</span>';
                echo '<span class="cmp-chip">sobres: ' . count($assignedLinks) . '</span></div>';
                if ($assignedLinks !== []) {
                    echo '<div class="cmp-rel-assigned-list">';
                    foreach ($assignedLinks as $link) {
                        echo '<div class="cmp-rel-assigned-item"><div><strong>' . cmp_h((string)$link['partido_barcode']) . '</strong> · ' . cmp_h((string)($link['equipo1'] ?? '')) . ' vs ' . cmp_h((string)($link['equipo2'] ?? ''));
                        if (!empty($link['fecha'])) echo ' <span class="cmp-rel-muted">(' . cmp_h((string)$link['fecha']) . ')</span>';
                        echo '</div><div class="cmp-rel-assigned-actions"><a href="../ver_digital.php?barcode=' . rawurlencode((string)$link['partido_barcode']) . '&i=0" target="_blank" rel="noopener">Ver sobre</a><button type="button" class="cmp-btn cmp-btn-sm" data-unassign-barcode="' . cmp_h((string)$link['partido_barcode']) . '">Quitar</button></div></div>';
                    }
                    echo '</div>';
                }
                echo '</div></li>';
            }
            echo '</ul>';
        }
        echo '</div></li>';
    }
    echo '</ul>';
}

function cmp_rel_render_left_tree_html(int $importId, string $tituloReg): string {
    $tree = cmp_rel_get_nodes_tree($importId);
    ob_start(); cmp_rel_render_tree_nodes($tree, $importId, $tituloReg); return (string)ob_get_clean();
}

function cmp_rel_render_right_panel_html(int $importId, array $tituloRegOptions, string $tituloReg, string $view, string $qSobres = '', string $otrosSys = '', string $otrosTitulo = '', string $otrosFilter = 'pending'): string {
    if ($tituloReg === '__otros__') return cmp_rel_render_right_panel_otros_html($importId, $otrosSys, $otrosTitulo, $view);
    $sobres = $tituloReg !== '' ? cmp_rel_get_pending_sobres($tituloReg, $importId, $view) : [];
    ob_start();
    echo '<div class="cmp-rel-card-list" id="cmpRelCardList" style="margin-top:10px;">';
    if ($tituloReg === '') echo '<div class="cmp-empty">Seleccioná un tituloReg para empezar.</div>';
    elseif ($sobres === []) echo '<div class="cmp-empty">No hay sobres para esta vista.</div>';
    else foreach ($sobres as $sobre) {
        $search = trim((string)$sobre['tituloSobre'] . ' ' . (string)$sobre['equipo1'] . ' ' . (string)$sobre['equipo2'] . ' ' . (string)$sobre['fecha']);
        echo '<article class="cmp-rel-card" draggable="true" tabindex="0" data-right-search="' . cmp_h($search) . '" data-barcode="' . cmp_h((string)$sobre['barcode']) . '">';
        echo '<h4>' . cmp_h((string)$sobre['barcode']) . '</h4>';
        echo '<div class="cmp-rel-card-meta">';
        if (!empty($sobre['fecha'])) echo '<span>' . cmp_h((string)$sobre['fecha']) . '</span>';
        if (!empty($sobre['origen'])) echo '<span>origen: ' . cmp_h((string)$sobre['origen']) . '</span>';
        echo '</div><div class="cmp-rel-card-text">' . cmp_h((string)$sobre['tituloSobre']) . '</div>';
        echo '<div class="cmp-rel-card-teams">' . cmp_h((string)$sobre['equipo1']) . ' - ' . cmp_h((string)$sobre['equipo2']) . '</div>';
        if (!empty($sobre['asignado_local']) || !empty($sobre['asignado_visitante'])) echo '<div class="cmp-rel-card-assigned">Asignado a: ' . cmp_h((string)($sobre['asignado_local'] ?? '')) . ' vs ' . cmp_h((string)($sobre['asignado_visitante'] ?? '')) . '</div>';
        echo '<div class="cmp-rel-card-actions"><a href="../ver_digital.php?barcode=' . rawurlencode((string)$sobre['barcode']) . '&i=0" target="_blank" rel="noopener">Ver sobre</a>';
        if (!empty($sobre['vinculo_id'])) echo '<button type="button" class="cmp-btn cmp-btn-sm" data-unassign-barcode="' . cmp_h((string)$sobre['barcode']) . '">Quitar</button>';
        echo '</div></article>';
    }
    echo '</div>';
    return (string)ob_get_clean();
}

function cmp_rel_render_right_panel_otros_html(int $importId, string $otrosSys = '', string $otrosTitulo = '', string $view = 'pending'): string {
    $rows = [];
    if ($otrosSys !== '' && $otrosTitulo !== '') $rows = cmp_edit_get_otros_sobres_by_sys($otrosSys, $otrosTitulo, $view === 'assigned' ? 'loaded' : $view);
    ob_start();
    echo '<input type="hidden" id="cmpRelOtrosSys" value="' . cmp_h($otrosSys) . '">';
    echo '<input type="hidden" id="cmpRelOtrosTitulo" value="' . cmp_h($otrosTitulo) . '">';
    if ($otrosTitulo !== '') echo '<div class="cmp-rel-muted" style="margin-top:6px;"><strong>Seleccionado:</strong> ' . cmp_h($otrosTitulo) . ' <small>· sys ' . cmp_h($otrosSys) . '</small></div>';
    else echo '<div class="cmp-empty" style="margin-top:14px;">Elegí <strong>Otros…</strong> y buscá un campeonato.</div>';
    if ($otrosTitulo !== '') {
        echo '<div class="cmp-rel-otros-table-wrap"><table class="cmp-table cmp-rel-otros-table"><thead><tr><th class="col-about">Sobre</th><th class="col-date">Fecha</th><th class="col-teams">Equipos</th></tr></thead><tbody>';
        if ($rows === []) echo '<tr><td colspan="3" class="cmp-empty">No hay sobres para este filtro.</td></tr>';
        else foreach ($rows as $row) {
            $barcode = (string)$row['barcode'];
            $titulo = (string)$row['titulo'];
            $fecha = (string)$row['fecha'];
            $equipo1 = (string)$row['equipo1'];
            $equipo2 = (string)$row['equipo2'];
            $isLoaded = !empty($row['is_loaded']);
            $search = trim($titulo . ' ' . $equipo1 . ' ' . $equipo2 . ' ' . $fecha);
            echo '<tr draggable="true" data-otros-row data-right-search="' . cmp_h($search) . '" data-right-search-base="' . cmp_h(trim($titulo . ' ' . $fecha)) . '" data-barcode="' . cmp_h($barcode) . '" data-titulo="' . cmp_h($titulo) . '" data-fecha="' . cmp_h($fecha) . '">';
            echo '<td><div class="cmp-rel-sobre-title">' . cmp_h($titulo) . '</div><div class="cmp-rel-sobre-meta"><span class="cmp-rel-sobre-barcode">' . cmp_h($barcode) . '</span><span class="cmp-rel-sobre-chip">' . ($isLoaded ? 'cargado' : 'pendiente') . '</span><a href="../ver_digital.php?barcode=' . rawurlencode($barcode) . '&i=0" target="_blank" rel="noopener">Ver digital</a></div></td>';
            echo '<td class="cmp-nowrap">' . cmp_h($fecha) . '</td>';
            echo '<td><div class="cmp-rel-team-stack">';
            foreach ([1,2] as $n) {
                $val = $n===1 ? $equipo1 : $equipo2;
                echo '<div class="cmp-rel-team-row"><div class="cmp-rel-team-label">Equipo ' . $n . '</div><div>';
                if ($isLoaded) {
                    echo cmp_h($val) . '<input type="hidden" name="equipo' . $n . '[]" value="' . cmp_h($val) . '">';
                } else {
                    echo '<div class="cmp-rel-team-picker" data-team-picker><input type="hidden" name="equipo' . $n . '[]" value=""><div class="cmp-rel-team-input-wrap"><input type="text" class="cmp-rel-team-input" placeholder="Buscar equipo..." autocomplete="off" data-team-input><button type="button" class="cmp-rel-team-clear" data-team-clear title="Limpiar">×</button></div></div>';
                }
                echo '</div></div>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="cmp-rel-muted" style="margin-top:12px;">En modo <strong>Otros</strong>, los sobres se guardan en <code>partidos</code> recién al asignarlos a un partido.</div>';
    }
    return (string)ob_get_clean();
}