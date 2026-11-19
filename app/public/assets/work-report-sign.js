// Kundenansicht: Leistungsnachweis mit Finger, Stift oder Maus unterschreiben.
// Die Unterschrift wird als JPEG (weißer Hintergrund) gespeichert und serverseitig
// an die Prüfsumme des angezeigten, eingereichten Leistungsstands gebunden.
(() => {
  "use strict";

  const root = document.querySelector("#work-report-sign");
  if (!root) return;

  const form = root.querySelector("[data-sign-form]");
  const canvas = root.querySelector("[data-signature-canvas]");
  const hint = root.querySelector("[data-signature-hint]");
  const error = root.querySelector("[data-error]");
  const submit = root.querySelector("[data-submit]");
  const context = canvas.getContext("2d");
  let strokes = [];
  let current = null;
  let saving = false;

  // Zeichenfläche an Anzeigegröße und Pixeldichte anpassen; vorhandene Striche neu zeichnen.
  function resize() {
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.round(rect.width * ratio);
    canvas.height = Math.round(rect.height * ratio);
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    redraw(context, rect.width, rect.height);
  }

  function redraw(target, width, height, scale = 1) {
    target.lineCap = "round";
    target.lineJoin = "round";
    target.strokeStyle = "#10233a";
    target.lineWidth = 2.4 * scale;
    for (const stroke of strokes) {
      target.beginPath();
      stroke.forEach((point, index) => {
        const x = point.x * width * scale;
        const y = point.y * height * scale;
        if (index === 0) target.moveTo(x, y);
        else target.lineTo(x, y);
      });
      if (stroke.length === 1) target.lineTo(stroke[0].x * width * scale + .1, stroke[0].y * height * scale);
      target.stroke();
    }
  }

  // Punkte werden relativ (0–1) gespeichert, damit Größenänderungen die Unterschrift nicht verzerren.
  function point(event) {
    const rect = canvas.getBoundingClientRect();
    return { x: (event.clientX - rect.left) / rect.width, y: (event.clientY - rect.top) / rect.height };
  }

  canvas.addEventListener("pointerdown", (event) => {
    event.preventDefault();
    canvas.setPointerCapture(event.pointerId);
    current = [point(event)];
    strokes.push(current);
    hint.hidden = true;
    resize();
  });
  canvas.addEventListener("pointermove", (event) => {
    if (!current) return;
    event.preventDefault();
    current.push(point(event));
    const rect = canvas.getBoundingClientRect();
    context.clearRect(0, 0, rect.width, rect.height);
    redraw(context, rect.width, rect.height);
  });
  const end = () => { current = null; };
  canvas.addEventListener("pointerup", end);
  canvas.addEventListener("pointercancel", end);

  root.querySelector("[data-clear]").addEventListener("click", () => {
    strokes = [];
    hint.hidden = false;
    resize();
  });

  const inkPoints = () => strokes.reduce((sum, stroke) => sum + stroke.length, 0);

  // Export in fester Größe auf weißem Grund (JPEG unterstützt keine Transparenz).
  function exportJpeg() {
    const rect = canvas.getBoundingClientRect();
    const width = 1000;
    const height = Math.round(width * rect.height / rect.width);
    const output = document.createElement("canvas");
    output.width = width;
    output.height = height;
    const target = output.getContext("2d");
    target.fillStyle = "#ffffff";
    target.fillRect(0, 0, width, height);
    redraw(target, width, height, width / rect.width);
    return output.toDataURL("image/jpeg", 0.85);
  }

  function showError(text) {
    error.textContent = text;
    error.hidden = false;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (saving) return;
    error.hidden = true;
    if (!form.reportValidity()) return;
    if (inkPoints() < 8) {
      showError("Bitte im Feld unterschreiben.");
      return;
    }
    saving = true;
    submit.disabled = true;
    try {
      await Calight.api(`/api/work-reports/${encodeURIComponent(root.dataset.id)}/signature`, {
        method: "POST",
        body: {
          version: Number(root.dataset.version),
          signerName: form.elements.signerName.value.trim(),
          submittedSha256: root.dataset.hash,
          image: exportJpeg(),
        },
      });
      location.assign(`/work-reports/${encodeURIComponent(root.dataset.id)}?signed=1`);
    } catch (exception) {
      showError(exception.message);
      saving = false;
      submit.disabled = false;
    }
  });

  window.addEventListener("resize", resize);
  resize();
})();
