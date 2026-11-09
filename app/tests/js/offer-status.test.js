import "../../public/assets/offer-status.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const nodes = [
  {
    id: "t1",
    type: "title",
    children: [
      { id: "p1", type: "position", workStatus: "done" },
      {
        id: "t2",
        type: "title",
        children: [
          { id: "p2", type: "position", workStatus: "open" },
          { id: "p3", type: "position", workStatus: "in_progress" },
        ],
      },
    ],
  },
];

assert(
  CalightOfferStatus.normalized(undefined) === "open",
  "Legacy-Status wird nicht als offen behandelt.",
);
assert(
  CalightOfferStatus.derived(nodes) === "in_progress",
  "Gemischter Titelstatus wurde falsch ermittelt.",
);
assert(
  CalightOfferStatus.derived([]) === "neutral",
  "Leerer Titel ist nicht neutral.",
);
const progress = CalightOfferStatus.progress(nodes);
assert(
  progress.done === 1 && progress.total === 3 && progress.percent === 33,
  "Angebotsfortschritt wurde falsch berechnet.",
);
assert(
  CalightOfferStatus.visible(nodes[0], "done"),
  "Titel mit passender Position fehlt im Filter.",
);
assert(
  !CalightOfferStatus.visible(nodes[0].children[1], "done"),
  "Titel ohne passende Position bleibt im Filter sichtbar.",
);
assert(
  CalightOfferStatus.percentageClass(0) === "bar-pct-0",
  "Nullanteil erhält eine falsche Balkenklasse.",
);
assert(
  CalightOfferStatus.percentageClass(0.2) === "bar-pct-1",
  "Sehr kleine Anteile bleiben unsichtbar.",
);
assert(
  CalightOfferStatus.percentageClass(54.4) === "bar-pct-54",
  "Prozentanteil wird falsch gerundet.",
);
assert(
  CalightOfferStatus.percentageClass(140) === "bar-pct-100",
  "Balkenbreite wird nicht begrenzt.",
);

console.log("offer status tests passed");
