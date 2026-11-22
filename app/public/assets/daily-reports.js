(() => {
  "use strict";

  // Gemeinsamer Anlegedialog (Büro-Übersicht und mobile Übersicht) und Übersicht der Büroansicht.
  const root = document.querySelector("#daily-reports-app");
  const { esc, api, format, notify } = Calight;

  let orders = [];
  let costTargets = [];
  let customers = [];
  let projects = [];
  let reference = null;

  /** Auswahllisten einmal laden (Aufträge, Kostenziele, Kunden, Projekte mit den Freigaberegeln). */
  async function loadReference() {
    if (reference) return reference;
    reference = await api("/api/daily-reports/reference-data");
    orders = reference.orders;
    costTargets = reference.costTargets || [];
    customers = reference.customers || [];
    projects = reference.projects || [];
    return reference;
  }

  const href = (report) => Calight.paths.dailyReport(report.id);
  const titleOf = (report) => report.title || report.order_title || report.project_name || "Ohne Bezeichnung";
  /** Kostenziel kompakt: Auftrag, sonst Kostenstelle, sonst „Ohne Zuordnung“. */
  /** Statuskennzeichen (Entwurf, eingereicht, festgeschrieben, Korrektur, storniert). */
  const statusBadge = (report) => `<span class="status-badge is-report-${esc(report.status || "draft")}">${esc(report.status_label || "Entwurf")}</span>`;
  const assignmentOf = (report) => report.order_number || report.cost_center_number || "Ohne Zuordnung";
  const customerOption = (customer, selected = "") => `<option value="${esc(customer.id)}"${customer.id === selected ? " selected" : ""}>${esc(customer.customerNumber || "Ohne Nr.")} · ${esc(customer.name)}</option>`;
  const projectOption = (project, selected = "") => `<option value="${esc(project.id)}"${project.id === selected ? " selected" : ""}>${esc(project.projectNumber ? `${project.projectNumber} · ` : "")}${esc(project.name)}</option>`;
  const orderOption = (order, selected = "") => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.order_number)} · ${esc(order.title)}</option>`;

  /**
   * Anlegen: primär optional ein Kostenziel „Auftrag / Kostenstelle“, dann Datum und Bezeichnung;
   * Kunde und Projekt sind nachgeordnete, optionale Kontextangaben. Mit Auftrag gelten dessen Kunde
   * und Projekt (nur dezent angezeigt). Es wird nie ein Auftrag oder Kunde angelegt.
   */
  async function createReport({ orderId = "", customerId = "" } = {}) {
    await loadReference();
    const form = document.createElement("form");
    // Vorauswahl: Einstieg vom Auftrag oder aktuell gefilterter Auftrag/Kunde.
    const preselectedOrder = orders.find((order) => order.id === orderId);
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Auftrag / Kostenstelle (optional)</span><select name="costTarget"></select></label>
      <p class="muted daily-report-order-context" data-order-context hidden></p>
      <label><span class="field-label">Berichtsdatum <span aria-hidden="true">*</span></span><input name="reportDate" type="date" required></label>
      <label><span class="field-label">Bezeichnung (optional)</span><input name="title" maxlength="200" placeholder="z. B. Lagerinventur, Besichtigung, Montage"></label>
      <div class="dialog-form-grid" data-context-fields>
        <label><span class="field-label">Kunde (optional)</span><select name="customerId"><option value="">Ohne Kunde</option>${customers.map((customer) => customerOption(customer, preselectedOrder ? "" : customerId)).join("")}</select></label>
        <label data-project-field><span class="field-label">Projekt (optional)</span><select name="projectId"></select></label>
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    const now = new Date();
    form.elements.reportDate.value = new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    Calight.costTargetSelect(form.elements.costTarget, costTargets, { orderId: preselectedOrder?.id || "", emptyLabel: "Ohne Zuordnung" });
    const sync = () => {
      const target = Calight.parseCostTarget(form.elements.costTarget.value);
      const order = orders.find((item) => item.id === target.orderId);
      // Mit Auftrag: Kunde und Projekt kommen aus dem Auftrag – keine widersprüchliche Auswahl.
      form.querySelector("[data-context-fields]").hidden = Boolean(order);
      const context = form.querySelector("[data-order-context]");
      context.hidden = !order;
      context.textContent = order ? `Kunde: ${order.customer_name || "–"}${order.project_name ? ` · Projekt: ${order.project_name}` : ""} (aus dem Auftrag)` : "";
      const customerId = order ? "" : form.elements.customerId.value;
      const customerProjects = projects.filter((project) => customerId && project.customerId === customerId);
      form.elements.projectId.innerHTML = '<option value="">Ohne Projekt</option>' + customerProjects.map((project) => projectOption(project, form.elements.projectId.value)).join("");
      form.querySelector("[data-project-field]").hidden = customerProjects.length === 0;
    };
    form.elements.costTarget.addEventListener("change", sync);
    form.elements.customerId.addEventListener("change", sync);
    sync();
    Calight.searchableSelect(form.elements.customerId, { placeholder: "Kundennummer oder Name eingeben …" });
    Calight.createDialog({
      title: "Tagesbericht anlegen",
      form,
      submitLabel: "Anlegen",
      discardMessage: "Den begonnenen Tagesbericht verwerfen?",
      initialFocus: preselectedOrder ? '[name="reportDate"]' : ".suggest-select input",
      submit: async () => {
        const { costTarget, customerId, projectId, ...fields } = Object.fromEntries(new FormData(form));
        const target = Calight.parseCostTarget(costTarget);
        const report = await api("/api/daily-reports", {
          method: "POST",
          body: { ...fields, ...target, customerId: target.orderId ? "" : customerId, projectId: target.orderId ? "" : projectId, activities: "", notes: "", entries: [] },
        });
        location.assign(href(report));
      },
    });
  }

  globalThis.CalightDailyReports = Object.freeze({ create: createReport, href });

  if (!root) return;
  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const employeeFilter = root.querySelector("[data-employee-filter]");
  const customerFilter = root.querySelector("[data-customer-filter]");
  const projectFilter = root.querySelector("[data-project-filter]");

  const renderCard = (report) => `
    <article class="overview-card" tabindex="0" data-href="${href(report)}">
      <div class="management-card-head"><span class="service-number">${format.date(report.report_date)}</span>${statusBadge(report)}<span>${format.hours(report.total_hours)}</span></div>
      <div class="overview-card-title" title="${esc(titleOf(report))}">${esc(titleOf(report))}</div>
      <div class="overview-card-meta">
        <span>${[assignmentOf(report), report.customer_name, report.project_name].filter(Boolean).map(esc).join(" · ")}</span>
        <span>${esc(report.employee_names || "Keine Arbeitszeiten")}</span>
        ${report.total_labor_cost !== undefined ? `<span>${format.money(report.total_labor_cost)} Ist-Lohnkosten</span>` : ""}
        <span>Geändert ${format.dateTime(report.updated_at)}</span>
      </div>
    </article>`;

  const renderTable = (reports, header) => `
    <thead><tr>
      ${header("date", "Berichtsdatum", "cell-date")}${header("order", "Auftrag / Kostenstelle", "cell-number")}${header("customer", "Kunde", "cell-text")}
      ${header("title", "Bezeichnung", "cell-title")}<th class="cell-text">Mitarbeiter</th><th class="cell-status">Status</th>${header("hours", "Gesamtstunden", "cell-money")}${header("updated", "Geändert", "cell-datetime")}
    </tr></thead>
    <tbody>${reports.map((report) => `
      <tr tabindex="0" data-href="${href(report)}">
        <td class="cell-date">${format.date(report.report_date)}</td>
        <td class="cell-number" title="${esc(report.order_title || report.cost_center_name || "")}">${report.order_number ? esc(report.order_number) : report.cost_center_number ? esc(report.cost_center_number) : '<span class="muted">Ohne Zuordnung</span>'}</td>
        <td class="cell-text" title="${esc(report.customer_name || "")}">${report.customer_name ? esc(report.customer_name) : '<span class="muted">–</span>'}</td>
        <td class="cell-title" title="${esc(report.title || report.activities)}">${esc(report.title || "Ohne Bezeichnung")}</td>
        <td class="cell-text" title="${esc(report.employee_names || "")}">${esc(report.employee_names || "–")}</td>
        <td class="cell-status">${statusBadge(report)}</td>
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
    filters: { status: root.querySelector("[data-status-filter]"), customer: customerFilter, project: projectFilter, assignment: root.querySelector("[data-assignment-filter]"), order: orderFilter, date: root.querySelector("[data-date-filter]"), employee: employeeFilter },
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
    emptyText: "Noch keine Tagesberichte vorhanden.",
    noMatchText: "Keine Tagesberichte entsprechen den gewählten Filtern.",
    loadingText: "Tagesberichte werden geladen …",
    renderTable,
    renderCard,
  });

  async function init() {
    if (root.dataset.initialReport) {
      location.replace(`/daily-reports/${encodeURIComponent(root.dataset.initialReport)}`);
      return;
    }
    // Filteroptionen zuerst laden, damit Werte aus der URL ausgewählt werden können.
    await loadReference();
    customerFilter.innerHTML = '<option value="">Alle Kunden</option>' + customers.map((customer) => customerOption(customer)).join("");
    projectFilter.innerHTML = '<option value="">Alle Projekte</option>' + projects.map((project) => projectOption(project)).join("");
    const employees = reference.filterEmployees || reference.employees;
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orders.map((order) => orderOption(order)).join("");
    employeeFilter.innerHTML = '<option value="">Alle Mitarbeiter</option>' + employees
      .map((employee) => `<option value="${esc(employee.id)}">${esc(employee.display_name)}${Number(employee.active) ? "" : " (inaktiv)"}</option>`)
      .join("");
    list.start();
    if (root.dataset.initialOrder && !Calight.moduleReadOnly) {
      // Der Einstieg „Bericht anlegen“ öffnet den Dialog nur einmal, nicht erneut über die History.
      const url = new URL(location.href);
      url.searchParams.delete("create_for");
      history.replaceState(null, "", url.pathname + url.search);
      createReport({ orderId: root.dataset.initialOrder, customerId: customerFilter.value });
    }
  }

  root.querySelector("[data-new]")?.addEventListener("click", () => createReport({ orderId: orderFilter.value, customerId: customerFilter.value }).catch((error) => notify(message, error.message)));
  init().catch((error) => notify(message, error.message));
})();
