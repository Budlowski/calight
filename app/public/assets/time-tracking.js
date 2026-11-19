// Zeiterfassung: Woche (Start), Tag (Erfassung), Monat (Auswertung) und für das Büro eine
// tabellarische Auswertung. Berechnung, Prüfungen und Rechte liegen auf dem Server.
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;

  // ---------------------------------------------------------------------------
  // Reine Hilfen (auch für Tests): Datumsrechnung auf YYYY-MM-DD ohne Zeitzonenfehler.
  // ---------------------------------------------------------------------------
  const parse = (value) => {
    const [year, month, day] = value.split("-").map(Number);
    return new Date(Date.UTC(year, month - 1, day));
  };
  const iso = (date) => date.toISOString().slice(0, 10);
  const addDays = (value, days) => {
    const date = parse(value);
    date.setUTCDate(date.getUTCDate() + days);
    return iso(date);
  };
  const weekStart = (value) => addDays(value, -((parse(value).getUTCDay() + 6) % 7));
  const monthRange = (value) => {
    const date = parse(value);
    const first = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), 1));
    const last = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0));
    return [iso(first), iso(last)];
  };
  const addMonths = (value, months) => {
    const date = parse(value);
    return iso(new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1)));
  };
  /** Minuten als „7:30 h“. */
  const hours = (minutes) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")} h`;
  const weekday = (value) => ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"][parse(value).getUTCDay()];
  const localToday = () => {
    const now = new Date();
    return new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  };
  const toMinutes = (time) => Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
  const toTime = (minutes) => `${String(Math.floor((minutes % 1440) / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;

  globalThis.CalightTimeTracking = Object.freeze({ addDays, weekStart, monthRange, addMonths, hours, weekday, toMinutes, toTime });

  const root = document.querySelector("#time-tracking-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const content = root.querySelector("[data-content]");
  const employeeSelect = root.querySelector("[data-employee]");
  const params = new URLSearchParams(location.search);
  const state = {
    view: ["day", "week", "month", "report"].includes(params.get("view")) ? params.get("view") : "week",
    date: /^\d{4}-\d{2}-\d{2}$/.test(params.get("date") || "") ? params.get("date") : localToday(),
    employee: params.get("employee") || "",
  };
  let reference = null;
  let current = null;
  const money = (value) => format.money(value);
  const km = (value) => (value === null || value === undefined || value === "" ? "–" : `${format.number(value, 1)} km`);
  const activityClass = (activity) => `time-activity time-activity-${esc(activity)}`;
  const employeeQuery = () => (state.employee ? `&employee=${encodeURIComponent(state.employee)}` : "");

  function writeUrl() {
    const query = new URLSearchParams();
    if (state.view !== "week") query.set("view", state.view);
    if (state.date !== localToday()) query.set("date", state.date);
    if (state.employee) query.set("employee", state.employee);
    history.replaceState(null, "", `${location.pathname}${query.toString() ? `?${query}` : ""}`);
  }

  async function render() {
    writeUrl();
    root.querySelectorAll("[data-view]").forEach((button) => {
      const active = button.dataset.view === state.view;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-selected", String(active));
    });
    employeeSelect.hidden = !reference.canManage || state.view === "report";
    try {
      if (state.view === "day") await renderDay();
      else if (state.view === "report") await renderReport();
      else await renderRange();
    } catch (exception) {
      content.innerHTML = "";
      notify(message, exception.message);
    }
  }

  // ---------------------------------------------------------------------------
  // Woche und Monat
  // ---------------------------------------------------------------------------
  async function renderRange() {
    const [from, to] = state.view === "week" ? [weekStart(state.date), addDays(weekStart(state.date), 6)] : monthRange(state.date);
    root.querySelector("[data-period-label]").textContent = state.view === "week"
      ? `${format.date(from)} – ${format.date(to)}`
      : new Intl.DateTimeFormat("de-DE", { month: "long", year: "numeric", timeZone: "UTC" }).format(parse(from));
    const data = await api(`/api/time-tracking/range?from=${from}&to=${to}${employeeQuery()}`);
    const today = localToday();
    const rows = data.days.filter((day) => state.view === "week" || day.entryCount || day.privateKm || day.expenseTotal || day.dailyReportHours);
    const summary = `<div class="order-value-summary time-summary">
        <div><span>Arbeitszeit</span><strong>${hours(data.totals.workMinutes)}</strong></div>
        <div><span>Reisezeit</span><strong>${hours(data.totals.travelMinutes)}</strong></div>
        ${state.view === "month" ? `<div><span>Privat-Pkw</span><strong>${km(data.totals.privateKm)}</strong></div><div><span>Auslagen</span><strong>${money(data.totals.expenseTotal)}</strong></div>` : ""}
        <div class="total"><span>Gesamt ${state.view === "week" ? "Woche" : "Monat"}</span><strong>${hours(data.totals.netMinutes)}</strong></div>
      </div>`;
    content.innerHTML = `${summary}
      <div class="overview-table-wrap"><table class="overview-table time-table"><thead><tr>
        <th class="cell-date">Tag</th><th class="cell-money">Arbeit</th><th class="cell-money">Reise</th><th class="cell-money">Gesamt</th>
        <th class="cell-money time-col-optional">Privat-Pkw</th><th class="cell-money time-col-optional">Auslagen</th><th class="cell-money time-col-optional" title="Bautagesberichte (Referenz)">Bautagesbericht</th>
      </tr></thead><tbody>${rows.map((day) => `
        <tr tabindex="0" data-open-day="${day.date}" class="${day.date === today ? "time-today" : ""}${day.entryCount ? "" : " time-empty"}">
          <td class="cell-date">${weekday(day.date)} ${format.date(day.date)}</td>
          <td class="cell-money">${day.entryCount ? hours(day.workMinutes) : "–"}</td>
          <td class="cell-money">${day.travelMinutes ? hours(day.travelMinutes) : "–"}</td>
          <td class="cell-money"><strong>${day.entryCount ? hours(day.netMinutes) : '<span class="muted">keine Einträge</span>'}</strong></td>
          <td class="cell-money time-col-optional">${km(day.privateKm)}</td>
          <td class="cell-money time-col-optional">${day.expenseTotal ? money(day.expenseTotal) : "–"}</td>
          <td class="cell-money time-col-optional">${day.dailyReportHours ? `${format.number(day.dailyReportHours, 2)} h` : "–"}</td>
        </tr>`).join("") || '<tr><td colspan="7" class="muted">Keine Einträge in diesem Monat.</td></tr>'}</tbody></table></div>`;
  }

  // ---------------------------------------------------------------------------
  // Tag
  // ---------------------------------------------------------------------------
  async function renderDay() {
    root.querySelector("[data-period-label]").textContent = `${weekday(state.date)}, ${format.date(state.date)}`;
    current = await api(`/api/time-tracking/day?date=${state.date}${employeeQuery()}`);
    const day = current;
    const editable = day.canEdit;
    const details = day.details;
    const detailText = details ? [
      details.assignmentKind ? reference.assignments[details.assignmentKind] : "",
      details.travelDay !== "none" ? reference.travelDays[details.travelDay] : "",
      details.overnight ? "Übernachtung" : "",
      details.location,
      details.privateKm ? `Privat-Pkw ${km(details.privateKm)}` : "",
    ].filter(Boolean).map(esc).join(" · ") : "";
    content.innerHTML = `
      <div class="order-value-summary time-summary">
        <div><span>Arbeitszeit</span><strong>${hours(day.totals.workMinutes)}</strong></div>
        <div><span>davon Reise</span><strong>${hours(day.totals.travelMinutes)}</strong></div>
        <div class="total"><span>Tag gesamt</span><strong>${hours(day.totals.netMinutes)}</strong></div>
      </div>
      ${day.warnings.map((warning) => `<p class="notice notice-warning">${esc(warning)}</p>`).join("")}
      ${!day.employee.active ? '<p class="notice notice-warning">Dieser Mitarbeiter ist archiviert.</p>' : ""}
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Zeitblöcke</h2><small>${reference.canManage && state.employee ? esc(day.employee.name) : "Beginn, Ende, Pause und Tätigkeit"}</small></div>
          <div class="step-section-actions">${editable ? '<button class="button" type="button" data-add-entry data-icon="plus">Zeitblock</button>' : ""}</div>
        </div>
        ${day.entries.length ? `<ul class="time-blocks">${day.entries.map((entry) => `
          <li class="time-block">
            <span class="time-block-range">${esc(entry.start)}–${esc(entry.end)}${entry.endsNextDay ? ' <small>(+1 Tag)</small>' : ""}</span>
            <span class="time-block-main"><span class="${activityClass(entry.activity)}">${esc(entry.activityLabel)}</span>
              ${entry.orderNumber ? `<span class="time-block-order">${esc(entry.orderNumber)} · ${esc(entry.orderTitle || "")}</span>` : '<span class="muted">ohne Auftrag</span>'}
              ${entry.notes ? `<small class="muted">${esc(entry.notes)}</small>` : ""}
              ${entry.changedByOther ? `<small class="muted">geändert von ${esc(entry.updatedByName)} am ${format.dateTime(entry.updatedAt)}</small>` : ""}</span>
            <span class="time-block-net"><strong>${hours(entry.netMinutes)}</strong>${entry.breakMinutes ? `<small class="muted">${entry.breakMinutes} min Pause</small>` : ""}</span>
            ${editable ? `<span class="time-block-actions"><button class="icon-button" type="button" data-edit-entry="${esc(entry.id)}" data-icon="square-pen" title="Bearbeiten" aria-label="Bearbeiten"></button><button class="icon-button icon-button-danger" type="button" data-delete-entry="${esc(entry.id)}" data-icon="trash" title="Löschen" aria-label="Zeitblock löschen"></button></span>` : ""}
          </li>`).join("")}</ul>` : '<p class="empty-state">Noch keine Zeitblöcke an diesem Tag.</p>'}
      </section>
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Einsatz und Auslagen</h2><small>${detailText || "Einsatzart, Reise, Übernachtung, Kilometer und Auslagen (optional)"}</small></div>
          <div class="step-section-actions">${editable ? `<button class="button button-light button-small" type="button" data-details data-icon="square-pen">${details || day.expenses.length ? "Bearbeiten" : "Erfassen"}</button>` : ""}</div>
        </div>
        ${day.expenses.length ? `<ul class="time-expenses">${day.expenses.map((expense) => `<li><span>${esc(expense.purpose)}${expense.orderNumber ? ` <small class="muted">· ${esc(expense.orderNumber)}</small>` : ""}${expense.hasReceipt ? ` <a href="/api/time-tracking/expenses/${encodeURIComponent(expense.id)}/receipt" target="_blank" rel="noopener" data-icon="file-down">Beleg</a>` : ""}</span><strong>${money(expense.amount)}</strong></li>`).join("")}</ul><p class="time-expense-total">Auslagen gesamt: <strong>${money(day.expenseTotal)}</strong></p>` : ""}
      </section>
      ${renderReferences(day)}`;
  }

  function renderReferences(day) {
    if (!day.dailyReportTimes.length && !day.workReportTimes.length) return "";
    return `<section class="card form-stack time-references">
      <div class="step-section-heading"><div><h2>Zeiten aus anderen Modulen</h2><small>Nur zur Kontrolle – werden hier nicht addiert</small></div></div>
      <ul class="time-reference-list">
        ${day.dailyReportTimes.map((row) => `<li><a href="/daily-reports/${encodeURIComponent(row.reportId)}">Bautagesbericht · ${esc(row.orderNumber)}</a>
          <span>${format.number(row.hours, 2)} h</span>
          <small class="muted">${row.countsAsActual ? "zählt als Ist-Zeit, solange für diesen Auftrag keine Zeitblöcke erfasst sind" : "ersetzt durch die Zeitblöcke dieses Auftrags (keine Doppelzählung)"}</small></li>`).join("")}
        ${day.workReportTimes.map((row) => `<li><a href="/work-reports/${encodeURIComponent(row.reportId)}">Rapport ${esc(row.reportNumber || "Entwurf")}${row.orderNumber ? ` · ${esc(row.orderNumber)}` : ""}</a>
          <span>${format.number(row.hours, 2)} h</span>
          <small class="muted">abrechenbare Leistung, keine Ist-Zeit${row.dateTo ? ` · Zeitraum bis ${format.date(row.dateTo)}` : ""}</small></li>`).join("")}
      </ul></section>`;
  }

  // ---------------------------------------------------------------------------
  // Dialoge
  // ---------------------------------------------------------------------------
  const orderOptions = (selected = "") => '<option value="">Ohne Auftrag</option>' + reference.orders
    .map((order) => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.orderNumber)} · ${esc(order.title)}</option>`).join("")
    + (selected && !reference.orders.some((order) => order.id === selected) ? `<option value="${esc(selected)}" selected>bisheriger Auftrag</option>` : "");

  function entryDialog(entry = null, preset = {}) {
    const lastEnd = current.entries.length ? current.entries[current.entries.length - 1].end : "07:00";
    const values = { start: entry?.start ?? preset.start ?? lastEnd, end: entry?.end ?? "", breakMinutes: entry?.breakMinutes ?? 0, activity: entry?.activity ?? preset.activity ?? "work", orderId: entry?.orderId ?? preset.orderId ?? "", notes: entry?.notes ?? "" };
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <div class="time-entry-grid">
        <label><span class="field-label">Beginn <span aria-hidden="true">*</span></span><input name="start" type="time" required value="${esc(values.start)}"></label>
        <label><span class="field-label">Ende <span aria-hidden="true">*</span></span><input name="end" type="time" required value="${esc(values.end)}"></label>
        <label><span class="field-label">Pause (Min.)</span><input name="breakMinutes" type="number" min="0" step="5" inputmode="numeric" value="${esc(values.breakMinutes)}"></label>
      </div>
      <p class="muted" data-net></p>
      <fieldset class="time-activity-choice"><legend class="field-label">Tätigkeit <span aria-hidden="true">*</span></legend>
        ${Object.entries(reference.activities).map(([key, label]) => `<label class="${activityClass(key)}"><input type="radio" name="activity" value="${key}"${key === values.activity ? " checked" : ""}> ${esc(label)}</label>`).join("")}
      </fieldset>
      <label><span class="field-label">Auftrag (optional)</span><select name="orderId">${orderOptions(values.orderId)}</select></label>
      <label><span class="field-label">Bemerkung (optional)</span><input name="notes" maxlength="500" value="${esc(values.notes)}" placeholder="z. B. Verteilung montiert"></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    const net = () => {
      const start = form.elements.start.value;
      const end = form.elements.end.value;
      if (!start || !end || start === end) {
        form.querySelector("[data-net]").textContent = "";
        return;
      }
      let span = toMinutes(end) - toMinutes(start);
      if (span <= 0) span += 1440;
      const value = span - Number(form.elements.breakMinutes.value || 0);
      form.querySelector("[data-net]").textContent = `Nettozeit: ${value > 0 ? hours(value) : "–"}${toMinutes(end) <= toMinutes(start) ? " (endet am Folgetag)" : ""}`;
    };
    form.addEventListener("input", net);
    net();
    Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
    const body = () => ({
      date: state.date, employeeId: state.employee, start: form.elements.start.value, end: form.elements.end.value,
      breakMinutes: form.elements.breakMinutes.value, activity: form.elements.activity.value, orderId: form.elements.orderId.value, notes: form.elements.notes.value.trim(),
      ...(entry ? { version: entry.version } : {}),
    });
    const save = async () => {
      current = entry
        ? await api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "PUT", body: body() })
        : await api("/api/time-tracking/entries", { method: "POST", body: body() });
    };
    Calight.createDialog({
      title: entry ? "Zeitblock bearbeiten" : "Zeitblock erfassen",
      form,
      submitLabel: "Speichern",
      discardMessage: "Eingaben verwerfen?",
      initialFocus: entry ? '[name="start"]' : '[name="end"]',
      // Schnelle wiederholte Erfassung: nächster Block beginnt mit dem Ende des vorigen.
      extraActions: entry ? [] : [{
        label: "Speichern und weiter",
        onClick: async (run) => {
          const next = { start: form.elements.end.value, activity: form.elements.activity.value, orderId: form.elements.orderId.value };
          if (!form.reportValidity()) return false;
          if (await run(save)) {
            await renderDay();
            setTimeout(() => entryDialog(null, next), 0);
          }
          return false;
        },
      }],
      submit: async () => {
        await save();
        await renderDay();
      },
    });
  }

  function detailsDialog() {
    const details = current.details || { assignmentKind: null, overnight: false, travelDay: "none", location: "", notes: "", privateKm: null, privateKmPurpose: "", privateKmOrderId: null };
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <fieldset class="time-choice"><legend class="field-label">Einsatzart</legend>
        <label class="check-inline"><input type="radio" name="assignmentKind" value=""${!details.assignmentKind ? " checked" : ""}> Keine Angabe</label>
        ${Object.entries(reference.assignments).map(([key, label]) => `<label class="check-inline"><input type="radio" name="assignmentKind" value="${key}"${details.assignmentKind === key ? " checked" : ""}> ${esc(label)}</label>`).join("")}
      </fieldset>
      <div class="dialog-form-grid">
        <label><span class="field-label">Reisetag</span><select name="travelDay">${Object.entries(reference.travelDays).map(([key, label]) => `<option value="${key}"${details.travelDay === key ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        <label class="check-inline time-overnight"><input type="checkbox" name="overnight"${details.overnight ? " checked" : ""}> Übernachtung</label>
        <label class="span-all"><span class="field-label">Einsatzort / Reiseziel</span><input name="location" maxlength="200" value="${esc(details.location)}"></label>
        <label><span class="field-label">Privat-Pkw (km)</span><input name="privateKm" inputmode="decimal" value="${esc(details.privateKm ? String(details.privateKm).replace(".", ",") : "")}" placeholder="0"></label>
        <label><span class="field-label">Fahrt für Auftrag (optional)</span><select name="privateKmOrderId">${orderOptions(details.privateKmOrderId || "")}</select></label>
        <label class="span-all"><span class="field-label">Fahrtzweck (optional)</span><input name="privateKmPurpose" maxlength="200" value="${esc(details.privateKmPurpose)}"></label>
        <label class="span-all"><span class="field-label">Bemerkung</span><textarea name="notes" rows="2" maxlength="1000">${esc(details.notes)}</textarea></label>
      </div>
      <div class="time-expense-editor">
        <div class="purchase-items-toolbar"><h3 class="purchase-subheading">Auslagen</h3><button class="button button-light button-small" type="button" data-add-expense data-icon="plus">Auslage</button></div>
        <div data-expense-list></div>
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    const drawExpenses = () => {
      form.querySelector("[data-expense-list]").innerHTML = current.expenses.length
        ? `<ul class="time-expenses">${current.expenses.map((expense) => `<li><span>${esc(expense.purpose)}${expense.receiptName ? ` <small class="muted">· Beleg</small>` : ""}</span><strong>${money(expense.amount)}</strong>
            <button class="icon-button icon-button-danger" type="button" data-remove-expense="${esc(expense.id)}" data-icon="trash" title="Auslage entfernen" aria-label="Auslage entfernen"></button></li>`).join("")}</ul>`
        : '<p class="muted">Keine Auslagen.</p>';
    };
    drawExpenses();
    form.addEventListener("click", async (event) => {
      if (event.target.closest("[data-add-expense]")) {
        expenseDialog(drawExpenses);
        return;
      }
      const remove = event.target.closest("[data-remove-expense]");
      if (!remove) return;
      try {
        current = await api(`/api/time-tracking/expenses/${encodeURIComponent(remove.dataset.removeExpense)}`, { method: "DELETE", body: {} });
        drawExpenses();
      } catch (exception) {
        notify(message, exception.message);
      }
    });
    Calight.createDialog({
      title: `Einsatz und Auslagen · ${format.date(state.date)}`,
      form,
      submitLabel: "Speichern",
      discardMessage: "Eingaben verwerfen?",
      submit: async () => {
        current = await api("/api/time-tracking/details", { method: "PUT", body: {
          date: state.date, employeeId: state.employee, assignmentKind: form.elements.assignmentKind.value, travelDay: form.elements.travelDay.value,
          overnight: form.elements.overnight.checked, location: form.elements.location.value.trim(), notes: form.elements.notes.value.trim(),
          privateKm: form.elements.privateKm.value.trim().replace(",", "."), privateKmPurpose: form.elements.privateKmPurpose.value.trim(), privateKmOrderId: form.elements.privateKmOrderId.value,
        } });
        await renderDay();
      },
    });
  }

  function expenseDialog(onSaved) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<div class="dialog-form-grid">
        <label><span class="field-label">Betrag <span aria-hidden="true">*</span></span><input name="amount" inputmode="decimal" required placeholder="0,00"></label>
        <label><span class="field-label">Zweck <span aria-hidden="true">*</span></span><input name="purpose" maxlength="200" required placeholder="z. B. Parkgebühr, Hotel, Maut"></label>
        <label class="span-all"><span class="field-label">Auftrag (optional)</span><select name="orderId">${orderOptions("")}</select></label>
        <label class="span-all"><span class="field-label">Beleg als PDF oder Bild (optional)</span><input name="file" type="file" accept="application/pdf,image/*"></label>
      </div><p class="field-error" data-expense-error hidden></p>`;
    const error = form.querySelector("[data-expense-error]");
    CalightModal.openOver({
      title: "Auslage erfassen",
      content: form,
      initialFocus: '[name="amount"]',
      actions: [
        { label: "Abbrechen", value: null },
        {
          label: "Hinzufügen", primary: true, validate: true,
          onClick: async () => {
            const data = new FormData();
            data.append("date", state.date);
            data.append("employeeId", state.employee);
            data.append("amount", form.elements.amount.value.trim().replace(",", "."));
            data.append("purpose", form.elements.purpose.value.trim());
            data.append("orderId", form.elements.orderId.value);
            if (form.elements.file.files[0]) data.append("file", form.elements.file.files[0]);
            const response = await fetch("/api/time-tracking/expenses", { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf, Accept: "application/json" }, body: data });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
              error.textContent = (Array.isArray(result.details) ? result.details.join(" ") : "") || result.error || `Speichern fehlgeschlagen (HTTP ${response.status}).`;
              error.hidden = false;
              return false;
            }
            current = result.data;
            onSaved();
            renderDay();
            return true;
          },
        },
      ],
    });
  }

  // ---------------------------------------------------------------------------
  // Auswertung (Büro)
  // ---------------------------------------------------------------------------
  async function renderReport() {
    const [defaultFrom, defaultTo] = monthRange(state.date);
    root.querySelector("[data-period-label]").textContent = new Intl.DateTimeFormat("de-DE", { month: "long", year: "numeric", timeZone: "UTC" }).format(parse(defaultFrom));
    const employee = state.employee;
    const order = new URLSearchParams(location.search).get("order") || "";
    const data = await api(`/api/time-tracking/report?from=${defaultFrom}&to=${defaultTo}${employee ? `&employee=${encodeURIComponent(employee)}` : ""}${order ? `&order=${encodeURIComponent(order)}` : ""}`);
    content.innerHTML = `
      <div class="overview-toolbar">
        <select data-report-employee aria-label="Mitarbeiter"><option value="">Alle Mitarbeiter</option>${reference.employees.map((item) => `<option value="${esc(item.id)}"${item.id === employee ? " selected" : ""}>${esc(item.name)}${item.active ? "" : " (archiviert)"}</option>`).join("")}</select>
        <select data-report-order aria-label="Auftrag"><option value="">Alle Aufträge</option>${reference.orders.map((item) => `<option value="${esc(item.id)}"${item.id === order ? " selected" : ""}>${esc(item.orderNumber)} · ${esc(item.title)}</option>`).join("")}</select>
      </div>
      <div class="order-value-summary time-summary">
        <div><span>Arbeitszeit</span><strong>${hours(data.totals.workMinutes)}</strong></div>
        <div><span>Reisezeit</span><strong>${hours(data.totals.travelMinutes)}</strong></div>
        <div class="total"><span>Gesamt</span><strong>${hours(data.totals.netMinutes)}</strong></div>
      </div>
      <h3 class="purchase-subheading">Nach Auftrag</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-title">Auftrag</th><th class="cell-money">Arbeit</th><th class="cell-money">Reise</th></tr></thead>
        <tbody>${data.byOrder.map((row) => `<tr><td class="cell-title">${row.orderId ? `${esc(row.orderNumber)} · ${esc(row.orderTitle)}` : '<span class="muted">Ohne Auftrag</span>'}</td><td class="cell-money">${hours(row.workMinutes)}</td><td class="cell-money">${hours(row.travelMinutes)}</td></tr>`).join("") || '<tr><td colspan="3" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>
      <h3 class="purchase-subheading">Zeitblöcke</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-date">Datum</th><th class="cell-text">Mitarbeiter</th><th class="cell-text">Zeit</th><th class="cell-type">Tätigkeit</th><th class="cell-number">Auftrag</th><th class="cell-money">Netto</th></tr></thead>
        <tbody>${data.rows.map((row) => `<tr><td class="cell-date">${format.date(row.date)}</td><td class="cell-text">${esc(row.employeeName)}</td><td class="cell-text">${esc(row.start)}–${esc(row.end)}${row.endsNextDay ? " (+1)" : ""}</td>
          <td class="cell-type"><span class="${activityClass(row.activity)}">${esc(row.activityLabel)}</span></td><td class="cell-number">${esc(row.orderNumber || "–")}</td><td class="cell-money">${hours(row.netMinutes)}</td></tr>`).join("") || '<tr><td colspan="6" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>`;
    content.querySelector("[data-report-employee]").addEventListener("change", (event) => {
      state.employee = event.target.value;
      render();
    });
    content.querySelector("[data-report-order]").addEventListener("change", (event) => {
      const query = new URLSearchParams(location.search);
      if (event.target.value) query.set("order", event.target.value);
      else query.delete("order");
      history.replaceState(null, "", `${location.pathname}?${query}`);
      renderReport().catch((exception) => notify(message, exception.message));
    });
  }

  // ---------------------------------------------------------------------------
  // Bedienung
  // ---------------------------------------------------------------------------
  root.addEventListener("click", async (event) => {
    const view = event.target.closest("[data-view]");
    if (view) {
      state.view = view.dataset.view;
      return render();
    }
    const step = event.target.closest("[data-step]");
    if (step) {
      const direction = Number(step.dataset.step);
      state.date = state.view === "day" ? addDays(state.date, direction) : state.view === "week" ? addDays(state.date, 7 * direction) : addMonths(state.date, direction);
      return render();
    }
    if (event.target.closest("[data-today]")) {
      state.date = localToday();
      return render();
    }
    const dayRow = event.target.closest("[data-open-day]");
    if (dayRow) {
      state.date = dayRow.dataset.openDay;
      state.view = "day";
      return render();
    }
    if (event.target.closest("[data-add-entry]")) return entryDialog();
    if (event.target.closest("[data-details]")) return detailsDialog();
    const edit = event.target.closest("[data-edit-entry]");
    if (edit) return entryDialog(current.entries.find((entry) => entry.id === edit.dataset.editEntry));
    const remove = event.target.closest("[data-delete-entry]");
    if (remove) {
      const entry = current.entries.find((item) => item.id === remove.dataset.deleteEntry);
      if (!(await CalightModal.confirm({ title: "Zeitblock löschen", content: `${entry.start}–${entry.end} (${entry.activityLabel}) wird gelöscht.`, confirmLabel: "Löschen", danger: true }))) return;
      try {
        current = await api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "DELETE", body: { version: entry.version } });
        await renderDay();
      } catch (exception) {
        notify(message, exception.message);
      }
    }
  });
  root.addEventListener("keydown", (event) => {
    const row = event.target.closest?.("[data-open-day]");
    if (!row || event.target !== row || !["Enter", " "].includes(event.key)) return;
    event.preventDefault();
    row.click();
  });
  employeeSelect.addEventListener("change", () => {
    state.employee = employeeSelect.value;
    render();
  });

  api("/api/time-tracking/reference-data")
    .then((data) => {
      reference = data;
      if (reference.canManage) {
        employeeSelect.innerHTML = (reference.ownEmployeeId ? "" : '<option value="">Mitarbeiter wählen …</option>')
          + reference.employees.map((item) => `<option value="${esc(item.id)}">${esc(item.name)}${item.active ? "" : " (archiviert)"}</option>`).join("");
        if (!state.employee) state.employee = reference.ownEmployeeId ? "" : (reference.employees.find((item) => item.active)?.id || "");
        employeeSelect.value = state.employee || reference.ownEmployeeId || "";
        root.querySelector('[data-view="report"]').hidden = false;
      }
      return render();
    })
    .catch((exception) => notify(message, exception.message));
})();
