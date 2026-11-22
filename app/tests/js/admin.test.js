// Verwaltung: Anzeige der Mitarbeiterkostensätze und Gliederung der Arbeitserfassungseinstellungen.
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

await import("../../public/assets/employees.js");
await import("../../public/assets/operations-settings.js");
const { rateText, rateInput } = globalThis.CalightEmployees;
const { SUBGROUPS, unit, display } = globalThis.CalightOperationsSettings;

const plain = (text) => text.replace(/\s/g, " ");
assert(plain(rateText("42.3333")) === "42,33 €/h", `rate shown with two decimals: ${rateText("42.3333")}`);
assert(plain(rateText("40.0000")) === "40,00 €/h", "whole rate with two decimals");
assert(rateInput("42.3333") === "42,3333", "input keeps stored precision");
assert(rateInput("40.0000") === "40,00" && rateInput("40.5000") === "40,50" && rateInput("0") === "0,00", "input shows at least two decimals");

// Jede Betriebseinstellung erscheint genau einmal in einer fachlichen Untergruppe (keine verlorenen Felder).
const known = ["orders.technician_visibility","time_tracking.times_optional","time_tracking.submission_rhythm","time_tracking.warnings_enabled","time_limits.regular_daily_hours","time_limits.max_daily_hours","time_limits.break_threshold_1_hours","time_limits.break_minimum_1_minutes","time_limits.break_threshold_2_hours","time_limits.break_minimum_2_minutes","time_limits.max_continuous_hours","time_limits.min_break_part_minutes","time_limits.min_rest_hours","travel.meal_allowances_enabled","travel.single_day_threshold_hours","travel.allowance_single","travel.allowance_arrival","travel.allowance_full","travel.allowance_departure","travel.reduction_breakfast_percent","travel.reduction_lunch_percent","travel.reduction_dinner_percent","travel.km_rate"];
const grouped = Object.values(SUBGROUPS).flatMap((groups) => groups.flatMap(([, keys]) => keys));
assert(known.length > 20, "settings keys found");
assert(known.every((key) => grouped.filter((item) => item === key).length === 1), `ungrouped or duplicate: ${known.filter((key) => grouped.filter((item) => item === key).length !== 1)}`);
assert(grouped.every((key) => known.includes(key)), "no unknown keys in groups");
assert(unit({ unit: "h" }) === "Std." && unit({ unit: "min" }) === "Min." && unit({ unit: "€/km" }) === "€/km" && unit({}) === "", "units are explicit");

assert(display({ unit: "€", value: "14" }) === "14,00" && display({ unit: "€/km", value: "0.3" }) === "0,30", "money with two decimals");
assert(display({ unit: "h", value: "8.5" }) === "8,5" && display({ unit: "%", value: "20" }) === "20", "other numbers unchanged");

console.log("admin tests passed");
