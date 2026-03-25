<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

require_once __DIR__ . '/../inc/campeonatos_import_dashboard_repo.php';

$error = null;
$message = (string)($_GET['msg'] ?? '');
$errorFlash = (string)($_GET['error'] ?? '');

$filters = cmp_dashboard_import_filters_from_request();
$imports = [];
$metrics = [
    'total' => 0,
    'sin_partidos' => 0,
    'con_ignorados' => 0,
    'cobertura_baja' => 0,
    'por_estado' => [],
];
$temporadas = [];
$estados = [];
$fuentes = [];

try {
    $imports = cmp_dashboard_import_list($filters);
    $metrics = cmp_dashboard_import_metrics($imports);
    $temporadas = cmp_dashboard_import_distinct_values('temporada_detectada');
    $estados = cmp_dashboard_import_distinct_values('estado');
    $fuentes = cmp_dashboard_import_distinct_values('fuente_tipo');
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Importaciones de campeonatos');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.cmp-dashboard-wrap { display:grid; gap:16px; }
.cmp-dashboard-head { display:flex; justify-content:space-between; align-items:start; gap:12px; flex-wrap:wrap; }
.cmp-dashboard-metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.cmp-dashboard-metric { border:1px solid #d9dee7; border-radius:12px; background:#fff; padding:12px 14px; }
.cmp-dashboard-metric-label { font-size:12px; color:#6b7280; margin-bottom:4px; }
.cmp-dashboard-metric-value { font-size:24px; font-weight:700; line-height:1.1; }
.cmp-dashboard-filters { display:grid; grid-template-columns:minmax(220px,1.6fr) repeat(3,minmax(140px,1fr)) auto; gap:10px; align-items:end; }
.cmp-dashboard-filters label { display:grid; gap:4px; font-weight:600; }
.cmp-dashboard-filters input, .cmp-dashboard-filters select { width:100%; min-width:0; box-sizing:border-box; }
.cmp-dashboard-table-wrap { overflow:auto; }
.cmp-dashboard-table { width:100%; min-width:1080px; }
.cmp-dashboard-actions { display:grid; gap:6px; }
.cmp-dashboard-action-select { min-width:170px; }
.cmp-dashboard-muted { color:#6b7280; font-size:12px; }
.cmp-dashboard-coverage-low { color:#b45309; font-weight:600; }
.cmp-dashboard-state-strip { display:flex; gap:8px; flex-wrap:wrap; }
@media (max-width:1000px) {
  .cmp-dashboard-metrics { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .cmp-dashboard-filters { grid-template-columns:1fr 1fr; }
}
@media (max-width:640px) {
  .cmp-dashboard-metrics, .cmp-dashboard-filters { grid-template-columns:1fr; }
}
</style>

<section class="cmp-wrap cmp-dashboard-wrap">
    <div class="cmp-dashboard-head">
        <div>
            <p class="cmp-kicker">Campeonatos / staging</p>
            <h2>Dashboard de importaciones</h2>
            <div class="cmp-dashboard-state-strip">
                <?php foreach ($metrics['por_estado'] as $state => $count): ?>
                    <span class="cmp-chip"><?= cmp_h((string)$state) ?>: <?= (int)$count ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <a href="campeonatos_importar.php" class="cmp-btn cmp-btn-primary">Nueva importación</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div>
    <?php endif; ?>

    <?php if ($errorFlash !== ''): ?>
        <div class="cmp-alert cmp-alert-error"><?= cmp_h($errorFlash) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
    <?php endif; ?>

    <section class="cmp-dashboard-metrics">
        <article class="cmp-dashboard-metric">
            <div class="cmp-dashboard-metric-label">Total importaciones</div>
            <div class="cmp-dashboard-metric-value"><?= (int)$metrics['total'] ?></div>
        </article>
        <article class="cmp-dashboard-metric">
            <div class="cmp-dashboard-metric-label">Sin partidos</div>
            <div class="cmp-dashboard-metric-value"><?= (int)$metrics['sin_partidos'] ?></div>
        </article>
        <article class="cmp-dashboard-metric">
            <div class="cmp-dashboard-metric-label">Con ignorados</div>
            <div class="cmp-dashboard-metric-value"><?= (int)$metrics['con_ignorados'] ?></div>
        </article>
        <article class="cmp-dashboard-metric">
            <div class="cmp-dashboard-metric-label">Cobertura baja (&lt; 80%)</div>
            <div class="cmp-dashboard-metric-value"><?= (int)$metrics['cobertura_baja'] ?></div>
        </article>
    </section>

    <section class="cmp-card">
        <form method="get" class="cmp-dashboard-filters">
            <label>
                Buscar
                <input type="text" name="q" value="<?= cmp_h($filters['q']) ?>" placeholder="Título, fuente, ID...">
            </label>

            <label>
                Temporada
                <select name="temporada">
                    <option value="">Todas</option>
                    <?php foreach ($temporadas as $temporada): ?>
                        <option value="<?= cmp_h($temporada) ?>" <?= $temporada === $filters['temporada'] ? 'selected' : '' ?>>
                            <?= cmp_h($temporada) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Estado
                <select name="estado">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $estado): ?>
                        <option value="<?= cmp_h($estado) ?>" <?= $estado === $filters['estado'] ? 'selected' : '' ?>>
                            <?= cmp_h($estado) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Fuente
                <select name="fuente_tipo">
                    <option value="">Todas</option>
                    <?php foreach ($fuentes as $fuente): ?>
                        <option value="<?= cmp_h($fuente) ?>" <?= $fuente === $filters['fuente_tipo'] ? 'selected' : '' ?>>
                            <?= cmp_h($fuente) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="cmp-actions">
                <button type="submit" class="cmp-btn cmp-btn-primary">Aplicar</button>
                <a href="campeonatos_importaciones.php" class="cmp-btn">Limpiar</a>
            </div>
        </form>
    </section>

    <section class="cmp-card">
        <div class="cmp-dashboard-table-wrap">
            <table class="cmp-table cmp-dashboard-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Temporada</th>
                        <th>Estado</th>
                        <th>Nodos</th>
                        <th>Partidos</th>
                        <th>Goles</th>
                        <th>Cobertura</th>
                        <th>Fuente</th>
                        <th>Actualizada</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$imports): ?>
                    <tr>
                        <td colspan="11" class="cmp-empty">No hay importaciones para esos filtros.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($imports as $row): ?>
                        <?php
                        $coverage = $row['audit_coverage_pct'];
                        $coverageLabel = $coverage === null ? '—' : number_format((float)$coverage, 1, ',', '.') . '%';
                        $coverageClass = ($coverage !== null && (float)$coverage < 80.0) ? 'cmp-dashboard-coverage-low' : '';
                        $updatedAt = (string)($row['actualizado_en'] ?? $row['creado_en'] ?? '');
                        ?>
                        <tr>
                            <td>#<?= (int)$row['id'] ?></td>
                            <td>
                                <strong><?= cmp_h((string)$row['titulo_fuente']) ?></strong>
                                <?php if (!empty($row['fuente_url'])): ?>
                                    <div class="cmp-dashboard-muted"><?= cmp_h((string)$row['fuente_url']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= cmp_h((string)($row['temporada_detectada'] ?? '')) ?></td>
                            <td><span class="cmp-chip"><?= cmp_h((string)($row['estado'] ?? '')) ?></span></td>
                            <td><?= (int)($row['nodes_count'] ?? 0) ?></td>
                            <td>
                                <?= (int)($row['matches_count'] ?? 0) ?>
                                <?php if ((int)($row['ignored_matches_count'] ?? 0) > 0): ?>
                                    <div class="cmp-dashboard-muted">ignorados: <?= (int)$row['ignored_matches_count'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)($row['goals_count'] ?? 0) ?></td>
                            <td class="<?= $coverageClass ?>">
                                <?= $coverageLabel ?>
                                <?php if ((int)($row['audit_total_lines'] ?? 0) > 0): ?>
                                    <div class="cmp-dashboard-muted">
                                        usadas: <?= (int)($row['audit_used_lines'] ?? 0) ?> /
                                        total: <?= (int)($row['audit_total_lines'] ?? 0) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= cmp_h((string)($row['fuente_tipo'] ?? '')) ?></td>
                            <td><?= cmp_h($updatedAt) ?></td>
                            <td>
                                <select class="cmp-dashboard-action-select"
                                    data-dashboard-action
                                    data-import-id="<?= (int)$row['id'] ?>"
                                    data-view-url="campeonatos_importacion.php?id=<?= (int)$row['id'] ?>"
                                    data-edit-url="campeonatos_importacion_editar.php?id=<?= (int)$row['id'] ?>">
                                <option value="">Acciones…</option>
                                <option value="view">Ver importación</option>
                                <option value="edit">Editar estructura</option>
                                <option value="delete">Borrar definitivamente</option>
                            </select>

                            <form method="post" action="campeonatos_importaciones_accion.php"
                                  class="cmp-dashboard-inline-form"
                                  data-dashboard-form="delete"
                                  data-import-id="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="action" value="hard_delete">
                                <input type="hidden" name="import_id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="q" value="<?= cmp_h($filters['q']) ?>">
                                <input type="hidden" name="temporada" value="<?= cmp_h($filters['temporada']) ?>">
                                <input type="hidden" name="estado" value="<?= cmp_h($filters['estado']) ?>">
                                <input type="hidden" name="fuente_tipo" value="<?= cmp_h($filters['fuente_tipo']) ?>">
                            </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-dashboard-action]').forEach(function (select) {
    select.addEventListener('change', function () {
        const value = select.value;
        if (!value) return;

        if (value === 'view') {
            window.location.href = select.dataset.viewUrl;
            return;
        }

        if (value === 'edit') {
            window.location.href = select.dataset.editUrl;
            return;
        }

        if (value === 'delete') {
            const importId = select.dataset.importId;
            const ok = window.confirm('Esto va a borrar definitivamente la importación y todo su staging asociado. ¿Continuar?');
            if (!ok) {
                select.value = '';
                return;
            }

            const form = document.querySelector('[data-dashboard-form="delete"][data-import-id="' + importId + '"]');
            if (form) {
                form.submit();
                return;
            }
        }

        select.value = '';
    });
});
});
</script>

<?php cmp_render_footer(); ?>