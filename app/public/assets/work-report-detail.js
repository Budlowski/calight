// Rapport: Leistungsnachweis (Monteur) und davon getrennte kaufmännische Bearbeitung (Büro).
(() => {
  "use strict";

  const root = document.querySelector("#work-report-detail");
  if (!root) return;

  const { esc, api, format, notify, exact } = Calight;
  const message = root.querySelector("[data-message]");
  const proofForm = root.querySelector("[data-proof]");
  const laborBox = root.querySelector("[data-labor]");
  const materialBox = root.querySelector("[data-materials]");
  const billingSection = root.querySelector("[data-billing-section]");
  const billingBox = root.querySelector("[data-billing]");
  // Meldungen zur kaufmännischen Bearbeitung erscheinen im Abschnitt selbst,
  // weil die Kopfmeldung beim Arbeiten an den Positionen außer Sicht ist.
  const billingMessage = root.querySelector("[data-billing-message]");
  const notifyBilling = (text, type) => {
    message.hidden = true;
    notify(billingMessage, text, type);
    billingMessage.scrollIntoView({ block: "nearest", behavior: "smooth" });
  };
  const catalogSearch = root.querySelector("[data-catalog-search]");
  const catalogResults = root.querySelector("[data-catalog-results]");
  const STATUS_LABELS = { draft: "Entwurf", submitted: "Eingereicht", processed: "Bearbeitet" };
  const METHOD_LABELS = { on_site: "vor Ort", later: "nachträglich" };
  // Nachweisarten der Kundenbestätigung; „manuell“ hat ausdrücklich keinen Unterschriftsnachweis.
  const KIND_LABELS = { signature: "Digital unterschrieben", returned_document: "Unterschriebenes Dokument zurückerhalten", manual: "Manuell dokumentiert (ohne Nachweis)" };
  const OTHER = "__other";

  let report = null;
  let reference = { employees: [], laborGroups: [], orders: [] };
  let labor = [];
  let materials = [];
  let billing = [];
  let proofDirty = false;
  let billingDirty = false;
  let busy = false;

  const editableProof = () => report?.status === "draft" && report.canEdit;
  const editableBilling = () => report?.canProcess && report.status === "submitted";
  const decimal = (value) => String(value ?? "").trim().replace(",", ".");
  const display = (value, digits) => format.number(Number(value || 0), digits);
  const lineTotal = (item) => (exact.DECIMAL_PATTERN.test(decimal(item.quantity)) && exact.DECIMAL_PATTERN.test(decimal(item.unitPrice))
    ? exact.lineCents(exact.toUnits(decimal(item.quantity), 3), decimal(item.unitPrice))
    : 0n);
  const money = (cents) => format.money(exact.fromUnits(cents, 2));
  // Eingabewerte ohne überflüssige Nachkommanullen anzeigen: "3.500" -> "3,5", "10.000" -> "10".
  const inputValue = (value) => String(value ?? "").replace(/(\.\d*?)0+$/, "$1").replace(/\.$/, "").replace(".", ",");

  function setDirty(kind, value) {
    if (kind === "proof") proofDirty = value;
    else billingDirty = value;
    root.querySelector("[data-dirty-hint]").hidden = !proofDirty;
    root.querySelector("[data-billing-dirty-hint]").hidden = !billingDirty;
  }

  // ---------------------------------------------------------------------------
  // Leistungsnachweis
  // ---------------------------------------------------------------------------
  function employeeSelect(row) {
    const known = reference.employees.some((employee) => employee.id === row.employeeId);
    const options = reference.employees.map((employee) => `<option value="${esc(employee.id)}"${employee.id === row.employeeId ? " selected" : ""}>${esc(employee.name)}</option>`);
    // Bereits erfasste, inzwischen inaktive Mitarbeiter bleiben lesbar.
    if (row.employeeId && !known) options.push(`<option value="${esc(row.employeeId)}" selected>${esc(row.workerName)}</option>`);
    const other = !row.employeeId && row.workerName !== undefined && row.workerName !== "";
    return `<select data-field="employeeId"><option value="">Mitarbeiter wählen …</option>${options.join("")}<option value="${OTHER}"${other ? " selected" : ""}>Andere Person …</option></select>`;
  }

  function renderLabor() {
    if (!editableProof()) {
      laborBox.innerHTML = labor.length
        ? `<ul class="work-report-readonly">${labor.map((row) => `<li><strong>${display(row.hours, 2)} h</strong> ${esc(row.workerName)}${row.laborItemName ? ` · ${esc(row.laborItemName)}` : ""}${row.activity ? `<br><span class="muted">${esc(row.activity)}</span>` : ""}</li>`).join("")}</ul>`
        : '<p class="empty-state">Keine Arbeitsstunden erfasst.</p>';
    } else {
      laborBox.innerHTML = labor.map((row, index) => `
        <div class="work-report-row work-report-labor-row" data-index="${index}">
          <label><span class="field-label">Mitarbeiter</span>${employeeSelect(row)}</label>
          <label${row.employeeId || !row.custom ? " hidden" : ""} data-custom-name><span class="field-label">Name</span><input data-field="workerName" maxlength="150" value="${esc(row.employeeId ? "" : row.workerName)}"></label>
          <label><span class="field-label">Stunden</span><input data-field="hours" inputmode="decimal" autocomplete="off" value="${esc(inputValue(row.hours))}" placeholder="z. B. 3,5"></label>
          <label><span class="field-label">Tätigkeitsart (optional)</span><select data-field="laborItemId"><option value="">–</option>${reference.laborGroups
            .map((group) => `<option value="${esc(group.id)}"${group.id === row.laborItemId ? " selected" : ""}>${esc(group.name)}</option>`).join("")}</select></label>
          <label class="work-report-wide"><span class="field-label">Tätigkeit (optional)</span><input data-field="activity" maxlength="500" value="${esc(row.activity || "")}"></label>
          <button class="icon-button icon-button-danger" type="button" data-remove-row="labor" data-icon="trash" title="Zeile entfernen" aria-label="Stundenzeile entfernen"></button>
        </div>`).join("") || '<p class="empty-state">Noch keine Stunden erfasst.</p>';
    }
    const total = labor.reduce((sum, row) => sum + (exact.DECIMAL_PATTERN.test(decimal(row.hours)) ? exact.toUnits(decimal(row.hours), 3) : 0n), 0n);
    root.querySelector("[data-hours-summary]").textContent = `${display(exact.fromUnits(total, 3), 2)} Stunden`;
  }

  const quantityText = (value) => display(value, 3).replace(/,?0+$/, "");

  function renderMaterials() {
    if (!editableProof()) {
      materialBox.innerHTML = materials.length
        ? `<ul class="work-report-readonly">${materials.map((row) => `<li><strong>${quantityText(row.quantity)} ${esc(row.unit)}</strong> ${esc(row.description)}</li>`).join("")}</ul>`
        : '<p class="empty-state">Kein Material erfasst.</p>';
      return;
    }
    if (!materials.length) {
      materialBox.innerHTML = '<p class="empty-state">Noch kein Material erfasst.</p>';
      return;
    }
    materialBox.innerHTML = materials.map((row, index) => `
      <div class="work-report-row work-report-material-row" data-index="${index}">
        <label><span class="field-label">Menge</span><input data-field="quantity" inputmode="decimal" autocomplete="off" value="${esc(inputValue(row.quantity))}"></label>
        <label><span class="field-label">Einheit</span><input data-field="unit" maxlength="20" list="work-report-units" value="${esc(row.unit || "")}"></label>
        <label class="work-report-wide"><span class="field-label">Bezeichnung</span><input data-field="description" maxlength="500" value="${esc(row.description || "")}"></label>
        <button class="icon-button icon-button-danger" type="button" data-remove-row="material" data-icon="trash" title="Zeile entfernen" aria-label="Materialzeile entfernen"></button>
      </div>`).join("")
      + '<datalist id="work-report-units"><option value="Stk."><option value="m"><option value="psch"><option value="h"><option value="km"><option value="kg"></datalist>';
  }

  function readProofRow(kind, element) {
    const index = Number(element.closest("[data-index]").dataset.index);
    return { rows: kind === "labor" ? labor : materials, index };
  }

  proofForm.addEventListener("input", (event) => {
    setDirty("proof", true);
    const field = event.target.dataset.field;
    const rowElement = event.target.closest("[data-index]");
    if (!field || !rowElement) return;
    const kind = rowElement.classList.contains("work-report-labor-row") ? "labor" : "material";
    const { rows, index } = readProofRow(kind, event.target);
    rows[index][field] = event.target.value;
    if (field === "hours") renderHoursOnly();
  });
  proofForm.addEventListener("change", (event) => {
    if (event.target.dataset.field !== "employeeId") return;
    const { index } = readProofRow("labor", event.target);
    const value = event.target.value;
    labor[index].custom = value === OTHER;
    labor[index].employeeId = value === OTHER ? "" : value;
    if (value !== OTHER) labor[index].workerName = "";
    const nameField = event.target.closest("[data-index]").querySelector("[data-custom-name]");
    nameField.hidden = value !== OTHER;
    if (value === OTHER) nameField.querySelector("input").focus();
    setDirty("proof", true);
  });
  proofForm.addEventListener("click", (event) => {
    const button = event.target.closest("[data-remove-row]");
    if (!button) return;
    const kind = button.dataset.removeRow;
    const { rows, index } = readProofRow(kind, button);
    rows.splice(index, 1);
    setDirty("proof", true);
    kind === "labor" ? renderLabor() : renderMaterials();
  });
  function renderHoursOnly() {
    const total = labor.reduce((sum, row) => sum + (exact.DECIMAL_PATTERN.test(decimal(row.hours)) ? exact.toUnits(decimal(row.hours), 3) : 0n), 0n);
    root.querySelector("[data-hours-summary]").textContent = `${display(exact.fromUnits(total, 3), 2)} Stunden`;
  }
  root.querySelector("[data-add-labor]").addEventListener("click", () => {
    // Ist nur ein Mitarbeiter verfügbar oder der Benutzer selbst als Mitarbeiter erkennbar, vorbelegen.
    labor.push({ employeeId: reference.employees.length === 1 ? reference.employees[0].id : "", workerName: "", hours: "", laborItemId: "", activity: "" });
    setDirty("proof", true);
    renderLabor();
    laborBox.querySelector(".work-report-labor-row:last-child select")?.focus();
  });
  root.querySelector("[data-add-material]").addEventListener("click", () => {
    materials.push({ quantity: "1", unit: "Stk.", description: "" });
    setDirty("proof", true);
    renderMaterials();
    materialBox.querySelector(".work-report-material-row:last-child [data-field='description']")?.focus();
  });

  function proofPayload() {
    return {
      version: report.version,
      workDate: proofForm.elements.workDate.value,
      workDateTo: proofForm.elements.workDateTo.value,
      contactPerson: proofForm.elements.contactPerson.value.trim(),
      workLocation: proofForm.elements.workLocation.value.trim(),
      description: proofForm.elements.description.value.trim(),
      notes: proofForm.elements.notes.value.trim(),
      labor: labor.map((row) => ({ employeeId: row.employeeId || "", workerName: row.employeeId ? "" : (row.workerName || "").trim(), hours: decimal(row.hours), laborItemId: row.laborItemId || "", activity: (row.activity || "").trim() })),
      materials: materials.map((row) => ({ quantity: decimal(row.quantity), unit: (row.unit || "").trim(), description: (row.description || "").trim() })),
    };
  }

  // ---------------------------------------------------------------------------
  // Kaufmännische Bearbeitung
  // ---------------------------------------------------------------------------
  function renderBilling() {
    billingSection.hidden = !report.canProcess || report.status === "draft";
    if (billingSection.hidden) return;
    const editable = editableBilling();
    root.querySelector("[data-billing-tools]").hidden = !editable;
    root.querySelector("[data-catalog-box]").hidden = !editable;
    const types = report.billingTypes || {};
    if (!billing.length) {
      billingBox.innerHTML = '<p class="empty-state">Noch keine Abrechnungspositionen. Übernehmen Sie den Leistungsnachweis oder fügen Sie Positionen hinzu.</p>';
    } else if (!editable) {
      billingBox.innerHTML = `<div class="measurement-table-wrap"><table class="measurement-totals work-report-billing-table"><thead><tr><th>Art</th><th>Bezeichnung</th><th class="m-num">Menge</th><th>Einheit</th><th class="m-num">Einzelpreis</th><th class="m-num">Gesamt</th></tr></thead><tbody>${billing
        .map((item) => `<tr><td>${esc(types[item.type] || item.type)}</td><td>${esc(item.description)}</td><td class="m-num">${display(item.quantity, 3)}</td><td>${esc(item.unit)}</td><td class="m-num">${format.money(item.unitPrice)}</td><td class="m-num">${money(lineTotal(item))}</td></tr>`).join("")}</tbody></table></div>`;
    } else {
      billingBox.innerHTML = billing.map((item, index) => `
        <div class="work-report-row work-report-billing-row" data-billing-index="${index}">
          <label><span class="field-label">Art</span><select data-billing-field="type">${Object.entries(types).map(([value, label]) => `<option value="${value}"${value === item.type ? " selected" : ""}>${esc(label)}</option>`).join("")}</select></label>
          <label class="work-report-wide"><span class="field-label">Bezeichnung</span><input data-billing-field="description" maxlength="500" value="${esc(item.description)}"></label>
          <label><span class="field-label">Menge</span><input data-billing-field="quantity" inputmode="decimal" value="${esc(inputValue(item.quantity))}"></label>
          <label><span class="field-label">Einheit</span><input data-billing-field="unit" maxlength="20" value="${esc(item.unit)}"></label>
          <label><span class="field-label">Einzelpreis €</span><input data-billing-field="unitPrice" inputmode="decimal" value="${esc(String(item.unitPrice).replace(".", ","))}" placeholder="0,00"></label>
          <div class="work-report-line-total"><span class="field-label">Gesamt</span><strong data-line-total>${money(lineTotal(item))}</strong></div>
          <div class="work-report-row-actions">
            <button class="icon-button" type="button" data-billing-move="-1" data-icon="arrow-up" title="Nach oben" aria-label="Position nach oben"${index === 0 ? " disabled" : ""}></button>
            <button class="icon-button" type="button" data-billing-move="1" data-icon="arrow-down" title="Nach unten" aria-label="Position nach unten"${index === billing.length - 1 ? " disabled" : ""}></button>
            <button class="icon-button icon-button-danger" type="button" data-billing-remove data-icon="trash" title="Position entfernen" aria-label="Position entfernen"></button>
          </div>
        </div>`).join("");
    }
    renderBillingTotal();
  }

  function renderBillingTotal() {
    root.querySelector("[data-billing-total]").textContent = money(billing.reduce((sum, item) => sum + lineTotal(item), 0n));
  }

  billingBox.addEventListener("input", (event) => {
    const field = event.target.dataset.billingField;
    if (!field) return;
    const rowElement = event.target.closest("[data-billing-index]");
    const item = billing[Number(rowElement.dataset.billingIndex)];
    item[field] = event.target.value;
    rowElement.querySelector("[data-line-total]").textContent = money(lineTotal(item));
    renderBillingTotal();
    setDirty("billing", true);
  });
  billingBox.addEventListener("change", (event) => {
    if (event.target.dataset.billingField !== "type") return;
    billing[Number(event.target.closest("[data-billing-index]").dataset.billingIndex)].type = event.target.value;
    setDirty("billing", true);
  });
  billingBox.addEventListener("click", (event) => {
    const rowElement = event.target.closest("[data-billing-index]");
    if (!rowElement) return;
    const index = Number(rowElement.dataset.billingIndex);
    const move = event.target.closest("[data-billing-move]");
    if (move) {
      const target = index + Number(move.dataset.billingMove);
      [billing[index], billing[target]] = [billing[target], billing[index]];
    } else if (event.target.closest("[data-billing-remove]")) {
      billing.splice(index, 1);
    } else {
      return;
    }
    setDirty("billing", true);
    renderBilling();
  });
  root.querySelector("[data-billing-add]").addEventListener("click", () => {
    billing.push({ type: "other", description: "", quantity: "1", unit: "psch", unitPrice: "", costItemId: null });
    setDirty("billing", true);
    renderBilling();
    billingBox.querySelector(".work-report-billing-row:last-child [data-billing-field='description']")?.focus();
  });

  // Vorschlag aus dem Leistungsnachweis: Stunden je Tätigkeitsart zusammengefasst, Material je Zeile.
  root.querySelector("[data-billing-prefill]").addEventListener("click", async () => {
    const groups = new Map();
    for (const row of report.labor) {
      const key = row.laborItemId || "";
      const group = groups.get(key) || { hours: 0n, name: row.laborItemName || "Arbeitsstunden" };
      group.hours += exact.toUnits(row.hours, 3);
      groups.set(key, group);
    }
    const prices = new Map();
    for (const [id, group] of groups) {
      if (!id) continue;
      const matches = await api(`/api/work-reports/catalog?q=${encodeURIComponent(group.name)}`).catch(() => []);
      const match = matches.find((item) => item.id === id);
      if (match) prices.set(id, match.salesPrice);
    }
    for (const [id, group] of groups) {
      billing.push({ type: "labor", description: group.name, quantity: exact.fromUnits(group.hours, 3), unit: "h", unitPrice: prices.get(id) || "", costItemId: id || null });
    }
    for (const row of report.materials) {
      billing.push({ type: "material", description: row.description, quantity: row.quantity, unit: row.unit, unitPrice: "", costItemId: null });
    }
    setDirty("billing", true);
    renderBilling();
    notify(message, "Leistungsnachweis übernommen. Bitte Verkaufspreise ergänzen oder Positionen aus dem Stamm verwenden.", "success");
  });

  // Stammartikel suchen und als Position übernehmen (nur Verkaufspreise).
  // Gemeinsame Stammdatensuche (Calight.suggest): Pfeiltasten, Enter, Escape, Leeren.
  Calight.suggest({
    input: catalogSearch,
    list: catalogResults,
    clear: root.querySelector("[data-catalog-clear]"),
    load: (query) => api(`/api/work-reports/catalog?q=${encodeURIComponent(query)}`),
    render: (item) => `<span>${esc(item.name)}${item.articleNumber ? ` <small class="muted">${esc(item.articleNumber)}</small>` : ""}</span><span class="work-report-catalog-price">${format.money(item.salesPrice)} / ${esc(item.unit)}</span>`,
    onError: (exception) => notifyBilling(exception.message),
    choose: (item) => {
      billing.push({ type: item.type, description: item.name, quantity: "1", unit: item.unit, unitPrice: item.salesPrice, costItemId: item.id });
      setDirty("billing", true);
      renderBilling();
      billingBox.querySelector(".work-report-billing-row:last-child [data-billing-field='quantity']")?.focus();
    },
  });

  const billingPayload = () => ({
    version: report.version,
    items: billing.map((item) => ({ type: item.type, description: (item.description || "").trim(), quantity: decimal(item.quantity), unit: (item.unit || "").trim(), unitPrice: decimal(item.unitPrice), costItemId: item.costItemId || "" })),
  });

  // ---------------------------------------------------------------------------
  // Kopf, Aktionen und Laden
  // ---------------------------------------------------------------------------
  function renderHeader() {
    const number = report.reportNumber || "Entwurf";
    root.querySelector("[data-number]").textContent = number;
    root.querySelector("[data-title]").textContent = `Rapport ${report.reportNumber || ""} · ${format.date(report.workDate)}`.replace("  ", " ");
    root.querySelector("[data-current-crumb]").textContent = number;
    // Eigenständige Rapporte haben keinen Auftrag; die Brotkrume führt dann über die Rapportübersicht.
    const orderCrumb = root.querySelector("[data-order-crumb]");
    orderCrumb.hidden = !report.orderId;
    root.querySelector("[data-orders-crumb]").hidden = !report.orderId;
    orderCrumb.innerHTML = report.orderId ? `<a href="/orders/${encodeURIComponent(report.orderId)}">${esc(report.orderNumber)}</a>` : "";
    root.querySelector("[data-order]").innerHTML = report.orderId
      ? `<a href="/orders/${encodeURIComponent(report.orderId)}">${esc(report.orderNumber)} · ${esc(report.orderTitle)}</a>${report.orderAssignedAt
        ? `<small class="muted">nachträglich zugeordnet von ${esc(report.orderAssignedByName)} am ${format.dateTime(report.orderAssignedAt)}</small>` : ""}`
      : '<span class="muted">Ohne Auftrag (eigenständiger Rapport)</span>';
    root.querySelector("[data-customer]").textContent = report.customerName;
    root.querySelector("[data-confirmation]").innerHTML = confirmationText();
    document.title = `Rapport ${number} · ${report.customerName}`;
    const details = [
      report.correctedNumber ? `Korrektur zu ${esc(report.correctedNumber)}` : "",
      report.correctionCount ? `${report.correctionCount} Korrektur${report.correctionCount === 1 ? "" : "en"} vorhanden` : "",
      report.submittedAt ? `eingereicht von ${esc(report.submittedByName)} am ${format.dateTime(report.submittedAt)}` : `erstellt von ${esc(report.creatorName)}`,
      report.processedAt ? `bearbeitet von ${esc(report.processedByName)}` : "",
    ].filter(Boolean).join(" · ");
    root.querySelector("[data-status-line]").innerHTML = `<span class="status-badge status-work-${esc(report.status)}">${STATUS_LABELS[report.status]}</span>${report.confirmationStatus === "confirmed" ? '<span class="status-badge status-work-confirmed">Vom Kunden bestätigt</span>' : ""}<span>${details}</span>`;

    // Kopf: nur Dokument-, Bestätigungs- und seltene Aktionen. Die kaufmännischen
    // Aktionen sitzen direkt am Abschnitt „Kaufmännische Bearbeitung“.
    const menu = (label, icon, items) => items.length
      ? `<details class="context-menu"><summary class="button button-light" data-icon="${icon}">${label}</summary><div class="context-menu-panel">${items.join("")}</div></details>` : "";
    const actions = [];
    const more = [];
    if (editableProof()) {
      actions.push('<button class="button button-light" type="button" data-action="save-proof" data-shortcut-save data-icon="save" title="Entwurf speichern (Strg+S)">Entwurf speichern</button>');
      actions.push('<button class="button" type="button" data-action="submit" data-icon="clipboard-check">Einreichen …</button>');
      more.push('<button type="button" data-action="delete" data-icon="trash">Entwurf löschen</button>');
    }
    actions.push(`<a class="button button-light" href="/api/work-reports/${encodeURIComponent(report.id)}/pdf" data-icon="file-down">${report.status === "draft" ? "PDF-Vorschau" : "PDF"}</a>`);
    if (report.canSign) actions.push(`<a class="button" href="/work-reports/${encodeURIComponent(report.id)}/sign" data-icon="square-pen">Unterschrift</a>`);
    const confirmation = [];
    if (report.canUploadConfirmation) confirmation.push('<button type="button" data-action="upload-confirmation" data-icon="file-up">Rücklauf hochladen …</button>');
    if (report.canUploadConfirmation && !report.confirmationRequestedAt) confirmation.push('<button type="button" data-action="request-confirmation" data-icon="clipboard-list">Als versendet markieren</button>');
    if (report.canConfirm) confirmation.push('<button type="button" data-action="confirm" data-icon="square-pen">Manuell dokumentieren …</button>');
    actions.push(menu("Bestätigung", "clipboard-check", confirmation));
    if (report.canCorrect) more.push('<button type="button" data-action="correct" data-icon="copy">Korrekturrapport anlegen</button>');
    if (report.canAssignOrder) more.push('<button type="button" data-action="assign-order" data-icon="clipboard-list">Auftrag zuordnen …</button>');
    actions.push(menu("Weitere Aktionen", "menu", more));
    root.querySelector("[data-actions]").innerHTML = actions.join("");

    const billingActions = [];
    if (editableBilling()) {
      billingActions.push('<button class="button button-light" type="button" data-action="save-billing" data-shortcut-save data-icon="save" title="Positionen speichern (Strg+S)">Positionen speichern</button>');
      billingActions.push('<button class="button" type="button" data-action="process" data-icon="clipboard-check">Bearbeitung abschließen …</button>');
    }
    if (report.canProcess && report.status === "processed") billingActions.push('<button class="button button-light" type="button" data-action="reopen" data-icon="square-pen">Bearbeiten</button>');
    root.querySelector("[data-billing-actions]").innerHTML = billingActions.join("");
  }

  function render() {
    renderHeader();
    for (const name of ["workDate", "workDateTo", "contactPerson", "workLocation", "description", "notes"]) {
      proofForm.elements[name].value = report[name] ?? "";
      proofForm.elements[name].disabled = !editableProof();
    }
    root.querySelectorAll("[data-add-labor], [data-add-material]").forEach((button) => { button.hidden = !editableProof(); });
    root.querySelector("[data-proof-hint]").textContent = editableProof()
      ? "Ausgeführte Arbeiten, Stunden und Material – ohne Preise. Nach dem Einreichen unveränderlich."
      : (report.status === "draft" ? "Entwurf eines anderen Benutzers (nur lesend)." : "Verbindlich eingereichter Leistungsnachweis (unveränderlich).");
    renderLabor();
    renderMaterials();
    renderBilling();
  }

  function adopt(current) {
    report = current;
    labor = current.labor.map((row) => ({ ...row, custom: !row.employeeId }));
    materials = current.materials.map((row) => ({ ...row }));
    billing = (current.billing || []).map((item) => ({ ...item }));
    setDirty("proof", false);
    setDirty("billing", false);
    render();
  }

  // Bestätigungsstand mit Nachweisart; „versendet“ ist nur ein Hinweis, keine Bestätigung.
  function confirmationText() {
    if (report.confirmationStatus === "confirmed") {
      const evidence = report.confirmationEvidence
        ? ` · <a href="/api/work-reports/${encodeURIComponent(report.id)}/confirmation-evidence">Nachweis öffnen</a>` : "";
      return `${esc(KIND_LABELS[report.confirmationKind] || "Bestätigt")}: ${esc(report.confirmedByName)} (${METHOD_LABELS[report.confirmationMethod] || ""}), ${format.dateTime(report.confirmedAt)}${evidence}`;
    }
    if (report.status === "draft") return "–";
    return report.confirmationRequestedAt
      ? `Zur Bestätigung versendet am ${format.dateTime(report.confirmationRequestedAt)}${report.confirmationRequestedByName ? ` von ${esc(report.confirmationRequestedByName)}` : ""}`
      : "Noch nicht bestätigt";
  }

  async function load() {
    const [current, ref] = await Promise.all([
      api(`/api/work-reports/${encodeURIComponent(root.dataset.id)}`),
      api("/api/work-reports/reference-data"),
    ]);
    reference = ref;
    adopt(current);
    if (new URLSearchParams(location.search).get("signed") === "1") {
      history.replaceState(null, "", location.pathname);
      notify(message, "Die Kundenunterschrift wurde gespeichert und dem eingereichten Leistungsnachweis zugeordnet.", "success");
    }
  }

  async function saveProof({ silent = false } = {}) {
    if (!proofForm.elements.workDate.reportValidity()) return false;
    adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}`, { method: "PUT", body: proofPayload() }));
    if (!silent) notify(message, "Entwurf wurde gespeichert.", "success");
    return true;
  }

  // Verständliche Prüfung vor dem Speichern; der Server prüft unverändert erneut.
  function checkBilling() {
    const fields = [["quantity", "eine Menge"], ["unitPrice", "einen Einzelpreis (0 für ohne Berechnung)"]];
    billing.forEach((item, index) => {
      for (const [field, label] of fields) {
        if (exact.DECIMAL_PATTERN.test(decimal(item[field]))) continue;
        billingBox.querySelector(`[data-billing-index="${index}"] [data-billing-field="${field}"]`)?.focus();
        const empty = decimal(item[field]) === "";
        throw new Error(`Position ${index + 1}: Bitte ${empty ? label + " eintragen" : "eine gültige Zahl eintragen, z. B. 12,50"}.`);
      }
    });
  }

  async function saveBilling({ silent = false } = {}) {
    checkBilling();
    adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/billing`, { method: "PUT", body: billingPayload() }));
    if (!silent) notifyBilling("Abrechnungspositionen wurden gespeichert.", "success");
    return true;
  }

  async function run(button, operation) {
    if (busy) return;
    busy = true;
    if (button) button.disabled = true;
    try {
      await operation();
    } catch (exception) {
      if (button?.closest("[data-billing-section]")) notifyBilling(exception.message);
      else notify(message, exception.message);
    } finally {
      busy = false;
      if (button?.isConnected) button.disabled = false;
    }
  }

  function summaryList(entries) {
    return `<dl class="measurement-complete-summary">${entries.map(([label, value]) => `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join("")}</dl>`;
  }

  async function confirmDialog(title, html, confirmLabel) {
    const box = document.createElement("div");
    box.className = "form-stack";
    box.innerHTML = html;
    return CalightModal.open({ title, content: box, actions: [{ label: "Abbrechen", value: false }, { label: confirmLabel, value: true, primary: true }], initialFocus: "[data-modal-primary]" });
  }

  const handlers = {
    "save-proof": () => saveProof(),
    "save-billing": () => saveBilling(),
    async submit() {
      if (proofDirty && !(await saveProof({ silent: true }))) return;
      const hours = report.labor.reduce((sum, row) => sum + exact.toUnits(row.hours, 3), 0n);
      const ok = await confirmDialog("Rapport einreichen", `
        <p>Mit dem Einreichen wird der Leistungsnachweis verbindlich gespeichert und erhält eine Rapportnummer. Danach sind keine Änderungen mehr möglich; Korrekturen erfolgen über einen neuen Korrekturrapport.</p>
        ${summaryList([["Leistungsdatum", format.date(report.workDate)], ["Arbeitsstunden", `${display(exact.fromUnits(hours, 3), 2)} h`], ["Materialzeilen", String(report.materials.length)]])}`, "Verbindlich einreichen");
      if (!ok) return;
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/submit`, { method: "POST", body: { version: report.version } }));
      notify(message, `Rapport ${report.reportNumber} wurde eingereicht.`, "success");
    },
    async delete() {
      const ok = await CalightModal.confirm({ title: "Entwurf löschen", content: "Der Rapportentwurf wird endgültig gelöscht.", confirmLabel: "Löschen", danger: true });
      if (!ok) return;
      await api(`/api/work-reports/${encodeURIComponent(report.id)}`, { method: "DELETE", body: { version: report.version } });
      setDirty("proof", false);
      location.assign(report.orderId ? `/orders/${encodeURIComponent(report.orderId)}` : "/work-reports");
    },
    async process() {
      if (billingDirty && !(await saveBilling({ silent: true }))) return;
      const ok = await confirmDialog("Kaufmännische Bearbeitung abschließen", `
        <p>Die Abrechnungspositionen werden festgeschrieben. Bei Bedarf kann die Bearbeitung wieder geöffnet werden; der Leistungsnachweis bleibt davon unberührt.</p>
        ${summaryList([["Positionen", String(report.billing.length)], ["Nettobetrag", format.money(report.billingTotal)]])}`, "Abschließen");
      if (!ok) return;
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/process`, { method: "POST", body: { version: report.version } }));
      notifyBilling("Kaufmännische Bearbeitung abgeschlossen.", "success");
    },
    async reopen() {
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/reopen`, { method: "POST", body: { version: report.version } }));
      notifyBilling("Die kaufmännische Bearbeitung ist wieder geöffnet.", "success");
    },
    async confirm() {
      const form = document.createElement("form");
      form.className = "form-stack";
      form.innerHTML = `
        <p>Nur verwenden, wenn keine Unterschrift und kein unterschriebenes Dokument vorliegt (z. B. mündliche Bestätigung). Die Bestätigung wird als <strong>manuell dokumentiert</strong> gekennzeichnet und bezieht sich ausschließlich auf den eingereichten Leistungsnachweis ${esc(report.reportNumber)}.</p>
        <label><span class="field-label">Bestätigt von <span aria-hidden="true">*</span></span><input name="confirmedByName" maxlength="200" required autocomplete="off" value="${esc(report.contactPerson || "")}"></label>
        <label><span class="field-label">Art der Bestätigung</span><select name="method"><option value="on_site">Vor Ort</option><option value="later">Nachträglich</option></select></label>`;
      const ok = await CalightModal.open({ title: "Bestätigung manuell dokumentieren", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Bestätigung speichern", value: true, primary: true, validate: true }], initialFocus: '[name="confirmedByName"]' });
      if (!ok) return;
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/confirm`, { method: "POST", body: { version: report.version, confirmedByName: form.elements.confirmedByName.value.trim(), method: form.elements.method.value } }));
      notify(message, "Kundenbestätigung wurde als manuell dokumentiert erfasst.", "success");
    },
    // Rücklauf: unterschriebenes PDF oder Foto archivieren (Büro).
    async "upload-confirmation"() {
      const form = document.createElement("form");
      form.className = "form-stack";
      form.innerHTML = `
        <p>Das Originaldokument wird unverändert archiviert und dem eingereichten Leistungsnachweis ${esc(report.reportNumber)} zugeordnet.</p>
        <label><span class="field-label">Unterschrieben von <span aria-hidden="true">*</span></span><input name="signerName" maxlength="200" required autocomplete="off" value="${esc(report.contactPerson || "")}"></label>
        <label><span class="field-label">Unterschriebenes Dokument (PDF, JPG, PNG, bis 15 MB) <span aria-hidden="true">*</span></span><input name="file" type="file" accept="application/pdf,image/jpeg,image/png" required></label>`;
      const ok = await CalightModal.open({ title: "Unterschriebenes Dokument hochladen", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Hochladen", value: true, primary: true, validate: true }], initialFocus: '[name="signerName"]' });
      if (!ok) return;
      const file = form.elements.file.files[0];
      if (!file || file.size > 15 * 1024 * 1024) {
        notify(message, "Bitte ein Dokument bis 15 MB auswählen.");
        return;
      }
      const body = new FormData();
      body.append("file", file);
      body.append("signerName", form.elements.signerName.value.trim());
      body.append("version", String(report.version));
      const response = await fetch(`/api/work-reports/${encodeURIComponent(report.id)}/confirmation-document`, {
        method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": root.dataset.csrf, Accept: "application/json" }, body,
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error((result.details || [result.error || `HTTP ${response.status}`]).join(" "));
      adopt(result.data);
      notify(message, "Das unterschriebene Dokument wurde archiviert.", "success");
    },
    async "request-confirmation"() {
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/confirmation-request`, { method: "POST", body: { version: report.version } }));
      notify(message, "Als zur Bestätigung versendet vermerkt.", "success");
    },
    // Nachträgliche Zuordnung (Büro): nur Aufträge desselben Kunden; der Leistungsnachweis bleibt unverändert.
    async "assign-order"() {
      const candidates = reference.orders.filter((order) => order.customerId === report.customerId);
      if (!candidates.length) {
        notify(message, "Für diesen Kunden gibt es keinen Auftrag, dem der Rapport zugeordnet werden könnte.", "warning");
        return;
      }
      const form = document.createElement("form");
      form.className = "form-stack";
      form.innerHTML = `
        <p>Der eingereichte Leistungsnachweis, eine Kundenbestätigung und die Abrechnungspositionen bleiben unverändert. Die Zuordnung wird mit Benutzer und Zeitpunkt gespeichert und kann nicht automatisch geändert werden.</p>
        <label><span class="field-label">Auftrag von ${esc(report.customerName)} <span aria-hidden="true">*</span></span>
          <select name="orderId" required><option value="">Auftrag auswählen …</option>${candidates
            .map((order) => `<option value="${esc(order.id)}">${esc(order.orderNumber)} · ${esc(order.title)}</option>`).join("")}</select></label>`;
      const ok = await CalightModal.open({ title: "Auftrag zuordnen", content: form, actions: [{ label: "Abbrechen", value: false }, { label: "Zuordnen", value: true, primary: true, validate: true }], initialFocus: '[name="orderId"]' });
      if (!ok) return;
      adopt(await api(`/api/work-reports/${encodeURIComponent(report.id)}/assign-order`, { method: "POST", body: { version: report.version, orderId: form.elements.orderId.value } }));
      notify(message, `Der Rapport wurde dem Auftrag ${report.orderNumber} zugeordnet.`, "success");
    },
    async correct() {
      const ok = await confirmDialog("Korrektur anlegen", "<p>Es wird ein neuer Rapportentwurf mit dem eingereichten Stand angelegt. Der ursprüngliche Rapport und eine eventuelle Kundenbestätigung bleiben unverändert erhalten.</p>", "Korrektur anlegen");
      if (!ok) return;
      const created = await api("/api/work-reports", { method: "POST", body: { correctionOf: report.id } });
      location.assign(`/work-reports/${encodeURIComponent(created.id)}`);
    },
  };

  for (const box of root.querySelectorAll("[data-actions], [data-billing-actions]")) {
    box.addEventListener("click", (event) => {
      const button = event.target.closest("[data-action]");
      if (button) run(button, handlers[button.dataset.action]);
    });
  }

  window.addEventListener("beforeunload", (event) => {
    if (!proofDirty && !billingDirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  load().catch((exception) => notify(message, exception.message));
})();
