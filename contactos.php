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

@media (min-width: 860px){
  .contactos-grid{
    grid-template-columns: 1fr 1fr;
  }

  .contactos-field--full{
    grid-column: 1 / -1;
  }
}
</style>

<main class="contactos-wrap">
  <div class="contactos-box">
    <h1>Hojas de contacto</h1>

    <p>Ingresá una o más líneas. Cada línea puede ser un barcode de AFDC o una ruta completa de carpeta en Windows. Para carpetas locales se tomarán solo los JPG del primer nivel.</p>

    <form
      id="contactosForm"
      method="post"
      action="<?= h(BASE_URL . '/api/contactos_generar.php') ?>"
    >
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="contactos-grid">
        <div class="contactos-field">
          <label for="output_mode">Salida</label>
          <select id="output_mode" name="output_mode" required>
            <option value="jpg_per_sobre">JPG por sobre</option>
            <option value="pdf_per_sobre">PDF por sobre</option>
            <option value="pdf_lote" selected>PDF por lote</option>
          </select>
        </div>

        <div class="contactos-field contactos-field--full">
          <label for="entradas">Barcodes y/o rutas completas</label>
          <textarea
            id="entradas"
            name="entradas"
            rows="12"
            placeholder="FO123456&#10;FO123457&#10;U:\Fototeca\Usuarios\Saslasky\FO027085&#10;U:\Fototeca\Usuarios\Saslasky\FO027086"
          ></textarea>
          <div class="contactos-help">Una entrada por línea. Cada línea puede ser un barcode o una ruta absoluta de carpeta en Windows. En rutas locales se toman solo JPG del primer nivel.</div>
        </div>
      </div>

      <div class="contactos-actions">
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
  var status = document.getElementById('contactosStatus');
  var doneHint = document.getElementById('contactosDoneHint');
  var entradasInput = document.getElementById('entradas');
  var busy = false;
  var frameReady = false;

  if (!form || !frame || !btn || !status || !doneHint || !entradasInput) {
    return;
  }

  function setGenerating() {
    btn.disabled = true;
    btn.textContent = 'Generando...';
    doneHint.hidden = true;
    status.classList.add('is-visible');
    status.innerHTML = '<strong>Generando hojas de contacto...</strong><span>Esto puede tardar varios segundos cuando hay muchas entradas o muchos JPG.</span>';
  }

  function setIdle(message) {
    busy = false;
    btn.disabled = false;
    btn.textContent = 'Generar';
    doneHint.hidden = false;
    status.classList.add('is-visible');
    status.innerHTML = '<strong>' + message + '</strong><span>Podés volver a generar sin recargar la página.</span>';
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

  function hasEntradas() {
    return entradasInput.value.trim() !== '';
  }

  form.addEventListener('submit', function (ev) {
    if (busy) {
      ev.preventDefault();
      return;
    }

    if (!hasEntradas()) {
      ev.preventDefault();
      setIdle('Ingresá al menos una línea con barcode o ruta completa.');
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
      lower.indexOf('inválido') !== -1
    ) {
      setIdle('La generación terminó con una respuesta del servidor para revisar.');
      return;
    }

    setIdle('La generación terminó.');
  });

  window.addEventListener('pageshow', function () {
    if (!busy) {
      btn.disabled = false;
      btn.textContent = 'Generar';
    }
  });

  window.addEventListener('pagehide', function () {
    busy = false;
    btn.disabled = false;
  });
})();
</script>

<?php
$footerPath = __DIR__ . '/inc/footer.php';
if (is_file($footerPath)) {
    require_once $footerPath;
}
?>