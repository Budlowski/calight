// Materialanforderungen: gemeinsamer Erfassungsdialog (Bericht, Auftrag, Übersicht)
// und die zentrale Übersichtsseite /material-requests.
(() => {
  "use strict";

  const { esc, api, format, notify } = Calight;
  const STATUS_LABELS = { open: "Offen", done: "Erledigt" };

  /**
   * Dialog zum Anlegen oder Bearbeiten.
   * @param {{orderId?:string, dailyReportId?:string, orderLabel?:string, request?:object, onSaved:Function}} options
   */
  function openDialog({ orderId = "", dailyReportId = "", orderLabel = "", request = null, onSaved }) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `
      ${orderLabel ? `<p class="muted">Auftrag ${esc(orderLabel)}</p>` : ""}
      <label><span class="field-label">Benötigtes Material <span aria-hidden="true">*</span></span>
        <textarea name="description" rows="5" maxlength="5000" required placeholder="z. B.&#10;50 m NYM-J 3x1,5&#10;10 Schalterdosen"></textarea>
        <small class="muted">Eine Position pro Zeile, mit Menge und Bezeichnung.</small></label>
      <div class="dialog-form-grid">
        <label><span class="field-label">Gewünscht bis (optional)</span><input name="neededBy" type="date"></label>
        <label class="span-all"><span class="field-label">Bemerkung (optional)</span><input name="notes" maxlength="2000"></label>
      </div>
      <p class="field-error" data-dialog-error hidden></p>`;
    if (request) {
      form.elements.description.value = request.description;
      form.elements.neededBy.value = request.neededBy || "";
      form.elements.notes.value = request.notes || "";
    }

    Calight.createDialog({
      title: request ? "Materialanforderung bearbeiten" : "Material anfordern",
      form,
      submitLabel: request ? "Speichern" : "Anforderung senden",
      discardMessage: "Die begonnene Materialanforderung verwerfen?",
      initialFocus: '[name="description"]',
      submit: async () => {
        const values = Object.fromEntries(new FormData(form));
        const saved = request
          ? await api(`/api/material-requests/${encodeURIComponent(request.id)}`, { method: "PUT", body: { ...values, version: request.version } })
          : await api("/api/material-requests", { method: "POST", body: { ...values, orderId, dailyReportId } });
        await onSaved?.(saved);
      },
    });
  }

  const setStatus = (request, status) => api(`/api/material-requests/${encodeURIComponent(request.id)}/status`, {
    method: "PATCH",
    body: { status, version: request.version },
  });

  const remove = (request) => api(`/api/material-requests/${encodeURIComponent(request.id)}`, {
    method: "DELETE",
    body: { version: request.version },
  });

  // Mehrzeilige Materialangaben bleiben als Zeilen lesbar.
  const lines = (text) => esc(text).replace(/\n/g, "<br>");
  const badge = (request) => `<span class="status-badge status-material-${esc(request.status)}">${STATUS_LABELS[request.status] || esc(request.status)}</span>`;

  /** Aktionen für eine Anforderung; einheitlich in Übersicht, Bericht und Auftrag. */
  function actions(request) {
    const buttons = [];
    if (request.canManage) {
      buttons.push(request.status === "open"
        ? `<button class="button button-light button-small" type="button" data-material-status="done" data-material-id="${esc(request.id)}" data-icon="clipboard-check">Erledigt</button>`
        : `<button class="button button-light button-small" type="button" data-material-status="open" data-material-id="${esc(request.id)}" data-icon="refresh-cw">Wieder öffnen</button>`);
    }
    if (request.canEdit) {
      buttons.push(`<button class="icon-button" type="button" data-material-edit="${esc(request.id)}" data-icon="square-pen" title="Bearbeiten" aria-label="Bearbeiten"></button>`);
      buttons.push(`<button class="icon-button icon-button-danger" type="button" data-material-delete="${esc(request.id)}" data-icon="trash" data-confirm-message="Materialanforderung endgültig löschen?" title="Löschen" aria-label="Löschen"></button>`);
    }
    return buttons.join("");
  }

  /** Kompakte Einträge für Bericht und Auftrag. */
  function renderCompact(requests) {
    if (!requests.length) return '<p class="empty-state">Noch kein Material angefordert.</p>';
    return `<div class="material-request-list">${requests.map((request) => `
      <article class="material-request-item">
        <div class="material-request-head">${badge(request)}<span class="muted">${esc(request.creatorName)} · ${format.dateTime(request.createdAt)}${request.neededBy ? ` · bis ${format.date(request.neededBy)}` : ""}</span></div>
        <div class="material-request-text">${lines(request.description)}</div>
        ${request.notes ? `<p class="muted material-request-notes">${esc(request.notes)}</p>` : ""}
        <div class="material-request-actions">${actions(request)}</div>
      </article>`).join("")}</div>`;
  }

  /**
   * Verknüpft Status-, Bearbeiten- und Löschaktionen in einem Container.
   * `find(id)` liefert die aktuelle Anforderung, `reload()` lädt die Darstellung neu.
   */
  function bindActions(container, { find, reload, message }) {
    container.addEventListener("click", async (event) => {
      const statusButton = event.target.closest("[data-material-status]");
      const editButton = event.target.closest("[data-material-edit]");
      const deleteButton = event.target.closest("[data-material-delete]");
      const button = statusButton || editButton || deleteButton;
      if (!button) return;
      const request = find(button.dataset.materialId || button.dataset.materialEdit || button.dataset.materialDelete);
      if (!request) return;
      if (editButton) {
        openDialog({ request, onSaved: reload });
        return;
      }
      if (deleteButton && !(await CalightModal.confirm({
        title: "Materialanforderung löschen",
        content: "Die Materialanforderung wird endgültig gelöscht.",
        confirmLabel: "Löschen",
        danger: true,
      }))) return;
      button.disabled = true;
      try {
        if (statusButton) await setStatus(request, statusButton.dataset.materialStatus);
        else await remove(request);
        await reload();
        notify(message, statusButton
          ? (statusButton.dataset.materialStatus === "done" ? "Materialanforderung als erledigt markiert." : "Materialanforderung wieder geöffnet.")
          : "Materialanforderung gelöscht.", "success");
      } catch (exception) {
        button.disabled = false;
        notify(message, exception.message);
      }
    });
  }

  globalThis.CalightMaterialRequests = Object.freeze({ openDialog, renderCompact, bindActions });

  // ---------------------------------------------------------------------------
  // Auftragsdetailseite: offene Anforderungen dieses Auftrags
  // ---------------------------------------------------------------------------
  const orderSection = document.querySelector("[data-order-material]");
  if (orderSection) {
    const box = orderSection.querySelector("[data-material-requests]");
    const orderMessage = orderSection.querySelector("[data-material-message]");
    let requests = [];
    try {
      requests = JSON.parse(orderSection.querySelector("[data-material-json]")?.textContent || "[]");
    } catch {
      requests = [];
    }
    const render = () => { box.innerHTML = renderCompact(requests); };
    const reload = async () => {
      const page = await api(`/api/material-requests?order=${encodeURIComponent(orderSection.dataset.orderId)}&per_page=100&sort=created&dir=asc`);
      requests = page.items;
      render();
    };
    render();
    bindActions(box, { find: (id) => requests.find((item) => item.id === id), reload, message: orderMessage });
    orderSection.querySelector("[data-add-material]")?.addEventListener("click", () => openDialog({
      orderId: orderSection.dataset.orderId,
      orderLabel: orderSection.dataset.orderLabel,
      onSaved: async () => {
        await reload();
        notify(orderMessage, "Materialanforderung wurde gespeichert.", "success");
      },
    }));
  }

  // ---------------------------------------------------------------------------
  // Zentrale Übersicht
  // ---------------------------------------------------------------------------
  const root = document.querySelector("#material-requests-app");
  if (!root) return;

  const message = root.querySelector("[data-message]");
  const orderFilter = root.querySelector("[data-order-filter]");
  const orderHref = (request) => `/orders/${encodeURIComponent(request.orderId)}`;
  /** Auftrag als Link; Anforderungen aus Berichten ohne Auftrag verweisen auf den Bericht. */
  const orderLink = (request, className = "") => request.orderId
    ? `<a${className ? ` class="${className}"` : ""} href="${orderHref(request)}">${esc(request.orderNumber)}</a>`
    : request.dailyReportId
      ? `<a${className ? ` class="${className}"` : ""} href="/daily-reports/${encodeURIComponent(request.dailyReportId)}">${esc(request.costCenterNumber || "Ohne Auftrag")}</a>`
      : `<span${className ? ` class="${className}"` : ""}>Ohne Auftrag</span>`;
  const reportLink = (request) => (request.dailyReportId
    ? `<a href="/daily-reports/${encodeURIComponent(request.dailyReportId)}">Bericht ${format.date(request.reportDate)}</a>`
    : "Direkt angefordert");

  const renderCard = (request) => `
    <article class="overview-card material-request-card">
      <div class="management-card-head">${orderLink(request, "service-number")}${badge(request)}</div>
      <div class="overview-card-meta"><span>${esc(request.customerName || "–")}</span><span>${esc(request.orderTitle)}</span></div>
      <div class="material-request-text">${lines(request.description)}</div>
      ${request.notes ? `<p class="muted material-request-notes">${esc(request.notes)}</p>` : ""}
      <div class="overview-card-meta">
        <span>${esc(request.creatorName)} · ${format.dateTime(request.createdAt)}</span>
        ${request.neededBy ? `<span>Gewünscht bis ${format.date(request.neededBy)}</span>` : ""}
        <span>${reportLink(request)}</span>
        ${request.status === "done" ? `<span>Erledigt von ${esc(request.completedByName)} · ${format.dateTime(request.completedAt)}</span>` : ""}
      </div>
      <div class="overview-card-actions">${actions(request)}</div>
    </article>`;

  const renderTable = (requests, header) => `
    <thead><tr>
      ${header("order", "Auftrag", "cell-number")}${header("customer", "Kunde", "cell-text")}<th class="cell-title">Material</th>
      ${header("created", "Angefordert", "cell-datetime")}<th class="cell-text">Von</th>${header("needed", "Gewünscht bis", "cell-date")}
      ${header("status", "Status", "cell-status")}<th class="cell-actions material-actions-column">Aktionen</th>
    </tr></thead>
    <tbody>${requests.map((request) => `
      <tr>
        <td class="cell-number">${orderLink(request)}</td>
        <td class="cell-text" title="${esc(request.customerName || "")}">${request.customerName ? esc(request.customerName) : '<span class="muted">–</span>'}</td>
        <td class="cell-title material-request-cell" title="${esc(request.description)}">${esc(request.description.split("\n")[0])}${request.description.includes("\n") ? " …" : ""}
          <small class="muted">${reportLink(request)}</small></td>
        <td class="cell-datetime">${format.dateTime(request.createdAt)}</td>
        <td class="cell-text" title="${esc(request.creatorName)}">${esc(request.creatorName)}</td>
        <td class="cell-date">${format.date(request.neededBy)}</td>
        <td class="cell-status">${badge(request)}</td>
        <td class="cell-actions material-actions-column">${actions(request)}</td>
      </tr>`).join("")}</tbody>`;

  const list = CalightListView.createListPage({
    root,
    module: "material-requests",
    endpoint: "/api/material-requests",
    results: root.querySelector("[data-list]"),
    search: root.querySelector("[data-search]"),
    filters: { status: root.querySelector("[data-status-filter]"), order: orderFilter },
    sortSelect: root.querySelector("[data-sort]"),
    count: root.querySelector("[data-count]"),
    reset: root.querySelector("[data-reset]"),
    message,
    defaultSort: "created",
    sorts: {
      created: { label: "Angefordert", type: "date", dir: "desc" },
      needed: { label: "Gewünscht bis", type: "date", dir: "asc" },
      order: { label: "Auftrag", dir: "asc" },
      customer: { label: "Kunde", dir: "asc" },
      status: { label: "Status", dir: "asc" },
      updated: { label: "Zuletzt geändert", type: "date", dir: "desc" },
    },
    emptyText: "Keine offenen Materialanforderungen.",
    noMatchText: "Keine Materialanforderungen entsprechen der aktuellen Auswahl.",
    loadingText: "Materialanforderungen werden geladen …",
    renderTable,
    renderCard,
  });

  bindActions(root.querySelector("[data-list]"), {
    find: (id) => list.items.find((item) => item.id === id),
    reload: () => list.reload(),
    message,
  });

  async function init() {
    const reference = await api("/api/daily-reports/reference-data");
    orderFilter.innerHTML = '<option value="">Alle Aufträge</option>' + reference.orders
      .map((order) => `<option value="${esc(order.id)}">${esc(order.order_number)} · ${esc(order.title)}</option>`)
      .join("");
    list.start();
  }

  init().catch((exception) => notify(message, exception.message));
})();
