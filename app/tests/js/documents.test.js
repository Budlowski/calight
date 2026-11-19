// Dokumente (Phase 3.4.2): gemeinsame Darstellung für Übersicht und Objektbereiche.
import "../../public/assets/calight.js";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

globalThis.document = { querySelector: () => null, querySelectorAll: () => [] };
await import("../../public/assets/documents.js");
const { title, objects, date } = globalThis.CalightDocuments;

const pdf = { title: 'Abnahme <script>', url: "/api/attachments/1", mimeType: "application/pdf", objects: [{ label: "Kunde Müller & Co", href: "/customers/1" }, { label: "Auftrag A1", href: "/orders/2" }], date: "2026-11-19T10:00:00Z" };
const link = title(pdf);
assert(link.includes('href="/api/attachments/1"') && link.includes('target="_blank"') && link.includes('rel="noopener"'), "title opens the document in a new tab");
assert(link.includes("&lt;script&gt;") && !link.includes("<script>"), "title is escaped");
assert(link.includes('data-icon="file-text"'), "PDFs use the document icon");
assert(title({ ...pdf, mimeType: "image/png" }).includes('data-icon="file-down"'), "other files use the download icon");
const related = objects(pdf);
assert(related.includes('href="/customers/1"') && related.includes("Müller &amp; Co") && related.includes('href="/orders/2"'), "related objects link to their pages");
assert(date(pdf) === "19.11.2026", `date uses German format: ${date(pdf)}`);
assert(date({ date: null }) === "–", "missing date renders a dash");

// Der Objektbereich lädt sich nur bei vorhandenen Abschnitten.
await import("../../public/assets/document-section.js");

console.log("documents.test.js ok");
