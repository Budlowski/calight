(() => {
  "use strict";

  const root = document.querySelector("#daily-reports-app");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const employeeFilter = root.querySelector("[data-employee-filter]");

  let orders = [];

  const href = (report) => `/daily-reports/${encodeURIComponent(report.id)}`;
  const titleOf = (report) => report.title || report.order_title;
  const orderOption = (order, selected = "") => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.order_number)} · ${esc(order.title)}</option>`;

  const renderCard = (report) => `
    <article class="overview-card" tabindex="0" data-href="${href(report)}">
      <div class="management-card-head"><span class="service-number">${format.date(report.report_date)}</span><span>${format.hours(report.total_hours)}</span></div>
      <div class="overview-card-title" title="${esc(titleOf(report))}">${esc(titleOf(report))}</div>
      <div class="overview-card-meta">
        <span>${esc(report.order_number)} · ${esc(report.customer_name)}</span>
        <span>${esc(report.employee_names || "Keine Arbeitszeiten")}</span>
        ${report.total_labor_cost !== undefined ? `<span>${format.money(report.total_labor_cost)} Ist-Lohnkosten</span>` : ""}
        <span>Geändert ${format.dateTime(report.updated_at)}</span>
      </div>
    </article>`;

  const renderTable = (reports, header) => `
    <thead><tr>
      ${header("date", "Berichtsdatum", "cell-date")}${header("order", "Auftrag", "cell-number")}${header("customer", "Kunde", "cell-text")}
      ${header("title", "Bezeichnung", "cell-title")}<th class="cell-text">Mitarbeiter</th>${header("hours", "Gesamtstunden", "cell-money")}${header("updated", "Geändert", "cell-datetime")}
    </tr></thead>
    <tbody>${reports.map((report) => `
      <tr tabindex="0" data-href="${href(report)}">
        <td class="cell-date">${format.date(report.report_date)}</td>
        <td class="cell-number">${esc(report.order_number)}</td>
        <td class="cell-text" title="${esc(report.customer_name)}">${esc(report.customer_name)}</td>
        <td class="cell-title" title="${esc(report.title || report.activities)}">${esc(report.title || "Ohne Bezeichnung")}</td>
        <td class="cell-text" title="${esc(report.employee_names || "")}">${esc(report.employee_names || "–")}</td>
        <td class="cell-money">${format.hours(report.total_hours)}</td>
        <td class="cell-datetime">${format.dateTime(report.updated_at)}</td>
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage({
    root,
    module: "daily-reports",
    endpoint: "/api/daily-reports",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: { order: orderFilter, date: root.querySelector("[data-date-filter]"), employee: employeeFilter },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "date",
    sorts: {
      date: { label: "Berichtsdatum", type: "date", dir: "desc" },
      order: { label: "Auftrag", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      title: { label: "Bezeichnung", dir: "asc" },
      hours: { label: "Gesamtstunden", type: "number", dir: "desc" },
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
    },
    emptyText: "Noch keine Bautagesberichte vorhanden.",
    noMatchText: "Keine Bautagesberichte entsprechen den gewählten Filtern.",
    loadingText: "Bautagesberichte werden geladen …",
    renderTable,
    renderCard,
  });

  function createReport() {
    const form = document.createElement("form");
    // Vorauswahl: Einstieg vom Auftrag oder aktuell gefilterter Auftrag.
    const preselected = root.dataset.initialOrder || orderFilter.value;
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Auftrag <span aria-hidden="true">*</span></span>
        <select name="orderId" required><option value="">Auftrag auswählen …</option>${orders.map((order) => orderOption(order, preselected)).join("")}</select></label>
      <label><span class="field-label">Berichtsdatum <span aria-hidden="true">*</span></span><input name="reportDate" type="date" required></label>
      <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200"></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    const now = new Date();
    form.elements.reportDate.value = new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

    Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer, Bezeichnung oder Kunde …" });
    Calight.createDialog({
      title: "Bautagesbericht anlegen",
      form,
      submitLabel: "Anlegen",
      discardMessage: "Den begonnenen Bautagesbericht verwerfen?",
      initialFocus: preselected ? '[name="reportDate"]' : ".suggest-select input",
      submit: async () => {
        const report = await api("/api/daily-reports", {
          method: "POST",
          body: { ...Object.fromEntries(new FormData(form)), activities: "", notes: "", entries: [] },
        });
        location.assign(href(report));
      },
    });
  }

  async function init() {
    if (root.dataset.initialReport) {
      location.replace(`/daily-reports/${encodeURIComponent(root.dataset.initialReport)}`);
      return;
    }
    // Filteroptionen zuerst laden, damit Werte aus der URL ausgewählt werden können.
    const reference = await api("/api/daily-reports/reference-data");
    orders = reference.orders;
    const employees = reference.filterEmployees || reference.employees;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders.map((order) => orderOption(order)).join("");
    employeeFilter.innerHTML = '<option value="">Alle Mitarbeiter</option>' + employees
      .map((employee) => `<option value="${esc(employee.id)}">${esc(employee.display_name)}${Number(employee.active) ? "" : " (inaktiv)"}</option>`)
      .join("");
    list.start();
    if (root.dataset.initialOrder) {
      // Der Einstieg „Bericht anlegen“ öffnet den Dialog nur einmal, nicht erneut über die History.
      const url = new URL(location.href);
      url.searchParams.delete("create_for");
      history.replaceState(null, "", url.pathname + url.search);
      createReport();
    }
  }

  root.querySelector("[data-new]")?.addEventListener("click", createReport);
  init().catch((error) => notify(message, error.message));
})();
