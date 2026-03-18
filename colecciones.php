<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth_v2.php';
require_once __DIR__ . '/inc/collections_repo.php';
require_once __DIR__ . '/inc/ui_collections.php';

afdc_v2_session_start();
$u = afdc_v2_current_user();
$logged = (bool)$u;
$uid = $logged ? (int)$u['id'] : 0;

$pageTitle = 'Colecciones';
$mainClass = 'container-fluid';

$base = rtrim(BASE_URL, '/');
$return = (string)($_SERVER['REQUEST_URI'] ?? ($base . '/colecciones.php'));

$collectionId = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = (int)($_GET['per'] ?? 60);
if (!in_array($perPage, [25, 50, 60, 100], true)) $perPage = 60;

include __DIR__ . '/inc/header.php';

echo '<section class="card">';

ui_collections_css(); // CSS compartido

if ($collectionId > 0) {
    // ========= MODO VER COLECCIÓN =========
    $col = v2_collection_get_accessible($collectionId, $uid);
    if (!$col) {
        http_response_code(404);
        echo '<div class="error"><strong>No encontrada</strong> o sin permiso.</div>';
        echo '</section>';
        include __DIR__ . '/inc/footer.php';
        exit;
    }

    $isCurated = ((int)($col['is_public'] ?? 0) === 1);
    $isMine = $logged && ((int)($col['created_by_user_id'] ?? 0) === (int)$uid);

    // Total items
    $total = v2_collection_count_items($collectionId);

    // Modo ordenar: sin paginación para ordenar el conjunto completo
    $orderMode = $isMine && isset($_GET['ordenar']) && (string)$_GET['ordenar'] === '1';

    if ($orderMode) {
        $page = 1;
        $perPage = max(1, $total);
        $totalPages = 1;
        $offset = 0;
    } else {
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // items: item_type, item_key, image_key, position
    $items = v2_collection_get_items_page($collectionId, $perPage, $offset);

    // Separar fotos y recortes
    $photoItems = [];
    foreach ($items as $it) {
        $type = (string)($it['item_type'] ?? 'foto');
        $itemKey = trim((string)($it['item_key'] ?? ''));
        $imageKey = trim((string)($it['image_key'] ?? ''));
        $effectiveKey = $itemKey !== '' ? $itemKey : $imageKey;

        if ($type !== 'recorte') {
            $photoItems[] = [
                'image_key' => $effectiveKey,
                'position' => (int)($it['position'] ?? 0),
            ];
        }
    }

    // Resolver solo fotos
    $resolvedPhotos = $photoItems ? v2_resolve_imagekeys_to_digital($photoItems) : [];

    $orderModeUrl = 'colecciones.php?id='.(int)$collectionId.'&ordenar=1';
    $normalModeUrl = 'colecciones.php?id='.(int)$collectionId;
    if (!$orderMode && $page > 1) $normalModeUrl .= '&p='.(int)$page;
    if (!$orderMode && $perPage !== 60) $normalModeUrl .= '&per='.(int)$perPage;

    // Header (volver + badge + copiar si curada)
    echo '<div class="col-head">';
    echo '  <div class="col-head-left">';
    echo '    <a class="btn" href="colecciones.php">← Volver</a>';
    echo '    <div class="col-titlewrap">';
    echo '      <div class="col-title">'.h((string)$col['title']).'</div>';
    echo '      <div class="col-sub">';
    echo '        <span class="pill">'.($isCurated ? 'Curada' : 'Privada').'</span>';
    echo '        <span class="muted">'.(int)$total.' items</span>';
    if ($orderMode) {
        echo '        <span class="pill">Ordenando</span>';
    }
    echo '      </div>';
    if (!empty($col['description'])) {
        echo '  <div class="col-desc">'.nl2br(h((string)$col['description'])).'</div>';
    }
    echo '    </div>';
    echo '  </div>';

    echo '  <div class="col-head-right">';
    if ($isCurated) {
        if ($logged) {
            $csrf = afdc_v2_csrf_token();
            echo '    <button class="btn btn-primary" type="button" data-copy-public="'.(int)$collectionId.'" data-csrf="'.h($csrf).'">Copiar a mis colecciones</button>';
        } else {
            echo '    <a class="btn btn-primary" href="'.$base.'/login.php?return='.urlencode($return).'">Iniciar sesión para copiar</a>';
        }
    }

    if ($isMine) {
        $csrf = afdc_v2_csrf_token();

        if ($orderMode) {
            echo '    <a class="btn btn-primary" href="'.h($normalModeUrl).'">Listo</a>';
        } else {
            echo '    <a class="btn btn-primary" href="'.h($orderModeUrl).'">Ordenar</a>';
        }

        echo '    <button class="btn" type="button"'
            .' data-col-action="rename"'
            .' data-col-id="'.(int)$collectionId.'"'
            .' data-col-title="'.h((string)$col['title']).'"'
            .' data-csrf="'.h($csrf).'">Renombrar</button>';

        echo '    <button class="btn" type="button"'
            .' data-col-action="edit"'
            .' data-col-id="'.(int)$collectionId.'"'
            .' data-col-title="'.h((string)$col['title']).'"'
            .' data-col-description="'.h((string)($col['description'] ?? '')).'"'
            .' data-col-public="'.(int)($col['is_public'] ?? 0).'"'
            .' data-csrf="'.h($csrf).'">Editar</button>';

        echo '    <button class="btn" type="button"'
            .' data-col-action="clear"'
            .' data-col-id="'.(int)$collectionId.'"'
            .' data-csrf="'.h($csrf).'">Vaciar</button>';

        echo '    <button class="btn btn-danger" type="button"'
            .' data-col-action="delete"'
            .' data-col-id="'.(int)$collectionId.'"'
            .' data-col-title="'.h((string)$col['title']).'"'
            .' data-csrf="'.h($csrf).'">Eliminar</button>';
    }
    echo '  </div>';
    echo '</div>';

    echo '<style>
      .collection-toolbar{
        display:flex; align-items:center; justify-content:space-between; gap:16px;
        margin:10px 0 16px;
        flex-wrap:wrap;
      }
      .thumb-size-control{
        display:flex; align-items:center; gap:10px; flex-wrap:wrap;
      }
      .thumb-size-control label{
        font-weight:600;
      }
      .thumb-size-control .mini,
      .thumb-size-control .maxi{
        font-size:12px;
        opacity:.75;
      }
      .thumb-size-control input[type=range]{
        width:180px;
      }
      .order-hint{
        font-size:13px;
        opacity:.8;
      }
      .collection-grid{
        --thumb-card-w: 220px;
        --thumb-media-h: 180px;
        display:grid;
        grid-template-columns: repeat(auto-fill, minmax(var(--thumb-card-w), 1fr));
        gap:14px;
      }
      .collection-card{
        position:relative;
        border-radius:16px;
        overflow:hidden;
        min-width:0;
      }
      .collection-card.is-dragging{
        opacity:.55;
      }
      .collection-card.drag-over{
        outline:2px dashed rgba(128,128,128,.65);
        outline-offset:2px;
      }
      .collection-card-link{
        display:block;
        text-decoration:none;
        color:inherit;
      }
      .collection-card-media{
        position:relative;
        height:var(--thumb-media-h);
        overflow:hidden;
        border-radius:16px;
        background:rgba(0,0,0,.08);
      }
      .collection-card-media img{
        width:100%;
        height:100%;
        object-fit:cover;
        display:block;
      }
      .collection-card-badges{
        position:absolute;
        left:10px;
        right:10px;
        bottom:10px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
        pointer-events:none;
      }
      .collection-card-chip{
        display:inline-flex;
        align-items:center;
        padding:4px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        background:rgba(0,0,0,.72);
        color:#fff;
      }
      .collection-card-chip.light{
        background:rgba(255,255,255,.92);
        color:#111;
      }
      .collection-card-title{
        margin:8px 2px 0;
        font-size:13px;
        line-height:1.25;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
      }
      .collection-drag-handle{
        position:absolute;
        top:10px;
        right:10px;
        z-index:3;
        width:34px;
        height:34px;
        border:none;
        border-radius:10px;
        background:rgba(0,0,0,.58);
        color:#fff;
        font-size:16px;
        cursor:grab;
        display:flex;
        align-items:center;
        justify-content:center;
      }
      .collection-grid[data-order-mode="1"] .collection-card{
        cursor:grab;
      }
      .collection-grid[data-order-mode="1"] .collection-card-link{
        cursor:default;
      }
      .collection-empty{
        padding:18px 0;
        opacity:.75;
      }
      .collection-saving{
        font-size:13px;
        min-height:1.2em;
      }
      .collection-saving.is-error{
        color:#a40000;
      }
      .collection-saving.is-ok{
        color:#0a6b2d;
      }
      @media (max-width: 700px){
        .collection-toolbar{
          align-items:flex-start;
        }
        .thumb-size-control input[type=range]{
          width:140px;
        }
      }
    </style>';

    // Meta pager
    ui_mosaic_meta($total, $page, $totalPages, $perPage);

    echo '<div class="collection-toolbar">';
    echo '  <div class="thumb-size-control">';
    echo '    <label for="thumbSizeRange">Miniaturas</label>';
    echo '    <span class="mini">chicas</span>';
    echo '    <input id="thumbSizeRange" type="range" min="140" max="320" step="10" value="220"';
    echo '      data-storage-key="afdc:collection:thumbsize:'.(int)$collectionId.'">';
    echo '    <span class="maxi">grandes</span>';
    echo '  </div>';
    echo '  <div class="order-hint">';
    if ($orderMode && $isMine) {
        echo 'Arrastrá las tarjetas para definir el orden editorial de la colección.';
    } else if ($isMine) {
        echo 'Podés ajustar el tamaño de miniaturas y usar “Ordenar” para reacomodar la colección.';
    } else {
        echo 'Ajustá el tamaño de miniaturas a gusto.';
    }
    echo '  </div>';
    echo '</div>';

    if (!$items) {
        echo '<div class="collection-empty">Esta colección no tiene items todavía.</div>';
    } else {
        $csrf = afdc_v2_csrf_token();

        echo '<div id="collectionGrid" class="collection-grid"'
            .' data-order-mode="'.($orderMode && $isMine ? '1' : '0').'"'
            .' data-collection-id="'.(int)$collectionId.'"'
            .' data-csrf="'.h($csrf).'"'
            .' data-reorder-url="'.h($base.'/api/collections_manage.php').'">';

        foreach ($items as $it) {
            $type = (string)($it['item_type'] ?? 'foto');
            $itemKey = trim((string)($it['item_key'] ?? ''));
            $imageKey = trim((string)($it['image_key'] ?? ''));
            $key = $itemKey !== '' ? $itemKey : $imageKey;
            $token = $type . ':' . $key;

            $title = $key;
            $thumb = '';
            $href = '#';
            $ok = false;
            $label = '';
            $badge = '';
            $badgeClass = 'collection-card-chip';
            $pos = (int)($it['position'] ?? 0);

            if ($type === 'recorte') {
                $recorteId = (int)$key;

                if ($recorteId > 0) {
                    $thumb = 'api/recorte_render.php?id='.$recorteId.'&modo=crop&maxw=900&q=85';
                    $href  = 'vincular_recorte.php?id='.$recorteId;
                    $title = 'Recorte #'.$recorteId;
                    $label = 'Recorte';
                    $badge = '#'.$recorteId;
                    $badgeClass = 'collection-card-chip light';
                    $ok = true;
                } else {
                    $title = 'Recorte inválido';
                    $label = 'Recorte';
                    $badge = 'Inválido';
                    $ok = false;
                }
            } else {
                $r = $resolvedPhotos[$key] ?? null;
                $title = $key;
                $label = (string)($r['label'] ?? '');
                $badge = $label !== '' ? $label : (string)$key;

                if ($r && !empty($r['url'])) {
                    $thumb = (string)$r['url'];
                    $ok = !empty($r['ok']);
                    $href = 'ver_digital.php?image_key='.urlencode($key)
                          .'&from_collection_id='.(int)$collectionId
                          .'&from_collection_title='.urlencode((string)$col['title']);
                }
            }

            echo '<article class="collection-card"'
                .' draggable="'.($orderMode && $isMine ? 'true' : 'false').'"'
                .' data-item-type="'.h($type).'"'
                .' data-item-key="'.h($key).'"'
                .' data-item-token="'.h($token).'"'
                .' data-position="'.$pos.'">';

            if ($orderMode && $isMine) {
                echo '<button class="collection-drag-handle" type="button" tabindex="-1" aria-hidden="true" title="Arrastrar para ordenar">↕</button>';
            }

            echo '<a class="collection-card-link" href="'.h($href).'"'
                .' title="'.h($title).'"'
                .' '.(($orderMode && $isMine) ? 'data-order-link="1"' : '').'>';

            echo '<div class="collection-card-media">';
            if ($thumb !== '') {
                echo '<img loading="lazy" src="'.h($thumb).'" alt="'.h($title).'">';
            } else {
                echo '<div style="height:100%;display:flex;align-items:center;justify-content:center;opacity:.7;">Sin imagen</div>';
            }

            echo '<div class="collection-card-badges">';
            if ($badge !== '') {
                echo '<span class="'.h($badgeClass).'">'.h($badge).'</span>';
            }
            if ($type === 'recorte') {
                echo '<span class="collection-card-chip">Recorte</span>';
            }
            echo '</div>';
            echo '</div>';

            echo '<div class="collection-card-title">'.h($title).'</div>';
            echo '</a>';
            echo '</article>';
        }

        echo '</div>';
        echo '<div id="collectionSavingState" class="collection-saving" aria-live="polite"></div>';
    }

    if (!$orderMode) {
        ui_mosaic_pager('colecciones.php?id='.(int)$collectionId, $page, $totalPages, $perPage);
    }

    // JS copiar curada
    ui_collections_copy_js($base);
    ?>
<script>
(function(){
  const base = <?= json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  async function postForm(url, data) {
      const fd = new FormData();
      Object.keys(data).forEach(k => fd.append(k, data[k]));

      const r = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      });

      const raw = await r.text();
      let json = null;

      try {
        json = raw ? JSON.parse(raw) : null;
      } catch (e) {
        throw new Error('Respuesta no JSON: ' + raw.slice(0, 500));
      }

      if (!r.ok || !json || !json.ok) {
        const msg = (json && json.error) ? json.error : ('HTTP ' + r.status + ' | ' + raw.slice(0, 300));
        throw new Error(msg);
      }

      return json;
    }

  function initThumbSlider() {
    const input = document.getElementById('thumbSizeRange');
    const grid = document.getElementById('collectionGrid');
    if (!input || !grid) return;

    const storageKey = input.dataset.storageKey || 'afdc:collection:thumbsize';
    const saved = window.localStorage.getItem(storageKey);
    const min = parseInt(input.min || '140', 10);
    const max = parseInt(input.max || '320', 10);

    function clamp(v){
      return Math.max(min, Math.min(max, parseInt(v || '220', 10) || 220));
    }

    function apply(v){
      const size = clamp(v);
      const media = Math.round(size * 0.82);
      grid.style.setProperty('--thumb-card-w', size + 'px');
      grid.style.setProperty('--thumb-media-h', media + 'px');
      input.value = String(size);
      window.localStorage.setItem(storageKey, String(size));
    }

    apply(saved || input.value);

    input.addEventListener('input', function(){
      apply(input.value);
    });
  }

  function initCollectionOrdering() {
    const grid = document.getElementById('collectionGrid');
    const state = document.getElementById('collectionSavingState');
    if (!grid || grid.dataset.orderMode !== '1') return;

    const collectionId = grid.dataset.collectionId || '';
    const csrf = grid.dataset.csrf || '';
    const reorderUrl = grid.dataset.reorderUrl || (base + '/api/collections_manage.php');

    let dragged = null;
    let saving = false;
    let pendingSave = false;

    function setState(msg, cls) {
      if (!state) return;
      state.textContent = msg || '';
      state.classList.remove('is-error', 'is-ok');
      if (cls) state.classList.add(cls);
    }

    function getCards() {
      return Array.from(grid.querySelectorAll('.collection-card'));
    }

    function serializeItems() {
      return getCards().map((card, idx) => ({
        item_type: card.dataset.itemType || 'foto',
        item_key: card.dataset.itemKey || '',
        position: idx + 1
      }));
    }

    async function saveOrder() {
      if (saving) {
        pendingSave = true;
        return;
      }
      saving = true;
      setState('Guardando orden…');

      try {
        await postForm(reorderUrl, {
          action: 'reorder',
          collection_id: collectionId,
          items_json: JSON.stringify(serializeItems()),
          csrf
        });
        setState('Orden guardado.', 'is-ok');
        window.setTimeout(() => {
          if (state && state.textContent === 'Orden guardado.') {
            state.textContent = '';
            state.classList.remove('is-ok');
          }
        }, 1400);
      } catch (err) {
        setState('No se pudo guardar el orden: ' + (err && err.message ? err.message : err), 'is-error');
      } finally {
        saving = false;
        if (pendingSave) {
          pendingSave = false;
          saveOrder();
        }
      }
    }

    grid.addEventListener('click', function(ev){
      if (ev.target.closest('[data-order-link]')) {
        ev.preventDefault();
      }
    });

    grid.addEventListener('dragstart', function(ev){
      const card = ev.target.closest('.collection-card');
      if (!card) return;
      dragged = card;
      card.classList.add('is-dragging');
      if (ev.dataTransfer) {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.dataset.itemToken || '');
      }
      setState('Reordenando… soltá para guardar.');
    });

    grid.addEventListener('dragend', function(ev){
      const card = ev.target.closest('.collection-card');
      if (card) card.classList.remove('is-dragging');
      getCards().forEach(el => el.classList.remove('drag-over'));
      dragged = null;
    });

    grid.addEventListener('dragover', function(ev){
      if (!dragged) return;
      ev.preventDefault();

      const target = ev.target.closest('.collection-card');
      getCards().forEach(el => el.classList.remove('drag-over'));

      if (!target || target === dragged) return;

      target.classList.add('drag-over');

      const rect = target.getBoundingClientRect();
      const before = ev.clientY < rect.top + rect.height / 2;

      if (before) {
        if (target !== dragged.nextElementSibling) {
          grid.insertBefore(dragged, target);
        }
      } else {
        if (target.nextElementSibling !== dragged) {
          grid.insertBefore(dragged, target.nextElementSibling);
        }
      }
    });

    grid.addEventListener('drop', function(ev){
      if (!dragged) return;
      ev.preventDefault();
      getCards().forEach(el => el.classList.remove('drag-over'));
      dragged.classList.remove('is-dragging');
      dragged = null;
      saveOrder();
    });
  }

  document.addEventListener('click', async function(ev){
    const btn = ev.target.closest('[data-col-action]');
    if (!btn) return;

    const action = btn.getAttribute('data-col-action') || '';
    const collectionId = btn.getAttribute('data-col-id') || '';
    const csrf = btn.getAttribute('data-csrf') || '';

    try {
      if (action === 'rename') {
        const currentTitle = btn.getAttribute('data-col-title') || '';
        const title = window.prompt('Nuevo nombre de la colección:', currentTitle);
        if (title === null) return;
        if (!title.trim()) {
          alert('El nombre no puede estar vacío.');
          return;
        }

        await postForm(base + '/api/collections_manage.php', {
          action: 'rename',
          collection_id: collectionId,
          title: title.trim(),
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'edit') {
        const currentTitle = btn.getAttribute('data-col-title') || '';
        const currentDesc = btn.getAttribute('data-col-description') || '';
        const currentPublic = btn.getAttribute('data-col-public') === '1';

        const title = window.prompt('Título de la colección:', currentTitle);
        if (title === null) return;
        if (!title.trim()) {
          alert('El título no puede estar vacío.');
          return;
        }

        const description = window.prompt('Descripción:', currentDesc);
        if (description === null) return;

        const isPublic = window.confirm(
          'Aceptar = pública / Cancelar = privada.\n\nEstado actual: ' + (currentPublic ? 'pública' : 'privada')
        ) ? 1 : 0;

        await postForm(base + '/api/collections_manage.php', {
          action: 'update',
          collection_id: collectionId,
          title: title.trim(),
          description: description,
          is_public: isPublic,
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'clear') {
        if (!window.confirm('¿Vaciar esta colección? Se eliminan sus vínculos, no las fotos ni los recortes originales.')) {
          return;
        }

        await postForm(base + '/api/collections_manage.php', {
          action: 'clear',
          collection_id: collectionId,
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'delete') {
        const title = btn.getAttribute('data-col-title') || '';
        if (!window.confirm('¿Eliminar la colección "' + title + '"?\n\nEsta acción no borra las fotos originales.')) {
          return;
        }

        const json = await postForm(base + '/api/collections_manage.php', {
          action: 'delete',
          collection_id: collectionId,
          csrf
        });

        location.href = json.redirect || (base + '/colecciones.php');
      }
    } catch (err) {
      alert('No se pudo completar la acción: ' + (err && err.message ? err.message : err));
    }
  });

  initThumbSlider();
  initCollectionOrdering();
})();
</script>
<?php

    echo '</section>';
    include __DIR__ . '/inc/footer.php';
    exit;
}

// ========= MODO LISTADO =========
$public = v2_collections_list_public();
$my = $logged ? v2_collections_list_my($uid) : [];

echo '<div class="col-head">';
echo '  <div class="col-head-left">';
echo '    <div class="col-title">Colecciones</div>';
echo '    <div class="col-sub"><span class="muted">Globales (curadas) + Mis colecciones</span></div>';
echo '  </div>';
echo '  <div class="col-head-right">';
if ($logged) {
    echo '    <a class="btn" href="'.$base.'/logout.php?return='.urlencode($return).'">Salir</a>';
} else {
    echo '    <a class="btn btn-primary" href="'.$base.'/login.php?return='.urlencode($return).'">Ingresar</a>';
}
echo '  </div>';
echo '</div>';

echo '<div class="cols-2">';

// Globales
echo '<div>';
echo '<div class="section-title">Colecciones globales (curadas)</div>';
if (!$public) {
    echo '<div class="muted">Todavía no hay colecciones curadas.</div>';
} else {
    ui_collection_cards($public, [
        'mode' => 'public',
        'can_copy' => $logged,
        'return' => $return,
        'base' => $base,
        'csrf' => $logged ? afdc_v2_csrf_token() : '',
    ]);
}
echo '</div>';

// Mis colecciones
echo '<div>';
echo '<div class="section-title">Mis colecciones</div>';
if (!$logged) {
    echo '<div class="muted">Iniciá sesión para ver y gestionar tus colecciones.</div>';
} else if (!$my) {
    echo '<div class="muted">Todavía no tenés colecciones. Podés crear una desde el visor (⋯ → Colecciones…).</div>';
} else {
    ui_collection_cards($my, [
        'mode' => 'my',
        'can_copy' => false,
        'return' => $return,
        'base' => $base,
        'csrf' => $logged ? afdc_v2_csrf_token() : '',
    ]);
}
echo '</div>';

echo '</div>';

ui_collections_copy_js($base);

?>
<script>
(function(){
  const base = <?= json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  async function postForm(url, data) {
      const fd = new FormData();
      Object.keys(data).forEach(k => fd.append(k, data[k]));

      const r = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      });

      const raw = await r.text();
      let json = null;

      try {
        json = raw ? JSON.parse(raw) : null;
      } catch (e) {
        throw new Error('Respuesta no JSON: ' + raw.slice(0, 500));
      }

      if (!r.ok || !json || !json.ok) {
        const msg = (json && json.error) ? json.error : ('HTTP ' + r.status + ' | ' + raw.slice(0, 300));
        throw new Error(msg);
      }

      return json;
    }


  document.addEventListener('click', async function(ev){
    const btn = ev.target.closest('[data-col-action]');
    if (!btn) return;

    const action = btn.getAttribute('data-col-action') || '';
    const collectionId = btn.getAttribute('data-col-id') || '';
    const csrf = btn.getAttribute('data-csrf') || '';

    try {
      if (action === 'rename') {
        const currentTitle = btn.getAttribute('data-col-title') || '';
        const title = window.prompt('Nuevo nombre de la colección:', currentTitle);
        if (title === null) return;
        if (!title.trim()) {
          alert('El nombre no puede estar vacío.');
          return;
        }

        await postForm(base + '/api/collections_manage.php', {
          action: 'rename',
          collection_id: collectionId,
          title: title.trim(),
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'edit') {
        const currentTitle = btn.getAttribute('data-col-title') || '';
        const currentDesc = btn.getAttribute('data-col-description') || '';
        const currentPublic = btn.getAttribute('data-col-public') === '1';

        const title = window.prompt('Título de la colección:', currentTitle);
        if (title === null) return;
        if (!title.trim()) {
          alert('El título no puede estar vacío.');
          return;
        }

        const description = window.prompt('Descripción:', currentDesc);
        if (description === null) return;

        const isPublic = window.confirm(
          'Aceptar = pública / Cancelar = privada.\n\nEstado actual: ' + (currentPublic ? 'pública' : 'privada')
        ) ? 1 : 0;

        await postForm(base + '/api/collections_manage.php', {
          action: 'update',
          collection_id: collectionId,
          title: title.trim(),
          description: description,
          is_public: isPublic,
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'clear') {
        if (!window.confirm('¿Vaciar esta colección? Se eliminan sus vínculos, no las fotos ni los recortes originales.')) {
          return;
        }

        await postForm(base + '/api/collections_manage.php', {
          action: 'clear',
          collection_id: collectionId,
          csrf
        });

        location.reload();
        return;
      }

      if (action === 'delete') {
        const title = btn.getAttribute('data-col-title') || '';
        if (!window.confirm('¿Eliminar la colección "' + title + '"?\n\nEsta acción no borra las fotos originales.')) {
          return;
        }

        const json = await postForm(base + '/api/collections_manage.php', {
          action: 'delete',
          collection_id: collectionId,
          csrf
        });

        location.href = json.redirect || (base + '/colecciones.php');
      }
    } catch (err) {
      alert('No se pudo completar la acción: ' + (err && err.message ? err.message : err));
    }
  });
})();
</script>
<?php

echo '</section>';
include __DIR__ . '/inc/footer.php';