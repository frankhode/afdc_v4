<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';

function cmp_edit_render_tree(array $nodes, int $currentNodeId, int $importId): void {
    if ($nodes === []) {
        echo '<div class="cmp-empty">No hay nodos.</div>';
        return;
    }

    echo '<ul class="cmp-tree">';
    foreach ($nodes as $node) {
        $nodeId = (int)$node['id'];
        $isCurrent = $nodeId === $currentNodeId;
        $type = (string)($node['tipo'] ?? '');
        $subtype = (string)($node['subtipo'] ?? '');
        $count = (int)($node['match_count_total'] ?? 0);
        $manual = (int)($node['is_manual'] ?? 0) === 1;
        $hasChildren = !empty($node['children']);
        $empty = $count === 0 && !$hasChildren;

        echo '<li class="cmp-tree-item">';
        echo '<div class="cmp-tree-row">';
        if ($hasChildren) {
            echo '<button type="button" class="cmp-tree-toggle" data-tree-toggle aria-expanded="true">−</button>';
        } else {
            echo '<span class="cmp-tree-toggle-spacer"></span>';
        }

        $classes = ['cmp-tree-link'];
        if ($isCurrent) {
            $classes[] = 'is-current';
        }

        $fullType = $type . ($subtype !== '' ? ':' . $subtype : '');
        $fullLabel = (string)$node['label'];
        $fullTitle = trim($fullType . ' ' . $fullLabel);

        echo '<a class="' . implode(' ', $classes) . '" href="campeonatos_importacion_editar.php?id=' . $importId . '&node_id=' . $nodeId . '#workspace" title="' . cmp_h($fullTitle) . '">';
        echo '<span class="cmp-tree-type" title="' . cmp_h($fullType) . '">' . cmp_h($fullType) . '</span>';
        echo '<span class="cmp-tree-label" title="' . cmp_h($fullLabel) . '">' . cmp_h($fullLabel) . '</span>';
        echo '<span class="cmp-chip">' . $count . '</span>';
        if ($manual) {
            echo ' <span class="cmp-chip cmp-chip-manual">manual</span>';
        }
        if ($empty) {
            echo ' <span class="cmp-chip cmp-chip-empty">vacío</span>';
        }
        echo '</a>';
        echo '</div>';

        if ($hasChildren) {
            echo '<div class="cmp-tree-children">';
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

$linkFilter = (string)($_GET['link_filter'] ?? 'all');

$id = (int)($_GET['id'] ?? 0);
$currentNodeId = (int)($_GET['node_id'] ?? 0);

$editMatchId = (int)($_GET['edit_match'] ?? 0);
$moveMatchId = (int)($_GET['move_match'] ?? 0);
$editGoalsMatchId = (int)($_GET['edit_goals'] ?? 0);

$editNodeMode = ((string)($_GET['node_action'] ?? '') === 'edit');
$createNodeMode = ((string)($_GET['node_action'] ?? '') === 'create');
$deleteNodeMode = ((string)($_GET['node_action'] ?? '') === 'delete');

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
$allowedChildTypes = [];
$canDeleteEmptyNode = false;

$tituloRegOptions = [];
$selectedTituloReg = '';

$otrosSys = '';
$otrosTitulo = '';
$otrosFilter = 'pending';
$otrosRows = [];
$teamOptions = [];
$otrosRegistro = null;
$isOtrosMode = false;

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

    $currentNode = $currentNodeId > 0 ? cmp_edit_find_node_in_tree($tree, $currentNodeId) : null;

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

        $allowedChildTypes = cmp_edit_allowed_child_types((string)$currentNode['tipo']);
        $canDeleteEmptyNode = (
            ((int)($currentNode['match_count_total'] ?? 0) === 0) &&
            empty($currentNode['children']) &&
            ((string)($currentNode['tipo'] ?? '') !== 'temporada')
        );
    }

        $tituloRegOptions = cmp_edit_get_distinct_tituloreg_options();
        $selectedTituloReg = trim((string)($_GET['tituloReg'] ?? ''));

        $otrosSys = trim((string)($_GET['otros_sys'] ?? ''));
        $otrosTitulo = trim((string)($_GET['otros_titulo'] ?? ''));
        $otrosFilter = trim((string)($_GET['otros_filter'] ?? 'pending'));
        if (!in_array($otrosFilter, ['pending', 'loaded', 'all'], true)) {
            $otrosFilter = 'pending';
        }

        $isOtrosMode = ($selectedTituloReg === '__otros__');

        if ($isOtrosMode) {
            $teamOptions = cmp_edit_get_distinct_team_options();

            if ($otrosSys !== '') {
                $otrosRegistro = cmp_edit_get_registro_by_sys($otrosSys);
                if ($otrosRegistro) {
                    if ($otrosTitulo === '') {
                        $otrosTitulo = (string)$otrosRegistro['titulo245'];
                    }
                    if ($otrosTitulo !== '') {
                        $otrosRows = cmp_edit_get_otros_sobres_by_sys($otrosSys, $otrosTitulo, $otrosFilter);
                    }
                }
            }
        }

        if ($currentNode !== null) {
            $currentMatches = cmp_edit_attach_match_link_summary($currentMatches);
            $currentMatches = cmp_edit_filter_matches_by_link_status($currentMatches, $linkFilter);
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

$mainClass = 'container-fluid';
cmp_render_header('Editar estructura de importación', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<link rel="stylesheet" href="../assets/css/campeonatos_editor_columns.css">

<div class="cmp-page-head">
    <div>
        <div class="cmp-kicker">Importación #<?= (int)$id ?></div>
        <h1><?= cmp_h((string)($import['titulo'] ?? 'Importación')) ?></h1>
        <div class="cmp-meta">Mini-hito 2 · edición clásica de staging</div>
    </div>
    <div class="cmp-page-actions">
        <a class="btn btn-outline-secondary" href="campeonatos_importacion.php?id=<?= $id ?>">Volver a importación</a>
        <a class="btn btn-outline-secondary" href="campeonatos_relacion_sobres.php?id=<?= $id ?>">Relacionar sobres</a>
        <a class="btn btn-primary" href="campeonatos_importacion_editar.php?id=<?= $id ?>">Refrescar</a>
    </div>
</div>

<?php if ($message !== ''): ?>
    <div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
<?php endif; ?>

<div class="cmp-editor-toolbar">
    <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>#workspace">Volver al árbol</a>
    <?php if ($hasSelectedNode): ?>
        <a class="cmp-btn cmp-btn-primary" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Ver nodo seleccionado</a>
    <?php endif; ?>
</div>

<div class="cmp-editor-shell cmp-editor-shell--two-cols" id="workspace">
    <aside class="cmp-editor-tree">
        <div class="cmp-card">
            <div class="cmp-card-head">
                <h3>Árbol editable</h3>
                <div class="cmp-card-actions">
                    <button type="button" class="cmp-btn cmp-btn-sm" data-tree-expand>Expandir todo</button>
                    <button type="button" class="cmp-btn cmp-btn-sm" data-tree-collapse>Colapsar todo</button>
                </div>
            </div>
            <?php cmp_edit_render_tree($tree, $currentNodeId, $id); ?>
        </div>
    </aside>

    <section class="cmp-editor-main">
        <div class="cmp-card cmp-panel-main">
            <?php if ($hasSelectedNode): ?>
                <div class="cmp-main-head">
                    <div>
                        <div class="cmp-breadcrumbs">
                            <?php foreach ($breadcrumbs as $idx => $crumb): ?>
                                <?php if ($idx > 0): ?> / <?php endif; ?>
                                <a href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$crumb['id'] ?>#workspace">
                                    <?= cmp_h((string)$crumb['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cmp-main-actions">
                        <select class="cmp-action-select cmp-action-select--node"
                                data-node-action
                                data-base-url="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>">
                            <option value="">Acciones del nodo…</option>
                            <option value="node_action=edit">Editar nodo</option>
                            <?php if ($allowedChildTypes !== []): ?>
                                <option value="node_action=create">Crear nodo hijo</option>
                            <?php endif; ?>
                            <?php if ($canDeleteEmptyNode): ?>
                                <option value="node_action=delete">Eliminar nodo vacío</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="cmp-grid cmp-grid-4">
                    <div class="cmp-stat"><div class="cmp-stat-label">ID</div><div class="cmp-stat-value"><?= (int)$currentNode['id'] ?></div></div>
                    <div class="cmp-stat"><div class="cmp-stat-label">Tipo</div><div class="cmp-stat-value"><?= cmp_h((string)$currentNode['tipo']) ?></div></div>
                    <div class="cmp-stat"><div class="cmp-stat-label">Subtipo</div><div class="cmp-stat-value"><?= cmp_h((string)($currentNode['subtipo'] ?? '')) ?></div></div>
                    <div class="cmp-stat"><div class="cmp-stat-label">Orden</div><div class="cmp-stat-value"><?= (int)($currentNode['orden'] ?? 0) ?></div></div>
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
                                        <td class="cmp-nowrap">
                                            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$child['id'] ?>#workspace">Abrir</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
                <?php if ($hasSelectedNode): ?>
<section class="cmp-subsection">
  <h4><?= $isOtrosMode ? 'Carga manual de sobres a partidos' : 'Cruce con partidos validados' ?></h4>

  <?php
  $baseWorkspaceUrl = 'campeonatos_importacion_editar.php?id=' . (int)$id . '&node_id=' . (int)$currentNodeId;
  if ($selectedTituloReg !== '') {
      $baseWorkspaceUrl .= '&tituloReg=' . urlencode($selectedTituloReg);
  }
  if ($isOtrosMode && $otrosSys !== '') {
      $baseWorkspaceUrl .= '&otros_sys=' . urlencode($otrosSys);
  }
  if ($isOtrosMode && $otrosTitulo !== '') {
      $baseWorkspaceUrl .= '&otros_titulo=' . urlencode($otrosTitulo);
  }
  if ($isOtrosMode && $otrosFilter !== '') {
      $baseWorkspaceUrl .= '&otros_filter=' . urlencode($otrosFilter);
  }
  ?>

  <form method="get" action="campeonatos_importacion_editar.php" class="cmp-stack-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end;">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">

    <div>
      <label for="tituloReg">tituloReg</label><br>
      <select name="tituloReg" id="tituloReg" required>
        <option value="">Seleccionar…</option>
        <?php foreach ($tituloRegOptions as $opt): ?>
          <option value="<?= cmp_h($opt) ?>" <?= $selectedTituloReg === $opt ? 'selected' : '' ?>>
            <?= cmp_h($opt) ?>
          </option>
        <?php endforeach; ?>
        <option value="__otros__" <?= $isOtrosMode ? 'selected' : '' ?>>Otros…</option>
      </select>
    </div>

    <?php if (!$isOtrosMode): ?>
      <label>
        <input type="checkbox" name="include_descendants" value="1" checked disabled>
        Incluir descendientes
      </label>

      <label>
        <input type="checkbox" name="allow_swapped" value="1" checked disabled>
        Permitir localía invertida
      </label>

      <div>
        <button type="button" class="cmp-btn" id="cmpGoToNormalLinking">Ir al cruce</button>
      </div>
    <?php else: ?>
      <div>
        <button type="button" class="cmp-btn" id="cmpOpenOtrosModal">Buscar campeonato…</button>
      </div>

      <?php if ($otrosTitulo !== ''): ?>
        <div>
          <strong>Seleccionado:</strong><br>
          <span><?= cmp_h($otrosTitulo) ?></span><br>
          <small>sys <?= cmp_h($otrosSys) ?></small>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </form>

  <?php if (!$isOtrosMode): ?>
    <form method="post" action="campeonatos_importacion_accion.php" class="cmp-stack-form" style="display:flex; gap:12px; flex-wrap:wrap; align-items:end; margin-top:12px;">
      <input type="hidden" name="action" value="generate_match_links">
      <input type="hidden" name="import_id" value="<?= (int)$id ?>">
      <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
      <input type="hidden" name="tituloReg" value="<?= cmp_h($selectedTituloReg) ?>">

      <label>
        <input type="checkbox" name="include_descendants" value="1" checked>
        Incluir descendientes
      </label>

      <label>
        <input type="checkbox" name="allow_swapped" value="1" checked>
        Permitir localía invertida
      </label>

      <div>
        <button type="submit" class="cmp-btn" <?= $selectedTituloReg === '' ? 'disabled' : '' ?>>Generar propuestas</button>
      </div>
    </form>

    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
      <?php
      $baseFilterUrl = 'campeonatos_importacion_editar.php?id=' . (int)$id . '&node_id=' . (int)$currentNodeId;
      if ($selectedTituloReg !== '') {
          $baseFilterUrl .= '&tituloReg=' . urlencode($selectedTituloReg);
      }
      ?>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=all#workspace">Todos</a>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=pending#workspace">Pendientes</a>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=unique#workspace">Propuesta única</a>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=ambiguous#workspace">Ambiguos</a>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=none#workspace">Sin evaluar</a>
      <a class="cmp-btn cmp-btn-sm" href="<?= $baseFilterUrl ?>&link_filter=validated#workspace">Validados</a>
    </div>
  <?php else: ?>
    <?php if ($otrosTitulo !== ''): ?>
      <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php
        $baseOtrosFilterUrl = 'campeonatos_importacion_editar.php?id=' . (int)$id
            . '&node_id=' . (int)$currentNodeId
            . '&tituloReg=__otros__'
            . '&otros_sys=' . urlencode($otrosSys)
            . '&otros_titulo=' . urlencode($otrosTitulo);
        ?>
        <a class="cmp-btn cmp-btn-sm" href="<?= $baseOtrosFilterUrl ?>&otros_filter=pending#workspace">Pendientes</a>
        <a class="cmp-btn cmp-btn-sm" href="<?= $baseOtrosFilterUrl ?>&otros_filter=loaded#workspace">Cargados</a>
        <a class="cmp-btn cmp-btn-sm" href="<?= $baseOtrosFilterUrl ?>&otros_filter=all#workspace">Todos</a>
      </div>

      <form method="post" action="campeonatos_importacion_accion.php" style="margin-top:12px;">
        <input type="hidden" name="action" value="save_other_manual_matches">
        <input type="hidden" name="import_id" value="<?= (int)$id ?>">
        <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
        <input type="hidden" name="tituloReg_manual" value="<?= cmp_h($otrosTitulo) ?>">
        <input type="hidden" name="otros_sys" value="<?= cmp_h($otrosSys) ?>">
        <input type="hidden" name="otros_titulo" value="<?= cmp_h($otrosTitulo) ?>">
        <input type="hidden" name="otros_filter" value="<?= cmp_h($otrosFilter) ?>">

        <div class="cmp-table-wrap" style="margin-top:12px;">
          <table class="cmp-table">
            <thead>
              <tr>
                <th>Barcode</th>
                <th>Título</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Equipo 1</th>
                <th>Equipo 2</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($otrosRows === []): ?>
                <tr>
                  <td colspan="6" class="cmp-empty">No hay sobres para mostrar en este filtro.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($otrosRows as $idx => $row): ?>
                  <tr>
                    <td class="cmp-nowrap">
                      <?= cmp_h((string)$row['barcode']) ?>
                      <input type="hidden" name="barcode[]" value="<?= cmp_h((string)$row['barcode']) ?>">
                    </td>
                    <td>
                      <?= cmp_h((string)$row['titulo']) ?>
                      <input type="hidden" name="tituloSobre[]" value="<?= cmp_h((string)$row['titulo']) ?>">
                    </td>
                    <td class="cmp-nowrap">
                      <?= cmp_h((string)$row['fecha']) ?>
                      <input type="hidden" name="fecha[]" value="<?= cmp_h((string)$row['fecha']) ?>">
                    </td>
                    <td class="cmp-nowrap">
                      <?= !empty($row['is_loaded']) ? 'cargado' : 'pendiente' ?>
                    </td>
                    <td>
                      <?php if (!empty($row['is_loaded'])): ?>
                        <?= cmp_h((string)$row['equipo1']) ?>
                        <input type="hidden" name="equipo1[]" value="<?= cmp_h((string)$row['equipo1']) ?>">
                      <?php else: ?>
                        <select name="equipo1[]">
                          <option value="">Seleccionar…</option>
                          <?php foreach ($teamOptions as $team): ?>
                            <option value="<?= cmp_h($team) ?>"><?= cmp_h($team) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($row['is_loaded'])): ?>
                        <?= cmp_h((string)$row['equipo2']) ?>
                        <input type="hidden" name="equipo2[]" value="<?= cmp_h((string)$row['equipo2']) ?>">
                      <?php else: ?>
                        <select name="equipo2[]">
                          <option value="">Seleccionar…</option>
                          <?php foreach ($teamOptions as $team): ?>
                            <option value="<?= cmp_h($team) ?>"><?= cmp_h($team) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:12px;">
          <button type="submit" class="cmp-btn">Guardar completos en partidos</button>
        </div>
      </form>
    <?php else: ?>
      <div class="cmp-empty-state" style="margin-top:12px;">
        <p>Elegí <strong>Otros…</strong> y buscá un campeonato en <code>registros.titulo245</code>.</p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
                <section class="cmp-subsection">
  <h4>Partidos de este nodo</h4>

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
          <th>Cruce</th>
          <th>Mejor candidato</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($currentMatches === []): ?>
        <tr>
          <td colspan="10" class="cmp-empty">No hay partidos para este nodo.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($currentMatches as $match): ?>
          <?php
            $bestLink = $match['match_link_best'] ?? null;
            $linkStatus = (string)($match['match_link_status'] ?? 'sin_evaluar');
            $linkCount = (int)($match['match_link_count'] ?? 0);
            $allLinks = $match['match_links'] ?? [];
          ?>
          <tr>
            <td><?= (int)$match['id'] ?></td>
            <td><?= cmp_h((string)($match['nodo_label'] ?? '')) ?></td>
            <td><?= cmp_h((string)$match['local_texto']) ?></td>
            <td><?= cmp_h((string)($match['goles_local'] ?? '')) ?></td>
            <td><?= cmp_h((string)($match['goles_visitante'] ?? '')) ?></td>
            <td><?= cmp_h((string)$match['visitante_texto']) ?></td>

            <td>
              <?php if (($match['estado'] ?? 'activo') === 'ignorado'): ?>
                <span class="cmp-chip cmp-chip-empty">ignorado</span>
              <?php else: ?>
                <span class="cmp-chip">activo</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($linkStatus === 'validado'): ?>
                <span class="cmp-chip">validado</span>
              <?php elseif ($linkStatus === 'propuesta_unica'): ?>
                <span class="cmp-chip">propuesta única</span>
              <?php elseif ($linkStatus === 'ambiguo'): ?>
                <span class="cmp-chip cmp-chip-empty">ambiguo (<?= $linkCount ?>)</span>
              <?php elseif ($linkStatus === 'rechazado'): ?>
                <span class="cmp-chip cmp-chip-empty">rechazado</span>
              <?php else: ?>
                <span class="cmp-chip cmp-chip-empty">sin evaluar</span>
              <?php endif; ?>
            </td>

            <td>
              <?php if ($bestLink): ?>
                <?php foreach ($allLinks as $idx => $link): ?>
                  <div style="<?= $idx > 0 ? 'margin-top:10px; padding-top:10px; border-top:1px solid #ddd;' : '' ?>">
                    <div>
                      <strong><?= cmp_h((string)$link['partido_barcode']) ?></strong>
                      <?php if ((int)($link['id'] ?? 0) === (int)($bestLink['id'] ?? 0)): ?>
                        <span class="cmp-chip" style="margin-left:6px;">mejor</span>
                      <?php endif; ?>
                    </div>

                    <div>
                      <?= cmp_h((string)$link['equipo1_validado']) ?>
                      vs
                      <?= cmp_h((string)$link['equipo2_validado']) ?>
                    </div>

                    <div>score: <?= (int)$link['score'] ?></div>

                    <?php if (!empty($link['fecha_validada'])): ?>
                      <div>fecha: <?= cmp_h((string)$link['fecha_validada']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($link['observacion'])): ?>
                      <div><?= cmp_h((string)$link['observacion']) ?></div>
                    <?php endif; ?>

                    <div style="margin-top:4px;">
                      <a href="../ver_digital.php?barcode=<?= urlencode((string)$link['partido_barcode']) ?>&i=0"
                         target="_blank" rel="noopener">
                        Ver sobre
                      </a>
                    </div>

                    <div style="margin-top:6px;">
                      <form method="post" action="campeonatos_importacion_accion.php" class="cmp-inline-form" style="display:inline-block;">
                        <input type="hidden" name="action" value="validate_match_link">
                        <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                        <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                        <input type="hidden" name="tituloReg" value="<?= cmp_h($selectedTituloReg) ?>">
                        <button type="submit" class="cmp-btn cmp-btn-sm">Validar este</button>
                      </form>

                      <form method="post" action="campeonatos_importacion_accion.php" class="cmp-inline-form" style="display:inline-block; margin-left:4px;">
                        <input type="hidden" name="action" value="reject_match_link">
                        <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                        <input type="hidden" name="link_id" value="<?= (int)$link['id'] ?>">
                        <input type="hidden" name="tituloReg" value="<?= cmp_h($selectedTituloReg) ?>">
                        <button type="submit" class="cmp-btn cmp-btn-sm">Rechazar</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="cmp-empty">—</span>
              <?php endif; ?>
            </td>

            <td class="cmp-nowrap">
              <select class="cmp-action-select"
                      data-match-action
                      data-base-url="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>">
                <option value="">Acciones…</option>
                <option value="edit_match=<?= (int)$match['id'] ?>">Editar partido</option>
                <option value="edit_goals=<?= (int)$match['id'] ?>">Editar goleadores</option>
                <option value="move_match=<?= (int)$match['id'] ?>">Mover partido</option>
                <?php if (($match['estado'] ?? 'activo') === 'ignorado'): ?>
                  <option value="restore_match:<?= (int)$match['id'] ?>">Restaurar</option>
                <?php else: ?>
                  <option value="ignore_match:<?= (int)$match['id'] ?>">Ignorar</option>
                <?php endif; ?>
              </select>

              <?php if (($match['estado'] ?? 'activo') === 'ignorado'): ?>
                <form method="post" action="campeonatos_importacion_accion.php"
                      class="cmp-inline-form cmp-action-hidden-form"
                      data-action-form="restore_match:<?= (int)$match['id'] ?>">
                  <input type="hidden" name="action" value="restore_match">
                  <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                  <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                </form>
              <?php else: ?>
                <form method="post" action="campeonatos_importacion_accion.php"
                      class="cmp-inline-form cmp-action-hidden-form"
                      data-action-form="ignore_match:<?= (int)$match['id'] ?>">
                  <input type="hidden" name="action" value="ignore_match">
                  <input type="hidden" name="import_id" value="<?= (int)$id ?>">
                  <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
                  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
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

                <section class="cmp-subsection">
                    <h4>Sugerencia de uso</h4>
                    <p>Para resolver una final ida/vuelta mal agrupada: creá una serie, después creá dos fechas hijas (Ida y Vuelta) y finalmente mové cada partido a su contenedor correcto.</p>
                </section>
            <?php else: ?>
                <div class="cmp-empty-state">
                    <p>Seleccioná un nodo del árbol para ver su información y acciones.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($hasSelectedNode && $editNodeMode): ?>
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-medium" role="dialog" aria-modal="true" aria-labelledby="cmpNodeEditTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpNodeEditTitle">Editar nodo</h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
            <input type="hidden" name="action" value="update_node">
            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
            <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">

            <label>
                Label
                <input type="text" name="label" value="<?= cmp_h((string)$currentNode['label']) ?>" required>
            </label>

            <label>
                Subtipo
                <input type="text" name="subtype" value="<?= cmp_h((string)($currentNode['subtipo'] ?? '')) ?>">
            </label>

            <label>
                Orden
                <input type="number" name="sort_order" value="<?= (int)($currentNode['orden'] ?? 0) ?>">
            </label>

            <div class="cmp-actions">
                <button type="submit" class="cmp-btn cmp-btn-primary">Guardar nodo</button>
                <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($hasSelectedNode && $createNodeMode && $allowedChildTypes !== []): ?>
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-medium" role="dialog" aria-modal="true" aria-labelledby="cmpNodeCreateTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpNodeCreateTitle">Crear nodo hijo</h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
            <input type="hidden" name="action" value="create_node">
            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
            <input type="hidden" name="parent_node_id" value="<?= (int)$currentNodeId ?>">

            <label>
                Tipo
                <select name="type" required>
                    <?php foreach ($allowedChildTypes as $type): ?>
                        <option value="<?= cmp_h($type) ?>"><?= cmp_h($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Label
                <input type="text" name="label" required>
            </label>

            <label>
                Subtipo
                <input type="text" name="subtype">
            </label>

            <label>
                Orden
                <input type="number" name="sort_order">
            </label>

            <div class="cmp-actions">
                <button type="submit" class="cmp-btn cmp-btn-primary">Crear nodo</button>
                <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($hasSelectedNode && $deleteNodeMode && $canDeleteEmptyNode): ?>
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-small" role="dialog" aria-modal="true" aria-labelledby="cmpNodeDeleteTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpNodeDeleteTitle">Eliminar nodo vacío</h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <p>Este nodo no tiene hijos ni partidos activos. ¿Querés eliminarlo?</p>

        <form method="post" action="campeonatos_importacion_accion.php">
            <input type="hidden" name="action" value="delete_empty_node">
            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
            <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
            <input type="hidden" name="parent_id" value="<?= (int)($currentNode['parent_id'] ?? 0) ?>">

            <div class="cmp-actions">
                <button type="submit" class="cmp-btn cmp-btn-danger">Eliminar nodo</button>
                <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($editingMatch): ?>
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-medium" role="dialog" aria-modal="true" aria-labelledby="cmpEditMatchTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpEditMatchTitle">Editar partido #<?= (int)$editingMatch['id'] ?></h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
            <input type="hidden" name="action" value="update_match">
            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
            <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
            <input type="hidden" name="match_id" value="<?= (int)$editingMatch['id'] ?>">

            <label>
                Local
                <input type="text" name="local_texto" value="<?= cmp_h((string)$editingMatch['local_texto']) ?>" required>
            </label>

            <div class="cmp-grid cmp-grid-2">
                <label>
                    GL
                    <input type="number" name="goles_local" value="<?= cmp_h((string)($editingMatch['goles_local'] ?? '')) ?>">
                </label>
                <label>
                    GV
                    <input type="number" name="goles_visitante" value="<?= cmp_h((string)($editingMatch['goles_visitante'] ?? '')) ?>">
                </label>
            </div>

            <label>
                Visitante
                <input type="text" name="visitante_texto" value="<?= cmp_h((string)$editingMatch['visitante_texto']) ?>" required>
            </label>

            <label>
                Observación manual
                <textarea name="observacion_manual" rows="3"><?= cmp_h((string)($editingMatch['observacion_manual'] ?? '')) ?></textarea>
            </label>

            <div class="cmp-meta">
                Línea fuente: <?= cmp_h((string)($editingMatch['fuente_linea'] ?? '')) ?>
            </div>

            <div class="cmp-actions">
                <button type="submit" class="cmp-btn cmp-btn-primary">Guardar partido</button>
                <a class="cmp-btn" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cancelar</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($movingMatch): ?>
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-medium" role="dialog" aria-modal="true" aria-labelledby="cmpMoveMatchTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpMoveMatchTitle">Mover partido #<?= (int)$movingMatch['id'] ?></h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <p class="cmp-meta">
            <?= cmp_h((string)$movingMatch['local_texto']) ?>
            <?= cmp_h((string)($movingMatch['goles_local'] ?? '')) ?>-<?= cmp_h((string)($movingMatch['goles_visitante'] ?? '')) ?>
            <?= cmp_h((string)$movingMatch['visitante_texto']) ?>
        </p>

        <form method="post" action="campeonatos_importacion_accion.php" class="cmp-form-grid">
            <input type="hidden" name="action" value="move_match">
            <input type="hidden" name="import_id" value="<?= (int)$id ?>">
            <input type="hidden" name="node_id" value="<?= (int)$currentNodeId ?>">
            <input type="hidden" name="match_id" value="<?= (int)$movingMatch['id'] ?>">

            <label>
                Fecha destino
                <select name="target_node_id" required>
                    <option value="">Seleccionar…</option>
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
    </div>
</div>
<?php endif; ?>

<?php if ($editingGoalsMatch): ?>
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
<div class="cmp-modal-backdrop is-open">
    <div class="cmp-modal cmp-modal-goals" role="dialog" aria-modal="true" aria-labelledby="cmpGoalsModalTitle">
        <div class="cmp-modal-head">
            <h4 id="cmpGoalsModalTitle">Editar goleadores del partido #<?= (int)$editingGoalsMatch['id'] ?></h4>
            <a class="cmp-btn cmp-btn-sm" href="campeonatos_importacion_editar.php?id=<?= (int)$id ?>&node_id=<?= (int)$currentNodeId ?>#workspace">Cerrar</a>
        </div>

        <p class="cmp-meta">
            <?= cmp_h((string)$editingGoalsMatch['local_texto']) ?>
            <?= cmp_h((string)($editingGoalsMatch['goles_local'] ?? '')) ?>-<?= cmp_h((string)($editingGoalsMatch['goles_visitante'] ?? '')) ?>
            <?= cmp_h((string)$editingGoalsMatch['visitante_texto']) ?>
        </p>

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
                    <?php foreach ($editingGoalEvents as $goal): ?>
                        <?php $side = (string)($goal['team_side'] ?? 'desconocido'); ?>
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
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-tree-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = btn.closest('.cmp-tree-item');
            if (!row) return;
            const children = row.querySelector(':scope > .cmp-tree-children');
            if (!children) return;

            const expanded = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            btn.textContent = expanded ? '+' : '−';
            children.style.display = expanded ? 'none' : '';
        });
    });

    const expandBtn = document.querySelector('[data-tree-expand]');
    const collapseBtn = document.querySelector('[data-tree-collapse]');

    if (expandBtn) {
        expandBtn.addEventListener('click', function () {
            document.querySelectorAll('[data-tree-toggle]').forEach(function (btn) {
                const row = btn.closest('.cmp-tree-item');
                const children = row ? row.querySelector(':scope > .cmp-tree-children') : null;
                btn.setAttribute('aria-expanded', 'true');
                btn.textContent = '−';
                if (children) children.style.display = '';
            });
        });
    }

    if (collapseBtn) {
        collapseBtn.addEventListener('click', function () {
            document.querySelectorAll('[data-tree-toggle]').forEach(function (btn) {
                const row = btn.closest('.cmp-tree-item');
                const children = row ? row.querySelector(':scope > .cmp-tree-children') : null;
                btn.setAttribute('aria-expanded', 'false');
                btn.textContent = '+';
                if (children) children.style.display = 'none';
            });
        });
    }

    document.querySelectorAll('[data-match-action]').forEach(function (select) {
        select.addEventListener('change', function () {
            const value = select.value;
            if (!value) return;

            const baseUrl = select.dataset.baseUrl || '';

            if (value.startsWith('edit_match=') || value.startsWith('edit_goals=') || value.startsWith('move_match=')) {
                window.location.href = baseUrl + '&' + value + '#workspace';
                return;
            }

            const form = document.querySelector('[data-action-form="' + value + '"]');
            if (form) {
                form.submit();
                return;
            }

            select.value = '';
        });
    });

    document.querySelectorAll('[data-node-action]').forEach(function (select) {
        select.addEventListener('change', function () {
            const value = select.value;
            if (!value) return;
            const baseUrl = select.dataset.baseUrl || '';
            window.location.href = baseUrl + '&' + value + '#workspace';
        });
    });

    const rows = document.getElementById('cmpGoalRows');
    const template = document.getElementById('cmpGoalRowTemplate');
    const addBtn = document.getElementById('cmpAddGoalRow');

    if (rows && template && addBtn) {
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
    }
});
</script>

