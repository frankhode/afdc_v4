<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';
require_once __DIR__ . '/../inc/campeonatos_entidades_repo.php';

$importId = (int)($_GET['id'] ?? 0);
$filter = trim((string)($_GET['filter'] ?? 'pending'));
$q = trim((string)($_GET['q'] ?? ''));
$message = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if (!in_array($filter, ['pending', 'resolved', 'all'], true)) {
    $filter = 'pending';
}

$import = null;
$rows = [];
$entities = [];
$stats = [
    'total' => 0,
    'local_resueltos' => 0,
    'visitante_resueltos' => 0,
    'local_normalizados' => 0,
    'visitante_normalizados' => 0,
];

try {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    $import = cmp_edit_get_import($importId);
    if (!$import) {
        throw new RuntimeException('La importación no existe.');
    }

    $stats = cmp_ent_get_import_entity_stats($importId);
    $rows = cmp_ent_detected_team_texts_for_import($importId);
    $entities = cmp_ent_list_all();

    $rows = array_values(array_filter($rows, function (array $row) use ($filter, $q): bool {
        $estado = (string)($row['estado'] ?? 'pendiente');

        if ($filter === 'pending' && $estado !== 'pendiente') {
            return false;
        }

        if ($filter === 'resolved' && $estado !== 'resuelto') {
            return false;
        }

        if ($q !== '') {
            $haystack = cmp_ent_normalize_name(
                (string)($row['equipo_texto'] ?? '') . ' ' .
                (string)($row['entidad_nombre'] ?? '') . ' ' .
                (string)($row['normalizado'] ?? '')
            );
            $needle = cmp_ent_normalize_name($q);

            if ($needle !== '' && !str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }));
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Normalizar equipos', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.cmp-normalizador-page {
  display: grid;
  gap: 14px;
}

.cmp-normalizador-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}

.cmp-normalizador-metrics {
  display: grid;
  grid-template-columns: repeat(5, minmax(120px, 1fr));
  gap: 10px;
}

.cmp-normalizador-metric {
  border: 1px solid #d9dde4;
  border-radius: 12px;
  background: #fff;
  padding: 10px 12px;
}

.cmp-normalizador-metric span {
  display: block;
  color: #6b7280;
  font-size: 12px;
}

.cmp-normalizador-metric strong {
  display: block;
  font-size: 21px;
  line-height: 1.2;
}

.cmp-normalizador-filters {
  display: flex;
  gap: 10px;
  align-items: end;
  flex-wrap: wrap;
}

.cmp-normalizador-filters label {
  display: grid;
  gap: 4px;
  font-weight: 600;
}

.cmp-normalizador-table-wrap {
  overflow: auto;
}

.cmp-normalizador-table {
  min-width: 1180px;
}

.cmp-normalizador-text {
  font-weight: 700;
}

.cmp-normalizador-example {
  color: #6b7280;
  font-size: 12px;
  margin-top: 3px;
}

.cmp-normalizador-action {
  display: flex;
  gap: 8px;
  align-items: center;
}

.cmp-normalizador-action select {
  min-width: 260px;
}

.cmp-normalizador-status-ok {
  color: #047857;
  font-weight: 700;
}

.cmp-normalizador-status-pending {
  color: #b45309;
  font-weight: 700;
}

@media (max-width: 1000px) {
  .cmp-normalizador-metrics {
    grid-template-columns: repeat(2, minmax(120px, 1fr));
  }
}

.cmp-entity-search-wrap {
  position: relative;
  display: grid;
  gap: 4px;
  min-width: 330px;
}

.cmp-entity-search {
  width: 100%;
  min-width: 0;
}

.cmp-entity-suggestions {
  position: absolute;
  z-index: 30;
  top: 100%;
  left: 0;
  right: 0;
  max-height: 260px;
  overflow: auto;
  border: 1px solid #cfd6df;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 8px 18px rgba(0,0,0,.12);
  display: none;
}

.cmp-entity-suggestions.is-open {
  display: block;
}

.cmp-entity-suggestion {
  padding: 8px 10px;
  cursor: pointer;
  border-bottom: 1px solid #edf1f5;
}

.cmp-entity-suggestion:last-child {
  border-bottom: 0;
}

.cmp-entity-suggestion.is-active,
.cmp-entity-suggestion:hover {
  background: #eef4ff;
}

.cmp-entity-suggestion strong {
  display: block;
}

.cmp-entity-suggestion small {
  display: block;
  color: #6b7280;
  margin-top: 2px;
}

.cmp-normalizador-action-stack {
  display: grid;
  gap: 8px;
}
</style>

<section class="cmp-wrap cmp-normalizador-page">
  <nav class="cmp-breadcrumbs">
    <a href="campeonatos_importaciones.php">Importaciones</a>
    <span>/</span>
    <?php if ($importId > 0): ?>
      <a href="campeonatos_importacion.php?id=<?= (int)$importId ?>">Importación #<?= (int)$importId ?></a>
      <span>/</span>
    <?php endif; ?>
    <strong>Normalizar equipos</strong>
  </nav>

  <div class="cmp-normalizador-head">
    <div>
      <p class="cmp-kicker">Entidades / alias</p>
      <h1>Normalizar equipos</h1>
      <?php if ($import): ?>
        <div class="cmp-meta">
          Importación #<?= (int)$importId ?> · <?= cmp_h((string)($import['titulo_fuente'] ?? '')) ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="cmp-actions">
      <a class="cmp-btn" href="campeonatos_importacion.php?id=<?= (int)$importId ?>">Volver a importación</a>
      <a class="cmp-btn" href="campeonatos_visor.php">Abrir visor</a>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="cmp-alert cmp-alert-success"><?= cmp_h($message) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <section class="cmp-normalizador-metrics">
    <article class="cmp-normalizador-metric">
      <span>Partidos</span>
      <strong><?= (int)$stats['total'] ?></strong>
    </article>

    <article class="cmp-normalizador-metric">
      <span>Locales resueltos</span>
      <strong><?= (int)$stats['local_resueltos'] ?></strong>
    </article>

    <article class="cmp-normalizador-metric">
      <span>Visitantes resueltos</span>
      <strong><?= (int)$stats['visitante_resueltos'] ?></strong>
    </article>

    <article class="cmp-normalizador-metric">
      <span>Locales normalizados</span>
      <strong><?= (int)$stats['local_normalizados'] ?></strong>
    </article>

    <article class="cmp-normalizador-metric">
      <span>Visitantes normalizados</span>
      <strong><?= (int)$stats['visitante_normalizados'] ?></strong>
    </article>
  </section>

  <section class="cmp-card">
    <form method="get" class="cmp-normalizador-filters">
      <input type="hidden" name="id" value="<?= (int)$importId ?>">

      <label>
        Ver
        <select name="filter">
          <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pendientes</option>
          <option value="resolved" <?= $filter === 'resolved' ? 'selected' : '' ?>>Resueltos</option>
          <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Todos</option>
        </select>
      </label>

      <label>
        Buscar
        <input type="text" name="q" value="<?= cmp_h($q) ?>" placeholder="Equipo, alias, normalizado...">
      </label>

      <button type="submit" class="cmp-btn cmp-btn-primary">Filtrar</button>
      <a class="cmp-btn" href="campeonatos_normalizar_equipos.php?id=<?= (int)$importId ?>">Limpiar</a>
    </form>
  </section>

  <section class="cmp-card">
    <div class="cmp-card-head">
      <h3>Textos detectados</h3>
      <div class="cmp-meta"><?= count($rows) ?> filas visibles</div>
    </div>

    <div class="cmp-normalizador-table-wrap">
      <table class="cmp-table cmp-normalizador-table">
        <thead>
          <tr>
            <th>Texto detectado</th>
            <th>Apariciones</th>
            <th>Normalizado</th>
            <th>Estado</th>
            <th>Entidad actual</th>
            <th>Asignar / crear entidad</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr>
              <td colspan="6" class="cmp-empty">No hay textos para mostrar con este filtro.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $raw = (string)$row['equipo_texto'];
                $isResolved = (string)$row['estado'] === 'resuelto';
              ?>
              <tr>
                <td>
                  <div class="cmp-normalizador-text"><?= cmp_h($raw) ?></div>
                  <?php if (!empty($row['example_line'])): ?>
                    <div class="cmp-normalizador-example">
                      Ejemplo: <?= cmp_h((string)$row['example_line']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td>
                  <strong><?= (int)$row['total_count'] ?></strong>
                  <div class="cmp-normalizador-example">
                    Local: <?= (int)$row['local_count'] ?> · Visitante: <?= (int)$row['visitante_count'] ?>
                  </div>
                </td>

                <td>
                  <code><?= cmp_h((string)$row['normalizado']) ?></code>
                </td>

                <td>
                  <?php if ($isResolved): ?>
                    <span class="cmp-normalizador-status-ok">Resuelto</span>
                  <?php else: ?>
                    <span class="cmp-normalizador-status-pending">Pendiente</span>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($isResolved): ?>
                    <strong><?= cmp_h((string)$row['entidad_nombre']) ?></strong>
                    <div class="cmp-normalizador-example">ID <?= (int)$row['entidad_id'] ?></div>
                  <?php else: ?>
                    <span class="cmp-empty">—</span>
                  <?php endif; ?>
                </td>

                <td>
                  <div class="cmp-normalizador-action-stack">
                    <form method="post"
                          action="campeonatos_normalizar_equipos_accion.php"
                          class="cmp-normalizador-action"
                          data-alias-form>
                      <input type="hidden" name="action" value="add_alias">
                      <input type="hidden" name="import_id" value="<?= (int)$importId ?>">
                      <input type="hidden" name="alias" value="<?= cmp_h($raw) ?>">
                      <input type="hidden" name="filter" value="<?= cmp_h($filter) ?>">
                      <input type="hidden" name="q" value="<?= cmp_h($q) ?>">
                      <input type="hidden" name="entidad_id" value="" data-entity-id>

                      <div class="cmp-entity-search-wrap">
                        <input
                          type="text"
                          class="cmp-entity-search"
                          placeholder="Buscar entidad... Enter asigna"
                          autocomplete="off"
                          data-entity-search
                          data-alias="<?= cmp_h($raw) ?>"
                        >
                        <div class="cmp-entity-suggestions" data-entity-suggestions></div>
                      </div>

                      <button type="submit" class="cmp-btn cmp-btn-sm" data-alias-submit disabled>
                        Agregar alias
                      </button>
                    </form>

                    <?php if (!$isResolved): ?>
                      <form method="post"
                            action="campeonatos_normalizar_equipos_accion.php"
                            class="cmp-normalizador-action"
                            data-create-form>
                        <input type="hidden" name="action" value="create_entity">
                        <input type="hidden" name="import_id" value="<?= (int)$importId ?>">
                        <input type="hidden" name="nombre" value="<?= cmp_h($raw) ?>">
                        <input type="hidden" name="filter" value="<?= cmp_h($filter) ?>">
                        <input type="hidden" name="q" value="<?= cmp_h($q) ?>">

                        <select name="tipo" required data-create-type>
                          <option value="club">club</option>
                          <option value="seleccion">selección</option>
                          <option value="combinado">combinado</option>
                        </select>

                        <button type="submit" class="cmp-btn cmp-btn-sm">
                          Crear entidad
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</section>

<script>
(() => {
  const MIN_CHARS = 2;

  function escapeHtml(value) {
    return String(value || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function searchEntities(q) {
    const params = new URLSearchParams({
      action: 'search',
      q: q
    });

    const res = await fetch('campeonatos_entidades_ajax.php?' + params.toString(), {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const json = await res.json();

    if (!json.ok) {
      throw new Error(json.error || 'Error buscando entidades.');
    }

    return Array.isArray(json.results) ? json.results : [];
  }

  function closeSuggestions(box) {
    box.classList.remove('is-open');
    box.innerHTML = '';
    box.dataset.activeIndex = '-1';
  }

  function getSuggestionItems(box) {
    return Array.from(box.querySelectorAll('[data-suggestion-id]'));
  }

  function setActiveSuggestion(box, index) {
    const items = getSuggestionItems(box);
    if (!items.length) {
      box.dataset.activeIndex = '-1';
      return;
    }

    if (index < 0) index = items.length - 1;
    if (index >= items.length) index = 0;

    items.forEach((item, i) => {
      item.classList.toggle('is-active', i === index);
    });

    box.dataset.activeIndex = String(index);
    items[index].scrollIntoView({ block: 'nearest' });
  }

  function chooseSuggestion(input, item, submitNow = false) {
    const form = input.closest('[data-alias-form]');
    if (!form || !item) return;

    const hidden = form.querySelector('[data-entity-id]');
    const submit = form.querySelector('[data-alias-submit]');
    const box = form.querySelector('[data-entity-suggestions]');

    hidden.value = item.dataset.suggestionId || '';
    input.value = item.dataset.suggestionLabel || item.textContent.trim();

    if (submit) {
      submit.disabled = hidden.value === '';
    }

    if (box) {
      closeSuggestions(box);
    }

    if (submitNow && hidden.value !== '') {
      form.submit();
    }
  }

  function renderSuggestions(input, rows) {
    const form = input.closest('[data-alias-form]');
    if (!form) return;

    const box = form.querySelector('[data-entity-suggestions]');
    if (!box) return;

    if (!rows.length) {
      box.innerHTML = '<div class="cmp-entity-suggestion"><strong>Sin resultados</strong><small>Ctrl+Enter crea entidad nueva con el texto detectado</small></div>';
      box.classList.add('is-open');
      box.dataset.activeIndex = '-1';
      return;
    }

    box.innerHTML = rows.map((row, index) => {
      const label = row.nombre_mostrable || '';
      const tipo = row.tipo || '';
      const oficial = row.nombre_oficial || '';
      const alias = row.alias_match || '';
      const sub = [tipo, oficial && oficial !== label ? oficial : '', alias ? 'alias: ' + alias : '']
        .filter(Boolean)
        .join(' · ');

      return `
        <div class="cmp-entity-suggestion ${index === 0 ? 'is-active' : ''}"
             data-suggestion-id="${escapeHtml(row.id)}"
             data-suggestion-label="${escapeHtml(label)}">
          <strong>${escapeHtml(label)}</strong>
          <small>${escapeHtml(sub)}</small>
        </div>
      `;
    }).join('');

    box.classList.add('is-open');
    box.dataset.activeIndex = '0';

    getSuggestionItems(box).forEach(item => {
      item.addEventListener('mousedown', ev => {
        ev.preventDefault();
        chooseSuggestion(input, item, false);
      });

      item.addEventListener('dblclick', ev => {
        ev.preventDefault();
        chooseSuggestion(input, item, true);
      });
    });
  }

  function setupEntityInput(input, index) {
    let timer = null;
    let lastQuery = '';

    input.addEventListener('input', () => {
      const form = input.closest('[data-alias-form]');
      if (!form) return;

      const hidden = form.querySelector('[data-entity-id]');
      const submit = form.querySelector('[data-alias-submit]');
      const box = form.querySelector('[data-entity-suggestions]');

      if (hidden) hidden.value = '';
      if (submit) submit.disabled = true;

      const q = input.value.trim();

      if (timer) {
        clearTimeout(timer);
      }

      if (q.length < MIN_CHARS) {
        if (box) closeSuggestions(box);
        return;
      }

      timer = setTimeout(async () => {
        try {
          lastQuery = q;
          const rows = await searchEntities(q);

          if (input.value.trim() !== lastQuery) {
            return;
          }

          renderSuggestions(input, rows);
        } catch (err) {
          if (box) {
            box.innerHTML = '<div class="cmp-entity-suggestion"><strong>Error</strong><small>' + escapeHtml(err.message || 'No se pudo buscar.') + '</small></div>';
            box.classList.add('is-open');
          }
        }
      }, 180);
    });

    input.addEventListener('keydown', ev => {
      const form = input.closest('[data-alias-form]');
      if (!form) return;

      const box = form.querySelector('[data-entity-suggestions]');
      const createForm = form.parentElement ? form.parentElement.querySelector('[data-create-form]') : null;

      if (ev.ctrlKey && ev.key === 'Enter') {
        if (createForm) {
          ev.preventDefault();
          createForm.submit();
        }
        return;
      }

      if (!box) return;

      const items = getSuggestionItems(box);
      const isOpen = box.classList.contains('is-open');

      if (ev.key === 'ArrowDown') {
        if (!isOpen || !items.length) return;
        ev.preventDefault();
        const current = parseInt(box.dataset.activeIndex || '0', 10);
        setActiveSuggestion(box, current + 1);
        return;
      }

      if (ev.key === 'ArrowUp') {
        if (!isOpen || !items.length) return;
        ev.preventDefault();
        const current = parseInt(box.dataset.activeIndex || '0', 10);
        setActiveSuggestion(box, current - 1);
        return;
      }

      if (ev.key === 'Escape') {
        if (isOpen) {
          ev.preventDefault();
          closeSuggestions(box);
        }
        return;
      }

      if (ev.key === 'Enter') {
        if (isOpen && items.length) {
          ev.preventDefault();
          const current = parseInt(box.dataset.activeIndex || '0', 10);
          const item = items[current] || items[0];

          if (item && item.dataset.suggestionId) {
            chooseSuggestion(input, item, true);
          }
        }
      }
    });

    input.addEventListener('blur', () => {
      const form = input.closest('[data-alias-form]');
      if (!form) return;

      const box = form.querySelector('[data-entity-suggestions]');
      if (!box) return;

      setTimeout(() => closeSuggestions(box), 160);
    });

    if (index === 0) {
      setTimeout(() => input.focus(), 120);
    }
  }

  document.querySelectorAll('[data-entity-search]').forEach((input, index) => {
    setupEntityInput(input, index);
  });
})();
</script>

<?php cmp_render_footer(); ?>