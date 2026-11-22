/*
 * Einstellungen → System → Datenbereinigung. Ablauf: Auswahl → „Auswahl prüfen“ (Serveranalyse)
 * → Sicherung erstellen → Bestätigungsdialog (Passwort, Bestätigungstext, ausdrückliche
 * Bestätigung) → Ausführung. Jede Änderung an Auswahl oder Zeitraum verwirft Vorschau und
 * Sicherung. Alle Prüfungen erfolgen serverseitig; die Seite verändert bei der Auswahl nichts.
 */
(function () {
  "use strict";

  const bytes = (value) => {
    const size = Number(value || 0);
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toLocaleString("de-DE", { maximumFractionDigits: 1 })} KB`;
    return `${(size / 1024 / 1024).toLocaleString("de-DE", { maximumFractionDigits: 1 })} MB`;
  };

  /** Auswahl aus dem Formular (nur Werte; Prüfung auf dem Server). */
  function selectionOf(form) {
    const areas = [...form.querySelectorAll('input[name="area"]:checked:not(:disabled)')].map((input) => input.value);
    const mode = form.elements.mode?.value === "period" ? "period" : "all";
    return {
      areas,
      mode,
      ...(mode === "period" ? { from: form.elements.from.value, to: form.elements.to.value } : {}),
      resetNumbers: mode === "all" && Boolean(form.elements.resetNumbers?.checked),
    };
  }

  /** Bereiche ohne Zeitraumunterstützung im Zeitraummodus sperren. */
  function syncPeriod(form) {
    const period = form.elements.mode?.value === "period";
    form.querySelectorAll('input[name="area"]').forEach((input) => {
      const unsupported = input.dataset.period !== "1";
      input.disabled = period && unsupported;
      const hint = input.closest("label")?.querySelector("[data-period-hint]");
      if (hint) hint.hidden = !(period && unsupported);
    });
    form.querySelector("[data-period-fields]").hidden = !period;
    if (form.elements.resetNumbers) {
      form.elements.resetNumbers.disabled = period;
      if (period) form.elements.resetNumbers.checked = false;
    }
    const all = form.querySelector("[data-all-business]");
    const business = [...form.querySelectorAll('input[name="area"][data-group="business"]:not(:disabled)')];
    if (all) {
      all.checked = business.length > 0 && business.every((input) => input.checked);
      all.indeterminate = !all.checked && business.some((input) => input.checked);
    }
  }

  globalThis.CalightSystemPurge = { selectionOf, syncPeriod, bytes };

  const root = document.getElementById("system-purge-app");
  if (!root || !globalThis.Calight) return;
  const { esc, api, notify, format } = Calight;
  const content = root.querySelector("[data-content]");
  const message = root.querySelector("[data-message]");
  let overview = null;
  let preview = null;
  let backup = null;

  const fragment = (html) => {
    const node = document.createElement("div");
    node.innerHTML = html;
    return node;
  };
  const dateTime = (value) => (value ? format.dateTime(value) : "");

  const areaCheckbox = (area) => `<label class="check-inline system-purge-area">
      <input type="checkbox" name="area" value="${esc(area.key)}" data-group="${esc(area.group)}" data-period="${area.period ? "1" : "0"}"${area.default ? " checked" : ""}>
      <span>${esc(area.label)}${area.period ? "" : ` <small class="muted" data-period-hint hidden>– kein Zeitraum möglich</small>`}</span></label>`;

  function renderForm() {
    const business = overview.areas.filter((area) => area.group === "business");
    const master = overview.areas.filter((area) => area.group === "master");
    const reason = overview.areas.find((area) => !area.period)?.periodReason || "";
    content.innerHTML = `
      <form class="card system-purge-form" data-form novalidate>
        <div class="system-purge-groups">
          <fieldset class="system-purge-group">
            <legend>Geschäftsdaten</legend>
            <label class="check-inline system-purge-all"><input type="checkbox" data-all-business checked><strong>Alle Geschäftsdaten</strong></label>
            ${business.map(areaCheckbox).join("")}
          </fieldset>
          <fieldset class="system-purge-group">
            <legend>Stammdaten</legend>
            <p class="muted">Standardmäßig erhalten.</p>
            ${master.map(areaCheckbox).join("")}
            <legend class="system-purge-sublegend">Zusätzliche Option</legend>
            <label class="check-inline"><input type="checkbox" name="resetNumbers"><span>Nummernkreis-Zählerstände zurücksetzen<br><small class="muted">Nur bei vollständiger Bereinigung und nur für Kreise, deren vergebene Nummern vollständig entfernt werden.</small></span></label>
          </fieldset>
        </div>
        <fieldset class="system-purge-group">
          <legend>Umfang</legend>
          <div class="segmented system-purge-mode" role="radiogroup" aria-label="Umfang">
            <label><input type="radio" name="mode" value="all" checked><span>Alle Datensätze</span></label>
            <label><input type="radio" name="mode" value="period"><span>Zeitraum</span></label>
          </div>
          <div class="form-grid" data-period-fields hidden>
            <label><span class="field-label">Von <span aria-hidden="true">*</span></span><input type="date" name="from"></label>
            <label><span class="field-label">Bis <span aria-hidden="true">*</span></span><input type="date" name="to"></label>
            <p class="muted system-purge-hint">Nur für Rapporte (Leistungsdatum aller Positionen), Tagesberichte (Berichtsdatum), Zeiterfassung (Buchungs- bzw. Abwesenheitstag; Einreichungen nur vollständig im Zeitraum) sowie Reisekosten und Auslagen (Tag). ${esc(reason)}</p>
          </div>
        </fieldset>
        <div class="form-actions"><button class="button" type="submit" data-icon="eye">Auswahl prüfen</button></div>
      </form>
      <section data-preview aria-live="polite"></section>
      ${productionSection()}
      ${runsSection()}`;
    const form = content.querySelector("[data-form]");
    syncPeriod(form);
    form.addEventListener("change", (event) => {
      if (event.target.matches("[data-all-business]")) {
        form.querySelectorAll('input[name="area"][data-group="business"]:not(:disabled)').forEach((input) => { input.checked = event.target.checked; });
      }
      syncPeriod(form);
      invalidate();
    });
    form.addEventListener("input", (event) => { if (event.target.matches('[type="date"]')) invalidate(); });
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      check(form);
    });
    content.querySelector("[data-production]")?.addEventListener("click", activateProduction);
  }

  function productionSection() {
    return `<section class="card system-purge-production">
        <h2>Produktivstatus</h2>
        <p>Nach der Bereinigung der Testdaten den Produktivstatus aktivieren. Danach ist die Datenbereinigung dauerhaft gesperrt; der Status lässt sich in Calight nicht zurücksetzen.</p>
        <button class="button button-danger-light" type="button" data-production>Produktivstatus aktivieren …</button>
      </section>`;
  }

  function runsSection() {
    if (!overview.runs.length) return "";
    return `<section class="card system-purge-runs"><h2>Protokoll</h2><ul class="system-purge-run-list">${overview.runs.map((run) => `<li>
        <span class="status-badge ${run.status === "succeeded" ? "status-purge-succeeded" : "status-purge-failed"}">${run.status === "succeeded" ? "Erfolgreich" : "Abgebrochen"}</span>
        <span><strong>${esc(dateTime(run.startedAt))}</strong> · ${esc(run.user)} · ${esc(run.areas.join(", ") || "Nummernkreise")}<br>
        <small class="muted">${esc(run.message)}${run.backup ? ` · Sicherung: ${esc(run.backup)}` : ""}</small></span></li>`).join("")}</ul></section>`;
  }

  function invalidate() {
    if (!preview && !backup) return;
    preview = null;
    backup = null;
    const box = content.querySelector("[data-preview]");
    box.innerHTML = '<p class="notice notice-warning">Die Auswahl wurde geändert. Bitte erneut „Auswahl prüfen“.</p>';
  }

  async function check(form) {
    message.hidden = true;
    const selection = selectionOf(form);
    if (selection.mode === "period" && (!selection.from || !selection.to)) {
      notify(message, "Bitte für den Zeitraum „Von“ und „Bis“ angeben.");
      return;
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      preview = await api("/api/system/purge/preview", { method: "POST", body: selection });
      backup = null;
      renderPreview();
    } catch (error) {
      notify(message, error.message);
    } finally {
      button.disabled = false;
    }
  }

  function renderPreview() {
    const box = content.querySelector("[data-preview]");
    const blockers = preview.blockers.length
      ? `<div class="notice notice-error" role="alert"><strong>Ausführung blockiert.</strong> Bitte die Auswahl ergänzen bzw. anpassen:<ul>${preview.blockers.map((item) => `<li>${esc(item)}</li>`).join("")}</ul></div>` : "";
    const areas = preview.areas.map((area) => `<div class="system-purge-preview-area">
        <h3>${esc(area.label)} <span class="muted">(${area.total.toLocaleString("de-DE")})</span></h3>
        ${area.tables.length ? `<ul>${area.tables.map((table) => `<li><span>${esc(table.label)}</span><strong>${table.count.toLocaleString("de-DE")}</strong></li>`).join("")}</ul>` : '<p class="muted">Keine Datensätze.</p>'}
      </div>`).join("");
    const numbers = preview.numberRanges.length ? `<h3>Nummernkreise</h3><ul class="system-purge-numbers">${preview.numberRanges.map((range) => `<li>${esc(range.label)}: ${
      range.resetTo !== null ? `Zähler ${range.next} → ${range.resetTo}` : esc(range.reason)}</li>`).join("")}</ul>` : "";
    const files = preview.files;
    box.innerHTML = `<div class="card system-purge-preview">
        <h2>Löschvorschau</h2>
        ${blockers}
        <p><strong>${preview.total.toLocaleString("de-DE")} Datensätze</strong> · <strong>${files.count} Dateien</strong> (${bytes(files.bytes)})${files.missing ? ` · ${files.missing} bereits fehlende Dateien` : ""}${files.kept ? ` · ${files.kept} weiterhin verwendete Dateien bleiben erhalten` : ""}</p>
        <div class="system-purge-preview-grid">${areas}</div>
        ${numbers}
        <h3>Bleibt erhalten</h3><ul class="system-purge-preserved">${preview.preserved.map((item) => `<li>${esc(item)}</li>`).join("")}</ul>
        <p class="muted">Vorschau gültig bis ${esc(dateTime(preview.expiresAt))}. Jede Änderung an Daten oder Auswahl erfordert eine neue Prüfung.</p>
        ${preview.executable ? `<div class="system-purge-steps">
          <div><h3>1. Sicherung</h3><p data-backup-state class="muted">Vor der Löschung wird eine vollständige Sicherung von Datenbank, Dokumenten und Angeboten erstellt und geprüft.</p>
            <button class="button button-light" type="button" data-backup data-icon="save">Sicherung erstellen</button></div>
          <div><h3>2. Löschen</h3><p class="muted">Erst nach erfolgreicher Sicherung möglich.</p>
            <button class="button button-danger-light" type="button" data-execute disabled data-icon="trash">Daten endgültig löschen …</button></div>
        </div>` : (preview.total === 0 && !preview.blockers.length ? '<p class="notice">Für diese Auswahl gibt es nichts zu löschen.</p>' : "")}
      </div>`;
    box.querySelector("[data-backup]")?.addEventListener("click", createBackup);
    box.querySelector("[data-execute]")?.addEventListener("click", confirmExecute);
  }

  async function createBackup(event) {
    const button = event.currentTarget;
    const state = content.querySelector("[data-backup-state]");
    button.disabled = true;
    state.textContent = "Sicherung wird erstellt und geprüft …";
    try {
      backup = await api("/api/system/purge/backup", { method: "POST", body: { previewId: preview.previewId } });
      state.className = "notice notice-success";
      state.textContent = `Sicherung erstellt und geprüft: ${backup.directory} (${backup.fileCount} Dateien, ${bytes(backup.totalBytes)}, ${dateTime(backup.createdAt)}).`;
      content.querySelector("[data-execute]").disabled = false;
    } catch (error) {
      state.className = "notice notice-error";
      state.textContent = error.message;
      button.disabled = false;
    }
  }

  async function confirmExecute() {
    if (!preview || !backup) return;
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<p class="notice notice-error">Es werden <strong>${preview.total.toLocaleString("de-DE")} Datensätze</strong> und <strong>${preview.files.count} Dateien</strong> endgültig gelöscht. Sicherung: ${esc(backup.directory)}</p>
      <label><span class="field-label">Ihr Passwort <span aria-hidden="true">*</span></span><input type="password" name="password" autocomplete="current-password" required></label>
      <label><span class="field-label">Bestätigungstext: <strong>${esc(overview.confirmation)}</strong> <span aria-hidden="true">*</span></span><input name="confirmation" autocomplete="off" required spellcheck="false"></label>
      <label class="check-inline"><input type="checkbox" name="confirmed" required><span>Ich bestätige die endgültige Löschung der ausgewählten Daten.</span></label>
      <div class="notice notice-error" data-dialog-error hidden></div>`;
    let result = null;
    await CalightModal.open({
      title: "Daten endgültig löschen",
      content: form,
      initialFocus: '[name="password"]',
      actions: [
        { label: "Abbrechen", value: false },
        { label: "Endgültig löschen", value: true, danger: true, validate: true, onClick: async () => {
          const error = form.querySelector("[data-dialog-error]");
          if (form.elements.confirmation.value !== overview.confirmation) {
            notify(error, `Bitte „${overview.confirmation}“ exakt eingeben.`);
            return false;
          }
          try {
            result = await api("/api/system/purge/execute", { method: "POST", body: {
              previewId: preview.previewId, backupId: backup.backupId, password: form.elements.password.value,
              confirmation: form.elements.confirmation.value, confirmed: form.elements.confirmed.checked,
            } });
            return undefined;
          } catch (requestError) {
            notify(error, requestError.message);
            return false;
          }
        } },
      ],
    });
    if (!result) return;
    preview = null;
    backup = null;
    await load();
    notify(message, `Bereinigung abgeschlossen: ${result.message}${result.warnings.length ? ` Hinweise: ${result.warnings.join(" ")}` : ""} Sicherung: ${result.backup}`, result.warnings.length ? "warning" : "success");
  }

  async function activateProduction() {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<p>Der Produktivstatus sperrt die Datenbereinigung dauerhaft. Er kann in Calight nicht zurückgesetzt werden.</p>
      <label><span class="field-label">Ihr Passwort <span aria-hidden="true">*</span></span><input type="password" name="password" autocomplete="current-password" required></label>
      <label><span class="field-label">Bestätigungstext: <strong>${esc(overview.productionConfirmation)}</strong> <span aria-hidden="true">*</span></span><input name="confirmation" autocomplete="off" required spellcheck="false"></label>
      <div class="notice notice-error" data-dialog-error hidden></div>`;
    let done = false;
    await CalightModal.open({
      title: "Produktivstatus aktivieren",
      content: form,
      initialFocus: '[name="password"]',
      actions: [
        { label: "Abbrechen", value: false },
        { label: "Endgültig aktivieren", value: true, danger: true, validate: true, onClick: async () => {
          try {
            await api("/api/system/production", { method: "POST", body: { password: form.elements.password.value, confirmation: form.elements.confirmation.value } });
            done = true;
            return undefined;
          } catch (error) {
            notify(form.querySelector("[data-dialog-error]"), error.message);
            return false;
          }
        } },
      ],
    });
    if (done) await load();
  }

  function renderProduction() {
    content.innerHTML = `<div class="notice notice-success" role="status"><strong>Produktivstatus aktiv</strong> seit ${esc(dateTime(overview.production.activatedAt))} (${esc(overview.production.user)}). Die Datenbereinigung ist dauerhaft gesperrt.</div>${runsSection()}`;
  }

  async function load() {
    try {
      overview = await api("/api/system/purge");
      if (overview.production) renderProduction();
      else renderForm();
    } catch (error) {
      content.innerHTML = "";
      notify(message, error.message);
    }
  }

  load();
})();
