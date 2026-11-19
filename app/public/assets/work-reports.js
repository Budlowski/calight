// Rapporte: Anlegen (Übersicht und Auftragsdetailseite) und zentrale Übersicht.
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const STATUS_LABELS = { draft: "Entwurf", submitted: "Eingereicht", processed: "Bearbeitet" };
  const href = (report) => `/work-reports/${encodeURIComponent(report.id)}`;
  const statusBadge = (report) => `<span class="status-badge status-work-${esc(report.status)}">${STATUS_LABELS[report.status] || esc(report.status)}</span>`;
  const confirmationBadge = (report) => (report.confirmationStatus === "confirmed" ? '<span class="status-badge status-work-confirmed">Bestätigt</span>' : "");
  const number = (report) => report.reportNumber || "Entwurf";
  const orderLabel = (report) => (report.orderId ? `${report.orderNumber} · ${report.orderTitle}` : "Ohne Auftrag");

  /**
   * Legt einen Entwurf an und öffnet ihn; die Erfassung erfolgt auf der Detailseite.
   * Pflicht ist der Kunde; ein Auftrag ist optional (eigenständiger Rapport).
   */
  async function create({ customerId = "", orderId = "" }) {
    const report = await api("/api/work-reports", { method: "POST", body: { customerId, orderId } });
    location.assign(href(report));
  }
  const createForOrder = (orderId) => create({ orderId });

  globalThis.CalightWorkReports = Object.freeze({ create, createForOrder, statusBadge, confirmationBadge, number, href, orderLabel });

  // Auftragsdetailseite: „Rapport anlegen“.
  document.querySelectorAll("[data-create-work-report]").forEach((button) => button.addEventListener("click", async () => {
    button.disabled = true;
    try {
      await createForOrder(button.dataset.createWorkReport);
    } catch (exception) {
      button.disabled = false;
      notify(document.querySelector("[data-work-report-message]"), exception.message);
    }
  }));

  const root = document.querySelector("#work-reports-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const customerFilter = root.querySelector("[data-customer-filter]");
  const canProcess = root.dataset.canProcess === "1";
  let orders = [];
  let customers = [];

  const renderCard = (report) => `
    <article class="overview-card" tabindex="0" data-href="${href(report)}">
      <div class="management-card-head"><span class="service-number">${esc(number(report))}</span><span class="management-card-badges">${confirmationBadge(report)}${statusBadge(report)}</span></div>
      <div class="overview-card-title" title="${esc(report.customerName)}">${esc(report.customerName)}</div>
      <div class="overview-card-meta">
        <span>${esc(orderLabel(report))}</span>
        <span>${format.date(report.workDate)}</span>
        <span>${format.hours(report.totalHours)}</span>
        <span>${esc(report.creatorName)}</span>
        ${canProcess && report.netTotal ? `<span>${format.money(report.netTotal)} netto</span>` : ""}
        ${report.correctedNumber ? `<span>Korrektur zu ${esc(report.correctedNumber)}</span>` : ""}
      </div>
    </article>`;

  const renderTable = (items, header) => `
    <thead><tr>
      ${header("number", "Nummer", "cell-number")}${header("order", "Auftrag", "cell-number")}${header("customer", "Kunde", "cell-text")}
      ${header("date", "Leistungsdatum", "cell-date")}<th class="cell-count">Stunden</th><th class="cell-text">Erstellt von</th>
      ${header("status", "Status", "cell-status")}<th class="cell-status">Bestätigung</th>${canProcess ? header("total", "Netto", "cell-money") : ""}
    </tr></thead>
    <tbody>${items.map((report) => `
      <tr tabindex="0" data-href="${href(report)}">
        <td class="cell-number">${esc(number(report))}</td>
        <td class="cell-number" title="${esc(orderLabel(report))}">${report.orderId ? esc(report.orderNumber) : '<span class="muted">Ohne Auftrag</span>'}</td>
        <td class="cell-text" title="${esc(report.customerName)}">${esc(report.customerName)}</td>
        <td class="cell-date">${format.date(report.workDate)}</td>
        <td class="cell-count">${format.number(report.totalHours, 2)}</td>
        <td class="cell-text" title="${esc(report.creatorName)}">${esc(report.creatorName)}</td>
        <td class="cell-status">${statusBadge(report)}</td>
        <td class="cell-status">${confirmationBadge(report) || "–"}</td>
        ${canProcess ? `<td class="cell-money">${report.netTotal ? format.money(report.netTotal) : "–"}</td>` : ""}
      </tr>`).join("")}</tbody>`;

  const sorts = {
    updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
    date: { label: "Leistungsdatum", type: "date", dir: "desc" },
    number: { label: "Nummer", dir: "asc" },
    order: { label: "Auftrag", dir: "asc" },
    customer: { label: "Kunde", dir: "asc" },
    status: { label: "Status", dir: "asc" },
  };
  if (canProcess) sorts.total = { label: "Nettobetrag", type: "number", dir: "desc" };

  const list = CalightListView.createListPage({
    root,
    module: "work-reports",
    endpoint: "/api/work-reports",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: {
      status: root.querySelector("[data-status-filter]"),
      confirmation: root.querySelector("[data-confirmation-filter]"),
      order: orderFilter,
      customer: customerFilter,
      assignment: root.querySelector("[data-assignment-filter]"),
    },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "updated",
    sorts,
    emptyText: "Noch keine Rapporte vorhanden.",
    noMatchText: "Keine Rapporte entsprechen der aktuellen Auswahl.",
    loadingText: "Rapporte werden geladen …",
    renderTable,
    renderCard,
  });

  const customerOption = (customer, selected = "") => `<option value="${esc(customer.id)}"${customer.id === selected ? " selected" : ""}>${esc(customer.customerNumber || "Ohne Nr.")} · ${esc(customer.name)}</option>`;

  /** Anlegen: zuerst Kunde (Pflicht), dann optional ein Auftrag dieses Kunden. */
  function newReport() {
    const preselectedOrder = orders.find((order) => order.id === orderFilter.value);
    const preselectedCustomer = preselectedOrder?.customerId || customerFilter.value;
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Kunde <span aria-hidden="true">*</span></span>
        <select name="customerId" required><option value="">Kunde auswählen …</option>${customers.map((customer) => customerOption(customer, preselectedCustomer)).join("")}</select></label>
      <label><span class="field-label">Auftrag (optional)</span>
        <select name="orderId"><option value="">Ohne Auftrag</option></select></label>
      <p class="muted">Ein Rapport kann auch ohne Auftrag angelegt werden. Das Büro kann ihn später einem Auftrag des Kunden zuordnen.</p>
      <p class="field-error" data-dialog-error hidden></p>`;
    const syncOrders = () => {
      const customerId = form.elements.customerId.value;
      const available = orders.filter((order) => order.customerId === customerId);
      form.elements.orderId.innerHTML = '<option value="">Ohne Auftrag</option>' + available
        .map((order) => `<option value="${esc(order.id)}"${order.id === preselectedOrder?.id ? " selected" : ""}>${esc(order.orderNumber)} · ${esc(order.title)}</option>`)
        .join("");
      form.elements.orderId.disabled = available.length === 0;
    };
    form.elements.customerId.addEventListener("change", syncOrders);
    syncOrders();
    Calight.createDialog({
      title: "Rapport anlegen",
      form,
      submitLabel: "Rapport anlegen",
      discardMessage: "Das Anlegen abbrechen?",
      initialFocus: '[name="customerId"]',
      submit: () => create({ customerId: form.elements.customerId.value, orderId: form.elements.orderId.value }),
    });
  }

  async function init() {
    const reference = await api("/api/work-reports/reference-data");
    orders = reference.orders;
    customers = reference.customers;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders
      .map((order) => `<option value="${esc(order.id)}">${esc(order.orderNumber)} · ${esc(order.title)}</option>`)
      .join("");
    customerFilter.innerHTML = '<option value="">Alle Kunden</option>' + customers.map((customer) => customerOption(customer)).join("");
    list.start();
  }

  root.querySelector("[data-new]")?.addEventListener("click", newReport);
  init().catch((exception) => notify(message, exception.message));
})();
