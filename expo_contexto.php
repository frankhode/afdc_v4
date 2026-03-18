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

function expo_ctx_h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function expo_ctx_safe_seg(string $s): string {
  $s = trim($s);
  $s = str_replace(["..", "/", "\\", "\0"], '', $s);
  return preg_replace('/[<>:"|?*]/', '_', $s);
}

function expo_ctx_label_from_filename(string $name): string {
  $lab = '999';
  if (preg_match('/_(\d{1,4})\.(jpe?g|png)$/i', $name, $m)) {
    $lab = str_pad((string)(int)$m[1], 3, '0', STR_PAD_LEFT);
  }
  return $lab;
}

function expo_ctx_barcode_from_piece(array $piece): string {
  $barcode = trim((string)($piece['barcode'] ?? ''));
  if ($barcode !== '') return $barcode;

  $ref = trim((string)($piece['ref_id'] ?? ''));
  if ($ref !== '' && strpos($ref, '_') !== false) {
    [$barcode] = explode('_', $ref, 2);
    return expo_ctx_safe_seg($barcode);
  }
  return '';
}

function expo_ctx_piece_label(array $piece): string {
  $label = trim((string)($piece['label'] ?? ''));
  if ($label !== '') return $label;

  $ref = trim((string)($piece['ref_id'] ?? ''));
  if ($ref !== '' && strpos($ref, '_') !== false) {
    [, $lab] = explode('_', $ref, 2);
    $lab = preg_replace('/\D+/', '', (string)$lab);
    return str_pad($lab, 3, '0', STR_PAD_LEFT);
  }
  return '000';
}

function expo_ctx_fetch_ufi_by_barcode(string $barcode): string {
  $rows = q("SELECT ufi FROM items WHERE barcode=? LIMIT 1", 's', [$barcode]) ?: [];
  return $rows ? trim((string)($rows[0]['ufi'] ?? '')) : '';
}

function expo_ctx_parse_yyyymmdd_to_iso(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return ['', 0];

  $digits = preg_replace('/\D+/', '', $raw);
  if (strlen($digits) >= 8) {
    $y = substr($digits, 0, 4);
    $m = substr($digits, 4, 2);
    $d = substr($digits, 6, 2);
    if (checkdate((int)$m, (int)$d, (int)$y)) {
      return [$y . '-' . $m . '-' . $d, (int)$y];
    }
  }
  return ['', 0];
}

function expo_ctx_fetch_envelope_meta(string $barcode): array {
  $barcode = expo_ctx_safe_seg($barcode);
  if ($barcode === '') return ['sys' => '', 'group' => '', 'date_iso' => '', 'year' => 0];

  $rowsTitle = q(
    "SELECT sys, fecha
     FROM titulos
     WHERE barcode = ?
     LIMIT 1",
    's',
    [$barcode]
  ) ?: [];

  $sys = $rowsTitle ? trim((string)($rowsTitle[0]['sys'] ?? '')) : '';
  $fecha = $rowsTitle ? trim((string)($rowsTitle[0]['fecha'] ?? '')) : '';
  [$dateIso, $year] = expo_ctx_parse_yyyymmdd_to_iso($fecha);

  $group = '';
  if ($sys !== '') {
    $rowsReg = q(
      "SELECT titulo245
       FROM registros
       WHERE sys = ?
         AND titulo245 IS NOT NULL
         AND titulo245 <> ''
       LIMIT 1",
      's',
      [$sys]
    ) ?: [];
    $group = $rowsReg ? trim((string)($rowsReg[0]['titulo245'] ?? '')) : '';
  }

  return [
    'sys' => $sys,
    'group' => $group,
    'date_iso' => $dateIso,
    'year' => (int)$year,
  ];
}

