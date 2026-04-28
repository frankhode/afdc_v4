<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

require_once __DIR__ . '/../inc/campeonatos_parser.php';
require_once __DIR__ . '/../inc/campeonatos_enriquecedor.php';
require_once __DIR__ . '/../inc/campeonatos_import_repo.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'import_text');

    try {
        if ($action === 'create_special') {
            $specialType = (string)($_POST['special_type'] ?? '');
            $importId = cmp_import_create_special($specialType);

            header('Location: campeonatos_importacion_editar.php?id=' . $importId);
            exit;
        }

        $sourceType = 'text';
        $sourceValue = trim((string)($_POST['source_value'] ?? ''));

        if ($sourceValue === '') {
            throw new InvalidArgumentException('Pegá el texto del torneo antes de importar.');
        }

        $parsed = cmp_parse_import($sourceType, $sourceValue);

        $db = cmp_db();
        $parsed = cmp_enrich_import_with_teams($parsed, $db);
        $parsed = cmp_enrich_import_with_goal_scorers($parsed);

        $importId = cmp_import_create($parsed, 'text', null, $sourceValue);

        header('Location: campeonatos_importacion.php?id=' . $importId);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$defaultText = (string)($_POST['source_value'] ?? '');

cmp_render_header('Importar campeonato', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.cmp-import-mode-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(320px, .8fr);
  gap: 16px;
  align-items: start;
}

.cmp-special-box {
  display: grid;
  gap: 12px;
}

.cmp-special-option {
  border: 1px solid #d9dde4;
  border-radius: 12px;
  padding: 12px 14px;
  background: #fff;
}

.cmp-special-option strong {
  display: block;
  margin-bottom: 4px;
}

.cmp-special-option small {
  display: block;
  color: #6b7280;
  line-height: 1.35;
}

.cmp-special-radio {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  cursor: pointer;
}

.cmp-special-radio input {
  margin-top: 3px;
}

@media (max-width: 1000px) {
  .cmp-import-mode-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<section class="cmp-wrap">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_importaciones.php">Importaciones</a>
    <span>/</span>
    <strong>Nueva importación</strong>
  </nav>

  <?php if ($error): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <div class="cmp-import-mode-grid">
    <form method="post" class="cmp-card cmp-form">
      <input type="hidden" name="action" value="import_text">

      <div class="cmp-pagehead" style="margin-bottom: 12px;">
        <div>
          <p class="cmp-kicker">Importar desde texto</p>
          <h2>Texto de torneo</h2>
        </div>
      </div>

      <div class="cmp-field">
        <label for="source_value">Texto del torneo</label>
        <textarea
          id="source_value"
          name="source_value"
          rows="22"
          placeholder="Pegá acá el texto crudo del torneo. Por ahora conviene un torneo por importación: Metropolitano, Nacional, Primera B, etc."
        ><?= cmp_h($defaultText) ?></textarea>

        <small class="cmp-help">
          Este modo usa el parser: detecta fases, grupos, fechas, partidos, equipos y goleadores cuando puede.
        </small>
      </div>

      <div class="cmp-actions">
        <button type="submit" class="cmp-btn cmp-btn-primary">Importar texto</button>
        <a class="cmp-btn" href="campeonatos_importaciones.php">Volver</a>
      </div>
    </form>

    <form method="post" class="cmp-card cmp-form">
      <input type="hidden" name="action" value="create_special">

      <div class="cmp-pagehead" style="margin-bottom: 12px;">
        <div>
          <p class="cmp-kicker">Crear manualmente</p>
          <h2>Estructura especial</h2>
        </div>
      </div>

      <div class="cmp-special-box">
        <label class="cmp-special-option cmp-special-radio">
          <input type="radio" name="special_type" value="sin_identificar" checked>
          <span>
            <strong>Campeonato sin identificar</strong>
            <small>
              Para partidos que parecen pertenecer a un campeonato, pero todavía no sabemos cuál.
            </small>
          </span>
        </label>

        <label class="cmp-special-option cmp-special-radio">
          <input type="radio" name="special_type" value="amistosos">
          <span>
            <strong>Amistosos y partidos sueltos</strong>
            <small>
              Para amistosos, partidos sin torneo, giras, homenajes o eventos futbolísticos que no dependen de una estructura de campeonato.
            </small>
          </span>
        </label>
      </div>

      <div class="cmp-actions">
        <button type="submit" class="cmp-btn cmp-btn-primary">Crear estructura</button>
      </div>

      <div class="cmp-alert" style="margin-top: 12px;">
        Se crea una estructura editable y vacía. Después podés abrirla en el editor, agregar nodos y cargar partidos manualmente.
      </div>
    </form>
  </div>

  <section class="cmp-card" style="margin-top: 16px;">
    <h2>Alcance</h2>
    <ul class="cmp-list">
      <li>Entrada por texto pegado para torneos importables.</li>
      <li>Creación manual de estructuras especiales.</li>
      <li>Guardado en el mismo staging de importaciones.</li>
      <li>Compatible con editor, dashboard, visor y relacionador.</li>
    </ul>
  </section>
</section>

<?php cmp_render_footer(); ?>