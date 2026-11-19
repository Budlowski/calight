// Zeiterfassung: Datums- und Zeithilfen der Oberfläche.
import "../../public/assets/calight.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
globalThis.document = { querySelector: () => null };
await import("../../public/assets/time-tracking.js");
const { addDays, weekStart, monthRange, addMonths, hours, weekday, toMinutes, toTime } = globalThis.CalightTimeTracking;

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
console.log("time-tracking.test.js ok");
