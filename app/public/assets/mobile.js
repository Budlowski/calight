/*
 * Mobiles Monteurs-Frontend: Verbindungshinweis, Bildschirmtastatur und Service-Worker-Registrierung.
 * Keine Fachlogik und keine lokale Datenhaltung (Phase 1: keine Offline-Erfassung).
 */
(function (global) {
  "use strict";

  /** Sichtbarkeit des Verbindungshinweises aus dem Online-Status ableiten. */
  function updateConnectionNotice(notice, online) {
    if (!notice) return;
    notice.hidden = online;
  }

  /** Tastatur gilt als geöffnet, wenn der sichtbare Bereich deutlich kleiner ist als das Fenster. */
  function keyboardOpen(viewportHeight, windowHeight) {
    return windowHeight - viewportHeight > 150;
  }

  /** Service Worker nur in sicherem Kontext (HTTPS oder localhost) und mit passendem Scope. */
  function registerServiceWorker(navigatorObject, secureContext) {
    if (!secureContext || !navigatorObject || !("serviceWorker" in navigatorObject)) return Promise.resolve(false);
    return navigatorObject.serviceWorker
      .register("/mobile/sw.js", { scope: "/mobile/" })
      .then(() => true)
      .catch(() => false);
  }

  function init(documentObject, windowObject) {
    const notice = documentObject.querySelector("[data-offline-notice]");
    const body = documentObject.body;
    updateConnectionNotice(notice, windowObject.navigator.onLine !== false);
    windowObject.addEventListener("online", () => updateConnectionNotice(notice, true));
    windowObject.addEventListener("offline", () => updateConnectionNotice(notice, false));

    // Ohne Verbindung keine Seitenwechsel ins Leere: Hinweis zeigen statt eines Browserfehlers.
    documentObject.addEventListener("click", (event) => {
      if (windowObject.navigator.onLine !== false) return;
      const link = event.target.closest ? event.target.closest("a[href]") : null;
      if (!link || link.target === "_blank" || !link.href.startsWith(windowObject.location.origin)) return;
      event.preventDefault();
      updateConnectionNotice(notice, false);
      notice?.scrollIntoView?.({ block: "nearest" });
    });

    const viewport = windowObject.visualViewport;
    if (viewport && body) {
      const update = () => body.classList.toggle("mobile-keyboard-open", keyboardOpen(viewport.height, windowObject.innerHeight));
      viewport.addEventListener("resize", update);
    }

    // Formularblatt geöffnet: Tastatur verkleinert den Inhalt, damit „Speichern“ sichtbar bleibt.
    // Sonst Standardverhalten, damit die untere Navigation beim Scrollen nicht springt.
    const meta = documentObject.querySelector('meta[name="viewport"]');
    if (meta && body && typeof windowObject.MutationObserver === "function") {
      const base = meta.getAttribute("content") || "";
      const sync = () => meta.setAttribute("content", viewportContent(base, body.classList.contains("mobile-sheet-open")));
      new windowObject.MutationObserver(sync).observe(body, { attributes: true, attributeFilter: ["class"] });
    }

    registerServiceWorker(windowObject.navigator, windowObject.isSecureContext === true);
  }

  /** Viewport-Angabe mit oder ohne interactive-widget=resizes-content. */
  function viewportContent(base, sheetOpen) {
    const parts = base.split(",").map((part) => part.trim()).filter((part) => part !== "" && !part.startsWith("interactive-widget"));
    if (sheetOpen) parts.push("interactive-widget=resizes-content");
    return parts.join(", ");
  }

  global.CalightMobile = { updateConnectionNotice, keyboardOpen, registerServiceWorker, viewportContent };
  if (typeof document !== "undefined" && typeof window !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => init(document, window));
    else init(document, window);
  }
})(globalThis);
