<?php
require_once __DIR__ . '/inc/bootstrap.php';

$pageTitle = 'Fútbol · AFDC v1';
include __DIR__ . '/inc/header.php';

$base = rtrim(BASE_URL, '/');
?>

<section class="hero hero--futbol">
  <div class="hero__media" aria-hidden="true">
    <img
      class="hero__img"
      src="<?= $base ?>/assets/img/futbol/diez.png"
      alt="Jugador con camiseta número 10 disputando una pelota en un estadio, con tribuna de fondo."
    >
    <div class="hero__shade"></div>
  </div>

  <div class="hero__content">
    <div class="hero__kicker">Categoría</div>
    <h1 class="hero__title">Fútbol</h1>
    <p class="hero__lead">Campeonatos y equipos (por ahora). Más secciones en camino.</p>

    <div class="hero__actions">
      <a class="btn" href="<?= $base ?>/campeonatos.php">Ver campeonatos</a>
      <a class="btn" href="<?= $base ?>/equipos.php">Ver equipos</a>
    </div>
  </div>
</section>

<section class="futbol-body">
  <p class="p">
    El diario comienza en 1963 y el período inicial conserva material muy valioso: formaciones, campeones,
    vueltas olímpicas y grandes momentos, con fuerte presencia de los equipos de mayor protagonismo de la época
    (especialmente Boca y River).
  </p>
  <p class="p">
    En 1973 se produjo un expurgo importante y se perdieron, sobre todo, muchos <strong>sobres de partidos completos</strong>.
    Aun así, quedaron selecciones de cada equipo y series destacadas.
  </p>
  <p class="p">
    El tramo más sólido y continuo del archivo de fútbol se concentra entre <strong>1974 y 1982</strong>.
    Luego aparece un nuevo vacío, hasta retomar con otra etapa muy potente entre <strong>1992 y 2004</strong>.
  </p>

  <div class="futbol-links">
    <a class="btn" href="<?= $base ?>/campeonatos.php">Ir a Campeonatos</a>
    <a class="btn" href="<?= $base ?>/equipos.php">Ir a Equipos</a>
  </div>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
