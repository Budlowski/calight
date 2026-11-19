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
  const can = (name) => Boolean(order?.permissions?.[name]);
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
    const facts = [
      ["Lieferant", esc(order.supplierName) + (order.supplierNumber ? ` <small>Nr. ${esc(order.supplierNumber)}</small>` : "")],
      ["Art", (order.origin === "external" ? "Externe Bestellung" : "Interne Bestellung") + externalRef],
      ["Auftrag", order.orderId ? `<a href="/orders/${encodeURIComponent(order.orderId)}">${esc(order.jobNumber)}</a> <small>${esc(order.jobTitle)}</small>` : muted("Ohne Auftrag")],
      ["Bestelldatum", order.orderDate ? format.date(order.orderDate) : muted("Noch nicht bestellt")],
    ];
    if (costs()) {
      // Drei Beträge strikt getrennt.
      facts.push(
        ["Bestellwert netto", money(order.expectedNetTotal) + " <small>ursprünglich bestellt</small>"],
        ["Bestätigt netto", order.confirmedNetTotal !== null ? `${money(order.confirmedNetTotal)} <small>aktuelle Auftragsbestätigung</small>` : muted("–")],
        ["Ist-Kosten netto", order.actualNetTotal !== null ? `${money(order.actualNetTotal)} <small>${order.costSource === "direct" ? "direkt erfasst" : "aus Belegen"}</small>` : muted("noch keine")],
      );
    }
    root.querySelector("[data-facts]").innerHTML = facts.map(([label, value]) => `<div><dt>${label}</dt><dd>${value}</dd></div>`).join("");

    const actions = [];
    const more = [];
    if (can("issue")) actions.push(`<button class="button" type="button" data-action="issue" data-icon="clipboard-check">${order.revision > 0 ? "Neue Fassung bestellen …" : "Bestellen …"}</button>`);
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
      ? "Entwurf – Positionen erfassen, dann „Bestellen“."
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
    f.orderId.innerHTML = '<option value="">Ohne Auftrag</option>' + reference.orders.map((job) => `<option value="${esc(job.id)}">${esc(job.orderNumber)} · ${esc(job.title)}</option>`).join("");
    f.supplierId.value = order.supplierId;
    f.orderId.value = order.orderId || "";
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
      Calight.searchableSelect(f.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
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
      orderDate: f.orderDate.value, expectedDeliveryDate: f.expectedDeliveryDate.value, orderId: f.orderId.value,
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
    const summary = `<div class="order-value-summary purchase-cost-summary">
        <div><span>Ursprünglicher Bestellwert</span><strong>${money(order.expectedNetTotal)}</strong></div>
        <div><span>Aktuell bestätigt</span><strong>${money(order.confirmedNetTotal)}</strong></div>
        <div class="total"><span>Tatsächliche Kosten netto${order.costSource === "direct" ? " (direkt erfasst)" : order.costSource === "invoices" ? " (aus Belegen)" : ""}</span><strong>${money(order.actualNetTotal)}</strong></div>
      </div>`;
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
      allocations = `<div class="purchase-allocation-summary"><span class="field-label">Kostenverteilung</span><ul class="work-report-readonly">${order.allocations.map((allocation) => `<li>${esc(allocation.orderNumber)} · ${esc(allocation.orderTitle)}: <strong>${money(allocation.amount)}</strong></li>`).join("")}</ul>
          <small class="muted">Nicht verteilt: ${money(order.unallocatedTotal)}</small></div>`;
    } else if (order.actualNetTotal !== null) {
      allocations = `<p class="muted">Die Ist-Kosten gehen vollständig an ${order.orderId ? `Auftrag ${esc(order.jobNumber)}` : "keinen Auftrag (noch keine Zuordnung)"}.</p>`;
    }
    root.querySelector('[data-event-list="invoices"]').innerHTML = summary + direct
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
  // Dialoge (je Aktion ein kleiner Dialog)
  // ---------------------------------------------------------------------------
  const field = (label, control, className = "") => `<label${className ? ` class="${className}"` : ""}><span class="field-label">${label}</span>${control}</label>`;
  const fileField = (label = "Beleg als PDF oder Bild (optional, max. 15 MB)") => field(label, '<input name="file" type="file" accept="application/pdf,image/*">', "span-all");
  const options = (labels, selected = "") => Object.entries(labels).map(([value, text]) => `<option value="${esc(value)}"${value === selected ? " selected" : ""}>${esc(text)}</option>`).join("");
  const dialogForm = (html) => {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `${html}<p class="field-error" data-dialog-error hidden></p>`;
    return form;
  };
  const values = (form) => Object.fromEntries([...new FormData(form).entries()].filter(([key]) => key !== "file").map(([key, value]) => [key, String(value).trim()]));
  const moneyFields = (data, names) => {
    for (const name of names) if (name in data) data[name] = decimal(data[name]);
    return data;
  };

  /** Vorgangsdialog: sendet Felder (und optional eine Datei) und übernimmt den neuen Stand. */
  function eventDialog({ title, html, submitLabel, path, prepare = (data) => data, setup = null, json = false, done }) {
    const form = dialogForm(html);
    setup?.(form);
    Calight.createDialog({
      title,
      form,
      submitLabel,
      discardMessage: "Die Eingaben verwerfen?",
      submit: async () => {
        const data = prepare(values(form), form);
        data.version = order.version;
        const file = form.elements.file?.files?.[0] || null;
        adopt(json ? await api(url(path), { method: "POST", body: data }) : await shared.postForm(url(path), data, file, csrf));
        success(done);
      },
    });
  }

  const dialogs = {
    confirmation() {
      eventDialog({
        title: "AB erfassen",
        submitLabel: "Erfassen",
        path: "/confirmations",
        done: "Auftragsbestätigung erfasst.",
        html: `<div class="dialog-form-grid">
            ${field("AB-Nummer (optional)", '<input name="confirmationNumber" maxlength="100" autocomplete="off">')}
            ${field('Bestätigungsdatum <span aria-hidden="true">*</span>', `<input name="confirmationDate" type="date" required value="${today()}">`)}
            ${costs() ? field("Bestätigter Nettobetrag (optional)", '<input name="confirmedNetTotal" inputmode="decimal" placeholder="0,00">') : ""}
            ${field("Bestätigter Liefertermin (optional)", '<input name="confirmedDeliveryDate" type="date">')}
            ${fileField()}
            ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000"></textarea>', "span-all")}
          </div>
          ${order.confirmations.length ? '<label class="check-inline"><input type="checkbox" name="makeCurrent" value="1" checked> Als aktuelle Bestätigung verwenden (frühere bleiben erhalten)</label>' : ""}`,
        prepare: (data, form) => {
          moneyFields(data, ["confirmedNetTotal"]);
          if (order.confirmations.length) data.makeCurrent = form.elements.makeCurrent.checked;
          return data;
        },
      });
    },

    delivery() {
      const hints = {
        partial: "Lieferstatus danach: Teilgeliefert.",
        complete: "Bestätigt die vollständige Lieferung, ohne jede Position abzugleichen. Lieferstatus danach: Geliefert.",
        return: "Rücksendung an den Lieferanten. Die Kosten ändern sich dadurch nicht – eine Gutschrift wird separat erfasst.",
        correction: "Korrigiert den Lieferstatus, z. B. nach einer Fehlerfassung.",
      };
      eventDialog({
        title: "Lieferung erfassen",
        submitLabel: "Erfassen",
        path: "/deliveries",
        done: "Lieferung erfasst.",
        html: `<div class="dialog-form-grid">
            ${field('Art <span aria-hidden="true">*</span>', `<select name="kind" required>${options(reference.deliveryKinds, "partial")}</select>`)}
            ${field('Lieferdatum <span aria-hidden="true">*</span>', `<input name="deliveryDate" type="date" required value="${today()}">`)}
            ${field("Lieferscheinnummer (optional)", '<input name="deliveryNoteNumber" maxlength="100" autocomplete="off">')}
            <label data-resulting hidden><span class="field-label">Lieferstatus danach <span aria-hidden="true">*</span></span><select name="resultingStatus">${options(reference.deliveryLabels, "partial")}</select></label>
            ${fileField("Lieferschein als PDF oder Bild (optional)")}
            ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000" placeholder="z. B. zurückgesandte Teile, Grund der Korrektur"></textarea>', "span-all")}
          </div>
          <p class="muted" data-delivery-hint></p>`,
        setup: (form) => {
          const sync = () => {
            const kind = form.elements.kind.value;
            const choose = kind === "return" || kind === "correction";
            form.querySelector("[data-resulting]").hidden = !choose;
            form.elements.resultingStatus.required = choose;
            form.querySelector("[data-delivery-hint]").textContent = hints[kind] || "";
          };
          form.elements.kind.addEventListener("change", sync);
          sync();
        },
        prepare: (data) => {
          if (data.kind === "partial" || data.kind === "complete") delete data.resultingStatus;
          return data;
        },
      });
    },

    invoice(kind = "invoice", related = null) {
      const credit = kind === "credit";
      const reversal = kind === "reversal";
      const originals = order.invoices.filter((invoice) => invoice.kind !== "reversal");
      const kindChoice = credit || reversal ? "" : field('Belegart <span aria-hidden="true">*</span>', `<select name="kind">${options({ invoice: "Rechnung", partial: "Teilrechnung", final: "Schlussrechnung" }, "invoice")}</select>`);
      const relatedChoice = credit && originals.length
        ? field("Zu Originalbeleg (optional)", `<select name="relatedInvoiceId"><option value="">Ohne Bezug</option>${originals.map((invoice) => `<option value="${esc(invoice.id)}">${esc(invoice.kindLabel)} ${esc(invoice.invoiceNumber)} · ${money(invoice.netAmount)}</option>`).join("")}</select>`, "span-all") : "";
      eventDialog({
        title: reversal ? `${related.kindLabel} ${related.invoiceNumber} stornieren` : credit ? "Gutschrift erfassen" : "Rechnung erfassen",
        submitLabel: reversal ? "Stornobeleg erfassen" : "Erfassen",
        path: "/invoices",
        done: reversal ? "Stornobeleg erfasst. Der Originalbeleg bleibt erhalten." : credit ? "Gutschrift erfasst." : "Beleg erfasst.",
        html: `${reversal ? `<p>Der Originalbeleg bleibt erhalten. Der Stornobeleg neutralisiert ihn ganz oder teilweise (noch nicht storniert: <strong>${money(related.remaining)}</strong>).</p>` : ""}
          <div class="dialog-form-grid">
            ${kindChoice}
            <fieldset class="span-all purchase-final-mode" data-final-mode hidden>
              <legend class="field-label">Die Schlussrechnung weist aus …</legend>
              ${Object.entries(reference.finalModes).map(([value, text], index) => `<label class="check-inline"><input type="radio" name="finalMode" value="${esc(value)}"${index === 0 ? " checked" : ""}> ${esc(text)}</label>`).join("")}
              <small class="muted">Bei der Gesamtrechnung zieht Calight die bereits erfassten Teilrechnungen ab, damit nichts doppelt zählt.</small>
            </fieldset>
            ${relatedChoice}
            ${field(reversal ? "Belegnummer (optional)" : 'Belegnummer <span aria-hidden="true">*</span>', `<input name="invoiceNumber" maxlength="100" autocomplete="off"${reversal ? "" : " required"}>`)}
            ${field('Belegdatum <span aria-hidden="true">*</span>', `<input name="invoiceDate" type="date" required value="${today()}">`)}
            ${field('Nettobetrag <span aria-hidden="true">*</span>', `<input name="netAmount" inputmode="decimal" required placeholder="0,00" value="${reversal ? esc(inputValue(related.remaining)) : ""}">`)}
            ${field("Umsatzsteuer (optional)", '<input name="vatAmount" inputmode="decimal" placeholder="0,00">')}
            ${field("Bruttobetrag (optional)", '<input name="grossAmount" inputmode="decimal" placeholder="0,00">')}
            ${fileField()}
            ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000"></textarea>', "span-all")}
          </div>
          ${order.directActualNetTotal !== null ? `<label class="check-inline notice notice-warning"><input type="checkbox" name="replaceDirectCost" value="1" required> Die direkt erfassten Ist-Kosten (${money(order.directActualNetTotal)}) durch die Belege ersetzen</label>` : ""}`,
        setup: (form) => {
          const sync = () => { form.querySelector("[data-final-mode]").hidden = form.elements.kind?.value !== "final"; };
          form.elements.kind?.addEventListener("change", sync);
          sync();
        },
        prepare: (data, form) => {
          moneyFields(data, ["netAmount", "vatAmount", "grossAmount"]);
          data.kind = credit ? "credit" : reversal ? "reversal" : form.elements.kind.value;
          if (data.kind !== "final") delete data.finalMode;
          if (reversal) data.relatedInvoiceId = related.id;
          if (form.elements.replaceDirectCost) data.replaceDirectCost = form.elements.replaceDirectCost.checked;
          return data;
        },
      });
    },

    credit() {
      dialogs.invoice("credit");
    },

    payment() {
      eventDialog({
        title: "Zahlung erfassen",
        submitLabel: "Erfassen",
        path: "/payments",
        json: true,
        done: "Zahlungsvermerk erfasst.",
        html: `<div class="dialog-form-grid">
            ${field('Art <span aria-hidden="true">*</span>', `<select name="kind">${options(reference.paymentKinds, "payment")}</select>`)}
            ${field('Datum <span aria-hidden="true">*</span>', `<input name="paymentDate" type="date" required value="${today()}">`)}
            <label data-amount><span class="field-label">Betrag <span aria-hidden="true">*</span></span><input name="amount" inputmode="decimal" placeholder="0,00"></label>
            <label data-resulting hidden><span class="field-label">Zahlungsstatus danach <span aria-hidden="true">*</span></span><select name="resultingStatus">${options(reference.paymentLabels, "partial")}</select></label>
            <label class="check-inline span-all" data-complete><input type="checkbox" name="complete" value="1"> Damit vollständig bezahlt</label>
            ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000" placeholder="z. B. Vorkasse, Lastschrift, Rückerstattung nach Gutschrift"></textarea>', "span-all")}
          </div>`,
        setup: (form) => {
          const sync = () => {
            const kind = form.elements.kind.value;
            form.querySelector("[data-amount]").hidden = kind === "correction";
            form.elements.amount.required = kind !== "correction";
            form.querySelector("[data-resulting]").hidden = kind === "payment";
            form.querySelector("[data-complete]").hidden = kind !== "payment";
          };
          form.elements.kind.addEventListener("change", sync);
          sync();
        },
        prepare: (data, form) => {
          moneyFields(data, ["amount"]);
          data.complete = form.elements.complete.checked;
          if (data.kind === "payment") delete data.resultingStatus;
          if (data.kind === "correction") delete data.amount;
          return data;
        },
      });
    },

    direct() {
      const form = dialogForm(`<p class="muted">Für einfache Käufe ohne Belegerfassung (z. B. Baumarkt, Onlinekauf). Nicht möglich, sobald Belege erfasst sind. Leer lassen entfernt den Betrag.</p>
        ${field("Tatsächliche Kosten netto", `<input name="amount" inputmode="decimal" placeholder="0,00" value="${esc(inputValue(order.directActualNetTotal))}">`)}`);
      Calight.createDialog({
        title: "Ist-Kosten direkt erfassen",
        form,
        submitLabel: "Speichern",
        discardMessage: "Die Eingaben verwerfen?",
        submit: async () => {
          adopt(await api(url("/actual-cost"), { method: "PUT", body: { version: order.version, amount: decimal(form.elements.amount.value) } }));
          success("Ist-Kosten gespeichert.");
        },
      });
    },

    allocations() {
      const rows = (order.allocations || []).map((allocation) => ({ orderId: allocation.orderId, amount: inputValue(allocation.amount) }));
      const form = dialogForm(`<p class="muted">Ohne Verteilung gehen die Ist-Kosten (${money(order.actualNetTotal)}) vollständig an den Auftrag der Bestellung. Mit Verteilung gelten genau die eingetragenen Beträge – höchstens bis zu den Ist-Kosten.</p>
        <div data-allocation-rows></div>
        <div><button class="button button-light button-small" type="button" data-add-allocation data-icon="plus">Auftrag hinzufügen</button></div>`);
      const box = form.querySelector("[data-allocation-rows]");
      const draw = () => {
        box.innerHTML = rows.length ? rows.map((row, index) => `
          <div class="purchase-allocation-row" data-allocation="${index}">
            <select data-allocation-field="orderId" aria-label="Auftrag"><option value="">Auftrag wählen …</option>${reference.orders.map((job) => `<option value="${esc(job.id)}"${job.id === row.orderId ? " selected" : ""}>${esc(job.orderNumber)} · ${esc(job.title)}</option>`).join("")}</select>
            <input data-allocation-field="amount" inputmode="decimal" value="${esc(row.amount)}" placeholder="Betrag netto" aria-label="Betrag netto">
            <button class="icon-button icon-button-danger" type="button" data-remove-allocation data-icon="trash" title="Zeile entfernen" aria-label="Zeile entfernen"></button>
          </div>`).join("") : '<p class="muted">Keine Verteilung – volle Kosten an den Auftrag der Bestellung.</p>';
      };
      const update = (event) => {
        const row = event.target.closest("[data-allocation]");
        if (row && event.target.dataset.allocationField) rows[Number(row.dataset.allocation)][event.target.dataset.allocationField] = event.target.value;
      };
      form.addEventListener("input", update);
      form.addEventListener("change", update);
      form.addEventListener("click", (event) => {
        if (event.target.closest("[data-add-allocation]")) {
          rows.push({ orderId: rows.length ? "" : order.orderId || "", amount: "" });
          draw();
        } else if (event.target.closest("[data-remove-allocation]")) {
          rows.splice(Number(event.target.closest("[data-allocation]").dataset.allocation), 1);
          draw();
        }
      });
      draw();
      Calight.createDialog({
        title: "Kostenverteilung",
        form,
        submitLabel: "Speichern",
        discardMessage: "Die Änderungen an der Verteilung verwerfen?",
        submit: async () => {
          adopt(await api(url("/allocations"), { method: "PUT", body: { version: order.version, allocations: rows.map((row) => ({ orderId: row.orderId, amount: decimal(row.amount) })) } }));
          success("Kostenverteilung gespeichert.");
        },
      });
    },

    document() {
      const kinds = Object.entries(reference.documentKinds).filter(([kind]) => costs() || kind !== "invoice");
      const form = dialogForm(`${field("Dokumentart", `<select name="kind">${kinds.map(([kind, label]) => `<option value="${esc(kind)}"${kind === "other" ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`)}
        ${field('Datei (PDF oder Bild, max. 15 MB) <span aria-hidden="true">*</span>', '<input name="file" type="file" accept="application/pdf,image/*" required>')}
        <p class="muted">Belege zu Auftragsbestätigung, Lieferung oder Rechnung am besten direkt beim jeweiligen Vorgang anhängen.</p>`);
      Calight.createDialog({
        title: "Dokument hochladen",
        form,
        submitLabel: "Hochladen",
        discardMessage: "Das Hochladen abbrechen?",
        submit: async () => {
          const file = form.elements.file.files[0];
          if (!file) throw new Error("Bitte eine Datei auswählen.");
          adopt(await shared.postForm(url("/documents"), { kind: form.elements.kind.value }, file, csrf));
          success("Dokument hochgeladen.");
        },
      });
    },
  };

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
        confirmLabel: "Bestellen",
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
