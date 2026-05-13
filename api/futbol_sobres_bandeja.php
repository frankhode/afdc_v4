<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/futbol_sobres_clasificacion_repo.php';

$filters = fsb_request_filters($_GET);
$q = $filters['q'];
$estado = $filters['estado'];
$mostrarTrabajados = (string)($_GET['mostrar_trabajados'] ?? '') === '1';

$message = trim((string)($_GET['msg'] ?? ''));
$errorFlash = trim((string)($_GET['error'] ?? ''));

$searchResults = [];
$bandeja = [];
$metricas = ['total' => 0, 'por_estado' => []];
$error = null;

try {
    if ($q !== '') {
        $searchResults = fsb_buscar_titulos($q);
    }

    $bandeja = fsb_listar_bandeja($filters);
    $metricas = fsb_metricas();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

cmp_render_header('Bandeja de sobres de fútbol', 'container-fluid');
?>
<link rel="stylesheet" href="../assets/css/campeonatos.css">

<style>
.fsb-wrap {
  display:grid;
  gap:14px;
  padding-top:10px;
}

.fsb-head {
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
  flex-wrap:wrap;
}

.fsb-head h2 {
  margin:0;
}

.fsb-muted {
  color:#667085;
  font-size:13px;
}

.fsb-grid {
  display:grid;
  grid-template-columns:minmax(320px, 0.78fr) minmax(620px, 1.6fr);
  gap:14px;
  align-items:start;
}

.fsb-card {
  background:#fff;
  border:1px solid #d9dee7;
  border-radius:14px;
  padding:14px;
}

.fsb-search-form {
  display:grid;
  grid-template-columns:minmax(260px, 1fr) minmax(160px, 220px) auto;
  gap:10px;
  align-items:end;
}

.fsb-search-form label {
  display:grid;
  gap:4px;
  font-weight:600;
}

.fsb-search-field {
  position:relative;
}

.fsb-search-form input,
.fsb-search-form select,
.fsb-edit-form input,
.fsb-edit-form select,
.fsb-edit-form textarea {
  width:100%;
  box-sizing:border-box;
  border:1px solid #c8d0da;
  border-radius:8px;
  padding:6px 8px;
  min-height:34px;
  background:#fff;
}

.fsb-search-field input {
  padding-right:34px;
}

.fsb-clear-search {
  position:absolute;
  right:7px;
  top:50%;
  transform:translateY(-50%);
  border:0;
  background:transparent;
  cursor:pointer;
  color:#667085;
  font-size:18px;
  line-height:1;
  padding:0 4px;
}

.fsb-clear-search:hover {
  color:#111827;
}

.fsb-inline-check {
  display:inline-flex !important;
  flex-direction:row !important;
  align-items:center;
  gap:6px !important;
  white-space:nowrap;
  font-weight:500 !important;
  color:#344054;
  padding-bottom:7px;
}

.fsb-inline-check input {
  width:auto;
  min-height:auto;
}

.fsb-state-strip {
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  max-width:760px;
  justify-content:flex-end;
}

.fsb-results {
  display:grid;
  gap:7px;
  margin-top:12px;
}

.fsb-result {
  border:1px solid #e1e6ed;
  border-radius:10px;
  padding:8px 10px;
  background:#fff;
  display:grid;
  grid-template-columns:minmax(0, 1fr) auto;
  gap:8px;
  align-items:center;
}

.fsb-result-title {
  font-weight:700;
  line-height:1.25;
}

.fsb-result-extra {
  margin-top:4px;
  display:flex;
  gap:6px;
  flex-wrap:wrap;
  align-items:center;
}

.fsb-result-actions {
  display:flex;
  gap:6px;
  align-items:center;
  justify-content:flex-end;
  flex-wrap:wrap;
}

.fsb-status-badges {
  display:flex;
  gap:6px;
  flex-wrap:wrap;
  align-items:center;
}

.fsb-badge {
  display:inline-flex;
  align-items:center;
  gap:4px;
  padding:3px 8px;
  border-radius:999px;
  border:1px solid #cfd6e2;
  background:#fff;
  color:#475467;
  font-size:12px;
  line-height:1.2;
}

.fsb-badge-ok {
  border-color:#9fd3ad;
  background:#f0fff4;
  color:#166534;
}

.fsb-badge-warn {
  border-color:#f7c873;
  background:#fff8e6;
  color:#92400e;
}

.fsb-badge-link {
  border-color:#9bb7ff;
  background:#eef4ff;
  color:#1d4ed8;
}

.fsb-bandeja-top {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}

.fsb-bandeja-top h3 {
  margin:0;
}

.fsb-bandeja-tools {
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
}

.fsb-bandeja-list {
  display:grid;
  gap:8px;
  margin-top:12px;
}

.fsb-item {
  border:1px solid #d9dee7;
  border-radius:12px;
  background:#fff;
  overflow:hidden;
}

.fsb-item.is-open {
  border-color:#b8c7e6;
}

.fsb-item-head {
  display:grid;
  grid-template-columns:minmax(0, 1fr) auto auto;
  gap:10px;
  padding:9px 12px;
  background:#fafbfd;
  align-items:center;
}

.fsb-main-title {
  font-weight:700;
  line-height:1.25;
  min-width:0;
}

.fsb-compact-state {
  justify-self:end;
}

.fsb-card-actions {
  display:flex;
  gap:8px;
  align-items:center;
  justify-content:flex-end;
}

.fsb-toggle-card {
  white-space:nowrap;
}

.fsb-item-body {
  padding:10px 12px 12px;
  display:none;
  border-top:1px solid #edf1f5;
}

.fsb-item.is-open .fsb-item-body {
  display:block;
}

.fsb-existing-line {
  margin-bottom:8px;
  padding:6px 8px;
  border:1px solid #e1e6ed;
  border-radius:8px;
  background:#fafbfd;
  font-size:12px;
  color:#475467;
}

.fsb-existing-line strong {
  color:#111827;
}

.fsb-edit-form {
  display:grid;
  gap:8px;
}

.fsb-edit-row-4 {
  display:grid;
  grid-template-columns:150px minmax(150px, 1fr) minmax(150px, 1fr) minmax(150px, 1fr);
  gap:8px;
  align-items:end;
}

.fsb-edit-row-3 {
  display:grid;
  grid-template-columns:140px 140px minmax(180px, 1fr);
  gap:8px;
  align-items:end;
}

.fsb-edit-row-notes {
  display:grid;
  grid-template-columns:minmax(260px, 1fr) auto;
  gap:8px;
  align-items:end;
}

.fsb-edit-form label {
  display:grid;
  gap:3px;
  font-weight:600;
  font-size:12px;
}

.fsb-edit-form textarea {
  resize:vertical;
  min-height:40px;
  max-height:120px;
}

.fsb-actions-right {
  display:flex;
  gap:8px;
  align-items:center;
  flex-wrap:wrap;
  justify-content:flex-end;
}

.fsb-delete-form {
  display:inline;
}

.fsb-ac-menu {
  position:fixed;
  z-index:5000;
  display:none;
  max-height:260px;
  overflow:auto;
  min-width:220px;
  max-width:520px;
  background:#fff;
  border:1px solid #c8d0da;
  border-radius:10px;
  box-shadow:0 8px 24px rgba(15, 23, 42, .14);
}

.fsb-ac-menu.is-open {
  display:block;
}

.fsb-ac-item {
  width:100%;
  border:0;
  background:#fff;
  text-align:left;
  padding:8px 10px;
  cursor:pointer;
  border-bottom:1px solid #edf1f5;
}

.fsb-ac-item:last-child {
  border-bottom:0;
}

.fsb-ac-item:hover,
.fsb-ac-item.is-active {
  background:#eef4ff;
}

.fsb-ac-label {
  display:block;
  font-weight:600;
  color:#111827;
  line-height:1.25;
}

.fsb-ac-meta {
  display:block;
  margin-top:2px;
  font-size:11px;
  color:#667085;
}

@media (max-width:1200px) {
  .fsb-grid {
    grid-template-columns:1fr;
  }

  .fsb-state-strip {
    justify-content:flex-start;
  }
}

@media (max-width:900px) {
  .fsb-search-form,
  .fsb-edit-row-4,
  .fsb-edit-row-3,
  .fsb-edit-row-notes {
    grid-template-columns:1fr;
  }

  .fsb-result,
  .fsb-item-head {
    grid-template-columns:1fr;
  }

  .fsb-result-actions,
  .fsb-card-actions,
  .fsb-compact-state,
  .fsb-actions-right {
    justify-content:flex-start;
    justify-self:start;
  }
}
</style>

<section class="cmp-wrap fsb-wrap">
  <div class="fsb-head">
    <div>
      <p class="cmp-kicker">Fútbol / clasificación previa</p>
      <h2>Bandeja de sobres</h2>      
    </div>    
  </div>

  <?php if ($errorFlash !== ''): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($errorFlash) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="cmp-alert cmp-alert-error"><?= cmp_h($error) ?></div>
  <?php endif; ?>

  <section class="fsb-card">
    <form method="get" class="fsb-search-form" id="fsbSearchForm">
      <label>
        Buscar en títulos / registros
        <span class="fsb-search-field">
          <input
            type="text"
            name="q"
            id="fsbSearchInput"
            value="<?= cmp_h($q) ?>"
            placeholder="River, Boca, Nacional 1977, barcode, sys..."
            autocomplete="off"
          >
          <button type="button" class="fsb-clear-search" id="fsbClearSearch" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">×</button>
        </span>
      </label>

      <label>
        Estado en bandeja
        <select name="estado" id="fsbEstadoFilter">
          <option value="">Todos</option>
          <?php foreach (fsb_estados() as $state => $label): ?>
            <option value="<?= cmp_h($state) ?>" <?= $estado === $state ? 'selected' : '' ?>>
              <?= cmp_h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="fsb-inline-check">
        <input type="checkbox" name="mostrar_trabajados" id="fsbMostrarTrabajados" value="1" <?= $mostrarTrabajados ? 'checked' : '' ?>>
        Mostrar ya trabajados
      </label>
    </form>
  </section>

  <div class="fsb-grid">
    <section class="fsb-card">
      <h3>Resultados de búsqueda</h3>

      <?php if ($q === ''): ?>
        <div class="cmp-empty">Buscá por título, barcode, sys, fecha o título de registro.</div>
      <?php else: ?>
        <?php
          $renderedResults = 0;
          ob_start();
        ?>

        <div class="fsb-results">
          <?php foreach ($searchResults as $row): ?>
            <?php
              $barcode = (string)($row['barcode'] ?? '');
              $already = !empty($row['clasificacion_id']);
              $isLinked = (int)($row['vinculos_count'] ?? 0) > 0;
              $isInPartidos = (int)($row['partidos_count'] ?? 0) > 0;

              if (!$mostrarTrabajados && ($already || $isLinked)) {
                  continue;
              }

              $renderedResults++;
            ?>

            <article class="fsb-result">
              <div>
                <div class="fsb-result-title"><?= cmp_h((string)($row['titulo'] ?? '')) ?></div>

                <?php if ($mostrarTrabajados): ?>
                  <div class="fsb-result-extra">
                    <?php if ($already): ?>
                      <span class="fsb-badge fsb-badge-ok"><?= cmp_h(fsb_estado_label((string)$row['clasificacion_estado'])) ?></span>
                    <?php endif; ?>

                    <?php if ($isInPartidos): ?>
                      <span class="fsb-badge fsb-badge-warn">en partidos</span>
                    <?php endif; ?>

                    <?php if ($isLinked): ?>
                      <span class="fsb-badge fsb-badge-link">vinculado</span>
                    <?php endif; ?>
                  </div>
                <?php elseif ($isInPartidos): ?>
                  <div class="fsb-result-extra">
                    <span class="fsb-badge fsb-badge-warn">en partidos</span>
                  </div>
                <?php endif; ?>
              </div>

              <div class="fsb-result-actions">
                <?php if ($barcode !== ''): ?>
                  <a href="../ver_digital.php?barcode=<?= rawurlencode($barcode) ?>&i=0" target="_blank" rel="noopener">Ver</a>
                <?php endif; ?>

                <?php if ($already): ?>
                  <span class="cmp-chip">ya agregado</span>
                <?php else: ?>
                  <form method="post" action="futbol_sobres_bandeja_accion.php">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="barcode" value="<?= cmp_h($barcode) ?>">
                    <input type="hidden" name="return_q" value="<?= cmp_h($q) ?>">
                    <input type="hidden" name="return_estado" value="<?= cmp_h($estado) ?>">
                    <button type="submit" class="cmp-btn cmp-btn-sm cmp-btn-primary">Agregar</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php
          $resultsHtml = (string)ob_get_clean();
        ?>

        <?php if ($renderedResults === 0): ?>
          <div class="cmp-empty">
            No hay resultados visibles para esa búsqueda.
            <?php if (!$mostrarTrabajados): ?>
              Probá activar <strong>Mostrar ya trabajados</strong>.
            <?php endif; ?>
          </div>
        <?php else: ?>
          <?= $resultsHtml ?>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="fsb-card">
      <div class="fsb-bandeja-top">
        <h3>Bandeja de clasificación</h3>

        <?php if ($bandeja): ?>
          <div class="fsb-bandeja-tools">
            <button type="button" class="cmp-btn cmp-btn-sm" id="fsbExpandAll">Expandir todo</button>
            <button type="button" class="cmp-btn cmp-btn-sm" id="fsbCollapseAll">Compactar todo</button>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$bandeja): ?>
        <div class="cmp-empty">No hay sobres en la bandeja para esos filtros.</div>
      <?php else: ?>
        <div class="fsb-bandeja-list" id="fsbBandejaList">
          <?php foreach ($bandeja as $row): ?>
            <?php
              $id = (int)$row['id'];
              $barcode = (string)$row['barcode'];
              $rowEstado = (string)$row['estado'];
              $title = trim((string)($row['titulo'] ?? ''));
              if ($title === '') {
                  $title = $barcode !== '' ? $barcode : 'Sobre sin título';
              }
            ?>

            <article class="fsb-item" data-fsb-card>
              <div class="fsb-item-head">
                <div class="fsb-main-title"><?= cmp_h($title) ?></div>

                <div class="fsb-compact-state">
                  <span class="fsb-badge fsb-badge-ok"><?= cmp_h(fsb_estado_label($rowEstado)) ?></span>
                </div>

                <div class="fsb-card-actions">
                  <button type="button" class="cmp-btn cmp-btn-sm fsb-toggle-card" data-fsb-toggle>Expandir</button>
                </div>
              </div>

              <div class="fsb-item-body">
                <?php if ((int)($row['partidos_count'] ?? 0) > 0 || (int)($row['vinculos_count'] ?? 0) > 0): ?>
                  <div class="fsb-existing-line">
                    <?php if ((int)($row['partidos_count'] ?? 0) > 0): ?>
                      <div>
                        <strong>Partidos:</strong>
                        <?= cmp_h((string)($row['partido_equipo1'] ?? '')) ?>
                        <?php if (!empty($row['partido_equipo2'])): ?>
                          vs <?= cmp_h((string)$row['partido_equipo2']) ?>
                        <?php endif; ?>
                        <?php if (!empty($row['partido_fecha'])): ?>
                          · <?= cmp_h((string)$row['partido_fecha']) ?>
                        <?php endif; ?>
                        <?php if (!empty($row['partido_tituloReg'])): ?>
                          · <?= cmp_h((string)$row['partido_tituloReg']) ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>

                    <?php if ((int)($row['vinculos_count'] ?? 0) > 0): ?>
                      <div>
                        <strong>Vinculado:</strong>
                        <?= cmp_h((string)($row['vinculo_local'] ?? '')) ?>
                        <?php if (!empty($row['vinculo_visitante'])): ?>
                          vs <?= cmp_h((string)$row['vinculo_visitante']) ?>
                        <?php endif; ?>
                        <?php if (!empty($row['vinculos_resumen'])): ?>
                          · <?= cmp_h((string)$row['vinculos_resumen']) ?>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <form method="post" action="futbol_sobres_bandeja_accion.php" class="fsb-edit-form" id="fsb-form-<?= $id ?>">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="return_q" value="<?= cmp_h($q) ?>">
                  <input type="hidden" name="return_estado" value="<?= cmp_h($estado) ?>">

                  <div class="fsb-edit-row-4">
                    <label>
                      Estado
                      <select name="estado">
                        <?php foreach (fsb_estados() as $state => $label): ?>
                          <option value="<?= cmp_h($state) ?>" <?= $rowEstado === $state ? 'selected' : '' ?>>
                            <?= cmp_h($label) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label>
                      Equipo 1
                      <input
                        type="text"
                        name="equipo1_texto"
                        value="<?= cmp_h((string)($row['equipo1_texto'] ?? '')) ?>"
                        class="fsb-ac-input"
                        data-ac-type="equipo"
                        autocomplete="off"
                      >
                    </label>

                    <label>
                      Equipo 2
                      <input
                        type="text"
                        name="equipo2_texto"
                        value="<?= cmp_h((string)($row['equipo2_texto'] ?? '')) ?>"
                        class="fsb-ac-input"
                        data-ac-type="equipo"
                        autocomplete="off"
                      >
                    </label>

                    <label>
                      Equipo principal
                      <input
                        type="text"
                        name="equipo_principal_texto"
                        value="<?= cmp_h((string)($row['equipo_principal_texto'] ?? '')) ?>"
                        class="fsb-ac-input"
                        data-ac-type="equipo"
                        autocomplete="off"
                      >
                    </label>
                  </div>

                  <div class="fsb-edit-row-3">
                    <label>
                      Fecha
                      <input type="text" name="fecha_sugerida" value="<?= cmp_h((string)($row['fecha_sugerida'] ?? '')) ?>" placeholder="19951217">
                    </label>

                    <label>
                      Precisión
                      <?php $fechaPrecision = (string)($row['fecha_precision'] ?? ''); ?>
                      <select name="fecha_precision">
                        <option value="" <?= $fechaPrecision === '' ? 'selected' : '' ?>>Sin definir</option>
                        <option value="exacta" <?= $fechaPrecision === 'exacta' ? 'selected' : '' ?>>Exacta</option>
                        <option value="aproximada" <?= $fechaPrecision === 'aproximada' ? 'selected' : '' ?>>Aproximada</option>
                        <option value="anio" <?= $fechaPrecision === 'anio' ? 'selected' : '' ?>>Solo año</option>
                        <option value="mes" <?= $fechaPrecision === 'mes' ? 'selected' : '' ?>>Año y mes</option>
                        <option value="dia_mes" <?= $fechaPrecision === 'dia_mes' ? 'selected' : '' ?>>Día y mes sin año</option>
                        <option value="dudosa" <?= $fechaPrecision === 'dudosa' ? 'selected' : '' ?>>Dudosa</option>
                        <option value="sin_fecha" <?= $fechaPrecision === 'sin_fecha' ? 'selected' : '' ?>>Sin fecha</option>
                      </select>
                    </label>

                    <label>
                      Campeonato
                      <input
                        type="text"
                        name="campeonato_sugerido_texto"
                        value="<?= cmp_h((string)($row['campeonato_sugerido_texto'] ?? '')) ?>"
                        class="fsb-ac-input"
                        data-ac-type="campeonato"
                        autocomplete="off"
                      >
                    </label>
                  </div>

                  <div class="fsb-edit-row-notes">
                    <label>
                      Notas
                      <textarea name="notas" rows="2" placeholder="Observaciones, dudas, pistas..."><?= cmp_h((string)($row['notas'] ?? '')) ?></textarea>
                    </label>

                    <div class="fsb-actions-right">
                      <?php if ((int)($row['partidos_count'] ?? 0) > 0 || (int)($row['vinculos_count'] ?? 0) > 0): ?>
                        <button
                          type="submit"
                          class="cmp-btn cmp-btn-sm"
                          form="fsb-autocomplete-form-<?= $id ?>"
                        >Autocompletar</button>
                      <?php endif; ?>

                      <button type="submit" class="cmp-btn cmp-btn-sm cmp-btn-primary">Guardar</button>

                      <button
                        type="submit"
                        class="cmp-btn cmp-btn-sm"
                        form="fsb-delete-form-<?= $id ?>"
                      >Quitar</button>
                    </div>
                  </div>
                </form>

                <form method="post" action="futbol_sobres_bandeja_accion.php" id="fsb-autocomplete-form-<?= $id ?>">
                  <input type="hidden" name="action" value="autocomplete">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="return_q" value="<?= cmp_h($q) ?>">
                  <input type="hidden" name="return_estado" value="<?= cmp_h($estado) ?>">
                </form>

                <form method="post" action="futbol_sobres_bandeja_accion.php" id="fsb-delete-form-<?= $id ?>" onsubmit="return confirm('¿Quitar este sobre de la bandeja? No borra el título ni el registro.');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $id ?>">
                  <input type="hidden" name="return_q" value="<?= cmp_h($q) ?>">
                  <input type="hidden" name="return_estado" value="<?= cmp_h($estado) ?>">
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</section>

