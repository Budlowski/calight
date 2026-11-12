(() => {
  "use strict";

  const root = document.querySelector("#offers-overview");
  if (!root) return;

  const { esc, api, format, collator, normalize, matches, notify } = Calight;
  const results = root.querySelector("#offer-list");
  const search = root.querySelector("#offer-search");
  const documentType = root.querySelector("#offer-document-type");
  const status = root.querySelector("#offer-status");
  const sort = root.querySelector("#offer-sort");
  const count = root.querySelector("#offer-result-count");
  const reset = root.querySelector("#offer-reset");
  const message = root.querySelector("#offers-message");
  const canWrite = root.dataset.canWrite === "1";
  const labels = { draft: "Entwurf", submitted: "Abgegeben", commissioned: "Angenommen", rejected: "Abgelehnt" };

  let offers = [];
  let visible = [];

  const href = (offer) => `/offers/${encodeURIComponent(offer.id)}`;
  const isChangeOrder = (offer) => offer.documentType === "change_order";
  const number = (offer) => offer.offerNumber || (isChangeOrder(offer) ? "Nachtragsentwurf" : "Entwurf");
  const revision = (offer) => (Number(offer.revision) > 0 ? ` · Revision ${Number(offer.revision)}` : "");
  const badge = (offer) => `<span class="status-badge status-${esc(offer.status)}">${esc(labels[offer.status] || offer.status)}</span>`;

  function actions(offer) {
    if (!canWrite) return "";
    const copy = `<button class="icon-button" type="button" data-icon="copy" data-copy-offer="${esc(offer.id)}" title="Kopieren" aria-label="Kopieren"></button>`;
    if (offer.status !== "draft") return copy;
    const label = isChangeOrder(offer) ? "Nachtragsentwurf" : "Angebotsentwurf";
    return `${copy}<button class="icon-button icon-button-danger" type="button" data-icon="trash" data-delete-offer="${esc(offer.id)}" data-confirm-message="${esc(`${label} ${offer.title} endgültig löschen?`)}" title="Löschen" aria-label="Löschen"></button>`;
  }

  const card = (offer) => `
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

  const table = () => `
    <div class="overview-table-wrap"><table class="overview-table">
      <thead><tr><th>Nummer</th><th>Bezeichnung</th><th>Kunde</th><th>Projekt</th><th>Dokumentart</th><th>Status</th><th class="cell-money">Nettosumme</th><th>Geändert</th>${canWrite ? "<th>Aktionen</th>" : ""}</tr></thead>
      <tbody>${visible.map((offer) => `
        <tr tabindex="0" data-href="${href(offer)}">
          <td class="cell-number">${esc(number(offer))}${revision(offer)}</td>
          <td class="cell-title" title="${esc(offer.title)}">${esc(offer.title)}</td>
          <td>${esc(offer.customerName)}</td>
          <td>${esc(offer.projectName || "Ohne Projekt")}</td>
          <td>${isChangeOrder(offer) ? "Nachtrag" : "Angebot"}</td>
          <td>${badge(offer)}</td>
          <td class="cell-money">${format.money(offer.netTotal)}</td>
          <td class="cell-date">${format.dateTime(offer.updatedAt)}</td>
          ${canWrite ? `<td class="cell-actions">${actions(offer)}</td>` : ""}
        </tr>`).join("")}</tbody>
    </table></div>`;

  function paint(view) {
    if (!visible.length) {
      results.innerHTML = '<p class="overview-empty">Keine Angebote entsprechen der aktuellen Auswahl.</p>';
      return;
    }
    results.innerHTML = view === "cards" ? `<div class="overview-card-grid">${visible.map(card).join("")}</div>` : table();
  }

  const view = CalightListView.create({ root, module: "offers", render: paint });
  CalightListView.enableRowNavigation(results);

  function render() {
    const query = normalize(search.value.trim());
    visible = offers
      .filter((offer) => (!status.value || offer.status === status.value)
        && (!documentType.value || offer.documentType === documentType.value)
        && matches(query, offer.title, offer.customerName, offer.projectName, offer.projectNumber, offer.offerNumber, offer.orderNumber))
      .sort((a, b) => {
        if (sort.value === "title") return collator.compare(a.title, b.title);
        if (sort.value === "number") return collator.compare(a.offerNumber || "", b.offerNumber || "");
        if (sort.value === "net-desc") return Number(b.netTotal || 0) - Number(a.netTotal || 0);
        return String(b.updatedAt).localeCompare(String(a.updatedAt));
      });
    count.textContent = `${visible.length} von ${offers.length}`;
    reset.disabled = !query && !status.value && !documentType.value && sort.value === "updated";
    view.render();
  }

  async function load() {
    results.innerHTML = '<p class="overview-empty">Angebote werden geladen …</p>';
    offers = (await api("/api/offers")).offers;
    render();
  }

  function createOffer() {
    const form = root.querySelector("#new-offer-template").content.firstElementChild.cloneNode(true);
    const file = form.querySelector("[data-import-file]");
    const basics = () => ({ projectId: form.elements.projectId.value, customerId: form.elements.customerId.value });
    Calight.bindProjectToCustomer(form);

    const dialog = Calight.createDialog({
      title: "Neues Angebot",
      form,
      submitLabel: "Entwurf anlegen",
      discardMessage: "Den begonnenen Angebotsentwurf verwerfen?",
      initialFocus: '[name="customerId"]',
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
      offers = offers.filter((item) => item.id !== id);
      render();
      notify(message, "Entwurf wurde gelöscht.", "success");
    } catch (exception) {
      button.disabled = false;
      notify(message, exception.message);
    }
  }

  for (const field of [search, documentType, status, sort]) field.addEventListener(field === search ? "input" : "change", render);
  reset.addEventListener("click", () => {
    search.value = "";
    documentType.value = "";
    status.value = "";
    sort.value = "updated";
    render();
    search.focus();
  });
  root.querySelector("#new-offer")?.addEventListener("click", createOffer);
  results.addEventListener("click", (event) => {
    const copy = event.target.closest("[data-copy-offer]");
    const remove = event.target.closest("[data-delete-offer]");
    // Die Löschbestätigung übernimmt ui.js zentral über [data-delete-offer].
    if (copy) copyOffer(copy);
    else if (remove) deleteOffer(remove);
  });
  load().catch((error) => notify(message, error.message));
})();
