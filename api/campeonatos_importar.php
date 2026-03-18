<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '/../inc/campeonatos_parser.php';
require_once __DIR__ . '/../inc/campeonatos_import_repo.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceType = 'text';
    $sourceValue = trim((string)($_POST['source_value'] ?? ''));

    try {
        $parsed = cmp_parse_import($sourceType, $sourceValue);
        $importId = cmp_import_create($parsed, 'text', null, $sourceValue);
        header('Location: campeonatos_importacion.php?id=' . $importId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultText = (string)($_POST['source_value'] ?? '');
cmp_render_header('Importar campeonato');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<section class="cmp-wrap">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_importaciones.php">Importaciones</a>
    <span>/</span>
    <strong>Nueva importación</strong>
  </nav>

  <?php if ($error): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <form method="post" class="cmp-card cmp-form">
    <div class="cmp-field">
      <label for="source_value">Texto del torneo</label>
      <textarea
        id="source_value"
        name="source_value"
        rows="22"
        placeholder="Pegá acá el texto crudo del torneo (por ahora conviene un torneo por importación: Metropolitano o Nacional)."
      ><?= cmp_h($defaultText) ?></textarea>
      <small class="cmp-help">
        Por ahora este hito trabaja solo con texto pegado. Conviene importar un torneo por vez.
      </small>
    </div>

    <div class="cmp-actions">
      <button type="submit" class="cmp-btn cmp-btn-primary">Importar texto</button>
      <a class="cmp-btn" href="campeonatos_importaciones.php">Volver</a>
    </div>
  </form>

  <section class="cmp-card">
    <h2>Alcance del hito</h2>
    <ul class="cmp-list">
      <li>Entrada por texto pegado.</li>
      <li>Un torneo por importación.</li>
      <li>Detección de fase, grupo, ronda, fecha y partidos.</li>
      <li>Fallback por bloques cuando no hay fechas explícitas.</li>
      <li>Guardado del árbol en staging.</li>
    </ul>
  </section>
</section>

<?php cmp_render_footer(); ?>