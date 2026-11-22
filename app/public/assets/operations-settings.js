(() => {
  "use strict";

  // Einstellungen → Arbeitserfassung: typisierte Betriebseinstellungen (Ja/Nein, Auswahl, Zahl) in
  // fachlichen Untergruppen. Änderungen werden gesammelt und mit „Speichern“ gemeinsam an dieselbe
  // API übergeben; Prüfung, Grenzen und Speicherung liegen unverändert auf dem Server.
  const UNITS = { h: "Std.", min: "Min.", "€": "€", "%": "%", "€/km": "€/km" };
  /** Rein darstellende Gliederung; Schlüssel ohne Zuordnung erscheinen in „Weitere“. */
  const SUBGROUPS = {
    time: [["Erfassung und Einreichung", ["time_tracking.times_optional", "time_tracking.submission_rhythm", "time_tracking.warnings_enabled"]]],
    limits: [
      ["Tägliche Arbeitszeit", ["time_limits.regular_daily_hours", "time_limits.max_daily_hours"]],
      ["Pausen", ["time_limits.break_threshold_1_hours", "time_limits.break_minimum_1_minutes", "time_limits.break_threshold_2_hours", "time_limits.break_minimum_2_minutes",
        "time_limits.min_break_part_minutes", "time_limits.max_continuous_hours"]],
      ["Ruhezeit", ["time_limits.min_rest_hours"]],
    ],
    travel: [
      ["Verpflegung", ["travel.meal_allowances_enabled", "travel.single_day_threshold_hours", "travel.allowance_single", "travel.allowance_arrival", "travel.allowance_full", "travel.allowance_departure"]],
      ["Kürzung bei gestellten Mahlzeiten", ["travel.reduction_breakfast_percent", "travel.reduction_lunch_percent", "travel.reduction_dinner_percent"]],
      ["Privat-Pkw", ["travel.km_rate"]],
    ],
  };
  const number = (value) => String(value).replace(".", ",");
  const unit = (setting) => UNITS[setting.unit] || setting.unit || "";
  /** Eurobeträge immer mit zwei Nachkommastellen, übrige Zahlen wie gespeichert (nur Anzeige). */
  const display = (setting) => (["€", "€/km"].includes(setting.unit) && Number.isFinite(Number(setting.value)) ? Number(setting.value).toFixed(2) : String(setting.value)).replace(".", ",");

  globalThis.CalightOperationsSettings = Object.freeze({ SUBGROUPS, unit, display });

  const root = typeof document !== "undefined" ? document.querySelector("#operations-settings-app") : null;
  if (!root) return;
  const { esc, api, format, notify } = Calight;
  const box = root.querySelector("[data-settings]");
  const message = root.querySelector("[data-message]");
  const saveButton = root.querySelector("[data-save]");
  const discardButton = root.querySelector("[data-discard]");
  const dirtyState = root.querySelector("[data-dirty-state]");
  const descriptions = { time: "Gilt für Rapporte, Tagesberichte und die Zeiterfassung.", limits: "Prüfgrenzwerte für Hinweise bei Einreichung und Festschreibung.", travel: "Rechnerische Referenzwerte für Verpflegung und Privat-Pkw." };
  const changes = new Map();
  let current = null;
  let saving = false;

  const changed = (setting) => (setting.updatedByName ? ` · zuletzt geändert von ${esc(setting.updatedByName)} am ${format.dateTime(setting.updatedAt)}` : "");
  const hint = (setting) => `${esc(setting.description)}${setting.type === "number" && String(setting.value) !== String(setting.default) ? ` · Standard ${esc(number(setting.default))} ${esc(unit(setting))}` : ""}${changed(setting)}`;

  function input(setting) {
    const id = `setting-${setting.key.replace(/[^a-z0-9]+/gi, "-")}`;
    if (setting.type === "bool") return `<input id="${id}" type="checkbox" data-key="${esc(setting.key)}" data-type="bool"${setting.value ? " checked" : ""}>`;
    if (setting.type === "choice") {
      return `<select id="${id}" data-key="${esc(setting.key)}" data-type="choice">${Object.entries(setting.options).map(([key, label]) => `<option value="${esc(key)}"${key === setting.value ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`;
    }
    return `<input id="${id}" type="text" data-key="${esc(setting.key)}" data-type="number" inputmode="decimal" value="${esc(display(setting))}"><span class="operations-unit">${esc(unit(setting))}</span>`;
  }

  const row = (setting) => `<div class="operations-row" data-row="${esc(setting.key)}">
      <label class="operations-label" for="setting-${esc(setting.key.replace(/[^a-z0-9]+/gi, "-"))}"><strong>${esc(setting.label)}</strong><small class="field-hint">${hint(setting)}</small></label>
      <div class="operations-control">${input(setting)}</div>
    </div>`;

  function render(data) {
    current = data;
    const byKey = Object.fromEntries(data.settings.map((setting) => [setting.key, setting]));
    box.innerHTML = Object.entries(data.groups).map(([group, title]) => {
      const settings = data.settings.filter((setting) => setting.group === group);
      if (!settings.length) return "";
      const used = new Set();
      const subgroups = (SUBGROUPS[group] || []).map(([label, keys]) => {
        const html = keys.map((key) => {
          if (!byKey[key] || byKey[key].group !== group) return "";
          used.add(key);
          return row(byKey[key]);
        }).join("");
        return html ? `<div class="settings-subgroup"><h3>${esc(label)}</h3>${html}</div>` : "";
      }).join("");
      const rest = settings.filter((setting) => !used.has(setting.key)).map(row).join("");
      return `<section class="card settings-section"><div class="settings-section-heading"><div><h2>${esc(title)}</h2><p class="muted">${esc(descriptions[group] || "")}</p></div></div>
        ${subgroups}${rest ? `<div class="settings-subgroup"><h3>Weitere</h3>${rest}</div>` : ""}</section>`;
    }).join("");
    changes.clear();
    updateState();
  }

  function updateState() {
    const pending = changes.size;
    dirtyState.textContent = pending ? `${pending} ungespeicherte ${pending === 1 ? "Änderung" : "Änderungen"}` : "Keine ungespeicherten Änderungen.";
    saveButton.disabled = saving || pending === 0;
    discardButton.disabled = saving || pending === 0;
    box.querySelectorAll("[data-row]").forEach((element) => element.classList.toggle("is-changed", changes.has(element.dataset.row)));
  }

  function valueOf(element) {
    if (element.dataset.type === "bool") return element.checked;
    if (element.dataset.type === "number") return element.value.trim().replace(",", ".");
    return element.value;
  }

  function track(event) {
    const element = event.target.closest("[data-key]");
    if (!element) return;
    const original = current.settings.find((setting) => setting.key === element.dataset.key);
    const value = valueOf(element);
    // Zahlen numerisch vergleichen („14,00“ entspricht gespeichertem „14“); ungültige Eingaben gelten als Änderung und prüft der Server.
    const same = element.dataset.type === "number" ? value !== "" && Number(value) === Number(original.value) : value === original.value;
    if (same) changes.delete(element.dataset.key);
    else changes.set(element.dataset.key, value);
    updateState();
  }
  box.addEventListener("input", track);
  box.addEventListener("change", track);

  saveButton.addEventListener("click", async () => {
    if (saving || changes.size === 0) return;
    saving = true;
    updateState();
    try {
      render(await api("/api/operational-settings", { method: "PUT", body: Object.fromEntries(changes) }));
      notify(message, "Einstellungen gespeichert.", "success");
    } catch (error) {
      notify(message, error.message);
    } finally {
      saving = false;
      updateState();
    }
  });
  discardButton.addEventListener("click", () => {
    if (current) render(current);
  });
  addEventListener("beforeunload", (event) => {
    if (!changes.size) return;
    event.preventDefault();
    event.returnValue = "";
  });

  api("/api/operational-settings").then(render).catch((error) => notify(message, error.message));
})();
