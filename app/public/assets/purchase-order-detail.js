// Bestelldetail (vorgangsorientiert): kompakter Kopf, Bestellung mit Positionen (Bearbeiten
// über eigene Aktion), Auftragsbestätigungen, Lieferungen, Rechnungen/Gutschriften, Zahlungen,
// Dokumente und Verlauf. Jede Erfassung öffnet einen kleinen Dialog. Regeln, Berechnungen und
// Rechte prüft ausschließlich der Server; hier wird nur angezeigt und erfasst.
(() => {
  "use strict";

  const root = document.querySelector("#purchase-order-detail");
  if (!root) return;

  const { esc, api, format, notify, exact } = Calight;
  const shared = globalThis.CalightPurchaseOrders;
  const message = root.querySelector("[data-message]");
  const general = root.querySelector("[data-general]");
  const itemsBox = root.querySelector("[data-items]");
  const id = root.dataset.id;
  const csrf = root.dataset.csrf;
  const url = (suffix = "") => `/api/purchase-orders/${encodeURIComponent(id)}${suffix}`;
  let order = null;
  let reference = null;
  let items = [];
  let editing = false;
  let dirty = false;
  let busy = false;

  const { money, badge, today } = shared;
  // Einkaufspreise: bis zu 4 Nachkommastellen anzeigen (3,4567 €), mindestens Cent.
  const unitPriceFormat = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 4 });
  const unitPrice = (value) => (value === null || value === undefined || value === "" ? "–" : unitPriceFormat.format(Number(value)));
  const decimal = (value) => String(value ?? "").trim().replace(",", ".");
  const inputValue = (value) => (value === null || value === undefined ? "" : String(value).replace(".", ","));
  // Deaktivierte Beschaffung: keine Aktionen (Server sperrt zusätzlich).
  const can = (name) => !Calight.moduleReadOnly && Boolean(order?.permissions?.[name]);
  const costs = () => Boolean(reference?.canCosts);
  const muted = (text) => `<span class="muted">${esc(text)}</span>`;
  const documentLink = (document) => (document
    ? `<a class="purchase-doc-link" href="${url(`/documents/${encodeURIComponent(document.id)}`)}" target="_blank" rel="noopener" data-icon="file-down">${esc(document.name)}</a>` : "");
  const by = (entry) => `<small class="muted">${format.dateTime(entry.createdAt)} · ${esc(entry.createdByName || entry.uploadedByName || "")}</small>`;
  const setDirty = (value) => {
    dirty = value;
    root.querySelector("[data-dirty-hint]").hidden = !value;
  };

  async function run(button, operation) {
    if (busy) return;
    busy = true;
    if (button) button.disabled = true;
    try {
      await operation();
    } catch (exception) {
      notify(message, exception.message);
      message.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } finally {
      busy = false;
      if (button?.isConnected) button.disabled = false;
    }
  }
  const success = (text) => notify(message, text, "success");

  function adopt(next) {
    order = next;
    items = order.items.map((item) => ({ ...item }));
    setDirty(false);
    render();
  }

  // ---------------------------------------------------------------------------
  // Kopf
  // ---------------------------------------------------------------------------
  function renderHeader() {
    root.querySelector("[data-number]").textContent = order.orderNumber;
    root.querySelector("[data-current-crumb]").textContent = order.orderNumber;
    root.querySelector("[data-title]").textContent = order.supplierName;
    document.title = `Bestellung ${order.orderNumber} · ${order.supplierName}`;
    root.querySelector("[data-status-line]").innerHTML = [
      shared.originBadge(order),
      shared.statusBadges(order, { prefix: true }),
      order.revision > 1 ? `<span>Fassung ${order.revision}</span>` : "",
    ].join("");

    const externalRef = order.externalReference ? ` <small>Ref. ${esc(order.externalReference)}</small>` : "";
    // Lieferantennummer und eigene Kundennummer (aus dem Snapshot der Bestellung).
    const supplierNotes = [
      order.supplierNumber ? `Nr. ${esc(order.supplierNumber)}` : "",
      order.supplierSnapshot?.customerNumber ? `unsere Kd.-Nr. ${esc(order.supplierSnapshot.customerNumber)}` : "",
    ].filter(Boolean).join(" · ");
    const facts = [
      ["Lieferant", esc(order.supplierName) + (supplierNotes ? ` <small>${supplierNotes}</small>` : "")],
      ["Art", (order.origin === "external" ? "Externe Bestellung" : "Interne Bestellung") + externalRef],
      ["Auftrag / Kostenstelle", order.orderId ? `<a href="/orders/${encodeURIComponent(order.orderId)}">${esc(order.jobNumber)}</a> <small>${esc(order.jobTitle)}</small>`
        : order.costCenterId ? `${esc(order.costCenterNumber)} <small>${esc(order.costCenterName)} · Kostenstelle</small>` : muted("Ohne Zuordnung")],
      ["Bestelldatum", order.orderDate ? format.date(order.orderDate) : muted("Noch nicht bestellt")],
    ];
    if (costs()) {
      // Drei Beträge strikt getrennt.
      facts.push(
        ["Bestellwert netto", money(order.expectedNetTotal) + " <small>ursprünglich bestellt</small>"],
        ["Bestätigt netto", order.confirmedNetTotal !== null ? `${money(order.confirmedNetTotal)} <small>aktuelle Auftragsbestätigung</small>` : muted("–")],
        ["Ist-Kosten netto", order.actualNetTotal !== null ? `${money(order.actualNetTotal)} <small>${order.costSource === "direct" ? "direkt erfasst" : "aus Belegen"}${order.allocationExceeded ? " · Kostenverteilung prüfen" : ""}</small>` : muted("noch keine")],
      );
    }
    root.querySelector("[data-facts]").innerHTML = facts.map(([label, value]) => `<div><dt>${label}</dt><dd>${value}</dd></div>`).join("");

    const actions = [];
    const more = [];
    if (can("issue")) actions.push(`<button class="button" type="button" data-action="issue" data-icon="clipboard-check">${order.revision > 0 ? "Neue Fassung bestellen …" : "Bestellung abschließen …"}</button>`);
    if (order.origin === "internal") {
      actions.push(`<a class="button button-light" href="${url("/pdf")}" target="_blank" rel="noopener" data-icon="file-down">${order.status === "draft" ? "PDF-Vorschau" : "PDF"}</a>`);
    }
    if (can("newRevision")) more.push('<button type="button" data-action="revision" data-icon="square-pen">Neue Fassung erstellen …</button>');
    // Nie bestellte Entwürfe werden gelöscht, nicht storniert.
    if (can("cancel") && order.placed) more.push('<button type="button" data-action="cancel" data-icon="trash">Bestellung stornieren …</button>');
    if (can("delete")) more.push('<button type="button" data-action="delete" data-icon="trash">Entwurf löschen</button>');
    if (more.length) actions.push(`<details class="context-menu"><summary class="button button-light" data-icon="menu">Weitere Aktionen</summary><div class="context-menu-panel">${more.join("")}</div></details>`);
    root.querySelector("[data-actions]").innerHTML = actions.join("");
  }

  // ---------------------------------------------------------------------------
  // 1. Bestellung (Ansicht / Bearbeiten)
  // ---------------------------------------------------------------------------
  function itemsTable(list) {
    const priced = costs();
    if (!list.length) return `<p class="empty-state">${order.origin === "external" ? "Keine Positionen erfasst – bei externen Bestellungen nicht erforderlich." : "Noch keine Positionen."}</p>`;
    const totalCents = list.reduce((sum, item) => (item.lineTotal ? sum + exact.toUnits(String(item.lineTotal), 2) : sum), 0n);
    const total = priced && list.some((item) => item.lineTotal)
      ? `<tfoot><tr><td colspan="6">Summe netto</td><td class="m-num">${format.money(exact.fromUnits(totalCents, 2))}</td></tr></tfoot>` : "";
    return `<div class="measurement-table-wrap"><table class="measurement-totals purchase-items-table"><thead><tr><th>Pos.</th><th class="m-num">Menge</th><th>Einheit</th><th>Artikel-Nr.</th><th>Bezeichnung</th>${priced ? '<th class="m-num">Einzelpreis</th><th class="m-num">Gesamt</th>' : ""}</tr></thead><tbody>${list.map((item) => `
      <tr><td>${item.position}</td><td class="m-num">${item.quantity !== null && item.quantity !== "" ? format.number(item.quantity, 3) : "–"}</td><td>${esc(item.unit)}</td><td>${esc(item.articleNumber)}</td>
        <td>${esc(item.description)}${item.notes ? `<small class="muted"> · ${esc(item.notes)}</small>` : ""}</td>
        ${priced ? `<td class="m-num">${unitPrice(item.unitPrice)}</td><td class="m-num">${money(item.lineTotal)}</td>` : ""}</tr>`).join("")}</tbody>${total}</table></div>`;
  }

  function renderOrderSection() {
    const draft = order.status === "draft";
    root.querySelector("[data-order-hint]").textContent = draft
      ? "Entwurf – Positionen erfassen, dann „Bestellung abschließen“."
      : (order.origin === "internal" ? "Lieferant und Positionen sind mit dem Bestellen festgeschrieben; Änderungen über „Neue Fassung“." : "Extern ausgelöste Bestellung.");
    root.querySelector("[data-order-actions]").innerHTML = can("edit") && !editing
      ? '<button class="button button-light button-small" type="button" data-edit-start data-icon="square-pen">Bearbeiten</button>' : "";
    root.querySelector("[data-order-view]").hidden = editing;
    root.querySelector("[data-order-edit]").hidden = !editing;
    if (editing) return;
    const snapshot = order.supplierSnapshot || {};
    const address = [[snapshot.street, snapshot.houseNumber].filter(Boolean).join(" "), [snapshot.postalCode, snapshot.city].filter(Boolean).join(" "), snapshot.country].filter(Boolean).join(", ");
    const facts = [
      ["Bezeichnung", order.title ? esc(order.title) : muted("–")],
      ["Gewünschter Liefertermin", order.expectedDeliveryDate ? format.date(order.expectedDeliveryDate) : muted("–")],
      ["Lieferanschrift", order.deliveryAddress ? esc(order.deliveryAddress).replace(/\n/g, "<br>") : muted("Firmenanschrift")],
    ];
    if (address || snapshot.customerNumber) facts.push(["Lieferant wie bestellt", `${esc(address || "–")}${snapshot.customerNumber ? ` <small>Unsere Kundennr. ${esc(snapshot.customerNumber)}</small>` : ""}`]);
    if (order.notes) facts.push(["Bemerkung", esc(order.notes).replace(/\n/g, "<br>")]);
    const requests = order.materialRequests.length
      ? `<p class="muted purchase-request-links">Aus früheren Materialanforderungen übernommen: ${order.materialRequests.map((request) => esc(request.description)).join(" · ")}</p>` : "";
    root.querySelector("[data-order-view]").innerHTML = `<dl class="project-facts purchase-order-facts">${facts.map(([label, value]) => `<div><dt>${label}</dt><dd>${value}</dd></div>`).join("")}</dl>${itemsTable(order.items)}${requests}`;
  }

  function fillGeneral() {
    const f = general.elements;
    const supplierKnown = reference.suppliers.some((supplier) => supplier.id === order.supplierId);
    f.supplierId.innerHTML = (supplierKnown ? "" : `<option value="${esc(order.supplierId)}">${esc(order.supplierName)}</option>`)
      + reference.suppliers.map((supplier) => `<option value="${esc(supplier.id)}">${esc(shared.supplierLabel(supplier))}</option>`).join("");
    // Gemeinsames Feld „Auftrag / Kostenstelle“ (Wert: Typ und ID).
    f.orderId.innerHTML = Calight.costTargetOptions(reference.costTargets, { orderId: order.orderId || "", costCenterId: order.costCenterId || "" });
    f.supplierId.value = order.supplierId;
    f.orderId.value = order.orderId ? `order:${order.orderId}` : order.costCenterId ? `cost_center:${order.costCenterId}` : "";
    f.title.value = order.title || "";
    f.externalReference.value = order.externalReference || "";
    f.orderDate.value = order.orderDate || "";
    f.expectedDeliveryDate.value = order.expectedDeliveryDate || "";
    f.deliveryAddress.value = order.deliveryAddress || "";
    f.notes.value = order.notes || "";
    const draft = order.status === "draft";
    for (const element of general.elements) element.disabled = false;
    // Lieferant und Lieferanschrift gehören zum Bestellinhalt: nur im Entwurf änderbar.
    f.supplierId.disabled = !draft;
    f.deliveryAddress.disabled = !draft;
    // Bestelldatum: bei internen Bestellungen setzt „Bestellen“ das Datum.
    f.orderDate.disabled = order.origin === "internal";
    const expected = root.querySelector("[data-expected-field]");
    expected.hidden = !costs();
    if (costs()) {
      f.expectedNetTotal.value = inputValue(order.expectedNetTotal);
      const derived = order.origin === "internal";
      f.expectedNetTotal.disabled = derived;
      f.expectedNetTotal.title = derived ? "Ergibt sich aus den Positionspreisen." : "";
    }
    if (!general.dataset.enhanced) {
      general.dataset.enhanced = "1";
      shared.supplierPicker(f.supplierId, reference.suppliers);
      Calight.searchableSelect(f.orderId, { placeholder: "Auftrag oder Kostenstelle: Nummer oder Bezeichnung …" });
    }
    for (const select of [f.supplierId, f.orderId]) {
      const box = select.nextElementSibling;
      if (!box?.classList.contains("suggest-select")) continue;
      const input = box.querySelector("input");
      input.value = select.selectedOptions[0]?.value ? select.selectedOptions[0].textContent : "";
      input.disabled = select.disabled;
      box.querySelector(".suggest-clear").hidden = select.disabled || input.value === "";
    }
    // Schnellanlage nur, solange der Lieferant änderbar ist.
    const create = general.querySelector(".purchase-supplier-create");
    if (create) create.hidden = f.supplierId.disabled;
    // Externe Bestellungen ohne Positionen brauchen keinen Positionseditor.
    root.querySelector("[data-items-editor]").hidden = !can("editItems") && !order.items.length;
  }
  general.addEventListener("input", () => setDirty(true));
  general.addEventListener("change", () => setDirty(true));

  // Positionseditor (nur im Entwurf änderbar, sonst als Tabelle).
  const lineCents = (item) => (exact.DECIMAL_PATTERN.test(decimal(item.quantity)) && exact.DECIMAL_PATTERN.test(decimal(item.unitPrice))
    ? exact.lineCents(exact.toUnits(decimal(item.quantity), 3), decimal(item.unitPrice)) : null);

  function renderItems() {
    const editable = can("editItems");
    root.querySelector("[data-add-item]").hidden = !editable;
    root.querySelector("[data-catalog-box]").hidden = !editable;
    const priced = costs();
    if (!editable) {
      itemsBox.innerHTML = `<p class="muted">Positionen sind festgeschrieben; Änderungen über „Neue Fassung“.</p>${itemsTable(items)}`;
    } else if (!items.length) {
      itemsBox.innerHTML = '<p class="empty-state">Noch keine Positionen. Artikel aus dem Stamm suchen oder eine Position hinzufügen.</p>';
    } else {
      itemsBox.innerHTML = items.map((item, index) => `
        <div class="work-report-row purchase-item-row${priced ? "" : " no-prices"}" data-index="${index}">
          <label><span class="field-label">Menge</span><input data-field="quantity" inputmode="decimal" value="${esc(inputValue(item.quantity))}" placeholder="0"></label>
          <label><span class="field-label">Einheit</span><input data-field="unit" maxlength="20" list="purchase-units" value="${esc(item.unit)}"></label>
          <label><span class="field-label">Artikel-Nr.</span><input data-field="articleNumber" maxlength="100" value="${esc(item.articleNumber)}"></label>
          <label class="work-report-wide"><span class="field-label">Bezeichnung</span><input data-field="description" maxlength="500" value="${esc(item.description)}"></label>
          ${priced ? `<label><span class="field-label">Einzelpreis €</span><input data-field="unitPrice" inputmode="decimal" value="${esc(inputValue(item.unitPrice))}" placeholder="optional"></label>
          <div class="work-report-line-total"><span class="field-label">Gesamt</span><strong data-line-total>${lineCents(item) === null ? "–" : format.money(exact.fromUnits(lineCents(item), 2))}</strong></div>` : ""}
          <label class="purchase-item-notes"><span class="field-label">Bemerkung</span><input data-field="notes" maxlength="1000" value="${esc(item.notes)}"></label>
          <div class="work-report-row-actions">
            <button class="icon-button" type="button" data-move="-1" data-icon="arrow-up" title="Nach oben" aria-label="Position nach oben"${index === 0 ? " disabled" : ""}></button>
            <button class="icon-button" type="button" data-move="1" data-icon="arrow-down" title="Nach unten" aria-label="Position nach unten"${index === items.length - 1 ? " disabled" : ""}></button>
            <button class="icon-button icon-button-danger" type="button" data-remove data-icon="trash" title="Position entfernen" aria-label="Position entfernen"></button>
          </div>
        </div>`).join("") + '<datalist id="purchase-units"><option value="Stk."><option value="m"><option value="kg"><option value="Pak."><option value="Rolle"><option value="psch"></datalist>';
    }
    renderItemsTotal();
  }

  function renderItemsTotal() {
    const priced = items.filter((item) => lineCents(item) !== null);
    root.querySelector("[data-items-total-row]").hidden = !can("editItems") || !costs() || !priced.length;
    root.querySelector("[data-items-total]").textContent = format.money(exact.fromUnits(priced.reduce((sum, item) => sum + lineCents(item), 0n), 2));
  }

  itemsBox.addEventListener("input", (event) => {
    const name = event.target.dataset.field;
    const row = event.target.closest("[data-index]");
    if (!name || !row) return;
    const item = items[Number(row.dataset.index)];
    item[name] = event.target.value;
    if (name === "quantity" || name === "unitPrice") {
      const cents = lineCents(item);
      const cell = row.querySelector("[data-line-total]");
      if (cell) cell.textContent = cents === null ? "–" : format.money(exact.fromUnits(cents, 2));
      renderItemsTotal();
    }
    setDirty(true);
  });
  itemsBox.addEventListener("click", (event) => {
    const row = event.target.closest("[data-index]");
    if (!row) return;
    const index = Number(row.dataset.index);
    const move = event.target.closest("[data-move]");
    if (move) {
      const target = index + Number(move.dataset.move);
      [items[index], items[target]] = [items[target], items[index]];
    } else if (event.target.closest("[data-remove]")) {
      items.splice(index, 1);
    } else {
      return;
    }
    items.forEach((item, position) => { item.position = position + 1; });
    setDirty(true);
    renderItems();
  });
  const addItem = (values) => {
    items.push({ id: null, position: items.length + 1, costItemId: null, articleNumber: "", description: "", quantity: "", unit: "", unitPrice: "", lineTotal: null, notes: "", ...values });
    setDirty(true);
    renderItems();
    itemsBox.querySelector(".purchase-item-row:last-of-type [data-field='quantity']")?.focus();
  };
  root.querySelector("[data-add-item]").addEventListener("click", () => addItem({}));
  Calight.suggest({
    input: root.querySelector("[data-catalog-search]"),
    list: root.querySelector("[data-catalog-results]"),
    clear: root.querySelector("[data-catalog-clear]"),
    load: (query) => api(`/api/purchase-orders/catalog?q=${encodeURIComponent(query)}&supplier=${encodeURIComponent(general.elements.supplierId.value)}`),
    render: (item) => `<span>${esc(item.name)} <small class="muted">${esc(item.supplierArticleNumber || item.articleNumber || "")}${item.fromSupplier ? " · Lieferant" : ""}</small></span>${item.costPrice !== null ? `<span class="work-report-catalog-price">${unitPrice(item.costPrice)} / ${esc(item.unit)}</span>` : ""}`,
    onError: (exception) => notify(message, exception.message),
    choose: (item) => addItem({ costItemId: item.id, articleNumber: item.supplierArticleNumber || item.articleNumber || "", description: item.name, unit: item.unit, unitPrice: item.costPrice ?? "" }),
  });

  function startEditing() {
    editing = true;
    render();
    general.elements.title.focus();
  }
  async function stopEditing() {
    if (dirty && !(await CalightModal.confirm({ title: "Änderungen verwerfen", content: "Die ungespeicherten Änderungen an der Bestellung verwerfen?", confirmLabel: "Verwerfen", danger: true }))) return;
    editing = false;
    adopt(order);
  }
  root.querySelector("[data-order-section]").addEventListener("click", (event) => {
    if (event.target.closest("[data-edit-start]")) {
      startEditing();
    } else if (event.target.closest("[data-edit-cancel]")) {
      stopEditing();
    } else if (event.target.closest("[data-edit-save]")) {
      const button = event.target.closest("[data-edit-save]");
      run(button, async () => {
        if (!editing) return;
        await save();
        editing = false;
        render();
      });
    }
  });

  function payload() {
    const f = general.elements;
    const body = {
      version: order.version, supplierId: f.supplierId.value, title: f.title.value.trim(), externalReference: f.externalReference.value.trim(),
      orderDate: f.orderDate.value, expectedDeliveryDate: f.expectedDeliveryDate.value, ...Calight.parseCostTarget(f.orderId.value),
      deliveryAddress: f.deliveryAddress.value.trim(), notes: f.notes.value.trim(),
    };
    if (costs() && !f.expectedNetTotal.disabled) body.expectedNetTotal = decimal(f.expectedNetTotal.value);
    if (can("editItems")) {
      body.items = items.map((item) => ({
        costItemId: item.costItemId || "", articleNumber: (item.articleNumber || "").trim(), description: (item.description || "").trim(),
        quantity: decimal(item.quantity), unit: (item.unit || "").trim(), notes: (item.notes || "").trim(),
        ...(costs() ? { unitPrice: decimal(item.unitPrice) } : {}),
      }));
    }
    return body;
  }

  async function save({ silent = false } = {}) {
    if (!general.elements.supplierId.value) throw new Error("Bitte einen Lieferanten auswählen.");
    adopt(await api(url(), { method: "PUT", body: payload() }));
    if (!silent) success("Bestellung gespeichert.");
    return true;
  }

  // ---------------------------------------------------------------------------
  // 2.–5. Vorgänge
  // ---------------------------------------------------------------------------
  const eventRow = (main, side) => `<li class="purchase-event"><div class="purchase-event-main">${main}</div><div class="purchase-event-side">${side}</div></li>`;
  const eventList = (rows, empty) => (rows.length ? `<ul class="purchase-events">${rows.join("")}</ul>` : `<p class="empty-state">${esc(empty)}</p>`);
  const notPlaced = () => (order.placed ? "" : "Verfügbar, sobald die Bestellung bestellt ist.");
  const meta = (parts) => {
    const text = parts.filter(Boolean).join(" · ");
    return text ? `<div class="purchase-event-meta">${text}</div>` : "";
  };

  function renderConfirmations() {
    root.querySelector('[data-event-actions="confirmations"]').innerHTML = can("events")
      ? '<button class="button button-light button-small" type="button" data-dialog="confirmation" data-icon="plus">AB erfassen</button>' : "";
    root.querySelector('[data-event-list="confirmations"]').innerHTML = eventList(order.confirmations.map((confirmation) => eventRow(
      `<span><strong>${confirmation.number ? esc(confirmation.number) : "Auftragsbestätigung"}</strong> vom ${format.date(confirmation.date)}</span>
        ${confirmation.current ? '<span class="status-badge status-po-confirmation-current">Aktuell</span>' : '<span class="status-badge status-po-confirmation-previous">Frühere Fassung</span>'}
        ${meta([confirmation.confirmedDeliveryDate ? `Liefertermin ${format.date(confirmation.confirmedDeliveryDate)}` : "", confirmation.notes ? esc(confirmation.notes) : "", documentLink(confirmation.document)])}
        ${by(confirmation)}`,
      `${costs() ? `<strong class="purchase-amount">${confirmation.confirmedNetTotal !== null ? money(confirmation.confirmedNetTotal) : "–"}</strong>` : ""}
        ${!confirmation.current && can("events") ? `<button class="button button-light button-small" type="button" data-current-confirmation="${esc(confirmation.id)}">Als aktuell markieren</button>` : ""}`,
    )), notPlaced() || "Noch keine Auftragsbestätigung erfasst.");
  }

  function renderDeliveries() {
    root.querySelector('[data-event-actions="deliveries"]').innerHTML = can("events")
      ? '<button class="button button-light button-small" type="button" data-dialog="delivery" data-icon="plus">Lieferung erfassen</button>' : "";
    root.querySelector('[data-event-list="deliveries"]').innerHTML = eventList(order.deliveries.map((delivery) => eventRow(
      `<span><strong>${esc(delivery.kindLabel)}</strong> vom ${format.date(delivery.date)}${delivery.deliveryNoteNumber ? ` · LS ${esc(delivery.deliveryNoteNumber)}` : ""}</span>
        ${meta([delivery.notes ? esc(delivery.notes) : "", documentLink(delivery.document)])}${by(delivery)}`,
      `<span class="muted">danach</span> ${badge("delivery", delivery.resultingStatus, delivery.resultingLabel)}`,
    )), notPlaced() || "Noch keine Lieferung erfasst.");
  }

  function renderInvoices() {
    const section = root.querySelector('[data-event-section="invoices"]');
    section.hidden = !costs();
    if (section.hidden) return;
    const actions = [];
    if (can("costs")) {
      actions.push('<button class="button button-light button-small" type="button" data-dialog="invoice" data-icon="plus">Rechnung erfassen</button>');
      actions.push('<button class="button button-light button-small" type="button" data-dialog="credit" data-icon="plus">Gutschrift erfassen</button>');
      const more = [];
      if (!order.invoices.length) more.push(`<button type="button" data-dialog="direct" data-icon="square-pen">${order.directActualNetTotal !== null ? "Direkte Ist-Kosten ändern …" : "Ist-Kosten direkt erfassen …"}</button>`);
      if (order.actualNetTotal !== null) more.push('<button type="button" data-dialog="allocations" data-icon="square-pen">Kostenverteilung …</button>');
      if (more.length) actions.push(`<details class="context-menu"><summary class="button button-light button-small" data-icon="menu">Weitere Aktionen</summary><div class="context-menu-panel">${more.join("")}</div></details>`);
    }
    root.querySelector('[data-event-actions="invoices"]').innerHTML = actions.join("");
    // Beträge stehen zentral im Kopf; hier nur Belege, Wirkung und Hinweise.
    const exceeded = order.allocationExceeded
      ? `<div class="notice notice-warning purchase-allocation-warning"><span>Die Kostenverteilung (${money(order.allocatedTotal)}) übersteigt die aktuellen Ist-Kosten (${money(order.actualNetTotal)}). Sie gilt bis zur Korrektur als ungültig.</span>${can("costs") ? '<button class="button button-light button-small" type="button" data-dialog="allocations" data-icon="square-pen">Verteilung korrigieren …</button>' : ""}</div>` : "";
    const rows = order.invoices.map((invoice) => {
      const remaining = invoice.remaining !== null ? Number(invoice.remaining) : null;
      const reversible = can("costs") && invoice.kind !== "reversal" && remaining !== null && remaining > 0;
      const fullyReversed = invoice.kind !== "reversal" && remaining === 0;
      const related = invoice.relatedNumber !== null ? ` · zu ${esc(invoice.relatedNumber || "Beleg")}` : "";
      return eventRow(
        `<span><strong>${esc(invoice.kindLabel)}</strong>${invoice.invoiceNumber ? ` ${esc(invoice.invoiceNumber)}` : ""} vom ${format.date(invoice.invoiceDate)}${related}</span>
          ${fullyReversed ? '<span class="status-badge status-po-status-cancelled">Storniert</span>' : ""}
          ${meta([
            invoice.finalModeLabel ? esc(invoice.finalModeLabel) : "",
            `netto ${money(invoice.netAmount)}${invoice.grossAmount !== null ? ` · brutto ${money(invoice.grossAmount)}` : ""}`,
            invoice.notes ? esc(invoice.notes) : "",
            documentLink(invoice.document),
          ])}${by(invoice)}`,
        // Stornobelege wirken über ihren Originalbeleg; ihr Gegenwert steht nur informativ in Klammern.
        `<span class="muted">Kostenwirkung</span> <strong class="purchase-amount${invoice.effect !== null && Number(invoice.effect) < 0 ? " is-negative" : ""}">${invoice.kind === "reversal" ? `(${money(invoice.effect)})` : money(invoice.effect)}</strong>
          ${reversible ? `<button class="button button-light button-small" type="button" data-reverse-invoice="${esc(invoice.id)}" data-icon="copy">Stornieren …</button>` : ""}`,
      );
    });
    const direct = order.directActualNetTotal !== null
      ? `<p class="notice notice-warning">Ist-Kosten direkt erfasst: <strong>${money(order.directActualNetTotal)}</strong>. Beim ersten Beleg werden sie nach ausdrücklicher Bestätigung durch die Belege ersetzt.</p>` : "";
    const totals = order.invoices.length
      ? `<p class="purchase-invoice-totals">Summe der Kostenwirkung: <strong>${money(order.invoicedNetTotal)}</strong>${order.invoicedGrossTotal !== null ? ` · brutto ${money(order.invoicedGrossTotal)}` : ""}</p>` : "";
    let allocations = "";
    if (order.allocations?.length) {
      allocations = `<div class="purchase-allocation-summary"><span class="field-label">Kostenverteilung</span><ul class="work-report-readonly">${order.allocations.map((allocation) => `<li>${allocation.orderId ? `${esc(allocation.orderNumber)} · ${esc(allocation.orderTitle)}` : `${esc(allocation.costCenterNumber)} · ${esc(allocation.costCenterName)} <small class="muted">Kostenstelle</small>`}: <strong>${money(allocation.amount)}</strong></li>`).join("")}</ul>
          <small class="muted">${order.allocationExceeded ? `Überverteilt: ${money(String(order.unallocatedTotal).replace("-", ""))}` : `Nicht verteilt: ${money(order.unallocatedTotal)}`}</small></div>`;
    } else if (order.actualNetTotal !== null) {
      allocations = `<p class="muted">Die Ist-Kosten gehen vollständig an ${order.orderId ? `Auftrag ${esc(order.jobNumber)}` : order.costCenterId ? `Kostenstelle ${esc(order.costCenterNumber)}` : "kein Kostenziel (noch keine Zuordnung)"}.</p>`;
    }
    root.querySelector('[data-event-list="invoices"]').innerHTML = exceeded + direct
      + eventList(rows, notPlaced() || "Noch keine Belege erfasst.") + totals + allocations;
  }

  function renderPayments() {
    root.querySelector('[data-event-actions="payments"]').innerHTML = can("costs")
      ? '<button class="button button-light button-small" type="button" data-dialog="payment" data-icon="plus">Zahlung erfassen</button>' : "";
    const rows = order.payments.map((payment) => eventRow(
      `<span><strong>${esc(payment.kindLabel)}</strong> vom ${format.date(payment.date)}</span>${meta([payment.notes ? esc(payment.notes) : ""])}${by(payment)}`,
      `${costs() && payment.amount !== null ? `<strong class="purchase-amount${payment.kind === "refund" ? " is-negative" : ""}">${payment.kind === "refund" ? "−" : ""}${money(payment.amount)}</strong>` : ""}
        <span class="muted">danach</span> ${badge("payment", payment.resultingStatus, payment.resultingLabel)}`,
    ));
    const totals = costs() && order.payments.length
      ? `<p class="purchase-invoice-totals">Gezahlt abzüglich Rückerstattungen: <strong>${money(order.paidTotal)}</strong>${order.openGrossTotal !== null ? ` · offen laut Belegen (brutto): <strong>${money(order.openGrossTotal)}</strong>` : ""}</p>` : "";
    root.querySelector('[data-event-list="payments"]').innerHTML = eventList(rows, notPlaced() || "Noch keine Zahlung vermerkt.") + totals;
  }

  // ---------------------------------------------------------------------------
  // 6. Dokumente und Verlauf
  // ---------------------------------------------------------------------------
  function renderArchive() {
    root.querySelector("[data-archive-hint]").textContent = `${order.documents.length} Dokument${order.documents.length === 1 ? "" : "e"} · ${order.history.length} Verlaufseinträge`;
    root.querySelector("[data-document-actions]").innerHTML = can("documents")
      ? '<button class="button button-light button-small" type="button" data-dialog="document" data-icon="file-up">Dokument hochladen</button>' : "";
    root.querySelector("[data-documents]").innerHTML = order.documents.length
      ? `<ul class="purchase-documents">${order.documents.map((document) => `
        <li>${documentLink(document)}
          <span class="status-badge">${esc(document.kindLabel)}${document.revision ? ` · Fassung ${document.revision}` : ""}</span>
          <small class="muted">${format.dateTime(document.createdAt)} · ${esc(document.uploadedByName)}</small>
          ${document.canDelete ? `<button class="icon-button icon-button-danger" type="button" data-delete-document="${esc(document.id)}" data-icon="trash" title="Dokument entfernen" aria-label="Dokument ${esc(document.name)} entfernen"></button>` : ""}</li>`).join("")}</ul>`
      : '<p class="muted">Noch keine Dokumente.</p>';
    root.querySelector("[data-history]").innerHTML = order.history.length
      ? `<div class="order-status-history">${order.history.map((entry) => `
        <div class="order-status-entry"><time>${format.dateTime(entry.createdAt)}</time><span>${esc(entry.label)}${entry.note ? `<small class="muted"> · ${esc(entry.note)}</small>` : ""}</span><span class="muted">${esc(entry.userName)}</span></div>`).join("")}</div>`
      : '<p class="muted">Noch keine Einträge.</p>';
  }

  function render() {
    renderHeader();
    renderOrderSection();
    if (editing) {
      fillGeneral();
      renderItems();
    }
    renderConfirmations();
    renderDeliveries();
    renderInvoices();
    renderPayments();
    renderArchive();
    // Abschnittsnummern fortlaufend (ohne Kostenrecht entfällt der Belegbereich).
    let number = 0;
    for (const element of root.querySelectorAll("[data-section-number]")) {
      if (!element.closest("section, details").hidden) element.textContent = String(++number);
    }
  }

  // ---------------------------------------------------------------------------
  // Dialoge (gemeinsam mit der Übersicht, siehe purchase-order-dialogs.js)
  // ---------------------------------------------------------------------------
  const dialogs = CalightPurchaseDialogs.create({
    getOrder: () => order,
    getReference: () => reference,
    csrf,
    onSaved: (next, text) => {
      adopt(next);
      success(text);
    },
  });

  root.addEventListener("click", (event) => {
    const opener = event.target.closest("[data-dialog]");
    if (opener) {
      opener.closest("details.context-menu")?.removeAttribute("open");
      dialogs[opener.dataset.dialog]?.();
      return;
    }
    const reverse = event.target.closest("[data-reverse-invoice]");
    if (reverse) {
      dialogs.invoice("reversal", order.invoices.find((invoice) => invoice.id === reverse.dataset.reverseInvoice));
      return;
    }
    const current = event.target.closest("[data-current-confirmation]");
    if (current) {
      run(current, async () => {
        adopt(await api(url(`/confirmations/${encodeURIComponent(current.dataset.currentConfirmation)}/current`), { method: "POST", body: { version: order.version } }));
        success("Auftragsbestätigung als aktuell markiert.");
      });
      return;
    }
    const remove = event.target.closest("[data-delete-document]");
    if (remove) {
      run(remove, async () => {
        if (!(await CalightModal.confirm({ title: "Dokument entfernen", content: "Das Dokument wird endgültig gelöscht.", confirmLabel: "Entfernen", danger: true }))) return;
        adopt(await api(url(`/documents/${encodeURIComponent(remove.dataset.deleteDocument)}`), { method: "DELETE", body: {} }));
        success("Dokument entfernt.");
      });
    }
  });

  // ---------------------------------------------------------------------------
  // Seitenaktionen
  // ---------------------------------------------------------------------------
  const noteDialog = (text, label) => {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.innerHTML = `<p>${esc(text)}</p><label><span class="field-label">${esc(label)}</span><textarea name="note" rows="2" maxlength="500"></textarea></label>`;
    return form;
  };

  const handlers = {
    async issue() {
      if (editing && dirty) await save({ silent: true });
      const ok = await CalightModal.confirm({
        title: order.revision > 0 ? "Neue Fassung bestellen" : "Bestellung ausgeben",
        content: `Die Bestellung ${order.orderNumber} an ${order.supplierName} wird verbindlich: Lieferant und Positionen werden festgeschrieben und das Bestell-PDF${order.revision > 0 ? ` (Fassung ${order.revision + 1})` : ""} archiviert. Den Versand an den Lieferanten übernehmen Sie wie gewohnt.`,
        confirmLabel: "Bestellung abschließen",
      });
      if (!ok) return;
      editing = false;
      adopt(await api(url("/issue"), { method: "POST", body: { version: order.version } }));
      success("Bestellung ausgegeben. Das PDF steht unter „PDF“ und bei den Dokumenten bereit.");
    },
    async revision() {
      const form = noteDialog("Die Bestellung wird für Änderungen geöffnet. Bereits ausgegebene PDFs bleiben unverändert archiviert; nach dem erneuten Bestellen entsteht eine neue Fassung.", "Grund der Änderung (optional)");
      const ok = await CalightModal.open({ title: "Neue Fassung erstellen", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Neue Fassung erstellen", value: true, primary: true }] });
      if (!ok) return;
      adopt(await api(url("/revision"), { method: "POST", body: { version: order.version, note: form.elements.note.value.trim() } }));
      startEditing();
      success("Neue Fassung geöffnet.");
    },
    async cancel() {
      const form = noteDialog("Die Bestellung wird storniert. Lieferungen, Belege, Zahlungen, Dokumente und bereits entstandene Ist-Kosten bleiben erhalten; Rücksendungen und Gutschriften können weiter erfasst werden.", "Stornierungsgrund (optional)");
      const ok = await CalightModal.open({ title: "Bestellung stornieren", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Stornieren", value: true, danger: true }] });
      if (!ok) return;
      editing = false;
      adopt(await api(url("/cancel"), { method: "POST", body: { version: order.version, note: form.elements.note.value.trim() } }));
      success("Bestellung storniert.");
    },
    async delete() {
      if (!(await CalightModal.confirm({ title: "Entwurf löschen", content: `Der Entwurf ${order.orderNumber} wird mit allen Positionen und hochgeladenen Dokumenten gelöscht.`, confirmLabel: "Löschen", danger: true }))) return;
      await api(url(), { method: "DELETE", body: { version: order.version } });
      dirty = false;
      location.assign("/purchase-orders");
    },
  };

  root.querySelector("[data-actions]").addEventListener("click", (event) => {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    button.closest("details.context-menu")?.removeAttribute("open");
    run(button, handlers[button.dataset.action]);
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  async function load() {
    const [current, ref] = await Promise.all([api(url()), api("/api/purchase-orders/reference-data")]);
    reference = ref;
    // Neue, noch leere interne Entwürfe direkt im Bearbeitungsmodus öffnen.
    editing = current.status === "draft" && current.permissions.edit && !current.items.length;
    adopt(current);
    try {
      const notice = sessionStorage.getItem("calight-purchase-notice");
      if (notice) {
        sessionStorage.removeItem("calight-purchase-notice");
        notify(message, notice, "warning");
      }
    } catch {
      // Ohne Speicher entfällt nur der Hinweis.
    }
  }
  load().catch((exception) => notify(message, exception.message));
})();
