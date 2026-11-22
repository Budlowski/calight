/*
 * Mobile Übersichten für Tagesberichte, Rapporte, Aufmaße und Materialbedarf: verbindet „Neu“ und
 * den Einstieg aus einem Auftrag (?create_for=<auftrag>) mit den gemeinsamen Anlegewegen der
 * Fachmodule. Keine eigene Fachlogik; Anlegen, Bearbeiten und Status laufen über die bestehende API.
 */
(function () {
  "use strict";

  const root = document.getElementById("mobile-reports");
  if (!root || !globalThis.Calight) return;
  const message = root.querySelector("[data-message]");
  const kind = root.dataset.kind;
  const readJson = (selector) => {
    try {
      return JSON.parse(document.querySelector(selector)?.textContent || "[]");
    } catch {
      return [];
    }
  };
  const orderOptions = readJson("[data-order-options]");
  const friendly = (error) => (/Failed to fetch|NetworkError|Load failed/i.test(error.message) ? "Keine Verbindung zum Server. Bitte erneut versuchen." : error.message);

  /**
   * Auftrag wählen (Aufmaß, Materialbedarf): nur die vom Server gelieferten, für den Benutzer
   * sichtbaren Aufträge; Suche über das gemeinsame Suchfeld. Liefert die ID oder "".
   */
  async function chooseOrder(title) {
    // Aufmaß: Aufträge ohne aufmaßfähige Positionen (Kennzeichen vom Server) sind nicht wählbar und
    // werden mit Grund darunter genannt. Der Server prüft beim Anlegen zusätzlich.
    const selectable = orderOptions.filter((order) => order.measurable !== false);
    const excluded = orderOptions.filter((order) => order.measurable === false);
    if (!selectable.length) {
      Calight.notify(message, excluded.length
        ? "Keiner Ihrer aktiven Aufträge hat aufmaßfähige Leistungspositionen (z. B. Regieaufträge ohne angenommenen Nachtrag)."
        : "Für Sie ist derzeit kein aktiver Auftrag verfügbar.", "warning");
      return "";
    }
    const form = document.createElement("form");
    form.className = "admin-form";
    form.noValidate = true;
    form.innerHTML = `<label><span class="field-label">Auftrag <span aria-hidden="true">*</span></span><select name="orderId" required><option value="">Auftrag wählen …</option>${selectable
      .map((order) => `<option value="${Calight.esc(order.id)}">${Calight.esc(order.label)}</option>`).join("")}</select></label>
      ${excluded.length ? `<details class="field-hint mobile-excluded-orders"><summary>${excluded.length} ${excluded.length === 1 ? "Auftrag" : "Aufträge"} ohne aufmaßfähige Positionen</summary>
        <p>Nur Aufträge mit verbindlichen Leistungspositionen (Auftragsgrundlage oder angenommener Nachtrag) können aufgemessen werden.</p>
        <ul>${excluded.map((order) => `<li>${Calight.esc(order.label)}</li>`).join("")}</ul></details>` : ""}`;
    Calight.searchableSelect(form.elements.orderId, { placeholder: "Nummer, Bezeichnung oder Kunde …" });
    let chosen = "";
    const confirmed = await CalightModal.open({
      title,
      content: form,
      initialFocus: ".suggest-select input",
      actions: [
        { label: "Abbrechen", value: false },
        { label: "Weiter", value: true, primary: true, validate: true, onClick: () => {
          chosen = form.elements.orderId.value;
          return chosen ? undefined : false;
        } },
      ],
    });
    return confirmed ? chosen : "";
  }

  const labelOf = (orderId) => orderOptions.find((order) => order.id === orderId)?.label || "";

  async function create(orderId = "") {
    if (kind === "daily") return globalThis.CalightDailyReports.create({ orderId });
    if (kind === "work") return globalThis.CalightWorkReports.openCreateDialog({ orderId });
    if (kind === "measurement") {
      const target = orderId || (await chooseOrder("Aufmaß anlegen"));
      if (target) await globalThis.CalightMeasurements.createForOrder(target);
      return undefined;
    }
    if (kind === "material") {
      const target = orderId || (await chooseOrder("Material anfordern"));
      if (target) globalThis.CalightMaterialRequests.openDialog({ orderId: target, orderLabel: labelOf(target), onSaved: () => location.reload() });
    }
    return undefined;
  }

  let busy = false;
  async function open(orderId = "") {
    // Schutz vor Mehrfachausführung bei wiederholtem Antippen.
    if (busy || Calight.moduleReadOnly) return;
    busy = true;
    try {
      await create(orderId);
    } catch (error) {
      Calight.notify(message, friendly(error));
    } finally {
      busy = false;
    }
  }

  root.querySelector("[data-mobile-create]")?.addEventListener("click", () => open());

  // Materialbedarf: Einträge mit den gemeinsamen Bausteinen darstellen und bedienen.
  if (kind === "material" && globalThis.CalightMaterialRequests) {
    const items = readJson("[data-material-json]");
    root.querySelectorAll("[data-material-item]").forEach((element) => {
      const item = items.find((request) => request.id === element.dataset.materialItem);
      if (item) element.innerHTML = globalThis.CalightMaterialRequests.renderCompact([item]);
    });
    const list = root.querySelector("[data-material-list]");
    if (list) {
      globalThis.CalightMaterialRequests.bindActions(list, {
        find: (id) => items.find((request) => request.id === id),
        reload: () => location.reload(),
        message,
      });
    }
  }

  // Einstieg aus der Auftragsdetailseite: Anlegen mit vorbelegtem Auftrag einmal anbieten.
  const orderId = root.dataset.createFor;
  if (orderId) {
    const url = new URL(location.href);
    url.searchParams.delete("create_for");
    history.replaceState(null, "", url.pathname + url.search);
    const preset = orderOptions.find((order) => order.id === orderId);
    if (kind === "measurement" && preset?.measurable === false) {
      Calight.notify(message, "Dieser Auftrag hat keine aufmaßfähigen Leistungspositionen (z. B. Regieauftrag ohne angenommenen Nachtrag). Ein Aufmaß ist nicht möglich.", "warning");
    } else if (kind === "measurement") {
      // Aufmaß: Anlage erst nach Bestätigung (kein Anlegen allein durch Seitenaufruf).
      CalightModal.confirm({ title: "Aufmaß anlegen", content: `Neues Aufmaß für ${labelOf(orderId) || "diesen Auftrag"} anlegen?`, confirmLabel: "Anlegen" })
        .then((ok) => { if (ok) open(orderId); });
    } else {
      open(orderId);
    }
  }
})();
