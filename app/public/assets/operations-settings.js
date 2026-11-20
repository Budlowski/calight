(() => {
  "use strict";

  // Einstellungen → Betrieb: typisierte Betriebseinstellungen (derzeit Ja/Nein), sofort gespeichert.
  const root = document.querySelector("#operations-settings-app");
  if (!root) return;
  const { esc, api, format, notify } = Calight;
  const box = root.querySelector("[data-settings]");
  const message = root.querySelector("[data-message]");

  function render(settings) {
    box.innerHTML = settings.map((setting) => `
      <label class="check-inline operations-setting">
        <input type="checkbox" data-key="${esc(setting.key)}"${setting.value ? " checked" : ""}>
        <span><strong>${esc(setting.label)}</strong><small class="muted">${esc(setting.description)}</small>
        ${setting.updatedByName ? `<small class="muted">Zuletzt geändert von ${esc(setting.updatedByName)} am ${format.dateTime(setting.updatedAt)}</small>` : ""}</span>
      </label>`).join("");
  }

  box.addEventListener("change", async (event) => {
    const input = event.target.closest("[data-key]");
    if (!input) return;
    input.disabled = true;
    try {
      render(await api("/api/operational-settings", { method: "PUT", body: { [input.dataset.key]: input.checked } }));
      notify(message, "Einstellung gespeichert.", "success");
    } catch (error) {
      input.checked = !input.checked;
      input.disabled = false;
      notify(message, error.message);
    }
  });

  api("/api/operational-settings").then(render).catch((error) => notify(message, error.message));
})();
