<?php
$path = $_SERVER['SCRIPT_NAME'] ?? '';
$base = rtrim(BASE_URL, '/');

function nav_active(string $filename, string $path): string {
    return (str_ends_with($path, '/' . $filename) || $path === $filename) ? ' is-active' : '';
}

function nav_active_any(array $filenames, string $path): string {
    foreach ($filenames as $f) {
        if (str_ends_with($path, '/' . $f) || $path === $f) return ' is-active';
    }
    return '';
}

// Para marcar el botón "Menú" activo cuando estás en cualquiera de estas pantallas
$menuPages = [
    'buscador_basico.php',
    'buscador_avanzado.php',
    'indice_marc.php',
    'tesauro.php',
    'edicion_impresa.php',
    'campeonatos.php',
    'equipos.php',
    'resultados.php',
];
?>

<nav class="nav nav--menu">
  <div class="nav__group nav__group--left<?= nav_active_any($menuPages, $path) ?>">
    <button class="nav__link nav__toggle" type="button" aria-haspopup="true" aria-expanded="false">
      ☰ <span class="menu-label">Menú</span> <span class="nav__caret">▾</span>
    </button>

    <div class="nav__dropdown nav__dropdown--left" role="menu" aria-label="Menú principal">
      <!-- Funcionalidades -->
      <div class="nav__head">Funcionalidades</div>
      <a class="nav__drop<?= nav_active('buscador_basico.php', $path) ?>" href="<?= $base ?>/buscador_basico.php">Buscador simple</a>
      <a class="nav__drop<?= nav_active('buscador_avanzado.php', $path) ?>" href="<?= $base ?>/buscador_avanzado.php">Buscador avanzado</a>
      <a class="nav__drop<?= nav_active('indice_marc.php', $path) ?>" href="<?= $base ?>/indice_marc.php?campo=600">Índices</a>
      <a class="nav__drop<?= nav_active('tesauro.php', $path) ?>" href="<?= $base ?>/tesauro.php">Tesauro</a>
      <a class="nav__drop<?= nav_active('edicion_impresa.php', $path) ?>" href="<?= $base ?>/edicion_impresa.php">Edición impresa</a>
      <a class="nav__drop<?= nav_active('fichero.php', $path) ?>" href="<?= rtrim(BASE_URL, '/') ?>/fichero.php">Fichero</a>

      <div class="nav__sep" role="separator" aria-hidden="true"></div>

      <!-- Secciones -->
      <div class="nav__head">Secciones</div>
      <a class="nav__drop<?= nav_active_any(['campeonatos.php','equipos.php','resultados.php'], $path) ?>" href="<?= $base ?>/futbol.php">Fútbol</a>
        <a href="<?= BASE_URL ?>/fotografos.php">Fotógrafos</a>
      <!-- Próximas secciones (placeholder) -->
      <a class="nav__drop is-disabled" href="#" aria-disabled="true">Boxeo</a>
      <a class="nav__drop is-disabled" href="#" aria-disabled="true">Turf</a>
      <a class="nav__drop is-disabled" href="#" aria-disabled="true">Tenis</a>
      <a class="nav__drop is-disabled" href="#" aria-disabled="true">Farándula</a>
      <a class="nav__drop is-disabled" href="#" aria-disabled="true">Selecciones</a>     

    </div>
  </div>
</nav>
