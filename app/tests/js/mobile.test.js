// Mobiles Frontend: Verbindungshinweis, Tastaturerkennung, Service-Worker-Registrierung und
// Zusicherung, dass der Service Worker keine Daten zwischenspeichert.
import { readFile } from "node:fs/promises";

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

await import("../../public/assets/mobile.js");
const { updateConnectionNotice, keyboardOpen, registerServiceWorker } = globalThis.CalightMobile;

const notice = { hidden: true };
updateConnectionNotice(notice, false);
assert(notice.hidden === false, "offline shows the notice");
updateConnectionNotice(notice, true);
assert(notice.hidden === true, "online hides the notice");
updateConnectionNotice(null, false); // fehlendes Element ist kein Fehler

assert(keyboardOpen(400, 800) === true, "large viewport shrink means keyboard");
assert(keyboardOpen(760, 800) === false, "small differences (browser bars) are ignored");

assert((await registerServiceWorker({}, true)) === false, "no service worker API: nothing registered");
let registration = null;
const navigatorStub = { serviceWorker: { register: async (url, options) => { registration = { url, options }; } } };
assert((await registerServiceWorker(navigatorStub, false)) === false, "insecure context: nothing registered");
assert(registration === null, "insecure context does not call register");
assert((await registerServiceWorker(navigatorStub, true)) === true, "secure context registers");
assert(registration.url === "/mobile/sw.js" && registration.options.scope === "/mobile/", "worker is scoped to the mobile frontend");
const failing = { serviceWorker: { register: async () => { throw new Error("blocked"); } } };
assert((await registerServiceWorker(failing, true)) === false, "registration errors are swallowed");

const worker = await readFile(new URL("../../public/assets/mobile-sw.js", import.meta.url), "utf8");
assert(!/caches\.open|cache\.put|\.addAll\(|indexedDB|localStorage/.test(worker), "service worker must not store responses or data");
assert(worker.includes('request.mode !== "navigate"'), "service worker only answers page navigations");
assert(!/onclick=/.test(worker), "offline page uses no inline script");

const { viewportContent } = globalThis.CalightMobile;
assert(viewportContent("width=device-width, initial-scale=1", true) === "width=device-width, initial-scale=1, interactive-widget=resizes-content", "sheet resizes content");
assert(viewportContent("width=device-width, initial-scale=1, interactive-widget=resizes-content", false) === "width=device-width, initial-scale=1", "default without interactive-widget");

console.log("mobile tests passed");
