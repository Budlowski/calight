// Einstellungen → Benutzerkonten: Anlegen, Bearbeiten, Passwort zurücksetzen, Mitarbeiter zuordnen.
// Alle Regeln (eindeutiger Benutzername, letzter Administrator, keine Selbständerung der Rolle,
// Sitzungsende) prüft der Server (UserAccessService); die Oberfläche spiegelt sie nur wider.
(() => {
  "use strict";

  const root = document.querySelector("#users-app");
  if (!root) return;
  const { esc, api, format, notify } = Calight;
  const list = root.querySelector("[data-list]");
  const search = root.querySelector("[data-search]");
  const showInactive = root.querySelector("[data-show-inactive]");
  const count = root.querySelector("[data-count]");
  const message = root.querySelector("[data-message]");
  const INTERFACES = { "": "Standard der Rolle", office: "Büroansicht", mobile: "Mobile Oberfläche" };
  const EVENTS = {
    created: "Angelegt", updated: "Geändert", role_changed: "Rolle geändert", activated: "Aktiviert", deactivated: "Deaktiviert",
    password_reset: "Passwort zurückgesetzt", employee_linked: "Mitarbeiter verknüpft", employee_unlinked: "Mitarbeiter gelöst",
  };
  let data = { users: [], employees: [], roles: {}, currentUserId: "" };

  const field = (label, control, hint = "", className = "") => `<label${className ? ` class="${className}"` : ""}><span class="field-label">${label}</span>${control}${hint ? `<small class="field-hint">${hint}</small>` : ""}</label>`;
  const required = (label) => `${label} <span aria-hidden="true">*</span>`;
  const options = (map, selected) => Object.entries(map).map(([value, label]) => `<option value="${esc(value)}"${value === (selected ?? "") ? " selected" : ""}>${esc(label)}</option>`).join("");

  /** Mitarbeiter ohne Konto oder bereits mit diesem Konto verknüpft (keine Doppelzuordnung). */
  function employeeOptions(user) {
    const selectable = data.employees.filter((employee) => !employee.userId || employee.userId === user?.id);
    return `<option value="">Kein Mitarbeiter</option>${selectable.map((employee) => `<option value="${esc(employee.id)}"${employee.userId && employee.userId === user?.id ? " selected" : ""}>${esc(employee.name)}${employee.personnelNumber ? ` (${esc(employee.personnelNumber)})` : ""}${employee.active ? "" : " – inaktiv"}</option>`).join("")}`;
  }

  function render() {
    const query = search.value.trim().toLocaleLowerCase("de-DE");
    const rows = data.users.filter((user) => (showInactive.checked || user.active)
      && (!query || `${user.username} ${user.displayName} ${user.employee?.name || ""}`.toLocaleLowerCase("de-DE").includes(query)));
    count.textContent = `${rows.length} von ${data.users.length}`;
    list.innerHTML = rows.length ? `<div class="service-list management-list">${rows.map((user) => `
      <article class="service-list-item management-card ${user.active ? "" : "is-inactive"}">
        <div class="service-list-main">
          <div class="management-card-head"><strong>${esc(user.displayName)}</strong><span class="management-card-badges"><span class="status-badge">${esc(user.roleLabel)}</span>${user.active ? "" : '<span class="status-badge">Deaktiviert</span>'}${user.id === data.currentUserId ? '<span class="status-badge status-in_progress">Eigenes Konto</span>' : ""}</span></div>
          <div class="management-card-facts">
            <span>Benutzername: ${esc(user.username)}</span>
            <span>${user.employee ? `Mitarbeiter: ${esc(user.employee.name)}${user.employee.active ? "" : " (inaktiv)"}` : "Kein Mitarbeiter zugeordnet"}</span>
            <span>Oberfläche: ${esc(INTERFACES[user.preferredInterface || ""])}</span>
            <span>Geändert ${format.dateTime(user.updatedAt)}</span>
          </div>
        </div>
        <div class="service-list-actions"><button class="button button-light button-small" type="button" data-edit="${esc(user.id)}" data-icon="square-pen">Bearbeiten</button></div>
      </article>`).join("")}</div>` : '<p class="empty-state">Keine Benutzerkonten gefunden.</p>';
  }

  async function load() {
    data = await api("/api/users/overview");
    render();
  }

  /**
   * Formular „Benutzer anlegen/bearbeiten“: Stammdaten im zweispaltigen Raster, Mitarbeiter über
   * die volle Breite, darunter abgesetzt Status und Sicherheit, am Ende das einklappbare Protokoll.
   */
  function accountForm(user) {
    const self = user?.id === data.currentUserId;
    const form = document.createElement("form");
    form.className = "admin-form";
    form.noValidate = true;
    form.innerHTML = `
      <div class="admin-form-grid">
        ${field(required("Benutzername"), `<input name="username" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false" required value="${esc(user?.username || "")}">`, "3–64 Zeichen: Buchstaben, Ziffern, . - _ @")}
        ${field(required("Anzeigename"), `<input name="displayName" maxlength="150" required value="${esc(user?.displayName || "")}">`, "Erscheint in Berichten und Protokollen.")}
        ${field(required("Rolle"), `<select name="role"${self ? " disabled" : ""}>${options(data.roles, user?.role || "technician")}</select>`, self ? "Die eigene Rolle kann nicht geändert werden." : "Rechte siehe Rollen &amp; Rechte.")}
        ${field("Bevorzugte Oberfläche", `<select name="preferredInterface">${options(INTERFACES, user?.preferredInterface || "")}</select>`, "Monteure verwenden immer die mobile Oberfläche.")}
        ${field("Mitarbeiter", `<select name="employeeId">${employeeOptions(user)}</select>`, "Für die persönliche Zeiterfassung erforderlich. Wählbar sind nur Mitarbeiter ohne Benutzerkonto.", "span-all")}
        ${user ? "" : `
          ${field(required("Passwort"), '<input name="password" type="password" minlength="8" autocomplete="new-password" required>', "Mindestens 8 Zeichen.")}
          ${field(required("Passwort wiederholen"), '<input name="passwordRepeat" type="password" minlength="8" autocomplete="new-password" required>')}`}
      </div>
      <section class="admin-form-section" aria-label="Status und Sicherheit">
        <h3>Status und Sicherheit</h3>
        <label class="check-inline"><input name="active" type="checkbox"${user && !user.active ? "" : " checked"}${self ? " disabled" : ""}> Konto aktiv</label>
        <small class="field-hint">${self ? "Das eigene Konto kann nicht deaktiviert werden." : "Deaktivierte Konten können sich nicht anmelden; bestehende Sitzungen enden sofort. Konten werden nie gelöscht."}</small>
        ${user ? `<div class="admin-form-row"><p class="field-hint">Ein neues Passwort beendet alle bestehenden Sitzungen dieses Kontos.</p><button class="button button-light" type="button" data-reset-password>Passwort zurücksetzen …</button></div>` : ""}
        ${user?.employee && user.active && !user.employee.active ? '<p class="notice notice-warning">Der verknüpfte Mitarbeiter ist inaktiv; das Konto kann sich anmelden, aber keine eigenen Zeiten erfassen.</p>' : ""}
      </section>
      ${user ? '<details class="user-events"><summary>Änderungsprotokoll</summary><ul class="user-event-list" data-events><li class="muted">Wird geladen …</li></ul></details>' : ""}
      <p class="field-error" data-dialog-error hidden></p>`;
    form.querySelector("[data-reset-password]")?.addEventListener("click", () => openPassword(user));
    return form;
  }

  function payload(form, user) {
    const values = Object.fromEntries(new FormData(form));
    return {
      username: values.username.trim(), displayName: values.displayName.trim(),
      role: form.elements.role.disabled ? user.role : values.role,
      active: form.elements.active.disabled ? true : form.elements.active.checked,
      preferredInterface: values.preferredInterface, employeeId: values.employeeId,
    };
  }

  function openForm(user = null) {
    const form = accountForm(user);
    if (user) {
      api(`/api/users/${encodeURIComponent(user.id)}`).then((account) => {
        const box = form.querySelector("[data-events]");
        box.innerHTML = account.events.length ? account.events.map((event) => `<li><strong>${esc(EVENTS[event.action] || event.action)}</strong> · ${format.dateTime(event.at)}${event.actorName ? ` · ${esc(event.actorName)}` : ""}${event.details ? `<br><small class="muted">${esc(event.details)}</small>` : ""}</li>`).join("") : '<li class="muted">Keine Einträge.</li>';
      }).catch(() => { form.querySelector("[data-events]").innerHTML = '<li class="muted">Protokoll nicht verfügbar.</li>'; });
    }
    Calight.createDialog({
      title: user ? `Benutzerkonto ${user.username}` : "Benutzer anlegen",
      form,
      submitLabel: user ? "Speichern" : "Anlegen",
      discardMessage: "Die Änderungen am Benutzerkonto verwerfen?",
      initialFocus: '[name="username"]',
      wide: true,
      submit: async () => {
        const body = payload(form, user);
        if (!user) {
          if (form.elements.password.value !== form.elements.passwordRepeat.value) throw new Error("Die Passwörter stimmen nicht überein.");
          body.password = form.elements.password.value;
        }
        await api(user ? `/api/users/${encodeURIComponent(user.id)}` : "/api/users", { method: user ? "PUT" : "POST", body });
        await load();
        notify(message, user ? "Benutzerkonto gespeichert." : "Benutzerkonto angelegt.", "success");
      },
    });
  }

  /** Eigener Dialog über dem Kontodialog (CalightModal.openOver): dessen Eingaben bleiben erhalten. */
  function openPassword(user) {
    const form = document.createElement("form");
    form.className = "admin-form";
    form.noValidate = true;
    form.innerHTML = `
      <p class="global-modal-summary">Neues Passwort für <strong>${esc(user.displayName)}</strong> (${esc(user.username)}). Alle bestehenden Sitzungen dieses Kontos werden beendet${user.id === data.currentUserId ? " – Ihre aktuelle Sitzung bleibt angemeldet" : ""}.</p>
      <div class="admin-form-grid">
        ${field(required("Neues Passwort"), '<input name="password" type="password" minlength="8" autocomplete="new-password" required>', "Mindestens 8 Zeichen.")}
        ${field(required("Passwort wiederholen"), '<input name="passwordRepeat" type="password" minlength="8" autocomplete="new-password" required>')}
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    const error = form.querySelector("[data-dialog-error]");
    CalightModal.openOver({
      title: "Passwort zurücksetzen",
      content: form,
      initialFocus: '[name="password"]',
      actions: [
        { label: "Abbrechen", value: null },
        {
          label: "Passwort setzen", primary: true, validate: true,
          onClick: async () => {
            error.hidden = true;
            try {
              if (form.elements.password.value !== form.elements.passwordRepeat.value) throw new Error("Die Passwörter stimmen nicht überein.");
              await api(`/api/users/${encodeURIComponent(user.id)}/password`, { method: "POST", body: { password: form.elements.password.value } });
              notify(message, "Passwort zurückgesetzt; bestehende Sitzungen wurden beendet.", "success");
              return true;
            } catch (exception) {
              error.textContent = exception.message;
              error.hidden = false;
              return false;
            }
          },
        },
      ],
    });
  }

  root.querySelector("[data-new]").addEventListener("click", () => openForm());
  list.addEventListener("click", (event) => {
    const id = event.target.closest("[data-edit]")?.dataset.edit;
    if (id) openForm(data.users.find((user) => user.id === id));
  });
  search.addEventListener("input", render);
  showInactive.addEventListener("change", render);
  load().catch((error) => notify(message, error.message));
})();
