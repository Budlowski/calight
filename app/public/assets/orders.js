(() => {
  "use strict";

  const root = document.querySelector("#orders-page");
  if (!root) return;

  const { esc, api, format, collator, normalize, matches, notify } = Calight;
  const results = root.querySelector("[data-list]");
  const search = root.querySelector("[data-search]");
  const status = root.querySelector("[data-status]");
  const type = root.querySelector("[data-type]");
  const sort = root.querySelector("[data-sort]");
  const count = root.querySelector("[data-count]");
  const reset = root.querySelector("[data-reset]");
  const message = root.querySelector("[data-message]");
  const labels = { open: "Offen", in_progress: "In Bearbeitung", completed: "Abgeschlossen", invoiced: "Abgerechnet" };

  let orders = [];
  let visible = [];

  const href = (order) => `/orders/${encodeURIComponent(order.id)}`;
  const badge = (order) => `<span class="status-badge status-${esc(order.status)}">${esc(labels[order.status] || order.status)}</span>`;
  const source = (order) => order.orderType === "offer"
    ? `Angebot ${order.sourceOfferNumber || "ohne Nummer"}${Number(order.sourceRevision) > 0 ? ` · Revision ${Number(order.sourceRevision)}` : ""}`
    : "Regieauftrag";

  const card = (order) => `
    <article class="overview-card" tabindex="0" data-href="${href(order)}">
      <div class="management-card-head"><span class="service-number">${esc(order.orderNumber)}</span>${badge(order)}</div>
      <div class="overview-card-title" title="${esc(order.title)}">${esc(order.title)}</div>
      <div class="overview-card-meta">
        <span>${esc(order.customerName)}</span>
        <span>${esc(order.projectName || "Ohne Projekt")}</span>
        <span>${esc(source(order))}</span>
        <span>${format.date(order.orderDate)}</span>
      </div>
    </article>`;

  const table = () => `
    <div class="overview-table-wrap"><table class="overview-table">
      <thead><tr><th>Auftragsnummer</th><th>Bezeichnung</th><th>Kunde</th><th>Projekt</th><th>Auftragsart</th><th>Status</th><th>Auftragsdatum</th></tr></thead>
      <tbody>${visible.map((order) => `
        <tr tabindex="0" data-href="${href(order)}">
          <td class="cell-number">${esc(order.orderNumber)}</td>
          <td class="cell-title" title="${esc(order.title)}">${esc(order.title)}</td>
          <td>${esc(order.customerName)}</td>
          <td>${esc(order.projectName || "Ohne Projekt")}</td>
          <td>${esc(source(order))}</td>
          <td>${badge(order)}</td>
          <td class="cell-date">${format.date(order.orderDate)}</td>
        </tr>`).join("")}</tbody>
    </table></div>`;

  function paint(view) {
    if (!visible.length) {
      results.innerHTML = '<p class="overview-empty">Keine Aufträge entsprechen der aktuellen Auswahl.</p>';
      return;
    }
    results.innerHTML = view === "cards" ? `<div class="overview-card-grid">${visible.map(card).join("")}</div>` : table();
  }

  const view = CalightListView.create({ root, module: "orders", render: paint });
  CalightListView.enableRowNavigation(results);

  function render() {
    const query = normalize(search.value.trim());
    visible = orders
      .filter((order) => (!status.value || order.status === status.value)
        && (!type.value || order.orderType === type.value)
        && matches(query, order.orderNumber, order.title, order.customerName, order.customerNumber,
          order.projectName, order.projectNumber, order.sourceOfferNumber))
      .sort((a, b) => {
        if (sort.value === "number") return collator.compare(a.orderNumber, b.orderNumber);
        if (sort.value === "title") return collator.compare(a.title, b.title);
        return String(b.updatedAt).localeCompare(String(a.updatedAt));
      });
    count.textContent = `${visible.length} von ${orders.length}`;
    reset.disabled = !query && !status.value && !type.value && sort.value === "updated";
    view.render();
  }

  async function load() {
    results.innerHTML = '<p class="overview-empty">Aufträge werden geladen …</p>';
    orders = (await api("/api/orders")).orders;
    render();
  }

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

  for (const element of [search, status, type, sort]) element.addEventListener(element === search ? "input" : "change", render);
  reset.addEventListener("click", () => {
    search.value = "";
    status.value = "";
    type.value = "";
    sort.value = "updated";
    render();
    search.focus();
  });
  root.querySelector("[data-new-order]")?.addEventListener("click", newOrder);
  load().catch((error) => notify(message, error.message));
})();
