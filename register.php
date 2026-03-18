<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/auth_v2.php'; // carga bootstrap.php -> q(), db()
afdc_v2_session_start();

$return = (string)($_GET['return'] ?? 'index.php');
if ($return === '' || preg_match('~^https?://~i', $return)) $return = 'index.php';

// ¿Hay algún usuario? ¿Hay algún admin?
$anyUser  = q("SELECT 1 FROM users LIMIT 1");
$anyAdmin = q("SELECT 1 FROM users WHERE role='admin' LIMIT 1");

// Si ya existe admin, solo admin puede crear usuarios
$cur = afdc_v2_current_user();
if ($anyAdmin && (!$cur || ($cur['role'] ?? '') !== 'admin')) {
    header('Location: login.php?return=' . urlencode('register.php?return=' . urlencode($return)));
    exit;
}

$error = '';
$okMsg = '';

// CSRF simple para formulario (LAN)
$csrf = afdc_v2_csrf_token();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Validar CSRF
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $postedCsrf)) {
        $error = 'CSRF inválido. Reintentá.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $display  = trim((string)($_POST['display_name'] ?? ''));
        $pass1    = (string)($_POST['password'] ?? '');
        $pass2    = (string)($_POST['password2'] ?? '');

        // Solo para primer setup: permitir elegir rol (default admin si no hay nadie)
        $role = (string)($_POST['role'] ?? 'user');
        if (!$anyUser) {
            $role = 'admin';
        } else {
            // si ya hay usuarios, solo admin puede tocar roles; igual acotamos
            $role = ($role === 'admin') ? 'admin' : 'user';
        }

        if ($username === '' || $pass1 === '' || $pass2 === '') {
            $error = 'Completá usuario y contraseña.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            $error = 'Usuario inválido. Usá 3–64 caracteres: letras, números, punto, guión o guión bajo.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (strlen($pass1) < 4) {
            $error = 'Contraseña muy corta (mínimo 4).';
        } else {
            $exists = q("SELECT 1 FROM users WHERE username=? LIMIT 1", "s", [$username]);
            if ($exists) {
                $error = 'Ese usuario ya existe.';
            } else {
                $hash = password_hash($pass1, PASSWORD_DEFAULT);
                q(
                    "INSERT INTO users (username, password_hash, role, display_name, is_active)
                     VALUES (?, ?, ?, ?, 1)",
                    "ssss",
                    [$username, $hash, $role, ($display !== '' ? $display : null)]
                );

                $okMsg = 'Usuario creado.';
                // Si no había admin, logueamos automáticamente al nuevo admin
                if (!$anyAdmin) {
                    $row = q("SELECT id FROM users WHERE username=? LIMIT 1", "s", [$username]);
                    if ($row) {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$row[0]['id'];
                        $_SESSION['csrf'] = bin2hex(random_bytes(32));
                    }
                }

                header('Location: ' . $return);
                exit;
            }
        }
    }
}

$title = (!$anyUser) ? 'Crear admin inicial' : 'Crear usuario';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0;background:#0b0b0b;color:#eee}
    .wrap{max-width:520px;margin:7vh auto;padding:18px}
    .card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:18px}
    label{display:block;margin:10px 0 6px;opacity:.85}
    input,select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#fff}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .btn{margin-top:14px;width:100%;height:40px;border-radius:14px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.10);color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:rgba(255,255,255,.14)}
    .err{margin:10px 0 0;padding:10px 12px;border-radius:12px;background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.22);color:#fecaca}
    .ok{margin:10px 0 0;padding:10px 12px;border-radius:12px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.22);color:#bbf7d0}
    .muted{margin-top:10px;opacity:.7;font-size:13px}
    a{color:#ddd}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 style="margin:0 0 6px;"><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      <div class="muted">AFDC · registro LAN</div>

      <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php elseif ($okMsg): ?>
        <div class="ok"><?= htmlspecialchars($okMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="register.php?<?= http_build_query(['return' => $return]) ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <div class="row">
          <div>
            <label>Usuario</label>
            <input name="username" autocomplete="username" required>
          </div>
          <div>
            <label>Nombre visible (opcional)</label>
            <input name="display_name" autocomplete="name">
          </div>
        </div>

        <?php if ($anyUser): ?>
          <label>Rol</label>
          <select name="role">
            <option value="user">user</option>
            <option value="admin">admin</option>
          </select>
          <div class="muted">Solo admins pueden crear usuarios (si ya existe un admin).</div>
        <?php else: ?>
          <div class="muted">Se creará el primer usuario como <strong>admin</strong>.</div>
        <?php endif; ?>

        <div class="row">
          <div>
            <label>Contraseña</label>
            <input type="password" name="password" autocomplete="new-password" required>
          </div>
          <div>
            <label>Repetir contraseña</label>
            <input type="password" name="password2" autocomplete="new-password" required>
          </div>
        </div>

        <button class="btn" type="submit">Crear</button>
      </form>

      <div class="muted">
        <a href="<?= htmlspecialchars($return, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Volver</a>
      </div>
    </div>
  </div>
</body>
</html>
