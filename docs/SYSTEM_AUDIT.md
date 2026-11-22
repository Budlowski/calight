# Calight – Systemaudit (Stand 22.11.2026, Commit-Basis `0e49102`)

Dieses Audit prüft Code, Datenmodell, Tests und Oberflächen. Es ändert keine Funktionen. Grundlage:
- AGENTS.md, ROADMAP.md und ui-guidelines.md,
- die 50 Migrationen, gegen ein aus ihnen aufgebautes SQLite-Schema geprüft,
- die Dienste unter `app/src`,
- 31 Integrations- und 29 Unit-/JS-Testdateien.

**Grenzen der Prüfung:**
- **PHP:** Keine PHP-Laufzeit. PHP-Tests liefen hier nicht; PHP-Code wurde statisch und mit IDE-Diagnosen geprüft.
- **SQL:** Ausgewählte SQL-Abläufe wurden mit `sqlite3` gegen das echte Schema nachgestellt.
- **Oberfläche:** Kein Zugriff auf eine laufende Instanz. Ansichten wurden nur statisch und mit Chromium gegen API-Attrappen geprüft.

Kennzeichnung der Befunde:
- **nachgewiesen** – am Code bzw. Schema belegt.
- **plausibel** – Risiko aus Code ableitbar, Auswirkung nicht zur Laufzeit belegt.
- **Verbesserung** – kein Fehler.

---

## A. Gesamtbewertung

| Bereich | Bewertung |
|---|---|
| Fachliche Konsistenz | **Gut mit einer klaren Lücke.** Die zentrale Stundenbasis (`time_entries`), Festschreibung und Revisionen, Korrekturketten und die Trennung von Nachweis und Abrechnung sind durchdacht und per Datenbank abgesichert. Uneinheitlich ist, welche Auftragsstatus neue Leistungen zulassen (F-01), und ein Auftragsstatus lässt sich nicht zurücknehmen (F-02). |
| Architektur und Wartbarkeit | **Solide, pragmatisch.** Klare Schichten (Controller → Service → Repository), zentrale Regeln in eigenen Klassen (`OrderVisibility`, `CostTargets`, `ReportTimeBookings`, `ModuleService`). Technische Schuld: wiederholter Controller-Code (F-10), einige sehr große Dienste und JS-Dateien (`PurchaseOrderService` 1.556 Zeilen, `offer-editor.js` 2.143 Zeilen). Teile des Codes sind in Einzeilerform verdichtet (z. B. `DailyReportService`); das erschwert Reviews. |
| UI/UX-Konsistenz | **Gut.** Gemeinsame Bausteine (Modal, Segmente, Statusbadges, Admin-Navigation), Desktop und Mobil nutzen dieselben Fachskripte. Kleinere Abweichungen, zuletzt laufend bereinigt. Belegter Darstellungsfehler: Inline-Style unter CSP (F-08). |
| Datenintegrität und Sicherheit | **Gut.** Fremdschlüssel überall, Sperr- und Unveränderlichkeitstrigger, Geldbeträge als exakte Dezimal-Strings (keine REAL-Spalten), Versionsprüfung, CSRF, vorbereitete Statements, Escaping, sichere Datei-Auslieferung (CSP-Sandbox, nosniff). Lücken: Fallback der Rechtematrix (F-03), kein Brute-Force-Schutz (F-04), Schutz der Sicherungsdateien (F-05). |
| Testqualität | **Breit und fachlich orientiert.** Viele Integrationstests prüfen Abläufe statt Interna, auch mit Negativfällen. Es fehlt ein systematischer Test aller Routen gegen die Rechtematrix (F-03). Die jüngsten Änderungen (Migrationen 0049, 0050) sind auf OMV noch nicht ausgeführt worden (F-17). |
| Produktionsreife | **Nahezu erreicht.** Vor dem Produktivbetrieb erforderlich sind ein vollständiger Testlauf auf OMV, die Entscheidung zur Auftragsstatus-Regel (F-01) sowie, bei Erreichbarkeit aus dem Internet, F-04 und F-05. |

---

## B. Konkrete Befunde

Schweregrade: kritisch / hoch / mittel / niedrig. Aufwand: XS (< 1 h), S (≤ ½ Tag), M (1–2 Tage), L (> 2 Tage).

