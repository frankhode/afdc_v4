<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
if (is_file(__DIR__ . '/inc/auth_v2.php')) {
  require_once __DIR__ . '/inc/auth_v2.php';
  if (function_exists('afdc_v2_session_start')) afdc_v2_session_start();
}
if (function_exists('afdc_v2_require_admin')) {
  afdc_v2_require_admin();
}
require_once __DIR__ . '/inc/exposiciones_helpers.php';

$message = '';
$error = '';
$csrf = function_exists('afdc_v2_csrf_token') ? afdc_v2_csrf_token() : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (function_exists('afdc_v2_csrf_token')) {
      $postedCsrf = (string)($_POST['csrf'] ?? '');
      if ($postedCsrf === '' || !hash_equals(afdc_v2_csrf_token(), $postedCsrf)) {
        throw new RuntimeException('CSRF inválido.');
      }
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create') {
      $newId = expo_create([
        'title' => trim((string)($_POST['title'] ?? 'Nueva exposición')),
        'kicker' => trim((string)($_POST['kicker'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'draft')),
      ]);
      header('Location: expo_editar_v2.php?id=' . $newId . '&created=1');
      exit;
    }

    if ($action === 'delete') {
      expo_delete((int)($_POST['expo_id'] ?? 0));
      $message = 'Exposición eliminada.';
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$rows = expo_get_all();
$statuses = expo_status_options();
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Exposiciones - AFDC</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/exposiciones.css">
</head>
<body class="expo-admin-body">
  <div class="expo-admin-wrap">
    <header class="expo-page-header">
      <div>
        <div class="expo-kicker">AFDC · Backoffice</div>
        <h1>Exposiciones</h1>
        <p class="expo-muted">Administración de contenido curado. Desde acá creás, editás y exportás exposiciones.</p>
      </div>
    </header>

    <?php if ($message !== ''): ?>
      <div class="expo-alert expo-alert-ok"><?= expo_h($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="expo-alert expo-alert-error"><?= expo_h($error) ?></div>
    <?php endif; ?>

    <section class="expo-card expo-create-card">
      <h2>Nueva exposición</h2>
      <form method="post" class="expo-grid-form expo-grid-form-compact">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
        <label>
          <span>Título</span>
          <input type="text" name="title" value="Nueva exposición" required>
        </label>
        <label>
          <span>Categoría / kicker</span>
          <input type="text" name="kicker" value="">
        </label>
        <label>
          <span>Estado</span>
          <select name="status">
            <?php foreach ($statuses as $key => $label): ?>
              <option value="<?= expo_h($key) ?>"><?= expo_h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="expo-form-actions">
          <span>&nbsp;</span>
          <button type="submit" class="btn btn-primary">Crear y editar</button>
        </label>
      </form>
    </section>

    <section class="expo-card">
      <div class="expo-section-head">
        <h2>Exposiciones existentes</h2>
        <span class="expo-muted"><?= count($rows) ?> registro(s)</span>
      </div>

      <?php if (!$rows): ?>
        <p class="expo-empty">Todavía no hay exposiciones cargadas.</p>
      <?php else: ?>
        <div class="expo-table-wrap">
          <table class="expo-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Título</th>
                <th>Plantilla</th>
                <th>Estado</th>
                <th>Piezas</th>
                <th>Actualizada</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <tr>
                  <td><?= (int)$row['id'] ?></td>
                  <td>
                    <strong><?= expo_h((string)$row['title']) ?></strong>
                    <?php if (!empty($row['subtitle'])): ?>
                      <div class="expo-muted small"><?= expo_h((string)$row['subtitle']) ?></div>
                    <?php endif; ?>
                    <div class="expo-muted small">slug: <?= expo_h((string)$row['slug']) ?></div>
                  </td>
                  <td><?= expo_h((string)$row['template_name']) ?></td>
                  <td><span class="expo-status expo-status-<?= expo_h((string)$row['status']) ?>"><?= expo_h($statuses[(string)$row['status']] ?? (string)$row['status']) ?></span></td>
                  <td><?= (int)$row['visible_pieces'] ?> / <?= (int)$row['total_pieces'] ?></td>
                  <td><?= expo_h((string)$row['updated_at']) ?></td>
                  <td>
                    <div class="expo-actions">
                      <a class="btn btn-small" href="expo_editar_v2.php?id=<?= (int)$row['id'] ?>">Editar</a>
                      <a class="btn btn-small" href="expo_ver.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">Ver</a>
                      <a class="btn btn-small" href="export_portable.php?expo_id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">Exportar</a>
                      <form method="post" onsubmit="return confirm('¿Eliminar esta exposición y todas sus piezas?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf" value="<?= expo_h($csrf) ?>">
                        <input type="hidden" name="expo_id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger">Eliminar</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
