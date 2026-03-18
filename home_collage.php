<?php
/**
 * home_collage.php
 *
 * Portada tipo mosaico de tiras verticales usando imágenes
 * pertenecientes a colecciones.
 */

function afdc_home_db_all($db, string $sql, array $params = [], string $types = ''): array {
  if ($db instanceof PDO) {
    $st = $db->prepare($sql);
    $st->execute(array_values($params));
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
  if ($db instanceof mysqli) {
    $st = $db->prepare($sql);
    if (!$st) return [];
    if (!empty($params)) {
      $types = $types !== '' ? $types : str_repeat('s', count($params));
      $st->bind_param($types, ...$params);
    }
    $st->execute();
    $res = $st->get_result();
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC) ?: [];
  }
  return [];
}

function afdc_home_url_path(array $parts): string {
  $p = [];
  foreach ($parts as $seg) {
    $seg = (string)$seg;
    $seg = str_replace('\\', '/', $seg);
    $seg = trim($seg, '/');
    if ($seg === '') continue;
    $p[] = rawurlencode($seg);
  }
  return '/' . implode('/', $p);
}

function afdc_home_base_path(): string {
  $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  if ($base === '' || $base === '.') $base = '';
  return $base;
}

function afdc_home_resolve_image_key_map($db, array $keys): array {
  $map = [];
  $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
  if (empty($keys)) return $map;

  $wanted = [];
  foreach ($keys as $ik) {
    if (!preg_match('/^(.+?)_([0-9A-Za-z]+)$/', $ik, $m)) continue;
    $inv = trim((string)$m[1]);
    $label = trim((string)$m[2]);
    if ($inv === '' || $label === '') continue;
    $wanted[$ik] = ['inv' => $inv, 'label' => $label];
  }
  if (empty($wanted)) return $map;

  $invList = array_values(array_unique(array_column($wanted, 'inv')));
  $placeholders = implode(',', array_fill(0, count($invList), '?'));
  $params = $invList;
  $types = str_repeat('s', count($params));

  $rows = afdc_home_db_all($db, "
    SELECT inv, cajon, nombramiento
    FROM digitales
    WHERE inv IN ($placeholders)
      AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
      AND nombramiento IS NOT NULL
      AND nombramiento <> ''
  ", $params, $types);

  $base = afdc_home_base_path();

  $byInv = [];
  foreach ($rows as $r) {
    $inv = (string)($r['inv'] ?? '');
    if ($inv === '') continue;
    $byInv[$inv][] = $r;
  }

  foreach ($wanted as $ik => $w) {
    $inv = $w['inv'];
    $label = strtoupper($w['label']);
    $cand = $byInv[$inv] ?? [];
    foreach ($cand as $r) {
      $name = (string)($r['nombramiento'] ?? '');
      $cajon = (string)($r['cajon'] ?? '');
      if ($name === '' || $cajon === '') continue;

      if (preg_match('/_' . preg_quote($label, '/') . '\.(jpg|jpeg|png|webp)$/i', $name)) {
        $map[$ik] = afdc_home_url_path([$base, 'bajas', $cajon, $inv, $name]);
        break;
      }
    }
  }

  return $map;
}

$db = db();

$targetCount = 42;
$items = [];

try {
  $collections = afdc_home_db_all($db, "
    SELECT id, title
    FROM collections_v2
    /*WHERE is_public = 1*/
    ORDER BY RAND()
  ");
} catch (Throwable $e) {
  try {
    $collections = afdc_home_db_all($db, "
      SELECT id, title
      FROM collections_v2
      ORDER BY RAND()
    ");
  } catch (Throwable $e2) {
    $collections = [];
  }
}

if (!empty($collections)) {
  $usedImageKeys = [];
  $perCollectionCount = [];

  // RONDA 1: una imagen resoluble por colección
  foreach ($collections as $col) {
    if (count($items) >= $targetCount) break;

    $cid = (int)($col['id'] ?? 0);
    $title = trim((string)($col['title'] ?? 'Colección'));
    if ($cid <= 0) continue;

    $rows = afdc_home_db_all($db, "
      SELECT image_key, position
      FROM collection_items_v2
      WHERE collection_id = ?
      ORDER BY RAND()
      LIMIT 12
    ", [$cid], 'i');

    if (empty($rows)) continue;

    $candidateKeys = [];
    foreach ($rows as $row) {
      $ik = (string)($row['image_key'] ?? '');
      if ($ik !== '' && !isset($usedImageKeys[$ik])) {
        $candidateKeys[] = $ik;
      }
    }

    if (empty($candidateKeys)) continue;

    $candidateMap = afdc_home_resolve_image_key_map($db, $candidateKeys);
    if (empty($candidateMap)) continue;

    foreach ($rows as $row) {
      $imageKey = (string)($row['image_key'] ?? '');
      if ($imageKey === '' || isset($usedImageKeys[$imageKey])) continue;
      if (!isset($candidateMap[$imageKey])) continue;

      $items[] = [
        'collection_id' => $cid,
        'title'         => $title,
        'image_key'     => $imageKey,
        'position'      => $row['position'] ?? null,
      ];
      $usedImageKeys[$imageKey] = true;
      $perCollectionCount[$cid] = 1;
      break;
    }
  }

  // RONDA 2: completar con una segunda imagen resoluble por colección
  if (count($items) < $targetCount) {
    shuffle($collections);

    foreach ($collections as $col) {
      if (count($items) >= $targetCount) break;

      $cid = (int)($col['id'] ?? 0);
      $title = trim((string)($col['title'] ?? 'Colección'));
      if ($cid <= 0) continue;

      $already = (int)($perCollectionCount[$cid] ?? 0);
      if ($already >= 2) continue;

      $rows = afdc_home_db_all($db, "
        SELECT image_key, position
        FROM collection_items_v2
        WHERE collection_id = ?
        ORDER BY RAND()
        LIMIT 16
      ", [$cid], 'i');

      if (empty($rows)) continue;

      $candidateKeys = [];
      foreach ($rows as $row) {
        $ik = (string)($row['image_key'] ?? '');
        if ($ik !== '' && !isset($usedImageKeys[$ik])) {
          $candidateKeys[] = $ik;
        }
      }

      if (empty($candidateKeys)) continue;

      $candidateMap = afdc_home_resolve_image_key_map($db, $candidateKeys);
      if (empty($candidateMap)) continue;

      foreach ($rows as $row) {
        $imageKey = (string)($row['image_key'] ?? '');
        if ($imageKey === '' || isset($usedImageKeys[$imageKey])) continue;
        if (!isset($candidateMap[$imageKey])) continue;

        $items[] = [
          'collection_id' => $cid,
          'title'         => $title,
          'image_key'     => $imageKey,
          'position'      => $row['position'] ?? null,
        ];
        $usedImageKeys[$imageKey] = true;
        $perCollectionCount[$cid] = $already + 1;
        break;
      }
    }
  }

  if (!empty($items)) {
    shuffle($items);
  }
}

$imageMap = afdc_home_resolve_image_key_map(
  $db,
  array_map(fn($r) => (string)($r['image_key'] ?? ''), $items)
);

$tiles = [];
foreach ($items as $r) {
  $collectionId = (int)($r['collection_id'] ?? 0);
  $title = trim((string)($r['title'] ?? 'Colección'));
  $imageKey = (string)($r['image_key'] ?? '');
  $img = (string)($imageMap[$imageKey] ?? '');

  if ($collectionId <= 0 || $imageKey === '' || $img === '') continue;

  $tiles[] = [
    'collection_id' => $collectionId,
    'title'         => $title,
    'image_key'     => $imageKey,
    'img'           => $img,
    'open'          => 'ver_digital.php?collection_id=' . rawurlencode((string)$collectionId)
                    . '&image_key=' . rawurlencode($imageKey)
                    . '&from_col_title=' . rawurlencode($title),
  ];
}

$tiles = array_values($tiles);
?>
<style>
  html, body { height: 100%; }
  body { margin: 0; }

  .app{
    min-height: 100vh;
    display:flex;
    flex-direction:column;
  }
  .app > main{
    flex: 1 1 auto;
    padding: 0 !important;
    margin: 0 !important;
    max-width: none !important;
    width: 100%;
  }

  .home-strips{
    position: relative;
    min-height: calc(100vh - 72px);
    height: calc(100vh - 72px);
    overflow: hidden;
    background: rgba(0,0,0,.08);
  }

  .home-strips__wrap{
    display:flex;
    align-items:stretch;
    gap: 6px;
    height: 100%;
    width: 100%;
    padding: 6px;
  }

  .home-strip{
    position: relative;
    height: 100%;
    min-width: 0;
    overflow: hidden;
    text-decoration: none;
    background: rgba(255,255,255,.05);
    border-left: 1px solid rgba(255,255,255,.06);
    border-right: 1px solid rgba(0,0,0,.06);
    isolation: isolate;
    display:flex;
    align-items:stretch;
    justify-content:center;
  }

  .home-strip--xs{ flex: 1 1 0; }
  .home-strip--sm{ flex: 1.35 1 0; }
  .home-strip--md{ flex: 1.8 1 0; }
  .home-strip--lg{ flex: 2.3 1 0; }
  .home-strip--xl{ flex: 2.9 1 0; }

  .home-strip__img{
    position:absolute;
    top:0;
    left:50%;
    width:auto;
    height:100%;
    max-width:none;
    object-fit: contain;
    transform: translateX(var(--shift-x, -50%)) scale(1.03);
    transform-origin: center center;
    transition: transform 240ms ease, filter 240ms ease, opacity 240ms ease;
    display:block;
    filter: grayscale(12%) contrast(1.03);
  }

  .home-strip::after{
    content:'';
    position:absolute;
    inset:0;
    background:
      linear-gradient(to top, rgba(0,0,0,.58) 0%, rgba(0,0,0,.18) 24%, rgba(0,0,0,.04) 45%, rgba(0,0,0,.10) 100%);
    opacity:.52;
    transition: opacity 220ms ease;
    z-index:1;
    pointer-events:none;
  }

  .home-strip:hover .home-strip__img{
    transform: translateX(var(--shift-x, -50%)) scale(1.08);
    filter: grayscale(0%) contrast(1.06);
  }

  .home-strip:hover::after{
    opacity:.30;
  }

  .home-strip__label{
    position:absolute;
    left:10px;
    right:10px;
    bottom:12px;
    z-index:2;
    color:#fff;
    font-weight:800;
    font-size:13px;
    line-height:1.2;
    text-shadow: 0 1px 2px rgba(0,0,0,.55);
    opacity:0;
    transform: translateY(8px);
    transition: opacity 180ms ease, transform 180ms ease;
    pointer-events:none;
    overflow:hidden;
    display:-webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
  }

  .home-strip:hover .home-strip__label{
    opacity:1;
    transform: translateY(0);
  }

  .home-strips__empty{
    min-height: calc(100vh - 72px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding: 32px;
    font-size: 18px;
    font-weight: 800;
    opacity:.72;
  }

  @media (max-width: 1280px){
    .home-strip--xs{ flex-grow: 1; }
    .home-strip--sm{ flex-grow: 1.25; }
    .home-strip--md{ flex-grow: 1.65; }
    .home-strip--lg{ flex-grow: 2.05; }
    .home-strip--xl{ flex-grow: 2.5; }
  }

  @media (max-width: 980px){
    .home-strips{
      min-height: calc(100vh - 64px);
      height: calc(100vh - 64px);
    }
    .home-strips__wrap{
      gap: 4px;
      padding: 4px;
    }
    .home-strip--xs{ flex-grow: 1; }
    .home-strip--sm{ flex-grow: 1.2; }
    .home-strip--md{ flex-grow: 1.5; }
    .home-strip--lg{ flex-grow: 1.9; }
    .home-strip--xl{ flex-grow: 2.3; }
    .home-strip__label{
      font-size:12px;
      left:8px;
      right:8px;
      bottom:10px;
    }
  }

  @media (max-width: 700px){
    .home-strip--xs,
    .home-strip--sm{ display:none; }

    .home-strip--md{ flex-grow: 1.4; }
    .home-strip--lg{ flex-grow: 1.8; }
    .home-strip--xl{ flex-grow: 2.2; }
  }
</style>

<?php if (empty($tiles)): ?>
  <section class="home-strips">
    <div class="home-strips__empty">Todavía no hay imágenes de colecciones disponibles para la portada.</div>
  </section>
<?php else: ?>
  <section class="home-strips">
    <div class="home-strips__wrap">
      <?php
      $sizes = ['xs', 'sm', 'md', 'lg', 'sm', 'md', 'xl', 'sm', 'md', 'lg', 'xs', 'md'];
      foreach ($tiles as $idx => $t):
        $size = $sizes[$idx % count($sizes)];
      ?>
        <a class="home-strip home-strip--<?= htmlspecialchars($size) ?>"
           href="<?= htmlspecialchars($t['open']) ?>"
           title="<?= htmlspecialchars($t['title']) ?>">
          <img class="home-strip__img"
               src="<?= htmlspecialchars($t['img']) ?>"
               alt="<?= htmlspecialchars($t['title']) ?>"
               loading="lazy"
               decoding="async">
          <span class="home-strip__label"><?= htmlspecialchars($t['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <script>
  (function(){
    const strips = document.querySelectorAll('.home-strip');
    strips.forEach((strip) => {
      const img = strip.querySelector('.home-strip__img');
      if (!img) return;

      const shift = -35 - Math.floor(Math.random() * 30);
      img.style.setProperty('--shift-x', shift + '%');
    });
  })();
</script>
<?php endif; ?>