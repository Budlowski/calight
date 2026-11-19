// Bestellungen (Phase 3.4.1.1): Statusanzeige und Versand von Vorgängen mit optionalem Beleg.
import "../../public/assets/calight.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

// Ohne Übersichtsseite exportiert das Skript nur die gemeinsamen Helfer.
globalThis.document = { querySelector: () => null };
await import("../../public/assets/purchase-orders.js");
const { statusBadges, postForm, money, supplierLabel } = globalThis.CalightPurchaseOrders;

// Drei getrennte Status; Liefer- und Zahlungsstatus erst nach dem Bestellen.
const draft = { status: "draft", statusLabel: "Entwurf", placed: false, deliveryStatus: "pending", deliveryLabel: "Ausstehend", paymentStatus: "open", paymentLabel: "Offen" };
const draftBadges = statusBadges(draft);
assert(draftBadges.includes("Entwurf") && !draftBadges.includes("Ausstehend") && !draftBadges.includes("Offen"), `draft shows only the order status: ${draftBadges}`);

const ordered = { ...draft, status: "ordered", statusLabel: "Bestellt", placed: true, deliveryStatus: "partial", deliveryLabel: "Teilgeliefert", paymentStatus: "paid", paymentLabel: "Bezahlt" };
const orderedBadges = statusBadges(ordered);
assert(orderedBadges.includes("status-po-status-ordered") && orderedBadges.includes("status-po-delivery-partial") && orderedBadges.includes("status-po-payment-paid"), "ordered shows all three statuses");
assert(statusBadges(ordered, { prefix: true }).includes("Lieferung: Teilgeliefert"), "detail header labels the statuses");

// Für eine neue Fassung wieder geöffnete Bestellung: Lieferungen und Zahlungen bleiben sichtbar.
const revision = { ...ordered, status: "draft", statusLabel: "Entwurf" };
assert(statusBadges(revision).includes("Teilgeliefert"), "reopened order keeps delivery and payment status");

const cancelled = statusBadges({ ...ordered, status: "cancelled", statusLabel: "Storniert" });
assert(cancelled.includes("status-po-status-cancelled") && cancelled.includes("Storniert"), "cancelled orders are labelled with text");
assert(statusBadges({ ...ordered, statusLabel: "<b>" }).includes("&lt;b&gt;"), "labels are escaped");

assert(money(null) === "–" && money("") === "–", "missing amounts render a dash");
assert(supplierLabel({ number: "70001", name: "Süd" }) === "70001 · Süd" && supplierLabel({ number: null, name: "Nord" }) === "Nord", "supplier label with optional number");

// Vorgang als multipart/form-data: Booleans als 1/0, leere Werte entfallen, Datei im Feld „file“.
let request;
globalThis.fetch = async (path, options) => {
  request = { path, options };
  return { ok: true, status: 201, json: async () => ({ data: { id: "po-1" } }) };
};
const file = new Blob(["%PDF-1.4"], { type: "application/pdf" });
const result = await postForm("/api/purchase-orders/po-1/deliveries", { kind: "return", makeCurrent: false, replaceDirectCost: true, version: 3, missing: null }, file, "csrf-token");
assert(result.id === "po-1", "postForm unwraps data");
assert(request.options.method === "POST" && request.options.headers["X-CSRF-Token"] === "csrf-token", "postForm sends CSRF token");
const body = request.options.body;
assert(body.get("kind") === "return" && body.get("version") === "3", "fields are sent as strings");
assert(body.get("makeCurrent") === "0" && body.get("replaceDirectCost") === "1", "booleans become 1/0");
assert(!body.has("missing"), "null values are omitted");
assert(body.get("file") !== null, "file is attached");

const withoutFile = await postForm("/api/purchase-orders/po-1/confirmations", { confirmationDate: "2026-11-19" }, null, "t");
assert(withoutFile.id === "po-1" && !request.options.body.has("file"), "file stays optional");

// Fehler: Validierungsdetails des Servers werden angezeigt.
globalThis.fetch = async () => ({ ok: false, status: 422, json: async () => ({ error: "Validierung fehlgeschlagen.", details: ["Bitte das Lieferdatum angeben."] }) });
let message = "";
try {
  await postForm("/api/purchase-orders/po-1/deliveries", { kind: "partial" }, null, "t");
} catch (exception) {
  message = exception.message;
}
assert(message === "Bitte das Lieferdatum angeben.", `postForm surfaces validation details: ${message}`);

globalThis.fetch = async () => ({ ok: false, status: 500, json: async () => { throw new Error("no json"); } });
try {
  await postForm("/x", {}, null, "t");
} catch (exception) {
  message = exception.message;
}
assert(message.includes("HTTP 500"), `postForm reports the HTTP status without JSON: ${message}`);

console.log("purchase-orders.test.js ok");
