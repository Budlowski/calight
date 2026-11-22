// Aufmaße: Anlegen (Übersicht und Auftragsdetailseite) und zentrale Übersicht.
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const STATUS_LABELS = { draft: "Entwurf", completed: "Abgeschlossen" };
  const href = (measurement) => Calight.paths.measurement(measurement.id);
  const badge = (measurement) => `<span class="status-badge status-measurement-${esc(measurement.status)}">${STATUS_LABELS[measurement.status] || esc(measurement.status)}</span>`;
  const number = (measurement) => measurement.measurementNumber || "Entwurf";
  const total = (measurement) => (measurement.netTotal === null ? "–" : format.money(measurement.netTotal));

  /** Legt einen Entwurf an und öffnet ihn direkt; Mengen werden auf der Bearbeitungsseite erfasst. */
  async function createForOrder(orderId) {
    const measurement = await api("/api/measurements", { method: "POST", body: { orderId } });
    location.assign(href(measurement));
  }

  globalThis.CalightMeasurements = Object.freeze({ createForOrder, badge, number, total, href });

  // Auftragsdetailseite: Button „Aufmaß anlegen“.
  document.querySelectorAll("[data-create-measurement]").forEach((button) => button.addEventListener("click", async () => {
    button.disabled = true;
    try {
      await createForOrder(button.dataset.createMeasurement);
    } catch (exception) {
      button.disabled = false;
      notify(document.querySelector("[data-measurement-message]"), exception.message);
    }
  }));

  const root = document.querySelector("#measurements-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  let orders = [];

  const renderCard = (measurement) => `
    <article class="overview-card" tabindex="0" data-href="${href(measurement)}">
      <div class="management-card-head"><span class="service-number">${esc(number(measurement))}</span>${badge(measurement)}</div>
      <div class="overview-card-title" title="${esc(measurement.title)}">${esc(measurement.title)}</div>
      <div class="overview-card-meta">
        <span>${esc(measurement.orderNumber)} · ${esc(measurement.customerName)}</span>
        <span>${format.date(measurement.measurementDate)}</span>
        <span>${measurement.itemCount} Positionen</span>
        ${measurement.netTotal === null ? "" : `<span>${total(measurement)} netto</span>`}
        ${measurement.correctedNumber ? `<span>Korrektur zu ${esc(measurement.correctedNumber)}</span>` : ""}
      </div>
    </article>`;

  const renderTable = (items, header) => `
    <thead><tr>
      ${header("number", "Nummer", "cell-number")}<th class="cell-title">Bezeichnung</th>${header("order", "Auftrag", "cell-number")}
      ${header("customer", "Kunde", "cell-text")}${header("date", "Datum", "cell-date")}<th class="cell-count">Positionen</th>
      ${header("status", "Status", "cell-status")}${header("total", "Netto", "cell-money")}
    </tr></thead>
    <tbody>${items.map((measurement) => `
      <tr tabindex="0" data-href="${href(measurement)}">
        <td class="cell-number">${esc(number(measurement))}</td>
        <td class="cell-title" title="${esc(measurement.title)}">${esc(measurement.title)}</td>
        <td class="cell-number">${esc(measurement.orderNumber)}</td>
        <td class="cell-text" title="${esc(measurement.customerName)}">${esc(measurement.customerName)}</td>
        <td class="cell-date">${format.date(measurement.measurementDate)}</td>
        <td class="cell-count">${measurement.itemCount}</td>
        <td class="cell-status">${badge(measurement)}</td>
        <td class="cell-money">${total(measurement)}</td>
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage({
    root,
    module: "measurements",
    endpoint: "/api/measurements",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: { status: root.querySelector("[data-status-filter]"), order: orderFilter },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "updated",
    sorts: {
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
      date: { label: "Aufmaßdatum", type: "date", dir: "desc" },
      number: { label: "Nummer", dir: "asc" },
      order: { label: "Auftrag", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      status: { label: "Status", dir: "asc" },
      total: { label: "Nettosumme", type: "number", dir: "desc" },
    },
    emptyText: "Noch keine Aufmaße vorhanden.",
    noMatchText: "Keine Aufmaße entsprechen der aktuellen Auswahl.",
    loadingText: "Aufmaße werden geladen …",
    renderTable,
    renderCard,
  });

  function newMeasurement() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    const preselected = root.dataset.initialOrder || orderFilter.value;
    form.innerHTML = `
      <label><span class="field-label">Auftrag <span aria-hidden="true">*</span></span>
        <select name="orderId" required><option value="">Auftrag auswählen …</option>${orders
          .map((order) => `<option value="${esc(order.id)}"${order.id === preselected ? " selected" : ""}>${esc(order.order_number)} · ${esc(order.title)}</option>`)
          .join("")}</select></label>
      <p class="muted">Aufgemessen werden die Positionen der angenommenen Angebotsrevision und angenommener Nachträge.</p>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
    Calight.createDialog({
      title: "Aufmaß anlegen",
      form,
      submitLabel: "Anlegen",
      discardMessage: "Das Anlegen abbrechen?",
      initialFocus: ".suggest-select input",
      submit: () => createForOrder(form.elements.orderId.value),
    });
  }

  async function init() {
    orders = (await api("/api/daily-reports/reference-data")).orders;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders
      .map((order) => `<option value="${esc(order.id)}">${esc(order.order_number)} · ${esc(order.title)}</option>`)
      .join("");
    list.start();
    if (root.dataset.initialOrder) newMeasurement();
  }

  root.querySelector("[data-new]")?.addEventListener("click", newMeasurement);
  init().catch((exception) => notify(message, exception.message));
})();
