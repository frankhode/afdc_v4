<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth_v2.php';

afdc_v2_session_start();
$u = afdc_v2_current_user();

if (!$u) {
    http_response_code(401);
    $pageTitle = 'Hojas de contacto';
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title>Hojas de contacto</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>
    <body>
      <p>No autenticado.</p>
    </body>
    </html>
    <?php
    exit;
}

if (($u['role'] ?? '') !== 'admin') {
    http_response_code(403);
    $pageTitle = 'Hojas de contacto';
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title>Hojas de contacto</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
    </head>
    <body>
      <p>No autorizado.</p>
    </body>
    </html>
    <?php
    exit;
}

$pageTitle = 'Hojas de contacto';
$csrf = afdc_v2_csrf_token();

require_once __DIR__ . '/inc/header.php';
require_once __DIR__ . '/inc/nav.php';
?>

<style>
.contactos-wrap{
  max-width: 1040px;
  margin: 18px auto 28px;
  padding: 0 16px 32px;
}

.contactos-box{
  border: 1px solid var(--afdc-border, var(--border, #444));
  border-radius: var(--radius, 14px);
  background: var(--afdc-card, var(--panel2, rgba(255,255,255,.04)));
  padding: 22px;
  box-shadow: var(--afdc-shadow, 0 2px 10px rgba(0,0,0,.08));
  color: var(--afdc-text, var(--text, inherit));
}

.contactos-box h1{
  margin: 0 0 10px;
  font-size: 2rem;
  line-height: 1.15;
  color: var(--afdc-text, var(--text, inherit));
}

.contactos-box p{
  margin: 0 0 18px;
  color: var(--afdc-text, var(--text, inherit));
}

.contactos-grid{
  display: grid;
  grid-template-columns: 1fr;
  gap: 18px;
}

.contactos-field{
  min-width: 0;
}

.contactos-field label{
  display: block;
  margin-bottom: 7px;
  font-weight: 700;
  color: var(--afdc-text, var(--text, inherit));
}

.contactos-field input[type="text"],
.contactos-field input[type="number"],
.contactos-field textarea,
.contactos-field select{
  width: 100%;
  padding: 11px 13px;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 12px;
  background: var(--afdc-input-bg, var(--afdc-card, var(--bg, #fff)));
  color: var(--afdc-text, var(--text, inherit));
  box-sizing: border-box;
  font: inherit;
  outline: none;
  box-shadow: none;
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}

.contactos-field input[type="text"]::placeholder,
.contactos-field textarea::placeholder{
  color: var(--afdc-muted, var(--muted, #777));
  opacity: 1;
}

.contactos-field input[type="text"]:focus,
.contactos-field input[type="number"]:focus,
.contactos-field textarea:focus,
.contactos-field select:focus{
  border-color: var(--afdc-border-strong, var(--afdc-text, var(--text, #333)));
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--afdc-text, var(--text, #333)) 12%, transparent);
}

.contactos-field textarea{
  min-height: 230px;
  resize: vertical;
}

.contactos-help{
  font-size: .95rem;
  color: var(--afdc-muted, var(--muted, var(--afdc-text, var(--text, inherit))));
  opacity: .82;
  margin-top: 7px;
}

.contactos-check{
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  font-weight: 600;
}

.contactos-actions{
  display: flex;
  gap: 12px;
  align-items: center;
  margin-top: 18px;
  flex-wrap: wrap;
}

.contactos-actions button{
  appearance: none;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 12px;
  background: var(--afdc-btn, var(--afdc-card, var(--panel, #eee)));
  color: var(--afdc-text, var(--text, inherit));
  font: inherit;
  font-weight: 600;
  padding: 10px 18px;
  min-height: 42px;
  cursor: pointer;
  transition: background .15s ease, border-color .15s ease, transform .05s ease;
}

.contactos-actions button:hover:not([disabled]){
  background: var(--afdc-btn-hover, var(--afdc-card-hover, var(--panel2, #f5f5f5)));
}

.contactos-actions button:active:not([disabled]){
  transform: translateY(1px);
}

.contactos-actions button[disabled]{
  opacity: .65;
  cursor: wait;
}

.contactos-done{
  font-size: .95rem;
  color: var(--afdc-muted, var(--muted, var(--afdc-text, var(--text, inherit))));
  opacity: .85;
}

.contactos-status{
  display: none;
  width: 100%;
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 12px;
  background: var(--afdc-card, var(--panel, #eee));
  color: var(--afdc-text, var(--text, inherit));
}

.contactos-status.is-visible{
  display: block;
}

.contactos-status strong{
  display: block;
  margin-bottom: 4px;
}

.contactos-status.is-success{
  border-color: rgba(90, 150, 90, .45);
}

.contactos-status.is-error{
  border-color: rgba(180, 90, 90, .45);
}

.contactos-mode-panel[hidden]{
  display: none;
}

.contactos-preview{
  display: none;
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 12px;
  background: var(--afdc-card, var(--panel, #eee));
}

.contactos-preview.is-visible{
  display: block;
}

.contactos-preview ul{
  margin: 8px 0 0 18px;
  padding: 0;
}

@media (min-width: 860px){
  .contactos-grid{
    grid-template-columns: 1fr 1fr;
  }

  .contactos-field--full{
    grid-column: 1 / -1;
  }
}

.contactos-progress{
  display: none;
  margin-top: 14px;
  padding: 12px 14px;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 12px;
  background: var(--afdc-card, var(--panel, #eee));
}

.contactos-progress.is-visible{
  display: block;
}

.contactos-progressbar{
  width: 100%;
  height: 18px;
  border: 1px solid var(--afdc-border, var(--border, #666));
  border-radius: 999px;
  overflow: hidden;
  background: color-mix(in srgb, var(--afdc-card, #eee) 80%, #000 20%);
  margin: 8px 0;
}

.contactos-progressbar-fill{
  width: 0%;
  height: 100%;
  background: var(--afdc-text, var(--text, #333));
  transition: width .25s ease;
}

.contactos-download-link{
  display: inline-block;
  margin-top: 8px;
  font-weight: 700;
}
</style>

<main class="contactos-wrap">
  <div class="contactos-box">
    <h1>Hojas de contacto</h1>

    <p>Generá hojas de contacto desde barcodes/rutas o desde una búsqueda por materia. En el modo materia los PDFs se dividen automáticamente por cantidad de imágenes, sin partir sobres.</p>

    <form
      id="contactosForm"
      method="post"
      action="<?= h(BASE_URL . '/api/contactos_generar.php') ?>"
      target="contactosHiddenFrame"
    >
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="contactos-grid">
        <div class="contactos-field">
          <label for="source_mode">Origen</label>
          <select id="source_mode" name="source_mode" required>
            <option value="manual" selected>Barcodes / rutas</option>
            <option value="materia">Búsqueda por materia</option>
          </select>
        </div>

        <div class="contactos-field" id="manualOutputField">
          <label for="output_mode">Salida</label>
          <select id="output_mode" name="output_mode">
            <option value="jpg_per_sobre">JPG por sobre</option>
            <option value="pdf_per_sobre">PDF por sobre</option>
            <option value="pdf_lote" selected>PDF por lote</option>
          </select>
        </div>

        <div class="contactos-field contactos-field--full contactos-mode-panel" id="manualPanel">
          <label for="entradas">Barcodes y/o rutas completas</label>
          <textarea
            id="entradas"
            name="entradas"
            rows="12"
            placeholder="FO123456&#10;FO123457&#10;U:\Fototeca\Usuarios\Saslasky\FO027085&#10;U:\Fototeca\Usuarios\Saslasky\FO027086"
          ></textarea>
          <div class="contactos-help">Una entrada por línea. Cada línea puede ser un barcode o una ruta absoluta de carpeta en Windows. En rutas locales se toman solo JPG del primer nivel.</div>
        </div>

        <div class="contactos-field contactos-mode-panel" id="materiaTextoField" hidden>
          <label for="materia_texto">Materia / texto</label>
          <input
            type="text"
            id="materia_texto"
            name="materia_texto"
            placeholder="Boca Juniors"
          >
          <label class="contactos-check">
            <input type="checkbox" name="materia_exacta" value="1">
            Coincidencia exacta
          </label>
        </div>

        <div class="contactos-field contactos-mode-panel" id="materiaCampoField" hidden>
          <label for="materia_campo">Campo MARC</label>
          <select id="materia_campo" name="materia_campo">
            <option value="todos" selected>Todos</option>
            <option value="600">600 - Persona</option>
            <option value="610">610 - Entidad</option>
            <option value="611">611 - Congreso / evento</option>
            <option value="630">630 - Título uniforme</option>
            <option value="650">650 - Tema</option>
            <option value="651">651 - Lugar</option>
          </select>
        </div>

        <div class="contactos-field contactos-mode-panel" id="materiaMaxField" hidden>
          <label for="max_images_per_pdf">Máximo de imágenes por PDF</label>
          <input
            type="number"
            id="max_images_per_pdf"
            name="max_images_per_pdf"
            min="1"
            step="1"
            value="200"
          >
          <div class="contactos-help">El sobre no se parte. Si un sobre supera este número, queda entero en un PDF propio.</div>
        </div>
      </div>

      <div id="contactosPreview" class="contactos-preview" aria-live="polite"></div>
      <div id="contactosProgress" class="contactos-progress" aria-live="polite">
        <strong id="contactosProgressTitle">Progreso</strong>
        <div class="contactos-progressbar">
          <div id="contactosProgressFill" class="contactos-progressbar-fill"></div>
        </div>
        <div id="contactosProgressMessage" class="contactos-help">Esperando...</div>
        <div id="contactosProgressMeta" class="contactos-help"></div>
        <div id="contactosDownloadBox"></div>
      </div>

      <div class="contactos-actions">
        <button type="button" id="contactosPreviewBtn" hidden>Contar / previsualizar</button>
        <button type="submit" id="contactosSubmitBtn">Generar</button>
        <span id="contactosDoneHint" class="contactos-done" hidden>Listo. Ya podés generar otra hoja.</span>
      </div>

      <div id="contactosStatus" class="contactos-status" aria-live="polite">
        <strong>Generando hojas de contacto...</strong>
        <span>Esto puede tardar varios segundos cuando hay varios sobres.</span>
      </div>
    </form>

    <iframe name="contactosHiddenFrame" id="contactosHiddenFrame" hidden></iframe>
  </div>
</main>

<script>
(function () {
  var form = document.getElementById('contactosForm');
  var frame = document.getElementById('contactosHiddenFrame');
  var btn = document.getElementById('contactosSubmitBtn');
  var previewBtn = document.getElementById('contactosPreviewBtn');
  var previewBox = document.getElementById('contactosPreview');
  var status = document.getElementById('contactosStatus');
  var doneHint = document.getElementById('contactosDoneHint');

  var sourceMode = document.getElementById('source_mode');
  var entradasInput = document.getElementById('entradas');
  var materiaTexto = document.getElementById('materia_texto');

  var manualPanel = document.getElementById('manualPanel');
  var manualOutputField = document.getElementById('manualOutputField');

  var materiaTextoField = document.getElementById('materiaTextoField');
  var materiaCampoField = document.getElementById('materiaCampoField');
  var materiaMaxField = document.getElementById('materiaMaxField');

  var progressBox = document.getElementById('contactosProgress');
  var progressFill = document.getElementById('contactosProgressFill');
  var progressTitle = document.getElementById('contactosProgressTitle');
  var progressMessage = document.getElementById('contactosProgressMessage');
  var progressMeta = document.getElementById('contactosProgressMeta');
  var downloadBox = document.getElementById('contactosDownloadBox');

  var busy = false;
  var frameReady = false;
  var pollTimer = null;

  if (!form || !frame || !btn || !previewBtn || !previewBox || !status || !doneHint || !sourceMode || !entradasInput || !materiaTexto) {
    return;
  }

  function isMateriaMode() {
    return sourceMode.value === 'materia';
  }

  function updateMode() {
    var materia = isMateriaMode();

    manualPanel.hidden = materia;
    manualOutputField.hidden = materia;

    materiaTextoField.hidden = !materia;
    materiaCampoField.hidden = !materia;
    materiaMaxField.hidden = !materia;

    previewBtn.hidden = !materia;
    previewBox.classList.remove('is-visible');
    previewBox.innerHTML = '';

    hideProgress();
    doneHint.hidden = true;
    status.classList.remove('is-visible');
  }

  function setGenerating(message) {
    btn.disabled = true;
    previewBtn.disabled = true;
    btn.textContent = 'Generando...';
    doneHint.hidden = true;
    status.classList.add('is-visible');
    status.innerHTML = '<strong>Generando hojas de contacto...</strong><span>' + (message || 'Esto puede tardar cuando hay muchas imágenes.') + '</span>';
  }

  function setIdle(message) {
    busy = false;
    btn.disabled = false;
    previewBtn.disabled = false;
    btn.textContent = 'Generar';
    doneHint.hidden = false;
    status.classList.add('is-visible');
    status.innerHTML = '<strong>' + message + '</strong><span>Podés volver a generar sin recargar la página.</span>';
  }

  function setError(message) {
    busy = false;
    btn.disabled = false;
    previewBtn.disabled = false;
    btn.textContent = 'Generar';
    doneHint.hidden = true;
    status.classList.add('is-visible');
    status.innerHTML = '<strong>' + message + '</strong>';
  }

  function showProgress() {
    if (!progressBox) return;
    progressBox.classList.add('is-visible');
    if (downloadBox) downloadBox.innerHTML = '';
  }

  function hideProgress() {
    if (!progressBox) return;
    progressBox.classList.remove('is-visible');
    if (progressFill) progressFill.style.width = '0%';
    if (progressMessage) progressMessage.textContent = 'Esperando...';
    if (progressMeta) progressMeta.textContent = '';
    if (downloadBox) downloadBox.innerHTML = '';
  }

  function updateProgress(data) {
    showProgress();

    var pct = parseInt(data.percent || 0, 10);
    if (!isFinite(pct)) pct = 0;
    pct = Math.max(0, Math.min(100, pct));

    if (progressFill) progressFill.style.width = pct + '%';
    if (progressTitle) progressTitle.textContent = 'Progreso: ' + pct + '%';
    if (progressMessage) progressMessage.textContent = data.message || 'Trabajando...';

    var meta = [];

    if (data.sobres_done && data.sobres_total) {
      meta.push('Sobres: ' + data.sobres_done + '/' + data.sobres_total);
    } else if (data.sobres_total) {
      meta.push('Sobres: ' + data.sobres_total);
    }

    if (data.imagenes_total) {
      meta.push('Imágenes: ' + data.imagenes_total);
    }

    if (data.pdf_actual && data.pdfs_total) {
      meta.push('PDF: ' + data.pdf_actual + '/' + data.pdfs_total);
    } else if (data.pdfs_total) {
      meta.push('PDFs: ' + data.pdfs_total);
    }

    if (data.current_barcode) {
      meta.push('Actual: ' + data.current_barcode);
    }

    if (progressMeta) progressMeta.textContent = meta.join(' · ');
  }

  function readFrameText() {
    try {
      var doc = frame.contentDocument || (frame.contentWindow ? frame.contentWindow.document : null);
      if (!doc || !doc.body) return '';
      return (doc.body.textContent || '').trim();
    } catch (e) {
      return '';
    }
  }

  function hasManualEntradas() {
    return entradasInput.value.trim() !== '';
  }

  function hasMateria() {
    return materiaTexto.value.trim() !== '';
  }

  function renderPreview(data) {
    var html = '';
    html += '<strong>Resumen estimado</strong>';
    html += '<ul>';
    html += '<li>Sobres encontrados: ' + data.sobres + '</li>';
    html += '<li>Imágenes totales: ' + data.imagenes + '</li>';
    html += '<li>PDFs estimados: ' + data.pdfs + '</li>';
    html += '<li>Máximo de imágenes por PDF: ' + data.max_images_per_pdf + '</li>';
    html += '</ul>';

    if (data.preview && data.preview.length) {
      html += '<div class="contactos-help">Primeros resultados:</div>';
      html += '<ul>';
      data.preview.forEach(function (row) {
        html += '<li>' + row.barcode + ' — ' + row.imagenes + ' imágenes</li>';
      });
      html += '</ul>';
    }

    previewBox.innerHTML = html;
    previewBox.classList.add('is-visible');
  }

  previewBtn.addEventListener('click', function () {
    if (!hasMateria()) {
      setError('Ingresá una materia o texto de búsqueda.');
      return;
    }

    previewBtn.disabled = true;
    btn.disabled = true;
    previewBox.classList.add('is-visible');
    previewBox.innerHTML = '<strong>Contando...</strong>';

    var fd = new FormData(form);
    fd.set('source_mode', 'materia');
    fd.set('dry_run', '1');

    fetch(form.action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
    .then(function (r) {
      if (!r.ok) {
        return r.text().then(function (t) {
          throw new Error(t || 'No se pudo contar la búsqueda');
        });
      }
      return r.json();
    })
    .then(function (data) {
      renderPreview(data);
      previewBtn.disabled = false;
      btn.disabled = false;
      status.classList.remove('is-visible');
    })
    .catch(function (err) {
      previewBtn.disabled = false;
      btn.disabled = false;
      setError(err.message || 'No se pudo contar la búsqueda.');
    });
  });

  function pollJob(jobId) {
    if (pollTimer) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    fetch('<?= h(BASE_URL . '/api/contactos_job_estado.php') ?>?id=' + encodeURIComponent(jobId), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    })
    .then(function (r) {
      if (!r.ok) {
        return r.text().then(function (t) {
          throw new Error(t || 'No se pudo leer el progreso');
        });
      }
      return r.json();
    })
    .then(function (data) {
      updateProgress(data);

      if (data.status === 'done') {
        busy = false;
        btn.disabled = false;
        previewBtn.disabled = false;
        btn.textContent = 'Generar';
        doneHint.hidden = false;

        status.classList.add('is-visible');
        status.innerHTML = '<strong>Listo.</strong><span>El archivo quedó preparado para descargar.</span>';

        if (downloadBox) {
          var href = '<?= h(BASE_URL . '/api/contactos_job_descargar.php') ?>?id=' + encodeURIComponent(jobId);
          var label = data.download_name ? data.download_name : 'Descargar archivo';
          downloadBox.innerHTML = '<a class="contactos-download-link" href="' + href + '">Descargar: ' + label + '</a>';
        }

        return;
      }

      if (data.status === 'error') {
        setError(data.message || 'La generación falló.');
        return;
      }

      pollTimer = setTimeout(function () {
        pollJob(jobId);
      }, 900);
    })
    .catch(function (err) {
      setError(err.message || 'No se pudo leer el progreso.');
    });
  }

function startMateriaJob() {
  var fd = new FormData(form);

  busy = true;
  setGenerating('Creando tarea...');
  showProgress();
  updateProgress({
    status: 'created',
    percent: 0,
    message: 'Creando tarea...'
  });

  fetch('<?= h(BASE_URL . '/api/contactos_job_iniciar.php') ?>', {
    method: 'POST',
    body: fd,
    credentials: 'same-origin'
  })
  .then(function (r) {
    if (!r.ok) {
      return r.text().then(function (t) {
        throw new Error(t || 'No se pudo crear la tarea');
      });
    }
    return r.json();
  })
  .then(function (data) {
    if (!data || !data.job_id) {
      throw new Error('La tarea no devolvió job_id');
    }

    var jobId = data.job_id;

    updateProgress({
      status: 'running',
      percent: 1,
      message: 'Tarea creada. Iniciando procesamiento...'
    });

    pollJob(jobId);

    var processFd = new FormData();
    processFd.set('csrf', '<?= h($csrf) ?>');
    processFd.set('job_id', jobId);

    fetch('<?= h(BASE_URL . '/api/contactos_job_procesar.php') ?>', {
      method: 'POST',
      body: processFd,
      credentials: 'same-origin'
    })
    .then(function (r) {
      /*
       * No usamos esta respuesta para progreso.
       * El progreso real lo informa contactos_job_estado.php.
       */
      return r.text();
    })
    .then(function () {
      pollJob(jobId);
    })
    .catch(function () {
      /*
       * Si falla, el endpoint debería haber escrito el error en el JSON.
       * Forzamos una última lectura.
       */
      pollJob(jobId);
    });
  })
  .catch(function (err) {
    setError(err.message || 'No se pudo iniciar la tarea.');
  });
}

  form.addEventListener('submit', function (ev) {
    if (busy) {
      ev.preventDefault();
      return;
    }

    if (isMateriaMode()) {
      ev.preventDefault();

      if (!hasMateria()) {
        setError('Ingresá una materia o texto de búsqueda.');
        return;
      }

      startMateriaJob();
      return;
    }

    if (!hasManualEntradas()) {
      ev.preventDefault();
      setError('Ingresá al menos una línea con barcode o ruta completa.');
      return;
    }

    busy = true;
    setGenerating();
  });

  frame.addEventListener('load', function () {
    if (!frameReady) {
      frameReady = true;
      return;
    }

    if (!busy) return;

    var text = readFrameText();
    var lower = text.toLowerCase();

    if (
      lower.indexOf('error') !== -1 ||
      lower.indexOf('fatal') !== -1 ||
      lower.indexOf('warning') !== -1 ||
      lower.indexOf('notice') !== -1 ||
      lower.indexOf('inválido') !== -1 ||
      lower.indexOf('no se') !== -1
    ) {
      setIdle('La generación terminó con una respuesta del servidor para revisar.');
      return;
    }

    setIdle('La generación terminó.');
  });

  sourceMode.addEventListener('change', updateMode);

  window.addEventListener('pageshow', function () {
    if (!busy) {
      btn.disabled = false;
      previewBtn.disabled = false;
      btn.textContent = 'Generar';
    }
  });

  window.addEventListener('pagehide', function () {
    busy = false;
    btn.disabled = false;
    previewBtn.disabled = false;
  });

  updateMode();
})();
</script>

<?php
$footerPath = __DIR__ . '/inc/footer.php';
if (is_file($footerPath)) {
    require_once $footerPath;
}
?>