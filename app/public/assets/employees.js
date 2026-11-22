// Stammdaten → Mitarbeiter: Mitarbeiter mit Kostensatz und optionalem Benutzerkonto (kein Konto,
// vorhandenes verknüpfen oder – mit Benutzerverwaltungsrecht – neues Konto in derselben Transaktion).
// Eindeutigkeit, Rechte und Passwortregeln prüft der Server (EmployeeService, UserAccessService).
(() => {
  "use strict";

  const root = document.querySelector("#employees-app");
  if (!root) return;
  const { esc, api, notify } = Calight;
  const list = root.querySelector("[data-list]");
  const form = root.querySelector("[data-form]");
  const search = root.querySelector("[data-search]");
  const inactive = root.querySelector("[data-show-inactive]");
  const count = root.querySelector("[data-count]");
  const message = root.querySelector("[data-message]");
  const error = root.querySelector("[data-error]");
  const saveButton = form.querySelector("[data-save]");
  const canCreateUser = root.dataset.canCreateUser === "1";
  const ROLE_LABELS = { admin: "Administrator", office: "Büro", technician: "Monteur" };

  let employees = [];
  let users = [];
  let roles = ROLE_LABELS;
  let editing = null;
  let dirty = false;
  let saving = false;

  const rate = (value) => `${new Intl.NumberFormat("de-DE", { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value))} €/h`;
  const mayDiscard = () => !dirty || CalightModal.confirm({ title: "Ungespeicherte Änderungen", content: "Nicht gespeicherte Änderungen verwerfen?", confirmLabel: "Verwerfen", danger: true });

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
            <span>${rate(employee.hourly_cost_rate)}</span>
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

  function syncAccount() {
    const mode = form.elements.accountMode.value;
    form.querySelector("[data-account-existing]").hidden = mode !== "existing";
    form.querySelector("[data-account-new]").hidden = mode !== "new";
    const note = form.querySelector("[data-account-note]");
    note.textContent = mode === "none" && editing && employees.find((item) => item.id === editing)?.user_id
      ? "Die Verknüpfung wird aufgehoben; das Benutzerkonto selbst bleibt bestehen."
      : mode === "new" ? "Mitarbeiter und Benutzerkonto werden gemeinsam angelegt; schlägt ein Teil fehl, wird nichts gespeichert." : "";
  }

  function open(employee) {
    editing = employee?.id || null;
    form.hidden = false;
    form.reset();
    form.elements.displayName.value = employee?.display_name || "";
    form.elements.personnelNumber.value = employee?.personnel_number || "";
    form.elements.hourlyCostRate.value = String(employee?.hourly_cost_rate || "0.0000").replace(".", ",");
    form.elements.active.checked = employee ? Number(employee.active) === 1 : true;
    form.elements.userId.innerHTML = userOptions(employee);
    form.elements.newRole.innerHTML = Object.entries(roles).map(([value, label]) => `<option value="${esc(value)}"${value === "technician" ? " selected" : ""}>${esc(label)}</option>`).join("");
    form.elements.accountMode.value = employee?.user_id ? "existing" : "none";
    form.querySelector("[data-new-account-option]").hidden = !canCreateUser;
    root.querySelector("[data-form-title]").textContent = employee ? "Mitarbeiter bearbeiten" : "Mitarbeiter anlegen";
    error.hidden = true;
    dirty = false;
    syncAccount();
    form.elements.displayName.focus();
  }

  async function load() {
    list.innerHTML = '<p class="empty-state">Mitarbeiter werden geladen …</p>';
    const data = await api("/api/employees");
    employees = data.employees;
    users = data.users;
    roles = data.roles || ROLE_LABELS;
    render();
  }

  root.querySelector("[data-new]").addEventListener("click", async () => {
    if (await mayDiscard()) open();
  });
  root.querySelector("[data-cancel]").addEventListener("click", async () => {
    if (!(await mayDiscard())) return;
    form.hidden = true;
    editing = null;
    dirty = false;
  });
  list.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-edit]");
    if (button && (await mayDiscard())) open(employees.find((item) => item.id === button.dataset.edit));
  });
  search.addEventListener("input", render);
  inactive.addEventListener("change", render);
  form.addEventListener("input", () => (dirty = true));
  form.addEventListener("change", (event) => {
    dirty = true;
    if (event.target.name === "accountMode") syncAccount();
  });
  addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (saving) return;
    error.hidden = true;
    const mode = form.elements.accountMode.value;
    if (mode === "new" && form.elements.newPassword.value !== form.elements.newPasswordRepeat.value) {
      error.textContent = "Die Passwörter stimmen nicht überein.";
      error.hidden = false;
      return;
    }
    saving = true;
    saveButton.disabled = true;
    try {
      const payload = {
        displayName: form.elements.displayName.value.trim(),
        personnelNumber: form.elements.personnelNumber.value.trim(),
        hourlyCostRate: form.elements.hourlyCostRate.value.trim().replace(",", "."),
        active: form.elements.active.checked,
        accountMode: mode,
        userId: mode === "existing" ? form.elements.userId.value : "",
        newUser: mode === "new" ? { username: form.elements.newUsername.value.trim(), password: form.elements.newPassword.value, role: form.elements.newRole.value } : null,
      };
      const result = await api(editing ? `/api/employees/${encodeURIComponent(editing)}` : "/api/employees", { method: editing ? "PUT" : "POST", body: payload });
      dirty = false;
      form.hidden = true;
      editing = null;
      await load();
      const hints = result.employee?.hints || [];
      notify(message, ["Mitarbeiter wurde gespeichert.", ...hints].join(" "), hints.length ? "warning" : "success");
    } catch (exception) {
      error.textContent = exception.message;
      error.hidden = false;
    } finally {
      saving = false;
      saveButton.disabled = false;
    }
  });

  load().catch((exception) => notify(message, exception.message));
})();
