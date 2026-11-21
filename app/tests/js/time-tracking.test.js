// Zeiterfassung: Datums- und Zeithilfen der Oberfläche.
import "../../public/assets/calight.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
globalThis.document = { querySelector: () => null };
await import("../../public/assets/time-tracking.js");
const { addDays, weekStart, monthRange, addMonths, hours, weekday, toMinutes, toTime, isoWeek, monthGrid, travelShort } = globalThis.CalightTimeTracking;

assert(weekStart("2026-11-19") === "2026-11-16", "week starts on Monday");
assert(weekStart("2026-11-22") === "2026-11-16", "Sunday belongs to the same week");
assert(weekStart("2026-11-16") === "2026-11-16", "Monday stays");
assert(addDays("2026-12-31", 1) === "2027-01-01", "year change");
assert(addDays("2026-03-29", 1) === "2026-03-30", "no DST shift");
const [from, to] = monthRange("2028-02-10");
assert(from === "2028-02-01" && to === "2028-02-29", `leap year month: ${from} ${to}`);
assert(addMonths("2026-01-31", 1) === "2026-02-01", "month navigation uses the first day");
assert(hours(525) === "8:45 h" && hours(0) === "0:00 h" && hours(65) === "1:05 h", "hours format");
assert(weekday("2026-11-19") === "Do", "German weekday");
assert(toMinutes("07:30") === 450 && toTime(450) === "07:30" && toTime(1500) === "01:00", "time conversion incl. next day");
// ISO-Kalenderwochen inkl. Jahreswechsel.
const week = (date) => { const { week: number, year } = isoWeek(date); return `${year}-${number}`; };
assert(week("2026-11-19") === "2026-47", `KW 47: ${week("2026-11-19")}`);
assert(week("2021-01-03") === "2020-53", "3.1.2021 gehört zu KW 53/2020");
assert(week("2024-12-30") === "2025-1", "30.12.2024 gehört zu KW 1/2025");
assert(week("2026-12-31") === "2026-53", `31.12.2026: ${week("2026-12-31")}`);
assert(week("2027-01-04") === "2027-1", "erste Woche 2027");

// Monatsraster: Montag als erster Tag, 4–6 Zeilen, keine festen Monatslängen.
const rows = (date) => monthGrid(date).length;
assert(rows("2027-02-01") === 4, "Februar 2027 beginnt Montag und hat genau vier Zeilen");
assert(rows("2026-11-01") === 6, "November 2026 beginnt Sonntag: sechs Zeilen");
assert(rows("2026-03-01") === 6 && rows("2026-12-01") === 5, "Zeilenanzahl je Monatsbeginn");
const leap = monthGrid("2028-02-14");
assert(leap.flat().includes("2028-02-29") && leap[0][0] === "2028-01-31", `Schaltjahr und Montag-Start: ${leap[0][0]}`);
assert(monthGrid("2026-11-05").every((row) => row.length === 7 && weekday(row[0]) === "Mo" && weekday(row[6]) === "So"), "jede Zeile Mo–So");
assert(monthGrid("2026-12-10").flat().at(-1) === "2027-01-03", "Jahreswechsel im Raster");

// Tagesnavigation und Reisezeitdarstellung.
assert(addDays("2027-01-01", -1) === "2026-12-31" && addDays("2028-02-28", 1) === "2028-02-29", "Tag zurück/weiter über Grenzen");
assert(addMonths("2026-12-15", 1) === "2027-01-01" && addMonths("2027-01-15", -1) === "2026-12-01", "Monatswechsel über Jahresgrenze");
assert(travelShort(90) === "R 1:30" && travelShort(0) === "", "Reisezeit kurz in Kalenderzellen");
// Uhrzeiten zu Berichtsstunden: Nettozeit muss exakt der Berichtsdauer entsprechen.
const { netFromTimes } = globalThis.CalightTimeTracking;
assert(netFromTimes("08:00", "14:30", 30, 360).valid, "6 h Bericht: 08:00–14:30 mit 30 min Pause passt");
assert(!netFromTimes("08:00", "15:30", 30, 360).valid && netFromTimes("08:00", "15:30", 30, 360).net === 420, "7 h passen nicht zu 6 h Bericht");
const night = netFromTimes("22:00", "02:00", 0, null);
assert(night.nextDay && night.net === 240 && night.valid, "über Mitternacht");
assert(netFromTimes("", "", 0, 360) === null, "ohne Uhrzeiten keine Berechnung");
assert(!netFromTimes("08:00", "09:00", 60, null).valid, "Pause so lang wie der Block ist ungültig");
// Stundenzettel-Zeile: Uhrzeit bzw. „Ohne Uhrzeit“, Beleg als Link, Herkunft, Stunden, passende Aktion.
const { entryRow } = globalThis.CalightTimeTracking;
const base = { id: "e1", start: null, end: null, hasTimes: false, timesMissing: false, endsNextDay: false, breakMinutes: 0, netMinutes: 300, travelMinutes: 0,
  activityLabel: "Arbeit / Montage", notes: "", orderNumber: null, orderTitle: null, customerName: null, assignmentKind: null, canEditTimes: true, canDelete: false,
  durationFixed: true, sourceKind: "work_report", sourceLabel: "Rapport", reportUrl: "/work-reports/1", reportLabel: "Rapport RP202611001", reportActivity: "Arbeitszeit – Monteur", reportDraft: false };
