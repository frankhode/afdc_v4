<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/auth_v2.php';

afdc_v2_session_start();

$return = (string)($_GET['return'] ?? '');
if ($return === '') $return = 'index.php';

// Validar return: solo rutas internas (sin http(s)://)
if (preg_match('~^https?://~i', $return)) {
    $return = 'index.php';
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Completá usuario y contraseña.';
    } else {
        $rows = q(
            "SELECT id, username, password_hash, role, display_name, is_active
             FROM users
             WHERE username=? LIMIT 1",
            "s",
            [$username]
        );

        if (!$rows) {
            $error = 'Usuario o contraseña incorrectos.';
        } else {
            $u = $rows[0];
            if ((int)$u['is_active'] !== 1) {
                $error = 'Usuario desactivado.';
            } elseif (!password_verify($password, (string)$u['password_hash'])) {
                $error = 'Usuario o contraseña incorrectos.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$u['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(32));

                // last_login_at (si existe en tu tabla)
                try {
                    q("UPDATE users SET last_login_at = NOW() WHERE id=?", "i", [(int)$u['id']]);
                } catch (\Throwable $e) {
                    // no pasa nada si tu tabla no tiene last_login_at (aunque en tu SQL sí)
                }

                header('Location: ' . $return);
                exit;
            }
        }
    }
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar sesión</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0;background:#0b0b0b;color:#eee}
    .wrap{max-width:420px;margin:8vh auto;padding:18px}
    .card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:18px}
    label{display:block;margin:10px 0 6px;opacity:.85}
    input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#fff}
    .btn{margin-top:14px;width:100%;height:40px;border-radius:14px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.10);color:#fff;font-weight:700;cursor:pointer}
    .btn:hover{background:rgba(255,255,255,.14)}
    .err{margin:10px 0 0;padding:10px 12px;border-radius:12px;background:rgba(220,38,38,.12);border:1px solid rgba(220,38,38,.22);color:#fecaca}
    .muted{margin-top:10px;opacity:.7;font-size:13px}
    a{color:#ddd}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h2 style="margin:0 0 6px;">Ingresar</h2>
      <div class="muted">AFDC · acceso LAN</div>

      <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="login.php?<?= http_build_query(['return' => $return]) ?>">
        <label>Usuario</label>
        <input name="username" autocomplete="username" required>

        <label>Contraseña</label>
        <input type="password" name="password" autocomplete="current-password" required>

        <button class="btn" type="submit">Entrar</button>
      </form>

      <div class="muted">
        <a href="<?= htmlspecialchars($return, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Volver</a>
      </div>
    </div>
  </div>
</body>
</html>
