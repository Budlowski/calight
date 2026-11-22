// Stammdaten-Übersicht: fünf Bereiche mit Suche, Filtern, Tabellen-/Kartenansicht und
// Bearbeitung im Modal. Stammpositionen (mit Kalkulationsbausteinen) haben eine eigene Seite
// (master-data-service.js). Alle Berechnungen und Prüfungen erfolgen serverseitig.
(() => {
  "use strict";

  const root = document.querySelector("#master-data-app");
  if (!root) return;

  const { esc, api, notify } = Calight;
  const state = { categories: [], suppliers: [], costItems: [], services: [], trailingTexts: [] };
  const typeLabels = { material: "Material", labor: "Lohn", external: "Fremdleistung", other: "Sonstige Kosten" };
  const euro = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  // Einkaufspreise und Kostensätze bis 4 Nachkommastellen.
  const costFormat = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 4 });
  const message = root.querySelector("#master-message");
  const showArchived = root.querySelector("#show-archived");
  const importFile = document.querySelector("#master-import-file");
  let importScope = "";

  const money = (value) => euro.format(Number(value || 0));
  const cost = (value) => costFormat.format(Number(value || 0));
  const decimalValue = (value) => String(value ?? "").trim().replace(",", ".");
  const decimalInput = (value, fallback) => String(value ?? fallback).replace(".", ",");
  const visible = (entry) => entry.active || showArchived.checked;
  const matches = (query, ...parts) => !query || parts.filter(Boolean).join(" ").toLocaleLowerCase("de").includes(query);
  const archivedClass = (entry) => (entry.active ? "" : " archived-row");
  const archivedBadge = (entry) => (entry.active ? "" : '<span class="status-badge status-archived">Archiviert</span>');
  const success = (text) => notify(message, text, "success");
  const failure = (text) => notify(message, text);

  async function load() {
    try {
      Object.assign(state, await api("/api/master-data"));
      renderAll();
    } catch (exception) {
      failure(exception.message);
    }
  }

  /** Speichern/Statuswechsel: Server prüft, danach Bestand neu laden. */
  async function mutate(path, body, text, method = "POST") {
    const result = await api(path, { method, body });
    success(text);
    await load();
    return result;
  }

  // ---------------------------------------------------------------------------
  // Hilfen für Kategorien und Lieferanten
  // ---------------------------------------------------------------------------
  function flatCategories(excluded = new Set()) {
    const byParent = new Map();
    state.categories.filter(visible).forEach((category) => {
      const key = category.parentId || "";
      if (!byParent.has(key)) byParent.set(key, []);
      byParent.get(key).push(category);
    });
    const result = [];
    const visit = (parentId, depth, seen) => {
      (byParent.get(parentId) || []).sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name, "de")).forEach((category) => {
        if (seen.has(category.id)) return;
        if (!excluded.has(category.id)) result.push({ ...category, depth });
        visit(category.id, depth + 1, new Set(seen).add(category.id));
      });
    };
    visit("", 0, new Set());
    return result;
  }

  function descendantsOf(categoryId) {
    const result = new Set([categoryId]);
    let changed = true;
    while (changed) {
      changed = false;
      state.categories.forEach((category) => {
        if (category.parentId && result.has(category.parentId) && !result.has(category.id)) {
          result.add(category.id);
          changed = true;
        }
      });
    }
    return result;
  }

  const categoryOptions = (selected = "", excluded = new Set(), empty = "Keine Kategorie") => `<option value="">${esc(empty)}</option>`
    + flatCategories(excluded).map((category) => `<option value="${esc(category.id)}"${category.id === selected ? " selected" : ""}>${"&nbsp;".repeat(category.depth * 4)}${esc(category.name)}${category.active ? "" : " (archiviert)"}</option>`).join("");
  const supplierOptions = (selected = "") => '<option value="">Kein Lieferant</option>'
    + state.suppliers.filter((supplier) => supplier.active || supplier.id === selected)
      .map((supplier) => `<option value="${esc(supplier.id)}"${supplier.id === selected ? " selected" : ""}>${esc(supplier.supplierNumber ? `${supplier.supplierNumber} · ${supplier.name}` : supplier.name)}</option>`).join("");
  const supplierPlace = (item) => [[item.street, item.houseNumber].filter(Boolean).join(" "), [item.postalCode, item.city].filter(Boolean).join(" ")].filter(Boolean).join(", ");

  // ---------------------------------------------------------------------------
  // Bereiche: Filter, Tabelle, Karte, Zeilenaktionen
  // ---------------------------------------------------------------------------
  const actionButtons = (kind, item, { copy = false, remove = false } = {}) => [
    `<button class="icon-button" type="button" data-action="edit" data-kind="${kind}" data-id="${esc(item.id)}" data-icon="square-pen" title="Bearbeiten" aria-label="Bearbeiten"></button>`,
    copy ? `<button class="icon-button" type="button" data-action="copy" data-kind="${kind}" data-id="${esc(item.id)}" data-icon="copy" title="Kopieren" aria-label="Kopieren"></button>` : "",
    remove
      ? `<button class="icon-button icon-button-danger" type="button" data-action="delete" data-kind="${kind}" data-id="${esc(item.id)}" data-icon="trash" title="Löschen" aria-label="Löschen"></button>`
      : `<button class="icon-button${item.active ? " icon-button-danger" : ""}" type="button" data-action="archive" data-kind="${kind}" data-id="${esc(item.id)}" data-icon="${item.active ? "package" : "refresh-cw"}" title="${item.active ? "Archivieren" : "Reaktivieren"}" aria-label="${item.active ? "Archivieren" : "Reaktivieren"}"></button>`,
  ].join("");

  const table = (head, rows) => `<div class="overview-table-wrap"><table class="overview-table master-table"><thead><tr>${head}<th class="cell-actions"><span class="visually-hidden">Aktionen</span></th></tr></thead><tbody>${rows}</tbody></table></div>`;
  const row = (kind, item, cells, options) => `<tr tabindex="0" data-open="${kind}" data-id="${esc(item.id)}" class="${archivedClass(item).trim()}">${cells}<td class="cell-actions">${actionButtons(kind, item, options)}</td></tr>`;
  const card = (kind, item, { number, title, meta, badges = "" }, options) => `
    <article class="overview-card${archivedClass(item)}" tabindex="0" data-open="${kind}" data-id="${esc(item.id)}">
      <div class="management-card-head"><span class="service-number">${esc(number || "Ohne Nr.")}</span><span class="management-card-badges">${badges}${archivedBadge(item)}</span></div>
      <div class="overview-card-title" title="${esc(title)}">${esc(title)}</div>
      <div class="overview-card-meta">${meta.filter(Boolean).map((part) => `<span>${part}</span>`).join("")}</div>
      <div class="overview-card-actions">${actionButtons(kind, item, options)}</div>
    </article>`;

  const sections = {
    "cost-items": {
      kind: "cost-item",
      empty: "Keine Stammartikel gefunden.",
      items(panel, query) {
        const category = panel.querySelector('[data-filter="category"]').value;
        const allowed = category ? descendantsOf(category) : null;
        const type = panel.querySelector('[data-filter="type"]').value;
        return state.costItems.filter(visible).filter((item) => matches(query, item.articleNumber, item.name, item.description, item.supplierArticleNumber)
          && (!allowed || allowed.has(item.categoryId)) && (!type || item.calculationType === type));
      },
      table: (items) => table(
        '<th class="cell-number">Artikel-Nr.</th><th class="cell-title master-cell-wide">Bezeichnung</th><th class="cell-text">Kategorie</th><th class="cell-type">Typ</th><th class="cell-type">Einheit</th><th class="cell-money">EK / Kostensatz</th><th class="cell-money">VK netto</th>',
        items.map((item) => row("cost-item", item, `<td class="cell-number">${esc(item.articleNumber || "–")}</td><td class="cell-title master-cell-wide" title="${esc(item.name)}">${esc(item.name)}</td>
          <td class="cell-text" title="${esc(item.categoryName || "")}">${esc(item.categoryName || "–")}</td><td class="cell-type">${esc(typeLabels[item.calculationType] || "")}</td><td class="cell-type">${esc(item.unit)}</td>
          <td class="cell-money">${cost(item.costPrice)}</td><td class="cell-money">${money(item.salesPrice)}</td>`, { copy: true })).join(""),
      ),
      card: (item) => card("cost-item", item, {
        number: item.articleNumber, title: item.name, badges: `<span class="status-badge">${esc(typeLabels[item.calculationType] || "")}</span>`,
        meta: [esc(item.categoryName || "Keine Kategorie"), `EK ${cost(item.costPrice)} / ${esc(item.unit)}`, `VK ${money(item.salesPrice)}`],
      }, { copy: true }),
    },
    services: {
      kind: "service",
      empty: "Keine Stammpositionen gefunden.",
      items: (panel, query) => state.services.filter(visible).filter((item) => matches(query, item.articleNumber, item.shortText, item.longText)),
      table: (items) => table(
        '<th class="cell-number">Positions-Nr.</th><th class="cell-title master-cell-wide">Kurztext</th><th class="cell-type">Einheit</th><th class="cell-count">Bausteine</th><th class="cell-money">VK netto</th>',
        items.map((item) => row("service", item, `<td class="cell-number">${esc(item.articleNumber || "–")}</td><td class="cell-title master-cell-wide" title="${esc(item.shortText)}">${esc(item.shortText)}</td>
          <td class="cell-type">${esc(item.unit)}</td><td class="cell-count">${item.components.length}</td><td class="cell-money">${money(item.calculation.calculatedUnitPrice)}</td>`, { copy: true })).join(""),
      ),
      card: (item) => card("service", item, {
        number: item.articleNumber, title: item.shortText,
        meta: [`je ${esc(item.unit)}`, `${item.components.length} ${item.components.length === 1 ? "Baustein" : "Bausteine"}`, `VK ${money(item.calculation.calculatedUnitPrice)}`],
      }, { copy: true }),
    },
    "trailing-texts": {
      kind: "trailing-text",
      empty: "Keine Schlepptexte vorhanden.",
      // Schlepptexte haben keinen Archivstatus; sie werden gelöscht.
      items: (panel, query) => state.trailingTexts.map((item) => ({ ...item, active: true })).filter((item) => matches(query, item.code, item.name)),
      table: (items) => table(
        '<th class="cell-number">Kürzel</th><th class="cell-title master-cell-wide">Ausgabetext</th>',
        items.map((item) => row("trailing-text", item, `<td class="cell-number">${esc(item.code)}</td><td class="cell-title master-cell-wide master-cell-plain" title="${esc(item.name)}">${esc(item.name)}</td>`, { remove: true })).join(""),
      ),
      card: (item) => card("trailing-text", item, { number: item.code, title: item.name, meta: [] }, { remove: true }),
    },
    categories: {
      kind: "category",
      empty: "Keine Kategorien gefunden.",
      items: (panel, query) => flatCategories().filter((item) => matches(query, item.name, item.description)),
      // Hierarchie über Einrückung; Reihenfolge wie im Baum.
      table: (items) => table(
        '<th class="cell-title master-cell-wide">Kategorie</th><th class="cell-text master-cell-wide">Beschreibung</th><th class="cell-count">Sortierung</th>',
        items.map((item) => row("category", item, `<td class="cell-title master-cell-wide" title="${esc(item.name)}"><span class="master-category-indent" style="--depth:${item.depth}">${item.depth ? "↳ " : ""}${esc(item.name)}</span></td>
          <td class="cell-text master-cell-wide" title="${esc(item.description)}">${esc(item.description || "–")}</td><td class="cell-count">${item.sortOrder}</td>`)).join(""),
      ),
      card: (item) => {
        const parent = state.categories.find((category) => category.id === item.parentId);
        return card("category", item, { number: parent ? `Unter ${parent.name}` : "Oberste Ebene", title: item.name, meta: [esc(item.description || "")] });
      },
    },
    suppliers: {
      kind: "supplier",
      empty: "Keine Lieferanten gefunden.",
      items: (panel, query) => state.suppliers.filter(visible).filter((item) => matches(query, item.supplierNumber, item.name, item.contact, supplierPlace(item), item.customerNumber)),
      table: (items) => table(
        '<th class="cell-number">Nummer</th><th class="cell-title master-cell-wide">Name</th><th class="cell-text master-cell-wide">Anschrift</th><th class="cell-text">Unsere Kd.-Nr.</th><th class="cell-text">Kontakt</th>',
        items.map((item) => row("supplier", item, `<td class="cell-number">${esc(item.supplierNumber || "–")}</td><td class="cell-title master-cell-wide" title="${esc(item.name)}">${esc(item.name)}</td>
          <td class="cell-text master-cell-wide" title="${esc(supplierPlace(item))}">${esc(supplierPlace(item) || "–")}</td><td class="cell-text">${esc(item.customerNumber || "–")}</td>
          <td class="cell-text" title="${esc(item.contact)}">${esc(item.contact || "–")}</td>`)).join(""),
      ),
      card: (item) => card("supplier", item, {
        number: item.supplierNumber, title: item.name,
        meta: [esc(supplierPlace(item) || "Keine Anschrift"), item.customerNumber ? `Kd.-Nr. ${esc(item.customerNumber)}` : "", esc(item.contact || "")],
      }),
    },
  };

  const views = {};
  for (const [scope, section] of Object.entries(sections)) {
    const panel = root.querySelector(`[data-panel="${scope}"]`);
    const list = panel.querySelector("[data-list]");
    const count = panel.querySelector("[data-count]");
    const render = (view) => {
      const query = panel.querySelector("[data-search]").value.trim().toLocaleLowerCase("de");
      const items = section.items(panel, query);
      count.textContent = `${items.length} ${items.length === 1 ? "Eintrag" : "Einträge"}`;
      if (!items.length) {
        list.innerHTML = `<p class="empty-state">${esc(section.empty)}</p>`;
      } else {
        list.innerHTML = view === "cards" ? `<div class="overview-card-grid">${items.map(section.card).join("")}</div>` : section.table(items);
      }
    };
    views[scope] = CalightListView.create({ root: panel, module: `master-data-${scope}`, render });
    panel.querySelector("[data-search]").addEventListener("input", () => views[scope].render());
    panel.querySelectorAll("[data-filter]").forEach((filter) => filter.addEventListener("change", () => views[scope].render()));
    panel.querySelector("[data-new]").addEventListener("click", () => editors[section.kind](null));
  }

  function renderAll() {
    const categoryFilter = root.querySelector('[data-panel="cost-items"] [data-filter="category"]');
    const selected = categoryFilter.value;
    categoryFilter.innerHTML = categoryOptions(selected, new Set(), "Alle Kategorien");
    Object.values(views).forEach((view) => view.render());
  }
  showArchived.addEventListener("change", renderAll);

  // Bereiche über die gemeinsame Verwaltungsnavigation (partials/admin-nav.php); der gewählte
  // Bereich steht in der URL (#suppliers), damit Direktlinks und Zurück funktionieren.
  const tabs = [...root.querySelectorAll("[data-tab]")];
  function selectTab(scope, push = false) {
    if (!sections[scope]) scope = "cost-items";
    tabs.forEach((tab) => {
      if (tab.dataset.tab === scope) tab.setAttribute("aria-current", "page");
      else tab.removeAttribute("aria-current");
    });
    const current = tabs.find((tab) => tab.dataset.tab === scope);
    const label = root.querySelector("[data-admin-current]");
    if (label && current) label.textContent = current.textContent;
    root.querySelector(".admin-nav-compact")?.removeAttribute("open");
    root.querySelectorAll(".tab-panel").forEach((panel) => {
      panel.hidden = panel.dataset.panel !== scope;
      panel.classList.toggle("active", !panel.hidden);
    });
    if (push) history.replaceState(null, "", `#${scope}`);
  }
  tabs.forEach((tab) => tab.addEventListener("click", (event) => {
    event.preventDefault();
    selectTab(tab.dataset.tab, true);
  }));
  addEventListener("hashchange", () => selectTab(location.hash.slice(1)));
  selectTab(location.hash.slice(1));

  // ---------------------------------------------------------------------------
  // Bearbeiten im Modal
  // ---------------------------------------------------------------------------
  const field = (label, control, className = "") => `<label${className ? ` class="${className}"` : ""}><span class="field-label">${label}</span>${control}</label>`;
  const required = (label) => `${label} <span aria-hidden="true">*</span>`;
  const dialogForm = (html) => {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `${html}<p class="field-error" data-dialog-error hidden></p>`;
    return form;
  };
  const value = (form, name) => form.elements[name].value.trim();
  const editDialog = ({ title, form, isNew, save, initialFocus }) => Calight.createDialog({
    title, form, initialFocus, submitLabel: isNew ? "Anlegen" : "Speichern", discardMessage: "Ungespeicherte Änderungen verwerfen?", submit: save,
  });

  const editors = {
    supplier(item) {
      const v = (key) => esc(item?.[key] || "");
      const form = dialogForm(`<div class="dialog-form-grid">
          ${field("Lieferantennummer (optional)", `<input name="supplierNumber" maxlength="80" value="${v("supplierNumber")}" autocomplete="off">`)}
          ${field(required("Name"), `<input name="name" maxlength="200" required value="${v("name")}" autocomplete="off">`)}
          ${field("Straße", `<input name="street" maxlength="200" value="${v("street")}">`)}
          ${field("Hausnummer", `<input name="houseNumber" maxlength="30" value="${v("houseNumber")}">`)}
          ${field("PLZ", `<input name="postalCode" maxlength="20" value="${v("postalCode")}">`)}
          ${field("Ort", `<input name="city" maxlength="120" value="${v("city")}">`)}
          ${field("Land", `<input name="country" maxlength="100" value="${v("country")}">`)}
          ${field("Unsere Kundennummer beim Lieferanten", `<input name="customerNumber" maxlength="100" value="${v("customerNumber")}">`)}
          ${field("Kontakt und Bemerkungen", `<textarea name="contact" rows="3" maxlength="2000">${v("contact")}</textarea>`, "span-all")}
        </div>`);
      editDialog({
        title: item ? "Lieferant bearbeiten" : "Lieferant anlegen", form, isNew: !item, initialFocus: '[name="name"]',
        save: async () => {
          const body = Object.fromEntries(["street", "houseNumber", "postalCode", "city", "country", "customerNumber", "contact", "name"].map((key) => [key, value(form, key)]));
          body.supplierNumber = value(form, "supplierNumber") || null;
          await mutate(item ? `/api/master-data/suppliers/${encodeURIComponent(item.id)}` : "/api/master-data/suppliers", body, item ? "Lieferant gespeichert." : "Lieferant angelegt.", item ? "PUT" : "POST");
        },
      });
    },

    category(item) {
      const excluded = item ? descendantsOf(item.id) : new Set();
      const form = dialogForm(`<div class="dialog-form-grid">
          ${field(required("Bezeichnung"), `<input name="name" maxlength="150" required value="${esc(item?.name || "")}">`, "span-all")}
          ${field("Übergeordnete Kategorie", `<select name="parentId">${categoryOptions(item?.parentId || "", excluded, "Oberste Ebene")}</select>`)}
          ${field(required("Sortierung"), `<input name="sortOrder" type="number" min="0" step="1" required value="${esc(item?.sortOrder ?? 0)}">`)}
          ${field("Beschreibung", `<textarea name="description" rows="3" maxlength="2000">${esc(item?.description || "")}</textarea>`, "span-all")}
        </div>`);
      editDialog({
        title: item ? "Kategorie bearbeiten" : "Kategorie anlegen", form, isNew: !item, initialFocus: '[name="name"]',
        save: async () => {
          await mutate(item ? `/api/master-data/categories/${encodeURIComponent(item.id)}` : "/api/master-data/categories", {
            name: value(form, "name"), description: value(form, "description"), parentId: form.elements.parentId.value || null, sortOrder: Number(form.elements.sortOrder.value),
          }, item ? "Kategorie gespeichert." : "Kategorie angelegt.", item ? "PUT" : "POST");
        },
      });
    },

    "trailing-text"(item) {
      const form = dialogForm(`
        ${field(required("Kürzel"), `<input name="code" maxlength="30" required value="${esc(item?.code || "")}" autocomplete="off">`)}
        ${field(required("Bezeichnung und Ausgabetext"), `<textarea name="name" rows="3" maxlength="500" required>${esc(item?.name || "")}</textarea>`)}`);
      editDialog({
        title: item ? "Schlepptext bearbeiten" : "Schlepptext anlegen", form, isNew: !item, initialFocus: '[name="code"]',
        save: async () => {
          await mutate(item ? `/api/master-data/trailing-texts/${encodeURIComponent(item.id)}` : "/api/master-data/trailing-texts",
            { code: value(form, "code"), name: value(form, "name") }, item ? "Schlepptext gespeichert." : "Schlepptext angelegt.", item ? "PUT" : "POST");
        },
      });
    },

    "cost-item"(item) {
      const form = dialogForm(`<div class="dialog-form-grid">
          ${field("Artikelnummer (optional)", `<input name="articleNumber" maxlength="100" value="${esc(item?.articleNumber || "")}" autocomplete="off">`)}
          ${field(required("Bezeichnung"), `<input name="name" maxlength="250" required value="${esc(item?.name || "")}">`)}
          ${field(required("Kalkulationstyp"), `<select name="calculationType" required>${Object.entries(typeLabels).map(([key, label]) => `<option value="${key}"${(item?.calculationType || "material") === key ? " selected" : ""}>${label}</option>`).join("")}</select>`)}
          ${field(required("Einheit"), `<input name="unit" maxlength="40" required placeholder="Stk., h, m …" value="${esc(item?.unit || "")}">`)}
          ${field("Kategorie", `<select name="categoryId">${categoryOptions(item?.categoryId || "")}</select>`)}
          ${field("Lieferant", `<select name="supplierId">${supplierOptions(item?.supplierId || "")}</select>`)}
          ${field("Lieferantenartikelnummer", `<input name="supplierArticleNumber" maxlength="100" value="${esc(item?.supplierArticleNumber || "")}">`)}
          <div></div>
          ${field(required("Netto-Einkaufspreis / Kostensatz"), `<input name="costPrice" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?" required value="${esc(decimalInput(item?.costPrice, "0.0000"))}">`)}
          ${field(required("Zuschlag in %"), `<input name="markupPercent" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]{1,2})?" required value="${esc(decimalInput(item?.markupPercent, "0.00"))}">`)}
          <div class="price-preview span-all"><span>Verkaufspreis netto</span><strong data-sales-price>${money(item?.salesPrice || 0)}</strong></div>
          ${field("Beschreibung", `<textarea name="description" rows="3" maxlength="5000">${esc(item?.description || "")}</textarea>`, "span-all")}
        </div>`);
      // Verkaufspreis rechnet der Server (gleiche Kalkulation wie im Angebot).
      let timer = 0;
      form.addEventListener("input", (event) => {
        if (!["costPrice", "markupPercent", "calculationType"].includes(event.target.name)) return;
        clearTimeout(timer);
        timer = setTimeout(async () => {
          if (!form.elements.costPrice.validity.valid || !form.elements.markupPercent.validity.valid) return;
          const output = form.querySelector("[data-sales-price]");
          try {
            const preview = await api("/api/master-data/preview-price", { method: "POST", body: { components: [{
              calculationType: form.elements.calculationType.value, quantity: "1.000",
              costPrice: decimalValue(form.elements.costPrice.value), markupPercent: decimalValue(form.elements.markupPercent.value),
            }] } });
            output.textContent = money(preview.calculatedUnitPrice);
          } catch (exception) {
            output.textContent = exception.message;
          }
        }, 200);
      });
      Calight.searchableSelect(form.elements.supplierId, { placeholder: "Lieferant suchen …" });
      editDialog({
        title: item ? "Stammartikel bearbeiten" : "Stammartikel anlegen", form, isNew: !item, initialFocus: '[name="name"]',
        save: async () => {
          await mutate(item ? `/api/master-data/cost-items/${encodeURIComponent(item.id)}` : "/api/master-data/cost-items", {
            articleNumber: value(form, "articleNumber") || null, name: value(form, "name"), description: value(form, "description"),
            categoryId: form.elements.categoryId.value || null, calculationType: form.elements.calculationType.value, unit: value(form, "unit"),
            costPrice: decimalValue(form.elements.costPrice.value), markupPercent: decimalValue(form.elements.markupPercent.value),
            supplierId: form.elements.supplierId.value || null, supplierArticleNumber: value(form, "supplierArticleNumber"),
          }, item ? "Stammartikel gespeichert." : "Stammartikel angelegt.", item ? "PUT" : "POST");
        },
      });
    },

    // Stammpositionen mit Kalkulationsbausteinen: eigene Seite.
    service(item) {
      location.href = item ? `/master-data/services/${encodeURIComponent(item.id)}` : "/master-data/services/new";
    },
  };

  const collections = { "cost-item": "costItems", service: "services", "trailing-text": "trailingTexts", category: "categories", supplier: "suppliers" };
  const endpoints = { "cost-item": "cost-items", service: "services", "trailing-text": "trailing-texts", category: "categories", supplier: "suppliers" };
  const nouns = { "cost-item": "Stammartikel", service: "Stammposition", "trailing-text": "Schlepptext", category: "Kategorie", supplier: "Lieferant" };
  const find = (kind, id) => state[collections[kind]].find((entry) => entry.id === id);

  async function action(button) {
    const { kind, id } = button.dataset;
    const item = find(kind, id);
    if (!item) return;
    const path = `/api/master-data/${endpoints[kind]}/${encodeURIComponent(id)}`;
    if (button.dataset.action === "edit") return editors[kind](item);
    if (button.dataset.action === "copy") return mutate(`${path}/copy`, {}, `${nouns[kind]} kopiert.`);
    if (button.dataset.action === "archive") return mutate(`${path}/archive`, { archived: item.active }, `${nouns[kind]} ${item.active ? "archiviert" : "reaktiviert"}.`);
    // Löschen mit eigener Bestätigung, die den Datensatz benennt.
    if (button.dataset.action === "delete") {
      if (!(await CalightModal.confirm({ title: `${nouns[kind]} löschen`, content: `„${item.code || item.name}“ wird gelöscht.`, confirmLabel: "Löschen", danger: true }))) return;
      return mutate(path, {}, `${nouns[kind]} gelöscht.`, "DELETE");
    }
  }

  root.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-action][data-kind]");
    if (button) {
      event.stopPropagation();
      button.disabled = true;
      try {
        await action(button);
      } catch (exception) {
        failure(exception.message);
      } finally {
        button.disabled = false;
      }
      return;
    }
    const open = event.target.closest("[data-open]");
    if (open && !event.target.closest("a,button,input,select,textarea,details,summary")) {
      const item = find(open.dataset.open, open.dataset.id);
      if (item) editors[open.dataset.open](item);
    }
  });
  root.addEventListener("keydown", (event) => {
    const open = event.target.closest?.("[data-open]");
    if (!open || event.target !== open || !["Enter", " "].includes(event.key)) return;
    event.preventDefault();
    const item = find(open.dataset.open, open.dataset.id);
    if (item) editors[open.dataset.open](item);
  });

  // ---------------------------------------------------------------------------
  // JSON-Austausch je Bereich
  // ---------------------------------------------------------------------------
  root.addEventListener("click", (event) => {
    const exportButton = event.target.closest("[data-master-export]");
    if (exportButton) {
      const link = document.createElement("a");
      link.href = `/api/master-data/export/${encodeURIComponent(exportButton.dataset.masterExport)}`;
      link.download = "";
      link.click();
      return;
    }
    const importButton = event.target.closest("[data-master-import]");
    if (!importButton) return;
    importScope = importButton.dataset.masterImport;
    importFile.value = "";
    importFile.click();
  });
  importFile.addEventListener("change", async () => {
    const file = importFile.files[0];
    if (!file || !importScope) return;
    try {
      if (file.size > 10_000_000) throw new Error("Die JSON-Datei darf höchstens 10 MB groß sein.");
      if (!(await CalightModal.confirm({ title: "Stammdaten importieren", content: "Den vollständigen Stamm aus dieser Datei importieren? Vorhandene Datensätze mit gleicher interner ID werden aktualisiert.", confirmLabel: "Importieren" }))) return;
      let document;
      try {
        document = JSON.parse(await file.text());
      } catch {
        throw new Error("Die ausgewählte Datei enthält kein gültiges JSON.");
      }
      const result = await api(`/api/master-data/import/${encodeURIComponent(importScope)}`, { method: "POST", body: document });
      await load();
      success(`${result.imported} Datensätze wurden importiert.`);
    } catch (exception) {
      failure(exception.message);
    } finally {
      importFile.value = "";
      importScope = "";
    }
  });

  load();
})();
