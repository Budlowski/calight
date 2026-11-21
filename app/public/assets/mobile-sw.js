/*
 * Service Worker des mobilen Frontends (ausgeliefert unter /mobile/sw.js, Scope /mobile/).
 *
 * Phase 1 speichert bewusst NICHTS zwischen: keine Seiten, keine API-Antworten, keine Dokumente
 * und keine personenbezogenen Daten in Cache Storage oder IndexedDB. Er beantwortet nur
 * fehlgeschlagene Seitenaufrufe mit einer erzeugten Hinweisseite, damit bei fehlender
 * Serververbindung eine verständliche Meldung statt eines Browserfehlers erscheint.
 *
 * Spätere Erweiterung (Offline-Entwürfe, IndexedDB, Synchronisation) gehört hierher, muss aber
 * Benutzerwechsel und Abmeldung berücksichtigen und darf keine Zugangsdaten ablegen.
 */
"use strict";

const OFFLINE_HTML = `<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#17324a"><title>Keine Verbindung · Calight</title>
<style>
body{margin:0;font:16px/1.5 system-ui,sans-serif;background:#f4f6f8;color:#17202a}
header{padding:calc(14px + env(safe-area-inset-top)) 16px 14px;background:#17324a;color:#fff;font-weight:700;font-size:18px}
main{max-width:560px;margin:0 auto;padding:24px 16px}
.card{padding:16px;border:1px solid #d6dce2;border-radius:8px;background:#fff}
h1{margin:0 0 8px;font-size:22px}
a.button{display:inline-flex;min-height:44px;align-items:center;margin-top:8px;padding:0 16px;border-radius:4px;background:#155a8a;color:#fff;font-weight:650;text-decoration:none}
</style></head>
<body><header>Calight</header><main><div class="card" role="alert"><h1>Keine Verbindung zum Server</h1>
<p>Calight ist gerade nicht erreichbar. Bitte die Netzwerkverbindung prüfen und erneut versuchen.</p>
<p>Es wurden keine Daten auf diesem Gerät gespeichert.</p>
<a class="button" href="">Erneut versuchen</a></div></main></body></html>`;

self.addEventListener("install", () => self.skipWaiting());

self.addEventListener("activate", (event) => {
  // Frühere Versionen dürfen keine Caches hinterlassen.
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.mode !== "navigate" || request.method !== "GET") return;
  event.respondWith(
    fetch(request).catch(
      () =>
        new Response(OFFLINE_HTML, {
          status: 503,
          headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" },
        }),
    ),
  );
});
