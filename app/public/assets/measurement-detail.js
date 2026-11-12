// Aufmaß bearbeiten: Mengen je verbindlicher Position erfassen, Fortschritt sehen,
// verbindlich abschließen oder ein Korrekturaufmaß anlegen.
(() => {
  "use strict";

  const root = document.querySelector("#measurement-detail");
  if (!root) return;

  const { esc, api, format, notify } = Calight;
  const message = root.querySelector("[data-message]");
  const headForm = root.querySelector("[data-head-form]");
  const sourcesBox = root.querySelector("[data-sources]");
  const search = root.querySelector("[data-position-search]");
  const filter = root.querySelector("[data-position-filter]");
  const saveBar = root.querySelector("[data-save-bar]");
  const saveButton = root.querySelector("[data-save]");
  const TYPE_LABELS = { bedarf: "Bedarf", alternative: "Alternative" };
  const STATUS_LABELS = { draft: "Entwurf", completed: "Abgeschlossen" };

  let measurement = null;
  let quantities = {};
  let dirty = false;
  let busy = false;

  // ---------------------------------------------------------------------------
  // Exakte Dezimalvorschau (keine Gleitkommazahlen). Maßgeblich bleibt die
  // serverseitige Berechnung beim Speichern; die Vorschau nutzt dieselbe Rundung.
  // ---------------------------------------------------------------------------
  const QUANTITY_PATTERN = /^-?\d+(?:[.,]\d{1,3})?$/;
  function toUnits(value, scale) {
    const text = String(value ?? "0").trim().replace(",", ".") || "0";
    const negative = text.startsWith("-");
    const [whole, fraction = ""] = text.replace(/^[-+]/, "").split(".");
    const units = BigInt(whole || "0") * 10n ** BigInt(scale) + BigInt((fraction + "0".repeat(scale)).slice(0, scale) || "0");
    return negative ? -units : units;
  }
  const fromUnits = (units, scale) => {
    const negative = units < 0n;
    const digits = (negative ? -units : units).toString().padStart(scale + 1, "0");
    return `${negative ? "-" : ""}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
  };
  // Menge (3 Stellen) × Preis (4 Stellen) → Cent, kaufmännisch gerundet.
  function lineCents(quantityUnits, price) {
    const product = quantityUnits * toUnits(price, 4);
    const divisor = 10n ** 5n;
    const half = divisor / 2n;
    return product >= 0n ? (product + half) / divisor : -((-product + half) / divisor);
  }
  const quantityText = (units) => format.number(Number(fromUnits(units, 3)), 3);
  const moneyText = (cents) => format.money(fromUnits(cents, 2));

  // ---------------------------------------------------------------------------
  // Darstellung
  // ---------------------------------------------------------------------------
  const editable = () => measurement?.status === "draft" && measurement.canEdit;

  function positionState(position) {
    const entered = quantities[position.key] ?? "";
    const valid = entered === "" || QUANTITY_PATTERN.test(entered.trim());
    const current = valid && entered !== "" ? toUnits(entered, 3) : 0n;
    const agreed = toUnits(position.agreedQuantity, 3);
    const total = toUnits(position.previousQuantity, 3) + current;
    return { entered, valid, current, agreed, total, open: agreed - total, overrun: total > agreed && current > 0n };
  }

  function matches(position) {
    const query = search.value.trim().toLocaleLowerCase("de-DE");
    if (query && !`${position.number} ${position.shortText}`.toLocaleLowerCase("de-DE").includes(query)) return false;
    const state = positionState(position);
    if (filter.value === "entered") return state.current !== 0n;
    if (filter.value === "open") return state.open > 0n;
    return true;
  }

  function positionRow(position) {
    const state = positionState(position);
    const typeBadge = TYPE_LABELS[position.effectiveType] ? `<span class="status-badge measurement-type-badge">${TYPE_LABELS[position.effectiveType]}</span>` : "";
    const input = editable()
      ? `<input class="measurement-quantity" inputmode="decimal" autocomplete="off" data-quantity="${esc(position.key)}" value="${esc(state.entered.replace(".", ","))}" aria-label="Menge dieses Aufmaßes für Position ${esc(position.number)}">`
      : `<span>${state.current !== 0n ? quantityText(state.current) : "–"}</span>`;
    return `
      <tr class="measurement-row${state.overrun ? " is-overrun" : ""}${state.current !== 0n ? " has-quantity" : ""}" data-row="${esc(position.key)}">
        <td class="m-number" data-label="Pos.">${esc(position.number)}</td>
        <td class="m-text" data-label="Leistung"><span class="m-short" title="${esc(position.shortText)}">${esc(position.shortText)}</span>${typeBadge}</td>
        <td class="m-num" data-label="EP">${format.money(position.unitPrice)}</td>
        <td class="m-num" data-label="Vereinbart">${quantityText(toUnits(position.agreedQuantity, 3))} ${esc(position.unit)}</td>
        <td class="m-num" data-label="Bisher">${quantityText(toUnits(position.previousQuantity, 3))}</td>
        <td class="m-input" data-label="Dieses Aufmaß">${input}</td>
        <td class="m-num" data-label="Gesamt" data-cell="total">${quantityText(state.total)}</td>
        <td class="m-num" data-label="Offen" data-cell="open">${openText(state, position)}</td>
        <td class="m-num" data-label="Betrag" data-cell="value">${moneyText(lineCents(state.current, position.unitPrice))}</td>
      </tr>`;
  }

  function openText(state, position) {
    if (state.open < 0n) {
      const confirmed = position.overrunConfirmed ? " (bestätigt)" : "";
      return `<span class="measurement-overrun" title="Gesamtmenge übersteigt die vereinbarte Menge">+${quantityText(-state.open)} über${confirmed}</span>`;
    }
    return quantityText(state.open);
  }

  // Titel erscheinen nur, wenn darunter passende Positionen stehen.
  function renderNodes(nodes, depth) {
    let html = "";
    for (const node of nodes) {
      if (node.kind === "title") {
        const children = renderNodes(node.children, depth + 1);
        if (!children) continue;
        const typeBadge = TYPE_LABELS[node.effectiveType] ? ` <span class="status-badge measurement-type-badge">${TYPE_LABELS[node.effectiveType]}</span>` : "";
        html += `<tr class="measurement-title-row depth-${Math.min(depth, 4)}"><th colspan="9" scope="rowgroup">${esc(node.number)} ${esc(node.name)}${typeBadge}</th></tr>${children}`;
      } else if (matches(node)) {
        html += positionRow(node);
      }
    }
    return html;
  }

  function renderSources() {
    const blocks = measurement.sources.map((source) => {
      const rows = renderNodes(source.nodes, 0);
      return `
        <section class="measurement-source">
          <h3>${esc(source.label)}</h3>
          ${rows ? `<div class="measurement-table-wrap"><table class="measurement-table">
            <thead><tr><th>Pos.</th><th>Leistung</th><th class="m-num">EP</th><th class="m-num">Vereinbart</th><th class="m-num">Bisher</th>
              <th class="m-input">Dieses Aufmaß</th><th class="m-num">Gesamt</th><th class="m-num">Offen</th><th class="m-num">Betrag</th></tr></thead>
            <tbody>${rows}</tbody></table></div>` : '<p class="empty-state">Keine Positionen entsprechen dem Filter.</p>'}
        </section>`;
    });
    if (measurement.orphanItems.length) {
      blocks.push(`<section class="measurement-source"><h3>Nicht mehr in der Auftragsgrundlage</h3><ul>${measurement.orphanItems
        .map((item) => `<li>${esc(item.number)} ${esc(item.shortText)}: ${quantityText(toUnits(item.quantity, 3))} ${esc(item.unit)} · ${format.money(item.lineTotal)}</li>`).join("")}</ul></section>`);
    }
    sourcesBox.innerHTML = blocks.join("") || '<p class="empty-state">Keine verbindlichen Positionen vorhanden.</p>';
    updateSummary();
  }

  function updateSummary() {
    const positions = allPositions();
    const entered = positions.filter((position) => positionState(position).current !== 0n).length;
    const overruns = positions.filter((position) => positionState(position).overrun).length;
    root.querySelector("[data-position-summary]").textContent = `${positions.length} Positionen · ${entered} mit Menge in diesem Aufmaß${overruns ? ` · ${overruns} über der vereinbarten Menge` : ""}`;
  }

  function allPositions(nodes = measurement.sources.flatMap((source) => source.nodes)) {
    return nodes.flatMap((node) => (node.kind === "title" ? allPositions(node.children) : [node]));
  }

  function renderTotals() {
    const rows = measurement.totals.map((total) => `
      <tr><th scope="row">${esc(total.label)}</th><td class="m-num">${format.money(total.itemsTotal)}</td>
        <td class="m-num">${Number(total.adjustmentAmount) !== 0 ? format.money(total.adjustmentAmount) : "–"}</td><td class="m-num"><strong>${format.money(total.netTotal)}</strong></td></tr>`).join("");
    const sum = measurement.totals.reduce((cents, total) => cents + toUnits(total.netTotal, 2), 0n);
    root.querySelector("[data-totals]").innerHTML = measurement.totals.length
      ? `<div class="measurement-table-wrap"><table class="measurement-totals"><thead><tr><th>Grundlage</th><th class="m-num">Positionen</th><th class="m-num">Nachlass/Aufschlag</th><th class="m-num">Netto</th></tr></thead>
          <tbody>${rows}</tbody><tfoot><tr><th scope="row">Gesamt netto</th><td></td><td></td><td class="m-num"><strong>${moneyText(sum)}</strong></td></tr></tfoot></table></div>
          ${dirty ? '<p class="muted">Summen werden beim Speichern aktualisiert.</p>' : ""}`
      : '<p class="empty-state">Noch keine Mengen erfasst.</p>';
    root.querySelector("[data-net-total]").textContent = measurement.totals.length ? moneyText(sum) : "–";
  }

  function renderHeader() {
    const number = measurement.measurementNumber || "Entwurf";
    root.querySelector("[data-number]").textContent = number;
    root.querySelector("[data-title]").textContent = measurement.title;
    root.querySelector("[data-current-crumb]").textContent = number;
    root.querySelector("[data-order-crumb]").innerHTML = `<a href="/orders/${encodeURIComponent(measurement.orderId)}">${esc(measurement.orderNumber)}</a>`;
    root.querySelector("[data-order]").innerHTML = `<a href="/orders/${encodeURIComponent(measurement.orderId)}">${esc(measurement.orderNumber)} · ${esc(measurement.orderTitle)}</a>`;
    root.querySelector("[data-customer]").textContent = measurement.customerName;
    document.title = `Aufmaß ${number} · ${measurement.orderNumber}`;
    const details = [
      measurement.correctedNumber ? `Korrektur zu ${esc(measurement.correctedNumber)}` : "",
      measurement.status === "completed" ? `abgeschlossen von ${esc(measurement.completedByName)} am ${format.dateTime(measurement.completedAt)}` : `erstellt von ${esc(measurement.creatorName)}`,
    ].filter(Boolean).join(" · ");
    root.querySelector("[data-status-line]").innerHTML = `<span class="status-badge status-measurement-${esc(measurement.status)}">${STATUS_LABELS[measurement.status]}</span><span>${details}</span>`;

    const actions = [];
    if (measurement.canComplete) actions.push('<button class="button" type="button" data-complete data-icon="clipboard-check">Abschließen</button>');
    if (measurement.canEdit) actions.push('<button class="button button-danger-light" type="button" data-delete data-icon="trash">Entwurf löschen</button>');
    if (measurement.canCorrect) actions.push('<button class="button button-light" type="button" data-correct data-icon="square-pen">Korrekturaufmaß anlegen</button>');
    root.querySelector("[data-actions]").innerHTML = actions.join("");

    for (const name of ["title", "measurementDate", "notes"]) {
      headForm.elements[name].value = measurement[name] ?? "";
      headForm.elements[name].disabled = !editable();
    }
    saveBar.hidden = !editable();
    root.querySelector("[data-hint]").textContent = editable()
      ? (measurement.correctionOfId
        ? "Korrekturaufmaß: Erfassen Sie nur die Differenz; negative Mengen verringern die bisher aufgemessene Menge."
        : "Erfassen Sie nur die in diesem Aufmaß zusätzlich ausgeführte Menge. Bisherige Mengen aus abgeschlossenen Aufmaßen werden automatisch berücksichtigt.")
      : "Abgeschlossene Aufmaße sind unveränderlich. Korrekturen werden als neues Korrekturaufmaß erfasst.";
  }

  function render() {
    renderHeader();
    renderSources();
    renderTotals();
  }

  function setDirty(value) {
    dirty = value;
    root.querySelector("[data-dirty-hint]").hidden = !dirty;
  }

  async function load() {
    measurement = await api(`/api/measurements/${encodeURIComponent(root.dataset.id)}`);
    quantities = {};
    for (const position of allPositions()) if (position.quantity !== "") quantities[position.key] = position.quantity;
    setDirty(false);
    render();
  }

  // ---------------------------------------------------------------------------
  // Bearbeitung
  // ---------------------------------------------------------------------------
  sourcesBox.addEventListener("input", (event) => {
    const input = event.target.closest("[data-quantity]");
    if (!input) return;
    quantities[input.dataset.quantity] = input.value.trim();
    setDirty(true);
    // Nur die berechneten Zellen der Zeile aktualisieren, damit der Fokus erhalten bleibt.
    const position = allPositions().find((item) => item.key === input.dataset.quantity);
    const row = input.closest("tr");
    const state = positionState(position);
    input.classList.toggle("is-invalid", !state.valid);
    input.setAttribute("aria-invalid", String(!state.valid));
    row.classList.toggle("is-overrun", state.overrun);
    row.classList.toggle("has-quantity", state.current !== 0n);
    row.querySelector('[data-cell="total"]').textContent = quantityText(state.total);
    row.querySelector('[data-cell="open"]').innerHTML = openText(state, position);
    row.querySelector('[data-cell="value"]').textContent = moneyText(lineCents(state.current, position.unitPrice));
    updateSummary();
    renderTotals();
  });

  // Enter springt zur nächsten Mengeneingabe (schnelle Erfassung, auch mobil).
  sourcesBox.addEventListener("keydown", (event) => {
    if (event.key !== "Enter" || !event.target.matches("[data-quantity]")) return;
    event.preventDefault();
    const inputs = [...sourcesBox.querySelectorAll("[data-quantity]")];
    inputs[inputs.indexOf(event.target) + 1]?.focus();
  });

  headForm.addEventListener("input", () => setDirty(true));
  search.addEventListener("input", renderSources);
  filter.addEventListener("change", renderSources);

  function payload() {
    const items = Object.entries(quantities)
      .filter(([, value]) => value !== "")
      .map(([key, value]) => ({ key, quantity: value.replace(",", ".") }));
    return {
      version: measurement.version,
      title: headForm.elements.title.value.trim(),
      measurementDate: headForm.elements.measurementDate.value,
      notes: headForm.elements.notes.value.trim(),
      items,
    };
  }

  async function save({ silent = false } = {}) {
    if (!headForm.reportValidity()) return false;
    const invalid = allPositions().find((position) => !positionState(position).valid);
    if (invalid) {
      notify(message, `Position ${invalid.number}: Bitte eine Zahl mit höchstens drei Nachkommastellen eingeben.`);
      sourcesBox.querySelector(`[data-quantity="${CSS.escape(invalid.key)}"]`)?.focus();
      return false;
    }
    measurement = await api(`/api/measurements/${encodeURIComponent(measurement.id)}`, { method: "PUT", body: payload() });
    setDirty(false);
    render();
    if (!silent) notify(message, "Aufmaß wurde gespeichert.", "success");
    return true;
  }

  async function run(button, operation) {
    if (busy) return;
    busy = true;
    if (button) button.disabled = true;
    try {
      await operation();
    } catch (exception) {
      notify(message, exception.message);
    } finally {
      busy = false;
      if (button?.isConnected) button.disabled = false;
    }
  }

  async function complete(confirmOverrun = false) {
    const response = await fetch(`/api/measurements/${encodeURIComponent(measurement.id)}/complete`, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": root.dataset.csrf },
      body: JSON.stringify({ version: measurement.version, confirmOverrun }),
    });
    const result = await response.json().catch(() => ({}));
    if (response.status === 409 && result.data?.overruns) return result.data;
    if (!response.ok) throw new Error((result.details || [result.error || "Abschluss fehlgeschlagen."]).join(" "));
    return result.data;
  }

  function overrunContent(overruns) {
    const box = document.createElement("div");
    box.className = "form-stack";
    box.innerHTML = `<p>Bei folgenden Positionen übersteigt die insgesamt aufgemessene Menge die vereinbarte Menge. Bitte prüfen Sie, ob es sich um eine Mengenabweichung der vereinbarten Leistung handelt. Zusätzliche Leistungen gehören in einen Nachtrag.</p>
      <ul class="measurement-overrun-list">${overruns.map((item) => `<li><strong>${esc(item.number)} ${esc(item.shortText)}</strong><br>
        vereinbart ${quantityText(toUnits(item.agreed, 3))} ${esc(item.unit)} · gesamt ${quantityText(toUnits(item.total, 3))} ${esc(item.unit)} · <span class="measurement-overrun">+${quantityText(toUnits(item.excess, 3))} ${esc(item.unit)}</span></li>`).join("")}</ul>`;
    return box;
  }

  root.querySelector("[data-actions]").addEventListener("click", (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    if (button.matches("[data-complete]")) {
      run(button, async () => {
        if (dirty && !(await save({ silent: true }))) return;
        const confirmed = await CalightModal.confirm({
          title: "Aufmaß abschließen",
          content: "Nach dem Abschluss erhält das Aufmaß eine Nummer und kann nicht mehr geändert werden. Korrekturen sind nur über ein Korrekturaufmaß möglich.",
          confirmLabel: "Abschließen",
        });
        if (!confirmed) return;
        let result = await complete();
        if (!result.completed) {
          const accept = await CalightModal.open({
            title: "Mengenüberschreitung bestätigen",
            content: overrunContent(result.overruns),
            actions: [{ label: "Abbrechen", value: false }, { label: "Überschreitung bestätigen und abschließen", value: true, primary: true }],
          });
          if (!accept) {
            await load();
            return;
          }
          result = await complete(true);
        }
        measurement = result.measurement;
        render();
        notify(message, `Aufmaß ${measurement.measurementNumber} wurde abgeschlossen.`, "success");
      });
    } else if (button.matches("[data-delete]")) {
      run(button, async () => {
        const confirmed = await CalightModal.confirm({ title: "Entwurf löschen", content: "Der Aufmaßentwurf und seine Mengen werden endgültig gelöscht.", confirmLabel: "Löschen", danger: true });
        if (!confirmed) return;
        await api(`/api/measurements/${encodeURIComponent(measurement.id)}`, { method: "DELETE", body: { version: measurement.version } });
        setDirty(false);
        location.assign(`/orders/${encodeURIComponent(measurement.orderId)}`);
      });
    } else if (button.matches("[data-correct]")) {
      run(button, async () => {
        const created = await api("/api/measurements", { method: "POST", body: { correctionOf: measurement.id } });
        location.assign(`/measurements/${encodeURIComponent(created.id)}`);
      });
    }
  });

  saveButton.addEventListener("click", () => run(saveButton, () => save()));

  window.addEventListener("beforeunload", (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = "";
  });

  load().catch((exception) => notify(message, exception.message));
})();
