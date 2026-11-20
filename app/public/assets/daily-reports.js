(() => {
  "use strict";

  const root = document.querySelector("#daily-reports-app");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const employeeFilter = root.querySelector("[data-employee-filter]");
  const customerFilter = root.querySelector("[data-customer-filter]");
  const projectFilter = root.querySelector("[data-project-filter]");

  let orders = [];
  let costTargets = [];
  let customers = [];
  let projects = [];

  const href = (report) => `/daily-reports/${encodeURIComponent(report.id)}`;
  const titleOf = (report) => report.title || report.order_title || report.project_name || "Ohne Bezeichnung";
  /** Kostenziel kompakt: Auftrag, sonst Kostenstelle, sonst „Ohne Zuordnung“. */
  const assignmentOf = (report) => report.order_number || report.cost_center_number || "Ohne Zuordnung";
  const customerOption = (customer, selected = "") => `<option value="${esc(customer.id)}"${customer.id === selected ? " selected" : ""}>${esc(customer.customerNumber || "Ohne Nr.")} · ${esc(customer.name)}</option>`;
  const projectOption = (project, selected = "") => `<option value="${esc(project.id)}"${project.id === selected ? " selected" : ""}>${esc(project.projectNumber ? `${project.projectNumber} · ` : "")}${esc(project.name)}</option>`;
  const orderOption = (order, selected = "") => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.order_number)} · ${esc(order.title)}</option>`;

  const renderCard = (report) => `
    <article class="overview-card" tabindex="0" data-href="${href(report)}">
      <div class="management-card-head"><span class="service-number">${format.date(report.report_date)}</span><span>${format.hours(report.total_hours)}</span></div>
      <div class="overview-card-title" title="${esc(titleOf(report))}">${esc(titleOf(report))}</div>
      <div class="overview-card-meta">
        <span>${esc(assignmentOf(report))} · ${esc(report.customer_name)}${report.project_name ? ` · ${esc(report.project_name)}` : ""}</span>
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
        <td class="cell-number" title="${esc(report.order_title || report.cost_center_name || "")}">${report.order_number ? esc(report.order_number) : report.cost_center_number ? esc(report.cost_center_number) : '<span class="muted">Ohne Zuordnung</span>'}</td>
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
    primaryFilters: ["customer"],
    filters: { customer: customerFilter, project: projectFilter, assignment: root.querySelector("[data-assignment-filter]"), order: orderFilter, date: root.querySelector("[data-date-filter]"), employee: employeeFilter },
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

  /**
   * Anlegen: Auftrag wählen (Kunde und Projekt werden übernommen) oder ohne Auftrag
   * direkt einen Kunden und optional ein Projekt wählen. Es wird nie ein Auftrag angelegt.
   */
  function createReport() {
    const form = document.createElement("form");
    // Vorauswahl: Einstieg vom Auftrag oder aktuell gefilterter Auftrag/Kunde.
    const preselectedOrder = orders.find((order) => order.id === (root.dataset.initialOrder || orderFilter.value));
    const preselectedCustomer = preselectedOrder?.customer_id || customerFilter.value;
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Kunde <span aria-hidden="true">*</span></span>
        <select name="customerId" required><option value="">Kunde auswählen …</option>${customers.map((customer) => customerOption(customer, preselectedCustomer)).join("")}</select></label>
      <label><span class="field-label">Auftrag / Kostenstelle (optional)</span><select name="costTarget"></select></label>
      <label data-project-field><span class="field-label">Projekt (optional)</span><select name="projectId"></select></label>
      <label><span class="field-label">Berichtsdatum <span aria-hidden="true">*</span></span><input name="reportDate" type="date" required></label>
      <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200" placeholder="z. B. Besichtigung, Bestandsaufnahme"></label>
      <p class="muted">Ohne Zuordnung oder auf eine Kostenstelle (z. B. Vertrieb & Angebote) etwa für Besichtigungen vor dem Angebot. Das Büro kann die Zuordnung später ändern.</p>
      <p class="field-error" data-dialog-error hidden></p>`;
    const now = new Date();
    form.elements.reportDate.value = new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    let picker = null;
    const sync = () => {
      const customerId = form.elements.customerId.value;
      // Gemeinsames Feld: Aufträge dieses Kunden und allgemeine Kostenstellen.
      const current = Calight.parseCostTarget(form.elements.costTarget.value);
      if (!form.elements.costTarget.value && preselectedOrder && !picker) current.orderId = preselectedOrder.id;
      const customerOrders = new Set(orders.filter((order) => order.customer_id === customerId).map((order) => order.id));
      const targets = costTargets.filter((target) => target.type !== "order" || customerOrders.has(target.id));
      if (current.orderId && !customerOrders.has(current.orderId)) current.orderId = "";
      form.elements.costTarget.innerHTML = Calight.costTargetOptions(targets, { ...current, emptyLabel: "Ohne Zuordnung" });
      picker?.refresh();
      // Mit Auftrag gilt dessen Projekt; sonst optional ein Projekt des Kunden.
      const withOrder = Boolean(Calight.parseCostTarget(form.elements.costTarget.value).orderId);
      const customerProjects = projects.filter((project) => project.customerId === customerId);
      form.elements.projectId.innerHTML = '<option value="">Ohne Projekt</option>' + customerProjects.map((project) => projectOption(project, form.elements.projectId.value)).join("");
      form.querySelector("[data-project-field]").hidden = withOrder || customerProjects.length === 0;
      if (withOrder) form.elements.projectId.value = "";
    };
    form.elements.customerId.addEventListener("change", sync);
    form.elements.costTarget.addEventListener("change", sync);
    sync();
    picker = Calight.searchableSelect(form.elements.costTarget, { placeholder: "Auftrag oder Kostenstelle: Nummer oder Bezeichnung …" });
    Calight.searchableSelect(form.elements.customerId, { placeholder: "Kundennummer oder Name eingeben …" });
    Calight.createDialog({
      title: "Bautagesbericht anlegen",
      form,
      submitLabel: "Anlegen",
      discardMessage: "Den begonnenen Bautagesbericht verwerfen?",
      initialFocus: preselectedCustomer ? '[name="reportDate"]' : ".suggest-select input",
      submit: async () => {
        const report = await api("/api/daily-reports", {
          method: "POST",
          body: (({ costTarget, ...fields }) => ({ ...fields, ...Calight.parseCostTarget(costTarget), activities: "", notes: "", entries: [] }))(Object.fromEntries(new FormData(form))),
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
    costTargets = reference.costTargets || [];
    customers = reference.customers || [];
    projects = reference.projects || [];
    customerFilter.innerHTML = '<option value="">Alle Kunden</option>' + customers.map((customer) => customerOption(customer)).join("");
    projectFilter.innerHTML = '<option value="">Alle Projekte</option>' + projects.map((project) => projectOption(project)).join("");
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
