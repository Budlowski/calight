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

    registerServiceWorker(windowObject.navigator, windowObject.isSecureContext === true);
  }

  global.CalightMobile = { updateConnectionNotice, keyboardOpen, registerServiceWorker };
  if (typeof document !== "undefined" && typeof window !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", () => init(document, window));
    else init(document, window);
  }
})(globalThis);
