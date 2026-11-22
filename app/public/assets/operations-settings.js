(() => {
  "use strict";

  // Einstellungen → Arbeitserfassung: typisierte Betriebseinstellungen (Ja/Nein, Auswahl, Zahl) je Gruppe,
  // sofort gespeichert. Prüfung und Grenzen liegen auf dem Server.
  const root = document.querySelector("#operations-settings-app");
  if (!root) return;
  const { esc, api, format, notify } = Calight;
  const box = root.querySelector("[data-settings]");
  const message = root.querySelector("[data-message]");
  const descriptions = { time: "Gilt für Rapporte, Tagesberichte und die Zeiterfassung.", limits: "Prüfgrenzwerte für Hinweise bei Einreichung und Festschreibung.", travel: "Rechnerische Referenzwerte für Verpflegung und Privat-Pkw." };
  const number = (value) => String(value).replace(".", ",");

  const changed = (setting) => (setting.updatedByName ? `<small class="muted">Zuletzt geändert von ${esc(setting.updatedByName)} am ${format.dateTime(setting.updatedAt)}</small>` : "");
  const control = (setting) => {
    if (setting.type === "bool") {
      return `<label class="check-inline operations-setting"><input type="checkbox" data-key="${esc(setting.key)}" data-type="bool"${setting.value ? " checked" : ""}>
        <span><strong>${esc(setting.label)}</strong><small class="muted">${esc(setting.description)}</small>${changed(setting)}</span></label>`;
    }
    const field = setting.type === "choice"
      ? `<select data-key="${esc(setting.key)}" data-type="choice">${Object.entries(setting.options).map(([key, label]) => `<option value="${esc(key)}"${key === setting.value ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`
      : `<span class="operations-number"><input data-key="${esc(setting.key)}" data-type="number" inputmode="decimal" value="${esc(number(setting.value))}" data-previous="${esc(number(setting.value))}"><span>${esc(setting.unit || "")}</span></span>`;
    return `<label class="operations-setting operations-field"><span><strong>${esc(setting.label)}</strong><small class="muted">${esc(setting.description)}${setting.type === "number" && String(setting.value) !== String(setting.default) ? ` · Standard ${esc(number(setting.default))} ${esc(setting.unit || "")}` : ""}</small>${changed(setting)}</span>${field}</label>`;
  };

  function render(data) {
    box.innerHTML = Object.entries(data.groups).map(([group, title]) => {
      const settings = data.settings.filter((setting) => setting.group === group);
      return settings.length ? `<section class="card settings-section"><div class="settings-section-heading"><div><h2>${esc(title)}</h2><p class="muted">${esc(descriptions[group] || "")}</p></div></div>
        <div class="form-stack">${settings.map(control).join("")}</div></section>` : "";
    }).join("");
  }

  box.addEventListener("change", async (event) => {
    const input = event.target.closest("[data-key]");
    if (!input) return;
    const value = input.dataset.type === "bool" ? input.checked : input.dataset.type === "number" ? input.value.trim().replace(",", ".") : input.value;
    input.disabled = true;
    try {
      render(await api("/api/operational-settings", { method: "PUT", body: { [input.dataset.key]: value } }));
      notify(message, "Einstellung gespeichert.", "success");
    } catch (error) {
      if (input.dataset.type === "bool") input.checked = !input.checked;
      if (input.dataset.type === "number") input.value = input.dataset.previous;
      input.disabled = false;
      notify(message, error.message);
    }
  });

  api("/api/operational-settings").then(render).catch((error) => notify(message, error.message));
})();
