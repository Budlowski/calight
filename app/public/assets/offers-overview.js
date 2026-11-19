(() => {
  "use strict";

  const root = document.querySelector("#offers-overview");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const results = root.querySelector("#offer-list");
  const message = root.querySelector("#offers-message");
  const canWrite = root.dataset.canWrite === "1";
  const labels = { draft: "Entwurf", submitted: "Abgegeben", commissioned: "Angenommen", rejected: "Abgelehnt" };

  const href = (offer) => `/offers/${encodeURIComponent(offer.id)}`;
  const isChangeOrder = (offer) => offer.documentType === "change_order";
  // Die Dokumentart steht in eigener Spalte bzw. als Badge; Entwürfe ohne Nummer heißen einheitlich „Entwurf“.
  const number = (offer) => offer.offerNumber || "Entwurf";
  const revision = (offer) => (Number(offer.revision) > 0 ? ` · Revision ${Number(offer.revision)}` : "");
  const badge = (offer) => `<span class="status-badge status-${esc(offer.status)}">${esc(labels[offer.status] || offer.status)}</span>`;

  function actions(offer) {
    if (!canWrite) return "";
    const copy = `<button class="icon-button" type="button" data-icon="copy" data-copy-offer="${esc(offer.id)}" title="Kopieren" aria-label="Kopieren"></button>`;
    if (offer.status !== "draft") return copy;
    const label = isChangeOrder(offer) ? "Nachtragsentwurf" : "Angebotsentwurf";
    return `${copy}<button class="icon-button icon-button-danger" type="button" data-icon="trash" data-delete-offer="${esc(offer.id)}" data-confirm-message="${esc(`${label} ${offer.title} endgültig löschen?`)}" title="Löschen" aria-label="Löschen"></button>`;
  }

  const renderCard = (offer) => `
    <article class="overview-card" tabindex="0" data-href="${href(offer)}">
      <div class="management-card-head">
        <span class="service-number">${esc(number(offer))}${revision(offer)}</span>
        <span class="management-card-badges">${isChangeOrder(offer) ? '<span class="status-badge document-type-badge">Nachtrag</span>' : ""}${badge(offer)}</span>
      </div>
      <div class="overview-card-title" title="${esc(offer.title)}">${esc(offer.title)}</div>
      <div class="overview-card-meta">
        <span>${esc(offer.customerName)}</span>
        <span>${esc(offer.projectName || "Ohne Projekt")}</span>
        <span>${format.money(offer.netTotal)} netto</span>
        <span>${format.dateTime(offer.updatedAt)}</span>
      </div>
      ${canWrite ? `<div class="overview-card-actions">${actions(offer)}</div>` : ""}
    </article>`;

  const renderTable = (offers, header) => `
    <thead><tr>
      ${header("number", "Nummer", "cell-number")}${header("title", "Bezeichnung", "cell-title")}${header("customer", "Kunde", "cell-text")}${header("project", "Projekt", "cell-text")}
      <th class="cell-type">Dokumentart</th>${header("status", "Status", "cell-status")}${header("net", "Nettosumme", "cell-money")}
      ${header("updated", "Geändert", "cell-datetime")}${canWrite ? '<th class="cell-actions">Aktionen</th>' : ""}
    </tr></thead>
    <tbody>${offers.map((offer) => `
      <tr tabindex="0" data-href="${href(offer)}">
        <td class="cell-number" title="${esc(number(offer))}${revision(offer)}">${esc(number(offer))}${revision(offer)}</td>
        <td class="cell-title" title="${esc(offer.title)}">${esc(offer.title)}</td>
        <td class="cell-text" title="${esc(offer.customerName)}">${esc(offer.customerName)}</td>
        <td class="cell-text" title="${esc(offer.projectName || "")}">${esc(offer.projectName || "Ohne Projekt")}</td>
        <td class="cell-type">${isChangeOrder(offer) ? "Nachtrag" : "Angebot"}</td>
        <td class="cell-status">${badge(offer)}</td>
        <td class="cell-money">${format.money(offer.netTotal)}</td>
        <td class="cell-datetime">${format.dateTime(offer.updatedAt)}</td>
        ${canWrite ? `<td class="cell-actions">${actions(offer)}</td>` : ""}
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage({
    primaryFilters: ["status"],
    root,
    module: "offers",
    endpoint: "/api/offers",
    results,
    search: root.querySelector("#offer-search"),
    filters: { type: root.querySelector("#offer-document-type"), status: root.querySelector("#offer-status") },
    sortSelect: root.querySelector("#offer-sort"),
    count: root.querySelector("#offer-result-count"),
    reset: root.querySelector("#offer-reset"),
    message,
    defaultSort: "updated",
    sorts: {
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
      number: { label: "Nummer", dir: "asc" },
      title: { label: "Bezeichnung", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      project: { label: "Projekt", dir: "asc" },
      status: { label: "Status", dir: "asc" },
      net: { label: "Nettosumme", type: "number", dir: "desc" },
    },
    emptyText: "Noch keine Angebote vorhanden.",
    noMatchText: "Keine Angebote entsprechen der aktuellen Auswahl.",
    loadingText: "Angebote werden geladen …",
    renderTable,
    renderCard,
  });

  function createOffer() {
    const form = root.querySelector("#new-offer-template").content.firstElementChild.cloneNode(true);
    const file = form.querySelector("[data-import-file]");
    const basics = () => ({ projectId: form.elements.projectId.value, customerId: form.elements.customerId.value });
    Calight.bindProjectToCustomer(form);
    Calight.searchableSelect(form.elements.customerId, { placeholder: "Kundennummer oder Name eingeben …" });

    const dialog = Calight.createDialog({
      title: "Angebot anlegen",
      form,
      submitLabel: "Entwurf anlegen",
      discardMessage: "Den begonnenen Angebotsentwurf verwerfen?",
      initialFocus: ".suggest-select input",
      submit: async () => {
        const result = await api("/api/offers", { method: "POST", body: { ...basics(), title: form.elements.title.value } });
        location.assign(result.url);
      },
      extraActions: [{
        label: "JSON importieren",
        onClick: () => {
          if (!form.elements.customerId.value) {
            dialog.showError("Bitte zuerst einen Kunden auswählen.");
            return false;
          }
          file.value = "";
          file.click();
          return false;
        },
      }],
    });

    // Der Import startet erst nach der Dateiauswahl, nicht beim Klick auf den Button.
    file.addEventListener("change", async () => {
      const selected = file.files[0];
      if (!selected) return;
      try {
        if (selected.size > 5_000_000) throw new Error("Die JSON-Datei darf höchstens 5 MB groß sein.");
        let offer;
        try {
          offer = JSON.parse(await selected.text());
        } catch {
          throw new Error("Die Datei enthält kein gültiges JSON.");
        }
        const result = await api("/api/offers/import", { method: "POST", body: { ...basics(), offer } });
        location.assign(result.url);
      } catch (exception) {
        dialog.showError(exception.message);
      }
    });
  }

  async function copyOffer(button) {
    button.disabled = true;
    try {
      const result = await api(`/api/offers/${encodeURIComponent(button.dataset.copyOffer)}/copy`, { method: "POST" });
      location.assign(result.url);
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  async function deleteOffer(button) {
    const id = button.dataset.deleteOffer;
    button.disabled = true;
    try {
      await api(`/api/offers/${encodeURIComponent(id)}`, { method: "DELETE" });
      await list.reload();
      notify(message, "Entwurf wurde gelöscht.", "success");
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  root.querySelector("#new-offer")?.addEventListener("click", createOffer);
  results.addEventListener("click", (event) => {
    const copy = event.target.closest("[data-copy-offer]");
    const remove = event.target.closest("[data-delete-offer]");
    // Die Löschbestätigung übernimmt ui.js zentral über [data-delete-offer].
    if (copy) copyOffer(copy);
    else if (remove) deleteOffer(remove);
  });
  list.start();
})();
