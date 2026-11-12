(() => {
  "use strict";

  const root = document.querySelector("#daily-reports-app");
  if (!root) return;

  const { esc, api, format, collator, normalize, matches, notify } = Calight;
  const results = root.querySelector("[data-list]");
  const search = root.querySelector("[data-search]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const dateFilter = root.querySelector("[data-date-filter]");
  const employeeFilter = root.querySelector("[data-employee-filter]");
  const sort = root.querySelector("[data-sort]");
  const count = root.querySelector("[data-count]");
  const reset = root.querySelector("[data-reset]");
  const message = root.querySelector("[data-message]");

  let reports = [];
  let orders = [];
  let visible = [];

  const href = (report) => `/daily-reports/${encodeURIComponent(report.id)}`;
  const titleOf = (report) => report.title || report.order_title;
  const orderOption = (order, selected = "") => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.order_number)} · ${esc(order.title)}</option>`;

  const card = (report) => `
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

  const table = () => `
    <div class="overview-table-wrap"><table class="overview-table">
      <thead><tr><th>Berichtsdatum</th><th>Auftrag</th><th>Kunde</th><th>Bezeichnung</th><th>Mitarbeiter</th><th class="cell-money">Gesamtstunden</th><th>Geändert</th></tr></thead>
      <tbody>${visible.map((report) => `
        <tr tabindex="0" data-href="${href(report)}">
          <td class="cell-date">${format.date(report.report_date)}</td>
          <td class="cell-number">${esc(report.order_number)}</td>
          <td>${esc(report.customer_name)}</td>
          <td class="cell-title" title="${esc(report.title || report.activities)}">${esc(report.title || "Ohne Bezeichnung")}</td>
          <td title="${esc(report.employee_names || "")}">${esc(report.employee_names || "–")}</td>
          <td class="cell-money">${format.hours(report.total_hours)}</td>
          <td class="cell-date">${format.dateTime(report.updated_at)}</td>
        </tr>`).join("")}</tbody>
    </table></div>`;

  function paint(view) {
    if (!visible.length) {
      results.innerHTML = '<p class="overview-empty">Keine Bautagesberichte entsprechen den gewählten Filtern.</p>';
      return;
    }
    results.innerHTML = view === "cards" ? `<div class="overview-card-grid">${visible.map(card).join("")}</div>` : table();
  }

  const view = CalightListView.create({ root, module: "daily-reports", render: paint });
  CalightListView.enableRowNavigation(results);

  function render() {
    const query = normalize(search.value.trim());
    visible = reports
      .filter((report) => (!orderFilter.value || report.order_id === orderFilter.value)
        && (!dateFilter.value || report.report_date === dateFilter.value)
        && (!employeeFilter.value || String(report.employee_ids || "").split(",").includes(employeeFilter.value))
        && matches(query, report.order_number, report.order_title, report.customer_name, report.title, report.activities, report.employee_names))
      .sort((a, b) => {
        if (sort.value === "date-asc") return String(a.report_date).localeCompare(String(b.report_date));
        if (sort.value === "order") return collator.compare(String(a.order_number), String(b.order_number));
        return String(b.report_date).localeCompare(String(a.report_date));
      });
    count.textContent = `${visible.length} von ${reports.length}`;
    reset.disabled = !query && !orderFilter.value && !dateFilter.value && !employeeFilter.value && sort.value === "date-desc";
    view.render();
  }

  function createReport() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Auftrag <span aria-hidden="true">*</span></span>
        <select name="orderId" required><option value="">Auftrag auswählen …</option>${orders.map((order) => orderOption(order, root.dataset.initialOrder)).join("")}</select></label>
      <label><span class="field-label">Berichtsdatum <span aria-hidden="true">*</span></span><input name="reportDate" type="date" required></label>
      <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200"></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    const now = new Date();
    form.elements.reportDate.value = new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

    Calight.createDialog({
      title: "Bautagesbericht anlegen",
      form,
      submitLabel: "Bericht anlegen",
      discardMessage: "Den begonnenen Bautagesbericht verwerfen?",
      initialFocus: root.dataset.initialOrder ? '[name="reportDate"]' : '[name="orderId"]',
      submit: async () => {
        const report = await api("/api/daily-reports", {
          method: "POST",
          body: { ...Object.fromEntries(new FormData(form)), activities: "", notes: "", entries: [] },
        });
        location.assign(href(report));
      },
    });
  }

  async function load() {
    results.innerHTML = '<p class="overview-empty">Bautagesberichte werden geladen …</p>';
    const [reference, result] = await Promise.all([api("/api/daily-reports/reference-data"), api("/api/daily-reports")]);
    if (root.dataset.initialReport) {
      location.replace(`/daily-reports/${encodeURIComponent(root.dataset.initialReport)}`);
      return;
    }
    orders = reference.orders;
    reports = result.reports;
    const employees = reference.filterEmployees || reference.employees;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders.map((order) => orderOption(order)).join("");
    employeeFilter.innerHTML = '<option value="">Alle Mitarbeiter</option>' + employees
      .map((employee) => `<option value="${esc(employee.id)}">${esc(employee.display_name)}${Number(employee.active) ? "" : " (inaktiv)"}</option>`)
      .join("");
    render();
    if (root.dataset.initialOrder) createReport();
  }

  for (const field of [search, orderFilter, dateFilter, employeeFilter, sort]) field.addEventListener(field === search ? "input" : "change", render);
  reset.addEventListener("click", () => {
    search.value = "";
    orderFilter.value = "";
    dateFilter.value = "";
    employeeFilter.value = "";
    sort.value = "date-desc";
    render();
    search.focus();
  });
  root.querySelector("[data-new]")?.addEventListener("click", createReport);
  load().catch((error) => notify(message, error.message));
})();
