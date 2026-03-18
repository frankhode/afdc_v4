<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}
require_once __DIR__ . '/inc/exposiciones_helpers.php';

$expoId = (int)($_GET['id'] ?? $_POST['expo_id'] ?? 0);
if ($expoId <= 0) {
  http_response_code(400);
  echo 'Falta id de exposición.';
  exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  try {
    if ($action === 'save_expo') {
      expo_update($expoId, [
        'slug' => $_POST['slug'] ?? '',
        'title' => $_POST['title'] ?? '',
        'kicker' => $_POST['kicker'] ?? '',
        'subtitle' => $_POST['subtitle'] ?? '',
        'intro_html' => $_POST['intro_html'] ?? '',
        'template_name' => $_POST['template_name'] ?? 'futbolistas_equipos',
        'source_collection_id' => (int)($_POST['source_collection_id'] ?? 0),
        'hero_type' => $_POST['hero_type'] ?? 'imagen',
        'hero_ref_id' => $_POST['hero_ref_id'] ?? '',
        'hero_pos_x' => $_POST['hero_pos_x'] ?? '50%',
        'hero_pos_y' => $_POST['hero_pos_y'] ?? '35%',
        'hero_height_px' => (int)($_POST['hero_height_px'] ?? 520),
        'hero_overlay_opacity' => (float)($_POST['hero_overlay_opacity'] ?? 0.35),
        'cta_label' => $_POST['cta_label'] ?? 'Explorar colección',
        'cta_target' => $_POST['cta_target'] ?? 'viewer.html',
        'status' => $_POST['status'] ?? 'draft',
      ]);
      $message = 'Cabecera de la exposición actualizada.';
    }

    if ($action === 'import_collection') {
      $collectionId = (int)($_POST['import_collection_id'] ?? 0);
      $append = !empty($_POST['append_mode']);
      $inserted = expo_import_collection_images($expoId, $collectionId, $append);
      $message = 'Se importaron ' . $inserted . ' pieza(s) desde la colección.';
    }

    if ($action === 'save_piece') {
      expo_update_piece((int)($_POST['piece_id'] ?? 0), [
        'title' => $_POST['piece_title'] ?? '',
        'subtitle' => $_POST['piece_subtitle'] ?? '',
        'caption_html' => $_POST['piece_caption_html'] ?? '',
        'sort_order' => (int)($_POST['piece_sort_order'] ?? 0),
        'is_featured' => !empty($_POST['piece_is_featured']) ? 1 : 0,
        'is_hidden' => !empty($_POST['piece_is_hidden']) ? 1 : 0,
      ]);
      $message = 'Pieza actualizada.';
    }

    if ($action === 'piece_delete') {
      expo_piece_delete((int)($_POST['piece_id'] ?? 0));
      $message = 'Pieza eliminada de la exposición.';
    }

    if ($action === 'piece_move_up') {
      expo_piece_move((int)($_POST['piece_id'] ?? 0), -1);
      $message = 'Pieza movida.';
    }

    if ($action === 'piece_move_down') {
      expo_piece_move((int)($_POST['piece_id'] ?? 0), 1);
      $message = 'Pieza movida.';
    }

    if ($action === 'set_hero') {
      $pieceId = (int)($_POST['piece_id'] ?? 0);
      $pieces = expo_get_pieces($expoId);
      foreach ($pieces as $piece) {
        if ((int)$piece['id'] === $pieceId) {
          expo_update($expoId, [
            'slug' => $_POST['slug_fallback'] ?? (expo_get($expoId)['slug'] ?? ''),
            'title' => $_POST['title_fallback'] ?? (expo_get($expoId)['title'] ?? ''),
            'kicker' => expo_get($expoId)['kicker'] ?? '',
            'subtitle' => expo_get($expoId)['subtitle'] ?? '',
            'intro_html' => expo_get($expoId)['intro_html'] ?? '',
            'template_name' => expo_get($expoId)['template_name'] ?? 'futbolistas_equipos',
            'source_collection_id' => (int)(expo_get($expoId)['source_collection_id'] ?? 0),
            'hero_type' => (string)$piece['piece_type'],
            'hero_ref_id' => (string)$piece['ref_id'],
            'hero_pos_x' => expo_get($expoId)['hero_pos_x'] ?? '50%',
            'hero_pos_y' => expo_get($expoId)['hero_pos_y'] ?? '35%',
            'hero_height_px' => (int)(expo_get($expoId)['hero_height_px'] ?? 520),
            'hero_overlay_opacity' => (float)(expo_get($expoId)['hero_overlay_opacity'] ?? 0.35),
            'cta_label' => expo_get($expoId)['cta_label'] ?? 'Explorar colección',
            'cta_target' => expo_get($expoId)['cta_target'] ?? 'viewer.html',
            'status' => expo_get($expoId)['status'] ?? 'draft',
          ]);
          $message = 'Hero actualizado desde la pieza seleccionada.';
          break;
        }
      }
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$expo = expo_get($expoId);
if (!$expo) {
  http_response_code(404);
  echo 'No se encontró la exposición.';
  exit;
}

$pieces = expo_get_pieces($expoId);
$collections = expo_get_collections();
$templateOptions = expo_template_options();
$statusOptions = expo_status_options();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Editar exposición - <?= expo_h((string)$expo['title']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/exposiciones.css">
</head>
<body class="expo-admin-body">
  <div class="expo-admin-wrap expo-admin-wrap-wide">
    <header class="expo-page-header">
      <div>
        <div class="expo-kicker">AFDC · Exposiciones</div>
        <h1><?= expo_h((string)$expo['title']) ?></h1>
        <p class="expo-muted">Backoffice editorial. Acá definís la portada, hero y piezas de la muestra.</p>
      </div>
      <div class="expo-header-actions">
        <a class="btn" href="exposiciones.php">← Volver</a>
        <a class="btn" href="expo_ver.php?id=<?= $expoId ?>" target="_blank" rel="noopener">Ver</a>
        <a class="btn btn-primary" href="export_portable.php?expo_id=<?= $expoId ?>" target="_blank" rel="noopener">Exportar</a>
      </div>
    </header>

    <?php if (!empty($_GET['created'])): ?>
      <div class="expo-alert expo-alert-ok">Exposición creada. Ya podés completar la portada y sumar piezas.</div>
    <?php endif; ?>
    <?php if ($message !== ''): ?>
      <div class="expo-alert expo-alert-ok"><?= expo_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="expo-alert expo-alert-error"><?= expo_h($error) ?></div>
    <?php endif; ?>

    <section class="expo-card">
      <div class="expo-section-head">
        <h2>Portada / identidad</h2>
      </div>
      <form method="post" class="expo-grid-form">
        <input type="hidden" name="action" value="save_expo">
        <input type="hidden" name="expo_id" value="<?= $expoId ?>">

        <label>
          <span>Título</span>
          <input type="text" name="title" value="<?= expo_h((string)$expo['title']) ?>" required>
        </label>
        <label>
          <span>Slug</span>
          <input type="text" name="slug" value="<?= expo_h((string)$expo['slug']) ?>">
        </label>
        <label>
          <span>Kicker / categoría</span>
          <input type="text" name="kicker" value="<?= expo_h((string)$expo['kicker']) ?>">
        </label>
        <label>
          <span>Subtítulo</span>
          <input type="text" name="subtitle" value="<?= expo_h((string)$expo['subtitle']) ?>">
        </label>
        <label>
          <span>Plantilla</span>
          <select name="template_name">
            <?php foreach ($templateOptions as $key => $label): ?>
              <option value="<?= expo_h($key) ?>" <?= ((string)$expo['template_name'] === $key ? 'selected' : '') ?>><?= expo_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Estado</span>
          <select name="status">
            <?php foreach ($statusOptions as $key => $label): ?>
              <option value="<?= expo_h($key) ?>" <?= ((string)$expo['status'] === $key ? 'selected' : '') ?>><?= expo_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Colección origen</span>
          <select name="source_collection_id">
            <option value="0">—</option>
            <?php foreach ($collections as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((int)$expo['source_collection_id'] === (int)$c['id'] ? 'selected' : '') ?>>#<?= (int)$c['id'] ?> · <?= expo_h((string)$c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Hero tipo</span>
          <select name="hero_type">
            <option value="imagen" <?= ((string)$expo['hero_type'] === 'imagen' ? 'selected' : '') ?>>Imagen</option>
            <option value="recorte_impreso" <?= ((string)$expo['hero_type'] === 'recorte_impreso' ? 'selected' : '') ?>>Recorte impreso</option>
          </select>
        </label>
        <label>
          <span>Hero ref_id</span>
          <input type="text" name="hero_ref_id" value="<?= expo_h((string)$expo['hero_ref_id']) ?>">
        </label>
        <label>
          <span>Posición X</span>
          <input type="text" name="hero_pos_x" value="<?= expo_h((string)$expo['hero_pos_x']) ?>" placeholder="50%">
        </label>
        <label>
          <span>Posición Y</span>
          <input type="text" name="hero_pos_y" value="<?= expo_h((string)$expo['hero_pos_y']) ?>" placeholder="35%">
        </label>
        <label>
          <span>Altura hero (px)</span>
          <input type="number" name="hero_height_px" value="<?= (int)$expo['hero_height_px'] ?>" min="240" step="10">
        </label>
        <label>
          <span>Overlay (0 a 1)</span>
          <input type="number" name="hero_overlay_opacity" value="<?= expo_h((string)$expo['hero_overlay_opacity']) ?>" min="0" max="1" step="0.05">
        </label>
        <label>
          <span>Texto botón</span>
          <input type="text" name="cta_label" value="<?= expo_h((string)$expo['cta_label']) ?>">
        </label>
        <label>
          <span>Destino botón</span>
          <input type="text" name="cta_target" value="<?= expo_h((string)$expo['cta_target']) ?>">
        </label>
        <label class="expo-grid-form-full">
          <span>Intro HTML</span>
          <textarea name="intro_html" rows="8"><?= expo_h((string)$expo['intro_html']) ?></textarea>
        </label>
        <div class="expo-form-actions expo-grid-form-full">
          <button type="submit" class="btn btn-primary">Guardar cabecera</button>
        </div>
      </form>
    </section>

    <section class="expo-card">
      <div class="expo-section-head">
        <h2>Importar piezas</h2>
        <span class="expo-muted">Por ahora, imágenes desde colección.</span>
      </div>
      <form method="post" class="expo-grid-form expo-grid-form-compact">
        <input type="hidden" name="action" value="import_collection">
        <input type="hidden" name="expo_id" value="<?= $expoId ?>">
        <label>
          <span>Colección</span>
          <select name="import_collection_id" required>
            <option value="">Seleccionar…</option>
            <?php foreach ($collections as $c): ?>
              <option value="<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> · <?= expo_h((string)$c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="expo-inline-check">
          <span>Modo</span>
          <label><input type="checkbox" name="append_mode" value="1" checked> Agregar sin borrar piezas existentes</label>
        </label>
        <label class="expo-form-actions">
          <span>&nbsp;</span>
          <button type="submit" class="btn">Importar</button>
        </label>
      </form>
    </section>

    <section class="expo-card">
      <div class="expo-section-head">
        <h2>Piezas de la exposición</h2>
        <span class="expo-muted"><?= count($pieces) ?> pieza(s)</span>
      </div>

      <?php if (!$pieces): ?>
        <p class="expo-empty">Todavía no hay piezas cargadas.</p>
      <?php else: ?>
        <div class="expo-piece-list">
          <?php foreach ($pieces as $piece): ?>
            <article class="expo-piece-card <?= !empty($piece['is_hidden']) ? 'is-hidden' : '' ?>">
              <div class="expo-piece-thumb">
                <?php if (!empty($piece['thumb_url'])): ?>
                  <img src="<?= expo_h((string)$piece['thumb_url']) ?>" alt="thumb">
                <?php else: ?>
                  <div class="expo-thumb-placeholder">Sin thumb</div>
                <?php endif; ?>
              </div>
              <div class="expo-piece-main">
                <div class="expo-piece-meta">
                  <span class="expo-pill">#<?= (int)$piece['id'] ?></span>
                  <span class="expo-pill"><?= expo_h((string)$piece['piece_type']) ?></span>
                  <span class="expo-pill">ref: <?= expo_h((string)$piece['ref_id']) ?></span>
                  <?php if ((string)$expo['hero_type'] === (string)$piece['piece_type'] && (string)$expo['hero_ref_id'] === (string)$piece['ref_id']): ?>
                    <span class="expo-pill expo-pill-hero">Hero</span>
                  <?php endif; ?>
                </div>

                <form method="post" class="expo-piece-form">
                  <input type="hidden" name="action" value="save_piece">
                  <input type="hidden" name="expo_id" value="<?= $expoId ?>">
                  <input type="hidden" name="piece_id" value="<?= (int)$piece['id'] ?>">

                  <label>
                    <span>Título</span>
                    <input type="text" name="piece_title" value="<?= expo_h((string)$piece['title']) ?>">
                  </label>
                  <label>
                    <span>Subtítulo</span>
                    <input type="text" name="piece_subtitle" value="<?= expo_h((string)$piece['subtitle']) ?>">
                  </label>
                  <label>
                    <span>Orden</span>
                    <input type="number" name="piece_sort_order" value="<?= (int)$piece['sort_order'] ?>" step="1">
                  </label>
                  <label class="expo-inline-check">
                    <span>Visibilidad</span>
                    <label><input type="checkbox" name="piece_is_featured" value="1" <?= !empty($piece['is_featured']) ? 'checked' : '' ?>> Destacada</label>
                    <label><input type="checkbox" name="piece_is_hidden" value="1" <?= !empty($piece['is_hidden']) ? 'checked' : '' ?>> Oculta</label>
                  </label>
                  <label class="expo-grid-form-full">
                    <span>Caption HTML</span>
                    <textarea name="piece_caption_html" rows="3"><?= expo_h((string)$piece['caption_html']) ?></textarea>
                  </label>
                  <div class="expo-piece-actions">
                    <button type="submit" class="btn btn-small">Guardar pieza</button>
                  </div>
                </form>

                <div class="expo-piece-actions">
                  <form method="post">
                    <input type="hidden" name="action" value="piece_move_up">
                    <input type="hidden" name="expo_id" value="<?= $expoId ?>">
                    <input type="hidden" name="piece_id" value="<?= (int)$piece['id'] ?>">
                    <button type="submit" class="btn btn-small">↑ Subir</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="piece_move_down">
                    <input type="hidden" name="expo_id" value="<?= $expoId ?>">
                    <input type="hidden" name="piece_id" value="<?= (int)$piece['id'] ?>">
                    <button type="submit" class="btn btn-small">↓ Bajar</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="set_hero">
                    <input type="hidden" name="expo_id" value="<?= $expoId ?>">
                    <input type="hidden" name="piece_id" value="<?= (int)$piece['id'] ?>">
                    <input type="hidden" name="slug_fallback" value="<?= expo_h((string)$expo['slug']) ?>">
                    <input type="hidden" name="title_fallback" value="<?= expo_h((string)$expo['title']) ?>">
                    <button type="submit" class="btn btn-small">Usar como hero</button>
                  </form>
                  <form method="post" onsubmit="return confirm('¿Quitar esta pieza de la exposición?');">
                    <input type="hidden" name="action" value="piece_delete">
                    <input type="hidden" name="expo_id" value="<?= $expoId ?>">
                    <input type="hidden" name="piece_id" value="<?= (int)$piece['id'] ?>">
                    <button type="submit" class="btn btn-small btn-danger">Quitar</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
