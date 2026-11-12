(() => {
  "use strict";

  const root = document.querySelector("#orders-page");
  if (!root) return;

  const { esc, api, format } = Calight;
  const labels = { open: "Offen", in_progress: "In Bearbeitung", completed: "Abgeschlossen", invoiced: "Abgerechnet" };

  const href = (order) => `/orders/${encodeURIComponent(order.id)}`;
  const badge = (order) => `<span class="status-badge status-${esc(order.status)}">${esc(labels[order.status] || order.status)}</span>`;
  const total = (order) => (order.contractNetTotal === null ? "–" : format.money(order.contractNetTotal));
  const source = (order) => order.orderType === "offer"
    ? `Angebot ${order.sourceOfferNumber || "ohne Nummer"}${Number(order.sourceRevision) > 0 ? ` · Revision ${Number(order.sourceRevision)}` : ""}`
    : "Regieauftrag";

  const renderCard = (order) => `
    <article class="overview-card" tabindex="0" data-href="${href(order)}">
      <div class="management-card-head"><span class="service-number">${esc(order.orderNumber)}</span>${badge(order)}</div>
      <div class="overview-card-title" title="${esc(order.title)}">${esc(order.title)}</div>
      <div class="overview-card-meta">
        <span>${esc(order.customerName)}</span>
        <span>${esc(order.projectName || "Ohne Projekt")}</span>
        <span>${esc(source(order))}</span>
        <span>${format.date(order.orderDate)}</span>
        ${order.contractNetTotal !== null ? `<span>${total(order)} netto</span>` : ""}
      </div>
    </article>`;

  const renderTable = (orders, header) => `
    <thead><tr>
      ${header("number", "Auftragsnummer", "cell-number")}${header("title", "Bezeichnung", "cell-title")}${header("customer", "Kunde", "cell-text")}
      <th class="cell-text">Projekt</th><th class="cell-text">Auftragsart</th>${header("status", "Status", "cell-status")}${header("date", "Auftragsdatum", "cell-date")}
      ${header("total", "Auftragssumme", "cell-money")}
    </tr></thead>
    <tbody>${orders.map((order) => `
      <tr tabindex="0" data-href="${href(order)}">
        <td class="cell-number">${esc(order.orderNumber)}</td>
        <td class="cell-title" title="${esc(order.title)}">${esc(order.title)}</td>
        <td class="cell-text" title="${esc(order.customerName)}">${esc(order.customerName)}</td>
        <td class="cell-text" title="${esc(order.projectName || "")}">${esc(order.projectName || "Ohne Projekt")}</td>
        <td class="cell-text">${esc(source(order))}</td>
        <td class="cell-status">${badge(order)}</td>
        <td class="cell-date">${format.date(order.orderDate)}</td>
        <td class="cell-money">${total(order)}</td>
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage({
    root,
    module: "orders",
    endpoint: "/api/orders",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: { status: root.querySelector("[data-status]"), type: root.querySelector("[data-type]") },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message: root.querySelector("[data-message]"),
    defaultSort: "updated",
    sorts: {
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
      number: { label: "Auftragsnummer", dir: "asc" },
      title: { label: "Bezeichnung", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      status: { label: "Status", dir: "asc" },
      date: { label: "Auftragsdatum", type: "date", dir: "desc" },
      total: { label: "Auftragssumme", type: "number", dir: "desc" },
    },
    emptyText: "Noch keine Aufträge vorhanden.",
    noMatchText: "Keine Aufträge entsprechen der aktuellen Auswahl.",
    loadingText: "Aufträge werden geladen …",
    renderTable,
    renderCard,
  });

  function newOrder() {
    const form = root.querySelector("[data-order-template]").content.firstElementChild.cloneNode(true);
    Calight.bindProjectToCustomer(form);
    Calight.createDialog({
      title: "Regieauftrag anlegen",
      form,
      submitLabel: "Auftrag anlegen",
      discardMessage: "Den begonnenen Regieauftrag verwerfen?",
      initialFocus: '[name="customerId"]',
      submit: async () => {
        const order = await api("/api/orders", { method: "POST", body: Object.fromEntries(new FormData(form)) });
        location.assign(href(order));
      },
    });
  }

  root.querySelector("[data-new-order]")?.addEventListener("click", newOrder);
  list.start();
})();
