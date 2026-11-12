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
  function createDialog({ title, form, submitLabel, discardMessage, initialFocus, submit, extraActions = [] }) {
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
      actions: [
        { label: "Abbrechen", value: null },
        ...extraActions.map((action) => ({ ...action, onClick: () => action.onClick(run) })),
        { label: submitLabel, primary: true, validate: true, onClick: () => run(submit) },
      ],
    });
    return { showError: (text) => { error.textContent = text; error.hidden = false; } };
  }

  globalThis.Calight = Object.freeze({ esc, api, format, exact, collator, normalize, matches, notify, bindProjectToCustomer, createDialog });
})();
