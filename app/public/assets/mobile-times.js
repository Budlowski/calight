/*
 * Mobile persönliche Zeiterfassung (/mobile/times).
 *
 * Reiner Client der bestehenden API /api/time-tracking/*: keine eigene Berechnung, Prüfung oder
 * Sperrlogik. Maßgeblich ist immer der dem Benutzer zugeordnete Mitarbeiter (keine Auswahl).
 * Summen, Hinweise, Pauschalen und Sperren kommen vom Server; die Nettovorschau im Formular dient
 * nur der Orientierung. Keine Offline-Erfassung: ohne Verbindung wird nichts gespeichert.
 */
(function (global) {
  "use strict";

  // ---------------------------------------------------------------------------
  // Reine Hilfen (getestet in tests/js/mobile-times.test.js)
  // ---------------------------------------------------------------------------

  const WEEKDAYS = ["So", "Mo", "Di", "Mi", "Do", "Fr", "Sa"];
  const WEEKDAYS_LONG = ["Sonntag", "Montag", "Dienstag", "Mittwoch", "Donnerstag", "Freitag", "Samstag"];
  const MONTHS = ["Januar", "Februar", "März", "April", "Mai", "Juni", "Juli", "August", "September", "Oktober", "November", "Dezember"];
  const DAY_STATUS = { open: "Offen", submitted: "Eingereicht", approved: "Festgeschrieben", reopened: "Zur Korrektur geöffnet" };
  const DAY_SYMBOL = { submitted: "⧗", approved: "✓", reopened: "↺" };
  const PERIOD_STATUS = { open: "Offen", submitted: "Eingereicht", approved: "Festgeschrieben", reopened: "Zur Korrektur geöffnet", mixed: "Teilweise eingereicht" };
  const SUBMISSION_STATUS = { submitted: "Eingereicht", approved: "Festgeschrieben", returned: "Zurückgegeben", reopened: "Wieder geöffnet" };

  /** Kalenderdatum (YYYY-MM-DD) in UTC, damit Sommerzeitwechsel keine Tage verschieben. */
  const parse = (date) => new Date(`${date}T00:00:00Z`);
  const iso = (value) => value.toISOString().slice(0, 10);
  const addDays = (date, days) => {
    const value = parse(date);
    value.setUTCDate(value.getUTCDate() + days);
    return iso(value);
  };
  const weekStart = (date) => addDays(date, -((parse(date).getUTCDay() + 6) % 7));
  const monthStart = (date) => `${date.slice(0, 7)}-01`;
  const addMonths = (date, months) => {
    const value = parse(monthStart(date));
    value.setUTCMonth(value.getUTCMonth() + months);
    return iso(value);
  };
  const germanDate = (date) => `${date.slice(8, 10)}.${date.slice(5, 7)}.${date.slice(0, 4)}`;
  const weekday = (date) => WEEKDAYS[parse(date).getUTCDay()];

  /** ISO-Kalenderwoche wie in der Büroansicht. */
  function isoWeek(date) {
    const value = parse(date);
    value.setUTCDate(value.getUTCDate() + 3 - ((value.getUTCDay() + 6) % 7));
    const firstThursday = new Date(Date.UTC(value.getUTCFullYear(), 0, 4));
    return 1 + Math.round(((value - firstThursday) / 86400000 - 3 + ((firstThursday.getUTCDay() + 6) % 7)) / 7);
  }

  /** Minuten als „7:30 h“. */
  function formatMinutes(minutes) {
    const value = Math.round(Number(minutes) || 0);
    const sign = value < 0 ? "-" : "";
    const absolute = Math.abs(value);
    return `${sign}${Math.floor(absolute / 60)}:${String(absolute % 60).padStart(2, "0")} h`;
  }

  /** „7:30“, „7,5“, „7.5“ oder „7“ (Stunden) → Minuten; ungültig → null. */
  function parseDuration(text) {
    const value = String(text ?? "").trim();
    if (value === "") return null;
    const clock = /^(\d{1,2}):([0-5]\d)$/.exec(value);
    if (clock) return Number(clock[1]) * 60 + Number(clock[2]);
    const decimal = /^(\d{1,2})(?:[.,](\d{1,2}))?$/.exec(value);
    if (!decimal) return null;
    return Math.round(Number(`${decimal[1]}.${decimal[2] || "0"}`) * 60);
  }

  /** Minuten als Eingabetext „7:30“ (leer bei null). */
  const durationText = (minutes) => (minutes === null || minutes === undefined ? "" : `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")}`);

  /** Nettovorschau aus Beginn, Ende und Pause (Ende ≤ Beginn: Folgetag) – nur Anzeige. */
  function previewNet(start, end, breakMinutes) {
    const pattern = /^([01]\d|2[0-3]):([0-5]\d)$/;
    if (!pattern.test(start || "") || !pattern.test(end || "")) return null;
    const minutes = (value) => Number(value.slice(0, 2)) * 60 + Number(value.slice(3, 5));
    let span = minutes(end) - minutes(start);
    if (span === 0) return null;
    if (span < 0) span += 1440;
    const pause = Number(breakMinutes) || 0;
    return span - pause > 0 ? span - pause : null;
  }

  /** Dezimaleingabe mit Komma → API-Format mit Punkt. */
  const decimalInput = (value) => String(value ?? "").trim().replace(/\s/g, "").replace(",", ".");

  /** Sperrfehler und Warnungen eines Zeitraums je Datum. */
  function hintsByDate(period) {
    const result = {};
    const add = (issue, level) => {
      if (!issue.date) return;
      (result[issue.date] ||= { errors: [], warnings: [] })[level].push(issue.message);
    };
    (period?.errors || []).forEach((issue) => add(issue, "errors"));
    (period?.warnings || []).forEach((issue) => add(issue, "warnings"));
    return result;
  }

  /** Monatsraster ab Montag; Lücken als null. */
  function monthCells(date) {
    const first = monthStart(date);
    const lead = (parse(first).getUTCDay() + 6) % 7;
    const cells = Array.from({ length: lead }, () => null);
    for (let day = first; day.slice(0, 7) === first.slice(0, 7); day = addDays(day, 1)) cells.push(day);
    while (cells.length % 7 !== 0) cells.push(null);
    return cells;
  }

  /** Formularwerte eines Zeitblocks → API-Nutzlast (dieselben Felder wie die Büroansicht). */
  function entryPayload(values, { untimed = false, fixedDuration = false } = {}) {
    const payload = {
      start: untimed ? "" : values.start || "",
      end: untimed ? "" : values.end || "",
      breakMinutes: untimed ? "" : String(values.breakMinutes ?? "").trim(),
      travelMinutes: String(values.travelMinutes ?? "").trim(),
      assignmentKind: values.assignmentKind || "",
      workLocation: String(values.workLocation ?? "").trim(),
      notes: String(values.notes ?? "").trim(),
    };
    if (!fixedDuration) {
      payload.date = values.date;
      payload.orderId = values.orderId || "";
      payload.costCenterId = values.costCenterId || "";
      if (untimed) {
        const minutes = parseDuration(values.duration);
        payload.durationMinutes = minutes === null ? "" : String(minutes);
      }
    }
    if (values.version !== undefined && values.version !== "") payload.version = Number(values.version);
    return payload;
  }

  /** Netzwerkfehler (fetch) verständlich melden; Serverfehler unverändert durchreichen. */
  function friendlyError(error) {
    if (error instanceof TypeError || /Failed to fetch|NetworkError|Load failed/i.test(String(error?.message))) {
      return "Keine Verbindung zum Server. Die Änderung wurde nicht gespeichert – bitte die Verbindung prüfen und erneut versuchen.";
    }
    return error?.message || "Die Anfrage ist fehlgeschlagen.";
  }

  global.CalightMobileTimes = Object.freeze({
    addDays, weekStart, monthStart, addMonths, isoWeek, formatMinutes, parseDuration, durationText, previewNet,
    decimalInput, hintsByDate, monthCells, entryPayload, friendlyError, germanDate,
  });

  // ---------------------------------------------------------------------------
  // Oberfläche
  // ---------------------------------------------------------------------------

  const root = typeof document !== "undefined" ? document.getElementById("mobile-times") : null;
  if (!root || !global.Calight) return;

  const { api, esc, format } = global.Calight;
  const readOnlyModule = global.Calight.moduleReadOnly;
  const content = root.querySelector("[data-content]");
  const message = root.querySelector("[data-message]");
  const sheet = document.querySelector("[data-sheet]");
  const sheetForm = sheet.querySelector("[data-sheet-form]");
  const sheetBody = sheet.querySelector("[data-sheet-body]");
  const sheetError = sheet.querySelector("[data-sheet-error]");
  const sheetActions = sheet.querySelector("[data-sheet-actions]");
  const today = root.dataset.today;

  const state = {
    view: root.dataset.view || "day",
    date: root.dataset.date || today,
    reference: null,
    day: null,
    period: null,
    sheet: null,
    dirty: false,
    ignorePop: false,
    busy: false,
  };

  const money = (value) => (value === null || value === undefined ? "–" : format.money(value));
  const km = (value) => `${format.number(value, 1)} km`;
  const canEdit = () => !readOnlyModule && Boolean(state.day?.canEdit);
  const periodType = () => (state.view === "month" ? "month" : "week");

  function showMessage(text, type = "success") {
    message.textContent = text;
    message.className = `notice notice-${type}`;
    message.hidden = !text;
    if (text && type === "success") setTimeout(() => { if (message.textContent === text) message.hidden = true; }, 4000);
  }

  function writeUrl(push = false) {
    const query = new URLSearchParams();
    if (state.view !== "day") query.set("view", state.view);
    if (state.date !== today) query.set("date", state.date);
    const url = `${location.pathname}${query.toString() ? `?${query}` : ""}`;
    if (push) history.pushState({ view: state.view, date: state.date }, "", url);
    else history.replaceState({ view: state.view, date: state.date }, "", url);
  }

  function periodLabel() {
    if (state.view === "day") return `<strong>${WEEKDAYS_LONG[parse(state.date).getUTCDay()]}</strong><span>${germanDate(state.date)}</span>`;
    if (state.view === "week") {
      const from = weekStart(state.date);
      return `<strong>KW ${isoWeek(state.date)}</strong><span>${germanDate(from)} – ${germanDate(addDays(from, 6))}</span>`;
    }
    return `<strong>${MONTHS[Number(state.date.slice(5, 7)) - 1]} ${state.date.slice(0, 4)}</strong>`;
  }

  function step(direction) {
    if (state.view === "day") state.date = addDays(state.date, direction);
    else if (state.view === "week") state.date = addDays(state.date, 7 * direction);
    else state.date = addMonths(state.date, direction);
    load(true);
  }

  // --- Laden -----------------------------------------------------------------

  async function load(push = false) {
    writeUrl(push);
    root.querySelectorAll("[data-view-switch]").forEach((button) => button.setAttribute("aria-pressed", String(button.dataset.viewSwitch === state.view)));
    root.querySelector("[data-period-label]").innerHTML = periodLabel();
    root.querySelector("[data-go-today]").hidden = state.view === "day" ? state.date === today
      : state.view === "week" ? weekStart(state.date) === weekStart(today) : monthStart(state.date) === monthStart(today);
    content.setAttribute("aria-busy", "true");
    try {
      if (state.view === "day") {
        const [day, period] = await Promise.all([
          api(`/api/time-tracking/day?date=${state.date}`),
          api(`/api/time-tracking/period?type=week&date=${state.date}`).catch(() => null),
        ]);
        state.day = day;
        state.period = period;
        renderDay();
      } else {
        state.period = await api(`/api/time-tracking/period?type=${periodType()}&date=${state.date}`);
        renderPeriod();
      }
    } catch (error) {
      content.innerHTML = `<p class="mobile-card notice notice-error">${esc(friendlyError(error))}</p>`;
    } finally {
      content.removeAttribute("aria-busy");
    }
  }

  // --- Tagesansicht ------------------------------------------------------------

  function hintList(errors, warnings) {
    if (!errors.length && !warnings.length) return "";
    return `<ul class="mobile-hint-list mobile-card">${errors.map((text) => `<li class="mobile-hint mobile-hint-error"><span aria-hidden="true">✕</span><span><span class="visually-hidden">Sperrfehler: </span>${esc(text)}</span></li>`).join("")}${warnings
      .map((text) => `<li class="mobile-hint mobile-hint-warning"><span aria-hidden="true">⚠</span><span><span class="visually-hidden">Hinweis: </span>${esc(text)}</span></li>`).join("")}</ul>`;
  }

  function entryItem(entry) {
    const time = entry.hasTimes ? `${esc(entry.start)}–${esc(entry.end)}${entry.endsNextDay ? " (+1)" : ""}` : (entry.timesMissing ? '<span class="mobile-warning-text">Uhrzeiten fehlen</span>' : "Ohne Uhrzeit");
    const target = entry.orderId ? `${esc(entry.orderNumber)} · ${esc(entry.orderTitle)}`
      : entry.costCenterId ? `${esc(entry.costCenterNumber)} · ${esc(entry.costCenterName)} <small class="muted">(Kostenstelle)</small>` : '<span class="muted">Ohne Zuordnung</span>';
    const extra = [
      entry.breakMinutes ? `Pause ${entry.breakMinutes} min` : "",
      entry.travelMinutes ? `davon Reisezeit ${formatMinutes(entry.travelMinutes)}` : "",
      entry.assignmentLabel || "",
    ].filter(Boolean).join(" · ");
    const editable = canEdit() && entry.canEditTimes;
    const tag = editable ? "button" : "div";
    return `<li><${tag} class="mobile-list-item mobile-entry"${editable ? ` type="button" data-edit-entry="${esc(entry.id)}"` : ""}>
      <span class="mobile-list-main">
        <small class="mobile-list-kicker">${time} · ${esc(entry.sourceLabel)}</small>
        <strong>${target}</strong>
        ${extra ? `<small class="muted">${esc(extra)}</small>` : ""}
        ${entry.notes ? `<small>${esc(entry.notes)}</small>` : ""}
        ${entry.durationFixed && editable ? '<small class="muted">Dauer aus dem Bericht · Uhrzeiten ergänzen</small>' : ""}
      </span>
      <span class="mobile-entry-hours">${formatMinutes(entry.netMinutes)}</span>
    </${tag}></li>`;
  }

  function renderDay() {
    const day = state.day;
    const hints = hintsByDate(state.period)[day.date] || { errors: [], warnings: [] };
    const editable = canEdit();
    const locked = day.lockStatus === "submitted" || day.lockStatus === "approved";
    const travel = day.travel;
    content.innerHTML = `
      <section class="mobile-card">
        <div class="mobile-card-heading"><h2>Arbeitszeit</h2><span class="status-badge mobile-lock-${esc(day.lockStatus)}">${esc(day.lockLabel)}</span></div>
        <p class="mobile-today"><strong class="mobile-today-value">${formatMinutes(day.totals.netMinutes)}</strong>
          <span class="muted">${day.entries.length === 1 ? "1 Buchung" : `${day.entries.length} Buchungen`}${day.totals.travelMinutes ? ` · davon Reisezeit ${formatMinutes(day.totals.travelMinutes)}` : ""}</span></p>
        ${locked ? '<p class="mobile-card-text muted">Dieser Tag ist eingereicht oder festgeschrieben und kann nur gelesen werden. Korrekturen öffnet das Büro.</p>' : ""}
      </section>
      ${hintList(hints.errors, hints.warnings)}
      ${editable ? `<div class="mobile-action-row">
        <button class="button" type="button" data-new-entry data-icon="plus">Arbeitszeit</button>
        <button class="button button-light" type="button" data-new-absence data-icon="plus">Abwesenheit</button>
      </div>` : ""}
      <section class="mobile-card">
        <h2>Buchungen</h2>
        ${day.entries.length ? `<ul class="mobile-list">${day.entries.map(entryItem).join("")}</ul>` : '<p class="mobile-card-text muted">Keine Arbeitszeiten an diesem Tag.</p>'}
        ${(day.carriedOver || []).length ? `<p class="mobile-card-text muted">Vom Vortag über Mitternacht: ${day.carriedOver.map((item) => `${esc(item.start)}–${esc(item.end)} (${formatMinutes(item.netMinutes)})`).join(", ")}</p>` : ""}
      </section>
      ${day.absences.length ? `<section class="mobile-card"><h2>Abwesenheit</h2><ul class="mobile-list">${day.absences.map((absence) => {
        const tag = editable ? "button" : "div";
        return `<li><${tag} class="mobile-list-item mobile-entry"${editable ? ` type="button" data-edit-absence="${esc(absence.id)}"` : ""}>
          <span class="mobile-list-main"><strong>${esc(absence.label)}</strong>${absence.notes ? `<small class="muted">${esc(absence.notes)}</small>` : ""}</span>
          <span class="mobile-entry-hours">${absence.fullDay ? "ganztägig" : formatMinutes(absence.minutes)}</span></${tag}></li>`;
      }).join("")}</ul><p class="mobile-card-text muted">Abwesenheiten zählen nicht zur Arbeitszeit.</p></section>` : ""}
      <section class="mobile-card">
        <div class="mobile-card-heading"><h2>Reisekosten und Auslagen</h2></div>
        ${travel && travel.category !== "none" ? `<p class="mobile-card-text"><strong>${esc(travel.categoryLabel)}</strong>${travel.abroad ? " · Ausland" : ""}${travel.location ? ` · ${esc(travel.location)}` : ""}</p>
          ${travel.allowance !== null ? `<p class="mobile-card-text muted">Verpflegungspauschale rechnerisch ${money(travel.allowance)}${travel.allowanceNote ? ` · ${esc(travel.allowanceNote)}` : ""}</p>` : ""}` : '<p class="mobile-card-text muted">Keine Auswärtstätigkeit erfasst.</p>'}
        ${day.trips.length ? `<h3>Privat-Pkw-Fahrten</h3><ul class="mobile-list">${day.trips.map((trip) => {
          const tag = editable ? "button" : "div";
          return `<li><${tag} class="mobile-list-item mobile-entry"${editable ? ` type="button" data-edit-trip="${esc(trip.id)}"` : ""}>
            <span class="mobile-list-main"><strong>${esc(trip.purpose)}</strong><small class="muted">${esc([trip.route, trip.orderNumber || trip.costCenterNumber].filter(Boolean).join(" · "))}</small></span>
            <span class="mobile-entry-hours">${km(trip.kilometers)}</span></${tag}></li>`;
        }).join("")}</ul>${travel?.kmAmount ? `<p class="mobile-card-text muted">${km(travel.privateKm)} · rechnerisch ${money(travel.kmAmount)}</p>` : ""}` : ""}
        ${day.expenses.length ? `<h3>Auslagen</h3><ul class="mobile-list">${day.expenses.map((expense) => `<li class="mobile-list-item">
            <span class="mobile-list-main"><strong>${esc(expense.purpose)}</strong><small class="muted">${esc([expense.orderNumber || expense.costCenterNumber, expense.receiptName].filter(Boolean).join(" · "))}</small>
              ${expense.hasReceipt ? `<a href="/api/time-tracking/expenses/${encodeURIComponent(expense.id)}/receipt" target="_blank" rel="noopener">Beleg öffnen</a>` : ""}</span>
            <span class="mobile-entry-hours">${money(expense.amount)}</span>
            ${editable ? `<button class="icon-button icon-button-danger mobile-icon-button" type="button" data-delete-expense="${esc(expense.id)}" data-icon="trash" aria-label="Auslage entfernen" title="Auslage entfernen"></button>` : ""}
          </li>`).join("")}</ul>` : ""}
        ${(day.travelWarnings || []).length ? hintList([], day.travelWarnings.map((item) => item.message || item)) : ""}
        ${editable ? `<div class="mobile-action-row">
          <button class="button button-light" type="button" data-edit-travel>Auswärtstätigkeit</button>
          <button class="button button-light" type="button" data-new-trip data-icon="plus">Fahrt</button>
          <button class="button button-light" type="button" data-new-expense data-icon="plus">Auslage</button>
        </div>` : ""}
      </section>
      ${state.period ? `<button class="button button-light mobile-wide-button" type="button" data-open-week>Woche ansehen und einreichen</button>` : ""}`;
  }

  // --- Woche und Monat -----------------------------------------------------------

  function dayMarkers(date, hints) {
    const hint = hints[date];
    return `${hint?.errors.length ? '<span class="mobile-marker-error" aria-label="Sperrfehler">✕</span>' : ""}${hint?.warnings.length ? '<span class="mobile-marker-warning" aria-label="Hinweis">⚠</span>' : ""}`;
  }

  function renderPeriod() {
    const period = state.period;
    const hints = hintsByDate(period);
    const days = Object.fromEntries(period.days.map((day) => [day.date, day]));
    const body = state.view === "week"
      ? `<ul class="mobile-list mobile-card mobile-week">${period.days.map((day) => `<li><button class="mobile-list-item mobile-entry${day.date === today ? " is-today" : ""}" type="button" data-open-day="${esc(day.date)}">
          <span class="mobile-week-date"><strong>${weekday(day.date)}</strong><small>${germanDate(day.date).slice(0, 6)}</small></span>
          <span class="mobile-list-main">
            <span>${DAY_SYMBOL[day.status] ? `<span aria-hidden="true">${DAY_SYMBOL[day.status]}</span> ` : ""}<small class="muted">${esc(DAY_STATUS[day.status] || day.status)}</small> ${dayMarkers(day.date, hints)}</span>
            ${day.absences.length ? `<small>${esc(day.absences.map((absence) => absence.label).join(", "))}</small>` : ""}
            ${day.holiday ? `<small class="muted">${esc(day.holiday)}</small>` : ""}
          </span>
          <span class="mobile-entry-hours">${day.netMinutes ? formatMinutes(day.netMinutes) : "–"}</span>
        </button></li>`).join("")}</ul>`
      : `<div class="mobile-card mobile-month" role="grid" aria-label="Monatskalender">
          ${["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"].map((name) => `<span class="mobile-month-head" role="columnheader">${name}</span>`).join("")}
          ${monthCells(state.date).map((date) => {
            if (!date) return '<span class="mobile-month-cell is-empty" aria-hidden="true"></span>';
            const day = days[date] || { netMinutes: 0, status: "open", absences: [] };
            const absence = day.absences.length ? `<small class="mobile-month-absence">${esc(`${day.absences[0].label.slice(0, 3)}.`)}</small>` : "";
            return `<button class="mobile-month-cell${date === today ? " is-today" : ""}${day.absences.length ? " has-absence" : ""}" type="button" data-open-day="${esc(date)}"
                aria-label="${germanDate(date)}: ${formatMinutes(day.netMinutes)}, ${esc(DAY_STATUS[day.status] || day.status)}">
              <span class="mobile-month-number">${Number(date.slice(8, 10))}${DAY_SYMBOL[day.status] ? `<span aria-hidden="true">${DAY_SYMBOL[day.status]}</span>` : ""}</span>
              <span class="mobile-month-hours">${day.netMinutes ? formatMinutes(day.netMinutes).replace(" h", "") : ""}</span>${absence}${dayMarkers(date, hints)}
            </button>`;
          }).join("")}
        </div>`;
    content.innerHTML = body + submissionCard(period);
  }

  function submissionCard(period) {
    const type = periodType();
    const allowed = (period.allowedTypes || []).includes(type);
    const totals = period.totals || {};
    const general = (period.errors || []).filter((issue) => !issue.date);
    const dated = (period.errors || []).filter((issue) => issue.date);
    const returned = (period.submissions || []).filter((submission) => submission.status === "returned" && submission.comment);
    const reasons = (period.events || []).filter((event) => event.reason && ["return", "reopen"].includes(event.action));
    return `<section class="mobile-card mobile-submission">
      <div class="mobile-card-heading"><h2>${esc(period.period.label)}</h2><span class="status-badge mobile-lock-${esc(period.status)}">${esc(PERIOD_STATUS[period.status] || period.status)}</span></div>
      <p class="mobile-today"><strong class="mobile-today-value">${formatMinutes(totals.netMinutes ?? period.days.reduce((sum, day) => sum + day.netMinutes, 0))}</strong>
        <span class="muted">Arbeitszeit im Zeitraum${totals.travelMinutes ? ` · davon Reisezeit ${formatMinutes(totals.travelMinutes)}` : ""}</span></p>
      ${dated.length || general.length ? `<h3>Sperrfehler</h3>${hintList([...general, ...dated].map((issue) => issue.message), [])}` : ""}
      ${(period.warnings || []).length ? `<details class="mobile-disclosure"><summary>${period.warnings.length} Hinweise</summary>${hintList([], period.warnings.map((issue) => issue.message))}</details>` : ""}
      ${returned.length || reasons.length ? `<h3>Rückgaben und Korrekturen</h3><ul class="mobile-list">${[
        ...returned.map((submission) => `<li class="mobile-list-item"><span class="mobile-list-main"><strong>Zurückgegeben${submission.decidedByName ? ` von ${esc(submission.decidedByName)}` : ""}</strong><small>${esc(submission.comment)}</small></span></li>`),
        ...reasons.filter((event) => event.action === "reopen").map((event) => `<li class="mobile-list-item"><span class="mobile-list-main"><strong>Zur Korrektur geöffnet (${germanDate(event.from)}${event.to !== event.from ? ` – ${germanDate(event.to)}` : ""})</strong><small>${esc(event.reason)}</small></span></li>`),
      ].join("")}</ul>` : ""}
      ${(period.submissions || []).length ? `<details class="mobile-disclosure"><summary>Einreichungen (${period.submissions.length})</summary><ul class="mobile-list">${period.submissions.map((submission) => `<li class="mobile-list-item">
          <span class="mobile-list-main"><strong>${esc(submission.label)}</strong><small class="muted">${format.dateTime(submission.submittedAt)}${submission.decidedAt ? ` · entschieden ${format.dateTime(submission.decidedAt)}` : ""}</small></span>
          <span class="status-badge">${esc(SUBMISSION_STATUS[submission.status] || submission.status)}</span></li>`).join("")}</ul></details>` : ""}
      ${(period.statements || []).length ? `<h3>Stundennachweise</h3><ul class="mobile-list">${period.statements.map((statement) => `<li><a class="mobile-list-item" href="${esc(statement.url)}" target="_blank" rel="noopener">
          <span class="mobile-list-main"><strong>${esc(statement.label)}${statement.revision > 1 ? ` · Revision ${statement.revision}` : ""}</strong><small class="muted">${format.dateTime(statement.createdAt)}</small></span>
          <span class="status-badge">${statement.current ? "Aktuell" : "Ersetzt"}</span></a></li>`).join("")}</ul>` : ""}
      ${readOnlyModule ? "" : allowed
        ? (period.canSubmit ? `<button class="button mobile-wide-button" type="button" data-submit-period>${type === "week" ? "Woche" : "Monat"} einreichen</button>`
          : (period.status === "approved" || period.status === "submitted" ? "" : '<p class="mobile-card-text muted">Einreichen ist möglich, sobald keine Sperrfehler mehr bestehen und der Zeitraum abgeschlossen ist.</p>'))
        : `<p class="mobile-card-text muted">Eingereicht wird laut Betriebseinstellung ${type === "week" ? "monatlich" : "wöchentlich"}.</p>`}
    </section>`;
  }

  // --- Formulare (Vollbild-Blatt) -------------------------------------------------

  function field(label, control, hint = "") {
    return `<label class="mobile-field"><span>${label}</span>${control}${hint ? `<small class="muted">${hint}</small>` : ""}</label>`;
  }
  const checkbox = (name, label, checked) => `<label class="mobile-check"><input type="checkbox" name="${name}"${checked ? " checked" : ""}><span>${label}</span></label>`;
  const options = (map, selected, empty = "") => `${empty ? `<option value="">${esc(empty)}</option>` : ""}${Object.entries(map).map(([value, label]) => `<option value="${esc(value)}"${value === selected ? " selected" : ""}>${esc(label)}</option>`).join("")}`;

  function costTargetField(orderId = "", costCenterId = "") {
    return field("Auftrag / Kostenstelle", '<select name="costTarget" data-cost-target></select>', "Nummer oder Bezeichnung eingeben");
  }

  function initCostTarget(orderId, costCenterId) {
    const select = sheetForm.querySelector("[data-cost-target]");
    if (select) global.Calight.costTargetSelect(select, state.reference.costTargets, { orderId: orderId || "", costCenterId: costCenterId || "" });
  }

  function openSheet({ title, body, submitLabel = "Speichern", remove = null, submit, init = null }) {
    state.sheet = { submit, remove };
    sheet.querySelector("[data-sheet-title]").textContent = title;
    // Jedes Formular erhält einen neuen Container; Listener hängen nur an diesem.
    sheetBody.innerHTML = `<div class="mobile-sheet-content">${body}</div>`;
    sheetError.hidden = true;
    sheetActions.innerHTML = `${remove ? '<button class="button button-danger-light" type="button" data-sheet-remove data-icon="trash">Löschen</button>' : ""}
      <button class="button button-light" type="button" data-sheet-cancel>Abbrechen</button>
      <button class="button" type="submit" data-sheet-submit>${esc(submitLabel)}</button>`;
    init?.();
    state.dirty = false;
    sheet.hidden = false;
    document.body.classList.add("mobile-sheet-open");
    history.pushState({ sheet: true, view: state.view, date: state.date }, "", location.href);
    sheetBody.querySelector("input:not([type=hidden]):not([type=checkbox]), select, textarea")?.focus({ preventScroll: true });
  }

  function closeSheet({ fromHistory = false } = {}) {
    if (sheet.hidden) return;
    sheet.hidden = true;
    document.body.classList.remove("mobile-sheet-open");
    state.sheet = null;
    state.dirty = false;
    if (!fromHistory && history.state?.sheet) {
      state.ignorePop = true;
      history.back();
    }
  }

  async function confirmDiscard() {
    if (!state.dirty) return true;
    return global.CalightModal
      ? global.CalightModal.confirm({ title: "Änderungen verwerfen?", content: "Die Eingaben wurden noch nicht gespeichert.", confirmLabel: "Verwerfen", cancelLabel: "Weiter bearbeiten", danger: true })
      : false;
  }

  async function runSheet(operation, successText) {
    if (state.busy) return;
    state.busy = true;
    sheetError.hidden = true;
    sheetActions.querySelectorAll("button").forEach((button) => { button.disabled = true; });
    try {
      const result = await operation();
      closeSheet();
      // Nach dem Speichern zur Tagesansicht des betroffenen Tages zurück; nach einer Einreichung
      // bleibt die Wochen- bzw. Monatsansicht.
      if (result?.date) {
        state.date = result.date;
        state.view = "day";
      }
      showMessage(successText);
      await load();
    } catch (error) {
      sheetError.textContent = friendlyError(error);
      sheetError.hidden = false;
      sheetError.scrollIntoView({ block: "nearest" });
    } finally {
      state.busy = false;
      sheetActions.querySelectorAll("button").forEach((button) => { button.disabled = false; });
    }
  }

  const values = () => Object.fromEntries(new FormData(sheetForm).entries());

  function entryForm(entry = null, preset = {}) {
    const fixed = Boolean(entry?.durationFixed);
    // Manuelle Zeiterfassung immer mit Uhrzeiten; nur Berichtsstunden (fixed) dürfen ohne bleiben.
    const untimed = fixed && !entry.hasTimes;
    const body = `
      ${fixed ? `<p class="mobile-card-text"><strong>${esc(entry.sourceLabel)}</strong> · Dauer ${formatMinutes(entry.netMinutes)} (aus dem Bericht, hier nicht änderbar)${entry.orderNumber ? ` · ${esc(entry.orderNumber)}` : ""}</p>` : ""}
      ${fixed ? "" : field("Datum", `<input type="date" name="date" value="${esc(entry?.date || state.date)}" required>`)}
      ${fixed ? `<div class="mobile-segmented mobile-segmented-small" role="radiogroup" aria-label="Erfassungsart">
          <label><input type="radio" name="mode" value="times"${untimed ? "" : " checked"}><span>Mit Uhrzeit</span></label>
          <label><input type="radio" name="mode" value="duration"${untimed ? " checked" : ""}><span>Ohne Uhrzeit</span></label>
        </div>` : ""}
      <div class="mobile-field-row" data-times-fields>
        ${field("Beginn", `<input type="time" name="start" value="${esc(entry?.start || "")}" step="60">`)}
        ${field("Ende", `<input type="time" name="end" value="${esc(entry?.end || "")}" step="60">`)}
      </div>
      <div class="mobile-field-row">
        <div data-times-fields>${field("Pause (Min.)", `<input type="number" name="breakMinutes" inputmode="numeric" min="0" step="1" value="${esc(entry?.breakMinutes || "")}" placeholder="0">`)}</div>
        ${field("Davon Reisezeit (Min.)", `<input type="number" name="travelMinutes" inputmode="numeric" min="0" step="1" value="${esc(entry?.travelMinutes || "")}" placeholder="0">`)}
      </div>
      <p class="mobile-preview" data-net-preview aria-live="polite"></p>
      ${fixed ? "" : costTargetField()}
      ${Calight.workLocationFields({ workLocation: entry?.workLocation || "", assignmentKind: entry?.assignmentKind || "" }, { labelClass: "", fieldClass: "mobile-field" })}
      ${field("Bemerkung", `<textarea name="notes" rows="2" maxlength="500">${esc(entry?.notes || "")}</textarea>`)}
      <input type="hidden" name="version" value="${esc(entry?.version ?? "")}">`;
    openSheet({
      title: entry ? (fixed ? "Uhrzeiten ergänzen" : "Arbeitszeit bearbeiten") : "Arbeitszeit erfassen",
      body,
      remove: entry?.canDelete ? () => api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "DELETE", body: { version: entry.version } }) : null,
      init: () => {
        if (!fixed) initCostTarget(entry?.orderId || preset.orderId || "", entry?.costCenterId || "");
        const sync = () => {
          const mode = sheetForm.elements.mode?.value || "times";
          sheetForm.querySelectorAll("[data-times-fields]").forEach((element) => { element.hidden = mode !== "times"; });
          sheetForm.querySelectorAll("[data-duration-fields]").forEach((element) => { element.hidden = mode === "times"; });
          const net = previewNet(sheetForm.elements.start.value, sheetForm.elements.end.value, sheetForm.elements.breakMinutes.value);
          sheetForm.querySelector("[data-net-preview]").textContent = net === null ? "" : `Netto ${formatMinutes(net)}`;
        };
        const container = sheetBody.firstElementChild;
        container.addEventListener("input", sync);
        container.addEventListener("change", sync);
        sync();
      },
      submit: () => {
        const form = values();
        const target = global.Calight.parseCostTarget(form.costTarget);
        const untimedMode = (form.mode || "times") === "duration";
        const payload = entryPayload({ ...form, ...Calight.readWorkLocation(sheetForm), ...target }, { untimed: untimedMode, fixedDuration: fixed });
        return entry
          ? api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "PUT", body: payload })
          : api("/api/time-tracking/entries", { method: "POST", body: payload });
      },
    });
  }

  function absenceForm(absence = null) {
    const fullDay = absence ? absence.fullDay : true;
    openSheet({
      title: absence ? "Abwesenheit bearbeiten" : "Abwesenheit erfassen",
      body: `${field("Datum", `<input type="date" name="date" value="${esc(absence?.date || state.date)}" required>`)}
        ${field("Art", `<select name="absenceType" required>${options(state.reference.absenceTypes, absence?.type || "", "Bitte wählen …")}</select>`)}
        <div class="mobile-segmented mobile-segmented-small" role="radiogroup" aria-label="Umfang">
          <label><input type="radio" name="absenceScope" value="full"${fullDay ? " checked" : ""}><span>Ganztägig</span></label>
          <label><input type="radio" name="absenceScope" value="hours"${fullDay ? "" : " checked"}><span>Stunden</span></label>
        </div>
        <div data-hours-field${fullDay ? " hidden" : ""}>${field("Dauer", `<input type="text" name="hours" inputmode="decimal" placeholder="z. B. 4 oder 4:30" value="${esc(absence && !absence.fullDay ? durationText(absence.minutes) : "")}">`, "Stunden oder Stunden:Minuten")}</div>
        ${field("Bemerkung", `<textarea name="notes" rows="2" maxlength="500">${esc(absence?.notes || "")}</textarea>`, "Keine Diagnosen oder medizinischen Angaben eintragen.")}`,
      remove: absence ? () => api(`/api/time-tracking/absences/${encodeURIComponent(absence.id)}`, { method: "DELETE", body: { version: absence.version } }) : null,
      init: () => sheetForm.querySelectorAll('[name="absenceScope"]').forEach((input) => input.addEventListener("change", () => {
        sheetForm.querySelector("[data-hours-field]").hidden = sheetForm.elements.absenceScope.value === "full";
      })),
      submit: () => {
        const form = values();
        const payload = { date: form.date, absenceType: form.absenceType, fullDay: sheetForm.elements.absenceScope.value === "full", hours: form.hours || "", notes: form.notes || "" };
        return absence
          ? api(`/api/time-tracking/absences/${encodeURIComponent(absence.id)}`, { method: "PUT", body: { ...payload, version: absence.version } })
          : api("/api/time-tracking/absences", { method: "POST", body: payload });
      },
    });
  }

  function travelForm() {
    const details = state.day.details || {};
    const category = details.travelCategory || "none";
    const meals = state.reference.travelSettings?.mealAllowances !== false;
    openSheet({
      title: "Auswärtstätigkeit und Verpflegung",
      body: `<p class="mobile-card-text muted">${germanDate(state.date)} · Die Verpflegungspauschale berechnet der Server rechnerisch aus diesen Angaben.</p>
        ${field("Art der Auswärtstätigkeit", `<select name="travelCategory">${options(state.reference.travelCategories, category)}</select>`)}
        <div data-travel-fields${category === "none" ? " hidden" : ""}>
          ${checkbox("abroad", "Ausland", details.abroad)}
          ${field("Abwesenheitsdauer (optional)", `<input type="text" name="absence" inputmode="decimal" placeholder="z. B. 9:30" value="${esc(durationText(details.absenceMinutes ?? null))}">`)}
          ${checkbox("overnight", "Auswärtige Übernachtung", details.overnight)}
          ${meals ? `<fieldset class="mobile-fieldset"><legend>Gestellte Mahlzeiten</legend>
            ${checkbox("mealBreakfast", "Frühstück", details.mealBreakfast)}${checkbox("mealLunch", "Mittagessen", details.mealLunch)}${checkbox("mealDinner", "Abendessen", details.mealDinner)}</fieldset>` : ""}
          ${field("Einsatzort", `<input type="text" name="location" maxlength="200" value="${esc(details.location || "")}">`)}
        </div>
        ${field("Bemerkung", `<textarea name="notes" rows="2" maxlength="1000">${esc(details.notes || "")}</textarea>`)}`,
      init: () => sheetForm.elements.travelCategory.addEventListener("change", () => {
        sheetForm.querySelector("[data-travel-fields]").hidden = sheetForm.elements.travelCategory.value === "none";
      }),
      submit: () => {
        const form = values();
        const flag = (name) => Boolean(sheetForm.elements[name]?.checked);
        return api("/api/time-tracking/details", { method: "PUT", body: {
          date: state.date, travelCategory: form.travelCategory, abroad: flag("abroad"), absence: form.absence || "", overnight: flag("overnight"),
          mealBreakfast: flag("mealBreakfast"), mealLunch: flag("mealLunch"), mealDinner: flag("mealDinner"), location: form.location || "", notes: form.notes || "",
        } });
      },
    });
  }

  function tripForm(trip = null) {
    openSheet({
      title: trip ? "Fahrt bearbeiten" : "Privat-Pkw-Fahrt erfassen",
      body: `${field("Kilometer", `<input type="text" name="kilometers" inputmode="decimal" required placeholder="z. B. 42,5" value="${esc(trip ? String(trip.kilometers).replace(".", ",") : "")}">`)}
        ${field("Zweck", `<input type="text" name="purpose" maxlength="200" required value="${esc(trip?.purpose || "")}">`)}
        ${field("Strecke / Reiseziel", `<input type="text" name="route" maxlength="200" value="${esc(trip?.route || "")}">`)}
        ${costTargetField()}
        ${field("Bemerkung", `<textarea name="notes" rows="2" maxlength="500">${esc(trip?.notes || "")}</textarea>`)}`,
      remove: trip ? () => api(`/api/time-tracking/trips/${encodeURIComponent(trip.id)}`, { method: "DELETE", body: { version: trip.version } }) : null,
      init: () => initCostTarget(trip?.orderId || "", trip?.costCenterId || ""),
      submit: () => {
        const form = values();
        const payload = { date: state.date, kilometers: decimalInput(form.kilometers), purpose: form.purpose || "", route: form.route || "", notes: form.notes || "",
          ...global.Calight.parseCostTarget(form.costTarget) };
        return trip
          ? api(`/api/time-tracking/trips/${encodeURIComponent(trip.id)}`, { method: "PUT", body: { ...payload, version: trip.version } })
          : api("/api/time-tracking/trips", { method: "POST", body: payload });
      },
    });
  }

  async function uploadExpense(form, file) {
    const data = new FormData();
    data.set("date", state.date);
    data.set("amount", decimalInput(form.amount));
    data.set("purpose", form.purpose || "");
    const target = global.Calight.parseCostTarget(form.costTarget);
    data.set("orderId", target.orderId);
    data.set("costCenterId", target.costCenterId);
    if (file) data.set("file", file);
    const csrf = root.dataset.csrf;
    const response = await fetch("/api/time-tracking/expenses", { method: "POST", credentials: "same-origin", headers: { Accept: "application/json", "X-CSRF-Token": csrf }, body: data });
    let result = {};
    try { result = await response.json(); } catch { /* Antwort ohne JSON */ }
    if (!response.ok) throw new Error((result.details || []).join(" ") || result.error || `Anfrage fehlgeschlagen (HTTP ${response.status}).`);
    return result.data;
  }

  function expenseForm() {
    openSheet({
      title: "Auslage erfassen",
      body: `${field("Betrag (€)", '<input type="text" name="amount" inputmode="decimal" required placeholder="z. B. 12,90">')}
        ${field("Zweck", '<input type="text" name="purpose" maxlength="200" required>')}
        ${costTargetField()}
        <fieldset class="mobile-fieldset"><legend>Beleg (optional)</legend>
          <div class="mobile-action-row">
            <label class="button button-light mobile-file-button">Foto aufnehmen<input type="file" name="photo" accept="image/*" capture="environment" hidden></label>
            <label class="button button-light mobile-file-button">Datei wählen<input type="file" name="upload" accept="application/pdf,image/*" hidden></label>
          </div>
          <p class="mobile-card-text muted" data-file-name>Kein Beleg ausgewählt (PDF oder Bild, höchstens 15 MB).</p>
        </fieldset>`,
      init: () => {
        initCostTarget("", "");
        const show = (event) => {
          const file = event.target.files?.[0];
          // Nur ein Beleg: die jeweils andere Auswahl zurücksetzen.
          const other = event.target.name === "photo" ? sheetForm.elements.upload : sheetForm.elements.photo;
          if (file) other.value = "";
          sheetForm.querySelector("[data-file-name]").textContent = file ? `Beleg: ${file.name}` : "Kein Beleg ausgewählt (PDF oder Bild, höchstens 15 MB).";
        };
        sheetForm.elements.photo.addEventListener("change", show);
        sheetForm.elements.upload.addEventListener("change", show);
      },
      submit: () => uploadExpense(values(), sheetForm.elements.photo.files?.[0] || sheetForm.elements.upload.files?.[0] || null),
    });
  }

  function submitForm() {
    const period = state.period;
    const type = periodType();
    openSheet({
      title: `${type === "week" ? "Woche" : "Monat"} einreichen`,
      submitLabel: "Verbindlich einreichen",
      body: `<p class="mobile-card-text"><strong>${esc(period.period.label)}</strong> · ${formatMinutes(period.totals?.netMinutes ?? 0)}</p>
        ${(period.warnings || []).length ? `<p class="mobile-card-text">Das Büro prüft ${period.warnings.length} Hinweise:</p>${hintList([], period.warnings.map((issue) => issue.message))}` : ""}
        <p class="mobile-card-text muted">Nach dem Einreichen sind die offenen Tage des Zeitraums gesperrt. Korrekturen öffnet das Büro.</p>
        ${checkbox("confirmComplete", "Ich bestätige, dass meine Angaben für diesen Zeitraum vollständig und richtig sind.", false)}`,
      submit: async () => {
        if (!sheetForm.elements.confirmComplete.checked) throw new Error("Bitte die Vollständigkeit bestätigen.");
        await api("/api/time-tracking/submissions", { method: "POST", body: { type, date: state.date, confirmComplete: true } });
        return null;
      },
    });
  }

  // --- Ereignisse -------------------------------------------------------------

  root.querySelectorAll("[data-view-switch]").forEach((button) => button.addEventListener("click", () => {
    state.view = button.dataset.viewSwitch;
    load(true);
  }));
  root.querySelectorAll("[data-step]").forEach((button) => button.addEventListener("click", () => step(Number(button.dataset.step))));
  root.querySelector("[data-go-today]").addEventListener("click", () => {
    state.date = today;
    load(true);
  });

  content.addEventListener("click", async (event) => {
    const target = event.target.closest("button, a");
    if (!target) return;
    const find = (list, id) => list.find((item) => item.id === id);
    if (target.dataset.openDay) {
      state.view = "day";
      state.date = target.dataset.openDay;
      load(true);
    } else if (target.hasAttribute("data-open-week")) {
      state.view = "week";
      load(true);
    } else if (target.hasAttribute("data-new-entry")) entryForm();
    else if (target.dataset.editEntry) entryForm(find(state.day.entries, target.dataset.editEntry));
    else if (target.hasAttribute("data-new-absence")) absenceForm();
    else if (target.dataset.editAbsence) absenceForm(find(state.day.absences, target.dataset.editAbsence));
    else if (target.hasAttribute("data-edit-travel")) travelForm();
    else if (target.hasAttribute("data-new-trip")) tripForm();
    else if (target.dataset.editTrip) tripForm(find(state.day.trips, target.dataset.editTrip));
    else if (target.hasAttribute("data-new-expense")) expenseForm();
    else if (target.hasAttribute("data-submit-period")) submitForm();
    else if (target.dataset.deleteExpense) {
      const ok = await global.CalightModal.confirm({ title: "Auslage entfernen?", content: "Die Auslage und ihr Beleg werden gelöscht.", confirmLabel: "Entfernen", danger: true });
      if (!ok) return;
      try {
        await api(`/api/time-tracking/expenses/${encodeURIComponent(target.dataset.deleteExpense)}`, { method: "DELETE", body: {} });
        showMessage("Auslage entfernt.");
        load();
      } catch (error) {
        showMessage(friendlyError(error), "error");
      }
    }
  });

  sheetForm.addEventListener("input", () => { state.dirty = true; });
  sheetForm.addEventListener("submit", (event) => {
    event.preventDefault();
    if (state.sheet) runSheet(state.sheet.submit, "Gespeichert.");
  });
  sheet.addEventListener("click", async (event) => {
    if (event.target.closest("[data-sheet-cancel]")) {
      if (await confirmDiscard()) closeSheet();
    } else if (event.target.closest("[data-sheet-remove]") && state.sheet?.remove) {
      const ok = await global.CalightModal.confirm({ title: "Eintrag löschen?", content: "Der Eintrag wird entfernt.", confirmLabel: "Löschen", danger: true });
      if (ok) runSheet(state.sheet.remove, "Gelöscht.");
    }
  });
  sheet.addEventListener("keydown", async (event) => {
    if (event.key === "Escape" && (await confirmDiscard())) closeSheet();
  });

  // Zurück-Taste schließt zuerst das Formular (mit Rückfrage bei ungespeicherten Eingaben).
  global.addEventListener("popstate", async (event) => {
    if (state.ignorePop) {
      state.ignorePop = false;
      return;
    }
    if (!sheet.hidden) {
      if (await confirmDiscard()) {
        closeSheet({ fromHistory: true });
      } else {
        history.pushState({ sheet: true, view: state.view, date: state.date }, "", location.href);
      }
      return;
    }
    if (event.state?.view) {
      state.view = event.state.view;
      state.date = event.state.date;
      load();
    }
  });
  global.addEventListener("beforeunload", (event) => {
    if (!sheet.hidden && state.dirty) {
      event.preventDefault();
      event.returnValue = "";
    }
  });

  // --- Start ------------------------------------------------------------------

  async function init() {
    try {
      state.reference = await api("/api/time-tracking/reference-data");
    } catch (error) {
      content.innerHTML = `<p class="mobile-card notice notice-error">${esc(friendlyError(error))}</p>`;
      return;
    }
    if (!state.reference.ownEmployeeId) {
      // Keine Zuordnung anhand von Namen: nur das Büro verknüpft Benutzer und Mitarbeiter.
      root.querySelectorAll(".mobile-segmented, .mobile-day-nav, [data-go-today]").forEach((element) => { element.hidden = true; });
      content.innerHTML = `<section class="mobile-card"><h2>Kein Mitarbeiter zugeordnet</h2>
        <p class="mobile-card-text">Ihrem Benutzerkonto ist kein Mitarbeiter zugeordnet. Persönliche Arbeitszeiten, Abwesenheiten und Reisekosten können erst erfasst werden, wenn das Büro unter Stammdaten → Mitarbeiter Ihr Benutzerkonto verknüpft.</p></section>`;
      return;
    }
    await load();
    const form = root.dataset.form;
    if (form && canEdit()) {
      if (form === "entry") entryForm(null, { orderId: root.dataset.order || "" });
      else if (form === "absence") absenceForm();
      else if (form === "travel") travelForm();
      else if (form === "trip") tripForm();
      else if (form === "expense") expenseForm();
    } else if (form && state.day && !state.day.canEdit) {
      showMessage("Dieser Tag ist gesperrt; es können keine Einträge erfasst werden.", "warning");
    }
  }

  init();
})(globalThis);
