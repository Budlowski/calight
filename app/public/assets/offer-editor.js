(() => {
  "use strict";
  const root = document.querySelector("#offer-editor");
  if (!root) return;
  const tree = document.querySelector("#offer-tree"),
    titleInput = document.querySelector("#offer-title"),
    status = document.querySelector("#save-status"),
    saveButton = document.querySelector("#save-offer"),
    termFields = {
      introText: document.querySelector("#offer-intro-text"),
      closingText: document.querySelector("#offer-closing-text"),
      paymentTerms: document.querySelector("#offer-payment-terms"),
      additionalTerms: document.querySelector("#offer-additional-terms"),
      validityMode: document.querySelector("#offer-validity-mode"),
      validityDays: document.querySelector("#offer-validity-days"),
      validityDate: document.querySelector("#offer-validity-date"),
    };
  let offer = null,
    etag = root.dataset.etag,
    dirty = false,
    draggedId = null,
    calcTimer = 0,
    stateRevision = 0,
    calculationRequest = 0,
    searchQuery = "",
    workStatusFilter = "",
    searchPreviousOpen = null,
    titlePositionCounts = new Map();
  const openNodes = new Set(),
    pricePreviews = new Map(),
    library = { services: [], costItems: [], trailingTexts: [] };
  const esc = (v) =>
    String(v ?? "").replace(
      /[&<>'"]/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;",
        })[c],
    );
  const euro = (v) =>
    v === null || v === undefined
      ? "–"
      : new Intl.NumberFormat("de-DE", {
          style: "currency",
          currency: "EUR",
        }).format(Number(v));
  const dec = (v, fallback = "0") => String(v ?? fallback).replace(".", ",");
  const toolbar = root.querySelector(".offer-tree-toolbar");
  const structureScroll = root.querySelector(".offer-structure-scroll");
  const offerHeaderScroll = structureScroll.querySelector(
      ".offer-header-scroll",
    ),
    offerTreeScroll = structureScroll.querySelector(".offer-tree-scroll");
  const costItemDialog = document.querySelector("#cost-item-dialog"),
    costItemDialogForm = document.querySelector("#cost-item-dialog-form"),
    costItemDialogError = costItemDialog?.querySelector(
      "[data-cost-item-dialog-error]",
    );
  let pendingCostItemComponent = null;
  offerTreeScroll.addEventListener("scroll", () => {
    offerHeaderScroll.scrollLeft = offerTreeScroll.scrollLeft;
  });
  toolbar
    .querySelector("[data-add-root-position]")
    .insertAdjacentHTML(
      "afterend",
      '<button class="button button-light" type="button" data-icon="plus" data-add-root-text>Text hinzufügen</button>',
    );
  toolbar.insertAdjacentHTML(
    "beforebegin",
    '<div class="offer-search"><label><span class="visually-hidden">Angebot durchsuchen</span><input type="search" data-offer-search placeholder="Titel und Positionen durchsuchen"></label><label class="offer-status-filter"><span class="visually-hidden">Bearbeitungsstatus filtern</span><select data-work-status-filter><option value="">Alle Bearbeitungsstatus</option><option value="open">Offen</option><option value="in_progress">In Bearbeitung</option><option value="done">Fertig</option></select></label><span data-search-count>Keine Suche</span><div class="offer-progress" data-offer-progress></div><div class="offer-search-results" data-search-results hidden></div></div>',
  );
  structureScroll.insertAdjacentHTML(
    "afterend",
    '<section class="offer-adjustment-panel"><strong>Angebotsanpassung</strong><label>Art<select data-adjustment="type"><option value="none">Keine</option><option value="discount">Rabatt</option><option value="surcharge">Aufschlag</option></select></label><label>Berechnung<select data-adjustment="mode"><option value="percentage">Prozentual</option><option value="absolute">Absolut</option></select></label><label>Wert<input data-adjustment="value" inputmode="decimal"></label><label>Bezeichnung<input data-adjustment="label" placeholder="Optional"></label></section>',
  );
  const adjustmentPanel = root.querySelector(".offer-adjustment-panel"),
    searchInput = root.querySelector("[data-offer-search]"),
    statusFilter = root.querySelector("[data-work-status-filter]"),
    searchCount = root.querySelector("[data-search-count]"),
    progressOutput = root.querySelector("[data-offer-progress]"),
    searchResults = root.querySelector("[data-search-results]");
  function uuid() {
    if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0,
        v = c === "x" ? r : (r & 3) | 8;
      return v.toString(16);
    });
  }
  async function responsePayload(response) {
    const text = await response.text();
    if (text === "") return {};
    try {
      return JSON.parse(text);
    } catch {
      return {
        error:
          text
            .replace(/<[^>]*>/g, " ")
            .replace(/\s+/g, " ")
            .trim() || "Anfrage fehlgeschlagen.",
      };
    }
  }
  async function api(path, body) {
    const response = await fetch(path, {
        method: body === undefined ? "GET" : "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          ...(body === undefined
            ? {}
            : {
                "Content-Type": "application/json",
                "X-CSRF-Token": root.dataset.csrf,
              }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      }),
      payload = await responsePayload(response);
    if (!response.ok)
      throw new Error(
        (payload.details || []).join(" ") ||
          payload.error ||
          "Anfrage fehlgeschlagen.",
      );
    return payload.data ?? payload;
  }
  function markDirty() {
    dirty = true;
    stateRevision++;
    status.textContent = "Ungespeicherte Änderungen";
    scheduleCalculation();
  }
  function newTitle() {
    return {
      id: uuid(),
      type: "title",
      titleType: "normal",
      name: "Neuer Titel",
      description: "",
      children: [],
    };
  }
  function newPosition() {
    return {
      id: uuid(),
      type: "position",
      positionType: "normal",
      workStatus: "open",
      shortText: "Neue Position",
      longText: "",
      quantity: "1.000",
      unit: "Stk.",
      manualUnitPrice: null,
      components: [],
      sourceServiceId: null,
      sourceServiceNumber: null,
      calculation: null,
    };
  }
  function newText() {
    return {
      id: uuid(),
      type: "text",
      title: "",
      text: "Neuer Hinweis",
      calculation: null,
    };
  }
  function normalize() {
    offer.documentTerms ??= {
      introText: null,
      closingText: null,
      paymentTerms: null,
      additionalTerms: null,
      validityMode: "default",
      validityDays: null,
      validityDate: null,
    };
    offer.offerAdjustment ??= {
      type: "none",
      mode: "percentage",
      value: "0.00",
      label: null,
    };
    walk(offer.nodes, (node) => {
      if (node.type === "title") {
        node.titleType ??= "normal";
        return;
      }
      if (node.type !== "position") return;
      node.sourceServiceId ??= null;
      node.sourceServiceNumber ??= null;
      node.workStatus = CalightOfferStatus.normalized(node.workStatus);
      node.components ??= [];
      node.components.forEach((c) => {
        c.sourceArticleNumber ??= c.masterDataSnapshot?.articleNumber ?? null;
        c.priceMode ??= c.masterDataId
          ? c.priceLocked
            ? "manual"
            : "master"
          : "manual";
        if (c.priceMode === "locked") c.priceMode = "manual";
        delete c.priceLocked;
        delete c.masterDataSnapshot;
      });
    });
  }
  function showTerms() {
    for (const [name, field] of Object.entries(termFields))
      field.value =
        offer.documentTerms[name] ?? (name === "validityMode" ? "default" : "");
  }
  function syncTerms() {
    const mode = termFields.validityMode.value;
    offer.documentTerms = {
      introText: termFields.introText.value || null,
      closingText: termFields.closingText.value || null,
      paymentTerms: termFields.paymentTerms.value || null,
      additionalTerms: termFields.additionalTerms.value || null,
      validityMode: mode,
      validityDays:
        mode === "duration"
          ? Number(termFields.validityDays.value) || null
          : null,
      validityDate:
        mode === "date" ? termFields.validityDate.value || null : null,
    };
  }
  function showAdjustment() {
    for (const field of adjustmentPanel.querySelectorAll("[data-adjustment]"))
      field.value =
        offer.offerAdjustment[field.dataset.adjustment] ??
        (field.dataset.adjustment === "value" ? "0,00" : "");
    const disabled = offer.offerAdjustment.type === "none";
    adjustmentPanel
      .querySelectorAll('[data-adjustment]:not([data-adjustment="type"])')
      .forEach((field) => (field.disabled = disabled));
  }
  function syncAdjustment() {
    const fields = Object.fromEntries(
      [...adjustmentPanel.querySelectorAll("[data-adjustment]")].map(
        (field) => [field.dataset.adjustment, field.value],
      ),
    );
    offer.offerAdjustment = {
      type: fields.type,
      mode: fields.mode,
      value: fields.value.replace(",", ".") || "0",
      label: fields.label.trim() || null,
    };
  }
  function walk(nodes, fn) {
    nodes.forEach((n) => {
      fn(n);
      if (n.type === "title") walk(n.children, fn);
    });
  }
  function find(id, nodes = offer.nodes) {
    for (const n of nodes) {
      if (n.id === id) return n;
      if (n.type === "title") {
        const x = find(id, n.children);
        if (x) return x;
      }
    }
    return null;
  }
  function container(parentId) {
    if (!parentId) return offer.nodes;
    const p = find(parentId);
    return p?.type === "title" ? p.children : null;
  }
  function locate(id, nodes = offer.nodes, parentId = "") {
    for (let i = 0; i < nodes.length; i++) {
      const n = nodes[i];
      if (n.id === id) return { nodes, index: i, parentId };
      if (n.type === "title") {
        const x = locate(id, n.children, n.id);
        if (x) return x;
      }
    }
    return null;
  }
  function cloneNode(node) {
    const copy = structuredClone(node);
    walk([copy], (n) => {
      n.id = uuid();
      if (n.type === "position") n.components.forEach((c) => (c.id = uuid()));
    });
    return copy;
  }
  const numbering = CalightOfferSearch.numbering;
  function dragHandle(n, number = "") {
    return `<span class="offer-row-drag" draggable="true" data-drag-id="${n.id}" title="${number ? `${esc(number)} ziehen und verschieben` : "Ziehen und verschieben"}"><span class="offer-drag" data-icon="grip-vertical" aria-hidden="true"></span><span class="offer-node-number">${number}</span></span>`;
  }
  function controls(n, i, total) {
    const removeMessage =
      n.type === "title" && n.children.length
        ? "Titel einschließlich aller enthaltenen Elemente entfernen?"
        : "Element wirklich entfernen?";
    return `<div class="offer-node-actions"><button class="icon-button" type="button" data-icon="arrow-up" data-up title="Nach oben" aria-label="Nach oben" ${i === 0 ? "disabled" : ""}></button><button class="icon-button" type="button" data-icon="arrow-down" data-down title="Nach unten" aria-label="Nach unten" ${i === total - 1 ? "disabled" : ""}></button><button class="icon-button" type="button" data-icon="copy" data-copy title="Kopieren" aria-label="Kopieren"></button><button class="icon-button icon-button-danger" type="button" data-icon="trash" data-remove data-confirm-message="${esc(removeMessage)}" title="Entfernen" aria-label="Entfernen"></button></div>`;
  }
  function dropZone(parent, index) {
    return `<div class="offer-drop-zone" data-drop-parent="${esc(parent)}" data-drop-index="${index}"></div>`;
  }
  function serviceLabel(s) {
    return `${s.articleNumber || "Ohne Nr."} · ${s.shortText}`;
  }
  function costLabel(c) {
    return `${c.articleNumber || "Ohne Nr."} · ${c.name}`;
  }
  function typeLabel(type) {
    return (
      {
        material: "Material",
        labor: "Lohn",
        external: "Fremdleistung",
        other: "Sonstige Kosten",
      }[type] || type
    );
  }
  function exactLibraryEntry(items, label, value) {
    const matches = items.filter((item) => label(item) === value);
    if (matches.length === 1) return matches[0];
    status.textContent = matches.length
      ? "Die Auswahl ist nicht eindeutig. Bitte eine eindeutige Artikel- oder Positionsnummer verwenden."
      : "Der ausgewählte Stammdatensatz wurde nicht gefunden.";
    return null;
  }
  function componentReference(c) {
    return c.sourceArticleNumber || c.name || "ohne Kennung";
  }
  function componentSource(c) {
    return c.priceMode === "master"
      ? `Aus Artikelstamm ${componentReference(c)}`
      : c.masterDataId
        ? `Eigener Preis · Artikelstamm ${componentReference(c)}`
        : "Eigener Preis";
  }
  function componentRows(p) {
    return p.components.length
      ? p.components
          .map(
            (
              c,
              i,
            ) => `<details class="component-row" data-component-index="${i}" data-component-type="${esc(c.calculationType)}">
              <summary class="component-summary">
                <span class="component-identity" title="${esc(c.name)}"><strong data-component-compact-name>${esc(c.name)}</strong><small data-component-compact-source>${esc(componentSource(c))}</small></span>
                <span class="component-type-badge" data-component-compact-type>${esc(typeLabel(c.calculationType))}</span>
                <span class="component-summary-value"><small>Menge</small><strong data-component-compact-quantity>${esc(dec(c.quantity, "1.000"))} ${esc(c.unit)}</strong></span>
                <span class="component-summary-value"><small>Kosten-EP</small><strong data-component-compact-cost>${euro(c.costPrice)}</strong></span>
                <span class="component-summary-value"><small>Aufschlag</small><strong data-component-compact-markup>${esc(dec(c.markupPercent, "0.00"))} %</strong></span>
                <span class="component-summary-value component-summary-sales"><small>VK-Anteil</small><strong data-component-compact-sales>${euro(p.calculation?.componentSalesPrices?.[i])}</strong></span>
              </summary>
              <div class="component-editor-body">
                <div class="component-primary-grid">
                  <label>Bezeichnung<input data-component-field="name" value="${esc(c.name)}"></label>
                  <label>Typ<select data-component-field="calculationType">${[
                    ["material", "Material"],
                    ["labor", "Lohn"],
                    ["external", "Fremdleistung"],
                    ["other", "Sonstige Kosten"],
                  ]
                    .map(
                      ([v, l]) =>
                        `<option value="${v}" ${c.calculationType === v ? "selected" : ""}>${l}</option>`,
                    )
                    .join("")}</select></label>
                  <label>Preisquelle<select data-component-field="priceMode" ${c.masterDataId ? "" : "disabled"}>${c.masterDataId ? `<option value="master" ${c.priceMode === "master" ? "selected" : ""}>Aus Artikelstamm ${esc(componentReference(c))}</option>` : ""}<option value="manual" ${c.priceMode === "manual" || !c.masterDataId ? "selected" : ""}>${c.masterDataId ? `Eigener Preis · Artikelstamm ${esc(componentReference(c))}` : "Eigener Preis"}</option></select></label>
                  <div class="component-controls-actions">
                    <button class="table-action" type="button" data-component-up title="Nach oben" aria-label="Nach oben" ${i === 0 ? "disabled" : ""}>↑</button>
                    <button class="table-action" type="button" data-component-down title="Nach unten" aria-label="Nach unten" ${i === p.components.length - 1 ? "disabled" : ""}>↓</button>
                    <button class="icon-button" type="button" data-component-copy title="Kopieren" aria-label="Kopieren"></button>
                    <button class="icon-button icon-button-danger" type="button" data-component-remove title="Entfernen" aria-label="Entfernen"></button>
                  </div>
                </div>
                <div class="component-price-grid">
                  <label>Menge je Leistungseinheit<input data-component-field="quantity" inputmode="decimal" value="${esc(dec(c.quantity, "1.000"))}"></label>
                  <label>Einheit<input data-component-field="unit" value="${esc(c.unit)}"></label>
                  <label>Kosten-EP<input data-component-field="costPrice" inputmode="decimal" value="${esc(dec(c.costPrice, "0.0000"))}" ${c.priceMode === "master" ? "readonly" : ""}></label>
                  <label>Aufschlag auf Kosten %<input data-component-field="markupPercent" inputmode="decimal" value="${esc(dec(c.markupPercent, "0.00"))}" ${c.priceMode === "master" ? "readonly" : ""}></label>
                  <div class="component-sales"><span>VK-Anteil je Leistungseinheit</span><strong data-component-sales>${euro(p.calculation?.componentSalesPrices?.[i])}</strong></div>
                  <div class="component-save-action"><button class="table-action" type="button" data-save-cost-item data-icon="save">In Artikelstamm speichern</button></div>
                </div>
              </div>
            </details>`,
          )
          .join("")
      : '<p class="muted component-empty">Noch keine Kalkulationsbausteine. Wählen Sie einen Eintrag aus dem Artikelstamm oder legen Sie einen Baustein manuell an.</p>';
  }
  function totals(p) {
    const c = p.calculation;
    if (!c)
      return '<div class="notice">Noch nicht kalkuliert. Baustein hinzufügen oder manuellen Einheitspreis aktivieren.</div>';
    return `<div class="offer-calculation-totals"><div>Materialkosten<strong>${euro(c.materialCosts)}</strong></div><div>Lohnkosten<strong>${euro(c.laborCosts)}</strong></div><div>Fremdleistungen<strong>${euro(c.externalCosts)}</strong></div><div>Sonstige Kosten<strong>${euro(c.otherCosts)}</strong></div><div class="total-cost">Selbstkosten<strong>${euro(c.totalCosts)}</strong></div><div class="total-cost">Zuschläge<strong>${euro(c.markupTotal)}</strong></div><div class="total-price">Kalkulierter EP<strong>${euro(c.calculatedUnitPrice)}</strong></div><div class="total-price effective-price">Verwendeter EP<strong>${euro(c.effectiveUnitPrice)}</strong></div><div class="total-price position-total">Gesamtpreis<strong>${euro(c.positionTotal)}</strong></div>${p.manualUnitPrice !== null ? `<div class="price-deviation">Preisdifferenz<strong>${euro(c.priceDeviation)}</strong></div>` : ""}</div>`;
  }
  function previewHtml(p) {
    const preview = pricePreviews.get(p.id);
    if (!preview) return "";
    const warnings = preview.warnings.map((w) => `<li>${esc(w)}</li>`).join("");
    const changes = preview.changes
      .map(
        (x) =>
          `<tr><td>${esc(x.name)}</td><td>${euro(x.oldCostPrice)}<br>${euro(x.oldSalesPrice)}</td><td>${euro(x.newCostPrice)}<br>${euro(x.newSalesPrice)}</td></tr>`,
      )
      .join("");
    return `<div class="price-update-preview"><h3>Vorschau Preisaktualisierung</h3>${warnings ? `<ul>${warnings}</ul>` : ""}${changes ? `<table><thead><tr><th>Baustein</th><th>Bisher</th><th>Neu</th></tr></thead><tbody>${changes}</tbody></table><div class="form-actions"><button class="button" type="button" data-apply-prices>Änderungen übernehmen</button><button class="button button-light" type="button" data-cancel-prices>Abbrechen</button></div>` : '<p>Keine aktualisierbaren Änderungen.</p><button class="button button-light" type="button" data-cancel-prices>Schließen</button>'}</div>`;
  }
  function positionEditor(p) {
    const manual = p.manualUnitPrice !== null;
    const calculationEditor = manual
      ? ""
      : `<div class="components-panel"><div class="offer-template-row"><label>Kalkulation<input list="costs-${p.id}" data-cost-choice placeholder="Artikelstamm durchsuchen"><datalist id="costs-${p.id}">${library.costItems.map((c) => `<option value="${esc(costLabel(c))}"></option>`).join("")}</datalist></label><button class="button button-light" type="button" data-add-cost>Übernehmen</button><button class="button button-light secondary-action" type="button" data-add-manual>Manuell anlegen</button></div><div class="components-list">${componentRows(p)}</div></div>`;
    return `<section class="offer-editor-section position-data-section"><div class="offer-section-heading"><span class="offer-section-step">1</span><div><strong>Positionsdaten</strong><small>Leistung, Menge und Positionsart</small></div></div><div class="offer-position-grid"><label>Kurztext<input data-field="shortText" value="${esc(p.shortText)}"></label><label>Positionsart<select data-field="positionType"><option value="normal" ${p.positionType === "normal" ? "selected" : ""}>Normalposition</option><option value="bedarf" ${p.positionType === "bedarf" ? "selected" : ""}>Bedarfsposition</option><option value="alternative" ${p.positionType === "alternative" ? "selected" : ""}>Alternativposition</option></select></label><label>Menge<input data-field="quantity" inputmode="decimal" value="${esc(dec(p.quantity, "1.000"))}"></label><label>Einheit<input data-field="unit" value="${esc(p.unit)}"></label></div><details class="offer-compact-details" ${p.longText ? "open" : ""}><summary>Langtext ${p.longText ? "bearbeiten" : "ergänzen"}</summary><label><span class="visually-hidden">Langtext</span><textarea data-field="longText" rows="3" placeholder="Optionale ausführliche Leistungsbeschreibung">${esc(p.longText)}</textarea></label></details><details class="offer-compact-details offer-template-panel"><summary>Aus Positionsstamm übernehmen</summary><div class="offer-template-row"><label>Stammposition<input list="services-${p.id}" data-service-choice placeholder="Positionsnummer oder Bezeichnung"><datalist id="services-${p.id}">${library.services.map((s) => `<option value="${esc(serviceLabel(s))}"></option>`).join("")}</datalist></label><button class="button button-light" type="button" data-take-service>Übernehmen</button></div></details></section><section class="offer-editor-section calculation-section"><div class="offer-section-heading"><span class="offer-section-step">2</span><div><strong>Preisermittlung</strong><small>${manual ? "Einheitspreis" : "Kalkulation aus " + p.components.length + " Baustein" + (p.components.length === 1 ? "" : "en")}</small></div></div><div class="price-method-row"><label>Preisermittlung<select data-price-method><option value="calculation" ${manual ? "" : "selected"}>Kalkulation</option><option value="unit-price" ${manual ? "selected" : ""}>Einheitspreis</option></select></label>${manual ? `<label>Einheitspreis netto<input data-manual-price inputmode="decimal" value="${esc(dec(p.manualUnitPrice))}"></label><p class="muted">Die Kalkulationsbausteine bleiben zur Dokumentation erhalten, bestimmen aber nicht den verwendeten Einheitspreis.</p>` : ""}</div>${calculationEditor}</section><section class="offer-editor-section price-section"><div class="offer-section-heading"><span class="offer-section-step">3</span><div><strong>Preisübersicht</strong><small>Kosten, Zuschläge und Verkaufspreis</small></div></div><div data-position-totals>${totals(p)}</div>${previewHtml(p)}<div class="offer-master-result" data-master-result></div><details class="offer-compact-details secondary-actions-panel"><summary>Weitere Aktionen</summary><div class="form-actions"><button class="button button-light" type="button" data-preview-prices>Preise aktualisieren</button><button class="button button-light" type="button" data-save-master>Im Positionsstamm speichern</button></div></details></section>`;
  }
  function renderNodes(nodes, parent, numbers, depth = 0) {
    let html = workStatusFilter ? "" : dropZone(parent, 0);
    nodes.forEach((n, i) => {
      if (!CalightOfferStatus.visible(n, workStatusFilter)) return;
      const no = numbers.get(n.id),
        level = depth === 0 ? "Titel" : "Untertitel",
        open = openNodes.has(n.id) ? "open" : "";
      if (n.type === "title") {
        const kind =
            n.titleType === "normal"
              ? "Normal"
              : n.titleType === "bedarf"
                ? "Bedarf"
                : "Alternative",
          excluded = n.titleType !== "normal";
        const derived = CalightOfferStatus.derived(n.children);
        html += `<details class="offer-node offer-title-node offer-kind-${esc(n.titleType)}" data-node-id="${n.id}" data-depth="${depth}" ${open}><summary>${dragHandle(n, no)}<span class="offer-level-label">${level}</span><span class="offer-title-name-summary"><strong data-node-label>${esc(n.name)}</strong><small>${positionCountLabel(n.id)}</small></span><span class="offer-position-meta"><span class="offer-position-kind">${kind}</span>${workStatusBadge(derived)}</span><span class="offer-title-total">${titleTotalText(n)}</span>${controls(n, i, nodes.length)}</summary><div class="offer-node-editor offer-title-editor"><div class="offer-title-edit-row"><label class="offer-title-name"><span>${level}bezeichnung</span><input data-field="name" value="${esc(n.name)}"></label><label><span>Titelart</span><select data-field="titleType"><option value="normal" ${n.titleType === "normal" ? "selected" : ""}>Normal</option><option value="bedarf" ${n.titleType === "bedarf" ? "selected" : ""}>Bedarf</option><option value="alternative" ${n.titleType === "alternative" ? "selected" : ""}>Alternative</option></select></label><label class="offer-title-description"><span>Beschreibung</span><textarea data-field="description" rows="1" placeholder="Optionale Beschreibung">${esc(n.description)}</textarea></label><div class="offer-child-toolbar"><button class="button button-light" type="button" data-icon="plus" data-add-child-title>Untertitel</button><button class="button button-light" type="button" data-icon="plus" data-add-child-position>Position</button><button class="button button-light" type="button" data-icon="plus" data-add-child-text>Text</button></div></div>${excluded ? '<div class="offer-excluded-note">Nicht in Angebotssumme enthalten</div>' : ""}<div class="offer-children">${renderNodes(n.children, n.id, numbers, depth + 1)}</div></div></details>`;
      } else if (n.type === "text") {
        html += `<details class="offer-node offer-text-node" data-node-id="${n.id}" data-depth="${depth}" ${open}><summary>${dragHandle(n)}<span class="offer-level-label text-label">Text</span><strong data-node-label>${esc(n.title || n.text)}</strong>${controls(n, i, nodes.length)}</summary><div class="offer-node-editor offer-text-editor"><label>Optionale Überschrift<input data-field="title" value="${esc(n.title)}"></label><label>Text<textarea data-field="text" rows="4">${esc(n.text)}</textarea></label></div></details>`;
      } else {
        html += `<details class="offer-node offer-position-node offer-kind-${esc(n.positionType)}" data-node-id="${n.id}" data-depth="${depth}" ${open}><summary>${dragHandle(n, no)}<span class="offer-level-label position-label">Position</span><span class="offer-position-name"><strong data-node-label>${esc(n.shortText)}</strong>${trailingTextBadge(n)}</span><span class="offer-position-meta"><span class="offer-position-kind">${n.positionType === "normal" ? "Normal" : n.positionType === "bedarf" ? "Bedarf" : "Alternative"}</span><span class="offer-price-source">${n.manualUnitPrice !== null ? "Eigener EP" : "Kalkuliert"}</span><select class="offer-work-status work-status-${esc(n.workStatus)}" data-work-status aria-label="Bearbeitungsstatus"><option value="open" ${n.workStatus === "open" ? "selected" : ""}>Offen</option><option value="in_progress" ${n.workStatus === "in_progress" ? "selected" : ""}>In Bearbeitung</option><option value="done" ${n.workStatus === "done" ? "selected" : ""}>Fertig</option></select></span><span class="offer-position-summary">${esc(dec(n.quantity))} ${esc(n.unit)}</span><span class="offer-unit-price">${euro(n.calculation?.effectiveUnitPrice)}</span><strong class="offer-gross-price">${euro(n.calculation?.positionTotal)}</strong>${controls(n, i, nodes.length)}</summary><div class="offer-node-editor offer-position-editor">${positionEditor(n)}</div></details>`;
      }
      if (!workStatusFilter) html += dropZone(parent, i + 1);
    });
    return html;
  }
  function workStatusBadge(value) {
    const labels = {
      open: "Offen",
      in_progress: "In Bearbeitung",
      done: "Fertig",
      neutral: "Leer",
    };
    return `<span class="offer-work-status-badge work-status-${esc(value)}">${labels[value]}</span>`;
  }
  function positionCountLabel(id) {
    const count = titlePositionCounts.get(id) ?? 0;
    return `${count} Position${count === 1 ? "" : "en"}`;
  }
  function trailingTextBadge(position) {
    const text = position.trailingText;
    if (!text?.code) return "";
    return `<span class="offer-trailing-badge" title="${esc(text.name)}">${esc(text.code)}</span>`;
  }
  function titleTotalText(node) {
    const calculation = node.calculation ?? {},
      alternative = calculation.alternativeSubtotal ?? "0.00",
      demand = calculation.demandSubtotal ?? "0.00",
      regular = calculation.regularSubtotal ?? "0.00";
    return `<span class="title-total-alternative${Number(alternative) === 0 ? " is-empty" : ""}">${Number(alternative) !== 0 ? `<small>Alternativ</small><strong>${euro(alternative)}</strong>` : ""}</span><span class="title-total-demand${Number(demand) === 0 ? " is-empty" : ""}">${Number(demand) !== 0 ? `<small>Bedarf</small><strong>${euro(demand)}</strong>` : ""}</span><span class="title-total-regular"><strong>${euro(regular)}</strong></span>`;
  }
  function trailingTextOptions(position) {
    const current = position.trailingText,
      known =
        current && library.trailingTexts.some((item) => item.id === current.id);
    return `<option value="">Kein Schlepptext</option>${library.trailingTexts.map((item) => `<option value="${item.id}" ${current?.id === item.id ? "selected" : ""}>${esc(item.code)} · ${esc(item.name)}</option>`).join("")}${current && !known ? `<option value="__snapshot" selected>${esc(current.code)} · ${esc(current.name)} (Snapshot)</option>` : ""}`;
  }
  function render() {
    if (!offer) return;
    titlePositionCounts = CalightOfferSearch.positionCounts(offer.nodes);
    const rendered = offer.nodes.length
      ? renderNodes(offer.nodes, "", numbering(offer.nodes))
      : "";
    tree.innerHTML =
      rendered ||
      `<div class="card offer-empty"><strong>${workStatusFilter ? "Keine Position mit diesem Bearbeitungsstatus." : "Noch keine Angebotsstruktur."}</strong></div>`;
    tree
      .querySelectorAll("[data-depth]")
      .forEach((row) =>
        row.classList.toggle(
          "offer-depth-capped",
          Number(row.dataset.depth) >= 3,
        ),
      );
    walk(offer.nodes, (node) => {
      if (node.type === "position") {
        const grid = tree.querySelector(
          `[data-node-id="${node.id}"] .offer-position-grid`,
        );
        if (grid)
          grid.insertAdjacentHTML(
            "beforeend",
            `<label>Schlepptext<select data-trailing-text>${trailingTextOptions(node)}</select></label>`,
          );
      }
    });
    applySearchHighlights();
    updateProgress();
    updateOfferTotal();
  }
  function updateProgress() {
    const progress = CalightOfferStatus.progress(offer.nodes);
    progressOutput.innerHTML = `<span>${progress.done} von ${progress.total} Positionen fertig</span><span class="offer-progress-track" aria-hidden="true"><i style="width:${progress.percent}%"></i></span>`;
  }
  function updateOfferTotal() {
    let out = document.querySelector("#offer-net-total");
    if (!out) {
      out = document.createElement("div");
      out.id = "offer-net-total";
      out.className = "offer-net-total";
      structureScroll.after(out);
    }
    const c = offer?.calculation ?? {},
      a = offer?.offerAdjustment ?? { type: "none" },
      adjusted = a.type !== "none";
    out.innerHTML = adjusted
      ? `<span>Zwischensumme ${euro(c.subtotalNet)}</span><span>${esc(a.label || (a.type === "discount" ? "Rabatt" : "Aufschlag"))} ${euro(c.adjustmentSigned)}</span><strong>Angebotssumme netto ${euro(c.netTotal)}</strong>`
      : `<span>Angebotssumme netto</span><strong>${euro(c.netTotal ?? "0.00")}</strong>`;
  }
  function searchHits() {
    return searchQuery
      ? CalightOfferSearch.find(
          offer.nodes,
          numbering(offer.nodes),
          searchQuery,
        )
      : [];
  }
  function openAncestors(id) {
    let location = locate(id);
    while (location?.parentId) {
      openNodes.add(location.parentId);
      location = locate(location.parentId);
    }
  }
  function applySearchHighlights() {
    if (!searchQuery) return;
    for (const hit of searchHits())
      tree
        .querySelector(`[data-node-id="${hit.id}"]`)
        ?.classList.add("offer-search-match");
  }
  function runSearch() {
    const value = searchInput.value.trim().toLocaleLowerCase("de-DE");
    if (value && !searchQuery) searchPreviousOpen = new Set(openNodes);
    if (!value && searchQuery) {
      openNodes.clear();
      for (const id of searchPreviousOpen ?? []) openNodes.add(id);
      searchPreviousOpen = null;
    }
    searchQuery = value;
    const hits = searchHits();
    if (value) for (const hit of hits) openAncestors(hit.id);
    searchCount.textContent = value ? `${hits.length} Treffer` : "Keine Suche";
    searchResults.hidden = !value || hits.length === 0;
    searchResults.innerHTML = hits
      .map(
        (hit) =>
          `<button type="button" data-search-target="${hit.id}">${esc(hit.label)}</button>`,
      )
      .join("");
    render();
  }
  function structural(focus = null) {
    markDirty();
    render();
    if (focus) tree.querySelector(`[data-node-id="${focus}"] input`)?.focus();
  }
  function scheduleCalculation() {
    clearTimeout(calcTimer);
    calcTimer = setTimeout(calculate, 250);
  }
  async function calculate() {
    if (!offer) return;
    const revision = stateRevision,
      request = ++calculationRequest,
      payload = structuredClone(offer);
    payload.title = titleInput.value.trim();
    try {
      const calculated = await api("/api/offer-calculation/calculate", payload);
      if (request !== calculationRequest || revision !== stateRevision) return;
      offer = calculated;
      refreshCalculations();
    } catch (e) {
      if (request === calculationRequest && revision === stateRevision)
        status.textContent = e.message;
    }
  }
  function refreshComponentSummaries(row, position) {
    row.querySelectorAll(".component-row").forEach((element, i) => {
      const c = position.components[i];
      if (!c) return;
      element.dataset.componentType = c.calculationType;
      element.querySelector("[data-component-compact-name]").textContent =
        c.name;
      element.querySelector(".component-identity").title = c.name;
      element.querySelector("[data-component-compact-source]").textContent =
        componentSource(c);
      element.querySelector("[data-component-compact-type]").textContent =
        typeLabel(c.calculationType);
      element.querySelector("[data-component-compact-quantity]").textContent =
        `${dec(c.quantity, "1.000")} ${c.unit}`;
      element.querySelector("[data-component-compact-cost]").textContent = euro(
        c.costPrice,
      );
      element.querySelector("[data-component-compact-markup]").textContent =
        `${dec(c.markupPercent, "0.00")} %`;
      element.querySelector("[data-component-compact-sales]").textContent =
        euro(position.calculation?.componentSalesPrices?.[i]);
      element.querySelector("[data-component-sales]").textContent = euro(
        position.calculation?.componentSalesPrices?.[i],
      );
    });
  }
  function openCostItemDialog(component) {
    pendingCostItemComponent = component;
    costItemDialogForm.reset();
    costItemDialogForm.elements.articleNumber.value = "";
    costItemDialogForm.elements.name.value = component.name;
    costItemDialogForm.elements.calculationType.value =
      component.calculationType;
    costItemDialogForm.elements.unit.value = component.unit;
    costItemDialogForm.elements.costPrice.value = dec(
      component.costPrice,
      "0.0000",
    );
    costItemDialogForm.elements.markupPercent.value = dec(
      component.markupPercent,
      "0.00",
    );
    costItemDialogError.hidden = true;
    costItemDialog.showModal();
    costItemDialogForm.elements.articleNumber.focus();
  }
  function refreshCalculations() {
    walk(offer.nodes, (n) => {
      const row = tree.querySelector(`[data-node-id="${n.id}"]`);
      if (!row) return;
      if (n.type === "title") {
        row.querySelector(".offer-title-total").innerHTML = titleTotalText(n);
        return;
      }
      if (n.type === "text") {
        row.querySelector("[data-node-label]").textContent = n.title || n.text;
        return;
      }
      row.querySelector("[data-node-label]").textContent = n.shortText;
      row.querySelector(".offer-position-kind").textContent =
        n.positionType === "normal"
          ? "Normal"
          : n.positionType === "bedarf"
            ? "Bedarf"
            : "Alternative";
      row.querySelector(".offer-position-summary").textContent =
        `${dec(n.quantity)} ${n.unit}`;
      row.querySelector(".offer-price-source").textContent =
        n.manualUnitPrice !== null ? "Eigener EP" : "Kalkuliert";
      row.querySelector(".offer-unit-price").textContent = euro(
        n.calculation?.effectiveUnitPrice,
      );
      row.querySelector(".offer-gross-price").textContent = euro(
        n.calculation?.positionTotal,
      );
      row.querySelector("[data-position-totals]").innerHTML = totals(n);
      refreshComponentSummaries(row, n);
    });
    updateOfferTotal();
  }
  function addNode(parent, node) {
    container(parent)?.push(node);
    openNodes.add(node.id);
    structural(node.id);
  }
  document.querySelector("[data-add-root-title]").onclick = () =>
    addNode("", newTitle());
  document.querySelector("[data-add-root-position]").onclick = () =>
    addNode("", newPosition());
  document.querySelector("[data-add-root-text]").onclick = () =>
    addNode("", newText());
  document.querySelector("[data-expand-all]").onclick = () => {
    walk(offer.nodes, (n) => openNodes.add(n.id));
    render();
  };
  document.querySelector("[data-collapse-all]").onclick = () => {
    openNodes.clear();
    render();
  };
  statusFilter.addEventListener("change", () => {
    workStatusFilter = statusFilter.value;
    render();
  });
  function positions() {
    const result = [];
    walk(offer.nodes, (node) => {
      if (node.type === "position") result.push(node);
    });
    return result;
  }
  function componentFromMaster(component, id = uuid()) {
    return {
      id,
      masterDataId: component.masterDataId,
      sourceArticleNumber: component.sourceArticleNumber ?? null,
      name: component.name,
      calculationType: component.calculationType,
      unit: component.unit,
      quantity: component.quantity,
      costPrice: component.costPrice,
      markupPercent: component.markupPercent,
      priceMode: component.priceMode,
    };
  }
  async function reloadLibrary() {
    const current = await api("/api/offer-calculation/library");
    Object.assign(library, current);
    return current;
  }
  function confirmMasterChanges(heading, changes) {
    if (changes.length === 0) {
      status.textContent = `${heading}: keine Änderungen gefunden.`;
      return false;
    }
    const visible = changes.slice(0, 20),
      more = changes.length - visible.length,
      text = visible.map((change) => `• ${change}`).join("\n");
    return confirm(
      `${heading}\n\n${text}${more ? `\n• … und ${more} weitere` : ""}\n\nÄnderungen übernehmen? Sie werden erst mit „Entwurf speichern“ dauerhaft gespeichert.`,
    );
  }
  async function refreshArticleMasterData() {
    try {
      status.textContent = "Artikelstammdaten werden geprüft …";
      await reloadLibrary();
      const byId = new Map(library.costItems.map((item) => [item.id, item])),
        updates = [];
      for (const position of positions())
        for (const component of position.components) {
          if (!component.masterDataId || component.priceMode !== "master")
            continue;
          const master = byId.get(component.masterDataId);
          if (!master) continue;
          const next = componentFromMaster(
            {
              ...master,
              masterDataId: master.id,
              sourceArticleNumber: master.articleNumber,
              quantity: component.quantity,
              priceMode: "master",
            },
            component.id,
          );
          if (
            JSON.stringify(componentFromMaster(component, component.id)) !==
            JSON.stringify(next)
          )
            updates.push({
              component,
              next,
              label: `${position.shortText}: ${component.name} (${euro(component.costPrice)} → ${euro(next.costPrice)})`,
            });
        }
      if (
        !confirmMasterChanges(
          "Artikelstammdaten aktualisieren",
          updates.map((update) => update.label),
        )
      )
        return;
      updates.forEach((update) => Object.assign(update.component, update.next));
      markDirty();
      render();
      status.textContent = `${updates.length} Artikelverknüpfung${updates.length === 1 ? "" : "en"} aktualisiert.`;
    } catch (error) {
      status.textContent = error.message;
    }
  }
  async function refreshPositionMasterData() {
    try {
      status.textContent = "Positionsstammdaten werden geprüft …";
      await reloadLibrary();
      const byId = new Map(library.services.map((item) => [item.id, item])),
        updates = [];
      for (const position of positions()) {
        if (!position.sourceServiceId) continue;
        const master = byId.get(position.sourceServiceId);
        if (!master) continue;
        const next = {
          shortText: master.shortText,
          longText: master.longText,
          unit: master.unit,
          sourceServiceNumber: master.articleNumber ?? null,
          components: master.components.map((component) =>
            componentFromMaster(component),
          ),
          trailingText: master.trailingText
            ? structuredClone(master.trailingText)
            : null,
        };
        const current = {
          shortText: position.shortText,
          longText: position.longText,
          unit: position.unit,
          sourceServiceNumber: position.sourceServiceNumber ?? null,
          components: position.components.map((component) => {
            const copy = componentFromMaster(component, "");
            delete copy.id;
            return copy;
          }),
          trailingText: position.trailingText ?? null,
        };
        const comparable = {
          ...next,
          components: next.components.map((component) => {
            const copy = { ...component };
            delete copy.id;
            return copy;
          }),
        };
        if (JSON.stringify(current) !== JSON.stringify(comparable))
          updates.push({
            position,
            next,
            label: `${position.sourceServiceNumber || "Ohne Nr."} · ${position.shortText}`,
          });
      }
      if (
        !confirmMasterChanges(
          "Positionsstammdaten aktualisieren",
          updates.map((update) => update.label),
        )
      )
        return;
      for (const { position, next } of updates) {
        Object.assign(position, next);
        if (!next.trailingText) delete position.trailingText;
      }
      markDirty();
      render();
      status.textContent = `${updates.length} Stammposition${updates.length === 1 ? "" : "en"} aktualisiert.`;
    } catch (error) {
      status.textContent = error.message;
    }
  }
  document.querySelector("[data-refresh-article-master]").onclick =
    refreshArticleMasterData;
  document.querySelector("[data-refresh-position-master]").onclick =
    refreshPositionMasterData;
  const importFile = document.querySelector("[data-import-file]");
  document.querySelector("[data-import-json]").onclick = () =>
    importFile.click();
  importFile.onchange = async () => {
    const file = importFile.files[0];
    if (!file) return;
    if (file.size > 5_000_000) {
      status.textContent = "Die JSON-Datei darf höchstens 5 MB groß sein.";
      return;
    }
    try {
      const imported = JSON.parse(await file.text()),
        headers = {
          "Content-Type": "application/json",
          "X-CSRF-Token": root.dataset.csrf,
          "If-Match": `"${etag}"`,
        },
        previewResponse = await fetch(
          `/api/offers/${encodeURIComponent(root.dataset.offerId)}/import-preview`,
          {
            method: "POST",
            credentials: "same-origin",
            headers,
            body: JSON.stringify(imported),
          },
        ),
        preview = await previewResponse.json();
      if (!previewResponse.ok)
        throw new Error((preview.details || []).join(" ") || preview.error);
      const changes = preview.changes;
      if (
        !confirm(
          `Import prüfen:\nBezeichnung: ${changes.title.old} → ${changes.title.new}\nElemente: ${changes.nodes.old} → ${changes.nodes.new}\nSumme: ${euro(changes.netTotal.old)} → ${euro(changes.netTotal.new)}\n\nBestehenden Entwurf ersetzen?`,
        )
      )
        return;
      const response = await fetch(
          `/api/offers/${encodeURIComponent(root.dataset.offerId)}/import`,
          {
            method: "PUT",
            credentials: "same-origin",
            headers,
            body: JSON.stringify(imported),
          },
        ),
        result = await response.json();
      if (!response.ok)
        throw new Error((result.details || []).join(" ") || result.error);
      location.reload();
    } catch (error) {
      status.textContent = `Import fehlgeschlagen: ${error.message}`;
    } finally {
      importFile.value = "";
    }
  };
  function responseFileName(response) {
    const disposition = response.headers.get("Content-Disposition") || "";
    const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
    if (encoded) return decodeURIComponent(encoded[1]);
    const quoted = disposition.match(/filename="([^"]+)"/i);
    return quoted?.[1] || "Angebot.pdf";
  }
  async function pdf(download) {
    if (!offer) return;
    status.textContent = "PDF wird erzeugt …";
    try {
      offer.title = titleInput.value.trim();
      const response = await fetch(
        `/api/offers/${encodeURIComponent(root.dataset.offerId)}/pdf`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": root.dataset.csrf,
          },
          body: JSON.stringify(offer),
        },
      );
      if (!response.ok) {
        const result = await response.json();
        throw new Error((result.details || []).join(" ") || result.error);
      }
      const fileName = responseFileName(response),
        blob = await response.blob(),
        file = new File([blob], fileName, { type: "application/pdf" }),
        url = URL.createObjectURL(file);
      if (download) {
        const link = document.createElement("a");
        link.href = url;
        link.download = fileName;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } else {
        window.open(url, "_blank", "noopener");
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      }
      status.textContent = dirty ? "Ungespeicherte Änderungen" : "PDF erzeugt";
    } catch (error) {
      status.textContent = error.message;
    }
  }
  document.querySelector("[data-pdf-preview]").onclick = () => pdf(false);
  document.querySelector("[data-pdf-download]").onclick = () => pdf(true);
  costItemDialog
    ?.querySelectorAll("[data-dialog-close]")
    .forEach((button) =>
      button.addEventListener("click", () => costItemDialog.close()),
    );
  costItemDialogForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!pendingCostItemComponent) return;
    costItemDialogError.hidden = true;
    const fields = costItemDialogForm.elements;
    try {
      const saved = await api("/api/offer-calculation/save-cost-item", {
        articleNumber: fields.articleNumber.value.trim() || null,
        name: fields.name.value.trim(),
        calculationType: fields.calculationType.value,
        unit: fields.unit.value.trim(),
        costPrice: fields.costPrice.value.replace(",", "."),
        markupPercent: fields.markupPercent.value.replace(",", "."),
      });
      costItemDialog.close();
      await reloadLibrary();
      status.textContent = `Stammartikel ${saved.articleNumber || saved.name} angelegt.`;
      if (
        confirm(
          "Stammartikel wurde angelegt. Diesen Baustein jetzt damit verknüpfen und den Artikelstammpreis verwenden?",
        )
      ) {
        Object.assign(pendingCostItemComponent, {
          masterDataId: saved.id,
          sourceArticleNumber: saved.articleNumber,
          name: saved.name,
          calculationType: saved.calculationType,
          unit: saved.unit,
          costPrice: saved.costPrice,
          markupPercent: saved.markupPercent,
          priceMode: "master",
        });
        markDirty();
        render();
      }
    } catch (error) {
      costItemDialogError.textContent = error.message;
      costItemDialogError.hidden = false;
    }
  });
  tree.addEventListener(
    "toggle",
    (e) => {
      const d = e.target.closest?.("[data-node-id]");
      if (d !== e.target) return;
      d.open
        ? openNodes.add(d.dataset.nodeId)
        : openNodes.delete(d.dataset.nodeId);
    },
    true,
  );
  tree.addEventListener("input", (e) => {
    const row = e.target.closest("[data-node-id]"),
      node = row && find(row.dataset.nodeId);
    if (!node) return;
    if (e.target.matches("[data-trailing-text]")) {
      if (e.target.value === "") delete node.trailingText;
      else if (e.target.value !== "__snapshot") {
        const selected = library.trailingTexts.find(
          (item) => item.id === e.target.value,
        );
        if (selected)
          node.trailingText = {
            id: selected.id,
            code: selected.code,
            name: selected.name,
          };
      }
      markDirty();
      render();
      return;
    }
    const ci = e.target.closest("[data-component-index]");
    if (ci && e.target.dataset.componentField) {
      const c = node.components[+ci.dataset.componentIndex],
        field = e.target.dataset.componentField;
      c[field] = ["quantity", "costPrice", "markupPercent"].includes(field)
        ? e.target.value.replace(",", ".")
        : e.target.value;
      if (field === "calculationType") ci.dataset.componentType = c[field];
      if (field === "priceMode" && c.priceMode === "master") {
        const master = library.costItems.find(
          (item) => item.id === c.masterDataId,
        );
        if (master)
          Object.assign(c, {
            sourceArticleNumber: master.articleNumber,
            name: master.name,
            calculationType: master.calculationType,
            unit: master.unit,
            costPrice: master.costPrice,
            markupPercent: master.markupPercent,
          });
      }
      markDirty();
      if (field === "priceMode") render();
      return;
    }
    if (e.target.dataset.field) {
      const f = e.target.dataset.field;
      node[f] =
        f === "quantity" ? e.target.value.replace(",", ".") : e.target.value;
      markDirty();
      return;
    }
    if (e.target.matches("[data-manual-price]")) {
      node.manualUnitPrice = e.target.value.replace(",", ".");
      markDirty();
    }
  });
  tree.addEventListener("change", (e) => {
    const row = e.target.closest("[data-node-id]"),
      p = row && find(row.dataset.nodeId);
    if (!p) return;
    if (p.type === "title" && e.target.matches('[data-field="titleType"]')) {
      render();
      return;
    }
    if (p.type !== "position") return;
    if (e.target.matches("[data-work-status]")) {
      p.workStatus = CalightOfferStatus.normalized(e.target.value);
      markDirty();
      render();
      return;
    }
    if (e.target.matches("[data-price-method]")) {
      p.manualUnitPrice =
        e.target.value === "unit-price"
          ? p.calculation?.effectiveUnitPrice ||
            p.calculation?.calculatedUnitPrice ||
            "0.00"
          : null;
      markDirty();
      render();
      window.clearTimeout(calcTimer);
      calculate();
    }
  });
  tree.addEventListener(
    "click",
    (event) => {
      if (event.target.closest("[data-work-status]")) {
        event.stopPropagation();
        return;
      }
      const row = event.target.closest("[data-node-id]"),
        node = row && find(row.dataset.nodeId);
      if (!row || node?.type !== "position") return;
      if (event.target.closest("[data-take-service]")) {
        const service = exactLibraryEntry(
          library.services,
          serviceLabel,
          row.querySelector("[data-service-choice]").value,
        );
        if (!service) {
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }
        if (service.trailingText)
          node.trailingText = structuredClone(service.trailingText);
        else delete node.trailingText;
      }
      if (
        event.target.closest("[data-add-cost]") &&
        !exactLibraryEntry(
          library.costItems,
          costLabel,
          row.querySelector("[data-cost-choice]").value,
        )
      ) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    },
    true,
  );
  tree.addEventListener("click", async (e) => {
    if (e.target.closest(".offer-node-actions")) e.preventDefault();
    const row = e.target.closest("[data-node-id]"),
      node = row && find(row.dataset.nodeId),
      where = node && locate(node.id);
    if (!node || !where) return;
    if (e.target.closest("[data-add-child-title]"))
      return addNode(node.id, newTitle());
    if (e.target.closest("[data-add-child-position]"))
      return addNode(node.id, newPosition());
    if (e.target.closest("[data-add-child-text]"))
      return addNode(node.id, newText());
    if (e.target.closest("[data-copy]")) {
      const c = cloneNode(node);
      where.nodes.splice(where.index + 1, 0, c);
      openNodes.add(c.id);
      return structural(c.id);
    }
    if (e.target.closest("[data-remove]")) {
      where.nodes.splice(where.index, 1);
      return structural();
    }
    if (e.target.closest("[data-up]") && where.index > 0) {
      [where.nodes[where.index - 1], where.nodes[where.index]] = [
        where.nodes[where.index],
        where.nodes[where.index - 1],
      ];
      return structural();
    }
    if (
      e.target.closest("[data-down]") &&
      where.index < where.nodes.length - 1
    ) {
      [where.nodes[where.index + 1], where.nodes[where.index]] = [
        where.nodes[where.index],
        where.nodes[where.index + 1],
      ];
      return structural();
    }
    if (node.type !== "position") return;
    const ci = e.target.closest("[data-component-index]"),
      index = ci ? +ci.dataset.componentIndex : -1;
    if (e.target.closest("[data-component-remove]"))
      node.components.splice(index, 1);
    else if (e.target.closest("[data-component-copy]")) {
      const c = structuredClone(node.components[index]);
      c.id = uuid();
      node.components.splice(index + 1, 0, c);
    } else if (e.target.closest("[data-component-up]") && index > 0)
      [node.components[index - 1], node.components[index]] = [
        node.components[index],
        node.components[index - 1],
      ];
    else if (
      e.target.closest("[data-component-down]") &&
      index < node.components.length - 1
    )
      [node.components[index + 1], node.components[index]] = [
        node.components[index],
        node.components[index + 1],
      ];
    else if (e.target.closest("[data-save-cost-item]")) {
      openCostItemDialog(node.components[index]);
      return;
    } else if (e.target.closest("[data-add-manual]"))
      node.components.push({
        id: uuid(),
        masterDataId: null,
        sourceArticleNumber: null,
        name: "Neuer Baustein",
        calculationType: "material",
        unit: "Stk.",
        quantity: "1.000",
        costPrice: "0.0000",
        markupPercent: "0.00",
        priceMode: "manual",
      });
    else if (e.target.closest("[data-add-cost]")) {
      const value = row.querySelector("[data-cost-choice]").value,
        c = library.costItems.find((x) => costLabel(x) === value);
      if (!c) return;
      node.components.push({
        id: uuid(),
        masterDataId: c.id,
        sourceArticleNumber: c.articleNumber,
        name: c.name,
        calculationType: c.calculationType,
        unit: c.unit,
        quantity: "1.000",
        costPrice: c.costPrice,
        markupPercent: c.markupPercent,
        priceMode: "master",
      });
    } else if (e.target.closest("[data-take-service]")) {
      const value = row.querySelector("[data-service-choice]").value,
        s = library.services.find((x) => serviceLabel(x) === value);
      if (!s) return;
      node.shortText = s.shortText;
      node.longText = s.longText;
      node.unit = s.unit;
      node.sourceServiceId = s.id;
      node.sourceServiceNumber = s.articleNumber;
      node.components = s.components.map((c) => ({
        ...structuredClone(c),
        id: uuid(),
        priceMode: c.priceMode === "locked" ? "manual" : c.priceMode,
      }));
    } else if (e.target.closest("[data-preview-prices]")) {
      try {
        pricePreviews.set(
          node.id,
          await api("/api/offer-calculation/price-updates", {
            components: node.components,
          }),
        );
        render();
      } catch (x) {
        status.textContent = x.message;
      }
      return;
    } else if (e.target.closest("[data-cancel-prices]")) {
      pricePreviews.delete(node.id);
      render();
      return;
    } else if (e.target.closest("[data-apply-prices]")) {
      const p = pricePreviews.get(node.id);
      p.updates.forEach((u) =>
        Object.assign(node.components[u.index], {
          costPrice: u.costPrice,
          markupPercent: u.markupPercent,
          sourceArticleNumber: u.sourceArticleNumber,
        }),
      );
      pricePreviews.delete(node.id);
    } else if (e.target.closest("[data-save-master]")) {
      return saveMaster(node, row, false, null);
    } else if (e.target.closest("[data-save-new]")) {
      return saveMaster(node, row, true, null);
    } else if (e.target.closest("[data-update-existing]")) {
      return saveMaster(node, row, false, e.target.dataset.updateExisting);
    } else return;
    markDirty();
    render();
  });
  async function saveMaster(node, row, confirmDuplicate, updateExistingId) {
    try {
      const result = await api("/api/offer-calculation/save-position", {
        position: node,
        confirmDuplicate,
        updateExistingId,
      });
      const output = row.querySelector("[data-master-result]");
      if (result.saved) {
        output.innerHTML = `<div class="notice notice-success">Als Stammposition <strong>${esc(result.saved.articleNumber || "ohne Nummer")} · ${esc(result.saved.shortText)}</strong> gespeichert. <a href="/master-data">Positionsstamm öffnen</a></div>`;
        library.services = await api("/api/offer-calculation/library").then(
          (x) => x.services,
        );
        return;
      }
      output.innerHTML = `<div class="notice notice-error">Mögliche Dublette: ${result.duplicates.map((d) => `${esc(d.articleNumber || "ohne Nr.")} · ${esc(d.shortText)}`).join(", ")}<div class="form-actions"><button class="button button-light" type="button" data-save-new>Trotzdem neu anlegen</button>${result.duplicates.map((d) => `<button class="button button-light" type="button" data-update-existing="${d.id}">Bestehende Stammposition aktualisieren</button>`).join("")}</div></div>`;
    } catch (x) {
      status.textContent = x.message;
    }
  }
  function clearDragState() {
    draggedId = null;
    tree
      .querySelectorAll(".offer-drop-zone.active")
      .forEach((zone) => zone.classList.remove("active"));
  }
  tree.addEventListener("dragstart", (e) => {
    const h = e.target.closest("[data-drag-id]");
    if (!h) return;
    draggedId = h.dataset.dragId;
    e.dataTransfer.setData("text/plain", draggedId);
  });
  tree.addEventListener("dragover", (e) => {
    const z = e.target.closest("[data-drop-parent]");
    if (!z) return;
    e.preventDefault();
    tree
      .querySelectorAll(".offer-drop-zone.active")
      .forEach((zone) => zone !== z && zone.classList.remove("active"));
    z.classList.add("active");
  });
  tree.addEventListener("dragleave", (e) => {
    const z = e.target.closest("[data-drop-parent]");
    if (z && !z.contains(e.relatedTarget)) z.classList.remove("active");
  });
  tree.addEventListener("dragend", clearDragState);
  tree.addEventListener("drop", (e) => {
    const z = e.target.closest("[data-drop-parent]");
    if (!z || !draggedId) return;
    e.preventDefault();
    const moving = find(draggedId),
      targetParent = z.dataset.dropParent,
      source = locate(draggedId),
      target = container(targetParent);
    if (!moving || !source || !target || findIn(moving, targetParent)) {
      clearDragState();
      return;
    }
    source.nodes.splice(source.index, 1);
    let i = Number(z.dataset.dropIndex);
    if (source.nodes === target && source.index < i) i--;
    target.splice(i, 0, moving);
    clearDragState();
    structural();
  });
  function findIn(n, id) {
    return (
      !!id &&
      (n.id === id ||
        (n.type === "title" && n.children.some((c) => findIn(c, id))))
    );
  }
  adjustmentPanel.addEventListener("input", () => {
    syncAdjustment();
    showAdjustment();
    markDirty();
  });
  searchInput.addEventListener("input", runSearch);
  searchResults.addEventListener("click", (event) => {
    const button = event.target.closest("[data-search-target]");
    if (!button) return;
    const id = button.dataset.searchTarget;
    if (workStatusFilter) {
      workStatusFilter = "";
      statusFilter.value = "";
    }
    openAncestors(id);
    openNodes.add(id);
    render();
    tree
      .querySelector(`[data-node-id="${id}"]`)
      ?.scrollIntoView({ behavior: "smooth", block: "center" });
  });
  titleInput.addEventListener("input", markDirty);
  Object.values(termFields).forEach((field) =>
    field.addEventListener("input", () => {
      syncTerms();
      markDirty();
    }),
  );
  window.addEventListener("beforeunload", (e) => {
    if (dirty) {
      e.preventDefault();
      e.returnValue = "";
    }
  });
  saveButton.onclick = async () => {
    calculationRequest++;
    offer.title = titleInput.value.trim();
    offer.updatedAt = new Date().toISOString();
    status.textContent = "Speichert …";
    saveButton.disabled = true;
    try {
      const r = await fetch(
          `/api/offers/${encodeURIComponent(root.dataset.offerId)}`,
          {
            method: "PUT",
            credentials: "same-origin",
            headers: {
              "Content-Type": "application/json",
              "If-Match": `"${etag}"`,
              "X-CSRF-Token": root.dataset.csrf,
            },
            body: JSON.stringify(offer),
          },
        ),
        x = await responsePayload(r);
      if (!r.ok)
        throw new Error(
          (x.details || []).join(" ") || x.error || "Speichern fehlgeschlagen.",
        );
      etag = x.etag;
      dirty = false;
      status.textContent = "Gespeichert";
      scheduleCalculation();
    } catch (x) {
      status.textContent = x.message;
    } finally {
      saveButton.disabled = false;
    }
  };
  const loadDraft = async () => {
    const response = await fetch(
        `/api/offers/${encodeURIComponent(root.dataset.offerId)}`,
        { credentials: "same-origin", headers: { Accept: "application/json" } },
      ),
      payload = await responsePayload(response);
    if (!response.ok)
      throw new Error(
        (payload.details || []).join(" ") ||
          payload.error ||
          "Entwurf konnte nicht geladen werden.",
      );
    return payload;
  };
  Promise.all([loadDraft(), api("/api/offer-calculation/library")])
    .then(([draft, lib]) => {
      offer = draft.offer;
      etag = draft.etag;
      Object.assign(library, lib);
      normalize();
      titleInput.value = offer.title;
      showTerms();
      showAdjustment();
      render();
      saveButton.disabled = false;
      status.textContent = "";
      scheduleCalculation();
    })
    .catch((x) => (status.textContent = x.message));
})();
