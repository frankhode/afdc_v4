<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/fotografos_repo.php';

$q = trim((string)($_GET['q'] ?? ''));
$rows = fot_fetch_all_visible($q);
$pageTitle = 'Fotógrafos';

if (file_exists(__DIR__ . '/inc/header.php')) {
    include __DIR__ . '/inc/header.php';
} else {
    ?><!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title><?= fot_h($pageTitle) ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link rel="stylesheet" href="<?= fot_h(fot_base_url('assets/fotografos.css')) ?>">
    </head>
    <body><?php
}

?>
<link rel="stylesheet" href="<?= fot_h(fot_base_url('assets/fotografos.css')) ?>">

<main class="fot-main">
  <section class="fot-hero">
    <div>
      <h1>Fotógrafos</h1>
      <p class="fot-subtitle">
        Autoridades normalizadas del archivo con acceso a los sobres asociados.
      </p>
    </div>

    <form method="get" class="fot-search">
      <input
        type="text"
        name="q"
        value="<?= fot_h($q) ?>"
        placeholder="Buscar por nombre o apellido"
        aria-label="Buscar fotógrafo"
      >
      <button type="submit">Buscar</button>
      <?php if ($q !== ''): ?>
        <a class="fot-btn fot-btn-light" href="<?= fot_h(fot_page_url('fotografos.php')) ?>">Limpiar</a>
      <?php endif; ?>
    </form>
  </section>

  <section class="fot-list-head">
    <div><strong><?= count($rows) ?></strong> fotógrafo<?= count($rows) === 1 ? '' : 's' ?></div>
  </section>

  <?php if (!$rows): ?>
    <section class="fot-empty">
      <p>No se encontraron fotógrafos<?= $q !== '' ? ' para la búsqueda actual' : '' ?>.</p>
    </section>
  <?php else: ?>
    <section class="fot-grid">
      <?php foreach ($rows as $row): ?>
        <?php
          $imgUrl = fot_build_image_url($row);
          $fechas = fot_format_fechas($row);
          $bio = fot_excerpt($row['bio'] ?? '', 200);
          $href = fot_page_url('fotografo.php', [
              'id' => (int)$row['id'],
              'slug' => (string)$row['slug'],
          ]);
        ?>
        <article class="fot-card">
          <div class="fot-card-media">
            <?php if ($imgUrl !== ''): ?>
              <a href="<?= fot_h($href) ?>" class="fot-card-thumblink">
                <div class="fot-card-thumb fot-card-thumb-hasimg">
                  <span>Ver imagen</span>
                </div>
              </a>
            <?php else: ?>
              <div class="fot-card-thumb">
                <span>Sin imagen</span>
              </div>
            <?php endif; ?>
          </div>

          <div class="fot-card-body">
            <h2 class="fot-card-title">
              <a href="<?= fot_h($href) ?>"><?= fot_h((string)$row['nombre_mostrar']) ?></a>
            </h2>

            <?php if ($fechas !== ''): ?>
              <div class="fot-meta"><?= fot_h($fechas) ?></div>
            <?php endif; ?>

            <div class="fot-meta">
              <?= (int)$row['sobres_count'] ?> sobre<?= ((int)$row['sobres_count'] === 1) ? '' : 's' ?>
            </div>

            <?php if ($bio !== ''): ?>
              <p class="fot-card-excerpt"><?= fot_h($bio) ?></p>
            <?php endif; ?>

            <div class="fot-card-actions">
              <a class="fot-btn" href="<?= fot_h($href) ?>">Ver ficha</a>
              <?php if ($imgUrl !== ''): ?>
                <a class="fot-btn fot-btn-light" href="<?= fot_h($imgUrl) ?>">Ver imagen</a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>

<?php
if (file_exists(__DIR__ . '/inc/footer.php')) {
    include __DIR__ . '/inc/footer.php';
} else {
    ?></body></html><?php
}