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
  max-width: 980px;
  margin: 24px auto;
  padding: 0 16px 32px;
}
.contactos-box{
  border: 1px solid var(--border, #444);
  background: var(--panel, #111);
  padding: 18px;
}
.contactos-box h1{
  margin: 0 0 10px;
  font-size: 1.5rem;
}
.contactos-grid{
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
.contactos-field label{
  display: block;
  margin-bottom: 6px;
  font-weight: 600;
}
.contactos-field input[type="text"],
.contactos-field textarea,
.contactos-field select{
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border, #666);
  background: var(--input-bg, #000);
  color: inherit;
  box-sizing: border-box;
}
.contactos-help{
  font-size: .92rem;
  opacity: .85;
  margin-top: 6px;
}
.contactos-actions{
  display:flex;
  gap:12px;
  align-items:center;
  margin-top: 16px;
  flex-wrap: wrap;
}
.contactos-actions button{
  padding: 10px 16px;
  cursor: pointer;
}
@media (min-width: 860px){
  .contactos-grid{
    grid-template-columns: 1fr 1fr;
  }
  .contactos-field--full{
    grid-column: 1 / -1;
  }
}

.contactos-status{
  display:none;
  margin-top: 14px;
  padding: 10px 12px;
  border: 1px solid var(--border, #666);
  background: var(--input-bg, #000);
}
.contactos-status.is-visible{
  display:block;
}
.contactos-status strong{
  display:block;
  margin-bottom: 4px;
}
.contactos-actions button[disabled]{
  opacity: .7;
  cursor: wait;
}
</style>

<main class="contactos-wrap">
  <div class="contactos-box">
    <h1>Hojas de contacto</h1>

    <p>Fuente inicial: <strong>Bajas</strong>.</p>

    <form id="contactosForm" method="post" action="<?= h(BASE_URL . '/api/contactos_generar.php') ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="contactos-grid">
        <div class="contactos-field">
          <label for="barcode">Barcode único</label>
          <input type="text" id="barcode" name="barcode" placeholder="FO123456">
          <div class="contactos-help">Opcional si usás la lista.</div>
        </div>

        <div class="contactos-field">
          <label for="output_mode">Salida</label>
          <select id="output_mode" name="output_mode" required>
            <option value="jpg_per_sobre">JPG por sobre</option>
            <option value="pdf_per_sobre">PDF por sobre</option>
            <option value="pdf_lote">PDF por lote</option>
          </select>
        </div>

        <div class="contactos-field contactos-field--full">
          <label for="lista_barcodes">Lista de barcodes</label>
          <textarea id="lista_barcodes" name="lista_barcodes" rows="10" placeholder="FO123456&#10;FO123457&#10;FO123458"></textarea>
          <div class="contactos-help">Podés separarlos por salto de línea, coma o punto y coma.</div>
        </div>
      </div>

      <div class="contactos-actions">
        <button type="submit" id="contactosSubmitBtn">Generar</button>
      </div>
      <div id="contactosStatus" class="contactos-status" aria-live="polite">
        <strong>Generando hojas de contacto...</strong>
        <span>Esto puede tardar varios segundos cuando hay varios sobres.</span>
      </div>
    </form>
  </div>
</main>

<script>
(function () {
  var form = document.getElementById('contactosForm');
  var btn = document.getElementById('contactosSubmitBtn');
  var status = document.getElementById('contactosStatus');

  if (!form || !btn || !status) return;

  form.addEventListener('submit', function () {
    btn.disabled = true;
    btn.textContent = 'Generando...';
    status.classList.add('is-visible');
  });
})();
</script>

<?php
$footerPath = __DIR__ . '/inc/footer.php';
if (is_file($footerPath)) {
    require_once $footerPath;
}