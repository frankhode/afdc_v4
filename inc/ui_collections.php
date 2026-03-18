<?php
declare(strict_types=1);

function ui_collections_css(): void {
    echo '<style>
    .col-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;margin:8px 0 14px;}
    .col-head-left{display:flex;gap:12px;align-items:flex-start;}
    .col-titlewrap{display:flex;flex-direction:column;gap:6px;}
    .col-title{font-size:22px;font-weight:900;letter-spacing:-.01em;}
    .col-sub{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .muted{opacity:.72;font-size:13px;}
    .pill{display:inline-flex;align-items:center;border:1px solid rgba(0,0,0,.12);border-radius:999px;padding:4px 10px;font-weight:800;font-size:12px;background:rgba(255,255,255,.45);}
    html.theme-dark .pill{border-color:rgba(255,255,255,.14);background:rgba(255,255,255,.06);}
    html.theme-vintage .pill{border-color:rgba(55,40,25,.18);background:rgba(252,246,232,.70);}

    .cols-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    @media(max-width: 980px){.cols-2{grid-template-columns:1fr;}}

    .section-title{font-weight:900;margin:8px 0 10px;font-size:14px;opacity:.82;}

    .cards{display:flex;flex-direction:column;gap:10px;}
    .cardRow{display:flex;justify-content:space-between;align-items:center;gap:12px;border:1px solid rgba(0,0,0,.10);border-radius:16px;padding:12px 12px;background:rgba(255,255,255,.45);}
    html.theme-dark .cardRow{border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
    html.theme-vintage .cardRow{border-color:rgba(55,40,25,.18);background:rgba(252,246,232,.70);}

    .cardMain{min-width:0;display:flex;flex-direction:column;gap:4px;}
    .cardTitle{font-weight:900;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .cardDesc{font-size:12px;opacity:.75;max-height:2.6em;overflow:hidden;}
    .cardMeta{font-size:12px;opacity:.70;display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .cardActions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}

    .btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 12px;border-radius:14px;border:1px solid rgba(0,0,0,.10);background:rgba(255,255,255,.55);font-weight:700;cursor:pointer;text-decoration:none;color:inherit;}
    .btn:hover{background:rgba(0,0,0,.06);}
    html.theme-dark .btn{border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:rgba(255,255,255,.92);}
    html.theme-dark .btn:hover{background:rgba(255,255,255,.08);}
    html.theme-vintage .btn{border-color:rgba(55,40,25,.18);background:rgba(252,246,232,.75);color:rgba(43,32,22,.92);}
    html.theme-vintage .btn:hover{background:rgba(55,40,25,.08);}

    .btn-primary{border-color:rgba(37,99,235,.35);background:rgba(37,99,235,.14);}
    html.theme-dark .btn-primary{border-color:rgba(185,199,255,.28);background:rgba(185,199,255,.10);}
    html.theme-vintage .btn-primary{border-color:rgba(95,55,25,.28);background:rgba(95,55,25,.10);}

    .metaBar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0 10px;}
    .pager{display:flex;gap:8px;align-items:center;justify-content:center;margin:14px 0 6px;flex-wrap:wrap;}
    .pager a{ text-decoration:none; }

    .mosaic{display:grid;grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));gap:10px;}
    @media(max-width: 520px){ .mosaic{grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));} }
    .tile{position:relative;border-radius:16px;overflow:hidden;border:1px solid rgba(0,0,0,.10);background:rgba(0,0,0,.12);}
    html.theme-dark .tile{border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.06);}
    html.theme-vintage .tile{border-color:rgba(55,40,25,.18);background:rgba(252,246,232,.60);}
    .tile img{width:100%;height:150px;object-fit:cover;display:block;transform:scale(1.02);}
    .tile .tlabel{position:absolute;left:8px;bottom:8px;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.04em;color:rgba(255,255,255,.92);background:rgba(0,0,0,.55);border:1px solid rgba(255,255,255,.14);}
    .tile .broken{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-weight:900;opacity:.75;}
    </style>';
}

function ui_collection_cards(array $rows, array $opts): void {
    $mode = (string)($opts['mode'] ?? 'public');
    $canCopy = (bool)($opts['can_copy'] ?? false);
    $return = (string)($opts['return'] ?? '');
    $base = (string)($opts['base'] ?? '');
    $csrf = (string)($opts['csrf'] ?? '');

    echo '<div class="cards">';
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $title = (string)($r['title'] ?? '');
        $desc = (string)($r['description'] ?? '');
        $count = (int)($r['count_items'] ?? 0);

        echo '<div class="cardRow">';
        echo '  <div class="cardMain">';
        echo '    <div class="cardTitle">'.h($title).'</div>';
        echo '    <div class="cardMeta"><span class="muted">'.$count.' imgs</span></div>';
        if ($desc !== '') echo '    <div class="cardDesc">'.h($desc).'</div>';
        echo '  </div>';

        echo '  <div class="cardActions">';
        echo '    <a class="btn" href="colecciones.php?id='.$id.'">Ver</a>';

        if ($mode === 'public') {

            if ($canCopy) {
                echo ' <button class="btn btn-primary" type="button" data-copy-public="'.$id.'" data-csrf="'.h($csrf).'">Copiar</button>';
            } else {
                echo ' <a class="btn btn-primary" href="'.$base.'/login.php?return='.urlencode($return).'">Copiar</a>';
            }

        }

        if ($mode === 'my') {

            echo ' <button class="btn"'
                .' data-col-action="rename"'
                .' data-col-id="'.$id.'"'
                .' data-col-title="'.h($title).'"'
                .' data-csrf="'.h($csrf).'">Renombrar</button>';

            echo ' <button class="btn"'
                .' data-col-action="edit"'
                .' data-col-id="'.$id.'"'
                .' data-col-title="'.h($title).'"'
                .' data-col-description="'.h($desc).'"'
                .' data-col-public="0"'
                .' data-csrf="'.h($csrf).'">Editar</button>';

            echo ' <button class="btn"'
                .' data-col-action="clear"'
                .' data-col-id="'.$id.'"'
                .' data-csrf="'.h($csrf).'">Vaciar</button>';

            echo ' <button class="btn"'
                .' data-col-action="delete"'
                .' data-col-id="'.$id.'"'
                .' data-col-title="'.h($title).'"'
                .' data-csrf="'.h($csrf).'">Eliminar</button>';

        }

        echo '  </div>';

        echo '</div>';
    }
    echo '</div>';
}

