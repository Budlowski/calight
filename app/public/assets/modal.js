(() => {
  "use strict";

  const dialog = document.createElement("dialog");
  dialog.className = "global-modal";
  dialog.innerHTML = `<section class="global-modal-panel" role="document"><header><h2 data-modal-title></h2><button class="icon-button" type="button" data-modal-close aria-label="Schließen">×</button></header><div class="global-modal-content" data-modal-content></div><footer data-modal-actions></footer></section>`;
  document.body.append(dialog);

  const title = dialog.querySelector("[data-modal-title]"),
    content = dialog.querySelector("[data-modal-content]"),
    footer = dialog.querySelector("[data-modal-actions]");
  let resolveCurrent = null,
    previousFocus = null,
    outsideCloses = true,
    closeGuard = null,
    confirmingClose = false;

  async function requestClose(value = null) {
    if (closeGuard?.isDirty?.() && !confirmingClose) {
      confirmingClose = true;
      const savedActions = [...footer.children];
      const warning = document.createElement("p");
      warning.className = "notice notice-warning global-modal-discard-warning";
      warning.textContent = closeGuard.message || "Ungespeicherte Eingaben wirklich verwerfen?";
      content.prepend(warning);
      const continueButton = document.createElement("button");
      continueButton.type = "button";
      continueButton.className = "button button-light";
      continueButton.textContent = "Weiter bearbeiten";
      continueButton.onclick = () => {
        warning.remove();
        footer.replaceChildren(...savedActions);
        confirmingClose = false;
        focusable()[0]?.focus();
      };
      const discardButton = document.createElement("button");
      discardButton.type = "button";
      discardButton.className = "button button-danger-light";
      discardButton.textContent = "Verwerfen";
      discardButton.onclick = () => close(value);
      footer.replaceChildren(continueButton, discardButton);
      continueButton.focus();
      return;
    }
    close(value);
  }

  function close(value = null) {
    if (!dialog.open) return;
    dialog.close();
    const resolve = resolveCurrent;
    resolveCurrent = null;
    resolve?.(value);
    previousFocus?.focus?.();
    previousFocus = null;
    closeGuard = null;
    confirmingClose = false;
  }

  function focusable() {
    return [
      ...dialog.querySelectorAll(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
      ),
    ].filter((element) => !element.hidden);
  }

  function open({
    title: heading,
    content: body,
    actions = [{ label: "Schließen", value: null }],
    closeOnOutside = true,
    confirmClose = null,
    initialFocus = null,
    wide = false,
  }) {
    if (dialog.open) close(null);
    // Breitere Variante für Verwaltungsformulare mit zweispaltigem Raster.
    dialog.classList.toggle("is-wide", wide);
    previousFocus = document.activeElement;
    outsideCloses = closeOnOutside;
    closeGuard = confirmClose;
    title.textContent = heading;
    content.replaceChildren();
    if (body instanceof Node) content.append(body);
    else {
      const message = document.createElement("p");
      message.className = "global-modal-message";
      message.textContent = String(body ?? "");
      content.append(message);
    }
    footer.replaceChildren();

    const promise = new Promise((resolve) => {
      resolveCurrent = resolve;
    });
    for (const action of actions) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = action.primary ? "button" : "button button-light";
      if (action.danger) button.classList.add("button-danger-light");
      button.textContent = action.label;
      button.disabled = action.disabled === true;
      if (action.primary) button.dataset.modalPrimary = "";
      button.addEventListener("click", async () => {
        const form = content.querySelector("form");
        if (action.validate && form && !form.reportValidity()) return;
        if (action.onClick) {
          button.disabled = true;
          try {
            if (
              (await action.onClick({ dialog, content, form, close })) === false
            )
              return;
          } finally {
            if (dialog.open) button.disabled = false;
          }
        }
        requestClose(action.value);
      });
      footer.append(button);
    }

    dialog.showModal();
    requestAnimationFrame(() => {
      const target =
        (typeof initialFocus === "string"
          ? dialog.querySelector(initialFocus)
          : initialFocus) || focusable()[0];
      target?.focus();
    });
    return promise;
  }

  function confirm({
    title,
    content,
    confirmLabel = "Bestätigen",
    cancelLabel = "Abbrechen",
    danger = false,
  }) {
    return open({
      title,
      content,
      actions: [
        { label: cancelLabel, value: false },
        { label: confirmLabel, value: true, primary: !danger, danger },
      ],
      initialFocus: danger ? "[data-modal-close]" : "[data-modal-primary]",
    });
  }

  dialog
    .querySelector("[data-modal-close]")
    .addEventListener("click", () => requestClose(null));
  dialog.addEventListener("cancel", (event) => {
    event.preventDefault();
    requestClose(null);
  });
  dialog.addEventListener("click", (event) => {
    if (!outsideCloses || event.target !== dialog) return;
    const panel = dialog.querySelector(".global-modal-panel"),
      rect = panel.getBoundingClientRect();
    if (
      event.clientX < rect.left ||
      event.clientX > rect.right ||
      event.clientY < rect.top ||
      event.clientY > rect.bottom
    )
      requestClose(null);
  });
  dialog.addEventListener("submit", (event) => {
    if (!(event.target instanceof HTMLFormElement)) return;
    event.preventDefault();
    footer.querySelector("[data-modal-primary]")?.click();
  });
  dialog.addEventListener("keydown", (event) => {
    if (event.key !== "Tab") return;
    const items = focusable();
    if (!items.length) return;
    const first = items[0],
      last = items.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  /**
   * Kleiner Dialog über einem bereits offenen Dialog (z. B. „Lieferant anlegen“ aus dem
   * Bestelldialog). Eigenes <dialog>-Element im Top-Layer; der darunterliegende Dialog bleibt
   * mit allen Eingaben erhalten. Aufbau und Bedienung wie open(): Escape/× schließt,
   * Aktionen mit onClick bleiben bei Rückgabe false offen.
   */
  function openOver({ title: heading, content: body, actions = [{ label: "Schließen", value: null }], initialFocus = null }) {
    const layer = document.createElement("dialog");
    layer.className = "global-modal global-modal-stacked";
    layer.innerHTML = `<section class="global-modal-panel" role="document"><header><h2></h2><button class="icon-button" type="button" aria-label="Schließen">×</button></header><div class="global-modal-content"></div><footer></footer></section>`;
    layer.querySelector("h2").textContent = heading;
    layer.querySelector(".global-modal-content").append(body);
    const returnFocus = document.activeElement;
    document.body.append(layer);
    return new Promise((resolve) => {
      const finish = (value) => {
        if (!layer.open) return;
        layer.close();
        layer.remove();
        returnFocus?.focus?.();
        resolve(value);
      };
      layer.querySelector("header button").addEventListener("click", () => finish(null));
      layer.addEventListener("cancel", (event) => {
        event.preventDefault();
        event.stopPropagation();
        finish(null);
      });
      layer.addEventListener("submit", (event) => {
        event.preventDefault();
        event.stopPropagation();
        layer.querySelector("[data-modal-primary]")?.click();
      });
      const footer = layer.querySelector("footer");
      for (const action of actions) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = action.primary ? "button" : "button button-light";
        if (action.primary) button.dataset.modalPrimary = "";
        button.textContent = action.label;
        button.addEventListener("click", async () => {
          const form = layer.querySelector("form");
          if (action.validate && form && !form.reportValidity()) return;
          if (action.onClick) {
            button.disabled = true;
            try {
              if ((await action.onClick({ dialog: layer, form, close: finish })) === false) return;
            } finally {
              button.disabled = false;
            }
          }
          finish(action.value ?? null);
        });
        footer.append(button);
      }
      layer.showModal();
      requestAnimationFrame(() => (typeof initialFocus === "string" ? layer.querySelector(initialFocus) : initialFocus)?.focus());
    });
  }

  globalThis.CalightModal = Object.freeze({ open, confirm, close, openOver });
})();
