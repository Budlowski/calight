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
  // Bei gehaltenem Zeiger außerhalb des Feldes wird auf den Rand begrenzt.
  const clamp = (value) => Math.min(1, Math.max(0, value));
  function point(event) {
    const rect = canvas.getBoundingClientRect();
    return { x: clamp((event.clientX - rect.left) / rect.width), y: clamp((event.clientY - rect.top) / rect.height) };
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

  // Export auf weißem Grund (JPEG unterstützt keine Transparenz), zugeschnitten auf die
  // Unterschrift mit Rand: so wird sie im PDF unabhängig von Feldgröße und Schreibposition
  // proportional und ohne Abschneiden eingepasst.
  function exportJpeg() {
    const rect = canvas.getBoundingClientRect();
    const scale = 1000 / rect.width;
    const xs = strokes.flat().map((p) => p.x * rect.width);
    const ys = strokes.flat().map((p) => p.y * rect.height);
    const margin = 18;
    let left = Math.min(...xs) - margin;
    let right = Math.max(...xs) + margin;
    let top = Math.min(...ys) - margin;
    let bottom = Math.max(...ys) + margin;
    // Mindestgröße, damit auch kurze Kürzel lesbar bleiben (Server verlangt mind. 100 × 40 px).
    const grow = (low, high, min) => {
      const missing = Math.max(0, min - (high - low)) / 2;
      return [low - missing, high + missing];
    };
    [left, right] = grow(left, right, 240);
    [top, bottom] = grow(top, bottom, 96);
    const output = document.createElement("canvas");
    output.width = Math.round((right - left) * scale);
    output.height = Math.round((bottom - top) * scale);
    const target = output.getContext("2d");
    target.fillStyle = "#ffffff";
    target.fillRect(0, 0, output.width, output.height);
    target.translate(-left * scale, -top * scale);
    redraw(target, rect.width, rect.height, scale);
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
