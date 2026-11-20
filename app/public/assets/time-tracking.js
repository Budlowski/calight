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

  /** ISO-Kalenderwoche (Woche mit dem Donnerstag bestimmt das Jahr). */
  const isoWeek = (value) => {
    const date = parse(value);
    const thursday = new Date(date);
    thursday.setUTCDate(date.getUTCDate() + 3 - ((date.getUTCDay() + 6) % 7));
    const firstThursday = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 4));
    firstThursday.setUTCDate(firstThursday.getUTCDate() + 3 - ((firstThursday.getUTCDay() + 6) % 7));
    return { week: 1 + Math.round((thursday - firstThursday) / 604800000), year: thursday.getUTCFullYear() };
  };
  /** Monatsraster: Wochen (Mo–So) mit allen Tagen, auch außerhalb des Monats; 4–6 Zeilen. */
  const monthGrid = (value) => {
    const [first, last] = monthRange(value);
    const weeks = [];
    for (let start = weekStart(first); start <= last; start = addDays(start, 7)) {
      weeks.push(Array.from({ length: 7 }, (_, index) => addDays(start, index)));
    }
    return weeks;
  };
  /** Reisezeit kurz für Kalenderzellen („R 1:30“). */
  const travelShort = (minutes) => (minutes ? `R ${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")}` : "");

  /**
   * Nettozeit aus Beginn, Ende und Pause (Ende ≤ Beginn = Folgetag). Bei vorgegebener Dauer
   * (Berichtsbuchung) muss die Nettozeit exakt passen. Ohne Uhrzeiten: null.
   */
  const netFromTimes = (start, end, breakMinutes = 0, fixed = null) => {
    if (!start || !end || start === end) return null;
    let span = toMinutes(end) - toMinutes(start);
    const nextDay = span <= 0;
    if (nextDay) span += 1440;
    const net = span - Number(breakMinutes || 0);
    return { net, nextDay, valid: net > 0 && (fixed === null || net === fixed) };
  };

  /** Kennzeichen „ohne Uhrzeit“: neutral, bei deaktivierter Betriebseinstellung als unvollständig. */
  const untimedLabel = (missing) => `<span class="time-untimed${missing ? " is-incomplete" : ""}"${missing ? ' title="Uhrzeiten sind laut Betriebseinstellung zu erfassen"' : ""}>Ohne Uhrzeit</span>`;

  /**
   * Eine Zeile des Stundenzettels: Uhrzeit · Tätigkeit/Beleg · Auftrag/Kunde · Herkunft · Stunden · Aktion.
   * Reine Darstellung der vom Server gelieferten Felder (auch für Tests exportiert).
   */
  const entryRow = (entry) => {
    const manual = entry.sourceKind === "manual";
    const context = entry.orderNumber ? `${entry.orderNumber}${entry.orderTitle ? ` · ${entry.orderTitle}` : ""}` : entry.customerName || "";
    const title = manual
      ? esc(entry.notes || entry.activityLabel)
      : `<a href="${esc(entry.reportUrl)}">${esc(entry.reportLabel)}</a>${entry.reportDraft ? ' <span class="time-meta-note">Entwurf</span>' : ""}`;
    const meta = [
      `<span class="time-source">${esc(entry.sourceLabel)}</span>`,
      manual && entry.notes ? esc(entry.activityLabel) : "",
      !manual && entry.reportActivity ? esc(entry.reportActivity) : "",
      context ? `<span class="time-context" title="${esc(context)}">${esc(context)}</span>` : manual ? "Ohne Auftrag" : "",
      entry.assignmentKind ? `<span title="${esc(entry.assignmentLabel)}">${entry.assignmentKind === "external" ? "Auswärts" : "Erste Tätigkeitsstätte"}</span>` : "",
    ].filter(Boolean).join('<span aria-hidden="true"> · </span>');
    const editLabel = entry.durationFixed ? (entry.hasTimes ? "Uhrzeiten bearbeiten" : "Uhrzeiten ergänzen") : "Bearbeiten";
    return `<li class="time-block${entry.hasTimes ? "" : " is-untimed"}">
      <span class="time-block-range">${entry.hasTimes ? `${esc(entry.start)}–${esc(entry.end)}${entry.endsNextDay ? '<small> +1 Tag</small>' : ""}` : untimedLabel(entry.timesMissing)}</span>
      <span class="time-block-main"><span class="time-block-title" title="${esc(manual ? entry.notes || entry.activityLabel : entry.reportLabel)}">${title}</span><span class="time-block-meta" title="${esc([entry.sourceLabel, entry.reportActivity, context].filter(Boolean).join(" · "))}">${meta}</span></span>
      <span class="time-block-net"><strong>${hours(entry.netMinutes)}</strong>${entry.travelMinutes ? `<small>davon ${hours(entry.travelMinutes)} Reise</small>` : ""}${entry.breakMinutes ? `<small>${entry.breakMinutes} min Pause</small>` : ""}</span>
      <span class="time-block-actions">
        ${entry.canEditTimes ? `<button class="icon-button" type="button" data-edit-entry="${esc(entry.id)}" data-icon="${entry.durationFixed && !entry.hasTimes ? "clock-3" : "square-pen"}" title="${editLabel}" aria-label="${editLabel}"></button>` : ""}
        ${entry.canDelete ? `<button class="icon-button icon-button-danger" type="button" data-delete-entry="${esc(entry.id)}" data-icon="trash" title="Löschen" aria-label="Zeitblock löschen"></button>` : ""}
      </span>
    </li>`;
  };

  globalThis.CalightTimeTracking = Object.freeze({ addDays, weekStart, monthRange, addMonths, hours, weekday, toMinutes, toTime, isoWeek, monthGrid, travelShort, netFromTimes, entryRow });

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
    employeeSelect.closest("[data-employee-field]").hidden = !reference.canManage || state.view === "report";
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
  // Einheitliche Summenbegriffe: Nettozeit gesamt, davon Reisezeit, übrige Arbeitszeit.
  /**
   * Tageszusammenfassung (Stundenzettel): Arbeitszeit, davon Reisezeit, ohne Uhrzeit. Einheitlich
   * zurückhaltend; „ohne Uhrzeit“ ist Information und nur bei deaktivierter Betriebseinstellung
   * als unvollständig markiert. Herkunft dezent in einer Zeile darunter.
   */
  const summaryTiles = (totals, extra = "") => {
    const untimed = totals.untimedMinutes || 0;
    const incomplete = Boolean(totals.incompleteMinutes);
    return `<div class="time-summary">
      <div><span>Arbeitszeit</span><strong>${hours(totals.netMinutes)}</strong></div>
      <div><span>Davon Reisezeit</span><strong>${hours(totals.travelMinutes)}</strong></div>
      <div class="${incomplete ? "is-incomplete" : ""}"><span>Ohne Uhrzeit${incomplete ? " · unvollständig" : ""}</span><strong>${hours(untimed)}</strong></div>${extra}
    </div>${sourceLine(totals)}`;
  };
  /** Herkunft der Summe (Rapport, Bautagesbericht, manuell) als dezente Zeile. */
  const sourceLine = (totals) => {
    if (!totals.bySource) return "";
    const parts = Object.entries(reference.sourceKinds).map(([kind, label]) => (totals.bySource[kind] ? `${label} ${hours(totals.bySource[kind])}` : "")).filter(Boolean);
    return parts.length > 1 ? `<p class="time-source-line">${parts.join(" · ")}</p>` : "";
  };
  const calendarCell = (day, { outside = false, showWeek = false } = {}) => {
    const today = day.date === localToday();
    const week = showWeek ? `<span class="time-cal-week">KW ${isoWeek(day.date).week}</span>` : "";
    const selected = day.date === state.date;
    const untimed = day.untimedMinutes ? `, davon ${hours(day.untimedMinutes)} ohne Uhrzeit` : "";
    return `<button type="button" class="time-cal-day${today ? " is-today" : ""}${selected ? " is-selected" : ""}${outside ? " is-outside" : ""}${day.netMinutes ? " has-time" : ""}" data-open-day="${day.date}"
        ${selected ? 'aria-current="date" ' : ""}aria-label="${weekday(day.date)} ${format.date(day.date)}: ${day.netMinutes ? hours(day.netMinutes) + untimed : "keine Einträge"}">
        <span class="time-cal-head"><span class="time-cal-weekday">${weekday(day.date)}</span><span class="time-cal-date">${Number(day.date.slice(8, 10))}.${showWeek || state.view === "week" ? `${Number(day.date.slice(5, 7))}.` : ""}</span>${week}</span>
        <strong class="time-cal-hours">${day.netMinutes ? hours(day.netMinutes).replace(" h", "") : "–"}</strong>
        <small class="time-cal-travel">${travelShort(day.travelMinutes)}</small>
        <span class="time-cal-flags" aria-hidden="true">${day.untimedMinutes ? `<span class="time-cal-flag${day.incompleteMinutes ? " is-incomplete" : ""}" title="${hours(day.untimedMinutes)} ohne Uhrzeit">◷</span>` : ""}${day.privateKm || day.expenseTotal ? '<span class="time-cal-flag" title="Kilometer oder Auslagen erfasst">•</span>' : ""}</span>
      </button>`;
  };

  async function renderRange() {
    const week = state.view === "week";
    const grid = week ? [] : monthGrid(state.date);
    const [from, to] = week ? [weekStart(state.date), addDays(weekStart(state.date), 6)] : [grid[0][0], grid[grid.length - 1][6]];
    const [monthFrom, monthTo] = monthRange(state.date);
    const iso = isoWeek(weekStart(state.date));
    root.querySelector("[data-period-label]").textContent = week
      ? `KW ${iso.week} · ${format.date(from)} – ${format.date(to)}`
      : new Intl.DateTimeFormat("de-DE", { month: "long", year: "numeric", timeZone: "UTC" }).format(parse(monthFrom));
    // Monatssummen exakt vom Server über die Tage des Monats (Randtage gehören zum Nachbarmonat).
    const [data, monthData] = await Promise.all([
      api(`/api/time-tracking/range?from=${from}&to=${to}${employeeQuery()}`),
      week ? null : api(`/api/time-tracking/range?from=${monthFrom}&to=${monthTo}${employeeQuery()}`),
    ]);
    const byDate = Object.fromEntries(data.days.map((day) => [day.date, day]));
    const inMonth = (date) => date >= monthFrom && date <= monthTo;
    const totals = week ? data.totals : monthData.totals;
    const head = '<div class="time-cal-weekdays" aria-hidden="true">' + ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"].map((name) => `<span>${name}</span>`).join("") + "</div>";
    const body = week
      ? `<div class="time-cal-row">${data.days.map((day) => calendarCell(day)).join("")}</div>`
      : grid.map((days) => `<div class="time-cal-row">${days.map((date, index) => calendarCell(byDate[date] || { date, netMinutes: 0, travelMinutes: 0 }, { outside: !inMonth(date), showWeek: index === 0 })).join("")}</div>`).join("");
    content.innerHTML = `${summaryTiles(totals, week ? "" : `<div><span>Privat-Pkw</span><strong>${km(totals.privateKm)}</strong></div><div><span>Auslagen</span><strong>${money(totals.expenseTotal)}</strong></div>`)}
      <div class="time-calendar ${week ? "is-week" : "is-month"}" role="group" aria-label="${week ? "Wochenkalender" : "Monatskalender"}">${head}${body}</div>`;
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
      details.absenceStart || details.absenceEnd ? `Abwesenheit ${details.absenceStart || "?"}–${details.absenceEnd || "?"}` : "",
      details.travelDay !== "none" ? reference.travelDays[details.travelDay] : "",
      details.overnight ? "Übernachtung" : "",
      details.location,
      details.privateKm ? `Privat-Pkw ${km(details.privateKm)}` : "",
    ].filter(Boolean).map(esc).join(" · ") : "";
    content.innerHTML = `
      ${summaryTiles(day.totals)}
      ${day.warnings.map((warning) => `<p class="time-hint" role="note"><span aria-hidden="true">⚠</span> ${esc(warning)}</p>`).join("")}
      ${!day.employee.active ? '<p class="notice notice-warning">Dieser Mitarbeiter ist archiviert.</p>' : ""}
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Arbeitszeiten</h2>${reference.canManage && state.employee ? `<small>${esc(day.employee.name)}</small>` : ""}</div>
          <div class="step-section-actions">${editable ? '<button class="button" type="button" data-add-entry data-icon="plus">Zeitblock</button>' : ""}</div>
        </div>
        ${day.entries.length ? `<ul class="time-blocks">${day.entries.map(entryRow).join("")}</ul>` : '<p class="empty-state">Für diesen Tag sind noch keine Arbeitszeiten erfasst.</p>'}
        ${day.carriedOver.map((block) => `<p class="time-carried">Fortsetzung vom Vortag (${esc(block.sourceLabel)}) ${esc(block.start)}–${esc(block.end)}: anteilig ${hours(block.netMinutes)}${block.travelMinutes ? `, davon ${hours(block.travelMinutes)} Reise` : ""} (in den Summen enthalten)</p>`).join("")}
      </section>
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Einsatz und Auslagen</h2><small>${detailText || "Einsatzart, Reise, Übernachtung, Kilometer und Auslagen (optional)"}</small></div>
          <div class="step-section-actions">${editable ? `<button class="button button-light button-small" type="button" data-details data-icon="square-pen">${details || day.expenses.length ? "Bearbeiten" : "Erfassen"}</button>` : ""}</div>
        </div>
        ${day.expenses.length ? `<ul class="time-expenses">${day.expenses.map((expense) => `<li><span>${esc(expense.purpose)}${expense.orderNumber ? ` <small class="muted">· ${esc(expense.orderNumber)}</small>` : ""}${expense.hasReceipt ? ` <a href="/api/time-tracking/expenses/${encodeURIComponent(expense.id)}/receipt" target="_blank" rel="noopener" data-icon="file-down">Beleg</a>` : ""}</span><strong>${money(expense.amount)}</strong></li>`).join("")}</ul><p class="time-expense-total">Auslagen gesamt: <strong>${money(day.expenseTotal)}</strong></p>` : ""}
      </section>`;
  }

  // ---------------------------------------------------------------------------
  // Dialoge
  // ---------------------------------------------------------------------------
  const orderOptions = (selected = "") => '<option value="">Ohne Auftrag</option>' + reference.orders
    .map((order) => `<option value="${esc(order.id)}"${order.id === selected ? " selected" : ""}>${esc(order.orderNumber)} · ${esc(order.title)}</option>`).join("")
    + (selected && !reference.orders.some((order) => order.id === selected) ? `<option value="${esc(selected)}" selected>bisheriger Auftrag</option>` : "");

  /** Dauer als „7:30“ bzw. „7,5“ → Minuten (für Buchungen ohne Uhrzeiten). */
  const parseDuration = (value) => {
    const text = String(value || "").trim().replace(",", ".");
    if (/^\d{1,2}:\d{2}$/.test(text)) return Number(text.split(":")[0]) * 60 + Number(text.split(":")[1]);
    return /^\d+(\.\d+)?$/.test(text) ? Math.round(Number(text) * 60) : NaN;
  };

  /**
   * Manuelle Buchung erfassen/bearbeiten oder bei Berichtsbuchungen nur die zeitliche Einordnung
   * ergänzen (Dauer laut Bericht, unveränderlich). Uhrzeiten sind optional, wenn die
   * Betriebseinstellung es erlaubt.
   */
  function entryDialog(entry = null, preset = {}) {
    const fixed = entry?.durationFixed ? entry.netMinutes : null;
    const timed = current.entries.filter((item) => item.hasTimes);
    const lastEnd = timed.length ? timed[timed.length - 1].end : "07:00";
    // Einsatzart des vorigen Blocks vorschlagen (jederzeit änderbar).
    const lastAssignment = current.entries.length ? current.entries[current.entries.length - 1].assignmentKind || "" : "";
    const values = {
      start: entry ? entry.start || "" : preset.start ?? (fixed === null ? lastEnd : ""), end: entry?.end ?? "", breakMinutes: entry?.breakMinutes ?? 0, travelMinutes: entry?.travelMinutes ?? 0,
      activity: entry?.activity ?? preset.activity ?? "work", assignmentKind: entry ? entry.assignmentKind || "" : preset.assignmentKind ?? lastAssignment,
      orderId: entry?.orderId ?? preset.orderId ?? "", notes: entry?.notes ?? "",
      duration: entry && !entry.hasTimes && fixed === null ? hours(entry.netMinutes).replace(" h", "") : "",
    };
    const timesRequired = !current.timesOptional && fixed === null;
    // Bestandsblöcke „Fahrt“ behalten ihre Tätigkeit; neu wird sie nicht angeboten.
    const activities = values.activity === "travel" ? { ...reference.activities, travel: reference.activityLabels.travel } : reference.activities;
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      ${entry?.changedByOther ? `<p class="time-dialog-meta">Zuletzt geändert von ${esc(entry.updatedByName)} am ${format.dateTime(entry.updatedAt)}</p>` : ""}
      ${fixed !== null ? `<p class="notice">${esc(entry.reportLabel)}: <strong>${hours(fixed)}</strong> laut Bericht. Die Dauer wird im Bericht geändert; hier werden nur Uhrzeiten, Pause, Reisezeit und Einsatzart ergänzt. Ende − Beginn − Pause muss genau ${hours(fixed)} ergeben.</p>` : ""}
      <div class="time-entry-grid">
        <label><span class="field-label">Beginn${timesRequired ? ' <span aria-hidden="true">*</span>' : " (optional)"}</span><input name="start" type="time"${timesRequired ? " required" : ""} value="${esc(values.start)}"></label>
        <label><span class="field-label">Ende${timesRequired ? ' <span aria-hidden="true">*</span>' : " (optional)"}</span><input name="end" type="time"${timesRequired ? " required" : ""} value="${esc(values.end)}"></label>
        <label><span class="field-label">Pause (Min.)</span><input name="breakMinutes" type="number" min="0" step="5" inputmode="numeric" value="${esc(values.breakMinutes)}"></label>
        <label><span class="field-label">Davon Reisezeit (Min.)</span><input name="travelMinutes" type="number" min="0" step="5" inputmode="numeric" value="${esc(values.travelMinutes)}"></label>
        ${fixed === null && !timesRequired ? `<label data-duration-field><span class="field-label">Dauer ohne Uhrzeiten</span><input name="duration" inputmode="decimal" placeholder="z. B. 1:30 oder 1,5" value="${esc(values.duration)}"></label>` : ""}
      </div>
      <p class="muted" data-net></p>
      ${fixed === null ? `<fieldset class="time-activity-choice"><legend class="field-label">Tätigkeit <span aria-hidden="true">*</span></legend>
        ${Object.entries(activities).map(([key, label]) => `<label class="${activityClass(key)}"><input type="radio" name="activity" value="${key}"${key === values.activity ? " checked" : ""}> ${esc(label)}</label>`).join("")}
      </fieldset>` : ""}
      <label><span class="field-label">Einsatzart (optional)</span><select name="assignmentKind"><option value="">Keine Angabe</option>${Object.entries(reference.assignments).map(([key, label]) => `<option value="${key}"${key === values.assignmentKind ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
      ${fixed === null ? `<label><span class="field-label">Auftrag (optional)</span><select name="orderId">${orderOptions(values.orderId)}</select></label>` : ""}
      <label><span class="field-label">Bemerkung (optional)</span><input name="notes" maxlength="500" value="${esc(values.notes)}" placeholder="${fixed === null ? "z. B. Angebotsbearbeitung" : "Ergänzung zur Zeiterfassung"}"></label>
      <p class="field-error" data-dialog-error hidden></p>`;
    const net = () => {
      const result = netFromTimes(form.elements.start.value, form.elements.end.value, form.elements.breakMinutes.value, fixed);
      const travel = Number(form.elements.travelMinutes.value || 0);
      const durationField = form.querySelector("[data-duration-field]");
      if (durationField) durationField.hidden = Boolean(form.elements.start.value || form.elements.end.value);
      const base = result ? result.net : fixed ?? parseDuration(form.elements.duration?.value);
      form.querySelector("[data-net]").textContent = !result && Number.isNaN(base)
        ? ""
        : result && result.net <= 0
          ? "Nettozeit: –"
          : `Nettozeit ${hours(base)} · davon Reisezeit ${hours(Math.max(0, travel))} · übrige Arbeitszeit ${travel <= base ? hours(base - Math.max(0, travel)) : "–"}${result?.nextDay ? " (endet am Folgetag)" : ""}${travel > base ? " – Reisezeit größer als Nettozeit" : ""}${result && fixed !== null && result.net !== fixed ? ` – passt nicht zu ${hours(fixed)} laut Bericht` : ""}`;
    };
    form.addEventListener("input", net);
    net();
    if (form.elements.orderId) Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });
    const body = () => {
      const timesGiven = Boolean(form.elements.start.value || form.elements.end.value);
      const duration = form.elements.duration && !timesGiven ? parseDuration(form.elements.duration.value) : NaN;
      return {
        date: state.date, employeeId: state.employee, start: form.elements.start.value, end: form.elements.end.value,
        breakMinutes: timesGiven ? form.elements.breakMinutes.value : 0, travelMinutes: form.elements.travelMinutes.value, assignmentKind: form.elements.assignmentKind.value,
        activity: form.elements.activity?.value ?? entry?.activity, orderId: form.elements.orderId?.value ?? "", notes: form.elements.notes.value.trim(),
        ...(Number.isNaN(duration) ? {} : { durationMinutes: duration }),
        ...(entry ? { version: entry.version } : {}),
      };
    };
    const save = async () => {
      current = entry
        ? await api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "PUT", body: body() })
        : await api("/api/time-tracking/entries", { method: "POST", body: body() });
    };
    Calight.createDialog({
      title: fixed !== null ? "Uhrzeiten ergänzen" : entry ? "Zeitblock bearbeiten" : "Zeitblock erfassen",
      form,
      submitLabel: "Speichern",
      discardMessage: "Eingaben verwerfen?",
      initialFocus: '[name="start"]',
      // Schnelle wiederholte Erfassung: nächster Block beginnt mit dem Ende des vorigen.
      extraActions: entry ? [] : [{
        label: "Speichern und weiter",
        onClick: async (run) => {
          const next = { start: form.elements.end.value, activity: form.elements.activity.value, orderId: form.elements.orderId.value, assignmentKind: form.elements.assignmentKind.value };
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
    const details = current.details || { overnight: false, travelDay: "none", location: "", notes: "", absenceStart: null, absenceEnd: null, privateKm: null, privateKmPurpose: "", privateKmOrderId: null };
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <div class="dialog-form-grid">
        <label><span class="field-label">Reisetag</span><select name="travelDay">${Object.entries(reference.travelDays).map(([key, label]) => `<option value="${key}"${details.travelDay === key ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        <label class="check-inline time-overnight"><input type="checkbox" name="overnight"${details.overnight ? " checked" : ""}> Übernachtung</label>
        <label class="span-all"><span class="field-label">Einsatzort / Reiseziel</span><input name="location" maxlength="200" value="${esc(details.location)}"></label>
        <label><span class="field-label">Beginn der Abwesenheit</span><input name="absenceStart" type="time" value="${esc(details.absenceStart || "")}"></label>
        <label><span class="field-label">Ende der Abwesenheit</span><input name="absenceEnd" type="time" value="${esc(details.absenceEnd || "")}"></label>
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
          date: state.date, employeeId: state.employee, absenceStart: form.elements.absenceStart.value, absenceEnd: form.elements.absenceEnd.value, travelDay: form.elements.travelDay.value,
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
  /** Anteil im Zeitraum; bei Blöcken über die Zeitraumgrenze zusätzlich der ganze Block. */
  function periodHours(period, total) {
    return period === total ? hours(total) : `${hours(period)} <small class="muted" title="Block über Mitternacht an der Zeitraumgrenze">von ${hours(total)}</small>`;
  }

  /** Herkunft der Stunden je Auftrag und fehlende Uhrzeiten. */
  function orderSourceNote(row) {
    const notes = Object.entries(reference.sourceKinds).map(([kind, label]) => (row.bySource[kind] ? `<span class="muted">${label} ${hours(row.bySource[kind])}</span>` : "")).filter(Boolean);
    if (row.incompleteMinutes) notes.push(`<span class="time-conflict-text">${hours(row.incompleteMinutes)} ohne Uhrzeiten</span>`);
    return notes.join("<br>") || '<span class="muted">–</span>';
  }

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
      ${summaryTiles(data.totals)}
      <h3 class="purchase-subheading">Nach Auftrag</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-title">Auftrag</th><th class="cell-money">Nettozeit</th><th class="cell-money">Davon Reise</th><th class="cell-money">Übrige Arbeit</th><th class="cell-status">Herkunft</th></tr></thead>
        <tbody>${data.byOrder.map((row) => `<tr><td class="cell-title">${row.orderId ? `${esc(row.orderNumber)} · ${esc(row.orderTitle)}` : '<span class="muted">Ohne Auftrag</span>'}</td><td class="cell-money">${hours(row.netMinutes)}</td><td class="cell-money">${hours(row.travelMinutes)}</td><td class="cell-money">${hours(row.workMinutes)}</td>
          <td class="cell-status">${orderSourceNote(row)}</td></tr>`).join("") || '<tr><td colspan="5" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>
      <h3 class="purchase-subheading">Buchungen</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-date">Datum</th><th class="cell-text">Mitarbeiter</th><th class="cell-text">Zeit</th><th class="cell-type">Herkunft</th><th class="cell-type">Einsatz</th><th class="cell-number">Auftrag</th><th class="cell-money">Netto</th><th class="cell-money">Reise</th></tr></thead>
        <tbody>${data.rows.map((row) => `<tr><td class="cell-date">${format.date(row.date)}</td><td class="cell-text">${esc(row.employeeName)}</td><td class="cell-text">${row.hasTimes ? `${esc(row.start)}–${esc(row.end)}${row.endsNextDay ? " (+1)" : ""}` : untimedLabel(!data.timesOptional)}</td>
          <td class="cell-type">${row.reportUrl ? `<a href="${esc(row.reportUrl)}">${esc(row.reportLabel)}</a>` : `<span class="${activityClass(row.activity)}">${esc(row.activityLabel)}</span>`}</td><td class="cell-type" title="${esc(row.assignmentLabel)}">${row.assignmentKind === "external" ? "Auswärts" : row.assignmentKind === "regular" ? "Erste T." : "–"}</td><td class="cell-number">${esc(row.orderNumber || "–")}</td><td class="cell-money">${periodHours(row.periodNetMinutes, row.netMinutes)}</td><td class="cell-money">${row.travelMinutes ? periodHours(row.periodTravelMinutes, row.travelMinutes) : "–"}</td></tr>`).join("") || '<tr><td colspan="8" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>`;
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
      if (!(await CalightModal.confirm({ title: "Zeitblock löschen", content: `${entry.hasTimes ? `${entry.start}–${entry.end}` : hours(entry.netMinutes)} (${entry.activityLabel}) wird gelöscht.`, confirmLabel: "Löschen", danger: true }))) return;
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
