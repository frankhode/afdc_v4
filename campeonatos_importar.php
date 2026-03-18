<?php
declare(strict_types=1);

require_once __DIR__ . '../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();
require_once __DIR__ . '../inc/campeonatos_parser.php';
require_once __DIR__ . '../inc/campeonatos_import_repo.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceType = 'text';
    $sourceValue = trim((string)($_POST['source_value'] ?? ''));

    try {
        $parsed = cmp_parse_import($sourceType, $sourceValue);

        // =============================
        // ENRIQUECIMIENTO (nuevo)
        // =============================
        require_once __DIR__ . '../inc/campeonatos_enriquecedor.php';

        // Normalización de equipos (si hay DB disponible)
        if (function_exists('db')) {
            $dbx = db();
            if ($dbx instanceof mysqli) {
                $parsed = cmp_enrich_import_with_teams($parsed, $dbx);
            }
        }

        // Extracción de goleadores
        $parsed = cmp_enrich_import_with_goal_scorers($parsed);
        // =============================

        $importId = cmp_import_create($parsed, 'text', null, $sourceValue);

        // Si existe la pantalla de revisión dentro de /api, redirige ahí.
        if (is_file(__DIR__ . '/campeonatos_importacion.php')) {
            header('Location: campeonatos_importacion.php?id=' . urlencode((string)$importId));
            exit;
        }

        // Fallback: volver a esta misma página con mensaje OK
        header('Location: campeonatos_importar.php?ok=1&id=' . urlencode((string)$importId));
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultText = (string)($_POST['source_value'] ?? '');
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$importIdMsg = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

cmp_render_header('Importar campeonato');
?>
<link rel="stylesheet" href="assets/css/campeonatos.css">

<section class="cmp-wrap">
  <nav class="cmp-breadcrumbs">
    <?php if (is_file(__DIR__ . '/campeonatos_importaciones.php')): ?>
      <a href="campeonatos_importaciones.php">Importaciones</a>
      <span>/</span>
    <?php endif; ?>
    <strong>Nueva importación</strong>
  </nav>

  <?php if ($ok && $importIdMsg !== ''): ?>
    <div class="cmp-alert cmp-alert-success">
      Importación creada correctamente. ID: <?= cmp_h($importIdMsg) ?>
      <?php if (is_file(__DIR__ . '/campeonatos_importacion.php')): ?>
        —
        <a href="campeonatos_importacion.php?id=<?= urlencode($importIdMsg) ?>">ver importación</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

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
        placeholder="Pegá acá el texto crudo del torneo."
      ><?= cmp_h($defaultText) ?></textarea>
      <small class="cmp-help">
        Por ahora este hito trabaja solo con texto pegado. Conviene importar un torneo por vez.
      </small>
    </div>

    <div class="cmp-actions">
      <button type="submit" class="cmp-btn cmp-btn-primary">Importar texto</button>

      <?php if (is_file(__DIR__ . '/campeonatos_importaciones.php')): ?>
        <a class="cmp-btn" href="campeonatos_importaciones.php">Volver</a>
      <?php endif; ?>
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