const report = entryRow({ ...base, customerName: "Testkunde" });
assert(report.includes("Ohne Uhrzeit") && !report.includes("is-incomplete"), "ohne Uhrzeit neutral bei erlaubter Erfassung ohne Uhrzeiten");
assert(report.includes('href="/work-reports/1"') && report.includes("Testkunde") && report.includes("5:00 h"), "Beleglink, Kunde und Stunden");
assert(report.includes('title="Uhrzeiten ergänzen"') && !report.includes("data-delete-entry"), "Berichtsbuchung: Uhrzeiten ergänzen, kein Löschen");
assert(entryRow({ ...base, timesMissing: true }).includes("time-untimed is-incomplete"), "unvollständig bei deaktivierter Einstellung");
const manual = entryRow({ ...base, durationFixed: false, sourceKind: "manual", sourceLabel: "Manuell", reportUrl: null, reportLabel: null, reportActivity: "",
  hasTimes: true, start: "07:00", end: "12:00", notes: "Büro / Rüstzeit", canDelete: true });
assert(manual.includes("07:00–12:00") && manual.includes("Büro / Rüstzeit") && manual.includes("Ohne Zuordnung") && manual.includes("data-delete-entry") && manual.includes('title="Bearbeiten"'), "manueller Eintrag");
assert(!manual.includes("geändert von"), "keine Änderungsinformationen in der Liste");
const withOrder = entryRow({ ...base, orderNumber: "AU1", orderTitle: "Halle <3>", customerName: "Kunde" });
assert(withOrder.includes("AU1 · Halle &lt;3&gt;") && !withOrder.includes("Kunde<"), "Auftrag statt Kunde, maskiert, nicht doppelt");
const onCostCenter = entryRow({ ...base, durationFixed: false, sourceKind: "manual", sourceLabel: "Manuell", reportUrl: null, reportLabel: null, reportActivity: "", notes: "Rüsten",
  costCenterNumber: "K120", costCenterName: "Lager & Rüstzeit" });
