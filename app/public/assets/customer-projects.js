// Übersichtsseiten für Kunden und Projekte. Beide teilen sich denselben
// Datenendpunkt; `data-page` entscheidet, welche Liste angezeigt wird.
(() => {
  "use strict";

  const root = document.querySelector("#customer-project-app");
  if (!root) return;

  const { esc, api, format, collator, normalize, matches, notify } = Calight;
  const page = root.dataset.page;
  const canWrite = root.dataset.canWrite === "1";
  const message = root.querySelector("#admin-message");
  const list = root.querySelector(page === "customers" ? "#customer-list" : "#project-list");
  const projectStatuses = { draft: "Entwurf", active: "Aktiv", completed: "Abgeschlossen", archived: "Archiviert" };

  let customers = [];
  let projects = [];
  let visible = [];

  const customerHref = (item) => `/customers/${encodeURIComponent(item.id)}`;
  const projectHref = (item) => `/projects/${encodeURIComponent(item.id)}`;
  const customerType = (item) => (item.customerType === "company" ? "Unternehmen" : "Privatperson");
  const place = (...parts) => parts.filter(Boolean).join(" ");
  const projectBadge = (item) => `<span class="status-badge status-${esc(item.status)}">${esc(projectStatuses[item.status] || item.status)}</span>`;
  const empty = (text) => `<p class="overview-empty">${text}</p>`;

  function customerActions(item) {
    if (!canWrite) return "";
    const archiveLabel = item.active ? "Archivieren" : "Reaktivieren";
    return `<button class="icon-button" type="button" data-icon="copy" data-copy-customer="${esc(item.id)}" title="Kopieren" aria-label="Kopieren"></button>`
      + `<button class="icon-button${item.active ? " icon-button-danger" : ""}" type="button" data-icon="${item.active ? "package" : "refresh-cw"}" data-archive-customer="${esc(item.id)}" title="${archiveLabel}" aria-label="${archiveLabel}"></button>`;
  }

  function projectActions(item) {
    if (!canWrite) return "";
    return `<a class="icon-button" data-icon="square-pen" href="${projectHref(item)}/edit" title="Bearbeiten" aria-label="Bearbeiten"></a>`;
  }

  function renderCustomers(view) {
    if (!visible.length) {
      list.innerHTML = empty("Keine Kunden entsprechen der aktuellen Auswahl.");
      return;
    }
    if (view === "cards") {
      list.innerHTML = `<div class="overview-card-grid">${visible.map((item) => `
        <article class="overview-card${item.active ? "" : " archived-row"}" tabindex="0" data-href="${customerHref(item)}">
          <div class="management-card-head"><span class="service-number">${esc(item.customerNumber || "Ohne Nr.")}</span><span class="status-badge">${customerType(item)}</span></div>
          <div class="overview-card-title" title="${esc(item.displayName)}">${esc(item.displayName)}</div>
          <div class="overview-card-meta">
            <span>${esc(place(item.postalCode, item.city) || "Kein Ort")}</span>
            <span>${item.contacts.length} Ansprechpartner</span>
            <span>${item.projectCount} Projekte</span>
            <span>${item.offerCount} Angebote</span>
          </div>
          ${canWrite ? `<div class="overview-card-actions">${customerActions(item)}</div>` : ""}
        </article>`).join("")}</div>`;
      return;
    }
    list.innerHTML = `
      <div class="overview-table-wrap"><table class="overview-table">
        <thead><tr><th>Kundennummer</th><th>Name / Firma</th><th>Ort</th><th>Kundentyp</th><th class="cell-money">Ansprechpartner</th><th class="cell-money">Projekte</th><th class="cell-money">Angebote</th>${canWrite ? "<th>Aktionen</th>" : ""}</tr></thead>
        <tbody>${visible.map((item) => `
          <tr tabindex="0" data-href="${customerHref(item)}"${item.active ? "" : ' class="archived-row"'}>
            <td class="cell-number">${esc(item.customerNumber || "Ohne Nr.")}</td>
            <td class="cell-title" title="${esc(item.displayName)}">${esc(item.displayName)}</td>
            <td>${esc(place(item.postalCode, item.city) || "–")}</td>
            <td>${customerType(item)}</td>
            <td class="cell-money">${item.contacts.length}</td>
            <td class="cell-money">${item.projectCount}</td>
            <td class="cell-money">${item.offerCount}</td>
            ${canWrite ? `<td class="cell-actions">${customerActions(item)}</td>` : ""}
          </tr>`).join("")}</tbody>
      </table></div>`;
  }

  function renderProjects(view) {
    if (!visible.length) {
      list.innerHTML = empty("Keine Projekte entsprechen der aktuellen Auswahl.");
      return;
    }
    if (view === "cards") {
      list.innerHTML = `<div class="overview-card-grid">${visible.map((item) => `
        <article class="overview-card${item.status === "archived" ? " archived-row" : ""}" tabindex="0" data-href="${projectHref(item)}">
          <div class="management-card-head"><span class="service-number">${esc(item.projectNumber || "Ohne Nr.")}</span>${projectBadge(item)}</div>
          <div class="overview-card-title" title="${esc(item.name)}">${esc(item.name)}</div>
          <div class="overview-card-meta">
            <span>${esc(item.customerName || "Kein Kunde")}</span>
            <span>${esc(place(item.sitePostalCode, item.siteCity) || "Kein Baustellenort")}</span>
            <span>${item.offerCount} Angebote</span>
            <span>Geändert ${format.date(item.updatedAt)}</span>
          </div>
          ${canWrite ? `<div class="overview-card-actions">${projectActions(item)}</div>` : ""}
        </article>`).join("")}</div>`;
      return;
    }
    list.innerHTML = `
      <div class="overview-table-wrap"><table class="overview-table">
        <thead><tr><th>Projektnummer</th><th>Projektbezeichnung</th><th>Kunde</th><th>Status</th><th>Baustellenort</th><th class="cell-money">Angebote</th><th>Geändert</th>${canWrite ? "<th>Aktionen</th>" : ""}</tr></thead>
        <tbody>${visible.map((item) => `
          <tr tabindex="0" data-href="${projectHref(item)}"${item.status === "archived" ? ' class="archived-row"' : ""}>
            <td class="cell-number">${esc(item.projectNumber || "Ohne Nr.")}</td>
            <td class="cell-title" title="${esc(item.name)}">${esc(item.name)}</td>
            <td>${esc(item.customerName || "Kein Kunde")}</td>
            <td>${projectBadge(item)}</td>
            <td>${esc(place(item.sitePostalCode, item.siteCity) || "–")}</td>
            <td class="cell-money">${item.offerCount}</td>
            <td class="cell-date">${format.date(item.updatedAt)}</td>
            ${canWrite ? `<td class="cell-actions">${projectActions(item)}</td>` : ""}
          </tr>`).join("")}</tbody>
      </table></div>`;
  }

  const view = CalightListView.create({ root, module: page, render: page === "customers" ? renderCustomers : renderProjects });
  CalightListView.enableRowNavigation(list);

  // Filterleiste: Felder, Standardwerte und Filterfunktion je Seite.
  const controls = page === "customers"
    ? { search: "#customer-search", type: "#customer-type-filter", sort: "#customer-sort", archived: "#show-archived", count: "#customer-result-count", reset: "#customer-reset" }
    : { search: "#project-search", status: "#project-status-filter", sort: "#project-sort", count: "#project-result-count", reset: "#project-reset" };
  const field = (name) => (controls[name] ? root.querySelector(controls[name]) : null);
  const defaultSort = page === "customers" ? "name" : "updated";

  function filterCustomers(query) {
    const type = field("type").value;
    const archived = field("archived").checked;
    return customers
      .filter((item) => (archived || item.active) && (!type || item.customerType === type)
        && matches(query, item.customerNumber, item.displayName, item.city, item.email, item.phone,
          ...item.contacts.map((contact) => `${contact.firstName} ${contact.lastName}`)))
      .sort((a, b) => {
        if (field("sort").value === "number") return collator.compare(a.customerNumber || "", b.customerNumber || "");
        if (field("sort").value === "updated") return String(b.updatedAt).localeCompare(String(a.updatedAt));
        return collator.compare(a.displayName, b.displayName);
      });
  }

  function filterProjects(query) {
    const status = field("status").value;
    return projects
      .filter((item) => (!status || item.status === status)
        && matches(query, item.projectNumber, item.name, item.customerName, item.contactName, item.sitePostalCode, item.siteCity))
      .sort((a, b) => {
        if (field("sort").value === "number") return collator.compare(a.projectNumber || "", b.projectNumber || "");
        if (field("sort").value === "name") return collator.compare(a.name, b.name);
        return String(b.updatedAt).localeCompare(String(a.updatedAt));
      });
  }

  function render() {
    const query = normalize(field("search").value.trim());
    const all = page === "customers" ? customers : projects;
    visible = page === "customers" ? filterCustomers(query) : filterProjects(query);
    field("count").textContent = `${visible.length} von ${all.length}`;
    field("reset").disabled = !query && field("sort").value === defaultSort
      && !field("type")?.value && !field("status")?.value && !field("archived")?.checked;
    view.render();
  }

  function resetFilters() {
    field("search").value = "";
    field("sort").value = defaultSort;
    if (field("type")) field("type").value = "";
    if (field("status")) field("status").value = "";
    if (field("archived")) field("archived").checked = false;
    render();
    field("search").focus();
  }

  const addressFields = (prefix = "") => {
    const name = (key) => (prefix ? `${prefix}${key[0].toUpperCase()}${key.slice(1)}` : key);
    return `<label>Straße<input name="${name("street")}" maxlength="200"></label>
      <label>Hausnummer<input name="${name("houseNumber")}" maxlength="30"></label>
      <label>PLZ<input name="${name("postalCode")}" maxlength="20"></label>
      <label>Ort<input name="${name("city")}" maxlength="120"></label>`;
  };

  function newCustomer() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <div class="dialog-form-grid">
        <label>Kundentyp<select name="customerType"><option value="company">Unternehmen</option><option value="private">Privatperson</option></select></label>
        <label>Kundennummer<input name="customerNumber" maxlength="100" placeholder="Automatisch"></label>
        <label class="span-all" data-company>Firmenname <span aria-hidden="true">*</span><input name="companyName" maxlength="200" required></label>
        <label data-private hidden>Vorname <span aria-hidden="true">*</span><input name="firstName" maxlength="100"></label>
        <label data-private hidden>Nachname <span aria-hidden="true">*</span><input name="lastName" maxlength="100"></label>
        ${addressFields()}
        <label>E-Mail (optional)<input name="email" type="email" maxlength="254"></label>
        <label>Telefon (optional)<input name="phone" maxlength="80"></label>
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    form.elements.customerType.addEventListener("change", () => {
      const company = form.elements.customerType.value === "company";
      form.querySelector("[data-company]").hidden = !company;
      form.querySelectorAll("[data-private]").forEach((element) => { element.hidden = company; });
      form.elements.companyName.required = company;
      form.elements.firstName.required = !company;
      form.elements.lastName.required = !company;
    });

    Calight.createDialog({
      title: "Neuer Kunde",
      form,
      submitLabel: "Kunde anlegen",
      discardMessage: "Den begonnenen Kunden verwerfen?",
      initialFocus: '[name="companyName"]',
      submit: async () => {
        const data = Object.fromEntries(new FormData(form));
        const saved = await api("/api/customers", {
          method: "POST",
          body: { ...data, autoCustomerNumber: !data.customerNumber, country: "Deutschland", contacts: [], addresses: [] },
        });
        location.assign(customerHref(saved));
      },
    });
  }

  function newProject() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    const customerOptions = customers
      .filter((item) => item.active)
      .map((item) => `<option value="${esc(item.id)}">${esc(item.customerNumber || "Ohne Nr.")} · ${esc(item.displayName)}</option>`)
      .join("");
    form.innerHTML = `
      <div class="dialog-form-grid">
        <label class="span-all">Projektbezeichnung <span aria-hidden="true">*</span><input name="name" maxlength="200" required></label>
        <label>Kunde (optional)<select name="customerId"><option value="">Kein Kunde</option>${customerOptions}</select></label>
        <label>Ansprechpartner (optional)<select name="contactId" disabled><option value="">Kein Ansprechpartner</option></select></label>
        <label>Projektnummer<input name="projectNumber" maxlength="100" placeholder="Automatisch"></label>
        <label>Status<select name="status"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="completed">Abgeschlossen</option></select></label>
        ${addressFields("site")}
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    form.elements.customerId.addEventListener("change", () => {
      const customer = customers.find((item) => item.id === form.elements.customerId.value);
      const select = form.elements.contactId;
      select.innerHTML = '<option value="">Kein Ansprechpartner</option>' + (customer?.contacts || [])
        .map((contact) => `<option value="${esc(contact.id)}">${esc(`${contact.firstName} ${contact.lastName}`.trim())}</option>`)
        .join("");
      select.disabled = !customer;
    });

    Calight.createDialog({
      title: "Neues Projekt",
      form,
      submitLabel: "Projekt anlegen",
      discardMessage: "Das begonnene Projekt verwerfen?",
      initialFocus: '[name="name"]',
      submit: async () => {
        const data = Object.fromEntries(new FormData(form));
        const saved = await api("/api/projects", {
          method: "POST",
          body: { ...data, autoProjectNumber: !data.projectNumber, siteCountry: "Deutschland" },
        });
        location.assign(projectHref(saved));
      },
    });
  }

  async function copyCustomer(button) {
    button.disabled = true;
    try {
      const saved = await api(`/api/customers/${encodeURIComponent(button.dataset.copyCustomer)}/copy`, { method: "POST", body: {} });
      location.assign(customerHref(saved));
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  async function toggleArchive(button) {
    const item = customers.find((row) => row.id === button.dataset.archiveCustomer);
    if (!item) return;
    if (item.active) {
      const confirmed = await CalightModal.confirm({ title: "Kunde archivieren", content: `${item.displayName} wirklich archivieren?`, confirmLabel: "Archivieren", danger: true });
      if (!confirmed) return;
    }
    button.disabled = true;
    try {
      await api(`/api/customers/${encodeURIComponent(item.id)}/archive`, { method: "POST", body: { archived: item.active } });
      await load();
      notify(message, item.active ? "Kunde wurde archiviert." : "Kunde wurde reaktiviert.", "success");
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  async function load() {
    list.innerHTML = empty("Daten werden geladen …");
    const data = await api("/api/customer-projects");
    customers = data.customers;
    projects = data.projects;
    render();
  }

  for (const name of ["search", "type", "status", "sort", "archived"]) {
    field(name)?.addEventListener(name === "search" ? "input" : "change", render);
  }
  field("reset").addEventListener("click", resetFilters);
  root.querySelector(page === "customers" ? "#new-customer" : "#new-project")?.addEventListener("click", page === "customers" ? newCustomer : newProject);
  list.addEventListener("click", (event) => {
    const copy = event.target.closest("[data-copy-customer]");
    const archive = event.target.closest("[data-archive-customer]");
    if (copy) copyCustomer(copy);
    else if (archive) toggleArchive(archive);
  });
  load().catch((exception) => notify(message, exception.message));
})();
