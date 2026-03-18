<?php
// afdc_v1/indices.php
require_once __DIR__ . '/inc/bootstrap.php';

$campos = [
  '600' => '600 — Materia (persona)',
  '610' => '610 — Materia (entidad)',
  '611' => '611 — Materia (evento)',
  '630' => '630 — Materia (título uniforme)',
  '650' => '650 — Materia (tema)',
  '651' => '651 — Materia (geográfico)',
];

$pageTitle = 'Índices (MARC)';
include __DIR__ . '/inc/header.php';
?>
<section class="card">
  <h1 style="margin:0 0 10px; font-size:22px;">Índices (MARC)</h1>
  <p class="small" style="margin:0 0 14px; color:rgba(255,255,255,.70);">
    Elegí un campo para listar términos (materias) y navegar a los sobres relacionados.
  </p>

  <div style="display:grid; gap:12px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
    <?php foreach ($campos as $c => $label): ?>
      <a class="btn" style="display:flex; justify-content:space-between; align-items:center; gap:10px; text-decoration:none;"
         href="indice_marc.php?campo=<?= urlencode($c) ?>">
        <span><?= h($label) ?></span>
        <span style="opacity:.75;">→</span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php include __DIR__ . '/inc/footer.php'; ?>
