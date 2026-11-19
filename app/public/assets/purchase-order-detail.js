// Bestelldetail: allgemeine Angaben, Positionen, Lieferung/Zahlung, Rechnungen/Kosten,
// Dokumente und Statusverlauf. Alle Regeln und Rechte prüft der Server erneut.
(() => {
  "use strict";

  const root = document.querySelector("#purchase-order-detail");
  if (!root) return;

  const { esc, api, format, notify, exact } = Calight;
  const message = root.querySelector("[data-message]");
  const general = root.querySelector("[data-general]");
  const itemsBox = root.querySelector("[data-items]");
  const id = root.dataset.id;
  const url = (suffix = "") => `/api/purchase-orders/${encodeURIComponent(id)}${suffix}`;
  let order = null;
  let reference = null;
  let items = [];
  let dirty = false;
  let busy = false;

  const money = (value) => (value === null || value === undefined || value === "" ? "–" : format.money(value));
  // Einkaufspreise: bis zu 4 Nachkommastellen anzeigen (3,4567 €), mindestens Cent.
  const unitPriceFormat = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR", minimumFractionDigits: 2, maximumFractionDigits: 4 });
  const unitPrice = (value) => (value === null || value === undefined || value === "" ? "–" : unitPriceFormat.format(Number(value)));
  const decimal = (value) => String(value ?? "").trim().replace(",", ".");
  const inputValue = (value) => (value === null || value === undefined ? "" : String(value).replace(".", ","));
  const badge = (kind, value, label) => `<span class="status-badge status-po-${kind}-${esc(value)}">${esc(label)}</span>`;
  const can = (name) => Boolean(order?.permissions?.[name]);
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
    root.querySelector("[data-title]").textContent = `${order.origin === "external" ? "Externe Bestellung" : "Bestellung"} ${order.orderNumber}`;
    document.title = `Bestellung ${order.orderNumber} · ${order.supplierName}`;
    const details = [
      order.revision > 1 ? `Fassung ${order.revision}` : "",
      order.issuedAt ? `bestellt von ${esc(order.issuedByName)} am ${format.dateTime(order.issuedAt)}` : (order.status === "draft" ? "noch nicht bestellt" : ""),
      `angelegt von ${esc(order.creatorName)}`,
    ].filter(Boolean).join(" · ");
    root.querySelector("[data-status-line]").innerHTML = [
      order.origin === "external" ? '<span class="status-badge status-po-origin">Extern</span>' : "",
      badge("status", order.status, order.statusLabel),
      // Liefer- und Zahlungsstatus erst nach dem Bestellen.
      order.status !== "draft" ? badge("delivery", order.deliveryStatus, `Lieferung: ${order.deliveryLabel}`) : "",
      order.status !== "draft" ? badge("payment", order.paymentStatus, `Zahlung: ${order.paymentLabel}`) : "", `<span>${details}</span>`,
    ].join("");
    const facts = [
      ["Lieferant", esc(order.supplierName) + (order.supplierNumber ? ` <small>${esc(order.supplierNumber)}</small>` : "")],
      ["Auftrag", order.orderId ? `<a href="/orders/${encodeURIComponent(order.orderId)}">${esc(order.jobNumber)} · ${esc(order.jobTitle)}</a>` : '<span class="muted">Ohne Auftrag</span>'],
      ["Bestelldatum", order.orderDate ? format.date(order.orderDate) : '<span class="muted">–</span>'],
    ];
    if (reference.canCosts) facts.push(["Erwartet / Ist netto", `${money(order.expectedNetTotal)} / ${money(order.actualNetTotal)}`]);
    root.querySelector("[data-facts]").innerHTML = facts.map(([label, value]) => `<div><dt>${label}</dt><dd>${value}</dd></div>`).join("");

    const actions = [];
    const more = [];
    if (can("edit")) actions.push('<button class="button button-light" type="button" data-action="save" data-shortcut-save data-icon="save" title="Speichern (Strg+S)">Speichern</button>');
    if (can("issue")) actions.push(`<button class="button" type="button" data-action="issue" data-icon="clipboard-check">${order.revision > 0 ? "Neue Fassung bestellen …" : "Bestellen …"}</button>`);
    if (order.origin === "internal") {
      actions.push(`<a class="button button-light" href="${url("/pdf")}" data-icon="file-down">${order.status === "draft" ? "PDF-Vorschau" : "PDF"}</a>`);
    }
    if (can("newRevision")) more.push('<button type="button" data-action="revision" data-icon="square-pen">Neue Fassung erstellen …</button>');
    if (can("cancel")) more.push('<button type="button" data-action="cancel" data-icon="trash">Bestellung stornieren …</button>');
    if (can("delete")) more.push('<button type="button" data-action="delete" data-icon="trash">Entwurf löschen</button>');
    if (more.length) actions.push(`<details class="context-menu"><summary class="button button-light" data-icon="menu">Weitere Aktionen</summary><div class="context-menu-panel">${more.join("")}</div></details>`);
    root.querySelector("[data-actions]").innerHTML = actions.join("");
  }

  // ---------------------------------------------------------------------------
  // 1. Allgemeine Angaben
  // ---------------------------------------------------------------------------
  function renderGeneral() {
    const f = general.elements;
    const supplierKnown = reference.suppliers.some((supplier) => supplier.id === order.supplierId);
    f.supplierId.innerHTML = (supplierKnown ? "" : `<option value="${esc(order.supplierId)}">${esc(order.supplierName)}</option>`)
      + reference.suppliers.map((supplier) => `<option value="${esc(supplier.id)}">${esc(supplier.number ? `${supplier.number} · ${supplier.name}` : supplier.name)}</option>`).join("");
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
    const editable = can("edit");
    for (const element of general.elements) element.disabled = !editable;
    // Lieferant und Lieferanschrift gehören zum Bestellinhalt: nur im Entwurf änderbar.
    f.supplierId.disabled = !editable || !draft;
    f.deliveryAddress.disabled = !editable || !draft;
    // Bestelldatum: bei internen Bestellungen setzt „Bestellen“ das Datum.
    f.orderDate.disabled = !editable || order.origin === "internal";
    const expected = root.querySelector("[data-expected-field]");
    expected.hidden = !reference.canCosts;
    if (reference.canCosts) {
      f.expectedNetTotal.value = inputValue(order.expectedNetTotal);
      const derived = order.origin === "internal" && items.some((item) => item.unitPrice !== null && item.unitPrice !== "");
      f.expectedNetTotal.disabled = !editable || derived;
      f.expectedNetTotal.title = derived ? "Ergibt sich aus den Positionspreisen." : "";
    }
    root.querySelector("[data-general-hint]").textContent = draft
      ? "Lieferant, Bezug und Lieferanschrift"
      : "Lieferant und Positionen sind mit der Bestellung festgeschrieben; Änderungen über „Neue Fassung“.";
    const links = root.querySelector("[data-request-links]");
    links.hidden = !order.materialRequests.length;
    links.innerHTML = order.materialRequests.length
      ? `<span class="field-label">Aus Materialanforderungen</span><ul class="work-report-readonly">${order.materialRequests.map((request) => `<li>${esc(request.description)} <small class="muted">${esc(request.orderNumber || "")} · ${request.status === "open" ? "offen" : "erledigt"}</small></li>`).join("")}</ul>`
      : "";
    if (!general.dataset.enhanced) {
      general.dataset.enhanced = "1";
      Calight.searchableSelect(f.supplierId, { placeholder: "Lieferantennummer oder Name eingeben …" });
      Calight.searchableSelect(f.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
    }
    // Suchfelder an den aktuellen Wert und Sperrzustand angleichen.
    for (const select of [f.supplierId, f.orderId]) {
      const box = select.nextElementSibling;
      if (!box?.classList.contains("suggest-select")) continue;
      const input = box.querySelector("input");
      input.value = select.selectedOptions[0]?.value ? select.selectedOptions[0].textContent : "";
      input.disabled = select.disabled;
      box.querySelector(".suggest-clear").hidden = select.disabled || input.value === "";
    }
  }
  general.addEventListener("input", () => setDirty(true));
  general.addEventListener("change", () => setDirty(true));

  // ---------------------------------------------------------------------------
  // 2. Positionen
  // ---------------------------------------------------------------------------
  const lineCents = (item) => (exact.DECIMAL_PATTERN.test(decimal(item.quantity)) && exact.DECIMAL_PATTERN.test(decimal(item.unitPrice))
    ? exact.lineCents(exact.toUnits(decimal(item.quantity), 3), decimal(item.unitPrice)) : null);

  function renderItems() {
    const editable = can("editItems");
    root.querySelector("[data-items-tools]").hidden = !editable;
    root.querySelector("[data-catalog-box]").hidden = !editable;
    const costs = reference.canCosts;
    root.querySelector("[data-items-hint]").textContent = order.origin === "external"
      ? "Optional – externe Bestellungen brauchen keine Positionen."
      : (editable ? "Vor dem Bestellen braucht jede Position eine Bezeichnung und eine Menge." : `${items.length} Position${items.length === 1 ? "" : "en"}`);
    if (!items.length) {
      itemsBox.innerHTML = `<p class="empty-state">${editable ? "Noch keine Positionen. Artikel aus dem Stamm suchen oder eine Position hinzufügen." : "Keine Positionen erfasst."}</p>`;
    } else if (!editable) {
      itemsBox.innerHTML = `<div class="measurement-table-wrap"><table class="measurement-totals purchase-items-table"><thead><tr><th>Pos.</th><th class="m-num">Menge</th><th>Einheit</th><th>Artikel-Nr.</th><th>Bezeichnung</th>${costs ? '<th class="m-num">Einzelpreis</th><th class="m-num">Gesamt</th>' : ""}</tr></thead><tbody>${items.map((item) => `
        <tr><td>${item.position}</td><td class="m-num">${item.quantity !== null ? format.number(item.quantity, 3) : "–"}</td><td>${esc(item.unit)}</td><td>${esc(item.articleNumber)}</td>
          <td>${esc(item.description)}${item.notes ? `<small class="muted"> · ${esc(item.notes)}</small>` : ""}</td>
          ${costs ? `<td class="m-num">${unitPrice(item.unitPrice)}</td><td class="m-num">${money(item.lineTotal)}</td>` : ""}</tr>`).join("")}</tbody></table></div>`;
    } else {
      itemsBox.innerHTML = items.map((item, index) => `
        <div class="work-report-row purchase-item-row${costs ? "" : " no-prices"}" data-index="${index}">
          <label><span class="field-label">Menge</span><input data-field="quantity" inputmode="decimal" value="${esc(inputValue(item.quantity))}" placeholder="0"></label>
          <label><span class="field-label">Einheit</span><input data-field="unit" maxlength="20" list="purchase-units" value="${esc(item.unit)}"></label>
          <label><span class="field-label">Artikel-Nr.</span><input data-field="articleNumber" maxlength="100" value="${esc(item.articleNumber)}"></label>
          <label class="work-report-wide"><span class="field-label">Bezeichnung</span><input data-field="description" maxlength="500" value="${esc(item.description)}"></label>
          ${costs ? `<label><span class="field-label">Einzelpreis €</span><input data-field="unitPrice" inputmode="decimal" value="${esc(inputValue(item.unitPrice))}" placeholder="optional"></label>
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
    root.querySelector("[data-items-total-row]").hidden = !reference.canCosts || !priced.length;
    root.querySelector("[data-items-total]").textContent = format.money(exact.fromUnits(priced.reduce((sum, item) => sum + lineCents(item), 0n), 2));
  }

  itemsBox.addEventListener("input", (event) => {
    const field = event.target.dataset.field;
    const row = event.target.closest("[data-index]");
    if (!field || !row) return;
    const item = items[Number(row.dataset.index)];
    item[field] = event.target.value;
    if (field === "quantity" || field === "unitPrice") {
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
    renderGeneral();
  };
  root.querySelector("[data-add-item]").addEventListener("click", () => {
    addItem({});
    itemsBox.querySelector(".purchase-item-row:last-of-type [data-field='quantity']")?.focus();
  });
  Calight.suggest({
    input: root.querySelector("[data-catalog-search]"),
    list: root.querySelector("[data-catalog-results]"),
    clear: root.querySelector("[data-catalog-clear]"),
    load: (query) => api(`/api/purchase-orders/catalog?q=${encodeURIComponent(query)}&supplier=${encodeURIComponent(general.elements.supplierId.value)}`),
    render: (item) => `<span>${esc(item.name)} <small class="muted">${esc(item.supplierArticleNumber || item.articleNumber || "")}${item.fromSupplier ? " · Lieferant" : ""}</small></span>${item.costPrice !== null ? `<span class="work-report-catalog-price">${unitPrice(item.costPrice)} / ${esc(item.unit)}</span>` : ""}`,
    onError: (exception) => notify(message, exception.message),
    choose: (item) => {
      addItem({ costItemId: item.id, articleNumber: item.supplierArticleNumber || item.articleNumber || "", description: item.name, unit: item.unit, unitPrice: item.costPrice ?? "" });
      itemsBox.querySelector(".purchase-item-row:last-of-type [data-field='quantity']")?.focus();
    },
  });

  // ---------------------------------------------------------------------------
  // 3. Lieferung und Zahlung
  // ---------------------------------------------------------------------------
  function renderTracking() {
    const box = root.querySelector("[data-tracking]");
    if (order.status === "draft") {
      box.innerHTML = '<p class="muted">Liefer- und Zahlungsstatus stehen nach dem Bestellen zur Verfügung.</p>';
      return;
    }
    const block = (kind, title, current, label, labels, permission) => `
      <div class="purchase-tracking-block">
        <div><span class="field-label">${title}</span> ${badge(kind, current, label)}</div>
        ${can(permission) ? `<div class="purchase-tracking-form" data-tracking-form="${kind}">
          <select aria-label="${title}">${Object.entries(labels).map(([value, text]) => `<option value="${value}"${value === current ? " selected" : ""}>${esc(text)}</option>`).join("")}</select>
          <input maxlength="500" placeholder="Bemerkung (optional)" aria-label="Bemerkung zum ${title}">
          <button class="button button-light" type="button" data-icon="save">Übernehmen</button>
        </div>` : ""}
      </div>`;
    box.innerHTML = `<div class="purchase-tracking">${block("delivery", "Lieferstatus", order.deliveryStatus, order.deliveryLabel, reference.deliveryLabels, "changeDelivery")}
      ${block("payment", "Zahlungsstatus", order.paymentStatus, order.paymentLabel, reference.paymentLabels, "changePayment")}</div>`;
  }
  root.querySelector("[data-tracking]").addEventListener("click", (event) => {
    const button = event.target.closest("button");
    const form = event.target.closest("[data-tracking-form]");
    if (!button || !form) return;
    const kind = form.dataset.trackingForm;
    run(button, async () => {
      if (dirty && !(await save({ silent: true }))) return;
      adopt(await api(url(`/${kind}`), { method: "POST", body: { version: order.version, status: form.querySelector("select").value, note: form.querySelector("input").value.trim() } }));
      success(kind === "delivery" ? "Lieferstatus aktualisiert." : "Zahlungsstatus aktualisiert.");
    });
  });

  // ---------------------------------------------------------------------------
  // 4. Rechnungen und Kosten
  // ---------------------------------------------------------------------------
  function renderCosts() {
    const section = root.querySelector("[data-costs-section]");
    section.hidden = !reference.canCosts;
    root.querySelector("[data-documents-number]").textContent = reference.canCosts ? "5" : "4";
    root.querySelector("[data-history-number]").textContent = reference.canCosts ? "6" : "5";
    if (section.hidden) return;
    const box = root.querySelector("[data-costs]");
    const edit = can("costs") && order.status !== "draft";
    const invoices = order.invoices || [];
    const sourceText = { direct: "direkt erfasster Betrag", invoices: "Summe der Lieferantenrechnungen" }[order.costSource] || "noch nicht bekannt";
    const invoiceDocuments = order.documents.filter((document) => document.kind === "invoice");
    box.innerHTML = `
      <div class="order-value-summary purchase-cost-summary">
        <div><span>Erwarteter Bestellwert</span><strong>${money(order.expectedNetTotal)}</strong></div>
        <div><span>Tatsächliche Kosten (${esc(sourceText)})</span><strong>${money(order.actualNetTotal)}</strong></div>
        <div class="total"><span>Nicht auf Aufträge verteilt</span><strong>${order.unallocatedTotal !== null && order.unallocatedTotal !== undefined ? money(order.unallocatedTotal) : (order.allocations?.length ? "–" : "Gesamter Betrag → Auftrag der Bestellung")}</strong></div>
      </div>
      ${order.status === "draft" ? '<p class="muted">Rechnungen und tatsächliche Kosten können nach dem Bestellen erfasst werden.</p>' : ""}

      <h3 class="purchase-subheading">Lieferantenrechnungen</h3>
      ${invoices.length ? `<div class="measurement-table-wrap"><table class="measurement-totals"><thead><tr><th>Rechnungsnr.</th><th>Datum</th><th class="m-num">Netto</th><th class="m-num">USt.</th><th class="m-num">Brutto</th><th>Beleg</th><th>Bemerkung</th>${edit ? "<th></th>" : ""}</tr></thead><tbody>${invoices.map((invoice) => `
        <tr><td>${esc(invoice.invoiceNumber)}</td><td>${format.date(invoice.invoiceDate)}</td><td class="m-num">${money(invoice.netAmount)}</td><td class="m-num">${money(invoice.vatAmount)}</td><td class="m-num">${money(invoice.grossAmount)}</td>
          <td>${invoice.documentId ? `<a href="${url(`/documents/${encodeURIComponent(invoice.documentId)}`)}" target="_blank" rel="noopener">Öffnen</a>` : "–"}</td><td>${esc(invoice.notes)}</td>
          ${edit ? `<td><button class="icon-button icon-button-danger" type="button" data-delete-invoice="${esc(invoice.id)}" data-icon="trash" title="Rechnung entfernen" aria-label="Rechnung ${esc(invoice.invoiceNumber)} entfernen"></button></td>` : ""}</tr>`).join("")}</tbody></table></div>` : '<p class="muted">Keine Rechnungen erfasst.</p>'}
      ${edit && order.directActualNetTotal === null ? `
        <form class="purchase-inline-form" data-invoice-form novalidate>
          <label><span class="field-label">Rechnungsnr. *</span><input name="invoiceNumber" maxlength="100" required></label>
          <label><span class="field-label">Datum *</span><input name="invoiceDate" type="date" required></label>
          <label><span class="field-label">Netto € *</span><input name="netAmount" inputmode="decimal" required></label>
          <label><span class="field-label">USt. €</span><input name="vatAmount" inputmode="decimal"></label>
          <label><span class="field-label">Brutto €</span><input name="grossAmount" inputmode="decimal"></label>
          <label><span class="field-label">Beleg</span><select name="documentId"><option value="">Ohne Beleg</option>${invoiceDocuments.map((document) => `<option value="${esc(document.id)}">${esc(document.name)}</option>`).join("")}</select></label>
          <label class="purchase-inline-wide"><span class="field-label">Bemerkung</span><input name="notes" maxlength="1000"></label>
          <button class="button button-light" type="submit" data-icon="plus">Rechnung erfassen</button>
        </form>` : ""}

      <h3 class="purchase-subheading">Direkt erfasster tatsächlicher Betrag</h3>
      <p class="muted">Für einfache Käufe ohne Rechnungserfassung. Nicht möglich, solange Rechnungen erfasst sind.</p>
      ${edit ? `<form class="purchase-inline-form" data-actual-form novalidate>
          <label><span class="field-label">Netto €</span><input name="amount" inputmode="decimal" value="${esc(inputValue(order.directActualNetTotal))}"${invoices.length ? " disabled" : ""}></label>
          <button class="button button-light" type="submit" data-icon="save"${invoices.length ? " disabled" : ""}>Betrag speichern</button>
        </form>` : `<p>${money(order.directActualNetTotal)}</p>`}

      <h3 class="purchase-subheading">Kostenverteilung auf Aufträge (optional)</h3>
      <p class="muted">Ohne Verteilung gehen die tatsächlichen Kosten vollständig an den Auftrag der Bestellung. Mit Verteilung gelten genau die eingetragenen Beträge.</p>
      <div data-allocations></div>
      ${edit ? `<div class="form-actions"><button class="button button-light button-small" type="button" data-add-allocation data-icon="plus">Auftrag hinzufügen</button>
        <button class="button button-light button-small" type="button" data-save-allocations data-icon="save">Verteilung speichern</button></div>` : ""}`;
    renderAllocations(edit);
  }

  let allocations = [];
  function renderAllocations(edit) {
    const box = root.querySelector("[data-allocations]");
    if (!edit) {
      box.innerHTML = order.allocations?.length
        ? `<ul class="work-report-readonly">${order.allocations.map((allocation) => `<li>${esc(allocation.orderNumber)} · ${esc(allocation.orderTitle)}: <strong>${money(allocation.amount)}</strong></li>`).join("")}</ul>`
        : '<p class="muted">Keine Verteilung.</p>';
      return;
    }
    box.innerHTML = allocations.length ? allocations.map((allocation, index) => `
      <div class="purchase-allocation-row" data-allocation="${index}">
        <select data-allocation-field="orderId" aria-label="Auftrag"><option value="">Auftrag wählen …</option>${reference.orders.map((job) => `<option value="${esc(job.id)}"${job.id === allocation.orderId ? " selected" : ""}>${esc(job.orderNumber)} · ${esc(job.title)}</option>`).join("")}</select>
        <input data-allocation-field="amount" inputmode="decimal" value="${esc(inputValue(allocation.amount))}" placeholder="Betrag netto" aria-label="Betrag netto">
        <button class="icon-button icon-button-danger" type="button" data-remove-allocation data-icon="trash" title="Zeile entfernen" aria-label="Zeile entfernen"></button>
      </div>`).join("") : '<p class="muted">Keine Verteilung.</p>';
  }

  root.querySelector("[data-costs]").addEventListener("input", (event) => {
    const row = event.target.closest("[data-allocation]");
    if (row && event.target.dataset.allocationField) allocations[Number(row.dataset.allocation)][event.target.dataset.allocationField] = event.target.value;
  });
  root.querySelector("[data-costs]").addEventListener("change", (event) => {
    const row = event.target.closest("[data-allocation]");
    if (row && event.target.dataset.allocationField) allocations[Number(row.dataset.allocation)][event.target.dataset.allocationField] = event.target.value;
  });
  root.querySelector("[data-costs]").addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.matches("[data-add-allocation]")) {
      allocations.push({ orderId: order.orderId || "", amount: "" });
      renderAllocations(true);
    } else if (button.matches("[data-remove-allocation]")) {
      allocations.splice(Number(button.closest("[data-allocation]").dataset.allocation), 1);
      renderAllocations(true);
    } else if (button.matches("[data-save-allocations]")) {
      run(button, async () => {
        adopt(await api(url("/allocations"), { method: "PUT", body: { version: order.version, allocations: allocations.map((allocation) => ({ orderId: allocation.orderId, amount: decimal(allocation.amount) })) } }));
        success("Kostenverteilung gespeichert.");
      });
    } else if (button.dataset.deleteInvoice) {
      run(button, async () => {
        if (!(await CalightModal.confirm({ title: "Rechnung entfernen", content: "Die Rechnung wird aus der Bestellung entfernt. Ein zugeordneter Beleg bleibt bei den Dokumenten.", confirmLabel: "Entfernen", danger: true }))) return;
        adopt(await api(url(`/invoices/${encodeURIComponent(button.dataset.deleteInvoice)}`), { method: "DELETE", body: { version: order.version } }));
        success("Rechnung entfernt.");
      });
    }
  });
  root.querySelector("[data-costs]").addEventListener("submit", (event) => {
    event.preventDefault();
    const form = event.target;
    const button = form.querySelector("[type='submit']");
    if (form.matches("[data-invoice-form]")) {
      if (!form.reportValidity()) return;
      const f = form.elements;
      run(button, async () => {
        adopt(await api(url("/invoices"), { method: "POST", body: {
          version: order.version, invoiceNumber: f.invoiceNumber.value.trim(), invoiceDate: f.invoiceDate.value,
          netAmount: decimal(f.netAmount.value), vatAmount: decimal(f.vatAmount.value), grossAmount: decimal(f.grossAmount.value),
          documentId: f.documentId.value, notes: f.notes.value.trim(),
        } }));
        success("Rechnung erfasst.");
      });
    } else if (form.matches("[data-actual-form]")) {
      run(button, async () => {
        adopt(await api(url("/actual-cost"), { method: "PUT", body: { version: order.version, amount: decimal(form.elements.amount.value) } }));
        success("Tatsächlicher Betrag gespeichert.");
      });
    }
  });

  // ---------------------------------------------------------------------------
  // 5. Dokumente und 6. Statusverlauf
  // ---------------------------------------------------------------------------
  function renderDocuments() {
    const box = root.querySelector("[data-documents]");
    const kinds = reference.documentKinds;
    const uploadKinds = Object.entries(kinds).filter(([kind]) => reference.canCosts || kind !== "invoice");
    box.innerHTML = `
      ${order.documents.length ? `<ul class="purchase-documents">${order.documents.map((document) => `
        <li><a href="${url(`/documents/${encodeURIComponent(document.id)}`)}" target="_blank" rel="noopener" data-icon="file-down">${esc(document.name)}</a>
          <span class="status-badge">${esc(document.kindLabel)}${document.revision ? ` · Fassung ${document.revision}` : ""}</span>
          <small class="muted">${format.dateTime(document.createdAt)} · ${esc(document.uploadedByName)}</small>
          ${document.canDelete ? `<button class="icon-button icon-button-danger" type="button" data-delete-document="${esc(document.id)}" data-icon="trash" title="Dokument entfernen" aria-label="Dokument ${esc(document.name)} entfernen"></button>` : ""}</li>`).join("")}</ul>` : '<p class="muted">Noch keine Dokumente.</p>'}
      ${can("documents") ? `<form class="purchase-inline-form" data-upload-form novalidate>
          <label><span class="field-label">Dokumentart</span><select name="kind">${uploadKinds.map(([kind, label]) => `<option value="${kind}">${esc(label)}</option>`).join("")}</select></label>
          <label class="purchase-inline-wide"><span class="field-label">Datei (PDF oder Bild, max. 15 MB)</span><input name="file" type="file" accept="application/pdf,image/*" required></label>
          <button class="button button-light" type="submit" data-icon="file-up">Hochladen</button>
        </form>` : ""}`;
  }
  root.querySelector("[data-documents]").addEventListener("submit", (event) => {
    event.preventDefault();
    const form = event.target;
    const file = form.elements.file.files[0];
    if (!file) {
      notify(message, "Bitte eine Datei auswählen.");
      return;
    }
    run(form.querySelector("[type='submit']"), async () => {
      const data = new FormData();
      data.append("file", file);
      data.append("kind", form.elements.kind.value);
      const response = await fetch(url("/documents"), { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf, Accept: "application/json" }, body: data });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error((Array.isArray(result.details) ? result.details.join(" ") : "") || result.error || `Hochladen fehlgeschlagen (HTTP ${response.status}).`);
      adopt(result.data);
      success("Dokument hochgeladen.");
    });
  });
  root.querySelector("[data-documents]").addEventListener("click", (event) => {
    const button = event.target.closest("[data-delete-document]");
    if (!button) return;
    run(button, async () => {
      if (!(await CalightModal.confirm({ title: "Dokument entfernen", content: "Das Dokument wird endgültig gelöscht.", confirmLabel: "Entfernen", danger: true }))) return;
      adopt(await api(url(`/documents/${encodeURIComponent(button.dataset.deleteDocument)}`), { method: "DELETE", body: {} }));
      success("Dokument entfernt.");
    });
  });

  function renderHistory() {
    root.querySelector("[data-history]").innerHTML = order.history.length
      ? `<div class="order-status-history">${order.history.map((entry) => `
        <div class="order-status-entry"><time>${format.dateTime(entry.createdAt)}</time><span>${esc(entry.label)}${entry.note ? `<small class="muted"> · ${esc(entry.note)}</small>` : ""}</span><span class="muted">${esc(entry.userName)}</span></div>`).join("")}</div>`
      : '<p class="muted">Noch keine Einträge.</p>';
  }

  function render() {
    allocations = (order.allocations || []).map((allocation) => ({ orderId: allocation.orderId, amount: allocation.amount }));
    renderHeader();
    renderItems();
    renderGeneral();
    renderTracking();
    renderCosts();
    renderDocuments();
    renderHistory();
  }

  // ---------------------------------------------------------------------------
  // Aktionen
  // ---------------------------------------------------------------------------
  function payload() {
    const f = general.elements;
    const body = {
      version: order.version, supplierId: f.supplierId.value, title: f.title.value.trim(), externalReference: f.externalReference.value.trim(),
      orderDate: f.orderDate.value, expectedDeliveryDate: f.expectedDeliveryDate.value, orderId: f.orderId.value,
      deliveryAddress: f.deliveryAddress.value.trim(), notes: f.notes.value.trim(),
    };
    if (reference.canCosts && !f.expectedNetTotal.disabled) body.expectedNetTotal = decimal(f.expectedNetTotal.value);
    if (can("editItems")) {
      body.items = items.map((item) => ({
        costItemId: item.costItemId || "", articleNumber: (item.articleNumber || "").trim(), description: (item.description || "").trim(),
        quantity: decimal(item.quantity), unit: (item.unit || "").trim(), notes: (item.notes || "").trim(),
        ...(reference.canCosts ? { unitPrice: decimal(item.unitPrice) } : {}),
      }));
    }
    return body;
  }

  async function save({ silent = false } = {}) {
    adopt(await api(url(), { method: "PUT", body: payload() }));
    if (!silent) success("Bestellung gespeichert.");
    return true;
  }

  const dialogText = (title, text, placeholder) => {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.innerHTML = `<p>${text}</p><label><span class="field-label">${placeholder}</span><textarea name="note" rows="2" maxlength="500"></textarea></label>`;
    return form;
  };

  const handlers = {
    save: () => save(),
    async issue() {
      if (dirty && !(await save({ silent: true }))) return;
      const ok = await CalightModal.confirm({
        title: order.revision > 0 ? "Neue Fassung bestellen" : "Bestellung ausgeben",
        content: `Die Bestellung ${order.orderNumber} an ${order.supplierName} wird verbindlich: Lieferant und Positionen werden festgeschrieben und das Bestell-PDF${order.revision > 0 ? ` (Fassung ${order.revision + 1})` : ""} archiviert. Den Versand an den Lieferanten übernehmen Sie wie gewohnt.`,
        confirmLabel: "Bestellen",
      });
      if (!ok) return;
      adopt(await api(url("/issue"), { method: "POST", body: { version: order.version } }));
      success("Bestellung ausgegeben. Das PDF steht unter „PDF“ und bei den Dokumenten bereit.");
    },
    async revision() {
      const form = dialogText("", "Die Bestellung wird für Änderungen geöffnet. Bereits ausgegebene PDFs bleiben unverändert archiviert; nach dem erneuten Bestellen entsteht eine neue Fassung.", "Grund der Änderung (optional)");
      const ok = await CalightModal.open({ title: "Neue Fassung erstellen", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Neue Fassung erstellen", value: true, primary: true }] });
      if (!ok) return;
      adopt(await api(url("/revision"), { method: "POST", body: { version: order.version, note: form.elements.note.value.trim() } }));
      success("Neue Fassung geöffnet.");
    },
    async cancel() {
      const form = dialogText("", "Die Bestellung wird storniert. Dokumente, Rechnungen und bereits entstandene Kosten bleiben erhalten.", "Begründung (optional)");
      const ok = await CalightModal.open({ title: "Bestellung stornieren", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Stornieren", value: true, primary: true }] });
      if (!ok) return;
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
    if (button) run(button, handlers[button.dataset.action]);
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  async function load() {
    const [current, ref] = await Promise.all([api(url()), api("/api/purchase-orders/reference-data")]);
    reference = ref;
    adopt(current);
  }
  load().catch((exception) => notify(message, exception.message));
})();
