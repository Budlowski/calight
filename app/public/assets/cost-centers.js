// Stammdaten → Kostenstellen: Übersicht mit Jahresauswertung, Pflege nur für Administratoren.
(() => {
  "use strict";

  const root = document.querySelector("#cost-centers-app");
  if (!root) return;
  const { esc, api, format, notify } = Calight;
  const message = root.querySelector("[data-message]");
  const list = root.querySelector("[data-list]");
  const yearSelect = root.querySelector("[data-year]");
  const showInactive = root.querySelector("[data-show-inactive]");
  const hours = (minutes) => `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, "0")} h`;
  let data = null;

  const thisYear = new Date().getFullYear();
  yearSelect.innerHTML = Array.from({ length: 6 }, (_, index) => thisYear - index).map((year) => `<option value="${year}">${year}</option>`).join("");

  function render() {
    const items = data.items.filter((item) => item.active || showInactive.checked);
    root.querySelector("[data-actions]").innerHTML = data.canManage
      ? `${data.templateAvailable ? '<button class="button button-light" type="button" data-template data-icon="file-down">Vorlage übernehmen …</button>' : ""}<button class="button" type="button" data-new data-icon="plus">Kostenstelle anlegen</button>`
      : "";
    if (!items.length) {
      list.innerHTML = `<p class="empty-state">${data.items.length ? "Keine aktiven Kostenstellen." : "Noch keine Kostenstellen angelegt."}${data.canManage && data.templateAvailable && !data.items.length ? " Mit „Vorlage übernehmen“ lassen sich typische Kostenstellen (K100–K190) anlegen." : ""}</p>`;
      return;
    }
    list.innerHTML = `<div class="overview-table-wrap"><table class="overview-table"><thead><tr>
        <th class="cell-number">Nummer</th><th class="cell-title">Bezeichnung</th><th class="cell-status">Status</th><th class="cell-money">Arbeitszeit ${esc(data.year)}</th>
        ${data.canCosts ? `<th class="cell-money">Bestellungen ${esc(data.year)}</th><th class="cell-money">Auslagen ${esc(data.year)}</th>` : ""}
        ${data.canManage ? '<th class="cell-actions"><span class="visually-hidden">Aktionen</span></th>' : ""}
      </tr></thead><tbody>${items.map((item) => `<tr>
        <td class="cell-number"><strong>${esc(item.number)}</strong></td>
        <td class="cell-title">${esc(item.name)}${item.description ? `<br><small class="muted">${esc(item.description)}</small>` : ""}</td>
        <td class="cell-status">${item.active ? '<span class="status-badge status-cost-center-active">Aktiv</span>' : '<span class="status-badge">Deaktiviert</span>'}</td>
        <td class="cell-money">${item.minutes ? hours(item.minutes) : "–"}</td>
        ${data.canCosts ? `<td class="cell-money">${format.money(item.purchaseTotal)}${item.purchasesWithoutCost ? `<br><small class="muted">${item.purchasesWithoutCost} ohne Ist-Kosten</small>` : ""}</td><td class="cell-money">${format.money(item.expenseTotal)}</td>` : ""}
        ${data.canManage ? `<td class="cell-actions"><button class="icon-button" type="button" data-edit="${esc(item.id)}" data-icon="square-pen" title="Bearbeiten" aria-label="${esc(item.number)} bearbeiten"></button></td>` : ""}
      </tr>`).join("")}</tbody></table></div>`;
  }

  async function load() {
    data = await api(`/api/cost-centers?year=${encodeURIComponent(yearSelect.value)}`);
    render();
  }

  function editDialog(item = null) {
    const form = document.createElement("form");
    form.className = "form-stack";
    form.noValidate = true;
    form.innerHTML = `<div class="dialog-form-grid">
        <label><span class="field-label">Nummer <span aria-hidden="true">*</span></span><input name="number" maxlength="20" required placeholder="z. B. K100" value="${esc(item?.number || "")}"></label>
        <label><span class="field-label">Bezeichnung <span aria-hidden="true">*</span></span><input name="name" maxlength="120" required value="${esc(item?.name || "")}"></label>
        <label class="span-all"><span class="field-label">Beschreibung (optional)</span><input name="description" maxlength="500" value="${esc(item?.description || "")}"></label>
      </div>
      <label class="check-inline"><input type="checkbox" name="active"${!item || item.active ? " checked" : ""}> Aktiv (für neue Zuordnungen auswählbar)</label>
      <p class="muted">Deaktivierte Kostenstellen bleiben in bisherigen Zuordnungen und Auswertungen erhalten.</p>
      <p class="field-error" data-dialog-error hidden></p>`;
    Calight.createDialog({
      title: item ? `Kostenstelle ${item.number}` : "Kostenstelle anlegen",
      form,
      submitLabel: "Speichern",
      discardMessage: "Änderungen verwerfen?",
      initialFocus: item ? '[name="name"]' : '[name="number"]',
      submit: async () => {
        const body = { number: form.elements.number.value.trim(), name: form.elements.name.value.trim(), description: form.elements.description.value.trim(), active: form.elements.active.checked, version: item?.version };
        await api(item ? `/api/cost-centers/${encodeURIComponent(item.id)}` : "/api/cost-centers", { method: item ? "PUT" : "POST", body });
        await load();
        notify(message, "Kostenstelle gespeichert.", "success");
      },
    });
  }

  root.addEventListener("click", async (event) => {
    if (event.target.closest("[data-new]")) return editDialog();
    const edit = event.target.closest("[data-edit]");
    if (edit) return editDialog(data.items.find((item) => item.id === edit.dataset.edit));
    if (event.target.closest("[data-template]")) {
      const ok = await CalightModal.confirm({ title: "Vorlage übernehmen",
        content: "Typische Kostenstellen (K100 Verwaltung & Büro bis K190 Gewährleistung & Kulanz) werden angelegt. Bereits vorhandene Nummern bleiben unverändert; alles kann anschließend bearbeitet oder deaktiviert werden.",
        confirmLabel: "Übernehmen" });
      if (!ok) return;
      try {
        const result = await api("/api/cost-centers/template", { method: "POST", body: {} });
        await load();
        notify(message, result.created.length ? `${result.created.length} Kostenstelle(n) angelegt${result.skipped.length ? `, ${result.skipped.length} bereits vorhanden` : ""}.` : "Alle Kostenstellen der Vorlage sind bereits vorhanden.", "success");
      } catch (error) {
        notify(message, error.message);
      }
    }
  });
  yearSelect.addEventListener("change", () => load().catch((error) => notify(message, error.message)));
  showInactive.addEventListener("change", render);
  load().catch((error) => notify(message, error.message));
})();
