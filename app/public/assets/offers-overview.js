(() => {
  "use strict";
  const root = document.querySelector("#offers-overview");
  if (!root) return;
  const list = document.querySelector("#offer-list"),
    search = document.querySelector("#offer-search"),
    filter = document.querySelector("#offer-status"),
    sort = document.querySelector("#offer-sort"),
    resultCount = document.querySelector("#offer-result-count"),
    message = document.querySelector("#offers-message"),
    form = document.querySelector("#new-offer-form"),
    newButton = document.querySelector("#new-offer"),
    importButton = document.querySelector("#import-new-offer"),
    importFile = document.querySelector("#import-new-file");
  let offers = [],
    formDirty = false;
  const esc = (v) =>
    String(v ?? "").replace(
      /[&<>'"]/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          "'": "&#39;",
          '"': "&quot;",
        })[c],
    );
  const labels = {
    draft: "Entwurf",
    submitted: "Abgegeben",
    commissioned: "Angenommen",
    rejected: "Abgelehnt",
  };
  const date = (v) =>
    new Intl.DateTimeFormat("de-DE", {
      dateStyle: "short",
      timeStyle: "short",
    }).format(new Date(v));
  const euro = (value) =>
    new Intl.NumberFormat("de-DE", {
      style: "currency",
      currency: "EUR",
    }).format(Number(value || 0));
  const collator = new Intl.Collator("de-DE", {
    numeric: true,
    sensitivity: "base",
  });
  function notify(text, type = "error") {
    message.textContent = text;
    message.className = `notice notice-${type}`;
    message.hidden = false;
  }
  async function confirmDiscard() {
    return (
      !formDirty ||
      (await CalightModal.confirm({
        title: "Ungespeicherte Eingaben",
        content: "Ungespeicherte Eingaben wirklich verwerfen?",
        confirmLabel: "Verwerfen",
        danger: true,
      }))
    );
  }
  async function openForm() {
    if (!(await confirmDiscard())) return;
    form.reset();
    form.hidden = false;
    formDirty = false;
    message.hidden = true;
    form.elements.customerId.focus();
  }
  function render() {
    const q = search.value.trim().toLocaleLowerCase("de"),
      status = filter.value,
      order = sort.value,
      rows = offers
        .filter(
          (o) =>
            (!status || o.status === status) &&
            (!q ||
              `${o.title} ${o.customerName || ""} ${o.projectName || ""} ${o.projectNumber || ""} ${o.offerNumber || ""}`
                .toLocaleLowerCase("de-DE")
                .includes(q)),
        )
        .sort((a, b) => {
          if (order === "title") return collator.compare(a.title, b.title);
          if (order === "number")
            return collator.compare(a.offerNumber || "", b.offerNumber || "");
          if (order === "net-desc")
            return Number(b.netTotal || 0) - Number(a.netTotal || 0);
          return String(b.updatedAt).localeCompare(String(a.updatedAt));
        });
    resultCount.textContent = `${rows.length} von ${offers.length}`;
    document.querySelector("#offer-reset").disabled =
      !q && !status && order === "updated";
    list.innerHTML = rows.length
      ? rows
          .map(
            (o) => {
              const assignment = o.projectId
                ? `${esc(o.projectNumber || "Ohne Projektnummer")} · ${esc(o.projectName)}`
                : `${esc(o.customerName)} · Ohne Projekt`;
              const projectAction = o.projectId
                ? `<a class="button button-light button-small" data-icon="folder-kanban" href="/projects/${encodeURIComponent(o.projectId)}">Projekt</a>`
                : "";
              return `<article class="service-list-item management-card"><div class="service-list-main"><div class="management-card-head"><span class="service-number">${esc(o.offerNumber || "Entwurf ohne Angebotsnummer")}${Number(o.revision || 0) > 0 ? ` · Revision ${Number(o.revision)}` : ""}</span><span class="status-badge status-${esc(o.status)}">${labels[o.status] || esc(o.status)}</span></div><strong>${esc(o.title)}</strong><span class="muted">${assignment}</span><span class="management-card-total">${euro(o.netTotal)} netto</span><span class="muted">${o.status === "draft" || !o.submittedAt ? `Zuletzt geändert ${date(o.updatedAt)}` : `Abgegeben ${date(o.submittedAt)}`}</span></div><div class="service-list-actions"><a class="button button-light button-small" data-icon="file-text" href="/offers/${encodeURIComponent(o.id)}">${o.status === "draft" ? "Öffnen" : "Ansehen"}</a>${projectAction}<button class="button button-light button-small" type="button" data-icon="copy" data-copy-offer="${o.id}">Kopieren</button>${o.status === "draft" ? `<button class="button button-light button-small button-danger-light" type="button" data-icon="trash" data-delete-offer="${o.id}" data-confirm-message="Angebotsentwurf ${esc(o.title)} endgültig löschen?">Löschen</button>` : ""}</div></article>`;
            },
          )
          .join("")
      : '<p class="card muted">Keine Angebote gefunden.</p>';
  }
  async function load() {
    const response = await fetch("/api/offers", { credentials: "same-origin" }),
      result = await response.json();
    if (!response.ok)
      throw new Error(result.error || "Angebote konnten nicht geladen werden.");
    offers = result.offers;
    render();
  }
  search.addEventListener("input", render);
  filter.addEventListener("change", render);
  sort.addEventListener("change", render);
  document.querySelector("#offer-reset").addEventListener("click", () => {
    search.value = "";
    filter.value = "";
    sort.value = "updated";
    render();
  });
  newButton.addEventListener("click", openForm);
  form.querySelector("[data-cancel]").addEventListener("click", async () => {
    if (!(await confirmDiscard())) return;
    form.reset();
    form.hidden = true;
    formDirty = false;
  });
  form.addEventListener("input", () => {
    formDirty = true;
  });
  form.elements.customerId.addEventListener("change",()=>{
    const customerId=form.elements.customerId.value;
    for(const option of form.elements.projectId.options){if(!option.value)continue;option.hidden=option.dataset.customerId!==customerId;}
    if(form.elements.projectId.selectedOptions[0]?.hidden)form.elements.projectId.value="";
  });
  list.addEventListener("click", async (event) => {
    const copyButton = event.target.closest("[data-copy-offer]");
    if (copyButton) {
      const offer = offers.find(
        (item) => item.id === copyButton.dataset.copyOffer,
      );
      if (!offer) return;
      copyButton.disabled = true;
      try {
        const response = await fetch(
            `/api/offers/${encodeURIComponent(offer.id)}/copy`,
            {
              method: "POST",
              credentials: "same-origin",
              headers: { "X-CSRF-Token": root.dataset.csrf },
            },
          ),
          result = await response.json();
        if (!response.ok)
          throw new Error(
            result.error || "Angebot konnte nicht kopiert werden.",
          );
        location.assign(result.url);
      } catch (error) {
        copyButton.disabled = false;
        notify(error.message);
      }
      return;
    }
    const button = event.target.closest("[data-delete-offer]");
    if (!button) return;
    const offer = offers.find((item) => item.id === button.dataset.deleteOffer);
    if (!offer) return;
    button.disabled = true;
    try {
      const response = await fetch(
          `/api/offers/${encodeURIComponent(offer.id)}`,
          {
            method: "DELETE",
            credentials: "same-origin",
            headers: { "X-CSRF-Token": root.dataset.csrf },
          },
        ),
        result = await response.json();
      if (!response.ok)
        throw new Error(
          result.error || "Entwurf konnte nicht gelöscht werden.",
        );
      offers = offers.filter((item) => item.id !== offer.id);
      render();
      notify("Angebotsentwurf gelöscht.", "success");
    } catch (error) {
      button.disabled = false;
      notify(error.message);
    }
  });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const response = await fetch("/api/offers", {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": root.dataset.csrf,
        },
        body: JSON.stringify({
          projectId: form.elements.projectId.value,
          customerId: form.elements.customerId.value,
          title: form.elements.title.value,
        }),
      }),
      result = await response.json();
    if (!response.ok)
      return notify(result.error || "Entwurf konnte nicht angelegt werden.");
    formDirty = false;
    location.assign(result.url);
  });
  importButton.onclick = () => {
    if (!form.elements.customerId.value)
      return notify("Bitte zuerst einen Kunden auswählen.");
    importFile.click();
  };
  importFile.onchange = async () => {
    const file = importFile.files[0];
    if (!file) return;
    try {
      if (file.size > 5_000_000)
        throw new Error("Die JSON-Datei darf höchstens 5 MB groß sein.");
      const offer = JSON.parse(await file.text()),
        response = await fetch("/api/offers/import", {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": root.dataset.csrf,
          },
          body: JSON.stringify({
            projectId: form.elements.projectId.value,
            customerId: form.elements.customerId.value,
            offer,
          }),
        }),
        result = await response.json();
      if (!response.ok)
        throw new Error((result.details || []).join(" ") || result.error);
      formDirty = false;
      location.assign(result.url);
    } catch (error) {
      notify(`Import fehlgeschlagen: ${error.message}`);
    } finally {
      importFile.value = "";
    }
  };
  window.addEventListener("beforeunload", (event) => {
    if (!formDirty) return;
    event.preventDefault();
    event.returnValue = "";
  });
  load().catch((error) => notify(error.message));
})();
