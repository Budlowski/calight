(() => {
  "use strict";

  // Einstellungen → Module: globale Modulschalter. Prüfung offener Vorgänge und Speicherung auf dem Server.
  const root = document.querySelector("#module-settings-app");
  if (!root) return;
  const { esc, api, notify } = Calight;
  const box = root.querySelector("[data-modules]");
  const message = root.querySelector("[data-message]");

  const badge = (module) => {
    if (module.core) return '<span class="status-badge status-active">Immer aktiv</span>';
    if (!module.implemented) return '<span class="status-badge status-archived">In Planung</span>';
    return module.enabled ? '<span class="status-badge status-active">Aktiv</span>' : '<span class="status-badge status-archived">Inaktiv</span>';
  };
  const list = (items) => `<ul class="module-open-items">${items.map((item) => `<li>${esc(item)}</li>`).join("")}</ul>`;
  // Dialoginhalt als Knoten (CalightModal zeigt Zeichenketten als reinen Text).
  const fragment = (html) => { const node = document.createElement("div"); node.innerHTML = html; return node; };

  const card = (module) => {
    const toggle = module.implemented && !module.core
      ? `<label class="check-inline module-toggle"><input type="checkbox" data-module="${esc(module.key)}"${module.enabled ? " checked" : ""}> Aktiv</label>`
      : "";
    const open = module.openItems.blocking.length || module.openItems.warnings.length
      ? `<details class="module-open"><summary>Offene Vorgänge</summary>${list([...module.openItems.blocking, ...module.openItems.warnings])}</details>`
      : "";
    return `<section class="card module-card${module.implemented ? "" : " is-planned"}">
      <div class="module-card-heading"><div><h2>${esc(module.label)}</h2>${badge(module)}</div>${toggle}</div>
      <p>${esc(module.description)}</p>${module.note ? `<p class="muted">${esc(module.note)}</p>` : ""}${open}</section>`;
  };

  const render = (modules) => { box.innerHTML = modules.map(card).join(""); };

  async function save(input, confirmOpen = false) {
    const enabled = input.checked;
    input.disabled = true;
    try {
      const result = await api(`/api/modules/${encodeURIComponent(input.dataset.module)}`, { method: "PUT", body: { enabled, confirmOpen } });
      if (result.saved) {
        // Navigation und Seiten richten sich nach den Modulen – neu laden.
        location.reload();
        return;
      }
      render(result.modules);
      const { blocking, warnings } = result.openItems;
      if (blocking.length) {
        await CalightModal.open({ title: "Deaktivierung nicht möglich", content: fragment(`<p>Diese Vorgänge müssen zuerst abgeschlossen werden:</p>${list(blocking)}${warnings.length ? `<p>Außerdem vorhanden:</p>${list(warnings)}` : ""}`), actions: [{ label: "Schließen", primary: true }] });
        return;
      }
      const confirmed = await CalightModal.confirm({ title: "Modul deaktivieren", content: fragment(`<p>Folgende Daten bleiben erhalten, können aber bis zur Wiederaktivierung nicht bearbeitet werden:</p>${list(warnings)}`), confirmLabel: "Deaktivieren" });
      if (confirmed) {
        const again = box.querySelector(`[data-module="${CSS.escape(input.dataset.module)}"]`);
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
