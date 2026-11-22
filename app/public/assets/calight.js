// Gemeinsame Helfer der Übersichts- und Detailseiten.
// Bewusst klein gehalten: Escaping, API-Aufrufe, Formatierung und Hinweise.
(() => {
  "use strict";

  const csrfToken = () => document.querySelector("[data-csrf]")?.dataset.csrf ?? "";

  const ESCAPES = { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" };
  const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (character) => ESCAPES[character]);

  // Liefert `data` (bzw. das ganze Ergebnis) oder wirft einen Fehler mit der
  // Servermeldung. Validierungsdetails haben Vorrang vor der allgemeinen Meldung.
  async function api(path, { method = "GET", body, signal } = {}) {
    const headers = { Accept: "application/json" };
    const options = { method, credentials: "same-origin", headers, signal };
    if (method !== "GET") headers["X-CSRF-Token"] = csrfToken();
    if (body !== undefined) {
      headers["Content-Type"] = "application/json";
      options.body = JSON.stringify(body);
    }
    const response = await fetch(path, options);
    let result = {};
    try {
      result = await response.json();
    } catch {
      if (!response.ok) throw new Error(`Anfrage fehlgeschlagen (HTTP ${response.status}).`);
    }
    if (!response.ok) {
      const details = Array.isArray(result.details) ? result.details.filter(Boolean) : [];
      throw new Error(details.join(" ") || result.error || "Anfrage fehlgeschlagen.");
    }
    return result.data ?? result;
  }

  const numberFormats = new Map();
  const decimal = (value, digits) => {
    if (!numberFormats.has(digits)) {
      numberFormats.set(digits, new Intl.NumberFormat("de-DE", { minimumFractionDigits: digits, maximumFractionDigits: digits }));
    }
    return numberFormats.get(digits).format(Number(value || 0));
  };
  const moneyFormat = new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" });
  const dateFormat = new Intl.DateTimeFormat("de-DE", { dateStyle: "medium" });
  const dateTimeFormat = new Intl.DateTimeFormat("de-DE", { dateStyle: "medium", timeStyle: "short" });
  // Reine Kalenderdaten (YYYY-MM-DD) lokal interpretieren, sonst verschiebt die Zeitzone den Tag.
  const toDate = (value) => new Date(/^\d{4}-\d{2}-\d{2}$/.test(value) ? `${value}T00:00:00` : value);

  const format = Object.freeze({
    number: decimal,
    hours: (value) => `${decimal(value, 3)} h`,
    money: (value) => moneyFormat.format(Number(value || 0)),
    date: (value) => (value ? dateFormat.format(toDate(value)) : "–"),
    dateTime: (value) => (value ? dateTimeFormat.format(toDate(value)) : "–"),
  });

  // Exakte Dezimalvorschau ohne Gleitkommazahlen (BigInt in festen Nachkommastellen).
  // Maßgeblich bleibt die Serverberechnung; die Vorschau rundet identisch (HalfUp auf Cent).
  const DECIMAL_PATTERN = /^-?\d+(?:[.,]\d+)?$/;
  function toUnits(value, scale) {
    const text = String(value ?? "0").trim().replace(",", ".") || "0";
    const negative = text.startsWith("-");
    const [whole, fraction = ""] = text.replace(/^[-+]/, "").split(".");
    const units = BigInt(whole || "0") * 10n ** BigInt(scale) + BigInt((fraction + "0".repeat(scale)).slice(0, scale) || "0");
    return negative ? -units : units;
  }
  function fromUnits(units, scale) {
    const negative = units < 0n;
    const digits = (negative ? -units : units).toString().padStart(scale + 1, "0");
    return `${negative ? "-" : ""}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
  }
  /** Menge (3 Stellen) × Preis (bis 4 Stellen) → Cent als BigInt, kaufmännisch gerundet. */
  function lineCents(quantityUnits, price) {
    const product = quantityUnits * toUnits(price, 4);
    const divisor = 10n ** 5n;
    const half = divisor / 2n;
    return product >= 0n ? (product + half) / divisor : -((-product + half) / divisor);
  }
  const exact = Object.freeze({ DECIMAL_PATTERN, toUnits, fromUnits, lineCents });

  const collator = new Intl.Collator("de-DE", { numeric: true, sensitivity: "base" });
  const normalize = (value) => String(value ?? "").toLocaleLowerCase("de-DE");
  const matches = (query, ...fields) => !query || normalize(fields.filter(Boolean).join(" ")).includes(query);

  function notify(element, text, type = "error") {
    if (!element) return;
    element.textContent = text;
    element.className = `notice notice-${type}`;
    element.hidden = false;
  }

  // Projektauswahl auf die Projekte des gewählten Kunden einschränken.
  function bindProjectToCustomer(form) {
    const customer = form.elements.customerId;
    const project = form.elements.projectId;
    if (!customer || !project) return;
    const sync = () => {
      for (const option of project.options) {
        if (option.value) option.hidden = option.dataset.customerId !== customer.value;
      }
      if (project.selectedOptions[0]?.hidden) project.value = "";
    };
    customer.addEventListener("change", sync);
    sync();
  }

  // Standardablauf für Neuanlage-Dialoge: Validierung, Doppelklickschutz,
  // Fehlermeldung im Dialog und Warnung bei ungespeicherten Eingaben.
  function createDialog({ title, form, submitLabel, discardMessage, initialFocus, submit, extraActions = [], wide = false }) {
    const error = form.querySelector("[data-dialog-error]");
    let dirty = false;
    let saving = false;
    form.addEventListener("input", () => (dirty = true));
    form.addEventListener("change", () => (dirty = true));

    const run = async (operation) => {
      if (saving) return false;
      saving = true;
      if (error) error.hidden = true;
      try {
        await operation();
        dirty = false;
        return true;
      } catch (exception) {
        if (error) {
          error.textContent = exception.message;
          error.hidden = false;
        }
        return false;
      } finally {
        saving = false;
      }
    };

    CalightModal.open({
      title,
      content: form,
      confirmClose: { isDirty: () => dirty, message: discardMessage },
      initialFocus,
      wide,
      actions: [
        { label: "Abbrechen", value: null },
        ...extraActions.map((action) => ({ ...action, onClick: () => action.onClick(run) })),
        { label: submitLabel, primary: true, validate: true, onClick: () => run(submit) },
      ],
    });
    return { showError: (text) => { error.textContent = text; error.hidden = false; } };
  }


  // Gemeinsame Stammdatensuche mit Vorschlagsliste (z. B. Artikel/Lohnkostengruppen).
  // Die Daten liefert immer der Server (Berechtigungen bleiben dort); die Komponente
  // regelt nur Darstellung und Bedienung: Pfeiltasten, Enter, Escape, Leeren-Knopf.
  let suggestCount = 0;

  /**
   * Lage einer am Bildschirm ausgerichteten Vorschlagsliste: unterhalb des Feldes, bei zu wenig
   * Platz (und mehr Platz oberhalb) nach oben; Höhe auf den verfügbaren Platz begrenzt.
   *
   * @param {{top:number,bottom:number,left:number,width:number}} rect Feld (getBoundingClientRect)
   * @param {number} contentHeight benötigte Höhe der Liste
   * @param {number} viewportHeight
   */
  function suggestPlacement(rect, contentHeight, viewportHeight, gap = 2, margin = 8, limit = 300) {
    const below = viewportHeight - rect.bottom - gap - margin;
    const above = rect.top - gap - margin;
    const wanted = Math.min(contentHeight, limit);
    const up = below < wanted && above > below;
    const maxHeight = Math.max(80, Math.floor(Math.min(limit, up ? above : below)));
    return {
      left: Math.round(rect.left), width: Math.round(rect.width), up, maxHeight,
      top: up ? null : Math.round(rect.bottom + gap),
      bottom: up ? Math.round(viewportHeight - rect.top + gap) : null,
    };
  }
  function suggest({ input, list, clear = null, load, render, choose, minLength = 2, empty = "Keine Treffer.", onError = null }) {
    const id = `suggest-${++suggestCount}`;
    let items = [];
    let active = -1;
    let timer = null;
    let request = 0;
    list.id ||= id;
    list.classList.add("suggest-list");
    list.setAttribute("role", "listbox");
    input.setAttribute("role", "combobox");
    input.setAttribute("aria-autocomplete", "list");
    input.setAttribute("aria-controls", list.id);
    input.setAttribute("aria-expanded", "false");

    const options = () => [...list.querySelectorAll("[data-suggest-index]")];
    // In Dialogen und scrollenden Containern würde die Liste abgeschnitten oder vom Dialogfuß
    // überdeckt: dann am Bildschirm ausrichten (position: fixed, ohne extreme z-index-Werte)
    // und bei zu wenig Platz unterhalb nach oben aufklappen. Beim Scrollen mitführen.
    const clipped = () => Boolean(input.closest("dialog, .global-modal-content, .overview-table-wrap, .table-wrap"));
    const place = () => {
      if (list.hidden || !list.classList.contains("suggest-list-fixed")) return;
      const geometry = suggestPlacement(input.getBoundingClientRect(), list.scrollHeight, innerHeight);
      list.style.left = `${geometry.left}px`;
      list.style.width = `${geometry.width}px`;
      list.style.top = geometry.top === null ? "" : `${geometry.top}px`;
      list.style.bottom = geometry.bottom === null ? "" : `${geometry.bottom}px`;
      list.style.maxHeight = `${geometry.maxHeight}px`;
      list.classList.toggle("suggest-list-up", geometry.up);
    };
    const open = (value) => {
      list.hidden = !value;
      input.setAttribute("aria-expanded", String(value));
      if (value) {
        list.classList.toggle("suggest-list-fixed", clipped());
        place();
        addEventListener("scroll", place, true);
        addEventListener("resize", place);
      } else {
        removeEventListener("scroll", place, true);
        removeEventListener("resize", place);
        active = -1;
        input.removeAttribute("aria-activedescendant");
      }
    };
    const highlight = (index) => {
      const all = options();
      if (!all.length) return;
      active = (index + all.length) % all.length;
      all.forEach((option, position) => option.setAttribute("aria-selected", String(position === active)));
      input.setAttribute("aria-activedescendant", all[active].id);
      all[active].scrollIntoView({ block: "nearest" });
    };
    const reset = () => {
      clearTimeout(timer);
      request++;
      input.value = "";
      if (clear) clear.hidden = true;
      open(false);
    };
    const pick = (index) => {
      const item = items[index];
      if (!item) return;
      reset();
      choose(item);
    };

    input.addEventListener("input", () => {
      if (clear) clear.hidden = input.value === "";
      clearTimeout(timer);
      const query = input.value.trim();
      if (query.length < minLength) {
        open(false);
        return;
      }
      timer = setTimeout(async () => {
        const current = ++request;
        try {
          const result = await load(query);
          if (current !== request) return; // veraltete Antwort verwerfen
          items = result;
          list.innerHTML = items.length
            ? items.map((item, index) => `<button type="button" role="option" id="${list.id}-${index}" aria-selected="false" tabindex="-1" data-suggest-index="${index}">${render(item)}</button>`).join("")
            : `<p class="muted">${esc(empty)}</p>`;
          open(true);
          active = -1;
        } catch (exception) {
          if (onError) onError(exception);
        }
      }, 250);
    });
    input.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        if (list.hidden) return;
        event.preventDefault();
        highlight(active + (event.key === "ArrowDown" ? 1 : -1));
      } else if (event.key === "Enter" && !list.hidden && active >= 0) {
        event.preventDefault();
        pick(active);
      } else if (event.key === "Escape" && !list.hidden) {
        event.stopPropagation();
        open(false);
      }
    });
    list.addEventListener("mousedown", (event) => event.preventDefault()); // Fokus bleibt im Suchfeld
    list.addEventListener("click", (event) => {
      const option = event.target.closest("[data-suggest-index]");
      if (option) pick(Number(option.dataset.suggestIndex));
    });
    input.addEventListener("blur", () => setTimeout(() => open(false), 150));
    clear?.addEventListener("click", () => {
      reset();
      input.focus();
    });
    return { reset };
  }

  // Auswahlfeld mit Suche: erweitert ein vorhandenes <select> um eine Eingabe mit
  // Vorschlägen (Calight.suggest). Durchsucht werden nur die bereits vom Server
  // gelieferten, berechtigungsgeprüften Optionen; der Wert bleibt im <select>
  // (Formulardaten, change-Ereignisse und bestehende Logik unverändert).
  function searchableSelect(select, { placeholder = "Nummer oder Name eingeben …" } = {}) {
    if (select.dataset.searchable) return;
    select.dataset.searchable = "1";
    const box = document.createElement("div");
    box.className = "suggest-select";
    box.innerHTML = `<input type="search" autocomplete="off" placeholder="${esc(placeholder)}"><button class="suggest-clear" type="button" aria-label="Auswahl leeren" title="Auswahl leeren" hidden>×</button><div class="suggest-results" hidden></div>`;
    const input = box.querySelector("input");
    const clear = box.querySelector("button");
    const required = select.required;
    select.required = false;
    input.required = required;
    select.classList.add("visually-hidden");
    select.tabIndex = -1;
    select.setAttribute("aria-hidden", "true");
    select.after(box);
    const label = select.closest("label");
    if (label) {
      // Die Beschriftung gilt jetzt für das Suchfeld (nicht für das ausgeblendete <select>).
      input.id = `searchable-${++suggestCount}`;
      label.htmlFor = input.id;
    }
    const options = () => [...select.options].filter((option) => option.value !== "");
    const show = () => {
      const selected = select.selectedOptions[0];
      input.value = selected && selected.value ? selected.textContent : "";
      clear.hidden = input.value === "";
      input.setCustomValidity("");
    };
    const validate = () => input.setCustomValidity(input.value !== "" && !select.value ? "Bitte einen Eintrag aus der Liste wählen." : "");
    suggest({
      input,
      list: box.querySelector(".suggest-results"),
      minLength: 1,
      load: async (query) => {
        const needle = normalize(query);
        return options().filter((option) => normalize(option.textContent).includes(needle)).slice(0, 50);
      },
      render: (option) => `<span>${esc(option.textContent)}</span>`,
      choose: (option) => {
        select.value = option.value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
        show();
      },
    });
    input.addEventListener("input", () => {
      clear.hidden = input.value === "";
      if (select.value && input.value !== select.selectedOptions[0]?.textContent) {
        select.value = "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
      validate();
    });
    clear.addEventListener("click", () => {
      if (select.value) {
        select.value = "";
        select.dispatchEvent(new Event("change", { bubbles: true }));
      }
      show();
    });
    show();
    return { refresh: show };
  }

  // Gemeinsames Kostenziel „Auftrag / Kostenstelle“: ein Suchfeld für Kundenaufträge und
  // allgemeine Kostenstellen (vorhandene searchableSelect-Komponente). Der Wert trägt Typ und ID
  // („order:<id>“ bzw. „cost_center:<id>“) – nie wird anhand des Nummernpräfixes entschieden.
  const costTargetValue = (target) => `${target.type}:${target.id}`;
  /** Auswahl → { orderId, costCenterId } (höchstens eines gesetzt). */
  function parseCostTarget(value) {
    const [type, ...rest] = String(value || "").split(":");
    const id = rest.join(":");
    return { orderId: type === "order" ? id : "", costCenterId: type === "cost_center" ? id : "" };
  }
  /** Optionen: Aufträge und aktive Kostenstellen; deaktivierte nur, wenn bereits zugeordnet. */
  function costTargetOptions(targets, { orderId = "", costCenterId = "", emptyLabel = "Ohne Zuordnung" } = {}) {
    const selected = orderId ? `order:${orderId}` : costCenterId ? `cost_center:${costCenterId}` : "";
    const visible = (targets || []).filter((target) => target.active || costTargetValue(target) === selected);
    const options = visible.map((target) => {
      const value = costTargetValue(target);
      const suffix = target.type === "cost_center" ? (target.active ? " (Kostenstelle)" : " (Kostenstelle, deaktiviert)") : "";
      return `<option value="${esc(value)}"${value === selected ? " selected" : ""}>${esc(target.number)} · ${esc(target.label)}${suffix}</option>`;
    });
    // Bisherige Zuordnung, die nicht (mehr) in der Liste ist (z. B. abgerechneter Auftrag), bleibt erhalten.
    if (selected && !visible.some((target) => costTargetValue(target) === selected)) options.push(`<option value="${esc(selected)}" selected>bisherige Zuordnung</option>`);
    return `<option value="">${esc(emptyLabel)}</option>${options.join("")}`;
  }
  /** Füllt ein <select> und macht es durchsuchbar (Nummer und Bezeichnung). */
  function costTargetSelect(select, targets, options = {}) {
    select.innerHTML = costTargetOptions(targets, options);
    searchableSelect(select, { placeholder: "Auftrag oder Kostenstelle: Nummer oder Bezeichnung …" });
    return { value: () => parseCostTarget(select.value) };
  }

  // Seite eines deaktivierten Moduls (Layout setzt data-module-readonly): nur lesen.
  // readOnly(obj, keep) setzt alle Aktionskennzeichen can* auf false (außer keep) – der Server sperrt ohnehin.
  const moduleReadOnly = typeof document !== "undefined" && document.body?.dataset.moduleReadonly === "1";
  function readOnly(value, keep = []) {
    if (!moduleReadOnly || !value || typeof value !== "object") return value;
    const copy = { ...value };
    Object.keys(copy).forEach((key) => { if (/^can[A-Z]/.test(key) && !keep.includes(key) && typeof copy[key] === "boolean") copy[key] = false; });
    return copy;
  }

  // Oberfläche der aktuellen Seite (Büro oder mobiles Frontend, siehe layout/mobile.php). Geteilte
  // Editoren erzeugen ihre Links über paths, damit sie in beiden Oberflächen bleiben.
  const ui = typeof document !== "undefined" && document.body?.dataset.ui === "mobile" ? "mobile" : "office";
  const segment = (id) => encodeURIComponent(String(id));
  const paths = Object.freeze(ui === "mobile"
    ? {
        dailyReports: () => "/mobile/daily-reports",
        dailyReport: (id) => `/mobile/daily-reports/${segment(id)}`,
        workReports: () => "/mobile/rapports",
        workReport: (id) => `/mobile/rapports/${segment(id)}`,
        workReportSign: (id) => `/mobile/rapports/${segment(id)}/sign`,
        order: (id) => `/mobile/orders/${segment(id)}`,
        measurements: () => "/mobile/measurements",
        measurement: (id) => `/mobile/measurements/${segment(id)}`,
        materialRequests: () => "/mobile/material-requests",
      }
    : {
        dailyReports: () => "/daily-reports",
        dailyReport: (id) => `/daily-reports/${segment(id)}`,
        workReports: () => "/work-reports",
        workReport: (id) => `/work-reports/${segment(id)}`,
        workReportSign: (id) => `/work-reports/${segment(id)}/sign`,
        order: (id) => `/orders/${segment(id)}`,
        measurements: () => "/measurements",
        measurement: (id) => `/measurements/${segment(id)}`,
        materialRequests: () => "/material-requests",
      });

  /**
   * Einsatzort (Freitext) mit Schalter „Auswärtstätigkeit“ für Zeitbuchungen. Der Schalter setzt die
   * bestehende Einsatzart (assignmentKind): an = external, aus = regular (Erste Tätigkeitsstätte),
   * sofern ein Ort angegeben ist oder die Buchung schon so eingeordnet war – sonst „keine Angabe“.
   */
  function workLocationFields(values = {}, { labelClass = "field-label", fieldClass = "", optional = true } = {}) {
    return `<div class="work-location-field">
      <label${fieldClass ? ` class="${fieldClass}"` : ""}><span${labelClass ? ` class="${labelClass}"` : ""}>Einsatzort${optional ? " (optional)" : ""}</span><input name="workLocation" maxlength="200" autocomplete="off" value="${esc(values.workLocation || "")}" placeholder="z. B. Baustelle, Ort"></label>
      <label class="work-location-switch"><input type="checkbox" role="switch" name="external"${values.assignmentKind === "external" ? " checked" : ""}><span>Auswärtstätigkeit</span></label>
      <input type="hidden" name="previousAssignment" value="${esc(values.assignmentKind || "")}">
    </div>`;
  }
  function readWorkLocation(form) {
    const workLocation = String(form.elements.workLocation?.value ?? "").trim();
    const external = Boolean(form.elements.external?.checked);
    const previous = form.elements.previousAssignment?.value || "";
    const assignmentKind = external ? "external" : (workLocation !== "" || previous === "regular" ? "regular" : "");
    return { workLocation, assignmentKind };
  }

  globalThis.Calight = Object.freeze({ ui, paths, moduleReadOnly, readOnly, esc, api, format, exact, collator, normalize, matches, notify, bindProjectToCustomer, createDialog, suggest, searchableSelect, suggestPlacement,
    costTargetOptions, costTargetSelect, parseCostTarget, workLocationFields, readWorkLocation });
})();
