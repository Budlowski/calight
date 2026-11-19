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
console.log("time-tracking.test.js ok");
