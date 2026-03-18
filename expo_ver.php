<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/exposiciones_helpers_v2.php';

$expoId = (int)($_GET['id'] ?? 0);
$expo = expo_get($expoId);
if (!$expo) {
  http_response_code(404);
  echo 'No se encontró la exposición.';
  exit;
}

$pieces = expo_public_visible_pieces($expoId);
$heroUrl = expo_find_hero_url($expo, $pieces);
$template = expo_normalize_template((string)($expo['template_name'] ?? 'hero_horizontal'));
$heroHeight = max(220, (int)($expo['hero_height_px'] ?? 520));
$heroWidth = max(22, min(65, (int)($expo['hero_width_pct'] ?? 38)));
$heroPosX = expo_h((string)($expo['hero_pos_x'] ?? '50%'));
$heroPosY = expo_h((string)($expo['hero_pos_y'] ?? '50%'));
$overlay = (float)($expo['hero_overlay_opacity'] ?? 0.35);
$overlay = max(0, min(1, $overlay));

$fotosUrl = 'expo_fotos.php?id=' . (int)$expoId;
$contextoUrl = 'expo_contexto.php?id=' . (int)$expoId;

function expo_btns_html(string $fotosUrl, string $contextoUrl): string {
  return '
    <div class="expo-hero-actions">
      <a class="btn btn-primary" href="' . expo_h($fotosUrl) . '">Ver fotos en la expo</a>
      <a class="btn" href="' . expo_h($contextoUrl) . '">Ver fotos de la expo en contexto del archivo</a>
    </div>
  ';
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= expo_h((string)$expo['title']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/exposiciones_v2.css">
</head>
<body class="expo-public-body template-<?= expo_h($template) ?>">
  <?php if ($template === 'hero_vertical_split'): ?>
    <header class="expo-public-split" style="--hero-width: <?= (int)$heroWidth ?>%; --hero-overlay: <?= expo_h((string)$overlay) ?>;">
      <div class="expo-public-split-media" style="<?= $heroUrl !== '' ? 'background-image:url(\'' . expo_h($heroUrl) . '\');' : '' ?> background-position: <?= $heroPosX ?> <?= $heroPosY ?>;">
        <div class="expo-public-split-overlay"></div>
      </div>
      <div class="expo-public-split-text">
        <?php if (!empty($expo['kicker'])): ?><div class="expo-kicker"><?= expo_h((string)$expo['kicker']) ?></div><?php endif; ?>
        <h1><?= expo_h((string)$expo['title']) ?></h1>
        <?php if (!empty($expo['subtitle'])): ?><p class="expo-subtitle"><?= expo_h((string)$expo['subtitle']) ?></p><?php endif; ?>
        <?php if (!empty($expo['intro_html'])): ?><div class="expo-public-intro-copy"><?= (string)$expo['intro_html'] ?></div><?php endif; ?>
        <?= expo_btns_html($fotosUrl, $contextoUrl) ?>
      </div>
    </header>

    <main class="expo-public-main">
      <section class="expo-card">
        <div class="expo-section-head">
          <h2>Fotos de la exposición</h2>
          <span class="expo-muted"><?= count($pieces) ?> visibles</span>
        </div>
        <p class="expo-muted">La exposición sigue debajo. También podés abrir la vista de fotos o verlas en contexto del archivo.</p>
        <div class="expo-public-grid expo-public-grid-editorial">
          <?php foreach ($pieces as $piece): ?>
            <article class="expo-public-piece">
              <div class="expo-public-thumb">
                <?php if (!empty($piece['thumb_url'])): ?>
                  <img src="<?= expo_h((string)$piece['thumb_url']) ?>" alt="">
                <?php else: ?>
                  <div class="expo-thumb-placeholder">Sin thumb</div>
                <?php endif; ?>
              </div>
              <div class="expo-public-text">
                <h3><?= expo_h((string)($piece['title'] ?: $piece['ref_id'])) ?></h3>
                <?php if (!empty($piece['subtitle'])): ?><div class="expo-muted"><?= expo_h((string)$piece['subtitle']) ?></div><?php endif; ?>
                <?php if (!empty($piece['caption_html'])): ?><div class="expo-caption"><?= (string)$piece['caption_html'] ?></div><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </main>

  <?php elseif ($template === 'fullscreen_story'): ?>
    <header class="expo-story-header">
      <?php if (!empty($expo['kicker'])): ?><div class="expo-kicker"><?= expo_h((string)$expo['kicker']) ?></div><?php endif; ?>
      <h1><?= expo_h((string)$expo['title']) ?></h1>
      <?php if (!empty($expo['subtitle'])): ?><p class="expo-subtitle"><?= expo_h((string)$expo['subtitle']) ?></p><?php endif; ?>
      <?= expo_btns_html($fotosUrl, $contextoUrl) ?>
    </header>

    <main class="expo-story-main">
      <?php if (!empty($expo['intro_html'])): ?>
        <section class="expo-story-intro expo-card">
          <?= (string)$expo['intro_html'] ?>
        </section>
      <?php endif; ?>

      <?php foreach ($pieces as $piece): ?>
        <section class="expo-story-piece">
          <div class="expo-story-piece-media">
            <?php if (!empty($piece['thumb_url'])): ?>
              <img src="<?= expo_h((string)$piece['thumb_url']) ?>" alt="">
            <?php else: ?>
              <div class="expo-thumb-placeholder">Sin thumb</div>
            <?php endif; ?>
          </div>
          <div class="expo-story-piece-text">
            <h2><?= expo_h((string)($piece['title'] ?: $piece['ref_id'])) ?></h2>
            <?php if (!empty($piece['subtitle'])): ?><div class="expo-muted"><?= expo_h((string)$piece['subtitle']) ?></div><?php endif; ?>
            <?php if (!empty($piece['caption_html'])): ?><div class="expo-caption"><?= (string)$piece['caption_html'] ?></div><?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </main>

  <?php else: ?>
    <header class="expo-hero" style="height: <?= (int)$heroHeight ?>px; <?= $heroUrl !== '' ? 'background-image:url(\'' . expo_h($heroUrl) . '\');' : '' ?> background-position: <?= $heroPosX ?> <?= $heroPosY ?>; --hero-overlay: <?= expo_h((string)$overlay) ?>;">
      <div class="expo-hero-overlay"></div>
      <div class="expo-hero-inner">
        <?php if (!empty($expo['kicker'])): ?><div class="expo-kicker"><?= expo_h((string)$expo['kicker']) ?></div><?php endif; ?>
        <h1><?= expo_h((string)$expo['title']) ?></h1>
        <?php if (!empty($expo['subtitle'])): ?><p class="expo-subtitle"><?= expo_h((string)$expo['subtitle']) ?></p><?php endif; ?>
        <?= expo_btns_html($fotosUrl, $contextoUrl) ?>
      </div>
    </header>

    <main class="expo-public-main">
      <?php if (!empty($expo['intro_html'])): ?>
        <section class="expo-public-intro expo-card">
          <?= (string)$expo['intro_html'] ?>
        </section>
      <?php endif; ?>

      <section class="expo-card">
        <div class="expo-section-head">
          <h2>Fotos de la exposición</h2>
          <span class="expo-muted"><?= count($pieces) ?> visibles</span>
        </div>
        <div class="expo-public-grid">
          <?php foreach ($pieces as $piece): ?>
            <article class="expo-public-piece">
              <div class="expo-public-thumb">
                <?php if (!empty($piece['thumb_url'])): ?>
                  <img src="<?= expo_h((string)$piece['thumb_url']) ?>" alt="">
                <?php else: ?>
                  <div class="expo-thumb-placeholder">Sin thumb</div>
                <?php endif; ?>
              </div>
              <div class="expo-public-text">
                <h3><?= expo_h((string)($piece['title'] ?: $piece['ref_id'])) ?></h3>
                <?php if (!empty($piece['subtitle'])): ?><div class="expo-muted"><?= expo_h((string)$piece['subtitle']) ?></div><?php endif; ?>
                <?php if (!empty($piece['caption_html'])): ?><div class="expo-caption"><?= (string)$piece['caption_html'] ?></div><?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    </main>
  <?php endif; ?>
</body>
</html>
