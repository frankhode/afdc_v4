<?php
// inc/header.php
// Requiere helpers: h(), BASE_URL y que exista inc/nav.php
$pageTitle = $pageTitle ?? 'AFDC v1';
$base = rtrim(BASE_URL, '/');

// v2 auth (para mostrar Ingresar / Salir sin flicker)
require_once __DIR__ . '/auth_v2.php';
afdc_v2_session_start();
$__u = afdc_v2_current_user();
$__logged = (bool)$__u;
$__userLabel = $__logged ? (trim((string)($__u['display_name'] ?? '')) ?: (string)$__u['username']) : 'Usuarios';
$__return = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/index.php'));
$__isAdmin = $__logged && (($__u['role'] ?? '') === 'admin');
?>

<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle) ?></title>

  <!-- Tema: setear ANTES del CSS para evitar "flash" -->
  <script>
  (function(){
    try{
      var t = localStorage.getItem('afdc_theme') || 'dark';
      var root = document.documentElement;
      root.classList.remove('theme-dark','theme-light','theme-vintage');
      root.classList.add('theme-' + t);
    }catch(e){}
  })();
  </script>

  <link rel="stylesheet" href="<?= $base ?>/assets/app.css?v=<?= (int)filemtime(__DIR__ . '/../assets/app.css') ?>">
  <script>
    window.AFDC_BAJAS_URL = <?= json_encode(rtrim(AFDC_BAJAS_URL, '/')) ?>;
  </script>

  <style>
    .topbar__shortcut{
      display:inline-flex;
      align-items:center;
      gap:8px;
      white-space:nowrap;
      text-decoration:none;
    }
  </style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <!-- IZQUIERDA: Menú principal -->
    <div class="topbar__left">
      <?php include __DIR__ . '/nav.php'; ?>
    </div>

    <!-- DERECHA: Marca -> Accesos -> Temas -> Usuarios -->
    <div class="topbar__right">
      <!-- Marca (AFDC + cámara) -->
      <a class="brand__home" href="<?= $base ?>/index.php" aria-label="Inicio">
        <span class="brand__text">AFDC</span>
        <span class="brand__icon" aria-hidden="true">📷</span>
      </a>

      <!-- Acceso rápido -->
      <a class="nav__link topbar__shortcut" href="<?= $base ?>/index.php?p=dia_como_hoy" title="Ver Un día como hoy">
        🗓 <span>Un día como hoy</span>
      </a>

      <!-- Temas -->
      <div class="nav__group theme-wrap">
        <button id="themeBtn" class="nav__link nav__toggle" type="button" aria-haspopup="true" aria-expanded="false">
          ☀️ <span class="theme-label">Tema</span> ▾
        </button>
        <div id="themeMenu" class="nav__dropdown theme-menu" role="menu" aria-label="Tema">
          <a class="nav__drop" href="#" data-theme="dark">Oscuro</a>
          <a class="nav__drop" href="#" data-theme="light">Claro</a>
          <a class="nav__drop" href="#" data-theme="vintage">Vintage</a>
        </div>
      </div>

      <!-- Usuarios -->
      <div class="nav__group user-wrap">
        <button class="nav__link nav__toggle" type="button" aria-haspopup="true" aria-expanded="false">
          👤 <span class="user-label"><?= h($__userLabel) ?></span> ▾
        </button>
        <div class="nav__dropdown user-menu" role="menu" aria-label="Usuarios">
          <?php if (!$__logged): ?>
            <a class="nav__drop" href="<?= $base ?>/login.php?return=<?= urlencode($__return) ?>">Iniciar sesión</a>
          <?php else: ?>
            <a class="nav__drop" href="<?= $base ?>/conjuntos.php?tab=pending">Sobres para revisar</a>
            <a class="nav__drop" href="<?= $base ?>/conjuntos.php">Ver conjuntos</a>
            <a class="nav__drop" href="<?= $base ?>/misrecortes.php">Mis recortes</a>
            <a class="nav__drop<?= nav_active('colecciones.php', $path) ?>" href="<?= rtrim(BASE_URL, '/') ?>/colecciones.php">Mis Colecciones</a>
            <?php if ($__isAdmin): ?>
              <a class="nav__drop" href="<?= $base ?>/exposiciones.php">Exposiciones</a>
              <a class="nav__drop is-disabled" href="#" aria-disabled="true">Colecciones curadas</a>
              <a class="nav__drop" href="api/campeonatos_importaciones.php">Importar campeonatos</a>
              <?php if ($__isAdmin): ?>
              <a class="nav__drop" href="<?= h(BASE_URL . '/contactos.php') ?>">Hojas de contacto</a>
            <?php endif; ?>              
            <?php endif; ?>

            <a class="nav__drop is-disabled" href="#" aria-disabled="true">Perfil</a>
            <a class="nav__drop" href="<?= $base ?>/logout.php?return=<?= urlencode($__return) ?>">Cerrar sesión</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

  <main class="<?= $mainClass ?? 'container' ?>">