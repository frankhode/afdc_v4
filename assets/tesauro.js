// AFDC v1 - Tesauro UI
// - Lista de raíces (server-rendered)
// - Árbol con carga lazy de hijos via /api/tesauro_children.php?id=...
// - Términos 'clicables' llevan a Resultados (tabla) (campo=650&termino=<termino>)
// - Buscador global: busca en todo el tesauro y muestra el camino jerárquico.

(function(){
  function $(sel, root){ return (root || document).querySelector(sel); }
  function $all(sel, root){ return Array.from((root || document).querySelectorAll(sel)); }

  function el(tag, attrs){
    const n = document.createElement(tag);
    if (attrs){
      for (const [k,v] of Object.entries(attrs)){
        if (k === 'class') n.className = v;
        else if (k === 'text') n.textContent = v;
        else n.setAttribute(k, String(v));
      }
    }
    return n;
  }

  async function fetchJson(url){
    const res = await fetch(url, {headers:{'Accept':'application/json'}});
    const data = await res.json().catch(()=>({}));
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  }

  function buildNode(item){
    const li = el('li', {class:'titem', 'data-id': item.id, 'data-loaded':'0'});
    const row = el('div', {class:'trow'});

    const twisty = el('span', {class:'twisty', text:'▸', title:'Expandir'});
    if (!item.has_children){
      twisty.classList.add('is-hidden');
    }

    const term = el('span', {class:'term', text: item.termino});
    if (item.has_materias){
      term.classList.add('is-clickable');
      term.setAttribute('title', 'Ver resultados');
    } else {
      term.classList.add('is-muted');
      term.setAttribute('title', 'Sin materias');
    }

    row.appendChild(twisty);
    row.appendChild(term);

    const ul = el('ul', {class:'tlist'});
    ul.hidden = true;

    li.appendChild(row);
    li.appendChild(ul);
    return {li, twisty, term, ul};
  }

  function setCount(n){
    const c = $('#tesCount');
    if (c) c.textContent = String(n);
  }

  function countNodes(rootUl){
    return rootUl ? rootUl.querySelectorAll('li.titem').length : 0;
  }

  function openResults(term){
    // Resultados (tabla con herramientas)
    const url = 'resultados.php?campo=650&termino=' + encodeURIComponent(term);
    window.open(url, '_blank', 'noopener');
  }

  function debounce(fn, ms){
    let t = null;
    return function(...args){
      if (t) clearTimeout(t);
      t = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  async function loadChildren(apiBase, parentId, ul){
    ul.hidden = false;
    ul.innerHTML = '';
    const loading = el('div', {class:'loading', text:'Cargando…'});
    const liLoad = el('li', {class:'titem'});
    liLoad.appendChild(loading);
    ul.appendChild(liLoad);

    const data = await fetchJson(apiBase + '/tesauro_children.php?id=' + encodeURIComponent(parentId));
    ul.innerHTML = '';

    const items = (data.items || []);
    if (!items.length){
      const liEmpty = el('li', {class:'titem'});
      liEmpty.appendChild(el('div', {class:'loading', text:'(sin hijos)'}));
      ul.appendChild(liEmpty);
      return;
    }

    for (const it of items){
      const node = buildNode(it);
      ul.appendChild(node.li);
    }
  }

  function attachEvents(app){
    const apiBase = app.getAttribute('data-api-base') || '';
    const treeWrap = $('#tesTree');
    const title = $('#tesTitle');

    const searchInput = $('#tesSearch');
    const searchBox = $('#tesSearchBox');
    const searchList = $('#tesSearchList');
    const searchMeta = $('#tesSearchMeta');

    const rootButtons = $all('.root-btn', app);

    async function showRoot(btn){
      rootButtons.forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');

      const rootId = btn.getAttribute('data-root-id');
      const rootTerm = btn.getAttribute('data-root-term') || '—';

      if (title) title.textContent = rootTerm;
      if (!treeWrap) return;

      treeWrap.innerHTML = '';
      const topUl = el('ul', {class:'tlist'});
      treeWrap.appendChild(topUl);

      // Root visible (como TreeItem raíz)
      const rootLi = el('li', {class:'titem', 'data-id': rootId, 'data-loaded':'1'});
      const rootRow = el('div', {class:'trow'});
      const rootTwisty = el('span', {class:'twisty', text:'▾', title:'Contraer/Expandir'});
      const rootTermSpan = el('span', {class:'term', text: rootTerm});
      rootTermSpan.classList.add('term');
      rootTermSpan.classList.add('is-muted'); // evitamos click en root para no asumir materias
      rootRow.appendChild(rootTwisty);
      rootRow.appendChild(rootTermSpan);
      const childUl = el('ul', {class:'tlist'});
      rootLi.appendChild(rootRow);
      rootLi.appendChild(childUl);
      topUl.appendChild(rootLi);

      try {
        await loadChildren(apiBase, rootId, childUl);
        childUl.hidden = false;
        setCount(countNodes(topUl));
      } catch (e){
        treeWrap.innerHTML = '';
        const err = el('div', {class:'error'});
        err.textContent = 'Error cargando tesauro: ' + (e && e.message ? e.message : String(e));
        treeWrap.appendChild(err);
        setCount(0);
      }
    }

    // Click en roots
    rootButtons.forEach(btn => {
      btn.addEventListener('click', () => showRoot(btn));
    });

    // Delegación de eventos en el árbol
    app.addEventListener('click', async (e) => {
      const t = e.target;
      if (!t) return;

      // Click en término
      const termEl = t.closest('.term');
      if (termEl && termEl.classList.contains('is-clickable')){
        openResults(termEl.textContent || '');
        return;
      }

      // Click en twisty (expand/colapsar)
      const twisty = t.closest('.twisty');
      if (!twisty || twisty.classList.contains('is-hidden')) return;

      const li = twisty.closest('li.titem');
      if (!li) return;

      const ul = li.querySelector('ul.tlist');
      if (!ul) return;

      const id = li.getAttribute('data-id');
      const loaded = li.getAttribute('data-loaded') === '1';

      // Toggle
      const willOpen = ul.hidden;
      ul.hidden = !willOpen;
      twisty.textContent = willOpen ? '▾' : '▸';

      if (willOpen && !loaded){
        li.setAttribute('data-loaded', '1');
        try {
          await loadChildren(apiBase, id, ul);
          setCount(countNodes($('#tesTree .tlist')));
        } catch (e){
          ul.innerHTML = '';
          const liErr = el('li', {class:'titem'});
          liErr.appendChild(el('div', {class:'error', text: 'Error: ' + (e && e.message ? e.message : String(e))}));
          ul.appendChild(liErr);
        }
      }
    });

    // Load initial
    const first = rootButtons[0];
    if (first) showRoot(first);

    // -------------------- Buscador global --------------------
    function clearHighlights(){
      $all('.trow.is-hit', app).forEach(n => n.classList.remove('is-hit'));
    }

    async function ensureExpanded(li){
      const twisty = li.querySelector('.twisty');
      const ul = li.querySelector('ul.tlist');
      if (!twisty || !ul) return;
      const id = li.getAttribute('data-id');
      const loaded = li.getAttribute('data-loaded') === '1';

      // abrir
      if (ul.hidden){
        ul.hidden = false;
        if (!twisty.classList.contains('is-hidden')) twisty.textContent = '▾';
      }
      if (!loaded){
        li.setAttribute('data-loaded', '1');
        await loadChildren(apiBase, id, ul);
      }
    }

    async function revealPath(pathIds){
      if (!Array.isArray(pathIds) || pathIds.length === 0) return;
      const rootId = String(pathIds[0]);
      const rootBtn = rootButtons.find(b => (b.getAttribute('data-root-id') === rootId));
      if (!rootBtn) return;
      await showRoot(rootBtn);

      // Expandir de root a hoja
      for (let i = 0; i < pathIds.length; i++){
        const id = String(pathIds[i]);
        const li = app.querySelector('li.titem[data-id="' + CSS.escape(id) + '"]');
        if (!li) continue;
        await ensureExpanded(li);
      }

      // Highlight del último
      clearHighlights();
      const lastId = String(pathIds[pathIds.length - 1]);
      const lastLi = app.querySelector('li.titem[data-id="' + CSS.escape(lastId) + '"]');
      if (lastLi){
        const row = lastLi.querySelector('.trow');
        if (row) row.classList.add('is-hit');
        lastLi.scrollIntoView({behavior:'smooth', block:'center'});
      }
      setCount(countNodes($('#tesTree .tlist')));
    }

    function renderSearch(items, qstr){
      if (!searchList || !searchBox) return;
      searchList.innerHTML = '';
      if (!qstr){
        searchBox.hidden = true;
        if (searchMeta) searchMeta.textContent = '';
        return;
      }
      searchBox.hidden = false;
      if (searchMeta) searchMeta.textContent = items.length ? (items.length + ' coincidencias') : 'Sin coincidencias';

      for (const it of items){
        const li = el('li', {class:'sitem'});
        const btn = el('button', {type:'button', class:'sbtn'});

        const crumb = el('div', {class:'crumb', text: (it.path_text || it.termino || '')});
        const meta = el('div', {class:'smeta'});
        if (it.has_materias){
          const a = el('a', {class:'slink', href: 'resultados.php?campo=650&termino=' + encodeURIComponent(it.termino || '')});
          a.textContent = 'Ver resultados';
          meta.appendChild(a);
        } else {
          meta.textContent = 'Sin materias';
        }

        btn.appendChild(crumb);
        btn.appendChild(meta);
        btn.addEventListener('click', () => revealPath(it.path_ids || []));

        li.appendChild(btn);
        searchList.appendChild(li);
      }
    }

    async function doSearch(qstr){
      if (!searchInput || !searchList) return;
      const qtrim = (qstr || '').trim();
      if (qtrim.length < 2){
        renderSearch([], '');
        return;
      }
      try {
        const data = await fetchJson(apiBase + '/tesauro_search.php?q=' + encodeURIComponent(qtrim));
        renderSearch(data.items || [], qtrim);
      } catch(e){
        renderSearch([], qtrim);
        if (searchMeta) searchMeta.textContent = 'Error buscando';
      }
    }

    if (searchInput){
      searchInput.addEventListener('input', debounce((e) => doSearch(e.target.value), 180));
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape'){
          searchInput.value = '';
          renderSearch([], '');
        }
      });
    }
  }

  function init(){
    const app = document.getElementById('tesauroApp');
    if (!app) return;
    attachEvents(app);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
