<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

$id = (int)($_GET['id'] ?? 0);
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

$entity = null;
$aliases = [];
$usage = [
    'total' => 0,
    'local_count' => 0,
    'visitante_count' => 0,
];

try {
    if ($id <= 0) {
        throw new InvalidArgumentException('Entidad inválida.');
    }

    $entity = cmp_ent_get($id);
    if (!$entity) {
        throw new RuntimeException('La entidad no existe.');
    }

    $aliases = cmp_ent_list_aliases($id);
    $usage = cmp_ent_usage_stats($id);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Editar entidad', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.cmp-ent-detail-page { display:grid; gap:14px; }
.cmp-ent-detail-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
.cmp-ent-form-grid {
  display:grid;
  grid-template-columns: repeat(2, minmax(220px, 1fr));
  gap:12px;
}
.cmp-ent-form-grid label { display:grid; gap:4px; font-weight:600; }
.cmp-ent-form-grid .full { grid-column:1 / -1; }
.cmp-ent-alias-form {
  display:grid;
  grid-template-columns:minmax(260px,1fr) minmax(220px,1fr) 160px auto;
  gap:10px;
  align-items:end;
}
.cmp-ent-alias-form label { display:grid; gap:4px; font-weight:600; }
.cmp-ent-metrics {
  display:grid;
  grid-template-columns:repeat(3,minmax(120px,1fr));
  gap:10px;
}
.cmp-ent-metric {
  border:1px solid #d9dde4;
  border-radius:12px;
  padding:10px 12px;
  background:#fff;
}
.cmp-ent-metric span { display:block; color:#6b7280; font-size:12px; }
.cmp-ent-metric strong { display:block; font-size:22px; }
@media (max-width:900px) {
  .cmp-ent-form-grid,
  .cmp-ent-alias-form,
  .cmp-ent-metrics { grid-template-columns:1fr; }
}
</style>

<section class="cmp-wrap cmp-ent-detail-page">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_entidades.php">Entidades</a>
    <span>/</span>
    <strong><?= $entity ? cmp_h((string)$entity['nombre_mostrable']) : 'Editar' ?></strong>
  </nav>

  <div class="cmp-ent-detail-head">
    <div>
      <p class="cmp-kicker">Entidad</p>
      <h1><?= $entity ? cmp_h((string)$entity['nombre_mostrable']) : 'Editar entidad' ?></h1>
      <?php if ($entity): ?>
        <div class="cmp-meta">ID <?= (int)$entity['id'] ?> · <?= cmp_h((string)$entity['tipo']) ?></div>
      <?php endif; ?>
    </div>

    <div class="cmp-actions">
      <a class="cmp-btn" href="campeonatos_entidades.php">Volver al listado</a>
      <a class="cmp-btn" href="campeonatos_visor.php">Abrir visor</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <?php if ($entity): ?>
    <section class="cmp-ent-metrics">
      <article class="cmp-ent-metric">
        <span>Partidos</span>
        <strong><?= (int)$usage['total'] ?></strong>
      </article>
      <article class="cmp-ent-metric">
        <span>Como local</span>
        <strong><?= (int)$usage['local_count'] ?></strong>
      </article>
      <article class="cmp-ent-metric">
        <span>Como visitante</span>
        <strong><?= (int)$usage['visitante_count'] ?></strong>
      </article>
    </section>

    <section class="cmp-card">
      <h3>Datos principales</h3>

      <form method="post" action="campeonatos_entidades_accion.php" class="cmp-ent-form-grid">
        <input type="hidden" name="action" value="update_entity">
        <input type="hidden" name="id" value="<?= (int)$entity['id'] ?>">

        <label>
          Nombre mostrable
          <input type="text" name="nombre_mostrable" value="<?= cmp_h((string)$entity['nombre_mostrable']) ?>" required>
        </label>

        <label>
          Nombre oficial
          <input type="text" name="nombre_oficial" value="<?= cmp_h((string)$entity['nombre_oficial']) ?>" required>
        </label>

        <label>
          Tipo
          <select name="tipo">
            <option value="club" <?= (string)$entity['tipo'] === 'club' ? 'selected' : '' ?>>club</option>
            <option value="seleccion" <?= (string)$entity['tipo'] === 'seleccion' ? 'selected' : '' ?>>selección</option>
            <option value="combinado" <?= (string)$entity['tipo'] === 'combinado' ? 'selected' : '' ?>>combinado</option>
          </select>
        </label>

        <label>
          Estado
          <select name="is_active">
            <option value="1" <?= (int)$entity['is_active'] === 1 ? 'selected' : '' ?>>activa</option>
            <option value="0" <?= (int)$entity['is_active'] === 0 ? 'selected' : '' ?>>inactiva</option>
          </select>
        </label>

        <label>
          País
          <input type="text" name="pais" value="<?= cmp_h((string)($entity['pais'] ?? '')) ?>">
        </label>

        <label>
          Ciudad
          <input type="text" name="ciudad" value="<?= cmp_h((string)($entity['ciudad'] ?? '')) ?>">
        </label>

        <label>
          Provincia / estado
          <input type="text" name="provincia_estado" value="<?= cmp_h((string)($entity['provincia_estado'] ?? '')) ?>">
        </label>

        <label>
          Normalizado
          <input type="text" value="<?= cmp_h((string)$entity['nombre_normalizado']) ?>" disabled>
        </label>

        <label class="full">
          Notas
          <textarea name="notas" rows="4"><?= cmp_h((string)($entity['notas'] ?? '')) ?></textarea>
        </label>

        <div class="full cmp-actions">
          <button type="submit" class="cmp-btn cmp-btn-primary">Guardar entidad</button>
        </div>
      </form>
    </section>

    <section class="cmp-card">
      <h3>Agregar alias</h3>

      <form method="post" action="campeonatos_entidades_accion.php" class="cmp-ent-alias-form">
        <input type="hidden" name="action" value="add_alias">
        <input type="hidden" name="id" value="<?= (int)$entity['id'] ?>">

        <label>
          Alias
          <input type="text" name="alias" required placeholder="Boca, C. A. Boca Juniors...">
        </label>

        <label>
          Notas
          <input type="text" name="notas" placeholder="Alias manual, variante encontrada...">
        </label>

        <label>
          Origen
          <select name="origen">
            <option value="manual">manual</option>
            <option value="detected">detected</option>
            <option value="rsssf">rsssf</option>
            <option value="migracion">migracion</option>
          </select>
        </label>

        <button type="submit" class="cmp-btn cmp-btn-primary">Agregar alias</button>
      </form>
    </section>

    <section class="cmp-card">
      <div class="cmp-card-head">
        <h3>Alias existentes</h3>
        <div class="cmp-meta"><?= count($aliases) ?> alias</div>
      </div>

      <table class="cmp-table">
        <thead>
          <tr>
            <th>Alias</th>
            <th>Normalizado</th>
            <th>Origen</th>
            <th>Notas</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($aliases === []): ?>
            <tr>
              <td colspan="5" class="cmp-empty">No hay alias cargados.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($aliases as $alias): ?>
              <tr>
                <td><strong><?= cmp_h((string)$alias['alias']) ?></strong></td>
                <td><code><?= cmp_h((string)$alias['alias_normalizado']) ?></code></td>
                <td><?= cmp_h((string)$alias['origen']) ?></td>
                <td><?= cmp_h((string)($alias['notas'] ?? '')) ?></td>
                <td class="cmp-nowrap">
                  <form method="post" action="campeonatos_entidades_accion.php" onsubmit="return confirm('¿Eliminar este alias?');">
                    <input type="hidden" name="action" value="delete_alias">
                    <input type="hidden" name="id" value="<?= (int)$entity['id'] ?>">
                    <input type="hidden" name="alias_id" value="<?= (int)$alias['id'] ?>">
                    <button type="submit" class="cmp-btn cmp-btn-sm">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="cmp-card">
      <h3>Reprocesar</h3>
      <p class="cmp-meta">
        Después de agregar o corregir alias, podés reprocesar todos los partidos para actualizar entidades vinculadas.
      </p>

      <form method="post" action="campeonatos_entidades_accion.php">
        <input type="hidden" name="action" value="backfill_all">
        <input type="hidden" name="id" value="<?= (int)$entity['id'] ?>">
        <button type="submit" class="cmp-btn">Reprocesar todos los partidos</button>
      </form>
    </section>
  <?php endif; ?>
</section>

<?php cmp_render_footer(); ?>