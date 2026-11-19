// Bestellungen: Übersicht, Anlegen (neue Bestellung, externe Bestellung aufnehmen) und
// gemeinsame Helfer für die Detailseite (Statusanzeige, Lieferanten-Schnellanlage, Uploads).
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const href = (order) => `/purchase-orders/${encodeURIComponent(order.id)}`;
  const badge = (kind, value, label) => `<span class="status-badge status-po-${kind}-${esc(value)}">${esc(label)}</span>`;
  const originBadge = (order) => (order.origin === "external" ? '<span class="status-badge status-po-origin">Extern</span>' : "");
  // Drei getrennte Status: Bestellung, Lieferung, Zahlung. Liefer-/Zahlungsstatus erst nach dem Bestellen.
  const statusBadges = (order, { prefix = false } = {}) => [
    badge("status", order.status, order.statusLabel),
    order.status === "draft" && !order.placed ? "" : badge("delivery", order.deliveryStatus, prefix ? `Lieferung: ${order.deliveryLabel}` : order.deliveryLabel),
    order.status === "draft" && !order.placed ? "" : badge("payment", order.paymentStatus, prefix ? `Zahlung: ${order.paymentLabel}` : order.paymentLabel),
  ].join("");
  const jobLabel = (order) => (order.orderId ? `${order.jobNumber} · ${order.jobTitle}` : "Ohne Auftrag");
  const money = (value) => (value === null || value === undefined || value === "" ? "–" : format.money(value));
  const today = () => {
    const now = new Date();
    return new Date(now - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  };
  const supplierLabel = (supplier) => (supplier.number ? `${supplier.number} · ${supplier.name}` : supplier.name);

  /** Fehlertext aus einer API-Antwort (gleiches Format wie Calight.api). */
  const errorText = (result, status) => (Array.isArray(result.details) ? result.details.join(" ") : "") || result.error || `Anfrage fehlgeschlagen (HTTP ${status}).`;

  /** Vorgang mit optionaler Datei als multipart/form-data senden. */
  async function postForm(path, fields, file, csrf) {
    const data = new FormData();
    for (const [key, value] of Object.entries(fields)) {
      if (value !== undefined && value !== null) data.append(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
    }
    if (file) data.append("file", file);
    const response = await fetch(path, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf, Accept: "application/json" }, body: data });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(errorText(result, response.status));
    return result.data;
  }

  /**
   * Lieferantenauswahl mit Schnellanlage: Die Suche bleibt erhalten; „Neuen Lieferanten anlegen“
   * öffnet einen kleinen Dialog über dem aktuellen (Name Pflicht, Rest optional). Nach dem
   * Speichern wird der neue Lieferant ausgewählt. Vorhandene Lieferanten werden nie geändert.
   */
  function supplierPicker(select, suppliers, { onCreated = null } = {}) {
    const picker = Calight.searchableSelect(select, { placeholder: "Lieferantennummer oder Name eingeben …" });
    const label = select.closest("label");
    const toggle = document.createElement("button");
    toggle.type = "button";
    toggle.className = "button button-light purchase-supplier-create";
    toggle.dataset.icon = "plus";
    toggle.title = "Neuen Lieferanten anlegen";
    toggle.setAttribute("aria-label", "Neuen Lieferanten anlegen");
    // Plus-Knopf direkt neben dem Suchfeld, damit das Formularraster unverändert bleibt.
    const box = select.nextElementSibling?.classList.contains("suggest-select") ? select.nextElementSibling : null;
    if (box) {
      const row = document.createElement("div");
      row.className = "purchase-supplier-row";
      box.before(row);
      row.append(box, toggle);
    } else {
      select.after(toggle);
    }
    toggle.addEventListener("click", () => {
      const form = document.createElement("form");
      form.className = "form-stack";
      form.noValidate = true;
      const typed = label?.querySelector(".suggest-select input")?.value.trim() || "";
      form.innerHTML = `
        <div class="dialog-form-grid">
          <label class="span-all"><span class="field-label">Name <span aria-hidden="true">*</span></span><input name="name" maxlength="200" required autocomplete="off" value="${esc(select.value ? "" : typed)}"></label>
          <label><span class="field-label">Straße</span><input name="street" maxlength="200" autocomplete="off"></label>
          <label><span class="field-label">Hausnummer</span><input name="houseNumber" maxlength="30" autocomplete="off"></label>
          <label><span class="field-label">PLZ</span><input name="postalCode" maxlength="20" autocomplete="off"></label>
          <label><span class="field-label">Ort</span><input name="city" maxlength="120" autocomplete="off"></label>
          <label><span class="field-label">Land</span><input name="country" maxlength="100" autocomplete="off"></label>
          <label><span class="field-label">Unsere Kundennummer</span><input name="customerNumber" maxlength="100" autocomplete="off"></label>
          <label class="span-all"><span class="field-label">Kontakt (optional)</span><input name="contact" maxlength="2000" autocomplete="off" placeholder="Telefon, E-Mail, Ansprechpartner"></label>
        </div>
        <p class="field-error" data-supplier-error hidden></p>`;
      const error = form.querySelector("[data-supplier-error]");
      CalightModal.openOver({
        title: "Lieferant anlegen",
        content: form,
        initialFocus: '[name="name"]',
        actions: [
          { label: "Abbrechen", value: null },
          {
            label: "Anlegen",
            primary: true,
            validate: true,
            onClick: async () => {
              const body = Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [key, String(value).trim()]));
              try {
                const supplier = await api("/api/purchase-orders/suppliers", { method: "POST", body });
                const entry = { id: supplier.id, number: supplier.supplierNumber, name: supplier.name };
                suppliers.push(entry);
                select.add(new Option(supplierLabel(entry), entry.id));
                select.value = entry.id;
                select.dispatchEvent(new Event("change", { bubbles: true }));
                picker?.refresh();
                onCreated?.(entry);
                return true;
              } catch (exception) {
                error.textContent = exception.message;
                error.hidden = false;
                return false;
              }
            },
          },
        ],
      });
    });
    return picker;
  }

  globalThis.CalightPurchaseOrders = Object.freeze({ href, badge, originBadge, statusBadges, jobLabel, money, today, supplierLabel, postForm, supplierPicker });

  const root = document.querySelector("#purchase-orders-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const canCosts = root.dataset.canCosts === "1";
  const supplierFilter = root.querySelector("[data-supplier-filter]");
  const orderFilter = root.querySelector("[data-order-filter]");
  let reference = { suppliers: [], orders: [] };

  // Bestellwert mit aktuell bestätigtem Wert darunter, wenn er abweicht (spart eine Spalte).
  const orderedValue = (order) => {
    const confirmed = order.confirmedNetTotal !== null && order.confirmedNetTotal !== undefined && order.confirmedNetTotal !== order.expectedNetTotal
      ? `<small class="purchase-confirmed-value">bestätigt ${money(order.confirmedNetTotal)}</small>` : "";
    return `${money(order.expectedNetTotal)}${confirmed}`;
  };
  const rowClass = (order) => (order.status === "cancelled" ? " purchase-cancelled" : "");
  const canWrite = root.dataset.canWrite === "1";
  const titleLine = (order) => (order.title ? `<small class="purchase-row-title" title="${esc(order.title)}">${esc(order.title)}</small>` : "");

  // Schnellerfassung je Bestellung, ohne die Bestellung zu öffnen (Rechte prüft der Server erneut).
  const quickActions = (order) => {
    if (!canWrite) return "";
    const items = [];
    if (order.placed) {
      items.push(["confirmation", "AB erfassen"], ["delivery", "Lieferung erfassen"]);
      if (canCosts) items.push(["invoice", "Rechnung erfassen"], ["credit", "Gutschrift erfassen"], ["payment", "Zahlung erfassen"]);
    }
    items.push(["document", "Dokument hochladen"]);
    return `<details class="context-menu purchase-row-menu"><summary class="icon-button" data-icon="menu" title="Vorgang erfassen" aria-label="Vorgang zu ${esc(order.orderNumber)} erfassen"></summary>
      <div class="context-menu-panel">${items.map(([dialog, label]) => `<button type="button" data-quick="${dialog}" data-order-id="${esc(order.id)}" data-icon="${dialog === "document" ? "file-up" : "plus"}">${label}</button>`).join("")}</div></details>`;
  };

  const renderCard = (order) => `
    <article class="overview-card${rowClass(order)}" tabindex="0" data-href="${href(order)}">
      <div class="management-card-head"><span class="service-number">${esc(order.orderNumber)}</span><span class="management-card-badges">${originBadge(order)}${quickActions(order)}</span></div>
      <div class="overview-card-title" title="${esc(order.supplierName)}">${esc(order.supplierName)}</div>
      ${titleLine(order)}
      <div class="overview-card-meta">
        <span>${esc(jobLabel(order))}</span>
        <span>${order.orderDate ? format.date(order.orderDate) : "Noch nicht bestellt"}</span>
        ${order.externalReference ? `<span>Ref. ${esc(order.externalReference)}</span>` : ""}
        ${canCosts ? `<span>Bestellt ${money(order.expectedNetTotal)}${order.confirmedNetTotal ? ` · bestätigt ${money(order.confirmedNetTotal)}` : ""} · Ist ${money(order.actualNetTotal)}</span>` : ""}
      </div>
      <div class="management-card-badges">${statusBadges(order)}</div>
    </article>`;

  const renderTable = (items, header) => `
    <thead><tr>
      ${header("number", "Bestellnummer", "cell-number")}${header("supplier", "Lieferant", "cell-text")}${header("date", "Datum", "cell-date")}
      ${header("order", "Auftrag", "cell-number")}
      <th class="cell-status">Status</th>
      ${canCosts ? `${header("expected", "Bestellwert netto", "cell-money")}${header("actual", "Ist netto", "cell-money")}` : ""}
      ${canWrite ? '<th class="cell-actions"><span class="visually-hidden">Aktionen</span></th>' : ""}
    </tr></thead>
    <tbody>${items.map((order) => `
      <tr tabindex="0" data-href="${href(order)}" class="${rowClass(order).trim()}">
        <td class="cell-number">${esc(order.orderNumber)} ${originBadge(order)}</td>
        <td class="cell-text" title="${esc(order.supplierName)}">${esc(order.supplierName)}${order.externalReference ? `<small class="muted"> · ${esc(order.externalReference)}</small>` : ""}${titleLine(order)}</td>
        <td class="cell-date">${order.orderDate ? format.date(order.orderDate) : '<span class="muted">–</span>'}</td>
        <td class="cell-number" title="${esc(jobLabel(order))}">${order.orderId ? esc(order.jobNumber) : '<span class="muted">–</span>'}</td>
        <td class="cell-status"><span class="purchase-status-stack">${statusBadges(order)}</span></td>
        ${canCosts ? `<td class="cell-money">${orderedValue(order)}</td><td class="cell-money">${money(order.actualNetTotal)}</td>` : ""}
        ${canWrite ? `<td class="cell-actions">${quickActions(order)}</td>` : ""}
      </tr>`).join("")}</tbody>`;

  const sorts = {
    updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
    date: { label: "Bestelldatum", type: "date", dir: "desc" },
    number: { label: "Bestellnummer", dir: "asc" },
    supplier: { label: "Lieferant", dir: "asc" },
    order: { label: "Auftrag", dir: "asc" },
  };
  if (canCosts) {
    sorts.expected = { label: "Bestellwert", type: "number", dir: "desc" };
    sorts.confirmed = { label: "Bestätigter Wert", type: "number", dir: "desc" };
    sorts.actual = { label: "Tatsächliche Kosten", type: "number", dir: "desc" };
  }

  const list = CalightListView.createListPage({
    root,
    module: "purchase-orders",
    endpoint: "/api/purchase-orders",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: {
      supplier: supplierFilter,
      order: orderFilter,
      origin: root.querySelector("[data-origin-filter]"),
      status: root.querySelector("[data-status-filter]"),
      delivery: root.querySelector("[data-delivery-filter]"),
      payment: root.querySelector("[data-payment-filter]"),
    },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "updated",
    sorts,
    emptyText: "Noch keine Bestellungen vorhanden.",
    noMatchText: "Keine Bestellungen entsprechen der aktuellen Auswahl.",
    loadingText: "Bestellungen werden geladen …",
    renderTable,
    renderCard,
  });

  const supplierOptions = () => reference.suppliers.map((supplier) => `<option value="${esc(supplier.id)}">${esc(supplierLabel(supplier))}</option>`).join("");
  const orderOptions = () => reference.orders.map((order) => `<option value="${esc(order.id)}">${esc(order.orderNumber)} · ${esc(order.title)}</option>`).join("");

  /** Anlegen: getrennte Dialoge für neue (interne) und extern bereits ausgelöste Bestellungen. */
  function newOrder(external) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      <label><span class="field-label">Lieferant <span aria-hidden="true">*</span></span>
        <select name="supplierId" required><option value="">Lieferant auswählen …</option>${supplierOptions()}</select></label>
      ${external ? `<div class="dialog-form-grid">
        <label><span class="field-label">Externe Bestellnummer (optional)</span><input name="externalReference" maxlength="100" autocomplete="off" placeholder="z. B. Bestellnr. des Webshops"></label>
        <label><span class="field-label">Bestelldatum</span><input name="orderDate" type="date" value="${today()}"></label>
        ${canCosts ? '<label><span class="field-label">Bestellwert netto (optional)</span><input name="expectedNetTotal" inputmode="decimal" placeholder="0,00"></label>' : ""}
        <label${canCosts ? "" : ' class="span-all"'}><span class="field-label">Bestellung oder Bestätigung (optional)</span><input name="file" type="file" accept="application/pdf,image/*"></label>
      </div>` : ""}
      <label><span class="field-label">Auftrag (optional)</span>
        <select name="orderId"><option value="">Ohne Auftrag</option>${orderOptions()}</select></label>
      ${external ? "" : '<p class="muted">Positionen und Lieferanschrift erfassen Sie anschließend auf der Bestellseite.</p>'}
      <p class="field-error" data-dialog-error hidden></p>`;
    supplierPicker(form.elements.supplierId, reference.suppliers, {
      onCreated: () => { supplierFilter.innerHTML = '<option value="">Alle Lieferanten</option>' + supplierOptions(); },
    });
    Calight.searchableSelect(form.elements.orderId, { placeholder: "Auftragsnummer oder Bezeichnung …" });

    Calight.createDialog({
      title: external ? "Externe Bestellung aufnehmen" : "Neue Bestellung",
      form,
      submitLabel: external ? "Aufnehmen" : "Anlegen",
      discardMessage: "Das Anlegen abbrechen?",
      initialFocus: ".suggest-select input",
      submit: async () => {
        if (!form.elements.supplierId.value) throw new Error("Bitte einen Lieferanten auswählen oder neu anlegen.");
        const body = { supplierId: form.elements.supplierId.value, orderId: form.elements.orderId.value };
        if (external) {
          Object.assign(body, {
            origin: "external",
            externalReference: form.elements.externalReference.value.trim(),
            orderDate: form.elements.orderDate.value,
            expectedNetTotal: form.elements.expectedNetTotal ? form.elements.expectedNetTotal.value.trim().replace(",", ".") : "",
          });
        }
        const order = await api("/api/purchase-orders", { method: "POST", body });
        const file = external ? form.elements.file.files[0] : null;
        if (file) {
          try {
            await postForm(`/api/purchase-orders/${encodeURIComponent(order.id)}/documents`, { kind: "supplier_confirmation" }, file, root.dataset.csrf);
          } catch (exception) {
            // Die Bestellung ist angelegt; die Datei lässt sich auf der Bestellseite erneut hochladen.
            try {
              sessionStorage.setItem("calight-purchase-notice", `Die Bestellung wurde angelegt, die Datei aber nicht übernommen: ${exception.message}`);
            } catch {
              // Ohne Speicher entfällt nur der Hinweis.
            }
          }
        }
        location.assign(href(order));
      },
    });
  }

  // Vorgangsdialoge der Detailseite wiederverwenden; danach Liste neu laden.
  let quickOrder = null;
  const quickDialogs = globalThis.CalightPurchaseDialogs?.create({
    getOrder: () => quickOrder,
    getReference: () => reference,
    csrf: root.dataset.csrf,
    onSaved: (next, text) => {
      notify(message, `${next.orderNumber}: ${text}`, "success");
      list.reload();
    },
  });
  root.querySelector("[data-list]").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-quick]");
    if (!button || !quickDialogs) return;
    event.stopPropagation();
    button.closest("details.context-menu")?.removeAttribute("open");
    button.disabled = true;
    try {
      // Aktueller Stand (Version, Belege) für Konfliktschutz und Auswahllisten.
      quickOrder = await api(`/api/purchase-orders/${encodeURIComponent(button.dataset.orderId)}`);
      quickDialogs[button.dataset.quick]();
    } catch (exception) {
      notify(message, exception.message);
    } finally {
      button.disabled = false;
    }
  });

  async function init() {
    reference = await api("/api/purchase-orders/reference-data");
    supplierFilter.innerHTML = '<option value="">Alle Lieferanten</option>' + supplierOptions();
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + orderOptions();
    list.start();
  }

  root.querySelector("[data-new]")?.addEventListener("click", () => newOrder(false));
  root.querySelector("[data-new-external]")?.addEventListener("click", () => newOrder(true));
  init().catch((exception) => notify(message, exception.message));
})();
