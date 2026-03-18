<?php
require __DIR__ . '/inc/bootstrap.php';

$page = $_GET['p'] ?? 'home';

switch ($page) {
  case 'dia_como_hoy':
    $pageTitle = 'Un día como hoy — AFDC';
    $mainClass = 'container-fluid';
    require __DIR__ . '/inc/header.php';
    require __DIR__ . '/dia_como_hoy.php';
    require __DIR__ . '/inc/footer.php';
    break;

  case 'home':
  default:
    $pageTitle = 'AFDC';
    $mainClass = 'container-fluid';
    require __DIR__ . '/inc/header.php';
    require __DIR__ . '/home_collage.php';
    require __DIR__ . '/inc/footer.php';
    break;
}