<script>
(() => {
  const form = document.getElementById('fsbSearchForm');
  const input = document.getElementById('fsbSearchInput');
  const estado = document.getElementById('fsbEstadoFilter');
  const clear = document.getElementById('fsbClearSearch');
  const mostrar = document.getElementById('fsbMostrarTrabajados');

  if (!form || !input || !estado) return;

  let timer = null;
  let lastSubmitted = input.value + '||' + estado.value + '||' + (mostrar && mostrar.checked ? '1' : '0');

  function submitSearch() {
    const current = input.value.trim() + '||' + estado.value + '||' + (mostrar && mostrar.checked ? '1' : '0');
    if (current === lastSubmitted) return;
    lastSubmitted = current;
    form.submit();
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(submitSearch, 360);
  });

  input.addEventListener('keydown', ev => {
    if (ev.key === 'Escape') {
      ev.preventDefault();
      input.value = '';
      submitSearch();
    }
  });

  estado.addEventListener('change', () => {
    clearTimeout(timer);
    submitSearch();
  });

  if (mostrar) {
    mostrar.addEventListener('change', () => {
      clearTimeout(timer);
      submitSearch();
    });
  }

  if (clear) {
    clear.style.display = input.value.trim() ? 'block' : 'none';

    input.addEventListener('input', () => {
      clear.style.display = input.value.trim() ? 'block' : 'none';
    });

    clear.addEventListener('click', () => {
      input.value = '';
      clear.style.display = 'none';
      submitSearch();
    });
  }
})();
</script>

