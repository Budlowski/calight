// Stammdaten → Mitarbeiter: Liste über die volle Breite, Anlegen und Bearbeiten im gemeinsamen
// Dialog (Calight.createDialog). Optionales Benutzerkonto: keines, vorhandenes verknüpfen oder –
// mit Benutzerverwaltungsrecht – neues Konto in derselben Transaktion. Eindeutigkeit, Rechte und
// Passwortregeln prüft der Server (EmployeeService, UserAccessService).
(() => {
  "use strict";

  const ROLE_LABELS = { admin: "Administrator", office: "Büro", technician: "Monteur" };

  /** Kostensatz zur Anzeige: immer zwei Nachkommastellen (gespeichert bleiben vier). */
  function rateText(value) {
    return `${new Intl.NumberFormat("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0)} €/h`;
  }

  /**
   * Kostensatz im Eingabefeld: mindestens zwei Nachkommastellen, weitere nur, wenn gespeichert –
   * so verändert ein unverändertes Speichern die gespeicherte Genauigkeit nicht.
   */
  function rateInput(value) {
    const [whole, fraction = ""] = String(value ?? "0").split(".");
    const trimmed = fraction.replace(/0+$/, "");
    return `${whole},${trimmed.length >= 2 ? trimmed : trimmed.padEnd(2, "0")}`;
  }

  globalThis.CalightEmployees = Object.freeze({ rateText, rateInput });

  const root = typeof document !== "undefined" ? document.querySelector("#employees-app") : null;
  if (!root) return;
  const { esc, api, notify } = Calight;
  const list = root.querySelector("[data-list]");
  const search = root.querySelector("[data-search]");
  const inactive = root.querySelector("[data-show-inactive]");
  const count = root.querySelector("[data-count]");
  const message = root.querySelector("[data-message]");
  const canCreateUser = root.dataset.canCreateUser === "1";
  const canReadCosts = root.dataset.canReadCosts === "1";

  let employees = [];
  let users = [];
  let roles = ROLE_LABELS;

  function render() {
    const query = search.value.trim().toLocaleLowerCase("de-DE");
    const rows = employees.filter((employee) => (inactive.checked || Number(employee.active) === 1)
      && (!query || `${employee.display_name} ${employee.personnel_number || ""} ${employee.username || ""}`.toLocaleLowerCase("de-DE").includes(query)));
    count.textContent = `${rows.length} von ${employees.length}`;
    list.innerHTML = rows.length ? rows.map((employee) => `
      <article class="service-list-item management-card ${Number(employee.active) ? "" : "is-inactive"}">
        <div class="service-list-main">
          <div class="management-card-head"><strong>${esc(employee.display_name)}</strong><span class="status-badge">${Number(employee.active) ? "Aktiv" : "Inaktiv"}</span></div>
          <div class="management-card-facts">
            <span>${esc(employee.personnel_number || "Ohne Personalnummer")}</span>
            <span>${employee.username ? `Login: ${esc(employee.username)} (${esc(ROLE_LABELS[employee.role] || employee.role)})${Number(employee.user_active) ? "" : " – Konto deaktiviert"}` : "Ohne Benutzerkonto"}</span>
            ${canReadCosts ? `<span class="employee-rate">${rateText(employee.hourly_cost_rate)}</span>` : ""}
          </div>
        </div>
        <div class="service-list-actions"><button class="button button-light button-small" type="button" data-edit="${esc(employee.id)}" data-icon="square-pen">Bearbeiten</button></div>
      </article>`).join("") : '<p class="empty-state">Keine Mitarbeiter gefunden.</p>';
  }

  /** Konten ohne Mitarbeiter oder bereits mit diesem Mitarbeiter verknüpft (1:1). */
  function userOptions(employee) {
    const linked = new Set(employees.filter((item) => item.user_id && item.id !== employee?.id).map((item) => item.user_id));
    const selectable = users.filter((user) => !linked.has(user.id));
    return '<option value="">Benutzerkonto wählen …</option>' + selectable.map((user) => `<option value="${esc(user.id)}"${user.id === employee?.user_id ? " selected" : ""}>${esc(user.display_name)} · ${esc(user.username)} (${esc(ROLE_LABELS[user.role] || user.role)})${Number(user.active) ? "" : " – deaktiviert"}</option>`).join("");
  }

  const field = (label, control, hint = "", className = "") => `<label${className ? ` class="${className}"` : ""}><span class="field-label">${label}</span>${control}${hint ? `<small class="field-hint">${hint}</small>` : ""}</label>`;
  const required = (label) => `${label} <span aria-hidden="true">*</span>`;

  function employeeForm(employee) {
    const form = document.createElement("form");
    form.className = "admin-form";
    form.noValidate = true;
    const mode = employee?.user_id ? "existing" : "none";
    form.innerHTML = `
      <div class="admin-form-grid">
        ${field(required("Anzeigename"), `<input name="displayName" maxlength="150" required value="${esc(employee?.display_name || "")}">`, "", "span-all")}
        ${field("Personalnummer", `<input name="personnelNumber" maxlength="50" value="${esc(employee?.personnel_number || "")}">`, "Optional, eindeutig.")}
        ${canReadCosts ? field(required("Interner Stundenkostensatz (€/h)"), `<input name="hourlyCostRate" inputmode="decimal" required value="${esc(rateInput(employee?.hourly_cost_rate ?? "0"))}">`, "Gilt für künftige Buchungen; bisherige Snapshots bleiben.") : ""}
      </div>
      <label class="check-inline"><input name="active" type="checkbox"${employee && Number(employee.active) !== 1 ? "" : " checked"}> Mitarbeiter aktiv</label>
      <fieldset class="admin-form-section">
        <legend>Benutzerkonto</legend>
        <div class="radio-cards">
          <label class="check-inline"><input type="radio" name="accountMode" value="none"${mode === "none" ? " checked" : ""}> Kein Benutzerkonto</label>
          <label class="check-inline"><input type="radio" name="accountMode" value="existing"${mode === "existing" ? " checked" : ""}> Vorhandenes Benutzerkonto verknüpfen</label>
          ${canCreateUser ? '<label class="check-inline"><input type="radio" name="accountMode" value="new"> Neues Benutzerkonto erstellen und verknüpfen</label>' : ""}
        </div>
        <div data-account-existing hidden>${field("Benutzerkonto", `<select name="userId">${userOptions(employee)}</select>`, "Nur Konten ohne Mitarbeiter sind wählbar.")}</div>
        <div class="admin-form-grid" data-account-new hidden>
          ${field(required("Benutzername"), '<input name="newUsername" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false">', "3–64 Zeichen: Buchstaben, Ziffern, . - _ @")}
          ${field("Rolle", `<select name="newRole">${Object.entries(roles).map(([value, label]) => `<option value="${esc(value)}"${value === "technician" ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`)}
          ${field(required("Passwort"), '<input name="newPassword" type="password" minlength="8" autocomplete="new-password">', "Mindestens 8 Zeichen.")}
          ${field(required("Passwort wiederholen"), '<input name="newPasswordRepeat" type="password" minlength="8" autocomplete="new-password">')}
        </div>
        <small class="field-hint" data-account-note></small>
      </fieldset>
      <p class="field-error" data-dialog-error hidden></p>`;
    const sync = () => {
      const selected = form.elements.accountMode.value;
      form.querySelector("[data-account-existing]").hidden = selected !== "existing";
      form.querySelector("[data-account-new]").hidden = selected !== "new";
      form.elements.userId.required = selected === "existing";
      ["newUsername", "newPassword", "newPasswordRepeat"].forEach((name) => { form.elements[name].required = selected === "new"; });
      form.querySelector("[data-account-note]").textContent = selected === "none" && employee?.user_id
        ? "Die Verknüpfung wird aufgehoben; das Benutzerkonto selbst bleibt bestehen."
        : selected === "new" ? "Mitarbeiter und Benutzerkonto werden gemeinsam gespeichert; schlägt ein Teil fehl, wird nichts übernommen." : "";
    };
    form.addEventListener("change", (event) => { if (event.target.name === "accountMode") sync(); });
    sync();
    return form;
  }

  function open(employee = null) {
    const form = employeeForm(employee);
    Calight.createDialog({
      title: employee ? `Mitarbeiter ${employee.display_name}` : "Mitarbeiter anlegen",
      form,
      submitLabel: employee ? "Speichern" : "Anlegen",
      discardMessage: "Die Änderungen am Mitarbeiter verwerfen?",
      initialFocus: '[name="displayName"]',
      wide: true,
      submit: async () => {
        const mode = form.elements.accountMode.value;
        if (mode === "new" && form.elements.newPassword.value !== form.elements.newPasswordRepeat.value) throw new Error("Die Passwörter stimmen nicht überein.");
        const result = await api(employee ? `/api/employees/${encodeURIComponent(employee.id)}` : "/api/employees", {
          method: employee ? "PUT" : "POST",
          body: {
            displayName: form.elements.displayName.value.trim(),
            personnelNumber: form.elements.personnelNumber.value.trim(),
            hourlyCostRate: (form.elements.hourlyCostRate?.value ?? employee?.hourly_cost_rate ?? "0").trim().replace(",", "."),
            active: form.elements.active.checked,
            accountMode: mode,
            userId: mode === "existing" ? form.elements.userId.value : "",
            newUser: mode === "new" ? { username: form.elements.newUsername.value.trim(), password: form.elements.newPassword.value, role: form.elements.newRole.value } : null,
          },
        });
        await load();
        const hints = result.employee?.hints || [];
        notify(message, ["Mitarbeiter wurde gespeichert.", ...hints].join(" "), hints.length ? "warning" : "success");
      },
    });
  }

  async function load() {
    const data = await api("/api/employees");
    employees = data.employees;
    users = data.users;
    roles = data.roles || ROLE_LABELS;
    render();
  }

  root.querySelector("[data-new]").addEventListener("click", () => open());
  list.addEventListener("click", (event) => {
    const button = event.target.closest("[data-edit]");
    if (button) open(employees.find((item) => item.id === button.dataset.edit));
  });
  search.addEventListener("input", render);
  inactive.addEventListener("change", render);
  list.innerHTML = '<p class="empty-state">Mitarbeiter werden geladen …</p>';
  load().catch((exception) => notify(message, exception.message));
})();
