<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

$q = trim((string)($_GET['q'] ?? ''));
$tipo = trim((string)($_GET['tipo'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? 'activos'));
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if (!in_array($tipo, ['', 'club', 'seleccion', 'combinado'], true)) {
    $tipo = '';
}

if (!in_array($estado, ['activos', 'inactivos', 'todos'], true)) {
    $estado = 'activos';
}

$rows = [];

try {
    $rows = cmp_ent_list_admin([
        'q' => $q,
        'tipo' => $tipo,
        'estado' => $estado,
    ]);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Entidades de campeonatos', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.cmp-ent-page { display:grid; gap:14px; }
.cmp-ent-head { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap; }
.cmp-ent-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
.cmp-ent-filters label { display:grid; gap:4px; font-weight:600; }
.cmp-ent-table-wrap { overflow:auto; }
.cmp-ent-table { min-width:980px; }
.cmp-ent-muted { color:#6b7280; font-size:12px; }
.cmp-ent-create-grid {
  display:grid;
  grid-template-columns: minmax(220px,1fr) minmax(220px,1fr) 150px auto;
  gap:10px;
  align-items:end;
}
.cmp-ent-create-grid label { display:grid; gap:4px; font-weight:600; }
@media (max-width:900px) {
  .cmp-ent-create-grid { grid-template-columns:1fr; }
}
</style>

<section class="cmp-wrap cmp-ent-page">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_importaciones.php">Importaciones</a>
    <span>/</span>
    <strong>Entidades</strong>
  </nav>

  <div class="cmp-ent-head">
    <div>
      <p class="cmp-kicker">Campeonatos / vocabulario</p>
      <h1>Equipos y entidades</h1>
      <div class="cmp-meta">Administración básica de nombres, tipos y alias.</div>
    </div>

    <div class="cmp-actions">
      <a class="cmp-btn" href="campeonatos_visor.php">Abrir visor</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <section class="cmp-card">
    <h3>Crear entidad rápida</h3>

    <form method="post" action="campeonatos_entidades_accion.php" class="cmp-ent-create-grid">
      <input type="hidden" name="action" value="create_entity">

      <label>
        Nombre mostrable
        <input type="text" name="nombre_mostrable" required placeholder="Club Atlético Peñarol">
      </label>

      <label>
        Nombre oficial
        <input type="text" name="nombre_oficial" placeholder="si se deja vacío usa el mostrable">
      </label>

      <label>
        Tipo
        <select name="tipo">
          <option value="club">club</option>
          <option value="seleccion">selección</option>
          <option value="combinado">combinado</option>
        </select>
      </label>

      <button type="submit" class="cmp-btn cmp-btn-primary">Crear</button>
    </form>
  </section>

  <section class="cmp-card">
    <form method="get" class="cmp-ent-filters">
      <label>
        Buscar
        <input type="text" name="q" value="<?= cmp_h($q) ?>" placeholder="Nombre, alias, normalizado...">
      </label>

      <label>
        Tipo
        <select name="tipo">
          <option value="" <?= $tipo === '' ? 'selected' : '' ?>>Todos</option>
          <option value="club" <?= $tipo === 'club' ? 'selected' : '' ?>>club</option>
          <option value="seleccion" <?= $tipo === 'seleccion' ? 'selected' : '' ?>>selección</option>
          <option value="combinado" <?= $tipo === 'combinado' ? 'selected' : '' ?>>combinado</option>
        </select>
      </label>

      <label>
        Estado
        <select name="estado">
          <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
          <option value="inactivos" <?= $estado === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
          <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option>
        </select>
      </label>

      <button type="submit" class="cmp-btn cmp-btn-primary">Filtrar</button>
      <a href="campeonatos_entidades.php" class="cmp-btn">Limpiar</a>
    </form>
  </section>

  <section class="cmp-card">
    <div class="cmp-card-head">
      <h3>Listado</h3>
      <div class="cmp-meta"><?= count($rows) ?> entidades</div>
    </div>

    <div class="cmp-ent-table-wrap">
      <table class="cmp-table cmp-ent-table">
        <thead>
          <tr>
            <th>Entidad</th>
            <th>Tipo</th>
            <th>Normalizado</th>
            <th>Alias</th>
            <th>Partidos</th>
            <th>Estado</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="7" class="cmp-empty">No hay entidades para esos filtros.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td>
                  <strong><?= cmp_h((string)$row['nombre_mostrable']) ?></strong>
                  <?php if ((string)$row['nombre_oficial'] !== (string)$row['nombre_mostrable']): ?>
                    <div class="cmp-ent-muted"><?= cmp_h((string)$row['nombre_oficial']) ?></div>
                  <?php endif; ?>
                </td>

                <td><?= cmp_h((string)$row['tipo']) ?></td>

                <td><code><?= cmp_h((string)$row['nombre_normalizado']) ?></code></td>

                <td><?= (int)($row['alias_count'] ?? 0) ?></td>

                <td><?= (int)($row['partidos_count'] ?? 0) ?></td>

                <td>
                  <?php if ((int)$row['is_active'] === 1): ?>
                    <span class="cmp-chip">activa</span>
                  <?php else: ?>
                    <span class="cmp-chip cmp-chip-empty">inactiva</span>
                  <?php endif; ?>
                </td>

                <td class="cmp-nowrap">
                  <a class="cmp-btn cmp-btn-sm" href="campeonatos_entidad.php?id=<?= (int)$row['id'] ?>">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>

<?php cmp_render_footer(); ?>