<script>
(() => {
  const cards = Array.from(document.querySelectorAll('[data-fsb-card]'));
  const expandAll = document.getElementById('fsbExpandAll');
  const collapseAll = document.getElementById('fsbCollapseAll');

  if (!cards.length) return;

  function setCard(card, open) {
    card.classList.toggle('is-open', open);
    const btn = card.querySelector('[data-fsb-toggle]');
    if (btn) btn.textContent = open ? 'Compactar' : 'Expandir';
  }

  cards.forEach(card => {
    const btn = card.querySelector('[data-fsb-toggle]');
    if (!btn) return;

    btn.addEventListener('click', () => {
      setCard(card, !card.classList.contains('is-open'));
    });
  });

  if (expandAll) {
    expandAll.addEventListener('click', () => {
      cards.forEach(card => setCard(card, true));
    });
  }

  if (collapseAll) {
    collapseAll.addEventListener('click', () => {
      cards.forEach(card => setCard(card, false));
    });
  }
})();
</script>

<script>
(() => {
  const inputs = Array.from(document.querySelectorAll('.fsb-ac-input'));
  if (!inputs.length) return;

  const menu = document.createElement('div');
  menu.className = 'fsb-ac-menu';
  document.body.appendChild(menu);

  let activeInput = null;
  let activeItems = [];
  let activeIndex = -1;
  let timer = null;
  let requestSeq = 0;

  function escapeHtml(str) {
    return String(str || '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function closeMenu() {
    menu.classList.remove('is-open');
    menu.innerHTML = '';
    activeItems = [];
    activeIndex = -1;
  }

  function positionMenu(input) {
    const rect = input.getBoundingClientRect();
    menu.style.left = rect.left + 'px';
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.width = Math.max(rect.width, 260) + 'px';
  }

  function setActive(index) {
    const buttons = Array.from(menu.querySelectorAll('.fsb-ac-item'));
    buttons.forEach(btn => btn.classList.remove('is-active'));

    if (index < 0 || index >= buttons.length) {
      activeIndex = -1;
      return;
    }

    activeIndex = index;
    buttons[index].classList.add('is-active');
    buttons[index].scrollIntoView({block: 'nearest'});
  }

  function chooseItem(item) {
    if (!activeInput || !item) return;
    activeInput.value = item.value || item.label || '';
    activeInput.dispatchEvent(new Event('change', {bubbles:true}));
    closeMenu();
    activeInput.focus();
  }

  function renderItems(input, items) {
    activeInput = input;
    activeItems = items || [];
    activeIndex = -1;

    if (!activeItems.length) {
      closeMenu();
      return;
    }

    menu.innerHTML = activeItems.map((item, idx) => {
      const metaParts = [];
      if (item.uso) metaParts.push('uso: ' + item.uso);
      if (item.fuentes) metaParts.push(item.fuentes);

      return `
        <button type="button" class="fsb-ac-item" data-ac-index="${idx}">
          <span class="fsb-ac-label">${escapeHtml(item.label || item.value || '')}</span>
          ${metaParts.length ? `<span class="fsb-ac-meta">${escapeHtml(metaParts.join(' · '))}</span>` : ''}
        </button>
      `;
    }).join('');

    positionMenu(input);
    menu.classList.add('is-open');

    menu.querySelectorAll('.fsb-ac-item').forEach(btn => {
      btn.addEventListener('mousedown', ev => {
        ev.preventDefault();
        const idx = parseInt(btn.dataset.acIndex || '-1', 10);
        chooseItem(activeItems[idx]);
      });
    });
  }

  async function search(input) {
    const q = input.value.trim();
    const type = input.dataset.acType || '';

    if (q.length < 2 || !type) {
      closeMenu();
      return;
    }

    const seq = ++requestSeq;

    try {
      const url = 'futbol_sobres_autocomplete.php?type='
        + encodeURIComponent(type)
        + '&q='
        + encodeURIComponent(q);

      const res = await fetch(url, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });

      const json = await res.json();

      if (seq !== requestSeq) return;

      if (!json.ok) {
        closeMenu();
        return;
      }

      renderItems(input, json.items || []);
    } catch (e) {
      if (seq === requestSeq) closeMenu();
    }
  }

  inputs.forEach(input => {
    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => search(input), 220);
    });

    input.addEventListener('focus', () => {
      if (input.value.trim().length >= 2) {
        clearTimeout(timer);
        timer = setTimeout(() => search(input), 120);
      }
    });

    input.addEventListener('keydown', ev => {
      if (!menu.classList.contains('is-open')) return;

      if (ev.key === 'ArrowDown') {
        ev.preventDefault();
        setActive(activeIndex < activeItems.length - 1 ? activeIndex + 1 : 0);
      } else if (ev.key === 'ArrowUp') {
        ev.preventDefault();
        setActive(activeIndex > 0 ? activeIndex - 1 : activeItems.length - 1);
      } else if (ev.key === 'Enter') {
        if (activeIndex >= 0 && activeItems[activeIndex]) {
          ev.preventDefault();
          chooseItem(activeItems[activeIndex]);
        }
      } else if (ev.key === 'Escape') {
        closeMenu();
      }
    });
  });

  document.addEventListener('click', ev => {
    if (ev.target === activeInput || menu.contains(ev.target)) return;
    closeMenu();
  });

  window.addEventListener('scroll', () => {
    if (activeInput && menu.classList.contains('is-open')) {
      positionMenu(activeInput);
    }
  }, true);

  window.addEventListener('resize', () => {
    if (activeInput && menu.classList.contains('is-open')) {
      positionMenu(activeInput);
    }
  });
})();
</script>

<?php cmp_render_footer(); ?>