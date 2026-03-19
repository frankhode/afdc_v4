<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';

function cmp_edit_render_tree(array $nodes, int $currentNodeId, int $importId): void {
    if ($nodes === []) {
        echo '<div class="cmp-empty">No hay nodos.</div>';
        return;
    }

    echo '<ul class="cmp-tree cmp-tree-edit">';
    foreach ($nodes as $node) {
        $nodeId = (int)$node['id'];
        $isCurrent = $nodeId === $currentNodeId;
        $type = (string)($node['tipo'] ?? '');
        $subtype = (string)($node['subtipo'] ?? '');
        $count = (int)($node['match_count_total'] ?? 0);
        $manual = (int)($node['is_manual'] ?? 0) === 1;
        $hasChildren = !empty($node['children']);
        $empty = $count === 0 && !$hasChildren;

        echo '<li class="cmp-tree-item' . ($hasChildren ? ' has-children' : '') . '" data-node-id="' . $nodeId . '">';

        echo '<div class="cmp-tree-row">';

        if ($hasChildren) {
            echo '<button type="button" class="cmp-tree-toggle" data-node-id="' . $nodeId . '" aria-expanded="true" title="Expandir/colapsar">';
            echo '<span class="cmp-tree-toggle-symbol">−</span>';
            echo '</button>';
        } else {
            echo '<span class="cmp-tree-toggle-placeholder"></span>';
        }

        echo '<a class="cmp-tree-link' . ($isCurrent ? ' is-current' : '') . '" href="campeonatos_importacion_editar.php?id=' . $importId . '&node_id=' . $nodeId . '#workspace">';
        echo '<span class="cmp-node-type">' . cmp_h($type) . ($subtype !== '' ? ':' . cmp_h($subtype) : '') . '</span>';
        echo '<span class="cmp-node-label">' . cmp_h((string)$node['label']) . '</span>';
        echo '<span class="cmp-node-count">' . $count . '</span>';
        if ($manual) {
            echo '<span class="cmp-chip cmp-chip-manual">manual</span>';
        }
        if ($empty) {
            echo '<span class="cmp-chip cmp-chip-empty">vacío</span>';
        }
        echo '</a>';

        echo '</div>';

        if ($hasChildren) {
            echo '<div class="cmp-tree-children" data-parent-id="' . $nodeId . '">';
            cmp_edit_render_tree($node['children'], $currentNodeId, $importId);
            echo '</div>';
        }

        echo '</li>';
    }
    echo '</ul>';
}

function cmp_edit_node_summary_type(array $node): string {
    $type = (string)($node['tipo'] ?? '');
    $subtype = trim((string)($node['subtipo'] ?? ''));
    return $subtype !== '' ? $type . ':' . $subtype : $type;
}

$id = (int)($_GET['id'] ?? 0);
$currentNodeId = (int)($_GET['node_id'] ?? 0);
$editMatchId = (int)($_GET['edit_match'] ?? 0);
$moveMatchId = (int)($_GET['move_match'] ?? 0);
$editGoalsMatchId = (int)($_GET['edit_goals'] ?? 0);
$error = (string)($_GET['error'] ?? '');
$message = (string)($_GET['msg'] ?? '');

$import = null;
$tree = [];
$nodesFlat = [];
$matchesFlat = [];
$currentNode = null;
$childNodes = [];
$currentMatches = [];
$destinationDates = [];
$editingMatch = null;
$movingMatch = null;
$editingGoalsMatch = null;
$editingGoalEvents = [];
$editingGoalCounts = [
    'local' => 0,
    'visitante' => 0,
    'desconocido' => 0,
    'total' => 0,
];
$breadcrumbs = [];

