(() => {
  "use strict";
  const root = document.querySelector("#customer-project-app");
  if (!root) return;
  const page = root.dataset.page,
    csrf = root.dataset.csrf,
    message = document.querySelector("#admin-message");
  const state = { customers: [], projects: [], contacts: [], addresses: [] };
  let dirty = false;
  const esc = (v) =>
    String(v ?? "").replace(
      /[&<>'"]/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;",
        })[c],
    );
  const statusLabels = {
    draft: "Entwurf",
    active: "Aktiv",
    completed: "Abgeschlossen",
    archived: "Archiviert",
  };
  const collator = new Intl.Collator("de-DE", {
    numeric: true,
    sensitivity: "base",
  });
  const shortDate = (value) =>
    value
      ? new Intl.DateTimeFormat("de-DE", { dateStyle: "short" }).format(
          new Date(value),
        )
      : "–";
  const compareText = (left, right) =>
    collator.compare(left || "", right || "");
  function notify(text, type = "success") {
    message.textContent = text;
    message.className = `notice notice-${type}`;
    message.hidden = false;
    clearTimeout(notify.timer);
    notify.timer = setTimeout(() => (message.hidden = true), 5000);
  }
  async function api(path, options = {}) {
    const headers = { Accept: "application/json", ...(options.headers || {}) };
    if (options.body !== undefined) {
      headers["Content-Type"] = "application/json";
      headers["X-CSRF-Token"] = csrf;
      options.body = JSON.stringify(options.body);
    }
    const response = await fetch(path, {
      credentials: "same-origin",
      ...options,
      headers,
    });
    let result;
    try {
      result = await response.json();
    } catch {
      throw new Error("Der Server hat keine gültige Antwort geliefert.");
    }
    if (!response.ok)
      throw new Error(
        (result.details || []).join(" ") ||
          result.error ||
          "Anfrage fehlgeschlagen.",
      );
    return result.data ?? result;
  }
  async function load() {
    const data = await api("/api/customer-projects");
    state.customers = data.customers;
    state.projects = data.projects;
    page === "customers" ? renderCustomers() : renderProjects();
  }
  async function mutate(path, body, method = "POST") {
    try {
      const saved = await api(path, { method, body });
      await load();
      notify("Änderungen wurden gespeichert.");
      return saved;
    } catch (e) {
      notify(e.message, "error");
      return null;
    }
  }
  function confirmDiscard() {
    return !dirty || window.confirm("Ungespeicherte Änderungen verwerfen?");
  }
  window.addEventListener("beforeunload", (e) => {
    if (!dirty) return;
    e.preventDefault();
    e.returnValue = "";
  });

  function renderCustomers() {
    const q = document
        .querySelector("#customer-search")
        .value.trim()
        .toLocaleLowerCase("de"),
      type = document.querySelector("#customer-type-filter").value,
      archived = document.querySelector("#show-archived").checked,
      sort = document.querySelector("#customer-sort").value;
    const items = state.customers
      .filter((c) => {
        const contacts = c.contacts
          .map(
            (contact) =>
              `${contact.firstName} ${contact.lastName} ${contact.function} ${contact.email} ${contact.phone}`,
          )
          .join(" ");
        const addresses = c.addresses
          .map(
            (address) =>
              `${address.label} ${address.street} ${address.postalCode} ${address.city}`,
          )
          .join(" ");
        const haystack =
          `${c.customerNumber} ${c.displayName} ${c.city} ${c.email} ${c.phone} ${contacts} ${addresses}`.toLocaleLowerCase(
            "de-DE",
          );
        return (
          (archived || c.active) &&
          (!type || c.customerType === type) &&
          (!q || haystack.includes(q))
        );
      })
      .sort((a, b) => {
        if (sort === "number")
          return compareText(a.customerNumber, b.customerNumber);
        if (sort === "updated")
          return String(b.updatedAt).localeCompare(String(a.updatedAt));
        return compareText(a.displayName, b.displayName);
      });
    document.querySelector("#customer-result-count").textContent =
      `${items.length} von ${state.customers.length}`;
    document.querySelector("#customer-reset").disabled =
      !q && !type && !archived && sort === "name";
    document.querySelector("#customer-list").innerHTML = items.length
      ? items
          .map(
            (c) =>
              `<article class="service-list-item management-card${c.active ? "" : " archived-row"}"><div class="service-list-main"><div class="management-card-head"><span class="service-number">${esc(c.customerNumber || "Ohne Nr.")}</span><span class="status-badge">${c.customerType === "company" ? "Unternehmen" : "Privatperson"}</span></div><strong>${esc(c.displayName)}</strong><span class="muted">${esc([c.street, c.houseNumber, c.postalCode, c.city].filter(Boolean).join(" ") || "Keine Hauptanschrift")}</span><span class="management-card-facts"><span>${c.contacts.length} Ansprechpartner</span><span>${c.addresses.length} weitere Adressen</span><span>${c.projectCount} Projekte</span><span>${c.offerCount} Angebote</span></span><span class="muted">${esc(c.email || c.phone || "Keine Kontaktdaten")} · geändert ${shortDate(c.updatedAt)}</span></div><div class="service-list-actions"><button class="button button-light button-small" type="button" data-icon="file-text" data-customer-edit="${c.id}">Bearbeiten</button><button class="button button-light button-small" type="button" data-icon="copy" data-customer-copy="${c.id}">Kopieren</button><button class="button button-light button-small" type="button" data-icon="${c.active ? "package" : "refresh-cw"}" data-customer-archive="${c.id}">${c.active ? "Archivieren" : "Reaktivieren"}</button></div></article>`,
          )
          .join("")
      : '<p class="card muted">Keine Kunden gefunden.</p>';
  }
  function openCustomer(item = null) {
    if (!confirmDiscard()) return;
    const f = document.querySelector("#customer-form");
    f.reset();
    f.elements.id.value = item?.id || "";
    f.elements.customerNumber.value = item?.customerNumber || "";
    const auto = !item?.customerNumber;
    f.elements.autoCustomerNumber.checked = auto;
    f.elements.autoCustomerNumber.closest("label").hidden = !auto;
    f.elements.customerNumber.disabled = auto || !!item?.customerNumber;
    for (const n of [
      "customerType",
      "companyName",
      "firstName",
      "lastName",
      "street",
      "houseNumber",
      "postalCode",
      "city",
      "country",
      "email",
      "phone",
      "website",
      "vatId",
      "notes",
    ])
      if (item) f.elements[n].value = item[n] ?? "";
      else if (n === "country") f.elements[n].value = "Deutschland";
    state.contacts = structuredClone(item?.contacts || []);
    state.addresses = structuredClone(item?.addresses || []);
    toggleCustomerType();
    renderContacts();
    renderAddresses();
    f.hidden = false;
    dirty = false;
    f.elements[
      item?.customerType === "private" ? "firstName" : "companyName"
    ].focus();
  }
  function toggleCustomerType() {
    const type =
      document.querySelector("#customer-form").elements.customerType.value;
    root
      .querySelectorAll("[data-company-field]")
      .forEach((e) => (e.hidden = type !== "company"));
    root
      .querySelectorAll("[data-private-field]")
      .forEach((e) => (e.hidden = type !== "private"));
  }
  function renderContacts() {
    document.querySelector("#contact-count").textContent =
      `(${state.contacts.length})`;
    document.querySelector("#contact-list").innerHTML =
      state.contacts
        .map(
          (c, i) =>
            `<div class="inline-record" data-contact="${i}"><div class="inline-record-heading"><strong>Ansprechpartner</strong><button class="button button-light button-small button-danger-light" type="button" data-icon="trash" data-remove-contact>Entfernen</button></div><div class="contact-grid"><label>Anrede<input data-field="salutation" value="${esc(c.salutation)}"></label><label>Vorname<input data-field="firstName" value="${esc(c.firstName)}" required></label><label>Nachname<input data-field="lastName" value="${esc(c.lastName)}" required></label><label>Funktion<input data-field="function" value="${esc(c.function)}"></label><label>E-Mail<input data-field="email" type="email" value="${esc(c.email)}"></label><label>Telefon<input data-field="phone" value="${esc(c.phone)}"></label><label>Mobil<input data-field="mobile" value="${esc(c.mobile)}"></label></div></div>`,
        )
        .join("") ||
      '<p class="muted inline-empty">Noch keine Ansprechpartner.</p>';
  }
  function renderAddresses() {
    document.querySelector("#address-count").textContent =
      `(${state.addresses.length})`;
    document.querySelector("#address-list").innerHTML =
      state.addresses
        .map(
          (a, i) =>
            `<div class="inline-record" data-address="${i}"><div class="inline-record-heading"><strong>Weitere Adresse</strong><button class="button button-light button-small button-danger-light" type="button" data-icon="trash" data-remove-address>Entfernen</button></div><div class="address-grid"><label>Typ<select data-field="addressType"><option value="invoice" ${a.addressType === "invoice" ? "selected" : ""}>Rechnung</option><option value="site" ${a.addressType === "site" ? "selected" : ""}>Baustelle</option><option value="other" ${a.addressType === "other" ? "selected" : ""}>Sonstige</option></select></label><label>Bezeichnung<input data-field="label" value="${esc(a.label)}"></label><label>Straße<input data-field="street" value="${esc(a.street)}"></label><label>Hausnummer<input data-field="houseNumber" value="${esc(a.houseNumber)}"></label><label>PLZ<input data-field="postalCode" value="${esc(a.postalCode)}"></label><label>Ort<input data-field="city" value="${esc(a.city)}"></label><label>Land<input data-field="country" value="${esc(a.country || "Deutschland")}"></label></div></div>`,
        )
        .join("") ||
      '<p class="muted inline-empty">Noch keine weiteren Adressen.</p>';
  }
  function customerPayload(f) {
    const get = (n) => f.elements[n].value;
    return {
      customerNumber: get("customerNumber") || null,
      autoCustomerNumber: f.elements.autoCustomerNumber.checked,
      customerType: get("customerType"),
      companyName: get("companyName"),
      firstName: get("firstName"),
      lastName: get("lastName"),
      street: get("street"),
      houseNumber: get("houseNumber"),
      postalCode: get("postalCode"),
      city: get("city"),
      country: get("country"),
      email: get("email"),
      phone: get("phone"),
      website: get("website"),
      vatId: get("vatId"),
      notes: get("notes"),
      contacts: state.contacts,
      addresses: state.addresses,
    };
  }
  function initCustomers() {
    const f = document.querySelector("#customer-form");
    document
      .querySelector("#customer-search")
      .addEventListener("input", renderCustomers);
    document
      .querySelector("#customer-type-filter")
      .addEventListener("change", renderCustomers);
    document
      .querySelector("#customer-sort")
      .addEventListener("change", renderCustomers);
    document
      .querySelector("#show-archived")
      .addEventListener("change", renderCustomers);
    document.querySelector("#customer-reset").addEventListener("click", () => {
      document.querySelector("#customer-search").value = "";
      document.querySelector("#customer-type-filter").value = "";
      document.querySelector("#customer-sort").value = "name";
      document.querySelector("#show-archived").checked = false;
      renderCustomers();
    });
    document
      .querySelector("#new-customer")
      .addEventListener("click", () => openCustomer());
    document
      .querySelector("#customer-list")
      .addEventListener("click", async (e) => {
        const edit = e.target.closest("[data-customer-edit]"),
          copy = e.target.closest("[data-customer-copy]"),
          archive = e.target.closest("[data-customer-archive]");
        if (edit)
          openCustomer(
            state.customers.find((c) => c.id === edit.dataset.customerEdit),
          );
        if (copy && confirmDiscard())
          await mutate(`/api/customers/${copy.dataset.customerCopy}/copy`, {});
        if (archive && confirmDiscard()) {
          const c = state.customers.find(
            (x) => x.id === archive.dataset.customerArchive,
          );
          await mutate(`/api/customers/${c.id}/archive`, {
            archived: c.active,
          });
        }
      });
    f.elements.customerType.addEventListener("change", toggleCustomerType);
    f.elements.autoCustomerNumber.addEventListener("change", () => {
      f.elements.customerNumber.disabled =
        f.elements.autoCustomerNumber.checked;
      if (!f.elements.autoCustomerNumber.checked)
        f.elements.customerNumber.focus();
    });
    document.querySelector("#add-contact").addEventListener("click", () => {
      state.contacts.push({
        id: null,
        salutation: "",
        firstName: "",
        lastName: "",
        function: "",
        email: "",
        phone: "",
        mobile: "",
      });
      renderContacts();
      dirty = true;
    });
    document.querySelector("#add-address").addEventListener("click", () => {
      state.addresses.push({
        id: null,
        addressType: "other",
        label: "",
        street: "",
        houseNumber: "",
        postalCode: "",
        city: "",
        country: "Deutschland",
      });
      renderAddresses();
      dirty = true;
    });
    document.querySelector("#contact-list").addEventListener("input", (e) => {
      const row = e.target.closest("[data-contact]");
      if (row && e.target.dataset.field) {
        state.contacts[+row.dataset.contact][e.target.dataset.field] =
          e.target.value;
        dirty = true;
      }
    });
    document.querySelector("#contact-list").addEventListener("click", (e) => {
      const row = e.target.closest("[data-contact]");
      if (row && e.target.closest("[data-remove-contact]")) {
        state.contacts.splice(+row.dataset.contact, 1);
        renderContacts();
        dirty = true;
      }
    });
    document.querySelector("#address-list").addEventListener("input", (e) => {
      const row = e.target.closest("[data-address]");
      if (row && e.target.dataset.field) {
        state.addresses[+row.dataset.address][e.target.dataset.field] =
          e.target.value;
        dirty = true;
      }
    });
    document.querySelector("#address-list").addEventListener("click", (e) => {
      const row = e.target.closest("[data-address]");
      if (row && e.target.closest("[data-remove-address]")) {
        state.addresses.splice(+row.dataset.address, 1);
        renderAddresses();
        dirty = true;
      }
    });
    f.addEventListener("input", () => (dirty = true));
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      const id = f.elements.id.value,
        saved = await mutate(
          id ? `/api/customers/${id}` : "/api/customers",
          customerPayload(f),
          id ? "PUT" : "POST",
        );
      if (saved) {
        dirty = false;
        f.hidden = true;
      }
    });
    f.querySelector("[data-cancel]").addEventListener("click", () => {
      if (confirmDiscard()) {
        dirty = false;
        f.hidden = true;
      }
    });
  }

  function renderProjects() {
    const q = document
        .querySelector("#project-search")
        .value.trim()
        .toLocaleLowerCase("de"),
      status = document.querySelector("#project-status-filter").value,
      sort = document.querySelector("#project-sort").value;
    const items = state.projects
      .filter((p) => {
        const haystack =
          `${p.projectNumber} ${p.name} ${p.customerName} ${p.contactName} ${p.siteStreet} ${p.sitePostalCode} ${p.siteCity} ${p.description}`.toLocaleLowerCase(
            "de-DE",
          );
        return (!status || p.status === status) && (!q || haystack.includes(q));
      })
      .sort((a, b) => {
        if (sort === "number")
          return compareText(a.projectNumber, b.projectNumber);
        if (sort === "name") return compareText(a.name, b.name);
        return String(b.updatedAt).localeCompare(String(a.updatedAt));
      });
    document.querySelector("#project-result-count").textContent =
      `${items.length} von ${state.projects.length}`;
    document.querySelector("#project-reset").disabled =
      !q && !status && sort === "updated";
    document.querySelector("#project-list").innerHTML = items.length
      ? items
          .map(
            (p) =>
              `<article class="service-list-item management-card${p.status === "archived" ? " archived-row" : ""}"><div class="service-list-main"><div class="management-card-head"><span class="service-number">${esc(p.projectNumber || "Ohne Nr.")}</span><span class="status-badge status-${esc(p.status)}">${statusLabels[p.status]}</span></div><strong>${esc(p.name)}</strong><span class="muted">${esc(p.customerName || "Kein Kunde zugeordnet")}${p.contactName ? ` · ${esc(p.contactName)}` : ""}</span><span class="management-card-facts"><span>${p.offerCount} Angebote</span><span>${esc([p.sitePostalCode, p.siteCity].filter(Boolean).join(" ") || "Keine Baustellenadresse")}</span></span><span class="muted">Geändert ${shortDate(p.updatedAt)}</span></div><div class="service-list-actions"><button class="button button-light button-small" type="button" data-icon="file-text" data-project-edit="${p.id}">Bearbeiten</button><a class="button button-light button-small" data-icon="folder-kanban" href="/projects/${encodeURIComponent(p.id)}">Angebote</a></div></article>`,
          )
          .join("")
      : '<p class="card muted">Keine Projekte gefunden.</p>';
  }
  function customerOptions(selected = "") {
    const f = document.querySelector("#project-form");
    f.elements.customerId.innerHTML =
      '<option value="">Kein Kunde zugeordnet</option>' +
      state.customers
        .filter((c) => c.active || c.id === selected)
        .map(
          (c) =>
            `<option value="${c.id}" ${c.id === selected ? "selected" : ""}>${esc(c.customerNumber)} · ${esc(c.displayName)}</option>`,
        )
        .join("");
  }
  function contactOptions(selected = "") {
    const f = document.querySelector("#project-form"),
      customer = state.customers.find(
        (c) => c.id === f.elements.customerId.value,
      ),
      contacts = customer?.contacts || [];
    f.elements.contactId.innerHTML =
      '<option value="">Kein Ansprechpartner</option>' +
      contacts
        .map(
          (c) =>
            `<option value="${c.id}" ${c.id === selected ? "selected" : ""}>${esc(`${c.firstName} ${c.lastName}`.trim())}${c.function ? ` · ${esc(c.function)}` : ""}</option>`,
        )
        .join("");
    f.elements.contactId.disabled = !customer;
    f.querySelector("[data-legacy-customer]").hidden = !!customer;
  }
  function openProject(item = null) {
    if (!confirmDiscard()) return;
    const f = document.querySelector("#project-form");
    f.reset();
    f.elements.id.value = item?.id || "";
    f.elements.projectNumber.value = item?.projectNumber || "";
    const auto = !item?.projectNumber;
    f.elements.autoProjectNumber.checked = auto;
    f.elements.autoProjectNumber.closest("label").hidden = !auto;
    f.elements.projectNumber.disabled = auto || !!item?.projectNumber;
    for (const n of [
      "name",
      "description",
      "customerName",
      "siteStreet",
      "siteHouseNumber",
      "sitePostalCode",
      "siteCity",
      "siteCountry",
      "status",
    ])
      if (item) f.elements[n].value = item[n] ?? "";
      else if (n === "siteCountry") f.elements[n].value = "Deutschland";
    customerOptions(item?.customerId || "");
    contactOptions(item?.contactId || "");
    const legacy = document.querySelector("#legacy-site-address");
    legacy.hidden = !item?.legacySiteAddress;
    legacy.textContent = item?.legacySiteAddress
      ? `Bisherige Baustellenanschrift: ${item.legacySiteAddress}`
      : "";
    legacy.dataset.value = item?.legacySiteAddress || "";
    const link = document.querySelector("#project-offers-link");
    link.hidden = !item;
    link.href = item ? `/projects/${encodeURIComponent(item.id)}` : "#";
    f.hidden = false;
    dirty = false;
    f.elements.name.focus();
  }
  function projectPayload(f) {
    const get = (n) => f.elements[n].value;
    return {
      projectNumber: get("projectNumber") || null,
      autoProjectNumber: f.elements.autoProjectNumber.checked,
      name: get("name"),
      description: get("description"),
      customerId: get("customerId") || null,
      contactId: get("contactId") || null,
      customerName: get("customerName"),
      siteStreet: get("siteStreet"),
      siteHouseNumber: get("siteHouseNumber"),
      sitePostalCode: get("sitePostalCode"),
      siteCity: get("siteCity"),
      siteCountry: get("siteCountry"),
      legacySiteAddress:
        document.querySelector("#legacy-site-address").dataset.value || "",
      status: get("status"),
    };
  }
  function initProjects() {
    const f = document.querySelector("#project-form");
    document
      .querySelector("#project-search")
      .addEventListener("input", renderProjects);
    document
      .querySelector("#project-status-filter")
      .addEventListener("change", renderProjects);
    document
      .querySelector("#project-sort")
      .addEventListener("change", renderProjects);
    document.querySelector("#project-reset").addEventListener("click", () => {
      document.querySelector("#project-search").value = "";
      document.querySelector("#project-status-filter").value = "";
      document.querySelector("#project-sort").value = "updated";
      renderProjects();
    });
    document
      .querySelector("#new-project")
      .addEventListener("click", () => openProject());
    document.querySelector("#project-list").addEventListener("click", (e) => {
      const edit = e.target.closest("[data-project-edit]");
      if (edit)
        openProject(
          state.projects.find((p) => p.id === edit.dataset.projectEdit),
        );
    });
    f.elements.customerId.addEventListener("change", () => contactOptions());
    f.elements.autoProjectNumber.addEventListener("change", () => {
      f.elements.projectNumber.disabled = f.elements.autoProjectNumber.checked;
      if (!f.elements.autoProjectNumber.checked)
        f.elements.projectNumber.focus();
    });
    f.addEventListener("input", () => (dirty = true));
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      const id = f.elements.id.value,
        saved = await mutate(
          id ? `/api/projects/${id}` : "/api/projects",
          projectPayload(f),
          id ? "PUT" : "POST",
        );
      if (saved) {
        dirty = false;
        f.hidden = true;
      }
    });
    f.querySelector("[data-cancel]").addEventListener("click", () => {
      if (confirmDiscard()) {
        dirty = false;
        f.hidden = true;
      }
    });
  }
  (page === "customers" ? initCustomers : initProjects)();
  load().catch((e) => notify(e.message, "error"));
})();