### F-01 – Uneinheitliche Regel „Auftrag nimmt neue Leistungen an“ · Fachlogik · **hoch** · nachgewiesen · **umgesetzt** (Paket 1, `OrderWorkRules`; Laufzeittests auf OMV ausstehend)
- **Dateien:**
  - `TimeTrackingService.php:1186`: Zeiterfassung lehnt abgerechnete Aufträge ab.
  - `WorkReportService.php:51/422` (`ASSIGNABLE_ORDER_STATUSES = ['open','in_progress']`): Rapporte nur bei offenen und laufenden Aufträgen.
  - `MeasurementService::accessibleOrder` (Zeile 573), `DailyReportService` (Anlage/Zuordnung), Materialanforderungen und Bestellungen prüfen nur die Sichtbarkeit (`OrderVisibility`), aber keinen Status.
- **Nachweis:** Vier Module, drei verschiedene Regeln. Tagesberichte, Aufmaße, Materialanforderungen und Bestellungen lassen sich auch einem **abgerechneten** Auftrag zuordnen. Die Stunden eines solchen Tagesberichts landen trotzdem in der Zeiterfassung, die denselben Auftrag für manuelle Buchungen sperrt.
- **Auswirkung:** Nachträgliche Leistungen, Mengen und Kosten auf abgerechneten Aufträgen bleiben unbemerkt. Die Nachkalkulation wird widersprüchlich.
- **Lösung:** Eine zentrale Regel, z. B. `OrderRepository::acceptsNewWork(status)` bzw. ein Teil der `OrderVisibility`-Abfragen, in allen Modulen verwenden. Fachlich festlegen, ob „abgeschlossen“ noch Korrekturen zulässt. **M**

### F-02 – Auftragsstatus nur vorwärts, ohne Prüfung offener Vorgänge · Fachlogik · **mittel** · nachgewiesen · **umgesetzt** (Paket 1, Migration `0051`; Laufzeittests auf OMV ausstehend)
- **Dateien:** `OrderService::changeStatus` mit `$allowed = ['open'=>'in_progress','in_progress'=>'completed','completed'=>'invoiced']`.
- **Nachweis:** Es gibt keinen Rückweg und keine Prüfung auf offene Rapporte, Tagesberichtsentwürfe, nicht abgeschlossene Aufmaße oder offene Bestellungen.
- **Auswirkung:** Ein versehentlich auf „abgerechnet“ gesetzter Auftrag lässt sich nur per Datenbank korrigieren. Offene Vorgänge bleiben unbemerkt.
- **Lösung:**
  - Rücknahme durch Administratoren mit Begründung (Statushistorie vorhanden).
  - Vor „abgeschlossen“ und „abgerechnet“ offene Vorgänge anzeigen und bestätigen lassen, analog zur Moduldeaktivierung.
  - **M**

### F-03 – Rechtematrix erlaubt unbekannte Pfade jedem angemeldeten Benutzer · Sicherheit · **mittel** · nachgewiesen (Design)
- **Dateien:** `AuthorizationPolicy::required` endet mit `return [];`, `Application.php:99`.
- **Nachweis:** Ohne Treffer genügt die Anmeldung. Ein Abgleich aller 233 registrierten Routen mit den Präfixregeln zeigt: Derzeit fällt keine fachliche Route durch, nur `/` und `/api/customer-projects`, die anderweitig abgedeckt sind. Eine künftig vergessene Route wäre aber auch für Monteure erreichbar.
- **Lösung:**
  - Für `/api/` und Seiten ohne Regel standardmäßig verweigern (Explizit-öffentlich-Liste).
  - Ein Test, der alle Router-Routen gegen die Policy prüft (Routen aus `Router` auslesen).
  - **S**

### F-04 – Kein Schutz gegen Passwort-Raten · Sicherheit · **mittel** · nachgewiesen
- **Dateien:** `Application.php:178–187`.
- **Nachweis:** Bei falscher Anmeldung gibt es nur `usleep(250000)`. Es fehlen Fehlversuchszähler, Sperre und Protokoll.
- **Auswirkung:** Bei Internet-Erreichbarkeit (z. B. eigener Mobil-Host) sind Online-Rateangriffe nur gebremst.
- **Lösung:**
  - Fehlversuche je Benutzername und IP in SQLite zählen.
  - Nach mehreren Fehlversuchen temporär sperren.
  - Eintrag in `user_account_events`.
  - Alternativ oder ergänzend: Reverse-Proxy-Rate-Limit dokumentieren.
  - **S–M**

