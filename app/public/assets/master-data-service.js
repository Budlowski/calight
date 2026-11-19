// Stammposition bearbeiten (eigene Seite): Texte, Einheit, Schlepptext und Kalkulationsbausteine
// aus dem Artikelstamm oder manuell. Preise berechnet ausschließlich der Server.
(() => {
  "use strict";

  const root = document.querySelector("#master-service-app");
  if (!root) return;

  const { esc, api, notify } = Calight;
  const form = root.querySelector("#service-form");
  const message = root.querySelector("#master-message");
  const preview = root.querySelector("#master-price-preview");
  const typeLabels = { material: "Material", labor: "Lohn", external: "Fremdleistung", other: "Sonstige Kosten" };
  const euro = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  const money = (value) => euro.format(Number(value || 0));
  const decimalValue = (value) => String(value ?? "").trim().replace(",", ".");
  const decimalInput = (value, fallback) => String(value ?? fallback).replace(".", ",");
  let id = root.dataset.id;
  let costItems = [];
  let components = [];
  let pendingUpdates = [];
  let previewTimer = 0;
  let dirty = false;
  let saving = false;

  const setDirty = (next) => {
    dirty = next;
    root.querySelector("[data-dirty-hint]").hidden = !next;
  };
  const sourceLabel = (component) => (component.priceMode === "master"
    ? `Aus Artikelstamm ${component.sourceArticleNumber || component.name}`
    : (component.masterDataId ? `Eigener Preis · Artikelstamm ${component.sourceArticleNumber || component.name}` : "Eigener Preis"));

  function renderHeader(service) {
    const label = service?.articleNumber || (service ? "Ohne Nr." : "Neue Stammposition");
    root.querySelector("[data-number]").textContent = label;
    root.querySelector("[data-title]").textContent = service?.shortText || "Neue Stammposition";
    root.querySelector("[data-current-crumb]").textContent = service?.shortText || "Neue Stammposition";
    root.querySelector("[data-status-line]").innerHTML = service && !service.active ? '<span class="status-badge status-archived">Archiviert</span>' : "";
    document.title = `${service?.shortText || "Neue Stammposition"} · Positionsstamm`;
  }

  function fill(service, trailingTexts) {
    const f = form.elements;
    f.trailingTextId.innerHTML = '<option value="">Kein Schlepptext</option>'
      + trailingTexts.map((text) => `<option value="${esc(text.id)}">${esc(text.code)} · ${esc(text.name)}</option>`).join("");
    f.articleNumber.value = service?.articleNumber || "";
    const automatic = !service || !service.articleNumber;
    f.autoArticleNumber.checked = automatic;
    f.autoArticleNumber.closest(".check-inline").hidden = !automatic;
    f.articleNumber.disabled = automatic;
    f.shortText.value = service?.shortText || "";
    f.longText.value = service?.longText || "";
    f.unit.value = service?.unit || "";
    f.trailingTextId.value = service?.trailingTextId || "";
    components = structuredClone(service?.components || []);
    pendingUpdates = [];
    preview.hidden = true;
    renderComponents();
    renderTotals(service?.calculation || null);
    renderHeader(service);
    setDirty(false);
  }

  // ---------------------------------------------------------------------------
  // Kalkulationsbausteine
  // ---------------------------------------------------------------------------
  function renderComponents() {
    root.querySelector("#component-count").textContent = `(${components.length})`;
    root.querySelector("#components-list").innerHTML = components.length ? components.map((component, index) => `
      <details class="component-row" data-component-index="${index}" data-component-type="${esc(component.calculationType)}">
        <summary class="component-summary"><span class="component-identity" title="${esc(component.name)}"><strong data-compact="name">${esc(component.name)}</strong><small data-compact="source">${esc(sourceLabel(component))}</small></span><span class="component-type-badge" data-compact="type">${esc(typeLabels[component.calculationType])}</span><span class="component-summary-value"><small>Menge</small><strong data-compact="quantity">${esc(decimalInput(component.quantity, "1.000"))} ${esc(component.unit)}</strong></span><span class="component-summary-value"><small>Kosten-EP</small><strong data-compact="cost">${money(component.costPrice)}</strong></span><span class="component-summary-value"><small>Aufschlag</small><strong data-compact="markup">${esc(decimalInput(component.markupPercent, "0.00"))} %</strong></span><span class="component-summary-value component-summary-sales"><small>VK-Anteil</small><strong data-compact="sales">${money(component.salesPrice || 0)}</strong></span></summary>
        <div class="component-editor-body"><div class="component-primary-grid">
          <label>Bezeichnung<input data-field="name" value="${esc(component.name)}" maxlength="250" required></label>
          <label>Typ<select data-field="calculationType">${Object.entries(typeLabels).map(([value, label]) => `<option value="${value}"${component.calculationType === value ? " selected" : ""}>${label}</option>`).join("")}</select></label>
          <label>Einheit<input data-field="unit" value="${esc(component.unit)}" maxlength="40" required></label>
          <label>Preisquelle<select data-field="priceMode"${component.masterDataId ? "" : " disabled"}>${component.masterDataId ? `<option value="master"${component.priceMode === "master" ? " selected" : ""}>Aus Artikelstamm ${esc(component.sourceArticleNumber || component.name)}</option>` : ""}<option value="manual"${component.priceMode !== "master" || !component.masterDataId ? " selected" : ""}>${component.masterDataId ? `Eigener Preis · Artikelstamm ${esc(component.sourceArticleNumber || component.name)}` : "Eigener Preis"}</option></select></label>
          <div class="component-controls-actions"><button type="button" class="icon-button" data-component-up data-icon="arrow-up"${index === 0 ? " disabled" : ""} title="Nach oben" aria-label="Nach oben"></button><button type="button" class="icon-button" data-component-down data-icon="arrow-down"${index === components.length - 1 ? " disabled" : ""} title="Nach unten" aria-label="Nach unten"></button><button type="button" class="icon-button" data-component-copy data-icon="copy" title="Kopieren" aria-label="Kopieren"></button><button type="button" class="icon-button icon-button-danger" data-component-remove data-icon="trash" title="Entfernen" aria-label="Entfernen"></button></div>
        </div>
        <div class="component-price-grid">
          <label>Menge je Leistungseinheit<input data-field="quantity" value="${esc(decimalInput(component.quantity, "0.000"))}" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,3})?" required></label>
          <label>Kosten-EP<input data-field="costPrice" value="${esc(decimalInput(component.costPrice, "0.0000"))}" inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,4})?"${component.priceMode === "master" ? " readonly" : ""} required></label>
          <label>Aufschlag auf Kosten %<input data-field="markupPercent" value="${esc(decimalInput(component.markupPercent, "0.00"))}" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]{1,2})?"${component.priceMode === "master" ? " readonly" : ""} required></label>
          <div class="component-sales"><span>VK-Anteil je Leistungseinheit</span><strong data-component-sales="${index}">${money(component.salesPrice || 0)}</strong></div>
        </div></div>
      </details>`).join("") : '<p class="empty-state">Noch keine Kalkulationsbausteine. Stammartikel übernehmen oder einen Baustein manuell anlegen.</p>';
  }

  function refreshSummary(row, component) {
    row.dataset.componentType = component.calculationType;
    const set = (key, text) => { row.querySelector(`[data-compact="${key}"]`).textContent = text; };
    set("name", component.name);
    row.querySelector(".component-identity").title = component.name;
    set("source", sourceLabel(component));
    set("type", typeLabels[component.calculationType] || component.calculationType);
    set("quantity", `${decimalInput(component.quantity, "1.000")} ${component.unit}`);
    set("cost", money(component.costPrice));
    set("markup", `${decimalInput(component.markupPercent, "0.00")} %`);
    set("sales", money(component.salesPrice || 0));
  }

  const normalized = () => components.map((component) => ({
    ...component, quantity: decimalValue(component.quantity), costPrice: decimalValue(component.costPrice), markupPercent: decimalValue(component.markupPercent),
  }));
  const changed = () => {
    pendingUpdates = [];
    preview.hidden = true;
    setDirty(true);
  };

  root.querySelector("#add-master-component").addEventListener("click", () => {
    const input = root.querySelector("#component-master-select");
    const label = input.value.trim();
    const found = costItems.filter((item) => item.active && `${item.articleNumber || "Ohne Nr."} · ${item.name}` === label);
    if (found.length !== 1) {
      notify(message, found.length ? "Die Auswahl ist nicht eindeutig." : "Bitte einen vorhandenen Stammartikel auswählen.");
      return;
    }
    const item = found[0];
    // Neue IDs vergibt der Server (funktioniert auch ohne sichere Browserfunktionen im LAN).
    components.push({ id: null, masterDataId: item.id, sourceArticleNumber: item.articleNumber, name: item.name, calculationType: item.calculationType, unit: item.unit, quantity: "1.000", costPrice: item.costPrice, markupPercent: item.markupPercent, priceMode: "master", salesPrice: item.salesPrice });
    input.value = "";
    changed();
    renderComponents();
    schedulePreview();
  });
  root.querySelector("#add-manual-component").addEventListener("click", () => {
    components.push({ id: null, masterDataId: null, sourceArticleNumber: null, name: "", calculationType: "material", unit: "Stk.", quantity: "1.000", costPrice: "0.0000", markupPercent: "0.00", priceMode: "manual", salesPrice: "0.00" });
    changed();
    renderComponents();
    const last = root.querySelector("#components-list .component-row:last-child");
    if (last) {
      last.open = true;
      last.querySelector('input[data-field="name"]')?.focus();
    }
    schedulePreview();
  });

  root.querySelector("#preview-master-prices").addEventListener("click", async () => {
    try {
      const result = await api("/api/master-data/preview-master-price-updates", { method: "POST", body: { components: normalized() } });
      pendingUpdates = result.updates;
      const warnings = result.warnings.map((warning) => `<li>${esc(warning)}</li>`).join("");
      const rows = result.changes.map((change) => `<tr><td><strong>${esc(change.name)}</strong><br><span class="muted">${esc(change.articleNumber || "ohne Artikelnummer")}</span></td><td>Kosten ${money(change.oldCostPrice)}<br>Zuschlag ${esc(decimalInput(change.oldMarkupPercent, "0.00"))} %<br>VK-Anteil ${money(change.oldSalesPrice)}</td><td>Kosten ${money(change.newCostPrice)}<br>Zuschlag ${esc(decimalInput(change.newMarkupPercent, "0.00"))} %<br>VK-Anteil ${money(change.newSalesPrice)}</td></tr>`).join("");
      preview.innerHTML = `<h3>Vorschau der Preisaktualisierung</h3>${warnings ? `<ul class="notice notice-error">${warnings}</ul>` : ""}${rows
        ? `<div class="table-wrap"><table><thead><tr><th>Baustein</th><th>Bisher</th><th>Neu</th></tr></thead><tbody>${rows}</tbody></table></div><p class="muted">Eigene Preise bleiben unverändert. Übernommene Änderungen werden erst mit „Speichern“ dauerhaft gespeichert.</p><div class="form-actions"><button type="button" class="button button-light" data-cancel-master-prices>Abbrechen</button><button type="button" class="button" data-apply-master-prices>Änderungen übernehmen</button></div>`
        : '<p>Alle Preise aus dem Artikelstamm sind bereits aktuell. Eigene Preise wurden nicht berücksichtigt.</p><button type="button" class="button button-light" data-cancel-master-prices>Schließen</button>'}`;
      preview.hidden = false;
    } catch (exception) {
      notify(message, exception.message);
    }
  });
  preview.addEventListener("click", (event) => {
    if (event.target.closest("[data-cancel-master-prices]")) {
      preview.hidden = true;
      pendingUpdates = [];
      return;
    }
    if (!event.target.closest("[data-apply-master-prices]")) return;
    pendingUpdates.forEach((update) => {
      const component = components[update.index];
      if (!component) return;
      Object.assign(component, { costPrice: update.costPrice, markupPercent: update.markupPercent, salesPrice: update.salesPrice, sourceArticleNumber: update.sourceArticleNumber });
    });
    changed();
    renderComponents();
    schedulePreview();
    notify(message, "Preisänderungen wurden übernommen und werden mit „Speichern“ gespeichert.", "success");
  });

  const list = root.querySelector("#components-list");
  list.addEventListener("input", (event) => {
    const row = event.target.closest("[data-component-index]");
    const name = event.target.dataset.field;
    if (!row || !name) return;
    const component = components[Number(row.dataset.componentIndex)];
    component[name] = event.target.value;
    pendingUpdates = [];
    preview.hidden = true;
    setDirty(true);
    if (component.masterDataId && ["costPrice", "markupPercent"].includes(name)) {
      component.priceMode = "manual";
      row.querySelector('[data-field="priceMode"]').value = "manual";
    }
    if (name === "priceMode") {
      if (event.target.value === "master") {
        const master = costItems.find((item) => item.id === component.masterDataId);
        if (master) Object.assign(component, { costPrice: master.costPrice, markupPercent: master.markupPercent });
      }
      renderComponents();
      root.querySelector(`[data-component-index="${row.dataset.componentIndex}"]`).open = true;
      schedulePreview();
      return;
    }
    refreshSummary(row, component);
    schedulePreview();
  });
  list.addEventListener("click", (event) => {
    const row = event.target.closest("[data-component-index]");
    if (!row) return;
    const index = Number(row.dataset.componentIndex);
    if (event.target.closest("[data-component-remove]")) components.splice(index, 1);
    else if (event.target.closest("[data-component-copy]")) components.splice(index + 1, 0, { ...structuredClone(components[index]), id: null });
    else if (event.target.closest("[data-component-up]") && index > 0) [components[index - 1], components[index]] = [components[index], components[index - 1]];
    else if (event.target.closest("[data-component-down]") && index < components.length - 1) [components[index + 1], components[index]] = [components[index], components[index + 1]];
    else return;
    changed();
    renderComponents();
    schedulePreview();
  });

  function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(async () => {
      if (!components.every((component) => component.name && component.unit && component.quantity && component.costPrice && component.markupPercent)) return;
      try {
        const result = await api("/api/master-data/preview-price", { method: "POST", body: { components: normalized() } });
        result.componentSalesPrices.forEach((price, index) => {
          components[index].salesPrice = price;
          const output = root.querySelector(`[data-component-sales="${index}"]`);
          if (output) output.textContent = money(price);
          const row = root.querySelector(`[data-component-index="${index}"]`);
          if (row) refreshSummary(row, components[index]);
        });
        renderTotals(result);
      } catch (exception) {
        root.querySelector("#service-totals").textContent = exception.message;
      }
    }, 200);
  }

  function renderTotals(calculation) {
    const target = root.querySelector("#service-totals");
    if (!calculation) {
      target.innerHTML = '<span class="muted">Noch keine Kalkulation.</span>';
      return;
    }
    const optional = [
      Number(calculation.externalCosts) !== 0 ? `<div><span>Fremdleistung EK</span><strong>${money(calculation.externalCosts)}</strong></div>` : "",
      Number(calculation.otherCosts) !== 0 ? `<div><span>Sonstige Kosten</span><strong>${money(calculation.otherCosts)}</strong></div>` : "",
    ].join("");
    const unit = form.elements.unit.value.trim();
    target.innerHTML = `<div><span>Material EK</span><strong>${money(calculation.materialCosts)}</strong></div><div><span>Lohnkosten</span><strong>${money(calculation.laborCosts)}</strong></div>${optional}<div><span>Selbstkosten gesamt</span><strong>${money(calculation.totalCosts)}</strong></div><div><span>Zuschläge gesamt</span><strong>${money(calculation.markupTotal ?? calculation.contributionMargin)}</strong></div><div class="total"><span>Verkaufspreis Position netto${unit ? ` je ${esc(unit)}` : ""}</span><strong>${money(calculation.calculatedUnitPrice)}</strong></div>`;
  }

  // ---------------------------------------------------------------------------
  // Kopfdaten und Speichern
  // ---------------------------------------------------------------------------
  form.addEventListener("input", (event) => {
    if (!event.target.closest("#components-list")) setDirty(true);
    if (event.target.name === "unit") schedulePreview();
  });
  form.elements.autoArticleNumber.addEventListener("change", () => {
    const automatic = form.elements.autoArticleNumber.checked;
    form.elements.articleNumber.disabled = automatic;
    if (automatic) form.elements.articleNumber.value = "";
    else form.elements.articleNumber.focus();
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (saving) return;
    if (!form.reportValidity()) return;
    saving = true;
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const f = form.elements;
      const saved = await api(id ? `/api/master-data/services/${encodeURIComponent(id)}` : "/api/master-data/services", {
        method: id ? "PUT" : "POST",
        body: {
          shortText: f.shortText.value.trim(), articleNumber: f.articleNumber.value.trim() || null, autoArticleNumber: f.autoArticleNumber.checked,
          longText: f.longText.value, unit: f.unit.value.trim(), trailingTextId: f.trailingTextId.value || null, components: normalized(),
        },
      });
      const created = !id;
      id = saved.id;
      if (created) history.replaceState(null, "", `/master-data/services/${encodeURIComponent(id)}`);
      await load();
      notify(message, created ? "Stammposition angelegt." : "Stammposition gespeichert.", "success");
    } catch (exception) {
      notify(message, exception.message);
    } finally {
      saving = false;
      button.disabled = false;
    }
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  async function load() {
    const data = await api("/api/master-data");
    costItems = data.costItems;
    root.querySelector("#component-master-options").innerHTML = costItems.filter((item) => item.active)
      .map((item) => `<option value="${esc(`${item.articleNumber || "Ohne Nr."} · ${item.name}`)}"></option>`).join("");
    const service = id ? data.services.find((entry) => entry.id === id) : null;
    if (id && !service) throw new Error("Die Stammposition wurde nicht gefunden.");
    fill(service, data.trailingTexts);
  }
  load().then(() => form.elements.shortText.focus()).catch((exception) => notify(message, exception.message));
})();