assert(onCostCenter.includes("K120 · Lager &amp; Rüstzeit") && !onCostCenter.includes("Ohne Zuordnung"), "Kostenstelle als Zuordnung");
// Freigabe: Tagesstatus, Hinweise, Nachweise mit Revisionen.
const { lockFlag, lockBadge, issueList, statementList } = globalThis.CalightTimeTracking;
assert(lockFlag("open") === "" && lockFlag("approved").includes("Festgeschrieben") && lockFlag("submitted").includes("is-submitted"), "Tageskennzeichen");
assert(lockBadge("reopened").includes("Zur Korrektur geöffnet") && lockBadge("mixed").includes("Teilweise"), "Statuskennzeichen");
const issues = issueList([{ message: "Überschneidung <x>" }], [{ message: "Sonntagsarbeit." }, { message: "Leerer Tag" }]);
assert(issues.includes("is-error") && issues.includes("&lt;x&gt;") && issues.includes("2 Hinweise") && !issues.includes(" open>"), "Sperrfehler sichtbar, Hinweise eingeklappt");
assert(issueList([], [{ message: "a" }]).includes("1 Hinweis") && issueList([], []).trim() === "", "ohne Fehler aufgeklappt, leer ohne Hinweise");
const statements = statementList([{ type: "month", label: "November 2026", revision: 1, current: false, url: "/a" }, { type: "month", label: "November 2026", revision: 2, current: true, url: "/b" }]);
assert(statements.includes("Revision 1") && statements.includes("ersetzt – nicht mehr aktuell") && statements.includes("Revision 2") && statements.includes("aktuell</small>"), "Revisionen");
assert(statementList([]) === "", "keine Nachweise");
// Reisekosten: Kurzbeschreibung und Fahrten.
const { travelSummary, tripLabel } = globalThis.CalightTimeTracking;
const categories = { none: "Keine Auswärtstätigkeit", arrival: "Anreisetag mit auswärtiger Übernachtung" };
const travel = { category: "arrival", categoryLabel: "x", abroad: false, location: "Leipzig", absenceMinutes: 810, overnight: true, meals: { breakfast: false, lunch: true, dinner: true }, notes: "" };
assert(travelSummary(travel, categories) === "Anreisetag mit auswärtiger Übernachtung · Leipzig · Abwesenheit 13:30 h · Übernachtung · gestellt: Mittagessen, Abendessen", travelSummary(travel, categories));
assert(travelSummary({ ...travel, category: "none", notes: "Nur Fahrten" }, categories) === "Nur Fahrten" && travelSummary(null, categories) === "", "ohne Auswärtstätigkeit nur Bemerkung");
assert(travelSummary({ ...travel, abroad: true, absenceMinutes: null, meals: { breakfast: false, lunch: false, dinner: false } }, categories).includes("Ausland · Leipzig · Übernachtung"), "Ausland");
assert(tripLabel({ route: "Werkstatt – Leipzig", purpose: "Anreise", orderNumber: "AU1", costCenterNumber: null }) === "Werkstatt – Leipzig · Anreise · AU1", "Fahrt mit Auftrag");
assert(tripLabel({ route: "", purpose: "Material", orderNumber: null, costCenterNumber: "K120" }) === "Material · K120", "Fahrt mit Kostenstelle");
// Prüfhinweise je Kalendertag und Abwesenheiten.
const { issuesByDate, issueFlag, absenceDuration, absenceShort, absenceSummary } = globalThis.CalightTimeTracking;
const byDate = issuesByDate({ errors: [{ date: "2026-10-13", message: "x" }, { date: null, message: "Zeitraum" }], warnings: [{ date: "2026-10-13", message: "w" }, { date: "2026-10-14", message: "w2" }] });
assert(byDate["2026-10-13"].errors.length === 1 && byDate["2026-10-13"].warnings.length === 1 && !byDate.null, "nach Datum, ohne Datum ausgelassen");
assert(issueFlag(byDate["2026-10-13"]).includes("is-error") && issueFlag(byDate["2026-10-13"]).includes("✕") && !issueFlag(byDate["2026-10-13"]).includes("⚠"), "Sperrfehler haben Vorrang");
assert(issueFlag(byDate["2026-10-14"]).includes("is-warning") && issueFlag(byDate["2026-10-14"]).includes("⚠") && issueFlag(undefined) === "", "Warnung bzw. neutral");
assert(issuesByDate(null) && Object.keys(issuesByDate(null)).length === 0, "ohne Daten leer");
assert(absenceDuration({ fullDay: true }) === "ganztägig" && absenceDuration({ fullDay: false, minutes: 270 }) === "4:30 h", "Dauer");
assert(absenceShort([{ type: "vacation", label: "Urlaub", fullDay: true }, { type: "sick", label: "Krankheit <x>", fullDay: false, minutes: 120 }]).includes("Krankheit &lt;x&gt; 2:00") && absenceShort() === "", "Kalenderkurzform");
const absenceTotals = { vacation: { label: "Urlaub", days: 2, minutes: 0 }, sick: { label: "Krankheit", days: 0, minutes: 0 }, compensatory: { label: "Zeitausgleich", days: 1, minutes: 240 } };
assert(absenceSummary(absenceTotals) === "Urlaub 2 Tage · Zeitausgleich 1 Tag, 4:00 h" && absenceSummary({}) === "", absenceSummary(absenceTotals));
const collapsed = issueList([{ message: "a" }], [{ message: "b" }], true);
assert(collapsed.startsWith("<details") && !collapsed.startsWith("<details open") && collapsed.includes("1 Sperrfehler") && collapsed.includes("1 Hinweis") && issueList([], [], true) === "", "Gesamtliste eingeklappt");
console.log("time-tracking.test.js ok");
