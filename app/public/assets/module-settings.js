(() => {
  "use strict";

  // Einstellungen → Module: kompakte Liste mit globalen Schaltern. Offene Vorgänge erst beim
  // Deaktivieren (Bestätigungsdialog). Prüfung und Speicherung auf dem Server.
  const root = document.querySelector("#module-settings-app");
  if (!root) return;
  const { esc, api, notify } = Calight;
  const box = root.querySelector("[data-modules]");
  const message = root.querySelector("[data-message]");

  const fragment = (html) => { const node = document.createElement("div"); node.innerHTML = html; return node; };
  const list = (items) => `<ul class="module-open-items">${items.map((item) => `<li>${esc(item)}</li>`).join("")}</ul>`;

  // Genau eine Zustandsangabe je Modul: Schalter (schaltbar) oder Text (fest/geplant).
  const control = (module) => {
    if (module.core) return '<span class="module-state">Immer aktiv</span>';
    if (!module.implemented) return '<span class="module-state">In Planung</span>';
    return `<label class="module-switch"><input type="checkbox" role="switch" data-module="${esc(module.key)}"${module.enabled ? " checked" : ""} aria-label="${esc(module.label)} aktiv"><span>${module.enabled ? "Aktiv" : "Inaktiv"}</span></label>`;
  };
  const row = (module) => `<li class="module-row${module.implemented ? "" : " is-planned"}">
      <span class="module-icon ui-icon icon-${esc(module.icon)}" aria-hidden="true"></span>
      <div class="module-text"><strong>${esc(module.label)}</strong><small>${esc(module.description)}</small></div>
      ${control(module)}</li>`;
  const render = (modules) => { box.innerHTML = `<ul class="module-rows card">${modules.map(row).join("")}</ul>`; };

  async function save(input, confirmOpen = false) {
    const key = input.dataset.module, enabled = input.checked;
    input.disabled = true;
    try {
      const result = await api(`/api/modules/${encodeURIComponent(key)}`, { method: "PUT", body: { enabled, confirmOpen } });
      if (result.saved) {
        location.reload(); // Navigation und Seiten richten sich nach den Modulen.
        return;
      }
      render(result.modules);
      const confirmed = await CalightModal.confirm({
        title: "Modul deaktivieren",
        content: fragment(`<p>Es gibt offene Vorgänge. Sie bleiben unverändert erhalten und lesbar, können aber erst nach der Wiederaktivierung weiterbearbeitet werden. Es wird nichts abgeschlossen, freigegeben oder storniert.</p>${list(result.openItems)}`),
        confirmLabel: "Trotzdem deaktivieren",
        danger: true,
      });
      if (confirmed) {
        const again = box.querySelector(`[data-module="${CSS.escape(key)}"]`);
        again.checked = false;
        await save(again, true);
      }
    } catch (error) {
      input.checked = !enabled;
      input.disabled = false;
      notify(message, error.message);
    }
  }

  box.addEventListener("change", (event) => {
    const input = event.target.closest("[data-module]");
    if (input) save(input);
  });

  api("/api/modules").then(render).catch((error) => notify(message, error.message));
})();
