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

function expo_full_url(array $piece): string {
  $url = trim((string)($piece['url'] ?? ''));
  if ($url !== '') return $url;
  return trim((string)($piece['thumb_url'] ?? ''));
}
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= expo_h((string)$expo['title']) ?> · Fotos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/exposiciones_v2.css">
  <style>
    .expo-fotos-wrap{max-width:1400px;margin:0 auto;padding:24px 22px 40px}
    .expo-fotos-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:18px}
    .expo-fotos-actions{display:flex;gap:10px;flex-wrap:wrap}
    .expo-fotos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}
    .expo-foto-card{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden}
    .expo-foto-card img{width:100%;display:block;aspect-ratio:4/3;object-fit:cover;background:rgba(0,0,0,.08);cursor:zoom-in}
    .expo-foto-meta{padding:14px}
    .expo-foto-meta h3{margin:0 0 6px}
    .expo-foto-meta .expo-muted{font-size:14px}
    .expo-foto-viewer{position:fixed;inset:0;background:rgba(0,0,0,.82);display:none;align-items:center;justify-content:center;padding:20px;z-index:100}
    .expo-foto-viewer.on{display:flex}
    .expo-foto-stage{max-width:min(1400px,95vw);max-height:92vh;width:100%;display:grid;grid-template-rows:auto 1fr auto;background:#0d1117;border:1px solid rgba(255,255,255,.14);border-radius:18px;overflow:hidden}
    .expo-foto-head,.expo-foto-foot{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.10);display:flex;align-items:center;justify-content:space-between;gap:12px}
    .expo-foto-foot{border-bottom:none;border-top:1px solid rgba(255,255,255,.10)}
    .expo-foto-main{display:flex;align-items:center;justify-content:center;background:#000;min-height:0}
    .expo-foto-main img{max-width:100%;max-height:100%;object-fit:contain}
    .expo-foto-nav{display:flex;gap:10px;align-items:center}
  </style>
</head>
<body class="expo-public-body">
  <div class="expo-fotos-wrap">
    <div class="expo-fotos-top">
      <div>
        <div class="expo-kicker"><?= expo_h((string)($expo['kicker'] ?? 'Exposición')) ?></div>
        <h1 style="margin:4px 0 8px;"><?= expo_h((string)$expo['title']) ?></h1>
        <?php if (!empty($expo['subtitle'])): ?><div class="expo-subtitle" style="margin:0;"><?= expo_h((string)$expo['subtitle']) ?></div><?php endif; ?>
      </div>
      <div class="expo-fotos-actions">
        <a class="btn" href="expo_ver.php?id=<?= (int)$expoId ?>">Volver a la portada</a>
        <a class="btn btn-primary" href="expo_contexto.php?id=<?= (int)$expoId ?>">Ver fotos de la expo en contexto del archivo</a>
      </div>
    </div>

    <section class="expo-card" style="margin-bottom:18px;">
      <div class="expo-section-head">
        <h2>Fotos en la expo</h2>
        <span class="expo-muted"><?= count($pieces) ?> visibles</span>
      </div>
      <p class="expo-muted">Vista limpia de las piezas activas de la exposición, respetando el orden curatorial.</p>
    </section>

    <div class="expo-fotos-grid">
      <?php foreach ($pieces as $idx => $piece): ?>
        <article class="expo-foto-card">
          <?php $full = expo_full_url($piece); ?>
          <?php if ($full !== ''): ?>
            <img
              src="<?= expo_h((string)($piece['thumb_url'] ?: $full)) ?>"
              data-index="<?= (int)$idx ?>"
              alt="<?= expo_h((string)($piece['title'] ?: $piece['ref_id'])) ?>"
              class="expo-foto-open">
          <?php else: ?>
            <div class="expo-thumb-placeholder" style="aspect-ratio:4/3;">Sin imagen</div>
          <?php endif; ?>
          <div class="expo-foto-meta">
            <h3><?= expo_h((string)($piece['title'] ?: $piece['ref_id'])) ?></h3>
            <?php if (!empty($piece['subtitle'])): ?><div class="expo-muted"><?= expo_h((string)$piece['subtitle']) ?></div><?php endif; ?>
            <?php if (!empty($piece['caption_html'])): ?><div class="expo-caption"><?= (string)$piece['caption_html'] ?></div><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="expo-foto-viewer" id="expoFotoViewer">
    <div class="expo-foto-stage">
      <div class="expo-foto-head">
        <div id="expoFotoTitle"></div>
        <button class="btn" type="button" id="expoFotoClose">Cerrar</button>
      </div>
      <div class="expo-foto-main">
        <img id="expoFotoImg" alt="">
      </div>
      <div class="expo-foto-foot">
        <div class="expo-muted" id="expoFotoCaption"></div>
        <div class="expo-foto-nav">
          <button class="btn" type="button" id="expoFotoPrev">Anterior</button>
          <button class="btn" type="button" id="expoFotoNext">Siguiente</button>
        </div>
      </div>
    </div>
  </div>

<script>
(function(){
  const pieces = <?= json_encode(array_values(array_map(function($p){
    return [
      'title' => (string)($p['title'] ?: $p['ref_id']),
      'subtitle' => (string)($p['subtitle'] ?? ''),
      'caption_html' => (string)($p['caption_html'] ?? ''),
      'thumb_url' => (string)($p['thumb_url'] ?? ''),
      'full_url' => trim((string)($p['url'] ?? '')) ?: trim((string)($p['thumb_url'] ?? ''))
    ];
  }, $pieces)), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  const viewer = document.getElementById('expoFotoViewer');
  const img = document.getElementById('expoFotoImg');
  const title = document.getElementById('expoFotoTitle');
  const caption = document.getElementById('expoFotoCaption');
  const btnClose = document.getElementById('expoFotoClose');
  const btnPrev = document.getElementById('expoFotoPrev');
  const btnNext = document.getElementById('expoFotoNext');
  let current = 0;

  function openAt(idx){
    current = idx;
    const p = pieces[idx];
    if (!p) return;
    img.src = p.full_url || p.thumb_url || '';
    title.innerHTML = '<strong>' + (p.title || '') + '</strong>' + (p.subtitle ? ' · ' + p.subtitle : '');
    caption.innerHTML = p.caption_html || '';
    viewer.classList.add('on');
  }

  document.querySelectorAll('.expo-foto-open').forEach(el => {
    el.addEventListener('click', function(){
      openAt(parseInt(this.dataset.index || '0', 10) || 0);
    });
  });

  btnClose.addEventListener('click', ()=> viewer.classList.remove('on'));
  viewer.addEventListener('click', (e)=> { if (e.target === viewer) viewer.classList.remove('on'); });
  btnPrev.addEventListener('click', ()=> openAt((current - 1 + pieces.length) % pieces.length));
  btnNext.addEventListener('click', ()=> openAt((current + 1) % pieces.length));
  document.addEventListener('keydown', (e)=>{
    if (!viewer.classList.contains('on')) return;
    if (e.key === 'Escape') viewer.classList.remove('on');
    if (e.key === 'ArrowLeft') openAt((current - 1 + pieces.length) % pieces.length);
    if (e.key === 'ArrowRight') openAt((current + 1) % pieces.length);
  });
})();
</script>
</body>
</html>