try {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }

    $import = cmp_edit_get_import($id);
    if (!$import) {
        throw new RuntimeException('La importación no existe.');
    }

    $nodesFlat = cmp_edit_get_nodes_flat($id);
    $matchesFlat = cmp_edit_get_matches_flat($id);
    $tree = cmp_edit_build_tree($nodesFlat, $matchesFlat);

    // No autoseleccionamos nodo al entrar.
    $currentNode = $currentNodeId > 0 ? cmp_edit_find_node_in_tree($tree, $currentNodeId) : null;

    // Si llega un node_id inválido, limpiamos el estado de selección.
    if ($currentNodeId > 0 && $currentNode === null) {
        $currentNodeId = 0;
    }

    if ($currentNode !== null) {
        $childNodes = cmp_edit_get_child_nodes($currentNode);
        $includeDescendants = (string)($currentNode['tipo'] ?? '') !== 'fecha';
        $currentMatches = cmp_edit_get_matches_for_node($currentNode, $matchesFlat, $includeDescendants);
        $breadcrumbs = cmp_edit_build_breadcrumbs($nodesFlat, $currentNodeId);
        $destinationDates = cmp_edit_list_destination_dates(
            $tree,
            (string)($currentNode['tipo'] ?? '') === 'fecha' ? $currentNodeId : 0
        );
    }

    if ($editMatchId > 0) {
        $editingMatch = cmp_edit_get_match_row($editMatchId);
    }
    if ($moveMatchId > 0) {
        $movingMatch = cmp_edit_get_match_row($moveMatchId);
    }
    if ($editGoalsMatchId > 0) {
      $editingGoalsMatch = cmp_edit_get_match_row($editGoalsMatchId);
      if ($editingGoalsMatch) {
          $editingGoalEvents = cmp_edit_decode_goal_events($editingGoalsMatch);
          if ($editingGoalEvents === []) {
              $editingGoalEvents[] = [
                  'player_raw' => '',
                  'minute' => null,
                  'team_side' => 'desconocido',
              ];
          }
          $editingGoalCounts = cmp_edit_goal_event_counts($editingGoalEvents);
      }
  }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$hasSelectedNode = $currentNode !== null;

