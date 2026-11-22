// Gemeinsame Editoren erzeugen ihre Links über Calight.paths: mobil bleiben sie im mobilen Frontend.
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

globalThis.document = { body: { dataset: { ui: "mobile" } }, querySelector: () => null };
await import("../../public/assets/calight.js?mobile");
const mobile = globalThis.Calight;
assert(mobile.ui === "mobile", "mobile layout detected");
assert(mobile.paths.dailyReport("a b") === "/mobile/daily-reports/a%20b", "daily report path is encoded");
assert(mobile.paths.workReport("1") === "/mobile/rapports/1" && mobile.paths.workReportSign("1") === "/mobile/rapports/1/sign", "work report paths");
assert(mobile.paths.measurement("m") === "/mobile/measurements/m" && mobile.paths.measurements() === "/mobile/measurements" && mobile.paths.materialRequests() === "/mobile/material-requests", "capture paths");
assert(mobile.paths.order("o") === "/mobile/orders/o" && mobile.paths.workReports() === "/mobile/rapports" && mobile.paths.dailyReports() === "/mobile/daily-reports", "lists and orders");

globalThis.document = { body: { dataset: {} }, querySelector: () => null };
await import("../../public/assets/calight.js?office");
const office = globalThis.Calight;
assert(office.ui === "office", "office is the default");
assert(office.paths.measurement("m") === "/measurements/m" && office.paths.materialRequests() === "/material-requests", "office capture paths");
assert(office.paths.workReportSign("1") === "/work-reports/1/sign" && office.paths.order("o") === "/orders/o" && office.paths.dailyReports() === "/daily-reports", "office paths unchanged");

console.log("paths tests passed");