function expo_ctx_fetch_envelope_images_online(string $barcode, string $ufi): array {
  if ($barcode === '' || $ufi === '') return [];

  $rows = q(
    "SELECT nombramiento
     FROM digitales
     WHERE inv=?
       AND (carpeta='Bajas' OR carpeta LIKE '%Bajas%')
       AND nombramiento IS NOT NULL
       AND nombramiento<>''",
    's',
    [$barcode]
  ) ?: [];

  $out = [];
  foreach ($rows as $r) {
    $nm = expo_ctx_safe_seg((string)($r['nombramiento'] ?? ''));
    if ($nm === '') continue;

    $lab = expo_ctx_label_from_filename($nm);
    $out[] = [
      'label' => $lab,
      'url' => '/afdc_v2/bajas/' . rawurlencode($ufi) . '/' . rawurlencode($barcode) . '/' . rawurlencode($nm),
      'name' => $nm,
    ];
  }

  usort($out, fn($a, $b) => (int)$a['label'] <=> (int)$b['label']);
  return $out;
}

$barcodesSet = [];
$pieceIndexByBarcode = [];
$firstMetaByBarcode = [];

foreach ($pieces as $piece) {
  if (($piece['piece_type'] ?? '') !== 'imagen') continue;

  $barcode = expo_ctx_barcode_from_piece($piece);
  if ($barcode === '') continue;

  $barcodesSet[$barcode] = true;
  if (!isset($firstMetaByBarcode[$barcode])) {
    $firstMetaByBarcode[$barcode] = $piece;
  }

  $lab = expo_ctx_piece_label($piece);
  if (!isset($pieceIndexByBarcode[$barcode])) {
    $pieceIndexByBarcode[$barcode] = [];
  }
  $pieceIndexByBarcode[$barcode][$lab] = true;
}

$envelopes = [];
$envelopeImages = [];

foreach (array_keys($barcodesSet) as $barcode) {
  $ufi = expo_ctx_fetch_ufi_by_barcode($barcode);
  $envMeta = expo_ctx_fetch_envelope_meta($barcode);
  $pieceMeta = $firstMetaByBarcode[$barcode] ?? [];

  $envelopeImages[$barcode] = expo_ctx_fetch_envelope_images_online($barcode, $ufi);

  $envelopes[$barcode] = [
    'barcode' => $barcode,
    'ufi' => $ufi,
    'sys' => (string)($envMeta['sys'] ?? ''),
    'group' => trim((string)($pieceMeta['group'] ?? '')) !== ''
      ? trim((string)$pieceMeta['group'])
      : trim((string)($envMeta['group'] ?? '')),
    'year' => (int)($pieceMeta['year'] ?? 0) > 0
      ? (int)$pieceMeta['year']
      : (int)($envMeta['year'] ?? 0),
    'date_iso' => trim((string)($pieceMeta['date_iso'] ?? '')) !== ''
      ? trim((string)$pieceMeta['date_iso'])
      : trim((string)($envMeta['date_iso'] ?? '')),
  ];
}

$GLOBALS['__expo_ctx_env_meta'] = $envelopes;

