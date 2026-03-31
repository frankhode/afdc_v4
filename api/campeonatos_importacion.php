<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/../inc/campeonatos_import_repo.php';
require_once __DIR__ . '/../inc/campeonatos_parser.php';
$mainClass = 'container-fluid';

function cmp_render_tree_nodes(array $nodes): void {
    if (!$nodes) return;

    echo '<ul class="cmp-tree">';
    foreach ($nodes as $node) {
        $tipo = $node['tipo'] ?? '';
        $subtipo = $node['subtipo'] ?? '';

        echo '<li>';
        echo '<div class="cmp-tree-node">';
        echo '<span class="cmp-node-type">' . cmp_h($tipo) . ($subtipo ? ':' . cmp_h($subtipo) : '') . '</span>';
        echo '<span class="cmp-node-label">' . cmp_h($node['label'] ?? '') . '</span>';

        // --- equipos normalizados ---
        $homeCanonical = trim((string)($node['home_team_canonical'] ?? ''));
        $awayCanonical = trim((string)($node['away_team_canonical'] ?? ''));
        $homeRaw = trim((string)($node['home_team_raw'] ?? ''));
        $awayRaw = trim((string)($node['away_team_raw'] ?? ''));
        $homeStatus = trim((string)($node['home_team_match_status'] ?? ''));
        $awayStatus = trim((string)($node['away_team_match_status'] ?? ''));

        if (
            $homeCanonical !== '' || $awayCanonical !== '' ||
            $homeRaw !== '' || $awayRaw !== ''
        ) {
            echo '<div style="margin-top:4px; font-size:12px; color:#666;">';
            echo '⚽ ' . cmp_h($homeCanonical !== '' ? $homeCanonical : $homeRaw);
            echo ' vs ';
            echo cmp_h($awayCanonical !== '' ? $awayCanonical : $awayRaw);
            echo '</div>';

            echo '<div style="font-size:11px; color:#999;">';
            echo '[' . cmp_h($homeStatus) . ' / ' . cmp_h($awayStatus) . ']';
            echo '</div>';
        }

        // --- goleadores detectados ---
        if (!empty($node['goal_events']) && is_array($node['goal_events'])) {
            echo '<div style="margin-top:6px; font-size:12px;">';
            echo '<strong>Goles:</strong>';

            foreach ($node['goal_events'] as $g) {
                $player = trim((string)($g['player_raw'] ?? ''));
                $minute = $g['minute'] ?? null;
                $side = trim((string)($g['team_side'] ?? ''));

                echo '<div style="margin-left:10px;">';
                echo cmp_h($player !== '' ? $player : '[sin nombre]');
                if ($minute !== null && $minute !== '') {
                    echo ' (' . (int)$minute . '\')';
                }
                if ($side !== '') {
                    echo ' <span style="color:#999;">[' . cmp_h($side) . ']</span>';
                }
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>';

        if (!empty($node['children'])) {
            cmp_render_tree_nodes($node['children']);
        }

        echo '</li>';
    }
    echo '</ul>';
}

function cmp_render_text_audit(array $audit): void {
    echo '<div class="cmp-audit-pre">';
    foreach (($audit['items'] ?? []) as $row) {
        $status = (string)($row['status'] ?? 'unused');
        $type = (string)($row['type'] ?? '');
        $hint = $type !== '' ? ' title="' . cmp_h($type) . '"' : '';
        $lineText = ((string)($row['text'] ?? '')) === '' ? ' ' : (string)$row['text'];

        echo '<div class="cmp-audit-line cmp-audit-' . cmp_h($status) . '"' . $hint . '>';
        echo '<span class="cmp-audit-num">' . (int)($row['line_no'] ?? 0) . '</span>';
        echo '<span class="cmp-audit-text">' . cmp_h($lineText) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

$id = (int)($_GET['id'] ?? 0);
$import = null;
$nodes = [];
$tree = [];
$matches = [];
$goalEvents = [];
$audit = [
    'summary' => [
        'total' => 0,
        'used' => 0,
        'unused' => 0,
        'suspicious' => 0,
        'empty' => 0,
        'coverage' => 0,
    ],
    'items' => [],
];
$error = null;

try {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }
    $import = cmp_import_get($id);
    if (!$import) {
        throw new RuntimeException('La importación no existe.');
    }
    $nodes = cmp_import_get_nodes($id);
    $tree = cmp_import_build_tree($nodes);
    $matches = cmp_import_get_matches($id);
    $goalEvents = cmp_import_get_goal_events($id);
    $audit = cmp_build_text_audit((string)$import['texto_crudo'], $tree);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Importación de campeonato', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<style>
.cmp-audit-wrap { margin-top: 1rem; }
.cmp-audit-legend { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:.75rem; }
.cmp-audit-chip { display:inline-flex; align-items:center; gap:.45rem; font-size:.92rem; }
.cmp-audit-chip i { width:.9rem; height:.9rem; border-radius:3px; display:inline-block; }
.cmp-audit-chip-used i { background:#dff3e4; border:1px solid #9cc8a6; }
.cmp-audit-chip-unused i { background:#f9dede; border:1px solid #d9a4a4; }
.cmp-audit-chip-suspicious i { background:#fff0c7; border:1px solid #dcc36f; }
.cmp-audit-chip-blank i { background:#f1f1f1; border:1px solid #d4d4d4; }
.cmp-audit-pre {
  border:1px solid #d9dde4;
  border-radius:12px;
  overflow:auto;
  background:#fff;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  font-size:.92rem;
}
.cmp-audit-line {
  display:grid;
  grid-template-columns:64px 1fr;
  gap:.75rem;
  padding:.2rem .75rem;
  white-space:pre-wrap;
  border-bottom:1px solid rgba(0,0,0,.03);
}
.cmp-audit-line:last-child { border-bottom:none; }
.cmp-audit-num { color:#7a7f89; text-align:right; user-select:none; }
.cmp-audit-text { white-space:pre-wrap; word-break:break-word; }
.cmp-audit-used { background:#eef9f0; }
.cmp-audit-unused { background:#fff1f1; }
.cmp-audit-suspicious { background:#fff8dd; }
.cmp-audit-blank { background:#fafafa; }
.cmp-audit-summary { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:.75rem; margin-bottom:1rem; }
.cmp-audit-stat { padding:.8rem .9rem; border:1px solid #d9dde4; border-radius:12px; background:#fff; }
.cmp-audit-stat strong { display:block; font-size:1.2rem; }
.cmp-actions-inline {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.cmp-audit-row td {
  background: #fafafa;
  border-top: none;
}

.cmp-audit-box {
  display: flex;
  gap: 24px;
  padding: 10px 0;
  font-size: 12px;
}

.cmp-audit-col {
  min-width: 180px;
}

.cmp-audit-col strong {
  font-size: 11px;
  color: #666;
}
</style>
<section class="cmp-wrap">
  <nav class="cmp-breadcrumbs"><a href="campeonatos_importaciones.php">Importaciones</a> <span>/</span> <strong>Ver importación</strong></nav>

  <?php if ($error): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php elseif ($import): ?>
    <div class="cmp-pagehead">
      <div>
        <p class="cmp-kicker">Importación #<?= (int)$import['id'] ?></p>
        <h2><?= cmp_h($import['titulo_fuente']) ?></h2>
        <p class="cmp-meta">Temporada: <?= cmp_h((string)$import['temporada_detectada']) ?> · Estado: <span class="cmp-badge"><?= cmp_h($import['estado']) ?></span></p>
      </div>
      <div class="cmp-actions-inline">
        <a href="campeonatos_importacion_editar.php?id=<?= (int)$import['id'] ?>" class="cmp-btn cmp-btn-primary">Editar estructura</a>
        <a href="campeonatos_importar.php" class="cmp-btn">Nueva importación</a>
      </div>
    </div>

    <div class="cmp-grid-2">
      <section class="cmp-card">
        <h3>Árbol detectado</h3>
        <?php cmp_render_tree_nodes($tree); ?>
      </section>

      <section class="cmp-card">
        <h3>Resumen</h3>
        <dl class="cmp-summary">
          <dt>Fuente tipo</dt><dd><?= cmp_h($import['fuente_tipo']) ?></dd>
          <dt>Fuente URL</dt><dd><?= cmp_h((string)$import['fuente_url']) ?></dd>
          <dt>Nodos</dt><dd><?= count($nodes) ?></dd>
          <dt>Partidos</dt><dd><?= count($matches) ?></dd>
          <dt>Goles</dt><dd><?= count($goalEvents) ?></dd>
        </dl>
      </section>
    </div>

    <section class="cmp-card">
      <h3>Partidos detectados</h3>
      <table class="cmp-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Nodo</th>
            <th>Local</th>
            <th>GL</th>
            <th>GV</th>
            <th>Visitante</th>
            <th>Local canon</th>
            <th>Estado L</th>
            <th>Visitante canon</th>
            <th>Estado V</th>
            <th>Goleadores</th>
            <th>Línea fuente</th>
          </tr>
        </thead>
        <tbody>
          <tbody>
            <?php if (!$matches): ?>
              <tr><td colspan="12" class="cmp-empty">No se detectaron partidos todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($matches as $row): ?>
                <?php
                  $localCanon = trim((string)($row['home_team_canonical'] ?? ''));
                  $localStatus = trim((string)($row['home_team_match_status'] ?? ''));
                  $visitCanon = trim((string)($row['away_team_canonical'] ?? ''));
                  $visitStatus = trim((string)($row['away_team_match_status'] ?? ''));

                  $goalText = '';
                  $goalEventsRaw = $row['goal_events'] ?? null;

                  if (is_string($goalEventsRaw) && $goalEventsRaw !== '') {
                      $decoded = json_decode($goalEventsRaw, true);
                      if (is_array($decoded)) {
                          $goalEventsRaw = $decoded;
                      }
                  }

                  if (is_array($goalEventsRaw) && $goalEventsRaw) {
                      $parts = [];
                      foreach ($goalEventsRaw as $g) {
                          $player = trim((string)($g['player_raw'] ?? ''));
                          $minute = $g['minute'] ?? null;
                          if ($player === '') {
                              continue;
                          }
                          $txt = $player;
                          if ($minute !== null && $minute !== '') {
                              $txt .= ' (' . (int)$minute . '\')';
                          }
                          $parts[] = $txt;
                      }
                      $goalText = implode(', ', $parts);
                  } elseif (!empty($row['goal_text_raw'])) {
                      $goalText = (string)$row['goal_text_raw'];
                  }
                ?>
                <tr>
                  <td><?= (int)$row['id'] ?></td>
                  <td>
                    <?= cmp_h($row['nodo_label']) ?>
                    <span class="cmp-muted">
                      (<?= cmp_h($row['nodo_tipo']) ?><?= !empty($row['nodo_subtipo']) ? ':' . cmp_h($row['nodo_subtipo']) : '' ?>)
                    </span>
                  </td>
                  <td><?= cmp_h($row['local_texto']) ?></td>
                  <td><?= cmp_h((string)$row['goles_local']) ?></td>
                  <td><?= cmp_h((string)$row['goles_visitante']) ?></td>
                  <td><?= cmp_h($row['visitante_texto']) ?></td>
                  <td><?= cmp_h($localCanon) ?></td>
                  <td><?= cmp_h($localStatus) ?></td>
                  <td><?= cmp_h($visitCanon) ?></td>
                  <td><?= cmp_h($visitStatus) ?></td>
                  <td><?= cmp_h($goalText) ?></td>
                  <td class="cmp-source-line"><?= cmp_h($row['fuente_linea']) ?></td>
                </tr>
                <?php
                  $events = $row['goal_events'] ?? [];

                  if (is_string($events) && $events !== '') {
                      $decodedEvents = json_decode($events, true);
                      if (is_array($decodedEvents)) {
                          $events = $decodedEvents;
                      }
                  }

                  $homeGoals = [];
                  $awayGoals = [];
                  $unknownGoals = [];

                  if (is_array($events)) {
                      foreach ($events as $ev) {
                          $name = trim((string)($ev['player_raw'] ?? ''));
                          $side = trim((string)($ev['team_side'] ?? 'desconocido'));

                          if ($name === '') {
                              continue;
                          }

                          if ($side === 'local') {
                              $homeGoals[] = $name;
                          } elseif ($side === 'visitante') {
                              $awayGoals[] = $name;
                          } else {
                              $unknownGoals[] = $name;
                          }
                      }
                  }
                ?>

                <tr class="cmp-audit-row">
                  <td colspan="12">
                    <div class="cmp-audit-box">
                      <div class="cmp-audit-col">
                        <strong>LOCAL</strong><br>
                        <?php if ($homeGoals): ?>
                          <?php foreach ($homeGoals as $g): ?>
                            - <?= cmp_h($g) ?><br>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="cmp-muted">—</span>
                        <?php endif; ?>
                      </div>

                      <div class="cmp-audit-col">
                        <strong>VISITANTE</strong><br>
                        <?php if ($awayGoals): ?>
                          <?php foreach ($awayGoals as $g): ?>
                            - <?= cmp_h($g) ?><br>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="cmp-muted">—</span>
                        <?php endif; ?>
                      </div>

                      <div class="cmp-audit-col">
                        <strong>AMBIGUOS</strong><br>
                        <?php if ($unknownGoals): ?>
                          <?php foreach ($unknownGoals as $g): ?>
                            - <?= cmp_h($g) ?><br>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span class="cmp-muted">—</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
      </table>
    </section>

    <section class="cmp-card cmp-audit-wrap">
      <h3>Texto fuente auditado</h3>

      <div class="cmp-audit-summary">
        <div class="cmp-audit-stat"><span>Líneas</span><strong><?= (int)($audit['summary']['total'] ?? 0) ?></strong></div>
        <div class="cmp-audit-stat"><span>Usadas</span><strong><?= (int)($audit['summary']['used'] ?? 0) ?></strong></div>
        <div class="cmp-audit-stat"><span>No usadas</span><strong><?= (int)($audit['summary']['unused'] ?? 0) ?></strong></div>
        <div class="cmp-audit-stat"><span>Dudosas</span><strong><?= (int)($audit['summary']['suspicious'] ?? 0) ?></strong></div>
        <div class="cmp-audit-stat"><span>Cobertura</span><strong><?= (int)($audit['summary']['coverage'] ?? 0) ?>%</strong></div>
      </div>

      <div class="cmp-audit-legend">
        <span class="cmp-audit-chip cmp-audit-chip-used"><i></i> Usado</span>
        <span class="cmp-audit-chip cmp-audit-chip-unused"><i></i> No usado</span>
        <span class="cmp-audit-chip cmp-audit-chip-suspicious"><i></i> Dudoso</span>
        <span class="cmp-audit-chip cmp-audit-chip-blank"><i></i> Línea vacía</span>
      </div>

      <?php cmp_render_text_audit($audit); ?>
    </section>
  <?php endif; ?>
</section>
<?php cmp_render_footer(); ?>