### F-05 – Sicherungen der Datenbereinigung sind nicht besonders geschützt · Sicherheit · **mittel** · plausibel
- **Dateien:** `SystemPurgeBackup.php` (`VACUUM INTO`, `copy`).
- **Nachweis:**
  - **Inhalt:** Die Sicherung enthält die vollständige Datenbank inklusive Passwort-Hashes sowie alle Dokumente.
  - **Rechte:** Dateien entstehen mit der Standard-umask, typischerweise 0644. Nur das Sicherungsverzeichnis selbst hat 0770.
  - **Aufbewahrung:** Es gibt keine Aufbewahrungsregel.
- **Auswirkung:** Je nach Rechten des Volumes auf dem Host sind die Sicherungen für andere Konten lesbar.
- **Lösung:** Dateien auf 0600 und Verzeichnisse auf 0700 setzen. Ablage und Aufbewahrung in `docs/system-purge.md` beschreiben. **S**

### F-06 – Dokumentenübersicht lädt alle Vorgänge und blättert in PHP · Performance · **mittel** · nachgewiesen
- **Dateien:** `DocumentService::page` (Zeilen 82–130: `usort`, `array_slice`), `DocumentRepository::workReports/measurements/dailyReports`. Für Monteure kommt je Aufmaß eine `OrderVisibility::visible`-Abfrage hinzu (N+1).
- **Nachweis:** Die Übersicht widerspricht der Projektregel „serverseitige Pagination, keine Liste lädt alle Datensätze“ (AGENTS 7).
- **Auswirkung:** Die Antwortzeit wächst linear mit allen Rapporten, Aufmaßen, Tagesberichten und Anhängen.
- **Lösung:** SQL-`UNION` mit `Paginator` oder zumindest Sichtbarkeit per `OrderVisibility::visibleIds` in einer Abfrage. **M**

### F-07 – Mobile Aufmaß-Auftragsauswahl mit N+1-Berechnung · Performance · **niedrig** · nachgewiesen
- **Dateien:** `MobileWorkspace::selectableOrders(…, true)` → `measurable()` → `MeasurementService::basisPositions` je Auftrag, bis zu 100 Aufträge.
- **Lösung:** Das Kennzeichen über eine Abfrage ermitteln (Auftragsgrundlage bzw. angenommene Nachträge vorhanden) oder erst nach der Auswahl prüfen. **S**

### F-08 – Inline-Style wird durch die CSP blockiert · UI · **niedrig** · nachgewiesen
- **Dateien:** `public/.htaccess:14` (`style-src 'self'`), `master-data.js:168` (`style="--depth:…"`).
- **Auswirkung:** Bei aktivem `mod_headers` fehlt die Einrückung der Kategorien-Hierarchie.
- **Lösung:** `data-depth` mit CSS-Klassen bzw. `element.style.setProperty` nach dem Rendern. **XS**

### F-09 – Uneinheitliche JSON-Antwortformen · API · **niedrig** · nachgewiesen
- **Nachweis:**
  - **Erfolgsantworten:** 56-mal `{data: …}`, daneben `{users}`, `{settings}`, `{saved}`, `{id}`, `{employee}`.
  - **Fehler:** teils `'Validierung fehlgeschlagen.' + details`, teils die Meldung direkt in `error`.
  - **Abfederung:** `Calight.api` gleicht das aus (`result.data ?? result`, details vor error).
- **Lösung:** Bei ohnehin anstehenden Änderungen auf `{data}` und `details` vereinheitlichen. Kein eigenes Projekt. **S** (schrittweise)

### F-10 – Wiederholter Controller-Code · Wartbarkeit · **niedrig** · nachgewiesen
- **Nachweis:** Die CSRF-Prüfung steht 36-mal manuell im Code. JSON-Dekodierung, Fehlerabbildung und `auth()`-Helfer werden je Controller wiederholt.
- **Lösung:** Kleiner gemeinsamer Helfer (z. B. `Request::jsonBody()`, `Controller::write()`), nur bei Berührung einsetzen. **M**, optional.

### F-11 – Fremdschlüssel ohne Index · Datenbank · **niedrig** · nachgewiesen
- **Nachweis:** 23 Fremdschlüsselspalten ohne Index, aus dem Schema ermittelt. Relevant sind vor allem:
  - `projects.customer_id`,
  - `time_expenses.order_id`,
  - `time_statements.submission_id`, `time_lock_events.submission_id`,
  - `purchase_order_*.document_id`,
  - `daily_report_order_events.*`.
