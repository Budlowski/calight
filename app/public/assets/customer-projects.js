// Übersichtsseiten für Kunden und Projekte; `data-page` wählt das Modul.
(() => {
  "use strict";

  const root = document.querySelector("#customer-project-app");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const page = root.dataset.page;
  const isCustomers = page === "customers";
  const canWrite = root.dataset.canWrite === "1";
  const message = root.querySelector("#admin-message");
  const results = root.querySelector(isCustomers ? "#customer-list" : "#project-list");
  const projectStatuses = { draft: "Entwurf", active: "Aktiv", completed: "Abgeschlossen", archived: "Archiviert" };

  const customerHref = (item) => `/customers/${encodeURIComponent(item.id)}`;
  const projectHref = (item) => `/projects/${encodeURIComponent(item.id)}`;
  const customerType = (item) => (item.customerType === "company" ? "Unternehmen" : "Privatperson");
  const place = (...parts) => parts.filter(Boolean).join(" ");
  const projectBadge = (item) => `<span class="status-badge status-${esc(item.status)}">${esc(projectStatuses[item.status] || item.status)}</span>`;

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

  const customerCard = (item) => `
    <article class="overview-card${item.active ? "" : " archived-row"}" tabindex="0" data-href="${customerHref(item)}">
      <div class="management-card-head"><span class="service-number">${esc(item.customerNumber || "Ohne Nr.")}</span><span class="status-badge">${customerType(item)}</span></div>
      <div class="overview-card-title" title="${esc(item.displayName)}">${esc(item.displayName)}</div>
      <div class="overview-card-meta">
        <span>${esc(place(item.postalCode, item.city) || "Kein Ort")}</span>
        <span>${item.contactCount} Ansprechpartner</span>
        <span>${item.projectCount} Projekte</span>
        <span>${item.offerCount} Angebote</span>
      </div>
      ${canWrite ? `<div class="overview-card-actions">${customerActions(item)}</div>` : ""}
    </article>`;

  const customerTable = (items, header) => `
    <thead><tr>
      ${header("number", "Kundennummer", "cell-number")}${header("name", "Name / Firma")}${header("city", "Ort")}${header("type", "Kundentyp", "cell-type")}
      <th class="cell-count">Ansprechp.</th><th class="cell-count">Projekte</th><th class="cell-count">Angebote</th>
      ${header("updated", "Geändert", "cell-date")}${canWrite ? '<th class="cell-actions">Aktionen</th>' : ""}
    </tr></thead>
    <tbody>${items.map((item) => `
      <tr tabindex="0" data-href="${customerHref(item)}"${item.active ? "" : ' class="archived-row"'}>
        <td class="cell-number">${esc(item.customerNumber || "Ohne Nr.")}</td>
        <td class="cell-title" title="${esc(item.displayName)}">${esc(item.displayName)}</td>
        <td class="cell-text">${esc(place(item.postalCode, item.city) || "–")}</td>
        <td class="cell-type">${customerType(item)}</td>
        <td class="cell-count">${item.contactCount}</td>
        <td class="cell-count">${item.projectCount}</td>
        <td class="cell-count">${item.offerCount}</td>
        <td class="cell-date">${format.date(item.updatedAt)}</td>
        ${canWrite ? `<td class="cell-actions">${customerActions(item)}</td>` : ""}
      </tr>`).join("")}</tbody>`;

  const projectCard = (item) => `
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
    </article>`;

  const projectTable = (items, header) => `
    <thead><tr>
      ${header("number", "Projektnummer", "cell-number")}${header("name", "Projektbezeichnung")}${header("customer", "Kunde")}
      ${header("status", "Status", "cell-status")}${header("city", "Baustellenort")}<th class="cell-count">Angebote</th>
      ${header("updated", "Geändert", "cell-date")}${canWrite ? '<th class="cell-actions">Aktionen</th>' : ""}
    </tr></thead>
    <tbody>${items.map((item) => `
      <tr tabindex="0" data-href="${projectHref(item)}"${item.status === "archived" ? ' class="archived-row"' : ""}>
        <td class="cell-number">${esc(item.projectNumber || "Ohne Nr.")}</td>
        <td class="cell-title" title="${esc(item.name)}">${esc(item.name)}</td>
        <td class="cell-text" title="${esc(item.customerName || "")}">${esc(item.customerName || "Kein Kunde")}</td>
        <td class="cell-status">${projectBadge(item)}</td>
        <td class="cell-text">${esc(place(item.sitePostalCode, item.siteCity) || "–")}</td>
        <td class="cell-count">${item.offerCount}</td>
        <td class="cell-date">${format.date(item.updatedAt)}</td>
        ${canWrite ? `<td class="cell-actions">${projectActions(item)}</td>` : ""}
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage(isCustomers ? {
    root,
    module: page,
    endpoint: "/api/customers",
    results,
    search: root.querySelector("#customer-search"),
    filters: { type: root.querySelector("#customer-type-filter"), archived: root.querySelector("#show-archived") },
    sortSelect: root.querySelector("#customer-sort"),
    count: root.querySelector("#customer-result-count"),
    reset: root.querySelector("#customer-reset"),
    message,
    defaultSort: "updated",
    sorts: {
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
      number: { label: "Kundennummer", dir: "asc" },
      name: { label: "Name", dir: "asc" },
      city: { label: "Ort", dir: "asc" },
      type: { label: "Kundentyp", dir: "asc" },
    },
    emptyText: "Noch keine aktiven Kunden vorhanden.",
    noMatchText: "Keine Kunden entsprechen der aktuellen Auswahl.",
    loadingText: "Kunden werden geladen …",
    renderTable: customerTable,
    renderCard: customerCard,
  } : {
    root,
    module: page,
    endpoint: "/api/projects",
    results,
    search: root.querySelector("#project-search"),
    filters: { status: root.querySelector("#project-status-filter") },
    sortSelect: root.querySelector("#project-sort"),
    count: root.querySelector("#project-result-count"),
    reset: root.querySelector("#project-reset"),
    message,
    defaultSort: "updated",
    sorts: {
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
      number: { label: "Projektnummer", dir: "asc" },
      name: { label: "Projektbezeichnung", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      status: { label: "Status", dir: "asc" },
      city: { label: "Baustellenort", dir: "asc" },
    },
    emptyText: "Noch keine Projekte vorhanden.",
    noMatchText: "Keine Projekte entsprechen der aktuellen Auswahl.",
    loadingText: "Projekte werden geladen …",
    renderTable: projectTable,
    renderCard: projectCard,
  });

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

  // Die Kundenauswahl im Projektdialog braucht Ansprechpartner und wird erst beim Öffnen geladen.
  async function newProject() {
    let customers;
    try {
      customers = (await api("/api/customer-projects")).customers;
    } catch (exception) {
      notify(message, exception.message);
      return;
    }
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
    const item = list.items.find((row) => row.id === button.dataset.archiveCustomer);
    if (!item) return;
    if (item.active) {
      const confirmed = await CalightModal.confirm({ title: "Kunde archivieren", content: `${item.displayName} wirklich archivieren?`, confirmLabel: "Archivieren", danger: true });
      if (!confirmed) return;
    }
    button.disabled = true;
    try {
      await api(`/api/customers/${encodeURIComponent(item.id)}/archive`, { method: "POST", body: { archived: item.active } });
      await list.reload();
      notify(message, item.active ? "Kunde wurde archiviert." : "Kunde wurde reaktiviert.", "success");
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  root.querySelector(isCustomers ? "#new-customer" : "#new-project")?.addEventListener("click", isCustomers ? newCustomer : newProject);
  results.addEventListener("click", (event) => {
    const copy = event.target.closest("[data-copy-customer]");
    const archive = event.target.closest("[data-archive-customer]");
    if (copy) copyCustomer(copy);
    else if (archive) toggleArchive(archive);
  });
  list.start();
})();
