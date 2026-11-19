// Erfassungsdialoge für Bestellvorgänge (AB, Lieferung, Belege, Zahlung, Ist-Kosten,
// Kostenverteilung, Dokument). Gemeinsam genutzt von Bestelldetail und Bestellübersicht.
// Regeln und Rechte prüft ausschließlich der Server.
(() => {
  "use strict";

  const { esc, api, exact } = Calight;
  const decimal = (value) => String(value ?? "").trim().replace(",", ".");
  const inputValue = (value) => (value === null || value === undefined ? "" : String(value).replace(".", ","));

  /**
   * @param {{ getOrder: () => object, getReference: () => object, csrf: string, onSaved: (order: object, message: string) => void }} context
   */
  function create(context) {
    const shared = globalThis.CalightPurchaseOrders;
    const { money, today } = shared;
    let order = null;
    let reference = null;
    let saved = null;
    const csrf = context.csrf;
    const url = (suffix = "") => `/api/purchase-orders/${encodeURIComponent(order.id)}${suffix}`;
    const costs = () => Boolean(reference?.canCosts);
    // Neuer Stand wird gemerkt und mit der Erfolgsmeldung an die Seite übergeben.
    const adopt = (next) => { saved = next; };
    const success = (text) => context.onSaved(saved, text);
    // ---------------------------------------------------------------------------
    // Dialoge (je Aktion ein kleiner Dialog)
    // ---------------------------------------------------------------------------
    const field = (label, control, className = "") => `<label${className ? ` class="${className}"` : ""}><span class="field-label">${label}</span>${control}</label>`;
    const fileField = (label = "Beleg als PDF oder Bild (optional, max. 15 MB)") => field(label, '<input name="file" type="file" accept="application/pdf,image/*">', "span-all");
    const options = (labels, selected = "") => Object.entries(labels).map(([value, text]) => `<option value="${esc(value)}"${value === selected ? " selected" : ""}>${esc(text)}</option>`).join("");
    const dialogForm = (html) => {
      const form = document.createElement("form");
      form.className = "form-stack";
      form.noValidate = true;
      form.innerHTML = `${html}<p class="field-error" data-dialog-error hidden></p>`;
      return form;
    };
    const values = (form) => Object.fromEntries([...new FormData(form).entries()].filter(([key]) => key !== "file").map(([key, value]) => [key, String(value).trim()]));
    const moneyFields = (data, names) => {
      for (const name of names) if (name in data) data[name] = decimal(data[name]);
      return data;
    };

    /** Vorgangsdialog: sendet Felder (und optional eine Datei) und übernimmt den neuen Stand. */
    function eventDialog({ title, html, submitLabel, path, prepare = (data) => data, setup = null, json = false, done }) {
      const form = dialogForm(html);
      setup?.(form);
      Calight.createDialog({
        title,
        form,
        submitLabel,
        discardMessage: "Die Eingaben verwerfen?",
        submit: async () => {
          const data = prepare(values(form), form);
          data.version = order.version;
          const file = form.elements.file?.files?.[0] || null;
          adopt(json ? await api(url(path), { method: "POST", body: data }) : await shared.postForm(url(path), data, file, csrf));
          success(done);
        },
      });
    }

    const dialogs = {
      confirmation() {
        eventDialog({
          title: "AB erfassen",
          submitLabel: "Erfassen",
          path: "/confirmations",
          done: "Auftragsbestätigung erfasst.",
          html: `<div class="dialog-form-grid">
              ${field("AB-Nummer (optional)", '<input name="confirmationNumber" maxlength="100" autocomplete="off">')}
              ${field('Bestätigungsdatum <span aria-hidden="true">*</span>', `<input name="confirmationDate" type="date" required value="${today()}">`)}
              ${costs() ? field("Bestätigter Nettobetrag (optional)", '<input name="confirmedNetTotal" inputmode="decimal" placeholder="0,00">') : ""}
              ${field("Bestätigter Liefertermin (optional)", '<input name="confirmedDeliveryDate" type="date">')}
              ${fileField()}
              ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000"></textarea>', "span-all")}
            </div>
            ${order.confirmations.length ? '<label class="check-inline"><input type="checkbox" name="makeCurrent" value="1" checked> Als aktuelle Bestätigung verwenden (frühere bleiben erhalten)</label>' : ""}`,
          prepare: (data, form) => {
            moneyFields(data, ["confirmedNetTotal"]);
            if (order.confirmations.length) data.makeCurrent = form.elements.makeCurrent.checked;
            return data;
          },
        });
      },

      delivery() {
        const hints = {
          partial: "Lieferstatus danach: Teilgeliefert.",
          complete: "Bestätigt die vollständige Lieferung, ohne jede Position abzugleichen. Lieferstatus danach: Geliefert.",
          return: "Rücksendung an den Lieferanten. Die Kosten ändern sich dadurch nicht – eine Gutschrift wird separat erfasst.",
          correction: "Korrigiert den Lieferstatus, z. B. nach einer Fehlerfassung.",
        };
        eventDialog({
          title: "Lieferung erfassen",
          submitLabel: "Erfassen",
          path: "/deliveries",
          done: "Lieferung erfasst.",
          html: `<div class="dialog-form-grid">
              ${field('Art <span aria-hidden="true">*</span>', `<select name="kind" required>${options(reference.deliveryKinds, "partial")}</select>`)}
              ${field('Lieferdatum <span aria-hidden="true">*</span>', `<input name="deliveryDate" type="date" required value="${today()}">`)}
              ${field("Lieferscheinnummer (optional)", '<input name="deliveryNoteNumber" maxlength="100" autocomplete="off">')}
              <label data-resulting hidden><span class="field-label">Lieferstatus danach <span aria-hidden="true">*</span></span><select name="resultingStatus">${options(reference.deliveryLabels, "partial")}</select></label>
              ${fileField("Lieferschein als PDF oder Bild (optional)")}
              ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000" placeholder="z. B. zurückgesandte Teile, Grund der Korrektur"></textarea>', "span-all")}
            </div>
            <p class="muted" data-delivery-hint></p>`,
          setup: (form) => {
            const sync = () => {
              const kind = form.elements.kind.value;
              const choose = kind === "return" || kind === "correction";
              form.querySelector("[data-resulting]").hidden = !choose;
              form.elements.resultingStatus.required = choose;
              form.querySelector("[data-delivery-hint]").textContent = hints[kind] || "";
            };
            form.elements.kind.addEventListener("change", sync);
            sync();
          },
          prepare: (data) => {
            if (data.kind === "partial" || data.kind === "complete") delete data.resultingStatus;
            return data;
          },
        });
      },

      invoice(kind = "invoice", related = null) {
        const credit = kind === "credit";
        const reversal = kind === "reversal";
        const originals = order.invoices.filter((invoice) => invoice.kind !== "reversal");
        const kindChoice = credit || reversal ? "" : field('Belegart <span aria-hidden="true">*</span>', `<select name="kind">${options(order.hasTotalFinal ? { invoice: "Rechnung" } : { invoice: "Rechnung", partial: "Teilrechnung", final: "Schlussrechnung" }, "invoice")}</select>`);
        const relatedChoice = credit && originals.length
          ? field("Zu Originalbeleg (optional)", `<select name="relatedInvoiceId"><option value="">Ohne Bezug</option>${originals.map((invoice) => `<option value="${esc(invoice.id)}">${esc(invoice.kindLabel)} ${esc(invoice.invoiceNumber)} · ${money(invoice.netAmount)}</option>`).join("")}</select>`, "span-all") : "";
        eventDialog({
          title: reversal ? `${related.kindLabel} ${related.invoiceNumber} stornieren` : credit ? "Gutschrift erfassen" : "Rechnung erfassen",
          submitLabel: reversal ? "Stornobeleg erfassen" : "Erfassen",
          path: "/invoices",
          done: reversal ? "Stornobeleg erfasst. Der Originalbeleg bleibt erhalten." : credit ? "Gutschrift erfasst." : "Beleg erfasst.",
          html: `${reversal ? `<p>Der Originalbeleg bleibt erhalten. Der Stornobeleg neutralisiert ihn ganz oder teilweise (noch nicht storniert: <strong>${money(related.remaining)}</strong>).</p>` : ""}
            <div class="dialog-form-grid">
              ${kindChoice}
              <fieldset class="span-all purchase-final-mode" data-final-mode hidden>
                <legend class="field-label">Die Schlussrechnung weist aus …</legend>
                ${Object.entries(reference.finalModes).map(([value, text], index) => `<label class="check-inline"><input type="radio" name="finalMode" value="${esc(value)}"${index === 0 ? " checked" : ""}> ${esc(text)}</label>`).join("")}
                <small class="muted">Bei der Gesamtrechnung zieht Calight die bereits erfassten Teilrechnungen ab, damit nichts doppelt zählt.</small>
              </fieldset>
              ${relatedChoice}
              ${field(reversal ? "Belegnummer (optional)" : 'Belegnummer <span aria-hidden="true">*</span>', `<input name="invoiceNumber" maxlength="100" autocomplete="off"${reversal ? "" : " required"}>`)}
              ${field('Belegdatum <span aria-hidden="true">*</span>', `<input name="invoiceDate" type="date" required value="${today()}">`)}
              ${field('Nettobetrag <span aria-hidden="true">*</span>', `<input name="netAmount" inputmode="decimal" required placeholder="0,00" value="${reversal ? esc(inputValue(related.remaining)) : ""}">`)}
              ${field("Umsatzsteuer (optional)", '<input name="vatAmount" inputmode="decimal" placeholder="0,00">')}
              ${field("Bruttobetrag (optional)", '<input name="grossAmount" inputmode="decimal" placeholder="0,00">')}
              ${fileField()}
              ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000"></textarea>', "span-all")}
            </div>
            ${order.directActualNetTotal !== null ? `<label class="check-inline notice notice-warning"><input type="checkbox" name="replaceDirectCost" value="1" required> Die direkt erfassten Ist-Kosten (${money(order.directActualNetTotal)}) durch die Belege ersetzen</label>` : ""}`,
          setup: (form) => {
            const sync = () => { form.querySelector("[data-final-mode]").hidden = form.elements.kind?.value !== "final"; };
            form.elements.kind?.addEventListener("change", sync);
            sync();
          },
          prepare: (data, form) => {
            moneyFields(data, ["netAmount", "vatAmount", "grossAmount"]);
            data.kind = credit ? "credit" : reversal ? "reversal" : form.elements.kind.value;
            if (data.kind !== "final") delete data.finalMode;
            if (reversal) data.relatedInvoiceId = related.id;
            if (form.elements.replaceDirectCost) data.replaceDirectCost = form.elements.replaceDirectCost.checked;
            return data;
          },
        });
      },

      credit() {
        dialogs.invoice("credit");
      },

      payment() {
        eventDialog({
          title: "Zahlung erfassen",
          submitLabel: "Erfassen",
          path: "/payments",
          json: true,
          done: "Zahlungsvermerk erfasst.",
          html: `<div class="dialog-form-grid">
              ${field('Art <span aria-hidden="true">*</span>', `<select name="kind">${options(reference.paymentKinds, "payment")}</select>`)}
              ${field('Datum <span aria-hidden="true">*</span>', `<input name="paymentDate" type="date" required value="${today()}">`)}
              <label data-amount><span class="field-label">Betrag <span aria-hidden="true">*</span></span><input name="amount" inputmode="decimal" placeholder="0,00"></label>
              <label data-resulting hidden><span class="field-label">Zahlungsstatus danach <span aria-hidden="true">*</span></span><select name="resultingStatus">${options(reference.paymentLabels, "partial")}</select></label>
              <label class="check-inline span-all" data-complete><input type="checkbox" name="complete" value="1"> Damit vollständig bezahlt</label>
              ${field("Bemerkung (optional)", '<textarea name="notes" rows="2" maxlength="1000" placeholder="z. B. Vorkasse, Lastschrift, Rückerstattung nach Gutschrift"></textarea>', "span-all")}
            </div>`,
          setup: (form) => {
            const sync = () => {
              const kind = form.elements.kind.value;
              form.querySelector("[data-amount]").hidden = kind === "correction";
              form.elements.amount.required = kind !== "correction";
              form.querySelector("[data-resulting]").hidden = kind === "payment";
              form.querySelector("[data-complete]").hidden = kind !== "payment";
            };
            form.elements.kind.addEventListener("change", sync);
            sync();
          },
          prepare: (data, form) => {
            moneyFields(data, ["amount"]);
            data.complete = form.elements.complete.checked;
            if (data.kind === "payment") delete data.resultingStatus;
            if (data.kind === "correction") delete data.amount;
            return data;
          },
        });
      },

      direct() {
        const form = dialogForm(`<p class="muted">Für einfache Käufe ohne Belegerfassung (z. B. Baumarkt, Onlinekauf). Nicht möglich, sobald Belege erfasst sind. Leer lassen entfernt den Betrag.</p>
          ${field("Tatsächliche Kosten netto", `<input name="amount" inputmode="decimal" placeholder="0,00" value="${esc(inputValue(order.directActualNetTotal))}">`)}`);
        Calight.createDialog({
          title: "Ist-Kosten direkt erfassen",
          form,
          submitLabel: "Speichern",
          discardMessage: "Die Eingaben verwerfen?",
          submit: async () => {
            adopt(await api(url("/actual-cost"), { method: "PUT", body: { version: order.version, amount: decimal(form.elements.amount.value) } }));
            success("Ist-Kosten gespeichert.");
          },
        });
      },

      allocations() {
        const rows = (order.allocations || []).map((allocation) => ({ orderId: allocation.orderId, amount: inputValue(allocation.amount) }));
        const form = dialogForm(`<p class="muted">Ohne Verteilung gehen die Ist-Kosten (${money(order.actualNetTotal)}) vollständig an den Auftrag der Bestellung. Mit Verteilung gelten genau die eingetragenen Beträge – höchstens bis zu den Ist-Kosten.</p>
          <div data-allocation-rows></div>
          <div><button class="button button-light button-small" type="button" data-add-allocation data-icon="plus">Auftrag hinzufügen</button></div>`);
        const box = form.querySelector("[data-allocation-rows]");
        const draw = () => {
          box.innerHTML = rows.length ? rows.map((row, index) => `
            <div class="purchase-allocation-row" data-allocation="${index}">
              <select data-allocation-field="orderId" aria-label="Auftrag"><option value="">Auftrag wählen …</option>${reference.orders.map((job) => `<option value="${esc(job.id)}"${job.id === row.orderId ? " selected" : ""}>${esc(job.orderNumber)} · ${esc(job.title)}</option>`).join("")}</select>
              <input data-allocation-field="amount" inputmode="decimal" value="${esc(row.amount)}" placeholder="Betrag netto" aria-label="Betrag netto">
              <button class="icon-button icon-button-danger" type="button" data-remove-allocation data-icon="trash" title="Zeile entfernen" aria-label="Zeile entfernen"></button>
            </div>`).join("") : '<p class="muted">Keine Verteilung – volle Kosten an den Auftrag der Bestellung.</p>';
        };
        const update = (event) => {
          const row = event.target.closest("[data-allocation]");
          if (row && event.target.dataset.allocationField) rows[Number(row.dataset.allocation)][event.target.dataset.allocationField] = event.target.value;
        };
        form.addEventListener("input", update);
        form.addEventListener("change", update);
        form.addEventListener("click", (event) => {
          if (event.target.closest("[data-add-allocation]")) {
            rows.push({ orderId: rows.length ? "" : order.orderId || "", amount: "" });
            draw();
          } else if (event.target.closest("[data-remove-allocation]")) {
            rows.splice(Number(event.target.closest("[data-allocation]").dataset.allocation), 1);
            draw();
          }
        });
        draw();
        Calight.createDialog({
          title: "Kostenverteilung",
          form,
          submitLabel: "Speichern",
          discardMessage: "Die Änderungen an der Verteilung verwerfen?",
          submit: async () => {
            adopt(await api(url("/allocations"), { method: "PUT", body: { version: order.version, allocations: rows.map((row) => ({ orderId: row.orderId, amount: decimal(row.amount) })) } }));
            success("Kostenverteilung gespeichert.");
          },
        });
      },

      document() {
        const kinds = Object.entries(reference.documentKinds).filter(([kind]) => costs() || kind !== "invoice");
        const form = dialogForm(`${field("Dokumentart", `<select name="kind">${kinds.map(([kind, label]) => `<option value="${esc(kind)}"${kind === "other" ? " selected" : ""}>${esc(label)}</option>`).join("")}</select>`)}
          ${field('Datei (PDF oder Bild, max. 15 MB) <span aria-hidden="true">*</span>', '<input name="file" type="file" accept="application/pdf,image/*" required>')}
          <p class="muted">Belege zu Auftragsbestätigung, Lieferung oder Rechnung am besten direkt beim jeweiligen Vorgang anhängen.</p>`);
        Calight.createDialog({
          title: "Dokument hochladen",
          form,
          submitLabel: "Hochladen",
          discardMessage: "Das Hochladen abbrechen?",
          submit: async () => {
            const file = form.elements.file.files[0];
            if (!file) throw new Error("Bitte eine Datei auswählen.");
            adopt(await shared.postForm(url("/documents"), { kind: form.elements.kind.value }, file, csrf));
            success("Dokument hochgeladen.");
          },
        });
      },
    };

    // Jeder Aufruf arbeitet mit dem aktuellen Stand der Bestellung.
    return Object.fromEntries(Object.entries(dialogs).map(([name, open]) => [name, (...args) => {
      order = context.getOrder();
      reference = context.getReference();
      return open(...args);
    }]));
  }

  globalThis.CalightPurchaseDialogs = Object.freeze({ create });
})();
