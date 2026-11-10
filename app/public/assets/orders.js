(() => {
  "use strict";
  const root = document.querySelector("#orders-page");
  if (!root) return;
  const list = root.querySelector("[data-list]"), form = root.querySelector("[data-form]"),
    search = root.querySelector("[data-search]"), status = root.querySelector("[data-status]"),
    type = root.querySelector("[data-type]"), sort = root.querySelector("[data-sort]"),
    count = root.querySelector("[data-count]"), reset = root.querySelector("[data-reset]"),
    newButton = root.querySelector("[data-new-order]"), message = root.querySelector("[data-message]");
  let orders = [], formDirty = false;
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[character]);
  const labels = { open: "Offen", in_progress: "In Bearbeitung", completed: "Abgeschlossen", invoiced: "Abgerechnet" };
  const collator = new Intl.Collator("de-DE", { numeric: true, sensitivity: "base" });
  const date = (value, withTime = false) => new Intl.DateTimeFormat("de-DE", withTime ? { dateStyle: "short", timeStyle: "short" } : { dateStyle: "short" }).format(new Date(value));

  function notify(text, kind = "error") {
    message.textContent = text;
    message.className = `notice notice-${kind}`;
    message.hidden = false;
  }
  function render() {
    const query = search.value.trim().toLocaleLowerCase("de-DE");
    const rows = orders.filter((order) => (!status.value || order.status === status.value)
      && (!type.value || order.orderType === type.value)
      && (!query || `${order.orderNumber} ${order.title} ${order.customerName} ${order.customerNumber || ""} ${order.projectName || ""} ${order.projectNumber || ""} ${order.sourceOfferNumber || ""}`.toLocaleLowerCase("de-DE").includes(query)));
    rows.sort((a, b) => sort.value === "number" ? collator.compare(a.orderNumber, b.orderNumber)
      : sort.value === "title" ? collator.compare(a.title, b.title) : String(b.updatedAt).localeCompare(String(a.updatedAt)));
    count.textContent = `${rows.length} von ${orders.length}`;
    reset.disabled = !query && !status.value && !type.value && sort.value === "updated";
    list.innerHTML = rows.map((order) => {
      const source = order.orderType === "offer"
        ? `Angebot ${esc(order.sourceOfferNumber || "ohne Nummer")}${Number(order.sourceRevision) > 0 ? ` · Revision ${Number(order.sourceRevision)}` : ""}`
        : "Direkter Regieauftrag";
      return `<article class="service-list-item management-card"><div class="service-list-main"><div class="management-card-head"><span class="service-number">${esc(order.orderNumber)}</span><span class="status-badge status-${esc(order.status)}">${labels[order.status] || esc(order.status)}</span></div><strong>${esc(order.title)}</strong><div class="management-card-facts"><span>${esc(order.customerName)}</span><span>${esc(order.projectName || "Ohne Projekt")}</span><span>${source}</span></div><span class="muted">Auftragsdatum ${date(order.orderDate)} · geändert ${date(order.updatedAt, true)}</span></div><div class="service-list-actions"><a class="button button-light button-small" data-icon="eye" href="/orders/${encodeURIComponent(order.id)}">Ansehen</a></div></article>`;
    }).join("") || '<p class="card empty-state">Keine Aufträge entsprechen der aktuellen Auswahl.</p>';
  }
  async function load() {
    list.innerHTML = '<p class="card empty-state">Aufträge werden geladen …</p>';
    const response = await fetch("/api/orders", { credentials: "same-origin" }), result = await response.json();
    if (!response.ok) throw new Error(result.error || "Aufträge konnten nicht geladen werden.");
    orders = result.orders; render();
  }
  async function confirmDiscard() {
    return !formDirty || CalightModal.confirm({ title: "Ungespeicherte Eingaben", content: "Den begonnenen Regieauftrag verwerfen?", confirmLabel: "Verwerfen", danger: true });
  }

  for (const element of [search, status, type, sort]) element.addEventListener(element === search ? "input" : "change", render);
  reset.addEventListener("click", () => { search.value = ""; status.value = ""; type.value = ""; sort.value = "updated"; render(); search.focus(); });
  newButton.addEventListener("click", async () => {
    if (!(await confirmDiscard())) return;
    form.reset(); form.hidden = false; formDirty = false; message.hidden = true; form.elements.customerId.focus();
  });
  root.querySelector("[data-cancel]").addEventListener("click", async () => {
    if (!(await confirmDiscard())) return;
    form.reset(); form.hidden = true; formDirty = false;
  });
  form.addEventListener("input", () => { formDirty = true; });
  form.elements.customerId.addEventListener("change", () => {
    const customerId = form.elements.customerId.value;
    for (const option of form.elements.projectId.options) if (option.value) option.hidden = option.dataset.customerId !== customerId;
    if (form.elements.projectId.selectedOptions[0]?.hidden) form.elements.projectId.value = "";
  });
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    submit.disabled = true; message.hidden = true;
    try {
      const response = await fetch("/api/orders", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "X-CSRF-Token": root.dataset.csrf }, body: JSON.stringify(Object.fromEntries(new FormData(form))) });
      const result = await response.json();
      if (!response.ok) throw new Error((result.details || [result.error]).filter(Boolean).join(" ") || "Auftrag konnte nicht angelegt werden.");
      formDirty = false; location.assign(`/orders/${encodeURIComponent(result.data.id)}`);
    } catch (error) { submit.disabled = false; notify(error.message); }
  });
  window.addEventListener("beforeunload", (event) => { if (formDirty) { event.preventDefault(); event.returnValue = ""; } });
  load().catch((error) => notify(error.message));
})();
