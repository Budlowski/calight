/*
 * Mobile Übersichten für Tagesberichte und Rapporte: verbindet „Neu“ und den Einstieg aus einem
 * Auftrag (?create_for=<auftrag>) mit den gemeinsamen Anlegedialogen der Fachmodule. Keine eigene
 * Fachlogik; die Dialoge legen über die bestehende API an und öffnen den mobilen Editor.
 */
(function () {
  "use strict";

  const root = document.getElementById("mobile-reports");
  if (!root || !globalThis.Calight) return;
  const message = root.querySelector("[data-message]");
  const daily = root.dataset.kind === "daily";

  let busy = false;
  async function open(orderId = "") {
    // Schutz vor Mehrfachausführung bei wiederholtem Antippen.
    if (busy || Calight.moduleReadOnly) return;
    busy = true;
    try {
      if (daily) await globalThis.CalightDailyReports.create({ orderId });
      else await globalThis.CalightWorkReports.openCreateDialog({ orderId });
    } catch (error) {
      Calight.notify(message, /Failed to fetch|NetworkError|Load failed/i.test(error.message) ? "Keine Verbindung zum Server. Bitte erneut versuchen." : error.message);
    } finally {
      busy = false;
    }
  }

  root.querySelector("[data-mobile-create]")?.addEventListener("click", () => open());

  // Einstieg aus der Auftragsdetailseite: Dialog mit vorbelegtem Auftrag einmal öffnen.
  const orderId = root.dataset.createFor;
  if (orderId) {
    const url = new URL(location.href);
    url.searchParams.delete("create_for");
    history.replaceState(null, "", url.pathname + url.search);
    open(orderId);
  }
})();
