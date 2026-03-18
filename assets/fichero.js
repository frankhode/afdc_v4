// assets/fichero.js
(() => {
  const root = document.getElementById("fichero-app");
  if (!root) return;

  const API = window.AFDC_FICHERO_API || "api/fichero.php";

  const el = {
    folder: document.getElementById("fichero-folder"),
    img: document.getElementById("fichero-img"),
    empty: document.getElementById("fichero-empty"),
    status: document.getElementById("fichero-status"),
    hint: document.getElementById("fichero-hint"),
    goto: document.getElementById("fichero-goto"),
    gotoBtn: document.getElementById("fichero-goto-btn"),
    prev: document.getElementById("fichero-prev"),
    next: document.getElementById("fichero-next"),
    prev10: document.getElementById("fichero-prev10"),
    next10: document.getElementById("fichero-next10"),
    toggle: document.getElementById("fichero-toggle"),
  };

  const state = {
    folder: "",
    min: 0,
    max: 0,
    card: 0,
    side: "F", // F | R
    hasBack: false,
    loading: false,
  };

  function setStatus(txt) {
    if (el.status) el.status.textContent = txt;
  }

  function enableControls(on) {
    [el.prev, el.next, el.prev10, el.next10, el.toggle, el.goto, el.gotoBtn].forEach((b) => {
      if (!b) return;
      b.disabled = !on;
    });
  }

  function showEmpty(msg) {
    if (el.empty) {
      el.empty.textContent = msg || "Elegí una carpeta para empezar";
      el.empty.hidden = false;
    }
    if (el.img) {
      el.img.hidden = true;
      el.img.removeAttribute("src");
      // limpiamos handlers para evitar efectos raros
      el.img.onload = null;
      el.img.onerror = null;
      el.img.dataset.token = "";
    }
  }

  function showImg() {
    if (el.empty) el.empty.hidden = true;
    if (el.img) el.img.hidden = false;
  }

  async function jget(url) {
    const r = await fetch(url, { cache: "no-store" });
    const d = await r.json();
    if (!d || d.ok !== true) throw new Error(d && d.error ? d.error : "Error");
    return d;
  }

  function fillSelect(select, items, placeholder) {
    select.innerHTML = "";
    const ph = document.createElement("option");
    ph.value = "";
    ph.textContent = placeholder;
    select.appendChild(ph);

    items.forEach((v) => {
      const o = document.createElement("option");
      o.value = v;
      o.textContent = v;
      select.appendChild(o);
    });
  }

  function clamp(n, a, b) {
    if (b && n > b) return b;
    if (a && n < a) return a;
    return n;
  }

  function updateNavButtons() {
    const can = !!state.folder && state.min > 0 && state.max > 0;
    enableControls(can && !state.loading);

    if (!can) return;

    if (el.prev) el.prev.disabled = state.loading || state.card <= state.min;
    if (el.prev10) el.prev10.disabled = state.loading || state.card <= state.min;
    if (el.next) el.next.disabled = state.loading || state.card >= state.max;
    if (el.next10) el.next10.disabled = state.loading || state.card >= state.max;

    if (el.toggle) {
      el.toggle.textContent = state.side === "F" ? "Frente" : "Reverso";
      el.toggle.disabled = state.loading || !state.hasBack;
    }

    if (el.goto) {
      el.goto.min = String(state.min);
      el.goto.max = String(state.max);
      el.goto.placeholder = `${state.min}…${state.max}`;
    }
  }

  async function loadFoldersAll() {
    try {
      const d = await jget(`${API}?action=folders_all`);
      fillSelect(el.folder, d.folders || [], "Elegí…");
      el.folder.disabled = false;
      setStatus("Elegí una carpeta.");
    } catch (err) {
      console.error(err);
      setStatus("Error: " + (err && err.message ? err.message : err));
      showEmpty("No se pudieron cargar las carpetas");
    }
  }

  async function loadRange(folder) {
    try {
      state.loading = true;
      updateNavButtons();
      setStatus(`"${folder}" (cargando…)`);
      showEmpty("Cargando…");
      if (el.hint) el.hint.textContent = "";

      const d = await jget(`${API}?action=range&folder=${encodeURIComponent(folder)}`);

      state.folder = d.folder;
      state.min = d.minCard || 0;
      state.max = d.maxCard || 0;
      state.card = state.min || 0;
      state.side = "F";
      state.hasBack = false;

      if (!state.min || !state.max) {
        state.loading = false;
        setStatus(`"${folder}" no tiene fichas compatibles.`);
        showEmpty("Esta carpeta no tiene fichas compatibles");
        updateNavButtons();
        return;
      }

      // cargamos la primera ficha
      await loadCard(state.card);
    } catch (err) {
      console.error(err);
      state.loading = false;
      setStatus("Error: " + (err && err.message ? err.message : err));
      showEmpty("Error al cargar la carpeta");
      updateNavButtons();
    }
  }

  async function loadCard(card) {
    if (!state.folder) return;

    card = clamp(card, state.min, state.max);

    state.loading = true;
    updateNavButtons();
    setStatus(`${state.folder} · Ficha ${card} de ${state.max} (cargando…)`);
    showEmpty("Cargando…");
    if (el.hint) el.hint.textContent = "";

    const d = await jget(
      `${API}?action=card&folder=${encodeURIComponent(state.folder)}&card=${encodeURIComponent(card)}`
    );

    state.card = d.card;
    state.min = d.minCard;
    state.max = d.maxCard;
    state.hasBack = !!d.backUrl;

    // si pedimos reverso y no hay, volvemos a frente
    if (state.side === "R" && !d.backUrl) state.side = "F";

    // fallback Java-like: si no hay frente pero hay reverso => mostrar reverso
    let side = state.side;
    let url = side === "R" ? d.backUrl : d.frontUrl;

    if (!d.frontUrl && d.backUrl) {
      side = "R";
      url = d.backUrl;
      if (el.hint) el.hint.textContent = "No hay frente para esta ficha; se muestra reverso.";
    }

    state.side = side;

    if (!url) {
      state.loading = false;
      setStatus(`${state.folder} · Ficha ${state.card} (sin imagen)`);
      showEmpty("Sin imagen para esta ficha");
      updateNavButtons();
      return;
    }

    // Esperamos a que cargue la imagen para “soltar” el estado loading
    const token = `${state.folder}|${state.card}|${state.side}|${Date.now()}`;
    if (el.img) el.img.dataset.token = token;

    el.img.onload = () => {
      if (!el.img || el.img.dataset.token !== token) return;

      state.loading = false;
      const ladoTxt = state.side === "F" ? "frente" : "reverso";
      setStatus(`${state.folder} · Ficha ${state.card} de ${state.max} (${ladoTxt})`);

      showImg();
      updateNavButtons();
    };

    el.img.onerror = () => {
      if (!el.img || el.img.dataset.token !== token) return;

      state.loading = false;
      setStatus(`${state.folder} · Ficha ${state.card} (no se pudo cargar la imagen)`);
      showEmpty("No se pudo cargar la imagen");
      updateNavButtons();
    };

    // Recién acá seteamos src (evita ícono roto)
    el.img.src = url;
  }

  function go(delta) {
    loadCard(state.card + delta).catch((e) => {
      console.error(e);
      state.loading = false;
      setStatus("Error: " + (e && e.message ? e.message : e));
      showEmpty("Error al cargar la ficha");
      updateNavButtons();
    });
  }

  function toggleSide() {
    if (!state.hasBack || state.loading) return;
    state.side = state.side === "F" ? "R" : "F";
    go(0);
  }

  // ==== Eventos UI ====
  el.folder.addEventListener("change", () => {
    const folder = el.folder.value;
    enableControls(false);
    if (!folder) {
      state.folder = "";
      state.min = state.max = state.card = 0;
      state.side = "F";
      state.hasBack = false;
      state.loading = false;
      setStatus("Elegí una carpeta…");
      showEmpty("Elegí una carpeta para empezar");
      return;
    }
    loadRange(folder);
  });

  el.prev.addEventListener("click", () => go(-1));
  el.next.addEventListener("click", () => go(1));
  el.prev10.addEventListener("click", () => go(-10));
  el.next10.addEventListener("click", () => go(10));
  el.toggle.addEventListener("click", () => toggleSide());

  el.gotoBtn.addEventListener("click", () => {
    if (!state.folder || state.loading) return;
    const v = parseInt(el.goto.value || "0", 10);
    if (!v) return;
    loadCard(v).catch((e) => {
      console.error(e);
      state.loading = false;
      setStatus("Error: " + (e && e.message ? e.message : e));
      showEmpty("Error al ir a la ficha");
      updateNavButtons();
    });
  });

  el.goto.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      el.gotoBtn.click();
    }
  });

  // ==== Atajos teclado ====
  document.addEventListener("keydown", (e) => {
    const tag = e.target && e.target.tagName ? e.target.tagName.toLowerCase() : "";
    if (tag === "input" || tag === "textarea" || tag === "select") return;
    if (!state.folder || state.loading) return;

    if (e.key === "ArrowLeft") {
      e.preventDefault();
      go(e.shiftKey ? -10 : -1);
    } else if (e.key === "ArrowRight") {
      e.preventDefault();
      go(e.shiftKey ? 10 : 1);
    } else if (e.key === " ") {
      e.preventDefault();
      toggleSide();
    } else if (e.key === "Home") {
      e.preventDefault();
      loadCard(state.min);
    } else if (e.key === "End") {
      e.preventDefault();
      loadCard(state.max);
    }
  });

  // ==== INIT (estado limpio) ====
  enableControls(false);
  el.folder.disabled = true;
  if (el.hint) el.hint.textContent = "";
  setStatus("Elegí una carpeta…");
  showEmpty("Elegí una carpeta para empezar");

  loadFoldersAll();
})();
