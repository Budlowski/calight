// Zentrale Dokumentenübersicht (nur lesend): Suche, Dokumentart, Sortierung, Seiten.
// Hochladen und Verwalten bleibt beim jeweiligen Geschäftsobjekt (document-section.js).
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;

  /** Gemeinsame Darstellung eines Dokuments (auch für die Bereiche der Geschäftsobjekte). */
  const icon = (document) => (document.mimeType === "application/pdf" ? "file-text" : "file-down");
  const title = (document) => `<a class="document-title" href="${esc(document.url)}" target="_blank" rel="noopener" data-icon="${icon(document)}" title="Öffnen">${esc(document.title)}</a>`;
  const objects = (document) => document.objects.map((object) => `<a href="${esc(object.href)}">${esc(object.label)}</a>`).join('<span class="muted"> · </span>');
  const date = (document) => (document.date ? format.date(String(document.date).slice(0, 10)) : "–");
  globalThis.CalightDocuments = Object.freeze({ title, objects, date });

  const root = document.querySelector("#documents-app");
  if (!root) return;
  const message = root.querySelector("[data-message]");
  const typeFilter = root.querySelector("[data-type-filter]");

  const renderTable = (items, header) => `
    <thead><tr>
      ${header("title", "Dokument", "cell-title document-cell-title")}${header("type", "Dokumentart", "cell-type")}
      <th class="cell-text document-cell-objects">Zugeordnet zu</th>${header("date", "Datum", "cell-date")}
    </tr></thead>
    <tbody>${items.map((document) => `
      <tr>
        <td class="cell-title document-cell-title" title="${esc(document.fileName)}">${title(document)}</td>
        <td class="cell-type"><span class="status-badge">${esc(document.typeLabel)}</span></td>
        <td class="cell-text document-cell-objects">${objects(document)}</td>
        <td class="cell-date">${date(document)}</td>
      </tr>`).join("")}</tbody>`;

  const renderCard = (document) => `
    <article class="overview-card document-card">
      <div class="management-card-head"><span class="status-badge">${esc(document.typeLabel)}</span><span class="muted">${date(document)}</span></div>
      <div class="overview-card-title">${title(document)}</div>
      <div class="overview-card-meta">${objects(document)}</div>
    </article>`;

  const list = CalightListView.createListPage({
    root,
    module: "documents",
    endpoint: "/api/documents",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: { type: typeFilter },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "date",
    sorts: {
      date: { label: "Datum", type: "date", dir: "desc" },
      title: { label: "Titel A–Z", dir: "asc" },
      type: { label: "Dokumentart", dir: "asc" },
    },
    emptyText: "Noch keine Dokumente vorhanden.",
    noMatchText: "Keine Dokumente entsprechen der aktuellen Auswahl.",
    loadingText: "Dokumente werden geladen …",
    renderTable,
    renderCard,
  });

  api("/api/documents/types")
    .then((labels) => {
      typeFilter.innerHTML = '<option value="">Alle Dokumentarten</option>' + Object.entries(labels).map(([value, label]) => `<option value="${esc(value)}">${esc(label)}</option>`).join("");
      list.start();
    })
    .catch((exception) => notify(message, exception.message));
})();
