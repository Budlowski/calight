// Mobile Zeiterfassung: Datums-, Dauer- und Nutzlasthilfen (ohne DOM; die Oberfläche startet nur mit #mobile-times).
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

await import("../../public/assets/mobile-times.js");
const T = globalThis.CalightMobileTimes;

// Datum ohne Zeitzonenverschiebung
assert(T.addDays("2026-03-28", 1) === "2026-03-29" && T.addDays("2026-03-29", 1) === "2026-03-30", "no DST shift");
assert(T.addDays("2026-12-31", 1) === "2027-01-01", "year change");
assert(T.weekStart("2026-11-22") === "2026-11-16" && T.weekStart("2026-11-16") === "2026-11-16", "week starts on Monday");
assert(T.addMonths("2026-01-31", 1) === "2026-02-01", "month navigation uses the first day");
assert(T.isoWeek("2026-11-21") === 47 && T.isoWeek("2027-01-01") === 53, `ISO week: ${T.isoWeek("2027-01-01")}`);
assert(T.germanDate("2026-11-21") === "21.11.2026", "German date");

// Monatsraster: Montag zuerst, vollständige Wochen
const cells = T.monthCells("2026-11-10");
assert(cells.length % 7 === 0, "month grid has full weeks");
assert(cells.slice(0, 6).every((cell) => cell === null) && cells[6] === "2026-11-01", "1.11.2026 is a Sunday");
assert(cells.filter(Boolean).length === 30, "November has 30 days");

// Dauer
assert(T.formatMinutes(450) === "7:30 h" && T.formatMinutes(0) === "0:00 h" && T.formatMinutes(-5) === "-0:05 h", "format minutes");
assert(T.parseDuration("7:30") === 450 && T.parseDuration("7,5") === 450 && T.parseDuration("8") === 480 && T.parseDuration("0:45") === 45, "parse duration");
assert(T.parseDuration("") === null && T.parseDuration("7:75") === null && T.parseDuration("abc") === null, "invalid durations");
assert(T.durationText(450) === "7:30" && T.durationText(null) === "", "duration text");
assert(T.previewNet("07:00", "16:00", "30") === 510, "net preview");
assert(T.previewNet("22:00", "06:00", "") === 480, "overnight preview");
assert(T.previewNet("08:00", "08:00", "0") === null && T.previewNet("08:00", "", "0") === null, "incomplete preview");
assert(T.previewNet("08:00", "08:30", "30") === null, "break consumes block");
assert(T.decimalInput(" 42,5 ") === "42.5", "decimal input");

// Hinweise je Tag
const hints = T.hintsByDate({ errors: [{ date: "2026-11-18", message: "a" }, { date: null, message: "general" }], warnings: [{ date: "2026-11-18", message: "b" }] });
assert(hints["2026-11-18"].errors[0] === "a" && hints["2026-11-18"].warnings[0] === "b" && Object.keys(hints).length === 1, "hints by date");

// Nutzlast: manuelle Blöcke mit Uhrzeit, Dauer oder Berichtsbuchung (Dauer fix, keine Ziel-/Datumsfelder)
const timed = T.entryPayload({ date: "2026-11-20", start: "07:00", end: "15:30", breakMinutes: "30", travelMinutes: "", orderId: "o1", costCenterId: "", assignmentKind: "external", notes: " Montage ", version: "" });
assert(timed.date === "2026-11-20" && timed.start === "07:00" && timed.orderId === "o1" && timed.notes === "Montage" && !("version" in timed) && !("durationMinutes" in timed), "timed payload");
const untimed = T.entryPayload({ date: "2026-11-20", start: "07:00", end: "08:00", duration: "7,5", breakMinutes: "15" }, { untimed: true });
assert(untimed.start === "" && untimed.end === "" && untimed.breakMinutes === "" && untimed.durationMinutes === "450", "untimed payload clears times");
const report = T.entryPayload({ start: "07:00", end: "11:00", breakMinutes: "0", travelMinutes: "30", version: "3", orderId: "x", date: "2026-11-01" }, { fixedDuration: true });
assert(!("date" in report) && !("orderId" in report) && !("durationMinutes" in report) && report.version === 3, "report entries never send duration, date or target");

// Verbindungsfehler verständlich
assert(T.friendlyError(new TypeError("Failed to fetch")).startsWith("Keine Verbindung"), "network error message");
assert(T.friendlyError(new Error("Der Tag ist gesperrt.")) === "Der Tag ist gesperrt.", "server message passes through");

console.log("mobile-times tests passed");
