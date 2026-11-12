import "../../public/assets/list-view.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const same = (actual, expected, message) => assert(JSON.stringify(actual) === JSON.stringify(expected), `${message}: ${JSON.stringify(actual)}`);
const { parseState, serializeState, pageNumbers } = globalThis.CalightListView;

const config = {
  filterNames: ["status", "type"],
  defaultSort: "updated",
  sorts: { updated: { dir: "desc" }, title: { dir: "asc" }, net: { dir: "desc" } },
};

// Standardwerte erzeugen eine leere URL.
const defaults = parseState("", config);
same(defaults, { q: "", filters: { status: "", type: "" }, sort: "updated", dir: "desc", page: 1, perPage: 25 }, "defaults");
same(serializeState(defaults, config), "", "default state has no parameters");

// Direkter Aufruf einer vollständigen URL stellt den Status wieder her.
const restored = parseState("?q=KNX&status=draft&sort=title&dir=desc&page=2&per_page=50", config);
same(restored, { q: "KNX", filters: { status: "draft", type: "" }, sort: "title", dir: "desc", page: 2, perPage: 50 }, "restore");
same(serializeState(restored, config), "q=KNX&status=draft&sort=title&dir=desc&page=2&per_page=50", "round trip");

// Ungültige oder veraltete Parameter fallen auf Standardwerte zurück.
same(parseState("?sort=evil&dir=up&page=-1&per_page=13&unknown=1", config),
  { q: "", filters: { status: "", type: "" }, sort: "updated", dir: "desc", page: 1, perPage: 25 }, "invalid parameters");
same(parseState("?sort=net", config).dir, "desc", "natural direction of a field");
same(serializeState({ ...defaults, sort: "title", dir: "asc" }, config), "sort=title&dir=asc", "non-default sort is written");
same(serializeState({ ...defaults, q: "Müller & Söhne" }, config), "q=M%C3%BCller+%26+S%C3%B6hne", "search is encoded");

// Kompakte Seitenauswahl.
same(pageNumbers(1, 1), [1], "single page");
same(pageNumbers(1, 3), [1, 2, 3], "few pages");
same(pageNumbers(5, 10), [1, null, 4, 5, 6, null, 10], "middle page");
same(pageNumbers(10, 10), [1, null, 9, 10], "last page");
same(pageNumbers(2, 10), [1, 2, 3, null, 10], "near start");

console.log("list view tests passed");
