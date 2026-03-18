<?php
require_once __DIR__ . '/inc/bootstrap.php';
$pageTitle = 'Fichero CROAF';
$mainClass = 'container-fluid';
include __DIR__ . '/inc/header.php';
$base = rtrim(BASE_URL, '/');
?>

<link rel="stylesheet" href="<?= $base ?>/assets/fichero.css?v=<?= (int)@filemtime(__DIR__ . '/assets/fichero.css') ?>">
<script>
  // API relativa (funciona aunque el proyecto esté en /afdc_v1)
  window.AFDC_FICHERO_API = <?= json_encode($base . '/api/fichero.php') ?>;
</script>
<script defer src="<?= $base ?>/assets/fichero.js?v=<?= (int)@filemtime(__DIR__ . '/assets/fichero.js') ?>"></script>

<section class="card">  
  <div id="fichero-app" class="fichero">
    <aside class="fichero__side">
      <h1 class="h1">Fichero CROAF</h1>
  <div class="fichero__block">
    <label class="fichero__label" for="fichero-folder">Carpeta</label>
    <select id="fichero-folder" class="fichero__select" disabled></select>
  </div>

  <div class="fichero__block">
    <label class="fichero__label" for="fichero-goto">Ir a ficha</label>
    <div class="fichero__goto">
      <input id="fichero-goto" class="fichero__input" type="number" min="1" step="1" placeholder="N°" disabled>
      <button id="fichero-goto-btn" class="btn" type="button" disabled>Ir</button>
    </div>
  </div>

  <div class="fichero__meta">
    <div id="fichero-status" class="fichero__status muted">Elegí una carpeta…</div>
    <div class="fichero__hint muted" id="fichero-hint"></div>    
  </div>
</aside>


    <section class="fichero__main">
      <div class="fichero__toolbar">
        <button id="fichero-prev10" class="btn" type="button" disabled>⟸ 10</button>
        <button id="fichero-prev" class="btn" type="button" disabled>← Anterior</button>

        <button id="fichero-toggle" class="btn" type="button" disabled>Frente</button>

        <button id="fichero-next" class="btn" type="button" disabled>Siguiente →</button>
        <button id="fichero-next10" class="btn" type="button" disabled>10 ⟹</button>
      </div>

      <div class="fichero__viewer">
  <div id="fichero-empty" class="fichero__empty">
    Elegí una carpeta para empezar
  </div>

  <img id="fichero-img" class="fichero__img" alt="Ficha" draggable="false" hidden />
</div>


    </section>
  </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
