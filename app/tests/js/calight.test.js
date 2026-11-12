import "../../public/assets/calight.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const { esc, format, matches, normalize, api } = globalThis.Calight;

assert(esc(`<a href="x">'&'</a>`) === "&lt;a href=&quot;x&quot;&gt;&#39;&amp;&#39;&lt;/a&gt;", "esc escapes HTML");
assert(esc(null) === "" && esc(undefined) === "", "esc handles empty values");

assert(format.date("2026-11-10") === "10.11.2026", `calendar dates stay on the same day: ${format.date("2026-11-10")}`);
assert(format.date("") === "–", "empty date renders a dash");
assert(format.hours("7.5") === "7,500 h", "hours use three decimals");
assert(format.money("1234.5").replace(/\s/g, " ") === "1.234,50 €", `money uses German format: ${format.money("1234.5")}`);
assert(format.number("1.23456", 4) === "1,2346", "number rounds to digits");

const query = normalize("MÜLLER");
assert(matches(query, "AU1", null, "Elektro Müller GmbH"), "matches ignores case and empty fields");
assert(!matches(query, "AU1", "Meier"), "matches rejects missing terms");
assert(matches("", "anything"), "empty query matches everything");

// API-Fehler: Validierungsdetails haben Vorrang vor der allgemeinen Meldung.
globalThis.document = { querySelector: () => ({ dataset: { csrf: "token" } }) };
let lastRequest;
globalThis.fetch = async (path, options) => {
  lastRequest = { path, options };
  return { ok: false, status: 422, json: async () => ({ error: "Validierung fehlgeschlagen.", details: ["Titel fehlt."] }) };
};
let message = "";
try {
  await api("/api/test", { method: "POST", body: { a: 1 } });
} catch (exception) {
  message = exception.message;
}
assert(message === "Titel fehlt.", `api surfaces validation details: ${message}`);
assert(lastRequest.options.headers["X-CSRF-Token"] === "token", "api sends CSRF token on writes");
assert(lastRequest.options.body === '{"a":1}', "api serializes JSON body");

globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ data: { id: "1" } }) });
assert((await api("/api/test")).id === "1", "api unwraps data");

// Exakte Dezimalvorschau (Aufmaß, Rapport): gleiche Rundung wie der Server, keine Gleitkommazahlen.
const { toUnits, fromUnits, lineCents, DECIMAL_PATTERN } = globalThis.Calight.exact;
for (const [quantity, price, expected] of [["0,333", "2.50", "0.83"], ["3,5", "85.00", "297.50"], ["-2,5", "2.50", "-6.25"], ["1.005", "1.00", "1.01"], ["2", "19.995", "39.99"]]) {
  const cents = fromUnits(lineCents(toUnits(quantity, 3), price), 2);
  assert(cents === expected, `${quantity} × ${price} = ${cents}, erwartet ${expected}`);
}
assert(fromUnits(toUnits("0,1", 3) + toUnits("0,2", 3), 3) === "0.300", "0,1 + 0,2 bleibt exakt");
assert(DECIMAL_PATTERN.test("3,5") && !DECIMAL_PATTERN.test("3,5 h"), "decimal pattern");

console.log("calight helper tests passed");
