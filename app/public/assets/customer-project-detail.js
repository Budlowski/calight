// Bearbeitungsseiten für Kunden (/customers/{id}) und Projekte (/projects/{id}/edit).
(() => {
  "use strict";

  const root = document.querySelector("#customer-project-detail");
  if (!root) return;

  const { esc, api, notify } = Calight;
  const kind = root.dataset.kind;
  const id = root.dataset.id;
  const form = root.querySelector("form");
  const error = root.querySelector("[data-error]");
  const message = root.querySelector("[data-message]");
  const isCustomer = kind === "customer";
  const endpoint = isCustomer ? `/api/customers/${encodeURIComponent(id)}` : `/api/projects/${encodeURIComponent(id)}`;

  let customers = [];
  let item = null;
  let contacts = [];
  let addresses = [];
  let dirty = false;
  let saving = false;

  const CUSTOMER_FIELDS = ["customerNumber", "customerType", "companyName", "firstName", "lastName", "street", "houseNumber", "postalCode", "city", "country", "email", "phone", "website", "vatId", "notes"];
  const PROJECT_FIELDS = ["projectNumber", "name", "status", "description", "customerId", "customerName", "siteStreet", "siteHouseNumber", "sitePostalCode", "siteCity", "siteCountry"];

  const input = (row, field, label, type = "text") => `<label>${label}<input data-field="${field}" type="${type}" value="${esc(row[field])}"></label>`;
  const recordHeading = (label, action) => `<div class="inline-record-heading"><strong>${label}</strong><button class="button button-light button-small button-danger-light" type="button" ${action} data-icon="trash">Entfernen</button></div>`;

  function renderContacts() {
    root.querySelector("[data-contact-count]").textContent = `(${contacts.length})`;
    root.querySelector("[data-contacts]").innerHTML = contacts.map((row, index) => `
      <div class="inline-record" data-row="${index}">
        ${recordHeading("Ansprechpartner", "data-remove-contact")}
        <div class="contact-grid">
          ${input(row, "salutation", "Anrede")}${input(row, "firstName", "Vorname")}${input(row, "lastName", "Nachname")}
          ${input(row, "function", "Funktion")}${input(row, "email", "E-Mail", "email")}${input(row, "phone", "Telefon")}${input(row, "mobile", "Mobil")}
        </div>
      </div>`).join("") || '<p class="muted">Noch keine Ansprechpartner.</p>';
  }

  function renderAddresses() {
    const types = { invoice: "Rechnung", site: "Baustelle", other: "Sonstige" };
    root.querySelector("[data-address-count]").textContent = `(${addresses.length})`;
    root.querySelector("[data-addresses]").innerHTML = addresses.map((row, index) => `
      <div class="inline-record" data-row="${index}">
        ${recordHeading("Weitere Adresse", "data-remove-address")}
        <div class="address-grid">
          <label>Typ<select data-field="addressType">${Object.entries(types).map(([value, label]) => `<option value="${value}"${row.addressType === value ? " selected" : ""}>${label}</option>`).join("")}</select></label>
          ${input(row, "label", "Bezeichnung")}${input(row, "street", "Straße")}${input(row, "houseNumber", "Hausnummer")}
          ${input(row, "postalCode", "PLZ")}${input(row, "city", "Ort")}${input({ ...row, country: row.country || "Deutschland" }, "country", "Land")}
        </div>
      </div>`).join("") || '<p class="muted">Noch keine weiteren Adressen.</p>';
  }

  function toggleCustomerType() {
    const company = form.elements.customerType.value === "company";
    root.querySelector("[data-company]").hidden = !company;
    root.querySelectorAll("[data-private]").forEach((element) => { element.hidden = company; });
    form.elements.companyName.required = company;
    form.elements.firstName.required = !company;
    form.elements.lastName.required = !company;
  }

  function renderContactOptions(selected = "") {
    const customer = customers.find((row) => row.id === form.elements.customerId.value);
    form.elements.contactId.innerHTML = '<option value="">Kein Ansprechpartner</option>' + (customer?.contacts || [])
      .map((contact) => `<option value="${esc(contact.id)}"${contact.id === selected ? " selected" : ""}>${esc(`${contact.firstName} ${contact.lastName}`.trim())}${contact.function ? ` · ${esc(contact.function)}` : ""}</option>`)
      .join("");
    form.elements.contactId.disabled = !customer;
    // Der freie Kundenname ist nur für Altprojekte ohne verknüpften Kunden relevant.
    root.querySelector("[data-legacy]").hidden = Boolean(customer);
  }

  function fillCustomer() {
    for (const name of CUSTOMER_FIELDS) form.elements[name].value = item[name] ?? "";
    contacts = structuredClone(item.contacts || []);
    addresses = structuredClone(item.addresses || []);
    toggleCustomerType();
    renderContacts();
    renderAddresses();
  }

  function fillProject() {
    form.elements.customerId.innerHTML = '<option value="">Kein Kunde zugeordnet</option>' + customers
      .filter((row) => row.active || row.id === item.customerId)
      .map((row) => `<option value="${esc(row.id)}">${esc(row.customerNumber || "Ohne Nr.")} · ${esc(row.displayName)}</option>`)
      .join("");
    for (const name of PROJECT_FIELDS) form.elements[name].value = item[name] ?? "";
    renderContactOptions(item.contactId || "");
  }

  function payload() {
    const data = Object.fromEntries(new FormData(form));
    if (isCustomer) return { ...data, autoCustomerNumber: false, contacts, addresses };
    return { ...data, legacySiteAddress: item.legacySiteAddress || "" };
  }

  async function load() {
    const data = await api("/api/customer-projects");
    customers = data.customers;
    item = (isCustomer ? data.customers : data.projects).find((row) => row.id === id);
    if (!item) throw new Error(isCustomer ? "Kunde wurde nicht gefunden." : "Projekt wurde nicht gefunden.");
    if (isCustomer) fillCustomer();
    else fillProject();
    dirty = false;
  }

  form.addEventListener("input", () => { dirty = true; });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (saving || !form.reportValidity()) return;
    // Speichern sitzt im Seitenkopf (form-Attribut) – wie auf den übrigen Detailseiten.
    const submit = form.querySelector('[type="submit"]') || document.querySelector(`[type="submit"][form="${form.id}"]`) || { disabled: false };
    saving = true;
    submit.disabled = true;
    error.hidden = true;
    try {
      await api(endpoint, { method: "PUT", body: payload() });
      dirty = false;
      await load();
      notify(message, isCustomer ? "Kunde wurde gespeichert." : "Projekt wurde gespeichert.", "success");
    } catch (exception) {
      error.textContent = exception.message;
      error.hidden = false;
    } finally {
      saving = false;
      submit.disabled = false;
    }
  });

  // Inline-Listen (Ansprechpartner, Adressen) für die Kundenseite.
  function bindRecordList(selector, rows, render, removeAttribute, blank) {
    const box = root.querySelector(selector);
    box.addEventListener("input", (event) => {
      const row = event.target.closest("[data-row]");
      if (!row || !event.target.dataset.field) return;
      rows()[Number(row.dataset.row)][event.target.dataset.field] = event.target.value;
    });
    box.addEventListener("click", (event) => {
      const row = event.target.closest("[data-row]");
      if (!row || !event.target.closest(`[${removeAttribute}]`)) return;
      rows().splice(Number(row.dataset.row), 1);
      render();
      dirty = true;
    });
    return () => {
      rows().push(blank());
      render();
      dirty = true;
      box.querySelector(".inline-record:last-child input")?.focus();
    };
  }

  async function runAction(button, operation) {
    button.disabled = true;
    try {
      await operation();
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  if (isCustomer) {
    form.elements.customerType.addEventListener("change", toggleCustomerType);
    const addContact = bindRecordList("[data-contacts]", () => contacts, renderContacts, "data-remove-contact",
      () => ({ id: null, salutation: "", firstName: "", lastName: "", function: "", email: "", phone: "", mobile: "" }));
    const addAddress = bindRecordList("[data-addresses]", () => addresses, renderAddresses, "data-remove-address",
      () => ({ id: null, addressType: "other", label: "", street: "", houseNumber: "", postalCode: "", city: "", country: "Deutschland" }));
    root.querySelector("[data-add-contact]").addEventListener("click", addContact);
    root.querySelector("[data-add-address]").addEventListener("click", addAddress);

    const copy = root.querySelector("[data-copy]");
    copy?.addEventListener("click", () => runAction(copy, async () => {
      const saved = await api(`${endpoint}/copy`, { method: "POST", body: {} });
      location.assign(`/customers/${encodeURIComponent(saved.id)}`);
    }));
    const archive = root.querySelector("[data-archive]");
    archive?.addEventListener("click", async () => {
      if (item.active) {
        const confirmed = await CalightModal.confirm({ title: "Kunde archivieren", content: `${item.displayName} wirklich archivieren?`, confirmLabel: "Archivieren", danger: true });
        if (!confirmed) return;
      }
      runAction(archive, async () => {
        await api(`${endpoint}/archive`, { method: "POST", body: { archived: item.active } });
        dirty = false;
        location.assign("/customers");
      });
    });
  } else {
    form.elements.customerId.addEventListener("change", () => {
      renderContactOptions();
      dirty = true;
    });
  }

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });
  load().catch((exception) => notify(message, exception.message));
})();
