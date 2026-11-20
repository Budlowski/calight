(() => {
  "use strict";

  const root = document.querySelector("#daily-report-detail");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const form = root.querySelector("form");
  const entriesBox = root.querySelector("[data-entries]");
  const message = root.querySelector("[data-message]");
  const error = root.querySelector("[data-error]");
  const save = root.querySelector("[data-save]");
  const remove = root.querySelector("[data-delete]");
  const summary = root.querySelector("[data-time-summary]");
  const reportUrl = () => `/api/daily-reports/${encodeURIComponent(report.id)}`;

  const attachmentsBox = root.querySelector("[data-attachments]");
  const attachmentError = root.querySelector("[data-attachment-error]");
  const materialBox = root.querySelector("[data-material-requests]");

  let report;
  let employees = [];
  let targets = [];
  let referenceOrders = [];
  let dirty = false;
  let saving = false;

  function employeeOptions(selected, fallbackName) {
    const options = employees.map((employee) => `<option value="${esc(employee.id)}"${employee.id === selected ? " selected" : ""}>${esc(employee.display_name)}${employee.personnel_number ? ` · ${esc(employee.personnel_number)}` : ""}</option>`);
    // Bereits gebuchte, inzwischen inaktive Mitarbeiter bleiben auswählbar.
    if (selected && !employees.some((employee) => employee.id === selected)) {
      options.push(`<option value="${esc(selected)}" selected>${esc(fallbackName || "Inaktiver Mitarbeiter")} (inaktiv)</option>`);
    }
    return options.join("");
  }

  const targetOptions = (selected) => targets
    .map((target) => `<option value="${esc(target.key)}"${target.key === selected ? " selected" : ""}>${"  ".repeat(Number(target.depth || 0))}${esc(target.label)}</option>`)
    .join("");

  const durationOf = (input) => Number(input.value.replace(",", ".")) || 0;

  function updateSummary() {
    const total = [...entriesBox.querySelectorAll('[data-field="durationHours"]')].reduce((sum, input) => sum + durationOf(input), 0);
    summary.textContent = `${format.number(total, 3)} Stunden erfasst`;
  }

  function addEntry(entry = {}) {
    const row = document.createElement("div");
    row.className = "report-time-row";
    row.dataset.entryId = entry.id || "";
    const cost = entry.labor_cost !== undefined
      ? `${format.number(entry.hourly_cost_rate_snapshot, 4)} €/h · ${format.money(entry.labor_cost)}`
      : "";
    row.innerHTML = `
      <label><span class="field-label">Mitarbeiter <span aria-hidden="true">*</span></span><select data-field="employeeId" required>${employeeOptions(entry.employee_id || (employees.length === 1 ? employees[0].id : ""), entry.employee_name || "")}</select></label>
      <label><span class="field-label">Arbeitsdatum <span aria-hidden="true">*</span></span><input data-field="workDate" type="date" value="${esc(entry.work_date || form.elements.reportDate.value)}" required></label>
      <label><span class="field-label">Dauer (h) <span aria-hidden="true">*</span></span><input data-field="durationHours" inputmode="decimal" value="${esc(String(entry.duration_hours || "").replace(".", ","))}" required></label>
      <label class="report-target"><span class="field-label">Zuordnung</span><select data-field="targetKey">${targetOptions(entry.targetKey || "order")}</select></label>
      <label class="report-description"><span class="field-label">Tätigkeit (optional)</span><input data-field="description" maxlength="1000" value="${esc(entry.description || "")}"></label>
      <div class="report-entry-meta">${cost}</div>
      <button class="icon-button icon-button-danger" type="button" data-remove data-remove-entry data-icon="trash" aria-label="Zeiteintrag entfernen" title="Zeiteintrag entfernen"></button>`;
    entriesBox.append(row);
    updateSummary();
    return row;
  }

  const field = (row, name) => row.querySelector(`[data-field="${name}"]`).value.trim();
  const entriesPayload = () => [...entriesBox.querySelectorAll(".report-time-row")].map((row) => ({
    id: row.dataset.entryId || undefined,
    employeeId: field(row, "employeeId"),
    workDate: field(row, "workDate"),
    durationHours: field(row, "durationHours").replace(",", "."),
    targetKey: field(row, "targetKey"),
    description: field(row, "description"),
  }));

  function setEditable(editable) {
    form.querySelectorAll("input, textarea, select, button[type=submit], [data-add-entry], [data-remove]").forEach((control) => {
      control.disabled = !editable;
    });
    remove.hidden = !editable;
  }

  function renderHeader() {
    const reportDate = format.date(report.report_date);
    root.querySelector("[data-title]").textContent = report.title || `Bautagesbericht vom ${reportDate}`;
    const assignment = report.order_id ? report.order_number : "Ohne Auftrag";
    root.querySelector("[data-meta]").textContent = [assignment, report.customer_name, report.project_name, `erstellt von ${report.creator_name}`].filter(Boolean).join(" · ");
    root.querySelector("[data-order-crumb]").innerHTML = report.order_id ? `<a href="/orders/${encodeURIComponent(report.order_id)}">${esc(report.order_number)}</a>` : "";
    root.querySelector("[data-order-crumb]").hidden = !report.order_id;
    root.querySelector("[data-current-crumb]").textContent = reportDate;
    document.title = `Bautagesbericht ${reportDate} · ${assignment}`;
    renderAssignment();
  }

  /**
   * Zuordnung: Kunde, Projekt und Auftrag. Die Arbeitszeiten sind tatsächliche Arbeitszeit und
   * erscheinen genau einmal in der Zeiterfassung der Mitarbeiter; sie folgen dem Auftrag des
   * Berichts. Büro: Auftrag zuordnen, ändern oder entfernen (protokolliert).
   */
  function renderAssignment() {
    const box = root.querySelector("[data-assignment]");
    const lines = [
      `<dt>Kunde</dt><dd>${esc(report.customer_name)}${report.customer_number ? ` <span class="muted">(${esc(report.customer_number)})</span>` : ""}</dd>`,
      report.project_id ? `<dt>Projekt</dt><dd>${esc([report.project_number, report.project_name].filter(Boolean).join(" · "))}</dd>` : "",
      `<dt>Auftrag</dt><dd>${report.order_id
        ? `<a href="/orders/${encodeURIComponent(report.order_id)}">${esc(report.order_number)} · ${esc(report.order_title)}</a>${report.order_assigned_at
          ? ` <small class="muted">zugeordnet von ${esc(report.order_assigned_by_name)} am ${format.dateTime(report.order_assigned_at)}</small>` : ""}`
        : '<span class="muted">Ohne Auftrag</span>'}</dd>`,
      `<dt>Arbeitszeiten</dt><dd>erscheinen in der Zeiterfassung der Mitarbeiter${report.order_id ? " und zählen als Ist-Stunden dieses Auftrags" : " (ohne Auftrag)"}</dd>`,
    ];
    const events = report.orderEvents || [];
    box.innerHTML = `<dl class="report-assignment">${lines.join("")}</dl>
      ${report.canAssignOrder ? `<div class="report-assignment-actions"><button class="button button-light button-small" type="button" data-assign-order data-icon="clipboard-list">${report.order_id ? "Auftrag ändern …" : "Auftrag zuordnen …"}</button></div>` : ""}
      ${events.length ? `<details class="report-order-events"><summary>Verlauf der Auftragszuordnung (${events.length})</summary><ul>${events.map((event) => `<li>${format.dateTime(event.changed_at)} · ${esc(event.changed_by_name)}: ${esc(event.previous_order_number || "ohne Auftrag")} → ${esc(event.order_number || "ohne Auftrag")}</li>`).join("")}</ul></details>` : ""}`;
  }

  // ---------------------------------------------------------------------------
  // Fotos und Anhänge: werden sofort einzeln hochgeladen, unabhängig vom Speichern.
  // ---------------------------------------------------------------------------
  const fileSize = (bytes) => (bytes >= 1048576 ? `${format.number(bytes / 1048576, 1)} MB` : `${format.number(Math.max(1, bytes / 1024), 0)} KB`);

  function renderAttachments() {
    const items = report.attachments || [];
    root.querySelector("[data-attachment-summary]").textContent = items.length
      ? `${items.length} ${items.length === 1 ? "Anhang" : "Anhänge"}`
      : "Fotos, PDF- oder Textdateien bis 15 MB";
    root.querySelector("[data-attachment-actions]").hidden = !report.canEdit;
    if (!items.length) {
      attachmentsBox.innerHTML = '<p class="empty-state">Noch keine Fotos oder Anhänge.</p>';
      return;
    }
    attachmentsBox.innerHTML = `<div class="attachment-grid">${items.map((item) => `
      <figure class="attachment-item">
        ${item.preview
          ? `<a class="attachment-preview" href="${esc(item.url)}" target="_blank" rel="noopener"><img src="${esc(item.url)}" alt="${esc(item.name)}" loading="lazy"></a>`
          : `<a class="attachment-preview attachment-file" href="${esc(item.url)}" download><span class="ui-icon icon-file-down" aria-hidden="true"></span><span>${esc(item.name.split(".").pop().toUpperCase())}</span></a>`}
        <figcaption>
          <a href="${esc(item.url)}" ${item.preview ? 'target="_blank" rel="noopener"' : "download"} title="${esc(item.name)}">${esc(item.name)}</a>
          <small class="muted">${fileSize(item.size)} · ${esc(item.uploadedByName)}</small>
        </figcaption>
        ${report.canEdit ? `<button class="icon-button icon-button-danger attachment-remove" type="button" data-remove-attachment="${esc(item.id)}" data-icon="trash" title="Anhang entfernen" aria-label="Anhang ${esc(item.name)} entfernen"></button>` : ""}
      </figure>`).join("")}</div>`;
  }

  async function upload(files) {
    attachmentError.hidden = true;
    const summary = root.querySelector("[data-attachment-summary]");
    const failed = [];
    let index = 0;
    for (const file of files) {
      index++;
      summary.textContent = `Lade ${index} von ${files.length} hoch …`;
      if (file.size > 15 * 1024 * 1024) {
        failed.push(`${file.name}: Die Datei ist größer als 15 MB.`);
        continue;
      }
      const body = new FormData();
      body.append("file", file);
      try {
        const response = await fetch(`/api/daily-reports/${encodeURIComponent(report.id)}/attachments`, {
          method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf, Accept: "application/json" }, body,
        });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error((result.details || [result.error || `HTTP ${response.status}`]).join(" "));
        report.attachments = [...(report.attachments || []), result.data];
      } catch (exception) {
        failed.push(`${file.name}: ${exception.message}`);
      }
    }
    renderAttachments();
    if (failed.length) {
      attachmentError.textContent = failed.join(" ");
      attachmentError.hidden = false;
    }
  }

  root.querySelectorAll("[data-attachment-input]").forEach((input) => input.addEventListener("change", async () => {
    const files = [...input.files];
    input.value = "";
    if (files.length) await upload(files);
  }));

  attachmentsBox.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-remove-attachment]");
    if (!button) return;
    const confirmed = await CalightModal.confirm({ title: "Anhang entfernen", content: "Der Anhang wird endgültig entfernt.", confirmLabel: "Entfernen", danger: true });
    if (!confirmed) return;
    button.disabled = true;
    try {
      await api(`/api/daily-reports/${encodeURIComponent(report.id)}/attachments/${encodeURIComponent(button.dataset.removeAttachment)}`, { method: "DELETE" });
      report.attachments = report.attachments.filter((item) => item.id !== button.dataset.removeAttachment);
      renderAttachments();
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  });

  // ---------------------------------------------------------------------------
  // Materialbedarf: eigene Datensätze, unabhängig vom Speichern des Berichts.
  // ---------------------------------------------------------------------------
  async function reloadMaterial() {
    const current = await api(`/api/daily-reports/${encodeURIComponent(report.id)}`);
    report.materialRequests = current.materialRequests;
    renderMaterial();
  }

  CalightMaterialRequests.bindActions(materialBox, {
    find: (id) => (report.materialRequests || []).find((item) => item.id === id),
    reload: reloadMaterial,
    message,
  });

  function renderMaterial() {
    materialBox.innerHTML = CalightMaterialRequests.renderCompact(report.materialRequests || []);
  }

  root.querySelector("[data-add-material]").addEventListener("click", () => CalightMaterialRequests.openDialog({
    dailyReportId: report.id,
    orderLabel: report.order_id ? `${report.order_number} · ${report.order_title}` : "",
    onSaved: async () => {
      await reloadMaterial();
      notify(message, "Materialanforderung wurde an das Büro gemeldet.", "success");
    },
  }));

  // Auftragszuordnung (Büro): nur Aufträge desselben Kunden; Tätigkeiten und Stunden bleiben unverändert,
  // die zugehörigen Zeitbuchungen folgen dem Auftrag (keine neuen Buchungen).
  root.querySelector("[data-assignment]").addEventListener("click", async (event) => {
    if (!event.target.closest("[data-assign-order]")) return;
    if (dirty) {
      notify(message, "Bitte zuerst die Änderungen speichern.", "warning");
      return;
    }
    const candidates = referenceOrders.filter((order) => order.customer_id === report.customer_id && order.id !== report.order_id
      && (report.order_id || !report.project_id || !order.project_id || order.project_id === report.project_id));
    const dialog = document.createElement("form");
    dialog.className = "form-stack";
    dialog.innerHTML = `
      <p>Die Zuordnung ändert weder Tätigkeiten noch Stunden. Die Arbeitszeiten dieses Berichts gehören danach zum gewählten Auftrag – bzw. ohne Auftrag zu keinem – und zählen dort genau einmal. Die Änderung wird mit Benutzer und Zeitpunkt protokolliert.</p>
      <label><span class="field-label">Auftrag von ${esc(report.customer_name)}</span>
        <select name="orderId">${report.order_id ? '<option value="">Ohne Auftrag (Zuordnung entfernen)</option>' : '<option value="">Auftrag auswählen …</option>'}${candidates.map((order) => `<option value="${esc(order.id)}">${esc(order.order_number)} · ${esc(order.title)}</option>`).join("")}</select></label>`;
    if (candidates.length) Calight.searchableSelect(dialog.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
    const ok = await CalightModal.open({ title: report.order_id ? "Auftrag ändern" : "Auftrag zuordnen", content: dialog, actions: [{ label: "Abbrechen", value: false }, { label: "Übernehmen", value: true, primary: true }] });
    if (!ok) return;
    if (!report.order_id && !dialog.elements.orderId.value) {
      notify(message, "Bitte einen Auftrag auswählen.", "warning");
      return;
    }
    try {
      await api(`/api/daily-reports/${encodeURIComponent(report.id)}/assign-order`, { method: "POST", body: { version: report.version, orderId: dialog.elements.orderId.value } });
      await load();
      notify(message, report.order_id ? `Der Bericht gehört jetzt zum Auftrag ${report.order_number}.` : "Der Bericht ist keinem Auftrag mehr zugeordnet.", "success");
    } catch (exception) {
      notify(message, exception.message);
    }
  });

  async function load() {
    const [reference, current] = await Promise.all([
      api("/api/daily-reports/reference-data"),
      api(`/api/daily-reports/${encodeURIComponent(root.dataset.id)}`),
    ]);
    report = current;
    employees = reference.employees;
    targets = report.targets;
    referenceOrders = reference.orders || [];
    form.elements.reportDate.value = report.report_date || "";
    for (const name of ["title", "activities", "notes"]) form.elements[name].value = report[name] || "";
    renderHeader();
    entriesBox.innerHTML = "";
    report.entries.forEach(addEntry);
    setEditable(report.canEdit);
    renderAttachments();
    renderMaterial();
    dirty = false;
  }

  form.addEventListener("input", () => {
    dirty = true;
    updateSummary();
  });
  entriesBox.addEventListener("click", (event) => {
    const button = event.target.closest("[data-remove]");
    if (!button) return;
    button.closest(".report-time-row").remove();
    dirty = true;
    updateSummary();
  });
  root.querySelector("[data-add-entry]").addEventListener("click", () => {
    addEntry().querySelector("select")?.focus();
    dirty = true;
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (saving || !form.reportValidity()) return;
    saving = true;
    save.disabled = true;
    error.hidden = true;
    try {
      await api(reportUrl(), {
        method: "PUT",
        body: {
          version: report.version,
          reportDate: form.elements.reportDate.value,
          title: form.elements.title.value.trim(),
          activities: form.elements.activities.value.trim(),
          notes: form.elements.notes.value.trim(),
          entries: entriesPayload(),
        },
      });
      dirty = false;
      await load();
      notify(message, "Bautagesbericht wurde gespeichert.", "success");
    } catch (exception) {
      error.textContent = exception.message;
      error.hidden = false;
    } finally {
      saving = false;
      save.disabled = false;
    }
  });

  remove.addEventListener("click", async () => {
    const confirmed = await CalightModal.confirm({
      title: "Bautagesbericht löschen",
      content: "Der Bericht und seine Zeiteinträge werden unwiderruflich gelöscht.",
      confirmLabel: "Löschen",
      danger: true,
    });
    if (!confirmed) return;
    remove.disabled = true;
    try {
      await api(reportUrl(), { method: "DELETE", body: { version: report.version } });
      dirty = false;
      location.assign("/daily-reports");
    } catch (exception) {
      remove.disabled = false;
      notify(message, exception.message);
    }
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });
  load().catch((exception) => notify(message, exception.message));
})();