cmp_render_header('Editar estructura de importación');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<link rel="stylesheet" href="../assets/css/campeonatos_editor_columns.css">
<style>
.cmp-goals-editor {
    display: grid;
    gap: 12px;
}
.cmp-goals-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.cmp-goals-warning {
    padding: 10px 12px;
    border: 1px solid #d6a700;
    background: #fff7d6;
    border-radius: 8px;
    font-size: 0.95rem;
}
.cmp-goal-rows {
    display: grid;
    gap: 10px;
}
.cmp-goal-row {
    display: grid;
    grid-template-columns: minmax(220px, 1.6fr) 110px 150px auto;
    gap: 10px;
    align-items: end;
}
.cmp-goal-row-template {
    display: none;
}
.cmp-goal-row .cmp-btn {
    white-space: nowrap;
}
@media (max-width: 980px) {
    .cmp-goal-row {
        grid-template-columns: 1fr;
    }
}
</style>
<section class="cmp-wrap cmp-wrap-editor">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_importaciones.php">Importaciones</a>
    <span>/</span>
    <a href="campeonatos_importacion.php?id=<?= (int)$id ?>">Ver importación</a>
    <span>/</span>
    <strong>Editar estructura</strong>
  </nav>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <?php if ($message !== ''): ?>
    <div class="cmp-alert cmp-alert-ok"><?= cmp_h($message) ?></div>
  <?php endif; ?>

  <?php if ($import): ?>
    <div class="cmp-pagehead cmp-pagehead-editor">
      <div>
        <p class="cmp-kicker">Importación #<?= (int)$import['id'] ?></p>
        <h2><?= cmp_h((string)$import['titulo_fuente']) ?></h2>
        <p class="cmp-meta">Mini-hito 2 · edición clásica de staging</p>
      </div>

      <div class="cmp-actions cmp-actions-inline">
        <a href="campeonatos_importacion.php?id=<?= (int)$import['id'] ?>" class="cmp-btn">Volver a importación</a>
        <a href="campeonatos_importacion_editar.php?id=<?= (int)$import['id'] ?><?= $currentNodeId > 0 ? '&node_id=' . (int)$currentNodeId : '' ?>#workspace" class="cmp-btn cmp-btn-primary">Refrescar</a>
      </div>
    </div>

    <div class="cmp-editor-shell <?= $hasSelectedNode ? 'is-node-mode' : 'is-tree-mode' ?>" id="cmpEditorShell">
      <div class="cmp-editor-toolbar">
        <button type="button" class="cmp-btn" id="cmpShowTreeBtn" <?= !$hasSelectedNode ? 'hidden' : '' ?>>
          Volver al árbol
        </button>

        <?php if ($currentNode): ?>
          <button type="button" class="cmp-btn cmp-btn-primary" id="cmpShowNodeBtn" hidden>
            Ver nodo seleccionado
          </button>
        <?php endif; ?>
      </div>

      <div id="workspace" class="cmp-grid-edit">
        <aside class="cmp-card cmp-edit-panel cmp-edit-panel--tree" id="cmpTreePanel">
          <div class="cmp-pane-head cmp-pane-head--with-actions">
            <h3>Árbol editable</h3>

            <div class="cmp-tree-head-actions">
              <button type="button" class="cmp-btn cmp-btn-sm" id="cmpExpandAll">Expandir todo</button>
              <button type="button" class="cmp-btn cmp-btn-sm" id="cmpCollapseAll">Colapsar todo</button>
            </div>
          </div>
          <?php cmp_edit_render_tree($tree, $currentNodeId, (int)$id); ?>
        </aside>

        <div class="cmp-node-panels" id="cmpNodePanels">
          <main class="cmp-card cmp-edit-panel cmp-edit-panel--detail">
            <?php if ($currentNode): ?>
              <div class="cmp-pane-head">
                <div>
                  <p class="cmp-kicker">Nodo seleccionado</p>
                  <h3><?= cmp_h((string)$currentNode['label']) ?></h3>
                  <p class="cmp-meta">
                    <?= cmp_h(cmp_edit_node_summary_type($currentNode)) ?>
                    · Partidos debajo: <?= (int)($currentNode['match_count_total'] ?? 0) ?>
                  </p>
                </div>
              </div>

              <?php if ($breadcrumbs): ?>
                <div class="cmp-breadcrumbs cmp-breadcrumbs-inline cmp-node-breadcrumb">
                  <?php foreach ($breadcrumbs as $idx => $crumb): ?>
                    <?php if ($idx > 0): ?><span>/</span><?php endif; ?>
                    <a href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$crumb['id'] ?>#workspace">
                      <?= cmp_h((string)$crumb['label']) ?>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="cmp-grid-node-meta">
                <div><span class="cmp-muted">ID</span><strong><?= (int)$currentNode['id'] ?></strong></div>
                <div><span class="cmp-muted">Tipo</span><strong><?= cmp_h((string)$currentNode['tipo']) ?></strong></div>
                <div><span class="cmp-muted">Subtipo</span><strong><?= cmp_h((string)($currentNode['subtipo'] ?? '')) ?></strong></div>
                <div><span class="cmp-muted">Orden</span><strong><?= (int)($currentNode['orden'] ?? 0) ?></strong></div>
              </div>

              <?php if ($childNodes !== []): ?>
                <section class="cmp-subsection">
                  <h4>Hijos del nodo</h4>
                  <div class="cmp-table-wrap">
                    <table class="cmp-table">
                      <thead>
                        <tr>
                          <th>Tipo</th>
                          <th>Label</th>
                          <th>Partidos</th>
                          <th></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($childNodes as $child): ?>
                          <tr>
                            <td><?= cmp_h(cmp_edit_node_summary_type($child)) ?></td>
                            <td><?= cmp_h((string)$child['label']) ?></td>
                            <td><?= (int)($child['match_count_total'] ?? 0) ?></td>
                            <td>
                              <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$child['id'] ?>#workspace">
                                Abrir
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </section>
              <?php endif; ?>

              <section class="cmp-subsection">
                <h4><?= (string)($currentNode['tipo'] ?? '') === 'fecha' ? 'Partidos de esta fecha' : 'Partidos debajo de este nodo' ?></h4>
                <div class="cmp-table-wrap">
                  <table class="cmp-table">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Contenedor</th>
                        <th>Local</th>
                        <th>GL</th>
                        <th>GV</th>
                        <th>Visitante</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if ($currentMatches === []): ?>
                        <tr>
                          <td colspan="8" class="cmp-empty">No hay partidos para este nodo.</td>
                        </tr>
                      <?php else: ?>
                        <?php foreach ($currentMatches as $match): ?>
                          <tr class="<?= (($match['estado'] ?? 'activo') === 'ignorado') ? 'cmp-row-muted' : '' ?>">
                            <td><?= (int)$match['id'] ?></td>
                            <td><?= cmp_h((string)$match['nodo_label']) ?></td>
                            <td><?= cmp_h((string)$match['local_texto']) ?></td>
                            <td><?= cmp_h((string)$match['goles_local']) ?></td>
                            <td><?= cmp_h((string)$match['goles_visitante']) ?></td>
                            <td><?= cmp_h((string)$match['visitante_texto']) ?></td>
                            <td>
                              <span class="cmp-chip <?= (($match['estado'] ?? 'activo') === 'ignorado') ? 'cmp-chip-empty' : 'cmp-chip-manual' ?>">
                                <?= cmp_h((string)($match['estado'] ?? 'activo')) ?>
                              </span>
                            </td>
                            <td class="cmp-nowrap">
                              <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>&edit_match=<?= (int)$match['id'] ?>#workspace">
                                    Editar
                                </a>
                                <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>&edit_goals=<?= (int)$match['id'] ?>#workspace">
                                    Goleadores
                                </a>
                                <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>&move_match=<?= (int)$match['id'] ?>#workspace">
                                    Mover
                                </a>

                              <?php if (($match['estado'] ?? 'activo') !== 'ignorado'): ?>
                                <form method="post" action="campeonatos_importacion_accion.php" class="cmp-inline-form">
                                  <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                                  <input type="hidden" name="action" value="ignore_match">
                                  <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                                  <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                                  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                  <button type="submit" class="cmp-btn cmp-btn-sm">Ignorar</button>
                                </form>
                              <?php else: ?>
                                <form method="post" action="campeonatos_importacion_accion.php" class="cmp-inline-form">
                                  <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                                  <input type="hidden" name="action" value="restore_match">
                                  <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                                  <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                                  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                                  <button type="submit" class="cmp-btn cmp-btn-sm">Restaurar</button>
                                </form>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </section>
            <?php else: ?>
              <div class="cmp-empty">Seleccioná un nodo del árbol para ver su información y acciones.</div>
            <?php endif; ?>
          </main>

          <aside class="cmp-card cmp-edit-panel cmp-edit-panel--actions">
            <?php if ($currentNode): ?>
              <div class="cmp-pane-head">
                <h3>Acciones</h3>
              </div>

              <div class="cmp-actions-stack">
                <?php if (($currentNode['tipo'] ?? '') !== 'temporada'): ?>
                  <section class="cmp-subsection">
                    <h4>Editar nodo</h4>
                    <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
                      <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                      <input type="hidden" name="action" value="update_node">
                      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="node_id" value="<?= (int)$currentNode['id'] ?>">

                      <label>Label
                        <input type="text" name="label" value="<?= cmp_h((string)$currentNode['label']) ?>">
                      </label>

                      <label>Subtipo
                        <input type="text" name="subtype" value="<?= cmp_h((string)($currentNode['subtipo'] ?? '')) ?>" placeholder="regular, ida, vuelta...">
                      </label>

                      <label>Orden
                        <input type="number" name="sort_order" value="<?= (int)($currentNode['orden'] ?? 0) ?>" min="0">
                      </label>

                      <button type="submit" class="cmp-btn cmp-btn-primary">Guardar nodo</button>
                    </form>
                  </section>
                <?php endif; ?>

                <?php $allowedChildTypes = cmp_edit_allowed_child_types((string)($currentNode['tipo'] ?? '')); ?>
                <?php if ($allowedChildTypes !== []): ?>
                  <section class="cmp-subsection">
                    <h4>Crear nodo hijo</h4>
                    <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
                      <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                      <input type="hidden" name="action" value="create_node">
                      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                      <input type="hidden" name="parent_node_id" value="<?= (int)$currentNode['id'] ?>">

                      <label>Tipo
                        <select name="type">
                          <?php foreach ($allowedChildTypes as $type): ?>
                            <option value="<?= cmp_h($type) ?>"><?= cmp_h($type) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>

                      <label>Label
                        <input type="text" name="label" placeholder="Ej. Serie Independiente vs Talleres">
                      </label>

                      <label>Subtipo
                        <input type="text" name="subtype" placeholder="Ej. ida, vuelta, unica">
                      </label>

                      <label>Orden
                        <input type="number" name="sort_order" min="0" placeholder="auto">
                      </label>

                      <button type="submit" class="cmp-btn cmp-btn-primary">Crear nodo</button>
                    </form>
                  </section>
                <?php endif; ?>

                <?php if (empty($currentNode['children']) && (int)($currentNode['match_count_direct'] ?? 0) === 0 && ($currentNode['tipo'] ?? '') !== 'temporada'): ?>
                  <section class="cmp-subsection">
                    <h4>Nodo vacío</h4>
                    <form method="post" action="campeonatos_importacion_accion.php">
                      <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>#workspace">
                      <input type="hidden" name="action" value="delete_empty_node">
                      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="node_id" value="<?= (int)$currentNode['id'] ?>">
                      <input type="hidden" name="parent_id" value="<?= (int)($currentNode['parent_id'] ?? 0) ?>">
                      <button type="submit" class="cmp-btn">Eliminar nodo vacío</button>
                    </form>
                  </section>
                <?php endif; ?>

                <?php if ($editingMatch): ?>
                  <section class="cmp-subsection">
                    <h4>Editar partido #<?= (int)$editingMatch['id'] ?></h4>
                    <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
                      <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                      <input type="hidden" name="action" value="update_match">
                      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                      <input type="hidden" name="match_id" value="<?= (int)$editingMatch['id'] ?>">

                      <label>Local
                        <input type="text" name="local_texto" value="<?= cmp_h((string)$editingMatch['local_texto']) ?>">
                      </label>

                      <div class="cmp-grid-scores">
                        <label>GL
                          <input type="number" name="goles_local" value="<?= cmp_h((string)$editingMatch['goles_local']) ?>">
                        </label>
                        <label>GV
                          <input type="number" name="goles_visitante" value="<?= cmp_h((string)$editingMatch['goles_visitante']) ?>">
                        </label>
                      </div>

                      <label>Visitante
                        <input type="text" name="visitante_texto" value="<?= cmp_h((string)$editingMatch['visitante_texto']) ?>">
                      </label>

                      <label>Observación manual
                        <textarea name="observacion_manual" rows="3"><?= cmp_h((string)($editingMatch['observacion_manual'] ?? '')) ?></textarea>
                      </label>

                      <label>Línea fuente
                        <textarea rows="3" readonly><?= cmp_h((string)$editingMatch['fuente_linea']) ?></textarea>
                      </label>

                      <div class="cmp-actions">
                        <button type="submit" class="cmp-btn cmp-btn-primary">Guardar partido</button>
                        <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
                      </div>
                    </form>
                  </section>
                <?php endif; ?>
                <?php if ($editingGoalsMatch): ?>
                <section class="cmp-subsection">
                    <h4>Editar goleadores del partido #<?= (int)$editingGoalsMatch['id'] ?></h4>

                    <p class="cmp-meta">
                        <?= cmp_h((string)$editingGoalsMatch['local_texto']) ?>
                        <?= cmp_h((string)$editingGoalsMatch['goles_local']) ?>-<?= cmp_h((string)$editingGoalsMatch['goles_visitante']) ?>
                        <?= cmp_h((string)$editingGoalsMatch['visitante_texto']) ?>
                    </p>

                    <?php
                    $expectedLocal = $editingGoalsMatch['goles_local'] !== null ? (int)$editingGoalsMatch['goles_local'] : null;
                    $expectedAway = $editingGoalsMatch['goles_visitante'] !== null ? (int)$editingGoalsMatch['goles_visitante'] : null;
                    $hasScoreMismatch = (
                        $expectedLocal !== null && $expectedAway !== null &&
                        (
                            $editingGoalCounts['local'] !== $expectedLocal ||
                            $editingGoalCounts['visitante'] !== $expectedAway
                        )
                    );
                    ?>

                    <div class="cmp-goals-editor">
                        <div class="cmp-goals-summary">
                            <span class="cmp-chip cmp-chip-manual">local: <?= (int)$editingGoalCounts['local'] ?></span>
                            <span class="cmp-chip cmp-chip-manual">visitante: <?= (int)$editingGoalCounts['visitante'] ?></span>
                            <span class="cmp-chip cmp-chip-empty">desconocido: <?= (int)$editingGoalCounts['desconocido'] ?></span>
                            <span class="cmp-chip">total: <?= (int)$editingGoalCounts['total'] ?></span>
                        </div>

                        <?php if ($hasScoreMismatch): ?>
                            <div class="cmp-goals-warning">
                                La cuenta de goleadores por lado no coincide con el marcador actual
                                (esperado: <?= (int)$expectedLocal ?> local / <?= (int)$expectedAway ?> visitante).
                                Se puede guardar igual.
                            </div>
                        <?php endif; ?>

                        <form method="post" action="campeonatos_importacion_accion.php">
                            <input type="hidden" name="action" value="update_goal_events">
                            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                            <input type="hidden" name="match_id" value="<?= (int)$editingGoalsMatch['id'] ?>">

                            <div class="cmp-goal-rows" id="cmpGoalRows">
                                <?php foreach ($editingGoalEvents as $idx => $goal): ?>
                                    <div class="cmp-goal-row">
                                        <label>
                                            Jugador
                                            <input type="text" name="goal_player_raw[]" value="<?= cmp_h((string)($goal['player_raw'] ?? '')) ?>">
                                        </label>

                                        <label>
                                            Minuto
                                            <input type="number" name="goal_minute[]" min="0" value="<?= cmp_h((string)($goal['minute'] ?? '')) ?>">
                                        </label>

                                        <label>
                                            Lado
                                            <select name="goal_team_side[]">
                                                <?php $side = (string)($goal['team_side'] ?? 'desconocido'); ?>
                                                <option value="local" <?= $side === 'local' ? 'selected' : '' ?>>local</option>
                                                <option value="visitante" <?= $side === 'visitante' ? 'selected' : '' ?>>visitante</option>
                                                <option value="desconocido" <?= $side === 'desconocido' ? 'selected' : '' ?>>desconocido</option>
                                            </select>
                                        </label>

                                        <button type="button" class="cmp-btn cmp-btn-sm cmp-goal-remove">Quitar</button>
                                    </div>
                                <?php endforeach; ?>

                                <div class="cmp-goal-row cmp-goal-row-template" id="cmpGoalRowTemplate">
                                    <label>
                                        Jugador
                                        <input type="text" name="goal_player_raw[]" value="">
                                    </label>

                                    <label>
                                        Minuto
                                        <input type="number" name="goal_minute[]" min="0" value="">
                                    </label>

                                    <label>
                                        Lado
                                        <select name="goal_team_side[]">
                                            <option value="local">local</option>
                                            <option value="visitante">visitante</option>
                                            <option value="desconocido" selected>desconocido</option>
                                        </select>
                                    </label>

                                    <button type="button" class="cmp-btn cmp-btn-sm cmp-goal-remove">Quitar</button>
                                </div>
                            </div>

                            <div class="cmp-actions">
                                <button type="button" class="cmp-btn" id="cmpAddGoalRow">Agregar gol</button>
                                <button type="submit" class="cmp-btn cmp-btn-primary">Guardar goleadores</button>
                                <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
                            </div>
                        </form>
                    </div>
                </section>
                <?php endif; ?>
                <?php if ($movingMatch): ?>
                  <section class="cmp-subsection">
                    <h4>Mover partido #<?= (int)$movingMatch['id'] ?></h4>
                    <p class="cmp-meta">
                      <?= cmp_h((string)$movingMatch['local_texto']) ?>
                      <?= cmp_h((string)$movingMatch['goles_local']) ?>-<?= cmp_h((string)$movingMatch['goles_visitante']) ?>
                      <?= cmp_h((string)$movingMatch['visitante_texto']) ?>
                    </p>

                    <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
                      <input type="hidden" name="return_to" value="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">
                      <input type="hidden" name="action" value="move_match">
                      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                      <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                      <input type="hidden" name="match_id" value="<?= (int)$movingMatch['id'] ?>">

                      <label>Fecha destino
                        <select name="target_node_id">
                          <?php foreach ($destinationDates as $dest): ?>
                            <option value="<?= (int)$dest['id'] ?>"><?= cmp_h((string)$dest['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>

                      <div class="cmp-actions">
                        <button type="submit" class="cmp-btn cmp-btn-primary">Mover partido</button>
                        <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
                      </div>
                    </form>
                  </section>
                <?php endif; ?>

                <?php if (!$editingMatch && !$movingMatch && !$editingGoalsMatch): ?>
                  <section class="cmp-subsection">
                    <h4>Sugerencia de uso</h4>
                    <p class="cmp-meta">
                      Para resolver una final ida/vuelta mal agrupada: creá una <strong>serie</strong>,
                      después creá dos <strong>fechas</strong> hijas (<em>Ida</em> y <em>Vuelta</em>)
                      y finalmente mové cada partido a su contenedor correcto.
                    </p>
                  </section>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <div class="cmp-empty">No hay acciones disponibles hasta seleccionar un nodo.</div>
            <?php endif; ?>
          </aside>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const shell = document.getElementById('cmpEditorShell');
  const showTreeBtn = document.getElementById('cmpShowTreeBtn');

  if (!shell) return;

  document.querySelectorAll('.cmp-tree-link').forEach(function (link) {
    link.addEventListener('click', function () {
      shell.classList.remove('is-tree-mode');
      shell.classList.add('is-node-mode');
    });
  });

  if (showTreeBtn) {
    showTreeBtn.addEventListener('click', function () {
      shell.classList.remove('is-node-mode');
      shell.classList.add('is-tree-mode');

      const url = new URL(window.location.href);
      url.searchParams.delete('node_id');
      url.searchParams.delete('edit_match');
      url.searchParams.delete('move_match');
      url.searchParams.delete('edit_goals');
      url.hash = 'workspace';
      window.history.replaceState({}, '', url.toString());

      window.location.href = url.toString();
    });
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const shell = document.getElementById('cmpEditorShell');
  const showTreeBtn = document.getElementById('cmpShowTreeBtn');
  const STORAGE_KEY = 'cmpTreeCollapsedNodes';

  const expandAllBtn = document.getElementById('cmpExpandAll');
  const collapseAllBtn = document.getElementById('cmpCollapseAll');

  if (expandAllBtn) {
    expandAllBtn.addEventListener('click', function () {
      const collapsed = new Set();
      saveCollapsedSet(collapsed);
      applyCollapsedState();
    });
  }

  if (collapseAllBtn) {
    collapseAllBtn.addEventListener('click', function () {
      const collapsed = new Set();

      document.querySelectorAll('.cmp-tree-item.has-children').forEach(function (item) {
        const nodeId = String(item.dataset.nodeId || '');
        if (nodeId) collapsed.add(nodeId);
      });

      saveCollapsedSet(collapsed);
      applyCollapsedState();
    });
  }

  function loadCollapsedSet() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      const arr = raw ? JSON.parse(raw) : [];
      return new Set(Array.isArray(arr) ? arr.map(String) : []);
    } catch (e) {
      return new Set();
    }
  }

  function saveCollapsedSet(set) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(set)));
  }

  function applyCollapsedState() {
    const collapsed = loadCollapsedSet();

    document.querySelectorAll('.cmp-tree-item.has-children').forEach(function (item) {
      const nodeId = String(item.dataset.nodeId || '');
      const toggle = item.querySelector('.cmp-tree-toggle');

      if (!nodeId || !toggle) return;

      const isCollapsed = collapsed.has(nodeId);
      item.classList.toggle('is-collapsed', isCollapsed);
      toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
    });
  }

  function expandAncestorsOfCurrentNode() {
    const currentLink = document.querySelector('.cmp-tree-link.is-current');
    if (!currentLink) return;

    const collapsed = loadCollapsedSet();
    let item = currentLink.closest('.cmp-tree-item');

    while (item) {
      const nodeId = String(item.dataset.nodeId || '');
      if (nodeId) {
        collapsed.delete(nodeId);
      }

      const parentChildren = item.parentElement.closest('.cmp-tree-children');
      item = parentChildren ? parentChildren.closest('.cmp-tree-item') : null;
    }

    saveCollapsedSet(collapsed);
    applyCollapsedState();
  }

  document.querySelectorAll('.cmp-tree-toggle').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();

      const nodeId = String(btn.dataset.nodeId || '');
      const item = btn.closest('.cmp-tree-item');
      if (!nodeId || !item) return;

      const collapsed = loadCollapsedSet();
      const willCollapse = !item.classList.contains('is-collapsed');

      if (willCollapse) {
        collapsed.add(nodeId);
      } else {
        collapsed.delete(nodeId);
      }

      saveCollapsedSet(collapsed);
      applyCollapsedState();
    });
  });

  applyCollapsedState();
  expandAncestorsOfCurrentNode();

  if (shell) {
    document.querySelectorAll('.cmp-tree-link').forEach(function (link) {
      link.addEventListener('click', function () {
        shell.classList.remove('is-tree-mode');
        shell.classList.add('is-node-mode');
      });
    });
  }

  if (showTreeBtn) {
    showTreeBtn.addEventListener('click', function () {
      if (shell) {
        shell.classList.remove('is-node-mode');
        shell.classList.add('is-tree-mode');
      }

      const url = new URL(window.location.href);
      url.searchParams.delete('node_id');
      url.searchParams.delete('edit_match');
      url.searchParams.delete('move_match');
      url.searchParams.delete('edit_goals');
      url.hash = 'workspace';
      window.location.href = url.toString();
    });
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rows = document.getElementById('cmpGoalRows');
    const template = document.getElementById('cmpGoalRowTemplate');
    const addBtn = document.getElementById('cmpAddGoalRow');

    if (!rows || !template || !addBtn) return;

    function bindRemoveButtons(scope) {
        scope.querySelectorAll('.cmp-goal-remove').forEach(function (btn) {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', function () {
                const row = btn.closest('.cmp-goal-row');
                if (!row) return;
                if (row.id === 'cmpGoalRowTemplate') return;

                const liveRows = rows.querySelectorAll('.cmp-goal-row:not(.cmp-goal-row-template)');
                if (liveRows.length <= 1) {
                    row.querySelectorAll('input').forEach(function (input) {
                        input.value = '';
                    });
                    row.querySelectorAll('select').forEach(function (select) {
                        select.value = 'desconocido';
                    });
                    return;
                }

                row.remove();
            });
        });
    }

    addBtn.addEventListener('click', function () {
        const clone = template.cloneNode(true);
        clone.removeAttribute('id');
        clone.classList.remove('cmp-goal-row-template');
        rows.appendChild(clone);
        bindRemoveButtons(clone);
    });

    bindRemoveButtons(rows);
});
</script>
<?php cmp_render_footer(); ?>