$manifest = [
  'expo' => [
    'id' => (int)$expoId,
    'title' => (string)($expo['title'] ?? ''),
    'kicker' => (string)($expo['kicker'] ?? ''),
  ],
  'items' => array_values(array_filter(array_map(function($p){
    if (($p['piece_type'] ?? '') !== 'imagen') return null;

    $barcode = expo_ctx_barcode_from_piece($p);
    $env = $GLOBALS['__expo_ctx_env_meta'][$barcode] ?? ['group' => '', 'date_iso' => '', 'year' => 0];

    return [
      'piece_id' => (int)($p['id'] ?? 0),
      'piece_type' => 'imagen',
      'ref_id' => (string)($p['ref_id'] ?? ''),
      'barcode' => $barcode,
      'label' => expo_ctx_piece_label($p),
      'url' => trim((string)($p['url'] ?? '')) ?: trim((string)($p['thumb_url'] ?? '')),
      'group' => trim((string)($p['group'] ?? '')) !== ''
        ? (string)$p['group']
        : (string)($env['group'] ?? ''),
      'date_iso' => trim((string)($p['date_iso'] ?? '')) !== ''
        ? (string)$p['date_iso']
        : (string)($env['date_iso'] ?? ''),
      'year' => (int)($p['year'] ?? 0) > 0
        ? (int)$p['year']
        : (int)($env['year'] ?? 0),
      'display_title' => (string)($p['title'] ?: $p['ref_id']),
      'caption_html' => (string)($p['caption_html'] ?? ''),
    ];
  }, $pieces))),
  'envelopes' => $envelopes,
  'envelope_images' => $envelopeImages,
  'piece_labels_by_barcode' => $pieceIndexByBarcode,
];
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= expo_ctx_h((string)$expo['title']) ?> · Contexto del archivo</title>
  <link rel="stylesheet" href="css/exposiciones_v2.css">
  <style>
    body.expo-public-body.ctx-body{
      --ctx-bg:#07111f;
      --ctx-card:#0c1a2d;
      --ctx-card-2:#0f2138;
      --ctx-line:rgba(125,161,216,.22);
      --ctx-line-strong:rgba(125,161,216,.36);
      --ctx-text:#e8eef9;
      --ctx-muted:#9db0d3;
      --ctx-accent:#e2ad34;
      --ctx-link:#9f7cff;
      background:linear-gradient(180deg,#030913 0%,#07111f 100%);
      color:var(--ctx-text);
    }
    .ctx-root,.ctx-root *{box-sizing:border-box}
    .ctx-root .btn{
      appearance:none;border:1px solid var(--ctx-line-strong);background:rgba(14,31,54,.9);
      color:var(--ctx-text);padding:10px 14px;border-radius:12px;text-decoration:none;
      display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;cursor:pointer
    }
    .ctx-root .btn:hover{border-color:rgba(226,173,52,.45);background:rgba(18,38,66,.98)}
    .ctx-root .btn.btn-primary{background:var(--ctx-accent);border-color:var(--ctx-accent);color:#111827}
    .ctx-root .muted{color:var(--ctx-muted)}
    .ctx-root .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .ctx-root .pill{
      display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;
      font-size:12px;font-weight:700;background:rgba(226,173,52,.18);color:#f0c767
    }

    .ctx-wrap{display:grid;grid-template-rows:auto 1fr;min-height:100vh}
    .ctx-topbar{
      position:sticky;top:0;z-index:20;background:rgba(3,9,19,.92);
      backdrop-filter:blur(12px);border-bottom:1px solid var(--ctx-line);
      padding:14px 16px;display:flex;gap:14px;align-items:flex-start;justify-content:space-between
    }
    .ctx-title{font-weight:800;font-size:16px;display:flex;gap:10px;align-items:center;line-height:1.2}
    .ctx-sub{color:var(--ctx-muted);font-size:13px;margin-top:4px}

    .ctx-layout{display:grid;grid-template-columns:340px 1fr;gap:14px;padding:14px;align-items:start}
    @media (max-width:980px){.ctx-layout{grid-template-columns:1fr}}

    .ctx-panel{
      background:linear-gradient(180deg,var(--ctx-card) 0%,var(--ctx-card-2) 100%);
      border:1px solid var(--ctx-line);border-radius:18px;padding:14px;
      position:sticky;top:80px;align-self:start;max-height:calc(100vh - 96px);
      overflow:auto;box-shadow:0 14px 44px rgba(0,0,0,.28)
    }
    .ctx-panel label{display:block;margin:0 0 6px;font-weight:700;color:var(--ctx-text)}
    .ctx-panel select,.ctx-panel input{
      width:100%;display:block;margin:0 0 12px;
      background:#09172a;color:var(--ctx-text);border:1px solid var(--ctx-line-strong);
      border-radius:12px;padding:10px 12px;outline:none
    }
    .ctx-panel select:focus,.ctx-panel input:focus{border-color:rgba(226,173,52,.5);box-shadow:0 0 0 3px rgba(226,173,52,.12)}
    .ctx-stat{margin-top:6px;font-size:13px;color:var(--ctx-muted)}

    .ctx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}
    .ctx-card{
      background:linear-gradient(180deg,var(--ctx-card) 0%,var(--ctx-card-2) 100%);
      border:1px solid var(--ctx-line);border-radius:16px;overflow:hidden;cursor:pointer;
      transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease
    }
    .ctx-card:hover{transform:translateY(-2px);border-color:rgba(226,173,52,.38);box-shadow:0 10px 26px rgba(0,0,0,.24)}
    .ctx-thumb{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:#060b12}
    .ctx-meta{padding:10px 12px;font-size:12px;color:var(--ctx-muted)}
    .ctx-meta b{color:var(--ctx-text)}
    .ctx-empty{display:none;padding:22px;text-align:center;color:var(--ctx-muted)}

    .ctx-modal{
      position:fixed;inset:0;background:rgba(2,7,15,.76);backdrop-filter:blur(8px);
      display:none;z-index:100;align-items:center;justify-content:center;padding:20px
    }
    .ctx-modal.on{display:flex}
    .ctx-viewer{
      width:min(1280px,96vw);height:min(820px,92vh);
      background:linear-gradient(180deg,#0a1525 0%,#0d1b2f 100%);
      border:1px solid var(--ctx-line-strong);border-radius:20px;overflow:hidden;
      display:grid;grid-template-rows:auto 1fr;box-shadow:0 26px 80px rgba(0,0,0,.5)
    }
    .ctx-vtop{
      padding:12px 14px;border-bottom:1px solid var(--ctx-line);
      display:flex;gap:12px;align-items:center;justify-content:space-between;
      background:rgba(7,17,31,.78)
    }
    .ctx-vmain{display:grid;grid-template-columns:minmax(0,1fr) 300px;min-height:0}
    @media (max-width:980px){.ctx-vmain{grid-template-columns:1fr}.ctx-vside{display:none}}
    .ctx-vimgwrap{
      background:#000;display:flex;align-items:center;justify-content:center;overflow:auto;position:relative;
      cursor:zoom-in;
    }
    .ctx-vimg{
      max-width:100%;max-height:100%;object-fit:contain;display:block;
      transform-origin:center center;transition:transform .18s ease;user-select:none
    }
    .ctx-vimg.zoomed{transform:scale(2);cursor:zoom-out}
    .ctx-vimgwrap.zoomed{cursor:zoom-out}
    .ctx-vside{
      border-left:1px solid var(--ctx-line);padding:12px;overflow:hidden;
      display:flex;flex-direction:column;gap:10px;min-height:0;background:rgba(8,18,32,.92)
    }
    .ctx-vside-head{display:flex;justify-content:space-between;gap:10px;align-items:center}
    .ctx-thumbpane{
      border:1px solid var(--ctx-line);border-radius:14px;overflow:auto;padding:10px;
      background:rgba(4,11,20,.46);flex:1 1 auto;min-height:0;height:0
    }
    .ctx-thumbgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
    .ctx-timg{
      width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px;border:2px solid transparent;
      cursor:pointer;background:#050a11;transition:border-color .12s ease,transform .12s ease,opacity .12s ease
    }
    .ctx-timg:hover{transform:translateY(-1px)}
    .ctx-timg.on{border-color:var(--ctx-accent)}
    .ctx-timg.inexpo{box-shadow:0 0 0 2px rgba(226,173,52,.35)}
    .ctx-timg.notexpo{opacity:.55;filter:grayscale(.28) contrast(.92)}

    .ctx-links a{color:var(--ctx-link);text-decoration:none}
    .ctx-links a:hover{text-decoration:underline}
  </style>
</head>
<body class="expo-public-body ctx-body">
<div class="ctx-root">
  <div class="ctx-wrap">
    <div class="ctx-topbar">
      <div>
        <div class="ctx-title"><?= expo_ctx_h((string)$expo['title']) ?> <span class="pill">contexto del archivo</span></div>
        <div class="ctx-sub"><?= count($manifest['items']) ?> imágenes de la expo agrupadas por sobre</div>
      </div>
      <div class="row ctx-links">
        <a class="btn" href="expo_ver.php?id=<?= (int)$expoId ?>">Portada</a>
        <a class="btn" href="expo_fotos.php?id=<?= (int)$expoId ?>">Ver fotos en la expo</a>
      </div>
    </div>

    <div class="ctx-layout">
      <aside class="ctx-panel">
        <label for="fGroup">Grupo</label>
        <select id="fGroup"><option value="">Todos</option></select>

        <label for="fYear">Año</label>
        <select id="fYear"><option value="">Todos</option></select>

        <label for="fText">Buscar</label>
        <input id="fText" type="text" placeholder="sobre · grupo · año">

        <div class="ctx-stat" id="stat"></div>
      </aside>

      <main>
        <div class="ctx-grid" id="grid"></div>
        <div class="ctx-empty" id="empty">No hay resultados.</div>
      </main>
    </div>
  </div>

  <div class="ctx-modal" id="modal">
    <div class="ctx-viewer">
      <div class="ctx-vtop">
        <div id="vinfo"></div>
        <button class="btn" id="closeBtn" type="button">Cerrar</button>
      </div>
      <div class="ctx-vmain">
        <div class="ctx-vimgwrap" id="vimgWrap"><img class="ctx-vimg" id="vimg" alt=""></div>
        <div class="ctx-vside">
          <div class="ctx-vside-head">
            <span>Resto del sobre</span>
            <span class="muted" id="vsideCount" style="font-size:12px;"></span>
          </div>
          <div class="ctx-thumbpane"><div class="ctx-thumbgrid" id="thumbGrid"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
window.__MANIFEST__ = <?= json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function(){
  const M = window.__MANIFEST__ || {};
  const grid = document.getElementById('grid');
  const empty = document.getElementById('empty');
  const stat = document.getElementById('stat');
  const fGroup = document.getElementById('fGroup');
  const fYear = document.getElementById('fYear');
  const fText = document.getElementById('fText');

  const modal = document.getElementById('modal');
  const vimgWrap = document.getElementById('vimgWrap');
  const vimg = document.getElementById('vimg');
  const vinfo = document.getElementById('vinfo');
  const closeBtn = document.getElementById('closeBtn');
  const thumbGrid = document.getElementById('thumbGrid');
  const vsideCount = document.getElementById('vsideCount');

  const items = M.items || [];
  const envImgs = M.envelope_images || {};
  const pieceLabelsByBarcode = M.piece_labels_by_barcode || {};
  let zoomed = false;

  function norm(s){ return (s || '').toString().toLowerCase().trim(); }
  function escapeHtml(s){
    return (s || '').toString().replace(/[&<>"]/g, m => (
      m === '&' ? '&amp;' :
      m === '<' ? '&lt;' :
      m === '>' ? '&gt;' : '&quot;'
    ));
  }

  const groups = Array.from(new Set(items.map(it => (it.group || '').trim()).filter(Boolean))).sort((a,b)=>a.localeCompare(b,'es'));
  groups.forEach(g => {
    const opt = document.createElement('option');
    opt.value = g;
    opt.textContent = g;
    fGroup.appendChild(opt);
  });

  const years = Array.from(new Set(items.map(it => parseInt(it.year || 0, 10)).filter(Boolean))).sort((a,b)=>a-b);
  years.forEach(y => {
    const opt = document.createElement('option');
    opt.value = String(y);
    opt.textContent = String(y);
    fYear.appendChild(opt);
  });

  function renderGrid(list){
    grid.innerHTML = '';
    empty.style.display = list.length ? 'none' : 'block';

    list.forEach(it => {
      const card = document.createElement('div');
      card.className = 'ctx-card';

      const img = document.createElement('img');
      img.className = 'ctx-thumb';
      img.loading = 'lazy';
      img.src = it.url || '';
      img.alt = it.display_title || it.ref_id || '';
      card.appendChild(img);

      const meta = document.createElement('div');
      meta.className = 'ctx-meta';
      const line1 = `<b>${escapeHtml(it.barcode || '')}</b> · ${escapeHtml(it.label || '')}`;
      const line2 = [it.group || '', it.year ? String(it.year) : ''].filter(Boolean).join(' · ');
      meta.innerHTML = `<div>${line1}</div><div>${escapeHtml(line2)}</div>`;
      card.appendChild(meta);

      card.addEventListener('click', ()=> openViewer(it));
      grid.appendChild(card);
    });
  }

  function applyFilters(){
    const group = (fGroup.value || '').trim();
    const year = parseInt(fYear.value || '0', 10);
    const txt = norm(fText.value);

    const out = items.filter(it => {
      if (group && String(it.group || '').trim() !== group) return false;
      if (year && parseInt(it.year || 0, 10) !== year) return false;
      if (txt) {
        const hay = [it.barcode || '', it.group || '', it.date_iso || '', String(it.year || ''), it.display_title || ''].join(' ');
        if (!norm(hay).includes(txt)) return false;
      }
      return true;
    });

    renderGrid(out);
    stat.textContent = out.length ? (out.length + ' resultados') : '';
  }

  function resetZoom(){
    zoomed = false;
    vimg.classList.remove('zoomed');
    vimgWrap.classList.remove('zoomed');
    vimg.style.transformOrigin = 'center center';
    vimgWrap.scrollTop = 0;
    vimgWrap.scrollLeft = 0;
  }

  function toggleZoom(ev){
    zoomed = !zoomed;
    vimg.classList.toggle('zoomed', zoomed);
    vimgWrap.classList.toggle('zoomed', zoomed);

    if (zoomed && ev) {
      const rect = vimg.getBoundingClientRect();
      const x = ((ev.clientX - rect.left) / rect.width) * 100;
      const y = ((ev.clientY - rect.top) / rect.height) * 100;
      vimg.style.transformOrigin = x + '% ' + y + '%';
    } else {
      vimg.style.transformOrigin = 'center center';
    }
  }

  function setMainImage(url){
    if (!url) return;
    resetZoom();
    vimg.src = url;
    Array.from(thumbGrid.querySelectorAll('img.ctx-timg')).forEach(n => {
      n.classList.toggle('on', (n.dataset.url || '') === url);
    });
  }

  function openViewer(it){
    const barcode = (it.barcode || '').trim();
    const imgs = envImgs[barcode] || [];
    const labelsInExpo = pieceLabelsByBarcode[barcode] || {};

    let head = `Sobre <b>${escapeHtml(barcode)}</b>`;
    if (it.date_iso) head += ` · Fecha <b>${escapeHtml(it.date_iso)}</b>`;
    if (it.group) head += ` · <b>${escapeHtml(it.group)}</b>`;
    vinfo.innerHTML = head;

    const mainUrl = it.url || '';
    setMainImage(mainUrl);
    vsideCount.textContent = imgs.length ? (imgs.length + ' imgs') : '';

    thumbGrid.innerHTML = '';
    imgs.forEach(x => {
      const im = document.createElement('img');
      im.className = 'ctx-timg';
      im.loading = 'lazy';
      im.src = x.url || '';
      im.alt = barcode + '_' + (x.label || '');
      im.dataset.url = x.url || '';

      if ((x.url || '') === mainUrl) im.classList.add('on');
      if (labelsInExpo[x.label]) im.classList.add('inexpo');
      else im.classList.add('notexpo');

      im.addEventListener('click', ()=> setMainImage(x.url || ''));
      im.addEventListener('dblclick', ()=> window.open(x.url || '', '_blank', 'noopener'));
      thumbGrid.appendChild(im);
    });

    modal.classList.add('on');
  }

  fGroup.addEventListener('change', applyFilters);
  fYear.addEventListener('change', applyFilters);
  fText.addEventListener('input', () => {
    window.clearTimeout(window.__ctxT);
    window.__ctxT = window.setTimeout(applyFilters, 120);
  });

  closeBtn.addEventListener('click', ()=> { modal.classList.remove('on'); resetZoom(); });
  modal.addEventListener('click', (e)=> { if (e.target === modal) { modal.classList.remove('on'); resetZoom(); }});
  document.addEventListener('keydown', (e)=> {
    if (e.key === 'Escape') { modal.classList.remove('on'); resetZoom(); }
  });

  vimgWrap.addEventListener('click', toggleZoom);
  vimg.addEventListener('dblclick', ()=> window.open(vimg.src || '', '_blank', 'noopener'));

  applyFilters();
})();
</script>
</body>
</html>