- **Auswirkung:** Bei heutigen Mengen unkritisch. Verlangsamt Kundenseiten, Löschprüfungen und die Datenbereinigung.
- **Lösung:** Eine additive Indexmigration für die genannten Spalten. **S**

### F-12 – Veraltete Projektdokumentation · Dokumentation · **niedrig** · nachgewiesen
- **AGENTS.md 4.17:** Manuelle Buchungen gelten dort noch als „optional ohne Uhrzeiten“. Seit `68019a3` sind Uhrzeiten Pflicht; die Einstellung betrifft nur noch Berichtsstunden.
- **AGENTS.md 4.18:** „mobile Erfassungsformulare sind noch nicht umgesetzt“. Sie sind umgesetzt.
- **AGENTS.md Abschnitt 2:** Die Statustabellen reichen nur bis `0025`.
- **Lösung:** Doku nachziehen. **XS**

### F-13 – Fehlende Fotodateien verschwinden im Tagesbericht-PDF ohne Hinweis · Fehlerbehandlung · **niedrig** · nachgewiesen
- **Dateien:** `DailyReportPdfService::contents` (`catch (Throwable) { return null; }`).
- **Lösung:** Im PDF „Datei nicht verfügbar“ ausgeben und `error_log` schreiben. **XS**

### F-14 – Datenbereinigung lässt leere Unterverzeichnisse zurück · Datenbereinigung · **niedrig** · nachgewiesen
- **Nachweis:** Sie entfernt Dateien und Angebotsverzeichnisse, aber nicht `daily-reports/<id>/`, `work-reports/<id>/` usw.
- **Lösung:** Nach dem Löschen leere Elternverzeichnisse unterhalb von `APP_DOCUMENTS_PATH` entfernen. **XS**

### F-15 – Datenbereinigung sperrt Schreibzugriffe während der Ausführung · Datenbereinigung · **niedrig** · plausibel
- **Nachweis:** Inhalts- und Vorschau-Fingerabdruck lesen die ganze Datenbank innerhalb von `BEGIN IMMEDIATE`. Andere Schreibzugriffe warten (`busy_timeout` 5 s) und schlagen bei großen Datenbanken fehl.
- **Lösung:** In `docs/system-purge.md` als Wartungsvorgang (ohne parallele Nutzung) kennzeichnen. Vor der Produktivnahme ist das ohnehin der Fall. **XS**

### F-16 – Kein serverseitiges Leerlauf-Timeout der Sitzung · Sicherheit · **niedrig** · plausibel
- **Dateien:** `Session::start` (Cookie-Lebensdauer 0), keine Prüfung der letzten Aktivität.
- **Auswirkung:** Auf gemeinsam genutzten Tablets oder Notebooks bleibt die Anmeldung bis zum Schließen des Browsers bestehen.
- **Lösung:** Letzte Aktivität in der Session führen, z. B. nach 12 h abmelden. **S**

### F-17 – Jüngste Änderungen nicht durch PHP-Testlauf bestätigt · Tests · **mittel** · offene Prüfung
- **Nachweis:** Seit dem letzten grünen Lauf (197 Tests) ist Folgendes nur statisch geprüft:
  - Migrationen `0049` (Einsatzort) und `0050` (Datenbereinigung),
  - Pflicht-Uhrzeiten,
  - die Datenbereinigung mit `SystemPurgeTest`.
- **Lösung:** `php tests/run.php` auf OMV ausführen und die Migration zuerst auf einer Kopie der Produktivdatenbank testen. **XS**

### F-18 – Kein Test aller Routen gegen die Rechtematrix · Tests · **niedrig** · nachgewiesen
- **Nachweis:** Bestehende Tests prüfen einzelne Pfade (`SecurityTest`, `UserManagementTest`), nicht vollständig.
- **Lösung:** Siehe F-03. **S**

---

## C. Priorisierte Maßnahmen

1. **Vor Produktivbetrieb erforderlich**
   - F-17: vollständiger PHP-Testlauf und Migrationstest auf einer Datenkopie.
   - F-01: Regel festlegen und zentral umsetzen.
   - F-04 und F-05, falls Calight aus dem Internet erreichbar ist bzw. Sicherungen auf geteiltem Speicher liegen.
   - Danach die Datenbereinigung ausführen und den Produktivstatus setzen.
