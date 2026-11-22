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
    const context = entry.orderNumber ? `${entry.orderNumber}${entry.orderTitle ? ` · ${entry.orderTitle}` : ""}`
      : entry.costCenterNumber ? `${entry.costCenterNumber} · ${entry.costCenterName}` : entry.customerName || "";
    const title = manual
      ? esc(entry.notes || entry.activityLabel)
      : `<a href="${esc(entry.reportUrl)}">${esc(entry.reportLabel)}</a>${entry.reportDraft ? ' <span class="time-meta-note">Entwurf</span>' : ""}`;
    const meta = [
      `<span class="time-source">${esc(entry.sourceLabel)}</span>`,
      !manual && entry.reportActivity ? esc(entry.reportActivity) : "",
      context ? `<span class="time-context" title="${esc(context)}">${esc(context)}</span>` : manual ? "Ohne Zuordnung" : "",
      entry.workLocation ? `<span>${esc(entry.workLocation)}</span>` : "",
      entry.assignmentKind ? `<span title="${esc(entry.assignmentLabel)}">${entry.assignmentKind === "external" ? "Auswärts" : "Erste Tätigkeitsstätte"}</span>` : "",
    ].filter(Boolean).join('<span aria-hidden="true"> · </span>');
    const editLabel = entry.durationFixed ? (entry.hasTimes ? "Uhrzeiten bearbeiten" : "Uhrzeiten ergänzen") : "Bearbeiten";
    return `<li class="time-block${entry.hasTimes ? "" : " is-untimed"}">
      <span class="time-block-range">${entry.hasTimes ? `${esc(entry.start)}–${esc(entry.end)}${entry.endsNextDay ? '<small> +1 Tag</small>' : ""}` : untimedLabel(entry.timesMissing)}</span>
      <span class="time-block-main"><span class="time-block-title" title="${esc(manual ? entry.notes || entry.activityLabel : entry.reportLabel)}">${title}</span><span class="time-block-meta" title="${esc([entry.sourceLabel, entry.reportActivity, context].filter(Boolean).join(" · "))}">${meta}</span></span>
      <span class="time-block-net"><strong>${hours(entry.netMinutes)}</strong>${entry.travelMinutes ? `<small>davon ${hours(entry.travelMinutes)} Reise</small>` : ""}${entry.breakMinutes ? `<small>${entry.breakMinutes} min Pause</small>` : ""}</span>
      <span class="time-block-actions">
        ${entry.canEditTimes && !Calight.moduleReadOnly ? `<button class="icon-button" type="button" data-edit-entry="${esc(entry.id)}" data-icon="${entry.durationFixed && !entry.hasTimes ? "clock-3" : "square-pen"}" title="${editLabel}" aria-label="${editLabel}"></button>` : ""}
        ${entry.canDelete && !Calight.moduleReadOnly ? `<button class="icon-button icon-button-danger" type="button" data-delete-entry="${esc(entry.id)}" data-icon="trash" title="Löschen" aria-label="Zeitblock löschen"></button>` : ""}
      </span>
    </li>`;
  };

  // ---------------------------------------------------------------------------
  // Freigabe: Tagesstatus, Zeitraumzusammenfassung (reine Darstellung, auch für Tests)
  // ---------------------------------------------------------------------------
  const LOCK_LABELS = { open: "Offen", submitted: "Eingereicht", approved: "Festgeschrieben", reopened: "Zur Korrektur geöffnet", mixed: "Teilweise eingereicht" };
  const LOCK_SYMBOLS = { submitted: "⧗", approved: "✓", reopened: "↺" };
  /** Kleines Kennzeichen des Tagesstatus in Kalenderzellen (offen: keines). */
  const lockFlag = (status) => (LOCK_SYMBOLS[status] ? `<span class="time-cal-flag time-lock-flag is-${status}" title="${LOCK_LABELS[status]}">${LOCK_SYMBOLS[status]}</span>` : "");
  /** Statuskennzeichen als Text (Tag, Woche, Monat). */
  const lockBadge = (status) => `<span class="time-lock-badge is-${esc(status)}">${esc(LOCK_LABELS[status] || status)}</span>`;
  /** Sperrfehler und Warnungen als kompakte Listen. */
  const issueList = (errors, warnings, collapsed = false) => collapsed
    ? (errors.length || warnings.length ? `<details class="time-issues-details"><summary>${[errors.length ? `<span class="time-issue-count is-error">✕ ${errors.length} Sperrfehler</span>` : "", warnings.length ? `<span class="time-issue-count is-warning">⚠ ${warnings.length} ${warnings.length === 1 ? "Hinweis" : "Hinweise"}</span>` : ""].filter(Boolean).join(" · ")}</summary>${issueList(errors, warnings)}</details>` : "")
    : `${errors.length ? `<ul class="time-issues is-error">${errors.map((item) => `<li>${esc(item.message)}</li>`).join("")}</ul>` : ""}
    ${warnings.length ? `<details class="time-issues-details"${errors.length ? "" : " open"}><summary>${warnings.length} ${warnings.length === 1 ? "Hinweis" : "Hinweise"}</summary><ul class="time-issues is-warning">${warnings.map((item) => `<li>${esc(item.message)}</li>`).join("")}</ul></details>` : ""}`;
  /** Nachweise eines Zeitraums mit Revision; ersetzte gekennzeichnet. */
  const statementList = (statements) => (statements.length
    ? `<ul class="time-statements">${statements.map((item) => `<li${item.current ? "" : ' class="is-superseded"'}><a href="${esc(item.url)}" target="_blank" rel="noopener" data-icon="file-down">${esc(item.type === "week" ? "Woche" : "Monat")} ${esc(item.label)} · Revision ${item.revision}</a> <small class="muted">${item.current ? "aktuell" : "ersetzt – nicht mehr aktuell"}</small></li>`).join("")}</ul>`
    : "");

  // ---------------------------------------------------------------------------
  // Prüfhinweise je Tag und Abwesenheiten (reine Darstellung, auch für Tests)
  // ---------------------------------------------------------------------------
  /** Sperrfehler und Warnungen des Servers nach Datum gruppiert. */
  const issuesByDate = (period) => {
    const map = {};
    for (const [kind, items] of [["errors", period?.errors || []], ["warnings", period?.warnings || []]]) {
      for (const item of items) {
        if (!item.date) continue;
        map[item.date] ??= { errors: [], warnings: [] };
        map[item.date][kind].push(item);
      }
    }
    return map;
  };
  /** Kennzeichen am Kalendertag: Sperrfehler (rot, ✕) vor Warnungen (orange, ⚠), sonst nichts. */
  const issueFlag = (issues) => {
    if (issues?.errors.length) return `<span class="time-cal-flag time-issue-flag is-error" title="${issues.errors.length} Sperrfehler">✕</span>`;
    if (issues?.warnings.length) return `<span class="time-cal-flag time-issue-flag is-warning" title="${issues.warnings.length} ${issues.warnings.length === 1 ? "Hinweis" : "Hinweise"}">⚠</span>`;
    return "";
  };
  /** Dauer einer Abwesenheit: „ganztägig“ oder Stunden. */
  const absenceDuration = (absence) => (absence.fullDay ? "ganztägig" : hours(absence.minutes));
  /** Abwesenheiten kurz für Kalenderzellen. */
  const absenceShort = (absences = []) => absences.map((absence) => `<small class="time-cal-absence is-${esc(absence.type)}">${esc(absence.label)}${absence.fullDay ? "" : ` ${hours(absence.minutes).replace(" h", "")}`}</small>`).join("");
  /** Summen je Abwesenheitsart (Tage bzw. Stunden), nur vorhandene. */
  const absenceSummary = (totals = {}) => Object.values(totals).filter((item) => item.days || item.minutes)
    .map((item) => `${item.label} ${[item.days ? `${item.days} ${item.days === 1 ? "Tag" : "Tage"}` : "", item.minutes ? hours(item.minutes) : ""].filter(Boolean).join(", ")}`).join(" · ");

  /** Kurzbeschreibung der Reiseangaben eines Tages (Kategorie, Ort, Dauer, Übernachtung, Mahlzeiten). */
  const travelSummary = (travel, categories) => {
    if (!travel || travel.category === "none") return travel?.notes || "";
    const meals = [["breakfast", "Frühstück"], ["lunch", "Mittagessen"], ["dinner", "Abendessen"]].filter(([key]) => travel.meals[key]).map(([, label]) => label);
    return [categories[travel.category] || travel.categoryLabel, travel.abroad ? "Ausland" : "", travel.location, travel.absenceMinutes !== null ? `Abwesenheit ${hours(travel.absenceMinutes)}` : "",
      travel.overnight ? "Übernachtung" : "", meals.length ? `gestellt: ${meals.join(", ")}` : ""].filter(Boolean).join(" · ");
  };
  /** Fahrt als Zeile: Strecke · Zweck · Auftrag/Kostenstelle. */
  const tripLabel = (trip) => [trip.route, trip.purpose, trip.orderNumber || trip.costCenterNumber].filter(Boolean).join(" · ");

  globalThis.CalightTimeTracking = Object.freeze({ issuesByDate, issueFlag, absenceDuration, absenceShort, absenceSummary, travelSummary, tripLabel, addDays, weekStart, monthRange, addMonths, hours, weekday, toMinutes, toTime, isoWeek, monthGrid, travelShort, netFromTimes, entryRow, lockFlag, lockBadge, issueList, statementList });

  const root = document.querySelector("#time-tracking-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const content = root.querySelector("[data-content]");
  const employeeSelect = root.querySelector("[data-employee]");
  const params = new URLSearchParams(location.search);
  const state = {
    view: ["day", "week", "month", "report", "approvals"].includes(params.get("view")) ? params.get("view") : "week",
    date: /^\d{4}-\d{2}-\d{2}$/.test(params.get("date") || "") ? params.get("date") : localToday(),
    employee: params.get("employee") || "",
  };
  let reference = null;
  let current = null;
  let currentPeriod = null;
  const money = (value) => format.money(value);
  const km = (value) => (value === null || value === undefined || value === "" ? "–" : `${format.number(value, 1)} km`);
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
    employeeSelect.closest("[data-employee-field]").hidden = !reference.canManage || state.view === "report" || state.view === "approvals";
    root.querySelector(".time-period").hidden = state.view === "approvals";
    try {
      if (state.view === "day") await renderDay();
      else if (state.view === "report") await renderReport();
      else if (state.view === "approvals") await renderApprovals();
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
  /** Herkunft der Summe (Rapport, Tagesbericht, manuell) als dezente Zeile. */
  const sourceLine = (totals) => {
    if (!totals.bySource) return "";
    const parts = Object.entries(reference.sourceKinds).map(([kind, label]) => (totals.bySource[kind] ? `${label} ${hours(totals.bySource[kind])}` : "")).filter(Boolean);
    return parts.length > 1 ? `<p class="time-source-line">${parts.join(" · ")}</p>` : "";
  };
  const calendarCell = (day, { outside = false, showWeek = false, issues = null } = {}) => {
    const today = day.date === localToday();
    const week = showWeek ? `<span class="time-cal-week">KW ${isoWeek(day.date).week}</span>` : "";
    const selected = day.date === state.date;
    const untimed = day.untimedMinutes ? `, davon ${hours(day.untimedMinutes)} ohne Uhrzeit` : "";
    return `<button type="button" class="time-cal-day${issues?.errors.length ? " has-error" : issues?.warnings.length ? " has-warning" : ""}${day.absences?.length ? " has-absence" : ""}${today ? " is-today" : ""}${selected ? " is-selected" : ""}${outside ? " is-outside" : ""}${day.netMinutes ? " has-time" : ""}" data-open-day="${day.date}"
        ${selected ? 'aria-current="date" ' : ""}aria-label="${weekday(day.date)} ${format.date(day.date)}: ${day.netMinutes ? hours(day.netMinutes) + untimed : "keine Einträge"}${day.lockStatus && day.lockStatus !== "open" ? `, ${LOCK_LABELS[day.lockStatus]}` : ""}${(day.absences || []).map((absence) => `, ${absence.label}`).join("")}${issues?.errors.length ? ", Sperrfehler" : issues?.warnings.length ? ", Hinweise" : ""}">
        <span class="time-cal-head"><span class="time-cal-weekday">${weekday(day.date)}</span><span class="time-cal-date">${Number(day.date.slice(8, 10))}.${showWeek || state.view === "week" ? `${Number(day.date.slice(5, 7))}.` : ""}</span>${week}</span>
        <strong class="time-cal-hours">${day.netMinutes ? hours(day.netMinutes).replace(" h", "") : "–"}</strong>
        <small class="time-cal-travel">${travelShort(day.travelMinutes)}</small>${absenceShort(day.absences)}
        <span class="time-cal-flags" aria-hidden="true">${issueFlag(issues)}${lockFlag(day.lockStatus)}${day.untimedMinutes ? `<span class="time-cal-flag${day.incompleteMinutes ? " is-incomplete" : ""}" title="${hours(day.untimedMinutes)} ohne Uhrzeit">◷</span>` : ""}${day.privateKm || day.expenseTotal || (day.mealAllowance && Number(day.mealAllowance) > 0) ? '<span class="time-cal-flag" title="Reisekosten oder Auslagen erfasst">•</span>' : ""}</span>
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
    const [data, monthData, period] = await Promise.all([
      api(`/api/time-tracking/range?from=${from}&to=${to}${employeeQuery()}`),
      week ? null : api(`/api/time-tracking/range?from=${monthFrom}&to=${monthTo}${employeeQuery()}`),
      api(`/api/time-tracking/period?type=${week ? "week" : "month"}&date=${week ? from : monthFrom}${employeeQuery()}`),
    ]);
    currentPeriod = period;
    const issues = issuesByDate(period);
    const byDate = Object.fromEntries(data.days.map((day) => [day.date, day]));
    const inMonth = (date) => date >= monthFrom && date <= monthTo;
    const totals = week ? data.totals : monthData.totals;
    const head = '<div class="time-cal-weekdays" aria-hidden="true">' + ["Mo", "Di", "Mi", "Do", "Fr", "Sa", "So"].map((name) => `<span>${name}</span>`).join("") + "</div>";
    const body = week
      ? `<div class="time-cal-row">${data.days.map((day) => calendarCell(day, { issues: issues[day.date] })).join("")}</div>`
      : grid.map((days) => `<div class="time-cal-row">${days.map((date, index) => calendarCell(byDate[date] || { date, netMinutes: 0, travelMinutes: 0 }, { outside: !inMonth(date), showWeek: index === 0, issues: issues[date] })).join("")}</div>`).join("");
    content.innerHTML = `${summaryTiles(totals, week ? "" : `<div><span>Privat-Pkw</span><strong>${km(totals.privateKm)}</strong></div>${totals.mealAllowance !== null && totals.mealAllowance !== undefined ? `<div><span>Verpflegung</span><strong>${money(totals.mealAllowance)}</strong></div>` : ""}<div><span>Auslagen</span><strong>${money(totals.expenseTotal)}</strong></div>`)}
      ${absenceSummary(totals.absences) ? `<p class="time-source-line">Abwesenheiten: ${esc(absenceSummary(totals.absences))}</p>` : ""}
      <div class="time-calendar ${week ? "is-week" : "is-month"}" role="group" aria-label="${week ? "Wochenkalender" : "Monatskalender"}">${head}${body}</div>
      ${periodPanel(period)}`;
  }

  /**
   * Freigabe des angezeigten Zeitraums: Status, Prüfergebnis, Einreichen, Wiederöffnen (Büro),
   * Nachweise mit Revisionen und Protokoll. Einreichen nur, wenn der Rhythmus den Zeitraum erlaubt.
   */
  function periodPanel(period) {
    const typeLabel = period.period.type === "week" ? "Woche" : "Monat";
    const allowed = period.allowedTypes.includes(period.period.type);
    const openDays = (period.statusCounts.open || 0) + (period.statusCounts.reopened || 0);
    const actions = [
      allowed && openDays && !Calight.moduleReadOnly ? `<button class="button button-small" type="button" data-submit-period${period.canSubmit ? "" : " disabled"} data-icon="file-up">${typeLabel} einreichen</button>` : "",
      period.canReopen && !Calight.moduleReadOnly ? '<button class="button button-light button-small" type="button" data-reopen-period data-icon="undo-2">Wieder öffnen</button>' : "",
    ].join("");
    const events = { submit: "Eingereicht", approve: "Festgeschrieben", return: "Zur Korrektur zurückgegeben", reopen: "Wieder geöffnet" };
    return `<section class="card form-stack time-approval">
      <div class="step-section-heading">
        <div><h2>Freigabe ${esc(period.period.label)}</h2><small>${lockBadge(period.status)}${period.status === "mixed" ? ` <span class="muted">${Object.entries(period.statusCounts).map(([key, count]) => `${count} ${LOCK_LABELS[key]}`).join(" · ")}</span>` : ""}</small></div>
        <div class="step-section-actions">${actions}</div>
      </div>
      ${openDays ? issueList(period.errors, period.warnings, true) : ""}
      ${!allowed && openDays ? `<p class="muted">Eingereicht wird laut Betriebseinstellung ${period.rhythm === "week" ? "wöchentlich" : "monatlich"}.</p>` : ""}
      ${statementList(period.statements)}
      ${period.events.length ? `<details class="time-issues-details"><summary>Protokoll</summary><ul class="time-events">${period.events.map((event) => `<li>${format.dateTime(event.at)} · ${esc(events[event.action] || event.action)} (${format.date(event.from)}–${format.date(event.to)}) von ${esc(event.userName)}${event.reason ? ` – ${esc(event.reason)}` : ""}</li>`).join("")}</ul></details>` : ""}
    </section>`;
  }

  /** Vor dem Einreichen: kompakte Zusammenfassung mit Stunden, Hinweisen und Sperrfehlern. */
  async function submitPeriod() {
    const period = currentPeriod;
    const form = document.createElement("form");
    form.className = "form-stack";
    form.innerHTML = `<div class="time-summary"><div><span>Arbeitszeit</span><strong>${hours(period.totals.netMinutes)}</strong></div><div><span>Davon Reisezeit</span><strong>${hours(period.totals.travelMinutes)}</strong></div><div><span>Auslagen</span><strong>${money(period.totals.expenseTotal)}</strong></div></div>
      ${issueList(period.errors, period.warnings)}
      <p class="muted">Nach dem Einreichen sind Arbeitszeiten, Abwesenheiten, Reiseangaben und Auslagen dieser Tage gesperrt; das Büro prüft und schreibt sie fest. Auch arbeitsfreie Tage werden gesperrt.</p>
      <label class="check-inline"><input type="checkbox" name="confirmComplete" required> Ich bestätige, dass die Angaben für diesen Zeitraum vollständig sind.</label>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.createDialog({
      title: `${period.period.type === "week" ? "Woche" : "Monat"} ${period.period.label} einreichen`,
      form,
      submitLabel: "Einreichen",
      submit: async () => {
        await api("/api/time-tracking/submissions", { method: "POST", body: { type: period.period.type, date: period.period.from, employeeId: state.employee, confirmComplete: form.elements.confirmComplete.checked } });
        notify(message, "Zeiten eingereicht.", "success");
        await renderRange();
      },
    });
  }

  /** Büro: festgeschriebene Tage gezielt zur Korrektur öffnen (Begründung Pflicht). */
  function reopenDialog(employeeId, from, to, after) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<div class="dialog-form-grid">
        <label><span class="field-label">Von</span><input name="from" type="date" required value="${esc(from)}"></label>
        <label><span class="field-label">Bis</span><input name="to" type="date" required value="${esc(to)}"></label>
        <label class="span-all"><span class="field-label">Begründung <span aria-hidden="true">*</span></span><textarea name="reason" rows="2" maxlength="1000" required></textarea></label>
      </div>
      <p class="muted">Der bisherige Nachweis bleibt unverändert erhalten und wird als nicht mehr aktuell gekennzeichnet. Nach der Korrektur sind erneute Einreichung und Festschreibung erforderlich.</p>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.createDialog({
      title: "Zur Korrektur öffnen",
      form,
      submitLabel: "Öffnen",
      discardMessage: "Eingaben verwerfen?",
      initialFocus: '[name="reason"]',
      submit: async () => {
        await api("/api/time-tracking/reopen", { method: "POST", body: { employeeId, from: form.elements.from.value, to: form.elements.to.value, reason: form.elements.reason.value.trim() } });
        notify(message, "Zeitraum zur Korrektur geöffnet.", "success");
        await after();
      },
    });
  }

  // ---------------------------------------------------------------------------
  // Freigaben (Büro)
  // ---------------------------------------------------------------------------
  async function renderApprovals() {
    const data = await api("/api/time-tracking/approvals");
    const card = (item) => `<article class="card form-stack time-approval-item" data-submission="${esc(item.id)}">
        <div class="step-section-heading">
          <div><h2>${esc(item.employeeName)} · ${esc(item.type === "week" ? "Woche" : "Monat")} ${esc(item.label)}</h2>
            <small>Eingereicht ${format.dateTime(item.submittedAt)} von ${esc(item.submittedByName)}</small></div>
          <div class="step-section-actions"><button class="button button-light button-small" type="button" data-open-period="${esc(item.employeeId)}" data-period-type="${esc(item.type)}" data-period-date="${esc(item.from)}" data-icon="calendar-days">Zeiten ansehen</button></div>
        </div>
        <div class="time-summary"><div><span>Arbeitszeit</span><strong>${hours(item.totals.netMinutes)}</strong></div><div><span>Davon Reisezeit</span><strong>${hours(item.totals.travelMinutes)}</strong></div><div><span>Auslagen</span><strong>${money(item.totals.expenseTotal)}</strong></div></div>
        ${issueList(item.errors, item.warnings)}
        ${Calight.moduleReadOnly ? "" : `        ${item.warnings.length ? '<label class="check-inline"><input type="checkbox" data-acknowledge> Hinweise geprüft und bestätigt</label>' : ""}
        <label><span class="field-label">Kommentar (bei Rückgabe Pflicht; bei erheblichen Auffälligkeiten empfohlen)</span><textarea rows="2" maxlength="1000" data-comment></textarea></label>
        <div class="time-approval-actions">
          <button class="button" type="button" data-approve="${esc(item.id)}" data-version="${item.version}"${item.errors.length ? " disabled" : ""} data-icon="clipboard-check">Festschreiben</button>
          <button class="button button-light" type="button" data-return="${esc(item.id)}" data-version="${item.version}" data-icon="undo-2">Zur Korrektur zurückgeben</button>
        </div>
`}
      </article>`;
    const decided = { approved: "Festgeschrieben", returned: "Zurückgegeben" };
    content.innerHTML = `${data.items.length ? data.items.map(card).join("") : '<p class="empty-state">Keine eingereichten Zeiträume.</p>'}
      ${data.recent.length ? `<section class="card form-stack"><h2>Zuletzt bearbeitet</h2><ul class="time-events">${data.recent.map((item) => `<li>${esc(item.employeeName)} · ${esc(item.label)}: ${esc(decided[item.status] || item.status)} ${item.decidedAt ? format.dateTime(item.decidedAt) : ""} ${item.decidedByName ? `von ${esc(item.decidedByName)}` : ""}${item.comment ? ` – ${esc(item.comment)}` : ""}</li>`).join("")}</ul></section>` : ""}`;
  }

  async function approvalAction(button, kind) {
    const card = button.closest("[data-submission]");
    try {
      if (kind === "approve") {
        await api(`/api/time-tracking/submissions/${encodeURIComponent(button.dataset.approve)}/approve`, { method: "POST", body: {
          version: Number(button.dataset.version), acknowledgeWarnings: Boolean(card.querySelector("[data-acknowledge]")?.checked), comment: card.querySelector("[data-comment]").value.trim(),
        } });
        notify(message, "Zeiten festgeschrieben, Nachweis archiviert.", "success");
      } else {
        const reason = card.querySelector("[data-comment]").value.trim();
        if (!reason) {
          notify(message, "Bitte im Kommentarfeld eine Begründung für die Rückgabe angeben.");
          card.querySelector("[data-comment]").focus();
          return;
        }
        await api(`/api/time-tracking/submissions/${encodeURIComponent(button.dataset.return)}/return`, { method: "POST", body: { version: Number(button.dataset.version), reason } });
        notify(message, "Zur Korrektur zurückgegeben.", "success");
      }
      await renderApprovals();
    } catch (exception) {
      notify(message, exception.message);
    }
  }

  // ---------------------------------------------------------------------------
  // Tag
  // ---------------------------------------------------------------------------
  async function renderDay() {
    root.querySelector("[data-period-label]").textContent = `${weekday(state.date)}, ${format.date(state.date)}`;
    const [dayData, dayPeriod] = await Promise.all([
      api(`/api/time-tracking/day?date=${state.date}${employeeQuery()}`),
      api(`/api/time-tracking/period?type=week&date=${state.date}${employeeQuery()}`).catch(() => null),
    ]);
    current = dayData;
    const day = current;
    // Prüfhinweise genau dieses Tages (serverseitige Prüfung des Zeitraums).
    const dayIssues = issuesByDate(dayPeriod)[state.date] || { errors: [], warnings: [] };
    const editable = day.canEdit && !Calight.moduleReadOnly;
    const travel = day.travel;
    const travelText = travel ? travelSummary(travel, reference.travelCategories) : "";
    content.innerHTML = `
      ${day.lockStatus && day.lockStatus !== "open" ? `<p class="time-lock-note">${lockBadge(day.lockStatus)} ${day.lockStatus === "reopened" ? "Nach der Korrektur bitte erneut einreichen." : "Bearbeitung gesperrt."}</p>` : ""}
      ${summaryTiles(day.totals)}
      ${dayIssues.errors.map((item) => `<p class="time-hint is-error" role="note"><span aria-hidden="true">✕</span> ${esc(item.message.replace(/^\d{2}\.\d{2}\.\d{4}: /, ""))}</p>`).join("")}
      ${dayIssues.warnings.map((item) => item.message.replace(/^\d{2}\.\d{2}\.\d{4}: /, "")).map((warning) => `<p class="time-hint" role="note"><span aria-hidden="true">⚠</span> ${esc(warning)}</p>`).join("")}
      ${!day.employee.active ? '<p class="notice notice-warning">Dieser Mitarbeiter ist archiviert.</p>' : ""}
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Arbeitszeiten</h2>${reference.canManage && state.employee ? `<small>${esc(day.employee.name)}</small>` : ""}</div>
          <div class="step-section-actions">${editable ? '<button class="button" type="button" data-add-entry data-icon="plus">Erfassen</button>' : ""}</div>
        </div>
        ${day.absences.length ? `<ul class="time-blocks">${day.absences.map((absence) => `<li class="time-block is-absence is-${esc(absence.type)}">
            <span class="time-block-range">${esc(absenceDuration(absence))}</span>
            <span class="time-block-main"><span class="time-block-title">${esc(absence.label)}</span><span class="time-block-meta">Abwesenheit${absence.notes ? ` · ${esc(absence.notes)}` : ""}</span></span>
            <span class="time-block-net"><small>keine Arbeitszeit</small></span>
            <span class="time-block-actions">${absence.canEdit && !Calight.moduleReadOnly ? `<button class="icon-button" type="button" data-edit-absence="${esc(absence.id)}" data-icon="square-pen" title="Abwesenheit bearbeiten" aria-label="Abwesenheit bearbeiten"></button><button class="icon-button icon-button-danger" type="button" data-delete-absence="${esc(absence.id)}" data-icon="trash" title="Abwesenheit entfernen" aria-label="Abwesenheit entfernen"></button>` : ""}</span>
          </li>`).join("")}</ul>` : ""}
        ${day.entries.length ? `<ul class="time-blocks">${day.entries.map(entryRow).join("")}</ul>` : day.absences.length ? "" : '<p class="empty-state">Für diesen Tag sind noch keine Arbeitszeiten oder Abwesenheiten erfasst.</p>'}
        ${day.carriedOver.map((block) => `<p class="time-carried">Fortsetzung vom Vortag (${esc(block.sourceLabel)}) ${esc(block.start)}–${esc(block.end)}: anteilig ${hours(block.netMinutes)}${block.travelMinutes ? `, davon ${hours(block.travelMinutes)} Reise` : ""} (in den Summen enthalten)</p>`).join("")}
      </section>
      <section class="card form-stack">
        <div class="step-section-heading">
          <div><h2>Reisekosten und Auslagen</h2><small>${esc(travelText) || "Auswärtstätigkeit, Verpflegung, Privat-Pkw-Fahrten und Auslagen (optional)"}</small></div>
          <div class="step-section-actions">${editable ? `<button class="button button-light button-small" type="button" data-details data-icon="square-pen">${travel || day.expenses.length ? "Bearbeiten" : "Erfassen"}</button>` : ""}</div>
        </div>
        ${travel && travel.category !== "none" ? `<p class="time-travel-amount">${travel.allowance !== null ? `Verpflegung (rechnerisch): <strong>${money(travel.allowance)}</strong>${travel.allowanceReduction && Number(travel.allowanceReduction) > 0 ? ` <small class="muted">nach Kürzung um ${money(travel.allowanceReduction)} für gestellte Mahlzeiten</small>` : ""}` : `<span class="muted">${esc(travel.allowanceNote)}</span>`}</p>` : ""}
        ${day.trips.length ? `<ul class="time-expenses">${day.trips.map((trip) => `<li><span>${esc(tripLabel(trip))}</span><strong>${km(trip.kilometers)}</strong></li>`).join("")}</ul><p class="time-expense-total">Privat-Pkw gesamt: <strong>${km(travel.privateKm)}</strong> <small class="muted">rechnerisch ${money(travel.kmAmount)}</small></p>` : ""}
        ${day.travelWarnings.map((warning) => `<p class="time-hint" role="note"><span aria-hidden="true">⚠</span> ${esc(warning)}</p>`).join("")}
        ${day.expenses.length ? `<ul class="time-expenses">${day.expenses.map((expense) => `<li><span>${esc(expense.purpose)}${expense.orderNumber || expense.costCenterNumber ? ` <small class="muted">· ${esc(expense.orderNumber || expense.costCenterNumber)}</small>` : ""}${expense.hasReceipt ? ` <a href="/api/time-tracking/expenses/${encodeURIComponent(expense.id)}/receipt" target="_blank" rel="noopener" data-icon="file-down">Beleg</a>` : ""}</span><strong>${money(expense.amount)}</strong></li>`).join("")}</ul><p class="time-expense-total">Auslagen gesamt: <strong>${money(day.expenseTotal)}</strong></p>` : ""}
      </section>`;
  }

  // ---------------------------------------------------------------------------
  // Dialoge
  // ---------------------------------------------------------------------------
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
  /**
   * Erfassungsdialog mit zwei Arten: „Arbeitszeit“ (Uhrzeiten/Dauer, Pause, Reisezeit, Einsatzart,
   * Auftrag/Kostenstelle, Bemerkung) und „Abwesenheit“ (Art, ganztägig oder Stunden, Bemerkung).
   * Es werden nur die Felder der gewählten Art angezeigt. Berichtsbuchungen: nur Uhrzeiten ergänzen.
   */
  function entryDialog(entry = null, preset = {}, absence = null) {
    const fixed = entry?.durationFixed ? entry.netMinutes : null;
    const timed = current.entries.filter((item) => item.hasTimes);
    const lastEnd = timed.length ? timed[timed.length - 1].end : "07:00";
    // Einsatzort und -art des vorigen Blocks vorschlagen (jederzeit änderbar).
    const lastEntry = current.entries.length ? current.entries[current.entries.length - 1] : null;
    const lastAssignment = lastEntry?.assignmentKind || "";
    const values = {
      start: entry ? entry.start || "" : preset.start ?? (fixed === null ? lastEnd : ""), end: entry?.end ?? "", breakMinutes: entry?.breakMinutes ?? 0, travelMinutes: entry?.travelMinutes ?? 0,
      assignmentKind: entry ? entry.assignmentKind || "" : preset.assignmentKind ?? lastAssignment,
      workLocation: entry ? entry.workLocation || "" : preset.workLocation ?? lastEntry?.workLocation ?? "",
      orderId: entry?.orderId ?? preset.orderId ?? "", costCenterId: entry?.costCenterId ?? preset.costCenterId ?? "", notes: entry?.notes ?? "",
      duration: entry && !entry.hasTimes && fixed === null ? hours(entry.netMinutes).replace(" h", "") : "",
    };
    const chooseKind = !entry && !absence;
    let kind = absence ? "absence" : preset.kind || "work";
    // Manuelle Zeitblöcke immer mit Uhrzeiten; nur Berichtsstunden (fixed) ohne.
    const timesRequired = fixed === null;
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      ${chooseKind ? `<div class="segmented time-kind-switch" role="radiogroup" aria-label="Art">
        <label><input type="radio" name="kind" value="work"${kind === "work" ? " checked" : ""}><span>Arbeitszeit</span></label>
        <label><input type="radio" name="kind" value="absence"${kind === "absence" ? " checked" : ""}><span>Abwesenheit</span></label></div>` : ""}
      <div class="form-stack" data-kind="work">
      ${entry?.changedByOther ? `<p class="time-dialog-meta">Zuletzt geändert von ${esc(entry.updatedByName)} am ${format.dateTime(entry.updatedAt)}</p>` : ""}
      ${fixed !== null ? `<p class="notice">${esc(entry.reportLabel)}: <strong>${hours(fixed)}</strong> laut Bericht. Die Dauer wird im Bericht geändert; hier werden nur Uhrzeiten, Pause, Reisezeit und Einsatzart ergänzt. Ende − Beginn − Pause muss genau ${hours(fixed)} ergeben.</p>` : ""}
      <div class="time-entry-grid">
        <label><span class="field-label">Beginn${timesRequired ? ' <span aria-hidden="true">*</span>' : " (optional)"}</span><input name="start" type="time"${timesRequired ? " required" : ""} value="${esc(values.start)}"></label>
        <label><span class="field-label">Ende${timesRequired ? ' <span aria-hidden="true">*</span>' : " (optional)"}</span><input name="end" type="time"${timesRequired ? " required" : ""} value="${esc(values.end)}"></label>
        <label><span class="field-label">Pause (Min.)</span><input name="breakMinutes" type="number" min="0" step="5" inputmode="numeric" value="${esc(values.breakMinutes)}"></label>
        <label><span class="field-label">Davon Reisezeit (Min.)</span><input name="travelMinutes" type="number" min="0" step="5" inputmode="numeric" value="${esc(values.travelMinutes)}"></label>
        ${fixed === null && !timesRequired ? `<label data-duration-field><span class="field-label">Dauer ohne Uhrzeiten</span><input name="duration" inputmode="decimal" placeholder="z. B. 1:30 oder 1,5" value="${esc(values.duration)}"></label>` : ""}
      </div>
      <p class="muted time-net-preview" data-net></p>
      ${fixed === null ? `<label><span class="field-label">Auftrag / Kostenstelle (optional)</span><select name="costTarget"></select></label>` : ""}
      ${Calight.workLocationFields(values)}
      <label><span class="field-label">Bemerkung (optional)</span><input name="notes" maxlength="500" value="${esc(values.notes)}" placeholder="${fixed === null ? "z. B. Angebotsbearbeitung, Werkstatt" : "Ergänzung zur Zeiterfassung"}"></label>
      </div>
      ${entry ? "" : `<div class="form-stack" data-kind="absence">
        <label><span class="field-label">Abwesenheitsart <span aria-hidden="true">*</span></span><select name="absenceType" required>${Object.entries(reference.absenceTypes).map(([key, label]) => `<option value="${key}"${key === (absence?.type ?? "vacation") ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
        <div class="segmented" role="radiogroup" aria-label="Umfang">
          <label><input type="radio" name="absenceScope" value="full"${absence && !absence.fullDay ? "" : " checked"}><span>Ganztägig</span></label>
          <label><input type="radio" name="absenceScope" value="hours"${absence && !absence.fullDay ? " checked" : ""}><span>Stunden</span></label>
        </div>
        <label data-absence-hours><span class="field-label">Dauer (Stunden) <span aria-hidden="true">*</span></span><input name="absenceHours" inputmode="decimal" placeholder="z. B. 4 oder 4:30" value="${esc(absence?.minutes ? hours(absence.minutes).replace(" h", "") : "")}"></label>
        <label><span class="field-label">Bemerkung (optional, keine medizinischen Angaben)</span><input name="absenceNotes" maxlength="500" value="${esc(absence?.notes ?? "")}"></label>
        <p class="muted time-travel-note">Abwesenheiten zählen nicht als Arbeitszeit und haben keinen Auftrag. Ganztägig ohne Stundenzahl – es wird keine Sollarbeitszeit angenommen.</p>
      </div>`}
      <p class="field-error" data-dialog-error hidden></p>`;
    const showKind = () => {
      form.querySelectorAll("[data-kind]").forEach((section) => {
        const active = section.dataset.kind === kind;
        section.hidden = !active;
        section.querySelectorAll("input, select").forEach((input) => (input.disabled = !active));
      });
      const hoursField = form.querySelector("[data-absence-hours]");
      if (hoursField) {
        const byHours = form.querySelector('[name="absenceScope"]:checked')?.value === "hours";
        hoursField.hidden = !byHours;
        form.elements.absenceHours.disabled = !byHours || kind !== "absence";
        form.elements.absenceHours.required = byHours;
      }
    };
    form.addEventListener("change", (event) => {
      if (event.target.name === "kind") kind = event.target.value;
      if (["kind", "absenceScope"].includes(event.target.name)) showKind();
    });
    const net = () => {
      if (kind !== "work") return;
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
    // Ein gemeinsames Suchfeld für Kundenaufträge und allgemeine Kostenstellen.
    if (form.elements.costTarget) Calight.costTargetSelect(form.elements.costTarget, reference.costTargets, { orderId: values.orderId, costCenterId: values.costCenterId });
    showKind();
    const body = () => {
      const timesGiven = Boolean(form.elements.start.value || form.elements.end.value);
      const duration = form.elements.duration && !timesGiven ? parseDuration(form.elements.duration.value) : NaN;
      return {
        date: state.date, employeeId: state.employee, start: form.elements.start.value, end: form.elements.end.value,
        breakMinutes: timesGiven ? form.elements.breakMinutes.value : 0, travelMinutes: form.elements.travelMinutes.value, ...Calight.readWorkLocation(form),
        ...Calight.parseCostTarget(form.elements.costTarget?.value ?? ""), notes: form.elements.notes.value.trim(),
        ...(Number.isNaN(duration) ? {} : { durationMinutes: duration }),
        ...(entry ? { version: entry.version } : {}),
      };
    };
    const absenceBody = () => ({
      date: state.date, employeeId: state.employee, absenceType: form.elements.absenceType.value,
      fullDay: form.querySelector('[name="absenceScope"]:checked').value === "full", hours: form.elements.absenceHours.value.trim().replace(",", "."),
      notes: form.elements.absenceNotes.value.trim(), ...(absence ? { version: absence.version } : {}),
    });
    const save = async () => {
      if (kind === "absence") {
        current = absence
          ? await api(`/api/time-tracking/absences/${encodeURIComponent(absence.id)}`, { method: "PUT", body: absenceBody() })
          : await api("/api/time-tracking/absences", { method: "POST", body: absenceBody() });
        return;
      }
      current = entry
        ? await api(`/api/time-tracking/entries/${encodeURIComponent(entry.id)}`, { method: "PUT", body: body() })
        : await api("/api/time-tracking/entries", { method: "POST", body: body() });
    };
    Calight.createDialog({
      title: absence ? "Abwesenheit bearbeiten" : fixed !== null ? "Uhrzeiten ergänzen" : entry ? "Zeitblock bearbeiten" : "Erfassen",
      form,
      submitLabel: "Speichern",
      discardMessage: "Eingaben verwerfen?",
      initialFocus: absence ? '[name="absenceType"]' : '[name="start"]',
      // Schnelle wiederholte Erfassung: nächster Block beginnt mit dem Ende des vorigen.
      extraActions: entry || absence ? [] : [{
        label: "Speichern und weiter",
        onClick: async (run) => {
          const next = kind === "work"
            ? { start: form.elements.end.value, ...Calight.parseCostTarget(form.elements.costTarget.value), ...Calight.readWorkLocation(form) }
            : { kind: "absence" };
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

  /**
   * „Reisekosten und Auslagen“ eines Tages: 1. Auswärtstätigkeit und Verpflegung, 2. dienstliche
   * Privat-Pkw-Fahrten, 3. Auslagen. Fahrten und Auslagen werden einzeln sofort gespeichert;
   * Beträge berechnet ausschließlich der Server aus den Betriebseinstellungen.
   */
  function detailsDialog() {
    const details = current.details || { travelCategory: "none", abroad: false, absenceMinutes: null, overnight: false, mealBreakfast: false, mealLunch: false, mealDinner: false, location: "", notes: "" };
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    const absence = details.absenceMinutes === null || details.absenceMinutes === undefined ? "" : hours(details.absenceMinutes).replace(" h", "");
    form.innerHTML = `
      <section class="time-travel-section"><h3 class="purchase-subheading">Auswärtstätigkeit und Verpflegung</h3>
        <div class="dialog-form-grid">
          <label class="span-all"><span class="field-label">Reisetag</span><select name="travelCategory">${Object.entries(reference.travelCategories).map(([key, label]) => `<option value="${key}"${details.travelCategory === key ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
          <label class="span-all" data-travel-only><span class="field-label">Einsatzort / Reiseziel</span><input name="location" maxlength="200" value="${esc(details.location)}"></label>
          <label data-travel-only><span class="field-label">Abwesenheitsdauer (optional)</span><input name="absence" inputmode="decimal" placeholder="z. B. 9:30" value="${esc(absence)}"></label>
          <div class="time-travel-checks" data-travel-only>
            <label class="check-inline"><input type="checkbox" name="abroad"${details.abroad ? " checked" : ""}> Ausland</label>
            <label class="check-inline"><input type="checkbox" name="overnight"${details.overnight ? " checked" : ""}> Auswärtige Übernachtung</label>
          </div>
          <fieldset class="span-all time-travel-checks" data-travel-only><legend class="field-label">Gestellte Mahlzeiten</legend>
            <label class="check-inline"><input type="checkbox" name="mealBreakfast"${details.mealBreakfast ? " checked" : ""}> Frühstück</label>
            <label class="check-inline"><input type="checkbox" name="mealLunch"${details.mealLunch ? " checked" : ""}> Mittagessen</label>
            <label class="check-inline"><input type="checkbox" name="mealDinner"${details.mealDinner ? " checked" : ""}> Abendessen</label>
          </fieldset>
          <label class="span-all"><span class="field-label">Bemerkung</span><textarea name="notes" rows="2" maxlength="1000">${esc(details.notes)}</textarea></label>
        </div>
        <p class="muted time-travel-note">Arbeitszeit und Abwesenheit sind verschiedene Größen; die Pauschale ergibt sich aus der gewählten Kategorie${reference.travelSettings.mealAllowances ? "" : " (Verpflegungspauschalen sind nicht aktiviert)"}. Calight zeigt einen rechnerischen Referenzbetrag, keine steuerliche Freigabe. Über Mitternacht ohne Übernachtung bitte nur einen Tag einstufen.</p>
      </section>
      <section class="time-travel-section"><h3 class="purchase-subheading">Dienstliche Privat-Pkw-Fahrten</h3>
        <div data-trip-list></div>
        <p class="muted time-travel-note">Nur dienstliche Fahrten – keine Fahrten zwischen Wohnung und erster Tätigkeitsstätte.</p>
      </section>
      <section class="time-travel-section"><h3 class="purchase-subheading">Auslagen</h3>
        <div data-expense-list></div>
      </section>
      <p class="field-error" data-dialog-error hidden></p>`;
    const toggle = () => form.querySelectorAll("[data-travel-only]").forEach((element) => (element.hidden = form.elements.travelCategory.value === "none"));
    form.elements.travelCategory.addEventListener("change", toggle);
    toggle();
    const drawTrips = () => {
      form.querySelector("[data-trip-list]").innerHTML = `${current.trips.length ? `<ul class="time-expenses">${current.trips.map((trip) => `<li><span>${esc(tripLabel(trip))}</span><strong>${km(trip.kilometers)}</strong>
          <span class="time-row-actions"><button class="icon-button" type="button" data-edit-trip="${esc(trip.id)}" data-icon="square-pen" title="Fahrt bearbeiten" aria-label="Fahrt bearbeiten"></button><button class="icon-button icon-button-danger" type="button" data-remove-trip="${esc(trip.id)}" data-icon="trash" title="Fahrt entfernen" aria-label="Fahrt entfernen"></button></span></li>`).join("")}</ul>` : ""}
        <button class="button button-light button-small" type="button" data-add-trip data-icon="plus">Fahrt</button>`;
    };
    const drawExpenses = () => {
      form.querySelector("[data-expense-list]").innerHTML = `${current.expenses.length
        ? `<ul class="time-expenses">${current.expenses.map((expense) => `<li><span>${esc(expense.purpose)}${expense.receiptName ? ` <small class="muted">· Beleg</small>` : ""}</span><strong>${money(expense.amount)}</strong>
            <button class="icon-button icon-button-danger" type="button" data-remove-expense="${esc(expense.id)}" data-icon="trash" title="Auslage entfernen" aria-label="Auslage entfernen"></button></li>`).join("")}</ul>`
        : ""}<button class="button button-light button-small" type="button" data-add-expense data-icon="plus">Auslage</button>`;
    };
    drawTrips();
    drawExpenses();
    form.addEventListener("click", async (event) => {
      if (event.target.closest("[data-add-expense]")) return expenseDialog(drawExpenses);
      if (event.target.closest("[data-add-trip]")) return tripDialog(null, drawTrips);
      const edit = event.target.closest("[data-edit-trip]");
      if (edit) return tripDialog(current.trips.find((trip) => trip.id === edit.dataset.editTrip), drawTrips);
      const removeTrip = event.target.closest("[data-remove-trip]");
      const remove = event.target.closest("[data-remove-expense]");
      if (!removeTrip && !remove) return;
      try {
        if (removeTrip) {
          const trip = current.trips.find((item) => item.id === removeTrip.dataset.removeTrip);
          current = await api(`/api/time-tracking/trips/${encodeURIComponent(trip.id)}`, { method: "DELETE", body: { version: trip.version } });
          drawTrips();
        } else {
          current = await api(`/api/time-tracking/expenses/${encodeURIComponent(remove.dataset.removeExpense)}`, { method: "DELETE", body: {} });
          drawExpenses();
        }
        renderDay();
      } catch (exception) {
        notify(message, exception.message);
      }
    });
    Calight.createDialog({
      title: `Reisekosten und Auslagen · ${format.date(state.date)}`,
      form,
      submitLabel: "Speichern",
      discardMessage: "Eingaben verwerfen?",
      submit: async () => {
        const travelOn = form.elements.travelCategory.value !== "none";
        current = await api("/api/time-tracking/details", { method: "PUT", body: {
          date: state.date, employeeId: state.employee, travelCategory: form.elements.travelCategory.value,
          location: travelOn ? form.elements.location.value.trim() : "", absence: travelOn ? form.elements.absence.value.trim() : "",
          abroad: travelOn && form.elements.abroad.checked, overnight: travelOn && form.elements.overnight.checked,
          mealBreakfast: travelOn && form.elements.mealBreakfast.checked, mealLunch: travelOn && form.elements.mealLunch.checked, mealDinner: travelOn && form.elements.mealDinner.checked,
          notes: form.elements.notes.value.trim(),
        } });
        await renderDay();
      },
    });
  }

  /** Eine dienstliche Privat-Pkw-Fahrt erfassen oder bearbeiten (gemeinsames Feld Auftrag/Kostenstelle). */
  function tripDialog(trip, onSaved) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<div class="dialog-form-grid">
        <label><span class="field-label">Kilometer <span aria-hidden="true">*</span></span><input name="kilometers" inputmode="decimal" required placeholder="0" value="${esc(trip ? String(trip.kilometers).replace(".", ",") : "")}"></label>
        <label><span class="field-label">Fahrtzweck <span aria-hidden="true">*</span></span><input name="purpose" maxlength="200" required placeholder="z. B. Material abholen" value="${esc(trip?.purpose ?? "")}"></label>
        <label class="span-all"><span class="field-label">Strecke / Reiseziel</span><input name="route" maxlength="200" placeholder="z. B. Werkstatt – Baustelle Leipzig" value="${esc(trip?.route ?? "")}"></label>
        <label class="span-all"><span class="field-label">Auftrag / Kostenstelle (optional)</span><select name="costTarget"></select></label>
        <label class="span-all"><span class="field-label">Bemerkung (optional)</span><input name="notes" maxlength="500" value="${esc(trip?.notes ?? "")}"></label>
      </div><p class="field-error" data-trip-error hidden></p>`;
    Calight.costTargetSelect(form.elements.costTarget, reference.costTargets, { orderId: trip?.orderId ?? "", costCenterId: trip?.costCenterId ?? "" });
    const error = form.querySelector("[data-trip-error]");
    CalightModal.openOver({
      title: trip ? "Fahrt bearbeiten" : "Fahrt erfassen",
      content: form,
      initialFocus: '[name="kilometers"]',
      actions: [
        { label: "Abbrechen", value: null },
        {
          label: trip ? "Speichern" : "Hinzufügen", primary: true, validate: true,
          onClick: async () => {
            const body = { date: state.date, employeeId: state.employee, kilometers: form.elements.kilometers.value.trim().replace(",", "."), purpose: form.elements.purpose.value.trim(),
              route: form.elements.route.value.trim(), notes: form.elements.notes.value.trim(), ...Calight.parseCostTarget(form.elements.costTarget.value), ...(trip ? { version: trip.version } : {}) };
            try {
              current = trip
                ? await api(`/api/time-tracking/trips/${encodeURIComponent(trip.id)}`, { method: "PUT", body })
                : await api("/api/time-tracking/trips", { method: "POST", body });
            } catch (exception) {
              error.textContent = exception.message;
              error.hidden = false;
              return false;
            }
            onSaved();
            renderDay();
            return true;
          },
        },
      ],
    });
  }

  function expenseDialog(onSaved) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<div class="dialog-form-grid">
        <label><span class="field-label">Betrag <span aria-hidden="true">*</span></span><input name="amount" inputmode="decimal" required placeholder="0,00"></label>
        <label><span class="field-label">Zweck <span aria-hidden="true">*</span></span><input name="purpose" maxlength="200" required placeholder="z. B. Parkgebühr, Hotel, Maut"></label>
        <label class="span-all"><span class="field-label">Auftrag / Kostenstelle (optional)</span><select name="costTarget"></select></label>
        <label class="span-all"><span class="field-label">Beleg als PDF oder Bild (optional)</span><input name="file" type="file" accept="application/pdf,image/*"></label>
      </div><p class="field-error" data-expense-error hidden></p>`;
    Calight.costTargetSelect(form.elements.costTarget, reference.costTargets);
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
            const target = Calight.parseCostTarget(form.elements.costTarget.value);
            data.append("orderId", target.orderId);
            data.append("costCenterId", target.costCenterId);
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
      <h3 class="purchase-subheading">Abwesenheiten <small class="muted">(getrennt von der Arbeitszeit)</small></h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-title">Art</th><th class="cell-money">Ganztägig</th><th class="cell-money">Stundenweise</th></tr></thead>
        <tbody>${Object.values(data.absences || {}).map((item) => `<tr><td class="cell-title">${esc(item.label)}</td><td class="cell-money">${item.days ? `${item.days} ${item.days === 1 ? "Tag" : "Tage"}` : "–"}</td><td class="cell-money">${item.minutes ? hours(item.minutes) : "–"}</td></tr>`).join("")}</tbody></table></div>
      <h3 class="purchase-subheading">Nach Auftrag / Kostenstelle</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-title">Auftrag / Kostenstelle</th><th class="cell-money">Nettozeit</th><th class="cell-money">Davon Reise</th><th class="cell-money">Übrige Arbeit</th><th class="cell-status">Herkunft</th></tr></thead>
        <tbody>${data.byOrder.map((row) => `<tr><td class="cell-title">${row.orderId ? `${esc(row.orderNumber)} · ${esc(row.orderTitle)}` : row.costCenterId ? `${esc(row.costCenterNumber)} · ${esc(row.costCenterName)} <small class="muted">Kostenstelle</small>` : '<span class="muted">Ohne Zuordnung</span>'}</td><td class="cell-money">${hours(row.netMinutes)}</td><td class="cell-money">${hours(row.travelMinutes)}</td><td class="cell-money">${hours(row.workMinutes)}</td>
          <td class="cell-status">${orderSourceNote(row)}</td></tr>`).join("") || '<tr><td colspan="5" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>
      <h3 class="purchase-subheading">Buchungen</h3>
      <div class="overview-table-wrap"><table class="overview-table"><thead><tr><th class="cell-date">Datum</th><th class="cell-text">Mitarbeiter</th><th class="cell-text">Zeit</th><th class="cell-type">Herkunft</th><th class="cell-type">Einsatz</th><th class="cell-number">Auftrag</th><th class="cell-money">Netto</th><th class="cell-money">Reise</th></tr></thead>
        <tbody>${data.rows.map((row) => `<tr><td class="cell-date">${format.date(row.date)}</td><td class="cell-text">${esc(row.employeeName)}</td><td class="cell-text">${row.hasTimes ? `${esc(row.start)}–${esc(row.end)}${row.endsNextDay ? " (+1)" : ""}` : untimedLabel(!data.timesOptional)}</td>
          <td class="cell-type">${row.reportUrl ? `<a href="${esc(row.reportUrl)}">${esc(row.reportLabel)}</a>` : `<span class="time-activity">${esc(row.activityLabel)}</span>`}</td><td class="cell-type" title="${esc(row.assignmentLabel)}">${row.assignmentKind === "external" ? "Auswärts" : row.assignmentKind === "regular" ? "Erste T." : "–"}</td><td class="cell-number">${esc(row.orderNumber || row.costCenterNumber || "–")}</td><td class="cell-money">${periodHours(row.periodNetMinutes, row.netMinutes)}</td><td class="cell-money">${row.travelMinutes ? periodHours(row.periodTravelMinutes, row.travelMinutes) : "–"}</td></tr>`).join("") || '<tr><td colspan="8" class="muted">Keine Einträge.</td></tr>'}</tbody></table></div>`;
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
    if (event.target.closest("[data-submit-period]")) return submitPeriod();
    if (event.target.closest("[data-reopen-period]")) return reopenDialog(currentPeriod.employee.id, currentPeriod.period.from, currentPeriod.period.to, renderRange);
    const approve = event.target.closest("[data-approve]");
    if (approve) return approvalAction(approve, "approve");
    const giveBack = event.target.closest("[data-return]");
    if (giveBack) return approvalAction(giveBack, "return");
    const openPeriod = event.target.closest("[data-open-period]");
    if (openPeriod) {
      state.employee = openPeriod.dataset.openPeriod;
      employeeSelect.value = state.employee;
      state.view = openPeriod.dataset.periodType;
      state.date = openPeriod.dataset.periodDate;
      return render();
    }
    if (event.target.closest("[data-add-entry]")) return entryDialog();
    const editAbsence = event.target.closest("[data-edit-absence]");
    if (editAbsence) return entryDialog(null, {}, current.absences.find((item) => item.id === editAbsence.dataset.editAbsence));
    const removeAbsence = event.target.closest("[data-delete-absence]");
    if (removeAbsence) {
      const absence = current.absences.find((item) => item.id === removeAbsence.dataset.deleteAbsence);
      if (!(await CalightModal.confirm({ title: "Abwesenheit entfernen", content: `${absence.label} (${absenceDuration(absence)}) wird entfernt.`, confirmLabel: "Entfernen", danger: true }))) return;
      try {
        current = await api(`/api/time-tracking/absences/${encodeURIComponent(absence.id)}`, { method: "DELETE", body: { version: absence.version } });
        await renderDay();
      } catch (exception) {
        notify(message, exception.message);
      }
      return;
    }
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
        root.querySelector('[data-view="approvals"]').hidden = false;
      }
      return render();
    })
    .catch((exception) => notify(message, exception.message));
})();
