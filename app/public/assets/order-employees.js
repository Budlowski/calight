// Auftragsdetail (Büro): Mitarbeiter zuordnen und entfernen. Bestimmt die Auftragssichtbarkeit für
// Monteure (OrderVisibility). Rechte und Prüfungen liegen auf dem Server (OrderService).
(() => {
  "use strict";

  const root = document.querySelector("[data-order-employees]");
  if (!root || !globalThis.Calight) return;
  const { esc, api, notify } = Calight;
  const list = root.querySelector("[data-employees-list]");
  const form = root.querySelector("[data-employees-add]");
  const message = root.querySelector("[data-employees-message]");
  const base = `/api/orders/${encodeURIComponent(root.dataset.orderId)}/employees`;
  let busy = false;

  function render(data) {
    const editable = data.canEdit && !Calight.moduleReadOnly;
    list.innerHTML = data.assigned.length ? data.assigned.map((employee) => `
      <li class="order-employee">
        <span><strong>${esc(employee.name)}</strong>${employee.personnelNumber ? ` <small class="muted">${esc(employee.personnelNumber)}</small>` : ""}
          ${employee.hasLogin ? "" : ' <small class="muted">· ohne Benutzerkonto</small>'}${employee.active ? "" : ' <small class="muted">· inaktiv</small>'}</span>
        ${editable ? `<button class="icon-button icon-button-danger" type="button" data-remove-employee="${esc(employee.id)}" data-icon="trash" aria-label="${esc(employee.name)} entfernen" title="Zuordnung entfernen"></button>` : ""}
      </li>`).join("") : '<li class="muted">Noch keine Mitarbeiter zugeordnet.</li>';
    form.hidden = !editable || data.available.length === 0;
    const select = form.elements.employeeId;
    select.innerHTML = '<option value="">Mitarbeiter wählen …</option>' + data.available
      .map((employee) => `<option value="${esc(employee.id)}">${esc(employee.name)}${employee.personnelNumber ? ` (${esc(employee.personnelNumber)})` : ""}</option>`).join("");
    // Suchfeld einmal einrichten; es liest die Optionen bei jeder Eingabe neu. Nach dem Neuaufbau leeren.
    Calight.searchableSelect(select, { placeholder: "Name oder Personalnummer eingeben …" });
    const input = form.querySelector(".suggest-select input");
    if (input) input.value = "";
  }

  async function run(operation, text) {
    if (busy) return;
    busy = true;
    try {
      render(await operation());
      if (text) notify(message, text, "success");
    } catch (error) {
      notify(message, error.message);
    } finally {
      busy = false;
    }
  }

  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const employeeId = form.elements.employeeId.value;
    if (!employeeId) return;
    run(() => api(base, { method: "POST", body: { employeeId } }), "Mitarbeiter zugeordnet.");
  });
  list.addEventListener("click", async (event) => {
    const button = event.target.closest("[data-remove-employee]");
    if (!button) return;
    const ok = await CalightModal.confirm({
      title: "Zuordnung entfernen?",
      content: "Der Mitarbeiter sieht den Auftrag danach je nach Einstellung nicht mehr. Erfasste Zeiten, Berichte und eigene Entwürfe bleiben erhalten.",
      confirmLabel: "Entfernen",
      danger: true,
    });
    if (ok) run(() => api(`${base}/${encodeURIComponent(button.dataset.removeEmployee)}`, { method: "DELETE", body: {} }), "Zuordnung entfernt.");
  });

  run(() => api(base));
})();
