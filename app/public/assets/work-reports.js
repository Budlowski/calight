// Rapporte: Anlegen (Übersicht und Auftragsdetailseite) und zentrale Übersicht.
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const STATUS_LABELS = { draft: "Entwurf", submitted: "Eingereicht", processed: "Bearbeitet" };
  const href = (report) => `/work-reports/${encodeURIComponent(report.id)}`;
  const statusBadge = (report) => `<span class="status-badge status-work-${esc(report.status)}">${STATUS_LABELS[report.status] || esc(report.status)}</span>`;
  const confirmationBadge = (report) => (report.confirmationStatus === "confirmed" ? '<span class="status-badge status-work-confirmed">Bestätigt</span>' : "");
  const number = (report) => report.reportNumber || "Entwurf";

  /** Legt einen Entwurf an und öffnet ihn; die Erfassung erfolgt auf der Detailseite. */
  async function createForOrder(orderId) {
    const report = await api("/api/work-reports", { method: "POST", body: { orderId } });
    location.assign(href(report));
  }

  globalThis.CalightWorkReports = Object.freeze({ createForOrder, statusBadge, confirmationBadge, number, href });

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
  const canProcess = root.dataset.canProcess === "1";
  let orders = [];

  const renderCard = (report) => `
    <article class="overview-card" tabindex="0" data-href="${href(report)}">
      <div class="management-card-head"><span class="service-number">${esc(number(report))}</span><span class="management-card-badges">${confirmationBadge(report)}${statusBadge(report)}</span></div>
      <div class="overview-card-title" title="${esc(report.orderTitle)}">${esc(report.orderNumber)} · ${esc(report.orderTitle)}</div>
      <div class="overview-card-meta">
        <span>${esc(report.customerName)}</span>
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
        <td class="cell-number" title="${esc(report.orderTitle)}">${esc(report.orderNumber)}</td>
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

  function newReport() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Auftrag <span aria-hidden="true">*</span></span>
        <select name="orderId" required><option value="">Auftrag auswählen …</option>${orders
          .map((order) => `<option value="${esc(order.id)}"${order.id === orderFilter.value ? " selected" : ""}>${esc(order.orderNumber)} · ${esc(order.title)} · ${esc(order.customerName)}</option>`)
          .join("")}</select></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.createDialog({
      title: "Rapport anlegen",
      form,
      submitLabel: "Rapport anlegen",
      discardMessage: "Das Anlegen abbrechen?",
      initialFocus: '[name="orderId"]',
      submit: () => createForOrder(form.elements.orderId.value),
    });
  }

  async function init() {
    orders = (await api("/api/work-reports/reference-data")).orders;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders
      .map((order) => `<option value="${esc(order.id)}">${esc(order.orderNumber)} · ${esc(order.title)}</option>`)
      .join("");
    list.start();
  }

  root.querySelector("[data-new]")?.addEventListener("click", newReport);
  init().catch((exception) => notify(message, exception.message));
})();
