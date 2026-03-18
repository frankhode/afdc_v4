<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/../inc/campeonatos_import_repo.php';

$error = null;
$imports = [];
try {
    $imports = cmp_import_list();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Importaciones de campeonatos');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">
<section class="cmp-wrap">
  <div class="cmp-pagehead">
    <div>
      <p class="cmp-kicker">Campeonatos / staging</p>
      <h2>Importaciones</h2>
    </div>
    <a href="campeonatos_importar.php" class="cmp-btn cmp-btn-primary">Nueva importación</a>
  </div>

  <?php if ($error): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <section class="cmp-card">
    <table class="cmp-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Título</th>
          <th>Temporada</th>
          <th>Estado</th>
          <th>Nodos</th>
          <th>Partidos</th>
          <th>Fuente</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$imports): ?>
        <tr><td colspan="8" class="cmp-empty">Todavía no hay importaciones.</td></tr>
      <?php else: ?>
        <?php foreach ($imports as $row): ?>
          <tr>
            <td>#<?= (int)$row['id'] ?></td>
            <td><?= cmp_h($row['titulo_fuente']) ?></td>
            <td><?= cmp_h((string)$row['temporada_detectada']) ?></td>
            <td><span class="cmp-badge"><?= cmp_h($row['estado']) ?></span></td>
            <td><?= (int)$row['nodos_count'] ?></td>
            <td><?= (int)$row['partidos_count'] ?></td>
            <td><?= cmp_h($row['fuente_tipo']) ?></td>
            <td><a href="campeonatos_importacion.php?id=<?= (int)$row['id'] ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </section>
</section>
<?php cmp_render_footer(); ?>
