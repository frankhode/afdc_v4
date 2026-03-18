<?php
// tesauro.php (AFDC v1)
// Navegador de tesauro basado en Tesauro.java (JavaFX)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/bootstrap.php';
$mainClass = 'container-fluid';

// -------------------- data: raíces --------------------
// Raíces = términos que son padre (esPadreDe) pero NO figuran como hijo (esHijoDe)
$roots = [];
$rootsErr = null;
try {
    $roots = q(
        "SELECT DISTINCT t.id, t.termino
         FROM terminos t
         JOIN relaciones r ON t.id = r.id1
         WHERE r.relacion = 'esPadreDe'
           AND r.id1 NOT IN (SELECT id1 FROM relaciones WHERE relacion = 'esHijoDe')
         ORDER BY t.termino"
    );
} catch (Throwable $e) {
    $rootsErr = $e->getMessage();
    $roots = [];
}

// -------------------- view --------------------
$pageTitle = 'Tesauro';
include __DIR__ . '/inc/header.php';
?>

<section class="card tes">
  <style>
    .tes h1{ margin:0 0 10px; font-size:22px; }
    .tes .hint{ opacity:.75; font-size:13px; margin:0 0 14px; }

    .tes .layout{ display:grid; grid-template-columns: 280px 1fr; gap:14px; }
    @media (max-width: 900px){ .tes .layout{ grid-template-columns: 1fr; } }

    .tes .roots{
      border:1px solid rgba(255,255,255,.10); border-radius:16px; padding:10px;
      background:rgba(255,255,255,.03);
    }
    .tes .roots h2{ margin:2px 6px 8px; font-size:14px; opacity:.75; }

    .tes .root-btn{
      width:100%; text-align:left; padding:10px 10px; border-radius:12px;
      border:1px solid transparent; background:transparent; color:inherit; cursor:pointer;
    }
    .tes .root-btn:hover{ background:rgba(255,255,255,.06); }
    .tes .root-btn.is-active{ background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.10); }

    .tes .tree{
      border:1px solid rgba(255,255,255,.10); border-radius:16px; padding:12px;
      background:rgba(255,255,255,.03); min-height: 240px;
    }
    .tes .tree-head{
      display:flex; gap:10px; align-items:baseline; justify-content:space-between;
      margin:0 0 10px; flex-wrap:wrap;
    }
    .tes .tree-title{ font-size:16px; margin:0; }
    .tes .tree-meta{ font-size:12px; opacity:.7; }

    /* Buscador global */
    .tes .search{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .tes .search input{
      height:36px; min-width:260px; padding:0 12px;
      border-radius:12px; border:1px solid rgba(255,255,255,.12);
      background:rgba(255,255,255,.04); color:#e8eaf0; outline:none;
    }
    .tes .search small{ opacity:.75; font-size:12px; }

    .tes .sbox{
      margin-top:12px;
      border-top:1px solid rgba(255,255,255,.10);
      padding-top:12px;
    }
    .tes .sbox h3{ margin:0 0 8px; font-size:13px; opacity:.75; }
    .tes .slist{ list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
    .tes .sitem{ margin:0; }
    .tes .sbtn{
      width:100%; text-align:left;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.10);
      background:rgba(255,255,255,.03);
      color:inherit;
      padding:10px 12px;
      cursor:pointer;
    }
    .tes .sbtn:hover{ background:rgba(255,255,255,.06); }
    .tes .crumb{ font-size:13px; }
    .tes .smeta{ margin-top:4px; font-size:12px; opacity:.75; display:flex; gap:10px; align-items:center; }
    .tes .slink{ text-decoration:underline; }

    /* Highlight en el árbol al revelar camino */
    .tes .trow.is-hit{ background:rgba(255,255,255,.10); outline:1px solid rgba(255,255,255,.18); }

    .tes ul.tlist{ list-style:none; padding-left:18px; margin:0; }
    .tes li.titem{ margin:2px 0; }
    .tes .trow{ display:flex; gap:8px; align-items:center; padding:3px 6px; border-radius:10px; }
    .tes .trow:hover{ background:rgba(255,255,255,.05); }
    .tes .twisty{
      width:18px; height:18px; display:inline-grid; place-items:center;
      border-radius:6px; cursor:pointer; user-select:none;
      background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.10);
      font-size:12px;
    }
    .tes .twisty.is-hidden{ opacity:0; pointer-events:none; }
    .tes .term{ cursor:pointer; user-select:none; padding:1px 4px; border-radius:8px; }
    .tes .term.is-clickable:hover{ text-decoration:underline; }
    .tes .term.is-muted{ opacity:.55; cursor:default; }
    .tes .loading{ opacity:.7; font-size:13px; padding:10px 6px; }
    .tes .error{ margin-top:10px; padding:10px; border-radius:12px; background:rgba(255,0,0,.08); border:1px solid rgba(255,0,0,.18); }
  </style>

  <h1>Tesauro</h1>
  <p class="hint">Elegí un tema raíz a la izquierda. Los términos en gris no tienen materias asociadas (no llevan a resultados).</p>

  <?php if ($rootsErr): ?>
    <div class="error">Error leyendo tablas <code>terminos/relaciones</code>: <?= h($rootsErr) ?></div>
  <?php endif; ?>

  <div class="layout" id="tesauroApp"
       data-api-base="<?= h(rtrim(BASE_URL, '/')) ?>/api"
       data-first-root-id="<?= h($roots[0]['id'] ?? '') ?>"
       data-first-root-term="<?= h($roots[0]['termino'] ?? '') ?>">

    <aside class="roots">
      <h2>Raíces</h2>

      <?php if (!$roots): ?>
        <div class="loading">No hay términos raíz para mostrar.</div>
      <?php else: ?>
        <?php foreach ($roots as $i => $r): ?>
          <button
            type="button"
            class="root-btn<?= $i === 0 ? ' is-active' : '' ?>"
            data-root-id="<?= h($r['id']) ?>"
            data-root-term="<?= h($r['termino']) ?>">
            <?= h($r['termino']) ?>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </aside>

    <section class="tree">
      <div class="tree-head">
        <div>
          <h2 class="tree-title" id="tesTitle">—</h2>
          <div class="tree-meta"><span id="tesCount">0</span> términos cargados</div>
        </div>

        <div class="search">
          <input id="tesSearch" type="search" placeholder="Buscar en todo el tesauro…" autocomplete="off" />
          <small id="tesSearchMeta"></small>
        </div>
      </div>

      <div id="tesTree" class="tree-body">
        <div class="loading">Cargando…</div>
      </div>

      <div id="tesSearchBox" class="sbox" hidden>
        <h3>Coincidencias (mostrando camino jerárquico)</h3>
        <ul id="tesSearchList" class="slist"></ul>
      </div>
    </section>
  </div>

  <script defer src="<?= h(rtrim(BASE_URL, '/')) ?>/assets/tesauro.js?v=<?= (int)@filemtime(__DIR__ . '/assets/tesauro.js') ?>"></script>
</section>

<?php include __DIR__ . '/inc/footer.php'; ?>