<?php if ($hasSelectedNode): ?>
<div class="cmp-modal-backdrop" id="cmpOtrosBackdrop" style="display:none;">
  <div class="cmp-modal cmp-modal-medium" role="dialog" aria-modal="true" aria-labelledby="cmpOtrosTitle">
    <div class="cmp-modal-head">
      <h4 id="cmpOtrosTitle">Buscar campeonato en registros.titulo245</h4>
      <button type="button" class="cmp-btn cmp-btn-sm" id="cmpCloseOtrosModal">Cerrar</button>
    </div>

    <div class="cmp-form-grid">
      <label>
        Buscar
        <input type="text" id="cmpOtrosSearchInput" placeholder="Ej. Primera B 1975, Copa, Nacional...">
      </label>
      <div>
        <button type="button" class="cmp-btn" id="cmpOtrosSearchBtn">Buscar</button>
      </div>
    </div>

    <div id="cmpOtrosSearchStatus" style="margin-top:12px;"></div>
    <div id="cmpOtrosSearchResults" style="margin-top:12px;"></div>
  </div>
</div>

<script>
(function () {
  const tituloRegSelect = document.getElementById('tituloReg');
  const openBtn = document.getElementById('cmpOpenOtrosModal');
  const closeBtn = document.getElementById('cmpCloseOtrosModal');
  const backdrop = document.getElementById('cmpOtrosBackdrop');
  const searchBtn = document.getElementById('cmpOtrosSearchBtn');
  const searchInput = document.getElementById('cmpOtrosSearchInput');
  const resultsBox = document.getElementById('cmpOtrosSearchResults');
  const statusBox = document.getElementById('cmpOtrosSearchStatus');
  const normalLinkBtn = document.getElementById('cmpGoToNormalLinking');

  const baseUrl = <?= json_encode('campeonatos_importacion_editar.php?id=' . (int)$id . '&node_id=' . (int)$currentNodeId) ?>;

  function openModal() {
    if (!backdrop) return;
    backdrop.style.display = 'flex';
    if (searchInput) searchInput.focus();
  }

  function closeModal() {
    if (!backdrop) return;
    backdrop.style.display = 'none';
  }

  function goToOtrosMode() {
    window.location.href = baseUrl + '&tituloReg=__otros__#workspace';
  }

  function goToNormalMode(selected) {
    if (!selected || selected === '__otros__') return;
    window.location.href = baseUrl + '&tituloReg=' + encodeURIComponent(selected) + '#workspace';
  }

  async function doSearch() {
    if (!searchInput || !resultsBox || !statusBox) return;

    const q = searchInput.value.trim();
    resultsBox.innerHTML = '';
    if (!q) {
      statusBox.textContent = 'Escribí algo para buscar.';
      return;
    }

    statusBox.textContent = 'Buscando…';

    try {
      const resp = await fetch('campeonatos_titulo245_ajax.php?action=search_registros&q=' + encodeURIComponent(q), {
        credentials: 'same-origin'
      });
      const data = await resp.json();

      if (!data.ok) {
        throw new Error(data.error || 'No se pudo buscar.');
      }

      const items = Array.isArray(data.items) ? data.items : [];
      statusBox.textContent = items.length ? ('Resultados: ' + items.length) : 'Sin resultados.';

      if (!items.length) {
        resultsBox.innerHTML = '';
        return;
      }

      const html = items.map(item => {
        const href = baseUrl
          + '&tituloReg=__otros__'
          + '&otros_sys=' + encodeURIComponent(item.sys)
          + '&otros_titulo=' + encodeURIComponent(item.titulo245)
          + '#workspace';

        return `
          <div style="padding:8px 0; border-top:1px solid #ddd;">
            <div><strong>${escapeHtml(item.titulo245)}</strong></div>
            <div><small>sys ${escapeHtml(item.sys)}</small></div>
            <div style="margin-top:6px;">
              <a class="cmp-btn cmp-btn-sm" href="${href}">Elegir</a>
            </div>
          </div>
        `;
      }).join('');

      resultsBox.innerHTML = html;
    } catch (err) {
      statusBox.textContent = err && err.message ? err.message : 'Error buscando registros.';
    }
  }

  function escapeHtml(str) {
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  if (tituloRegSelect) {
    tituloRegSelect.addEventListener('change', function () {
      if (this.value === '__otros__') {
        goToOtrosMode();
        return;
      }
      if (this.value) {
        goToNormalMode(this.value);
      }
    });
  }

  if (normalLinkBtn && tituloRegSelect) {
    normalLinkBtn.addEventListener('click', function () {
      if (tituloRegSelect.value) {
        goToNormalMode(tituloRegSelect.value);
      }
    });
  }

  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (searchBtn) searchBtn.addEventListener('click', doSearch);
  if (searchInput) {
    searchInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        doSearch();
      }
    });
  }
})();
</script>
<?php endif; ?>

<?php cmp_render_footer(); ?>