2. **Kurzfristig sinnvoll**
   - F-02: Statusrücknahme und Warnung bei offenen Vorgängen.
   - F-03 mit F-18: Standard-Verweigern und Routentest.
   - F-12: Dokumentation.
   - F-08: CSP-Darstellung.
   - F-13: Hinweis bei fehlenden Fotodateien.
3. **Langfristig**
   - F-06: Pagination der Dokumentenübersicht.
   - F-11: Indizes.
   - F-16: Sitzungs-Timeout.
   - F-10: gemeinsame Controller-Hilfen.
   - Aufteilung der größten Dienste und Skripte bei fachlichen Erweiterungen.
4. **Optional / kosmetisch**
   - F-07, F-09, F-14, F-15.

---

## D. Positivbefunde (keine Überarbeitung nötig)

- **Zentrale Stundenbasis:** Rapporte, Tagesberichte und manuelle Erfassung buchen genau einmal in `time_entries`. Dafür sorgen UNIQUE-Verweise und `ReportTimeBookings`, Kostensätze werden als Snapshot übernommen. Doppelzählung ist strukturell ausgeschlossen.
- **Festschreibung per Datenbank:** Sperrtrigger für Tage, Berichte und Nachweise sowie unveränderliche Revisionen (`*_statements`). Das System verlässt sich nicht auf die Oberfläche.
- **Geldbeträge:** durchgehend exakte Dezimal-Strings (Brick\Math), keine REAL-Spalten.
- **Auftragssichtbarkeit:** zentral in `OrderVisibility`, von allen Modulen genutzt.
- **Kaufmännische Werte:** Trennung über `commercial.read`/`costs.read` und `ConfidentialDataFilter`.
- **Grundschutz:**
  - CSRF bei allen schreibenden Routen,
  - vorbereitete SQL-Statements,
  - konsequentes Escaping (`Calight.esc`, `$escape`),
  - Modalinhalte als Text,
  - keine Inline-Skripte.
- **Datei-Auslieferung:** Typprüfung per Inhalt (`finfo`), Speicherpfade nur aus UUIDs, Download mit CSP-Sandbox und `nosniff`.
- **Konten und Sitzungen:**
  - Session-Fixation-Schutz,
  - `HttpOnly`/`SameSite`,
  - Sitzungsversion beendet Sitzungen bei Passwortwechsel oder Deaktivierung,
  - letztes Administratorkonto geschützt.
- **Konkurrierende Änderungen:** Versions- und ETag-Prüfung, Bearbeitungssperren für Angebote.
- **Module:** Deaktivierung löscht nichts, Daten bleiben lesbar, Schreibzugriffe werden serverseitig abgewiesen.
- **Datenbereinigung:**
  - Vorschau mit Fingerabdruck,
  - generische Fremdschlüsselprüfung ohne stille Kaskaden,
  - verpflichtende geprüfte Sicherung,
  - transaktionale Ausführung,
  - endgültiger Produktivstatus.
- **Tests:** fachliche Integrationstests mit Negativfällen (Rechte, Sperren, Revisionen, Kostenrechnung bei Bestellungen); JS-Tests aktuell 13/13 erfolgreich (bun).

---

## E. Offene Prüfungen

- **PHP-Testlauf:** Keine PHP-Laufzeit, kein Docker in der Prüfumgebung (F-17).
- **Echte Browseransichten:** Nur statische Prüfung und Chromium mit API-Attrappen. Nicht geprüft wurden:
  - Tastaturbedienung im Gesamtablauf,
  - echte Touch-Geräte,
  - CSP-Wirkung mit realem Apache.
- **Mobilgeräte:** Die Leistenproblematik unter Firefox für Android wurde mehrfach angepasst. Die abschließende Bestätigung auf dem Gerät steht aus.
- **Last und Datenmenge:** Keine Messungen mit realistischen Datenmengen (F-06, F-07, F-11, F-15).
- **Wiederherstellung aus der Sicherung:** Nur als manuelles Verfahren dokumentiert, nicht praktisch erprobt.
- **Angebotseditor:** `offer-editor.js` (2.143 Zeilen) und die PDF-Erzeugung wurden nur stichprobenartig geprüft.
