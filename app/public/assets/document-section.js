// Dokumentbereich eines Geschäftsobjekts (Kunde, Projekt, Angebot, Auftrag): zeigt direkt und
// indirekt zugehörige Dokumente; allgemeine Anhänge lassen sich hochladen und entfernen.
// <section data-document-section data-target-type="order" data-target-id="…" data-can-upload="1"
//          data-contacts='[{"id":"…","name":"…"}]'>  (Ansprechpartner nur beim Kunden)
(() => {
  "use strict";

  const { esc, api, notify } = Calight;
  const MAX_SHOWN = 100;

  function setup(section) {
    const type = section.dataset.targetType;
    const id = section.dataset.targetId;
    const canUpload = section.dataset.canUpload === "1";
    const contacts = (() => {
      try {
        return JSON.parse(section.dataset.contacts || "[]");
      } catch {
        return [];
      }
    })();
    section.innerHTML = `
      <div class="step-section-heading">
        <div><h2>Dokumente</h2><small>Dokumente der Vorgänge und eigene Anhänge (PDF oder Bild)</small></div>
        <div class="step-section-actions">${canUpload ? '<button class="button button-light button-small" type="button" data-upload data-icon="file-up">Dokument hochladen</button>' : ""}</div>
      </div>
      <div class="notice" data-message role="status" hidden></div>
      <div data-documents><p class="empty-state">Dokumente werden geladen …</p></div>`;
    const message = section.querySelector("[data-message]");
    const box = section.querySelector("[data-documents]");
    const { title, objects, date } = globalThis.CalightDocuments;

    async function load() {
      try {
        const page = await api(`/api/documents?${encodeURIComponent(type)}=${encodeURIComponent(id)}&per_page=${MAX_SHOWN}`);
        box.innerHTML = page.items.length ? `<ul class="document-list">${page.items.map((document) => {
          // Entfernen nur für eigene Anhänge: löst die Zuordnung zu diesem Objekt.
          const link = document.removable ? document.objects.find((object) => object.type === type && object.id === id) : null;
          return `<li class="document-list-item">
            <div class="document-list-main">${title(document)}<small class="muted">${esc(document.typeLabel)} · ${date(document)}${document.description ? ` · ${esc(document.description)}` : ""}</small>
              <small>${objects(document)}</small></div>
            ${link?.canUnlink ? `<button class="icon-button icon-button-danger" type="button" data-unlink="${esc(document.id.replace("attachment:", ""))}" data-icon="trash" title="Anhang entfernen" aria-label="Anhang ${esc(document.title)} entfernen"></button>` : ""}
          </li>`;
        }).join("")}</ul>${page.total > MAX_SHOWN ? `<p class="muted">Weitere Dokumente in der <a href="/documents">Dokumentenübersicht</a>.</p>` : ""}`
          : '<p class="empty-state">Noch keine Dokumente.</p>';
      } catch (exception) {
        box.innerHTML = "";
        notify(message, exception.message);
      }
    }

    section.addEventListener("click", async (event) => {
      if (event.target.closest("[data-upload]")) {
        openUpload();
        return;
      }
      const button = event.target.closest("[data-unlink]");
      if (!button) return;
      const ok = await CalightModal.confirm({
        title: "Anhang entfernen",
        content: "Die Zuordnung zu diesem Objekt wird entfernt. Ist der Anhang nirgends sonst zugeordnet, wird die Datei gelöscht.",
        confirmLabel: "Entfernen",
        danger: true,
      });
      if (!ok) return;
      try {
        const result = await api(`/api/attachments/${encodeURIComponent(button.dataset.unlink)}/links/${encodeURIComponent(type)}/${encodeURIComponent(id)}`, { method: "DELETE", body: {} });
        notify(message, result.deleted ? "Anhang gelöscht." : "Zuordnung entfernt; der Anhang bleibt bei den übrigen Objekten.", "success");
        await load();
      } catch (exception) {
        notify(message, exception.message);
      }
    });

    function openUpload() {
      const form = document.createElement("form");
      form.className = "form-stack";
      form.noValidate = true;
      form.innerHTML = `
        <label><span class="field-label">Datei (PDF oder Bild, max. 15 MB) <span aria-hidden="true">*</span></span><input name="file" type="file" accept="application/pdf,image/*" required></label>
        <label><span class="field-label">Titel (optional)</span><input name="title" maxlength="200" autocomplete="off" placeholder="Leer = Dateiname"></label>
        <label><span class="field-label">Beschreibung (optional)</span><textarea name="description" rows="2" maxlength="1000"></textarea></label>
        ${contacts.length ? `<label><span class="field-label">Zusätzlich Ansprechpartner zuordnen (optional)</span><select name="contact"><option value="">Kein Ansprechpartner</option>${contacts.map((contact) => `<option value="${esc(contact.id)}">${esc(contact.name)}</option>`).join("")}</select></label>` : ""}
        <p class="field-error" data-dialog-error hidden></p>`;
      Calight.createDialog({
        title: "Dokument hochladen",
        form,
        submitLabel: "Hochladen",
        discardMessage: "Das Hochladen abbrechen?",
        submit: async () => {
          const file = form.elements.file.files[0];
          if (!file) throw new Error("Bitte eine Datei auswählen.");
          const data = new FormData();
          data.append("file", file);
          data.append("title", form.elements.title.value.trim());
          data.append("description", form.elements.description.value.trim());
          data.append("targets[]", `${type}:${id}`);
          if (form.elements.contact?.value) data.append("targets[]", `contact:${form.elements.contact.value}`);
          const response = await fetch("/api/attachments", {
            method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": document.querySelector("[data-csrf]")?.dataset.csrf || "", Accept: "application/json" }, body: data,
          });
          const result = await response.json().catch(() => ({}));
          if (!response.ok) throw new Error((Array.isArray(result.details) ? result.details.join(" ") : "") || result.error || `Hochladen fehlgeschlagen (HTTP ${response.status}).`);
          notify(message, "Dokument hochgeladen.", "success");
          await load();
        },
      });
    }

    load();
  }

  document.querySelectorAll("[data-document-section]").forEach(setup);
})();
