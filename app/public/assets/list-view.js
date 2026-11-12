(() => {
  "use strict";

  // Tabellen-/Kartenumschalter. Die gewählte Darstellung ist eine persönliche
  // Einstellung pro Benutzer und Modul und liegt deshalb in localStorage.
  function create({ root, module, render }) {
    const buttons = [...root.querySelectorAll("[data-list-view]")];
    const mobileDefault = matchMedia("(max-width: 700px)").matches ? "cards" : "table";
    const storageKey = `calight:list-view:${document.body.dataset.userId || "anonymous"}:${module}`;
    let view = mobileDefault;
    try {
      view = localStorage.getItem(storageKey) || mobileDefault;
    } catch {
      // Ohne Speicherzugriff gilt der Gerätestandard.
    }
    if (!buttons.some((button) => button.dataset.listView === view)) view = mobileDefault;

    function apply(next, persist = true) {
      view = next;
      root.dataset.listView = view;
      buttons.forEach((button) => {
        const selected = button.dataset.listView === view;
        button.classList.toggle("is-active", selected);
        button.setAttribute("aria-pressed", String(selected));
      });
      if (persist) {
        try {
          localStorage.setItem(storageKey, view);
        } catch {
          // Darstellung gilt dann nur für diese Seite.
        }
      }
      render(view);
    }

    buttons.forEach((button) => button.addEventListener("click", () => apply(button.dataset.listView)));
    apply(view, false);
    return { get view() { return view; }, render: () => render(view) };
  }

  function enableRowNavigation(container) {
    const open = (event) => {
      const item = event.target.closest("[data-href]");
      if (!item || event.target.closest("a,button,input,select,textarea,details,summary")) return;
      if (event.type === "keydown" && !["Enter", " "].includes(event.key)) return;
      if (event.type === "keydown") event.preventDefault();
      location.href = item.dataset.href;
    };
    container.addEventListener("click", open);
    container.addEventListener("keydown", open);
  }

  // ---------------------------------------------------------------------------
  // Listenstatus <-> URL
  //
  // Die URL ist die maßgebliche Quelle für Suche, Filter, Sortierung und Seite.
  // Standardwerte werden weggelassen, damit kopierte Links kurz bleiben.
  // ---------------------------------------------------------------------------
  const PAGE_SIZES = [10, 25, 50, 100];
  const DEFAULT_PAGE_SIZE = 25;

  function parseState(search, { filterNames, sorts, defaultSort }) {
    const params = new URLSearchParams(search);
    const sort = sorts[params.get("sort")] ? params.get("sort") : defaultSort;
    const dir = ["asc", "desc"].includes(params.get("dir")) ? params.get("dir") : sorts[sort].dir;
    const page = /^\d{1,9}$/.test(params.get("page") || "") ? Math.max(1, Number(params.get("page"))) : 1;
    const perPage = PAGE_SIZES.includes(Number(params.get("per_page"))) ? Number(params.get("per_page")) : DEFAULT_PAGE_SIZE;
    const filters = {};
    for (const name of filterNames) filters[name] = (params.get(name) || "").trim();
    return { q: (params.get("q") || "").trim(), filters, sort, dir, page, perPage };
  }

  function serializeState(state, { filterNames, sorts, defaultSort }) {
    const params = new URLSearchParams();
    if (state.q) params.set("q", state.q);
    for (const name of filterNames) if (state.filters[name]) params.set(name, state.filters[name]);
    if (state.sort !== defaultSort || state.dir !== sorts[state.sort].dir) {
      params.set("sort", state.sort);
      params.set("dir", state.dir);
    }
    if (state.page > 1) params.set("page", String(state.page));
    if (state.perPage !== DEFAULT_PAGE_SIZE) params.set("per_page", String(state.perPage));
    return params.toString();
  }

  // Kompakte Seitenauswahl: erste, letzte und Nachbarn der aktuellen Seite.
  function pageNumbers(page, pageCount) {
    const pages = new Set([1, pageCount, page - 1, page, page + 1]);
    const sorted = [...pages].filter((value) => value >= 1 && value <= pageCount).sort((a, b) => a - b);
    const result = [];
    sorted.forEach((value, index) => {
      if (index > 0 && value - sorted[index - 1] > 1) result.push(null);
      result.push(value);
    });
    return result;
  }

  const DIRECTION_LABELS = {
    text: { asc: "A–Z", desc: "Z–A" },
    date: { desc: "neueste zuerst", asc: "älteste zuerst" },
    number: { desc: "absteigend", asc: "aufsteigend" },
  };
  const otherDirection = (dir) => (dir === "asc" ? "desc" : "asc");
  const numberFormat = new Intl.NumberFormat("de-DE");

  /**
   * Gemeinsame Steuerung einer Übersichtsseite: lädt die aktuelle Seite vom Server
   * und hält Bedienelemente, URL und Browser-History synchron.
   *
   * Modulspezifisch bleiben Endpunkt, Filterfelder, Sortierfelder sowie Tabellen-
   * und Karteninhalt (renderTable/renderCard).
   */
  function createListPage(options) {
    const { root, module, endpoint, results, search, filters = {}, sortSelect, count, reset, message, sorts, defaultSort } = options;
    const config = { filterNames: Object.keys(filters), sorts, defaultSort };
    const escapeHtml = Calight.esc;
    const pagination = document.createElement("nav");
    pagination.className = "list-pagination";
    pagination.setAttribute("aria-label", "Seitennavigation");
    results.after(pagination);

    let state = parseState(location.search, config);
    let data = null;
    let controller = null;
    let searchTimer = null;
    let searchEntryPushed = false;

    // Sortierauswahl aus denselben Definitionen wie die Spaltenköpfe erzeugen,
    // damit beide Bedienwege denselben Zustand anzeigen.
    if (sortSelect) {
      sortSelect.innerHTML = Object.entries(sorts).map(([key, sort]) => {
        const labels = DIRECTION_LABELS[sort.type || "text"];
        return [sort.dir, otherDirection(sort.dir)]
          .map((dir) => `<option value="${key}:${dir}">${escapeHtml(sort.label)} (${labels[dir]})</option>`)
          .join("");
      }).join("");
    }

    const filterValue = (element) => (element.type === "checkbox" ? (element.checked ? "1" : "") : element.value.trim());

    function syncControls() {
      if (search && document.activeElement !== search) search.value = state.q;
      for (const [name, element] of Object.entries(filters)) {
        if (element.type === "checkbox") element.checked = state.filters[name] === "1";
        else element.value = state.filters[name] || "";
        // Unbekannte Werte (z. B. veraltete Links) nicht stillschweigend als Filter behalten.
        if (element.tagName === "SELECT" && element.value !== (state.filters[name] || "")) state.filters[name] = "";
      }
      if (sortSelect) sortSelect.value = `${state.sort}:${state.dir}`;
      if (reset) reset.disabled = isDefault();
    }

    const isDefault = () => !state.q && Object.values(state.filters).every((value) => !value)
      && state.sort === defaultSort && state.dir === sorts[defaultSort].dir;

    function writeUrl(mode) {
      const query = serializeState(state, config);
      const url = `${location.pathname}${query ? `?${query}` : ""}`;
      if (url === `${location.pathname}${location.search}`) return;
      if (mode === "push") history.pushState(null, "", url);
      else history.replaceState(null, "", url);
    }

    // Jede Statusänderung: URL aktualisieren, Bedienelemente angleichen, Seite laden.
    function update(changes, { mode = "push", keepPage = false } = {}) {
      state = { ...state, ...changes, filters: { ...state.filters, ...(changes.filters || {}) } };
      if (!keepPage) state.page = 1;
      if (mode === "push") searchEntryPushed = false;
      writeUrl(mode);
      syncControls();
      load();
    }

    function renderPagination() {
      if (!data || data.total === 0) {
        pagination.hidden = true;
        return;
      }
      pagination.hidden = false;
      const first = (data.page - 1) * data.perPage + 1;
      const last = Math.min(data.total, data.page * data.perPage);
      const button = (page, label, title, disabled = false) => `<button class="icon-button list-page-button" type="button" data-page="${page}" title="${title}" aria-label="${title}"${disabled ? " disabled" : ""}>${label}</button>`;
      const pages = data.pageCount > 1 ? `
        <div class="list-pagination-pages">
          ${button(1, "«", "Erste Seite", data.page === 1)}
          ${button(data.page - 1, "‹", "Vorherige Seite", data.page === 1)}
          ${pageNumbers(data.page, data.pageCount).map((page) => page === null
            ? '<span class="list-page-gap" aria-hidden="true">…</span>'
            : `<button class="list-page-number${page === data.page ? " is-current" : ""}" type="button" data-page="${page}"${page === data.page ? ' aria-current="page"' : ""} aria-label="Seite ${page}">${page}</button>`).join("")}
          ${button(data.page + 1, "›", "Nächste Seite", data.page === data.pageCount)}
          ${button(data.pageCount, "»", "Letzte Seite", data.page === data.pageCount)}
        </div>` : "";
      pagination.innerHTML = `
        <span class="list-pagination-range">${numberFormat.format(first)}–${numberFormat.format(last)} von ${numberFormat.format(data.total)} Einträgen</span>
        ${pages}
        <label class="list-pagination-size">Pro Seite
          <select data-per-page>${PAGE_SIZES.map((size) => `<option value="${size}"${size === data.perPage ? " selected" : ""}>${size}</option>`).join("")}</select>
        </label>`;
    }

    function paint(view) {
      if (!data) return;
      if (!data.items.length) {
        results.innerHTML = `<p class="overview-empty">${escapeHtml(isDefault() ? options.emptyText : options.noMatchText)}</p>`;
      } else if (view === "cards") {
        results.innerHTML = `<div class="overview-card-grid">${data.items.map(options.renderCard).join("")}</div>`;
      } else {
        results.innerHTML = `<div class="overview-table-wrap"><table class="overview-table">${options.renderTable(data.items, header)}</table></div>`;
      }
      renderPagination();
    }

    // Sortierbarer Spaltenkopf; die aktive Spalte zeigt die Richtung per Pfeil und aria-sort.
    function header(key, label, className = "") {
      const classAttribute = className ? ` class="${className}"` : "";
      if (!sorts[key]) return `<th${classAttribute}>${escapeHtml(label)}</th>`;
      const active = state.sort === key;
      const ariaSort = active ? (state.dir === "asc" ? "ascending" : "descending") : "none";
      const next = active ? otherDirection(state.dir) : sorts[key].dir;
      const hint = `Nach ${label} sortieren (${DIRECTION_LABELS[sorts[key].type || "text"][next]})`;
      return `<th${classAttribute} aria-sort="${ariaSort}"><button class="sort-header${active ? " is-active" : ""}" type="button" data-sort-key="${key}" title="${escapeHtml(hint)}">`
        + `<span>${escapeHtml(label)}</span><span class="sort-indicator" aria-hidden="true">${active ? (state.dir === "asc" ? "▲" : "▼") : "↕"}</span></button></th>`;
    }

    const view = create({ root, module, render: paint });
    enableRowNavigation(results);

    async function load() {
      controller?.abort();
      controller = new AbortController();
      const signal = controller.signal;
      results.setAttribute("aria-busy", "true");
      root.classList.add("is-loading");
      if (!data) results.innerHTML = `<p class="overview-empty">${escapeHtml(options.loadingText || "Daten werden geladen …")}</p>`;
      try {
        const params = new URLSearchParams(serializeState(state, config));
        // Der Server erhält Sortierung und Richtung immer explizit.
        params.set("sort", state.sort);
        params.set("dir", state.dir);
        const result = await Calight.api(`${endpoint}?${params}`, { signal });
        if (signal.aborted) return;
        data = result;
        message.hidden = true;
        // Serverseitig korrigierte Werte (z. B. Seite hinter dem Ende) in die URL übernehmen.
        if (result.page !== state.page || result.perPage !== state.perPage || result.sort !== state.sort || result.dir !== state.dir) {
          state = { ...state, page: result.page, perPage: result.perPage, sort: result.sort, dir: result.dir };
          writeUrl("replace");
          syncControls();
        }
        if (count) count.textContent = `${numberFormat.format(result.total)} ${result.total === 1 ? "Eintrag" : "Einträge"}`;
        view.render();
        options.onLoad?.(result);
      } catch (exception) {
        if (exception.name === "AbortError") return;
        Calight.notify(message, exception.message);
        if (!data) results.innerHTML = `<p class="overview-empty">${escapeHtml("Die Liste konnte nicht geladen werden.")}</p>`;
      } finally {
        if (!signal.aborted) {
          results.removeAttribute("aria-busy");
          root.classList.remove("is-loading");
        }
      }
    }

    // Suche: verzögert laden; nur der erste Tastendruck einer Eingabe erzeugt einen
    // History-Eintrag, weitere Eingaben ersetzen ihn.
    search?.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => {
        const q = search.value.trim();
        if (q === state.q) return;
        const mode = searchEntryPushed ? "replace" : "push";
        update({ q }, { mode });
        searchEntryPushed = true;
      }, 300);
    });
    search?.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;
      event.preventDefault();
      clearTimeout(searchTimer);
      if (search.value.trim() !== state.q) update({ q: search.value.trim() }, { mode: searchEntryPushed ? "replace" : "push" });
      searchEntryPushed = false;
    });
    search?.addEventListener("blur", () => { searchEntryPushed = false; });

    for (const [name, element] of Object.entries(filters)) {
      element.addEventListener("change", () => update({ filters: { [name]: filterValue(element) } }));
    }
    sortSelect?.addEventListener("change", () => {
      const [sort, dir] = sortSelect.value.split(":");
      update({ sort, dir });
    });
    reset?.addEventListener("click", () => {
      const cleared = Object.fromEntries(config.filterNames.map((name) => [name, ""]));
      update({ q: "", filters: cleared, sort: defaultSort, dir: sorts[defaultSort].dir });
      search?.focus();
    });

    results.addEventListener("click", (event) => {
      const button = event.target.closest("[data-sort-key]");
      if (!button) return;
      const key = button.dataset.sortKey;
      const dir = state.sort === key ? otherDirection(state.dir) : sorts[key].dir;
      update({ sort: key, dir });
      // Fokus nach dem Neuzeichnen auf demselben Spaltenkopf halten.
      requestAnimationFrame(() => results.querySelector(`[data-sort-key="${key}"]`)?.focus());
    });
    pagination.addEventListener("click", (event) => {
      const button = event.target.closest("[data-page]");
      if (!button || button.disabled) return;
      update({ page: Number(button.dataset.page) }, { keepPage: true });
      root.scrollIntoView({ block: "start", behavior: "smooth" });
    });
    pagination.addEventListener("change", (event) => {
      if (!event.target.matches("[data-per-page]")) return;
      update({ perPage: Number(event.target.value) });
    });

    // Browser-Zurück/Vorwärts stellt den Listenstatus aus der URL wieder her.
    window.addEventListener("popstate", () => {
      clearTimeout(searchTimer);
      searchEntryPushed = false;
      state = parseState(location.search, config);
      if (search) search.value = state.q;
      syncControls();
      load();
    });

    return {
      header,
      /** Erst aufrufen, wenn alle Filteroptionen vorhanden sind. */
      start() {
        syncControls();
        writeUrl("replace");
        load();
      },
      reload: load,
      get items() { return data?.items ?? []; },
    };
  }

  globalThis.CalightListView = Object.freeze({ create, enableRowNavigation, createListPage, parseState, serializeState, pageNumbers });
})();