function ui_mosaic_meta(int $total, int $page, int $totalPages, int $perPage): void {
    echo '<div class="metaBar">';
    echo '  <div class="muted">Total: <strong>'.(int)$total.'</strong> — Página '.(int)$page.' / '.(int)$totalPages.'</div>';
    echo '  <div class="muted">Por página: <strong>'.(int)$perPage.'</strong></div>';
    echo '</div>';
}

function ui_mosaic_grid(array $cards): void {
    echo '<div class="mosaic">';
    foreach ($cards as $c) {
        $thumb = (string)($c['thumb'] ?? '');
        $href  = (string)($c['href'] ?? '#');
        $label = (string)($c['label'] ?? '');
        $ok    = (bool)($c['ok'] ?? false);

        echo '<a class="tile" href="'.h($href).'" title="'.h((string)($c['title'] ?? '')).'">';
        if ($ok && $thumb !== '') {
            echo '<img src="'.h($thumb).'" alt="">';
            if ($label !== '') echo '<span class="tlabel">'.h($label).'</span>';
        } else {
            echo '<div class="broken">No disponible</div>';
        }
        echo '</a>';
    }
    echo '</div>';
}

function ui_mosaic_pager(string $baseHref, int $page, int $totalPages, int $perPage): void {
    if ($totalPages <= 1) return;

    $mk = function(int $p) use ($baseHref, $perPage): string {
        $sep = (strpos($baseHref, '?') !== false) ? '&' : '?';
        return $baseHref . $sep . 'p=' . $p . '&per=' . $perPage;
    };

    echo '<div class="pager">';
    echo '<a class="btn" href="'.h($mk(1)).'">« Primero</a>';
    echo '<a class="btn" href="'.h($mk(max(1, $page-1))).'">‹ Anterior</a>';
    echo '<span class="muted">Página '.(int)$page.' / '.(int)$totalPages.'</span>';
    echo '<a class="btn" href="'.h($mk(min($totalPages, $page+1))).'">Siguiente ›</a>';
    echo '<a class="btn" href="'.h($mk($totalPages)).'">Último »</a>';
    echo '</div>';
}

function ui_collections_copy_js(string $baseUrl): void {
    // usa el endpoint ya existente: POST /api/v2/collections/copy-public (X-CSRF-Token)
    echo '<script>
    (function(){
      const base = '.json_encode(rtrim($baseUrl, "/")).';
      async function ensureMe(){
        const r = await fetch(base + "/api/v2/me", { credentials:"same-origin" });
        const j = await r.json().catch(()=>null);
        return j && j.ok && j.logged_in ? j : null;
      }

      document.addEventListener("click", async (e)=>{
        const btn = e.target.closest("[data-copy-public]");
        if (!btn) return;
        e.preventDefault();

        const pid = Number(btn.getAttribute("data-copy-public")||0);
        if (!pid) return;

        // csrf desde atributo si está, si no lo pedimos a /me (más robusto)
        let csrf = btn.getAttribute("data-csrf") || "";
        if (!csrf){
          const me = await ensureMe();
          if (!me){ window.location.href = base + "/login.php?return=" + encodeURIComponent(location.pathname + location.search); return; }
          csrf = me.csrf_token || "";
        }

        btn.disabled = true;
        const old = btn.textContent;
        btn.textContent = "Copiando…";

        try{
          const r = await fetch(base + "/api/v2/collections/copy-public", {
            method:"POST",
            headers: { "Content-Type":"application/json", "X-CSRF-Token": csrf },
            credentials:"same-origin",
            body: JSON.stringify({ public_collection_id: pid })
          });
          const j = await r.json().catch(()=>null);
          if (!j || !j.ok) throw new Error(j?.error || "Error");
          // vamos directo a la nueva colección
          window.location.href = "colecciones.php?id=" + encodeURIComponent(j.new_collection_id);
        }catch(err){
          btn.disabled = false;
          btn.textContent = old || "Copiar";
          alert("No se pudo copiar: " + (err?.message || ""));
        }
      });
    })();
    </script>';
}
