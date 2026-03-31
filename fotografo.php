<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/fotografos_repo.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$slug = trim((string)($_GET['slug'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

$fotografo = fot_fetch_one($id ?: null, $slug !== '' ? $slug : null);

if (!$fotografo || (int)($fotografo['visible'] ?? 0) !== 1) {
    http_response_code(404);
    $pageTitle = 'Fotógrafo no encontrado';

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
      <section class="fot-empty">
        <h1>Fotógrafo no encontrado</h1>
        <p>La ficha solicitada no existe o no está visible.</p>
        <p><a class="fot-btn" href="<?= fot_h(fot_page_url('fotografos.php')) ?>">Volver a Fotógrafos</a></p>
      </section>
    </main>
    <?php

    if (file_exists(__DIR__ . '/inc/footer.php')) {
        include __DIR__ . '/inc/footer.php';
    } else {
        ?></body></html><?php
    }
    exit;
}

$pageTitle = 'Fotógrafo · ' . (string)$fotografo['nombre_mostrar'];
$sobres = fot_fetch_sobres_by_fotografo((int)$fotografo['id'], $q);
$variantes = fot_fetch_variantes((int)$fotografo['id']);
$imgUrl = fot_build_image_url($fotografo);
$fechas = fot_format_fechas($fotografo);

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
  <nav class="fot-breadcrumb">
    <a href="<?= fot_h(fot_page_url('fotografos.php')) ?>">Fotógrafos</a>
    <span>›</span>
    <span><?= fot_h((string)$fotografo['nombre_mostrar']) ?></span>
  </nav>

  <section class="fot-detail-head">
    <div class="fot-detail-main">
      <h1><?= fot_h((string)$fotografo['nombre_mostrar']) ?></h1>

      <?php if ($fechas !== ''): ?>
        <div class="fot-meta fot-detail-fechas"><?= fot_h($fechas) ?></div>
      <?php endif; ?>

      <div class="fot-meta">
        <?= (int)($fotografo['sobres_count'] ?? 0) ?> sobre<?= ((int)($fotografo['sobres_count'] ?? 0) === 1) ? '' : 's' ?> asociado<?= ((int)($fotografo['sobres_count'] ?? 0) === 1) ? '' : 's' ?>
      </div>

      <?php if (trim((string)($fotografo['bio'] ?? '')) !== ''): ?>
        <div class="fot-bio">
          <?= nl2br(fot_h((string)$fotografo['bio'])) ?>
        </div>
      <?php endif; ?>

      <?php if ($variantes): ?>
        <div class="fot-variantes">
          <strong>Variantes detectadas:</strong>
          <?php foreach ($variantes as $i => $var): ?>
            <span class="fot-chip">
              <?= fot_h((string)$var['autor_raw']) ?>
              <small>(<?= (int)$var['sobres_count'] ?>)</small>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="fot-detail-side">
      <?php if ($imgUrl !== ''): ?>
        <a class="fot-detail-image fot-detail-image-link" href="<?= fot_h($imgUrl) ?>">
          <span>Ver imagen asociada</span>
        </a>
      <?php else: ?>
        <div class="fot-detail-image">
          <span>Sin imagen asociada</span>
        </div>
      <?php endif; ?>
    </aside>
  </section>

  <section class="fot-table-wrap">
    <div class="fot-table-head">
      <h2>Sobres asociados</h2>

      <form method="get" class="fot-inline-search">
        <input type="hidden" name="id" value="<?= (int)$fotografo['id'] ?>">
        <input type="hidden" name="slug" value="<?= fot_h((string)$fotografo['slug']) ?>">
        <input
          type="text"
          name="q"
          value="<?= fot_h($q) ?>"
          placeholder="Filtrar sobres por barcode, título o autor"
          aria-label="Filtrar sobres"
        >
        <button type="submit">Filtrar</button>
        <?php if ($q !== ''): ?>
          <a class="fot-btn fot-btn-light" href="<?= fot_h(fot_page_url('fotografo.php', ['id' => (int)$fotografo['id'], 'slug' => (string)$fotografo['slug']])) ?>">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="fot-table-scroll">
      <table class="fot-table">
        <thead>
          <tr>
            <th>Ver digital</th>
            <th>Barcode</th>
            <th>Fecha</th>
            <th>Título</th>
            <th>Autor inventario</th>
            <th>Raw detectado</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$sobres): ?>
            <tr>
              <td colspan="6" class="fot-empty-cell">No hay sobres para mostrar.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($sobres as $row): ?>
              <?php
                $barcode = (string)($row['barcode'] ?? '');
                $digitalesCount = (int)($row['digitales_count'] ?? 0);
                $verDigitalUrl = fot_page_url('ver_digital.php', ['barcode' => $barcode, 'i' => 0]);
              ?>
              <tr>
                <td>
                  <?php if ($barcode !== ''): ?>
                    <a href="<?= fot_h($verDigitalUrl) ?>">
                      Ver<?= $digitalesCount > 0 ? ' (' . $digitalesCount . ')' : '' ?>
                    </a>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td><?= fot_h($barcode) ?></td>
                <td><?= fot_h((string)($row['fecha'] ?? '')) ?></td>
                <td><?= fot_h((string)($row['titulo'] ?? '')) ?></td>
                <td><?= fot_h((string)($row['autor_inventario'] ?? '')) ?></td>
                <td><?= fot_h((string)($row['raws_detectados'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<?php
if (file_exists(__DIR__ . '/inc/footer.php')) {
    include __DIR__ . '/inc/footer.php';
} else {
    ?></body></html><?php
}