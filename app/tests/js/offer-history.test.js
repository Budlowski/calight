import "../../public/assets/offer-history.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const history = new CalightOfferHistory(10);
history.reset({ value: 0 });
for (let value = 1; value <= 12; value++) history.record({ value });
assert(
  history.past.length === 10,
  "History ist nicht auf zehn Schritte begrenzt.",
);
assert(
  history.undo().value === 11,
  "Undo liefert nicht den vorherigen Zustand.",
);
assert(
  history.redo().value === 12,
  "Redo stellt den Zustand nicht wieder her.",
);

history.undo();
history.record({ value: 99 });
assert(!history.canRedo, "Eine neue Änderung nach Undo verwirft Redo nicht.");

history.reset({ text: "" });
history.record({ text: "C" }, "title");
history.record({ text: "Ca" }, "title");
history.record({ text: "Cal" }, "title");
assert(
  history.past.length === 1,
  "Zusammenhängende Texteingaben wurden nicht gruppiert.",
);
assert(
  history.undo().text === "",
  "Gruppierte Eingabe lässt sich nicht vollständig rückgängig machen.",
);

history.reset({ text: "A" });
history.record({ text: "AB" }, "title");
history.endGroup();
history.record({ text: "ABC" }, "title");
assert(
  history.past.length === 2,
  "Getrennte Texteingaben wurden fälschlich zusammengefasst.",
);

history.reset({ nodes: [{ id: "title", children: [{ id: "position" }] }] });
history.record({ nodes: [] });
assert(
  history.undo().nodes[0].children[0].id === "position",
  "Gelöschte Hierarchie wurde nicht wiederhergestellt.",
);

history.reset({ value: "gespeichert" });
history.record({ value: "geändert" });
history.replaceCurrent({ value: "geändert", savedAt: "jetzt" });
assert(
  history.undo().value === "gespeichert",
  "Undo-History ging beim Speichern verloren.",
);
assert(
  history.redo().savedAt === "jetzt",
  "Redo nach dem Speichern stellt den Sitzungszustand nicht wieder her.",
);

console.log("offer history tests passed");
