// AFDC v1 - theme + utilidades livianas
(function () {
  const KEY = "afdc_theme";
  const THEMES = new Set(["dark", "light", "vintage"]);

  function applyTheme(t) {
    if (!THEMES.has(t)) t = "dark";
    const html = document.documentElement;
    html.classList.remove("theme-dark", "theme-light", "theme-vintage");
    html.classList.add("theme-" + t);
  }

  function getTheme() {
    try {
      return localStorage.getItem(KEY) || "dark";
    } catch (e) {
      return "dark";
    }
  }

  function setTheme(t) {
    if (!THEMES.has(t)) return;
    try {
      localStorage.setItem(KEY, t);
    } catch (e) {}
    applyTheme(t);
  }

  // API global por si querés usarlo después
  window.AFDC = window.AFDC || {};
  window.AFDC.getTheme = getTheme;
  window.AFDC.setTheme = setTheme;

  // 1) aplicar al cargar
  applyTheme(getTheme());

  // 2) clicks en opciones: <button data-theme="light">Claro</button> / <a data-theme="dark">Oscuro</a>
  document.addEventListener("click", function (e) {
    const opt = e.target.closest("[data-theme]");
    if (opt) {
      const t = (opt.getAttribute("data-theme") || "").toLowerCase();
      if (THEMES.has(t)) {
        e.preventDefault();
        setTheme(t);
        closeAllDropdowns(); // <-- esto
      }
    }
  });
// 3) Dropdowns del header (Menú / Temas / Usuarios)
// Estructura esperada:
// <div class="nav__group">
//   <button class="nav__toggle" ...>...</button>
//   <div class="nav__dropdown">...</div>
// </div>
function closeAllDropdowns() {
  document.querySelectorAll('.nav__group.is-open').forEach(g => {
    g.classList.remove('is-open');
    const btn = g.querySelector('.nav__toggle');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  });
}

function openDropdown(group) {
  group.classList.add('is-open');
  const btn = group.querySelector('.nav__toggle');
  if (btn) btn.setAttribute('aria-expanded', 'true');
}

document.addEventListener('click', function (e) {
  const toggle = e.target.closest('.nav__toggle');
  if (toggle) {
    const group = toggle.closest('.nav__group');
    if (!group) return;
    e.preventDefault();

    const wasOpen = group.classList.contains('is-open');
    closeAllDropdowns();
    if (!wasOpen) openDropdown(group);
    return;
  }

  // Click en una opción del dropdown: cerramos (salvo disabled)
  const item = e.target.closest('.nav__drop');
  if (item && !item.classList.contains('is-disabled')) {
    closeAllDropdowns();
    return;
  }

  // Click afuera: cerramos todo
  if (!e.target.closest('.nav__group')) {
    closeAllDropdowns();
  }
});

// cerrar con ESC
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeAllDropdowns();
});

  // 3) dropdown simple (opcional): botón con [data-theme-toggle] que abre/cierra el contenedor
  // Recomendación de estructura:
  // <div class="themeMenu" data-theme-menu>
  //   <button data-theme-toggle>...</button>
  //   <div class="themeMenu__drop">...</div>
  // </div>
  document.addEventListener("click", function (e) {
    const toggle = e.target.closest("[data-theme-toggle]");
    if (!toggle) return;

    const root = toggle.closest("[data-theme-menu]") || toggle.parentElement;
    if (!root) return;

    e.preventDefault();
    root.classList.toggle("open");
  });

  // cerrar dropdown al clickear afuera
  document.addEventListener("click", function (e) {
    const open = document.querySelector("[data-theme-menu].open");
    if (!open) return;
    if (e.target.closest("[data-theme-menu]")) return;
    open.classList.remove("open");
  });

  // cerrar con ESC
  document.addEventListener("keydown", function (e) {
    if (e.key !== "Escape") return;
    const open = document.querySelector("[data-theme-menu].open");
    if (open) open.classList.remove("open");
  });
})();

const EI = {
    pages: [],
    index: 0,
    zoom: 1,
    zoomFit: 1,

    init() {
        this.fecha = document.getElementById('ei-fecha');
        this.ed    = document.getElementById('ei-edicion');
        this.spread = document.getElementById('ei-spread');

        fetch('api/edicionimpresa.php?action=dates')
            .then(r => r.json())
            .then(d => this.dates = d);

        this.fecha.addEventListener('change', () => this.loadEditions());
        this.ed.addEventListener('change', () => this.loadPages());

        document.getElementById('ei-prev').onclick = () => this.move(-2);
        document.getElementById('ei-next').onclick = () => this.move(2);
        document.getElementById('ei-fit').onclick  = () => this.fit();
    },

    loadEditions() {
        fetch(`api/edicionimpresa.php?action=editions&fecha=${this.fecha.value}`)
            .then(r => r.json())
            .then(eds => {
                this.ed.innerHTML = '';
                eds.forEach(e => {
                    const o = document.createElement('option');
                    o.value = e;
                    o.textContent = 'Edición ' + e;
                    this.ed.appendChild(o);
                });
                this.ed.disabled = false;
                this.loadPages();
            });
    },

    loadPages() {
        fetch(`api/edicionimpresa.php?action=pages&fecha=${this.fecha.value}&ed=${this.ed.value}`)
            .then(r => r.json())
            .then(p => {
                this.pages = p;
                this.index = 0;
                this.render();
            });
    },

    render() {
        this.spread.innerHTML = '';

        const left = this.index === 0 ? null : this.pages[this.index - 1];
        const right = this.pages[this.index];

        this.spread.appendChild(this.makePage(left));
        this.spread.appendChild(this.makePage(right));
        this.fit();
    },

    makePage(p) {
        const d = document.createElement('div');
        d.className = 'ei-page';
        if (!p) {
            d.classList.add('blank');
            d.style.width = '400px';
            d.style.height = '560px';
            return d;
        }
        const img = document.createElement('img');
        img.src = AFDC_BAJAS_URL + '/Edicion%20impresa' +
            p.folder.replace(/\\/g,'/') + '/' + p.barcode + '.jpg';
        d.appendChild(img);
        return d;
    },

    move(step) {
        this.index = Math.max(0, Math.min(this.pages.length - 1, this.index + step));
        this.render();
    },

    fit() {
        this.zoom = 1;
        this.spread.style.transform = 'scale(1)';
    }
};

// --- Guard para EI: solo si existe el módulo en la página ---
document.addEventListener('DOMContentLoaded', () => {
  try{
    if (document.getElementById('ei-spread')) EI.init();
  }catch(e){ console.warn('EI init error', e); }
});
