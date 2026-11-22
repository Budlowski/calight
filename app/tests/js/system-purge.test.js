// Datenbereinigung: Auswahl aus dem Formular und Sperre nicht zeitraumfähiger Bereiche.
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

globalThis.document = { getElementById: () => null };
await import("../../public/assets/system-purge.js");
const { selectionOf, syncPeriod, bytes } = globalThis.CalightSystemPurge;

const input = (value, group, period, checked) => {
  const label = { querySelector: () => hint };
  const hint = { hidden: true };
  return { value, checked, disabled: false, dataset: { group, period: period ? "1" : "0" }, hint, closest: () => label };
};
const areas = [input("customers", "business", false, true), input("daily_reports", "business", true, true), input("employees", "master", false, false)];
const all = { checked: false, indeterminate: false };
const periodFields = { hidden: true };
const form = {
  elements: { mode: { value: "all" }, from: { value: "2026-01-01" }, to: { value: "2026-01-31" }, resetNumbers: { checked: true, disabled: false } },
  querySelector: (selector) => (selector === "[data-period-fields]" ? periodFields : selector === "[data-all-business]" ? all : null),
  querySelectorAll: (selector) => {
    let list = areas;
    if (selector.includes('[data-group="business"]')) list = list.filter((item) => item.dataset.group === "business");
    if (selector.includes(":not(:disabled)")) list = list.filter((item) => !item.disabled);
    if (selector.includes(":checked")) list = list.filter((item) => item.checked);
    return list;
  },
};

syncPeriod(form);
let selection = selectionOf(form);
assert(selection.mode === "all" && selection.resetNumbers === true && !("from" in selection), "all mode with number reset");
assert(selection.areas.join() === "customers,daily_reports", "checked areas");
assert(all.checked === true && periodFields.hidden === true, "all business selected, no period fields");

form.elements.mode.value = "period";
syncPeriod(form);
selection = selectionOf(form);
assert(areas[0].disabled && areas[0].hint.hidden === false && !areas[1].disabled, "areas without period support are disabled with reason");
assert(selection.areas.join() === "daily_reports" && selection.from === "2026-01-01" && selection.to === "2026-01-31", "period selection only supported areas");
assert(selection.resetNumbers === false && form.elements.resetNumbers.disabled && periodFields.hidden === false, "no number reset in period mode");
assert(bytes(512) === "512 B" && bytes(2048) === "2 KB", "byte formatting");

console.log("system purge tests passed");
