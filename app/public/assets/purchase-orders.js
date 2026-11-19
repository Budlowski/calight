// Bestellungen: zentrale Übersicht und Anlegen (neue interne Bestellung, externe Bestellung aufnehmen).
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const href = (order) => `/purchase-orders/${encodeURIComponent(order.id)}`;
  const badge = (kind, value, label) => `<span class="status-badge status-po-${kind}-${esc(value)}">${esc(label)}</span>`;
  const statusBadges = (order) => [
    order.origin === "external" ? '<span class="status-badge status-po-origin">Extern</span>' : "",
    order.status !== "ordered" ? badge("status", order.status, order.statusLabel) : "",
  ].join("");
  const jobLabel = (order) => (order.orderId ? `${order.jobNumber} · ${order.jobTitle}` : "Ohne Auftrag");
  const money = (value) => (value === null || value === undefined ? "–" : format.money(value));

  globalThis.CalightPurchaseOrders = Object.freeze({ href, badge, statusBadges, jobLabel });

  const root = document.querySelector("#purchase-orders-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const canCosts = root.dataset.canCosts === "1";
  const supplierFilter = root.querySelector("[data-supplier-filter]");
  const orderFilter = root.querySelector("[data-order-filter]");
  let reference = { suppliers: [], orders: [], materialRequests: [] };

  const renderCard = (order) => `
    <article class="overview-card" tabindex="0" data-href="${href(order)}">
      <div class="management-card-head"><span class="service-number">${esc(order.orderNumber)}</span><span class="management-card-badges">${statusBadges(order)}</span></div>
      <div class="overview-card-title" title="${esc(order.supplierName)}">${esc(order.supplierName)}</div>
      <div class="overview-card-meta">
        <span>${esc(jobLabel(order))}</span>
        <span>${order.orderDate ? format.date(order.orderDate) : "Noch nicht bestellt"}</span>
        ${order.externalReference ? `<span>Ref. ${esc(order.externalReference)}</span>` : ""}
        ${canCosts ? `<span>Erwartet ${money(order.expectedNetTotal)} · Ist ${money(order.actualNetTotal)}</span>` : ""}
      </div>
      <div class="management-card-badges">${badge("delivery", order.deliveryStatus, order.deliveryLabel)}${badge("payment", order.paymentStatus, order.paymentLabel)}</div>
    </article>`;

  const renderTable = (items, header) => `
    <thead><tr>
      ${header("number", "Bestellnummer", "cell-number")}${header("supplier", "Lieferant", "cell-text")}${header("date", "Datum", "cell-date")}
      ${header("order", "Auftrag", "cell-number")}
      ${canCosts ? `${header("expected", "Erwartet netto", "cell-money")}${header("actual", "Ist netto", "cell-money")}` : ""}
      <th class="cell-status">Lieferung</th><th class="cell-status">Zahlung</th>
    </tr></thead>
    <tbody>${items.map((order) => `
      <tr tabindex="0" data-href="${href(order)}">
        <td class="cell-number">${esc(order.orderNumber)} ${statusBadges(order)}</td>
        <td class="cell-text" title="${esc(order.supplierName)}">${esc(order.supplierName)}${order.externalReference ? `<small class="muted"> · ${esc(order.externalReference)}</small>` : ""}</td>
        <td class="cell-date">${order.orderDate ? format.date(order.orderDate) : '<span class="muted">Entwurf</span>'}</td>
        <td class="cell-number" title="${esc(jobLabel(order))}">${order.orderId ? esc(order.jobNumber) : '<span class="muted">–</span>'}</td>
        ${canCosts ? `<td class="cell-money">${money(order.expectedNetTotal)}</td><td class="cell-money">${money(order.actualNetTotal)}</td>` : ""}
        <td class="cell-status">${badge("delivery", order.deliveryStatus, order.deliveryLabel)}</td>
        <td class="cell-status">${badge("payment", order.paymentStatus, order.paymentLabel)}</td>
      </tr>`).join("")}</tbody>`;

  const sorts = {
    updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
    date: { label: "Bestelldatum", type: "date", dir: "desc" },
    number: { label: "Bestellnummer", dir: "asc" },
    supplier: { label: "Lieferant", dir: "asc" },
    order: { label: "Auftrag", dir: "asc" },
  };
  if (canCosts) {
    sorts.expected = { label: "Erwarteter Betrag", type: "number", dir: "desc" };
    sorts.actual = { label: "Tatsächlicher Betrag", type: "number", dir: "desc" };
  }

  const list = CalightListView.createListPage({
    root,
    module: "purchase-orders",
    endpoint: "/api/purchase-orders",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: {
      supplier: supplierFilter,
      order: orderFilter,
      origin: root.querySelector("[data-origin-filter]"),
      status: root.querySelector("[data-status-filter]"),
      delivery: root.querySelector("[data-delivery-filter]"),
      payment: root.querySelector("[data-payment-filter]"),
    },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "updated",
    sorts,
    emptyText: "Noch keine Bestellungen vorhanden.",
    noMatchText: "Keine Bestellungen entsprechen der aktuellen Auswahl.",
    loadingText: "Bestellungen werden geladen …",
    renderTable,
    renderCard,
  });

  const supplierOptions = () => reference.suppliers.map((supplier) => `<option value="${esc(supplier.id)}">${esc(supplier.number ? `${supplier.number} · ${supplier.name}` : supplier.name)}</option>`).join("");
  const orderOptions = () => reference.orders.map((order) => `<option value="${esc(order.id)}">${esc(order.orderNumber)} · ${esc(order.title)}</option>`).join("");
  const today = () => {
    const now = new Date();
    return new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  };

  /** Anlegen: getrennte Dialoge für neue (interne) und extern bereits ausgelöste Bestellungen. */
  function newOrder(external) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Lieferant <span aria-hidden="true">*</span></span>
        <select name="supplierId" required><option value="">Lieferant auswählen …</option>${supplierOptions()}</select></label>
      ${external ? `<div class="form-grid">
        <label><span class="field-label">Externe Bestellnummer (optional)</span><input name="externalReference" maxlength="100" autocomplete="off"></label>
        <label><span class="field-label">Bestelldatum</span><input name="orderDate" type="date" value="${today()}"></label>
        ${canCosts ? '<label><span class="field-label">Bestellwert netto (optional)</span><input name="expectedNetTotal" inputmode="decimal" placeholder="0,00"></label>' : ""}
        <label><span class="field-label">Bestätigung als Datei (optional)</span><input name="file" type="file" accept="application/pdf,image/*"></label>
      </div>` : ""}
      <label><span class="field-label">Auftrag (optional)</span>
        <select name="orderId"><option value="">Ohne Auftrag</option>${orderOptions()}</select></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.searchableSelect(form.elements.supplierId, { placeholder: "Lieferantennummer oder Name eingeben …" });
    Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });

    Calight.createDialog({
      title: external ? "Externe Bestellung aufnehmen" : "Neue Bestellung",
      form,
      submitLabel: external ? "Aufnehmen" : "Anlegen",
      discardMessage: "Das Anlegen abbrechen?",
      initialFocus: ".suggest-select input",
      submit: async () => {
        const body = { supplierId: form.elements.supplierId.value, orderId: form.elements.orderId.value };
        if (external) {
          Object.assign(body, {
            origin: "external",
            externalReference: form.elements.externalReference.value.trim(),
            orderDate: form.elements.orderDate.value,
            expectedNetTotal: form.elements.expectedNetTotal ? form.elements.expectedNetTotal.value.trim().replace(",", ".") : "",
          });
        }
        const order = await api("/api/purchase-orders", { method: "POST", body });
        const file = external ? form.elements.file.files[0] : null;
        if (file) await uploadDocument(order.id, file, "supplier_confirmation");
        location.assign(href(order));
      },
    });
  }

  async function uploadDocument(id, file, kind) {
    const data = new FormData();
    data.append("file", file);
    data.append("kind", kind);
    const response = await fetch(`/api/purchase-orders/${encodeURIComponent(id)}/documents`, {
      method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf, Accept: "application/json" }, body: data,
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error((Array.isArray(result.details) ? result.details.join(" ") : "") || result.error || `Hochladen fehlgeschlagen (HTTP ${response.status}).`);
  }

  async function init() {
    reference = await api("/api/purchase-orders/reference-data");
    supplierFilter.innerHTML = '<option value="">Alle Lieferanten</option>' + supplierOptions();
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orderOptions();
    list.start();
  }

  root.querySelector("[data-new]")?.addEventListener("click", () => newOrder(false));
  root.querySelector("[data-new-external]")?.addEventListener("click", () => newOrder(true));
  init().catch((exception) => notify(message, exception.message));
})();
