# AGENTS.md – Verbindliche Arbeitsgrundlage für Calight

Diese Datei gilt für das gesamte Repository. Sie richtet sich modellunabhängig an Codex, Claude Code und andere Entwicklungsagenten. Lies sie vor jeder Entwicklungsaufgabe vollständig. Ergänzende Anweisungen in tiefer liegenden `AGENTS.md`-Dateien gelten nur für deren Verzeichnis; derzeit existieren keine solchen Ergänzungen.

Die Schlüsselwörter **MUSS**, **DARF NICHT**, **SOLL** und **SOLLTE** kennzeichnen verbindliche Regeln beziehungsweise begründete Empfehlungen. Bei Widersprüchen haben explizite aktuelle Benutzeranforderungen Vorrang. Fachliche Widersprüche zwischen Auftrag, Code und Dokumentation sind offenzulegen, nicht stillschweigend umzudeuten.

## 1. Projekt in Kürze

Calight ist eine schlanke, selbst gehostete Open-Source-Anwendung für Handwerksbetriebe und Dienstleister. Der Schwerpunkt liegt auf:

- Artikel- und Positionsstammdaten;
- nachvollziehbarer Angebotskalkulation;
- Kunden, optionalen Projekten, Angeboten, Revisionen und Aufträgen;
- unveränderlichen abgegebenen Dokumentständen;
- Nachträgen, Mitarbeitern, Bautagesberichten und Ist-Zeiten;
- einer schrittweisen Erweiterung zur Ausführungsdokumentation und Nachkalkulation.

Calight ist **keine Buchhaltungs- oder Fakturasoftware**. Rechnungen und Buchhaltung bleiben in externen Anwendungen wie Lexware. Calight darf externe Rechnungsnummern oder Abrechnungsstände künftig referenzieren, soll Lexware aber nicht nachbauen.

### Projektphilosophie

- Self-Hosting ohne Cloudzwang oder externe Laufzeitdienste.
- Der reguläre Betrieb muss ohne Internetverbindung möglich sein. Lokale Assets statt CDN verwenden.
- Einfache Installation und Wartung, primär mit Docker.
- Verständlicher, modularer und langfristig wartbarer Code.
- Möglichst wenige Abhängigkeiten; keine unnötigen Frameworks oder Abstraktionsschichten.
- Professionelle, kompakte und konsistente Bedienung auf Desktop, Tablet und Smartphone.
- Mehrbenutzerbetrieb mit serverseitigen Rechten, Datenintegrität und nachvollziehbaren Änderungen.
- Bestehende Installationen, JSON-Dokumente und Archive langfristig kompatibel halten.
- Overengineering vermeiden. Die kleinste robuste, fachlich richtige Lösung ist vorzuziehen.

## 2. Verlässliche Informationsquellen

Vor einer Änderung sind die betroffenen Implementierungen und Dokumente zu lesen. Frühere Chats stehen neuen Agenten möglicherweise nicht zur Verfügung und sind keine verlässliche Repository-Quelle.

| Quelle | Aufgabe und Verbindlichkeit |
|---|---|
| `AGENTS.md` | Verbindliche Arbeits-, Architektur-, Sicherheits- und Qualitätsregeln für Entwicklungsagenten. |
| `CLAUDE.md` | Kurzer Einstieg für Claude Code; verweist auf diese Datei und ersetzt sie nicht. |
| `docs/README.md` | Öffentliche Projektbeschreibung, Installation, Betrieb und allgemeiner Einstieg. Im Repository gibt es derzeit keine `README.md` im Hauptverzeichnis. |
| `docs/ROADMAP.md` | Fachliches Zielbild und geplante Phasen. Statusangaben können dem lokalen Implementierungsstand hinterherhinken. |
| `docs/offer-json-schema.md` und `app/config/offer-schema-v1.json` | Beschreibung beziehungsweise maschinenlesbare Ausgangsdefinition des Angebotsformats. Immer zusätzlich Validator und Normalizer prüfen. |
| `docs/kalkulationsbausteine.md` | Preisquellen und Kalkulationsverhalten. |
| `docs/master-data-json.md` | Vollständiger Import/Export einzelner Stammdatenbereiche. |
| `docs/pdf-templates.md` | Fachliche Regeln des Angebotsdrucks und der Dokumenteinstellungen. |
| `app/migrations/*.sql` | Tatsächlich eingeführtes SQLite-Schema und historische Datenmigrationen. |
| `app/src`, `app/templates`, `app/public/assets` | Maßgeblicher aktueller Implementierungsstand. |
| `app/tests` und `.github/workflows/quality.yml` | Erwartetes Verhalten und automatisierte Qualitätsprüfungen. |

### Bekannte Dokumentationsabweichungen

Stand 19.11.2026 geben die Statustabellen in `docs/README.md` und `docs/ROADMAP.md` den Implementierungsstand bis Phase 3.4 (Migration `0025`) wieder. Verbleibende bekannte Abweichungen:

- `app/config/offer-schema-v1.json` bezeichnet `projectId` noch ausschließlich als UUID und bildet nicht alle später ergänzten Dokumentfelder ab. Der tatsächliche Import- und Speichervertrag ergibt sich aus `OfferValidator`, `OfferNormalizer`, `OfferService` und den Tests. Das Schema nicht isoliert als einzige Wahrheit behandeln.
- `docs/README.md` nennt Ansprechpartnerverwaltung in der Roadmap als geplant, obwohl Kundenkontakte und mehrere Adressen seit Migration `0007` vorhanden sind. Eine weitergehende Kontakt-/CardDAV-Integration bleibt geplant.

Diese Hinweise sind keine Erlaubnis, Dokumente oder Verträge nebenbei umzuschreiben. Bei einer betroffenen Aufgabe den Ist-Zustand prüfen und Abweichungen gezielt korrigieren oder im Abschlussbericht nennen.

## 3. Technischer Stack und Repository-Aufbau

### Verbindlicher Stack

- PHP `^8.4`, Apache und SQLite.
- Serverseitig gerenderte PHP-Templates in `app/templates`.
- HTML, CSS und Vanilla JavaScript; kein Frontend-Buildsystem erforderlich.
- Composer mit PSR-4-Autoloading für `App\` aus `app/src`.
- `brick/math` ist die einzige fachliche Composer-Bibliothek und dient exakter Dezimalarithmetik.
- Projekteigene PDF-Infrastruktur `App\Infrastructure\Pdf\SimplePdf`; **kein mPDF**.
- Angebotsinhalte als JSON-Dateien, Metadaten und übrige Fachdatensätze in SQLite.
- Docker/Compose als primärer Bereitstellungsweg.

React, Vue, Svelte, Angular, zusätzliche UI-Frameworks, ORMs, Microservices oder neue PDF-Bibliotheken dürfen nur nach ausdrücklichem Auftrag eingeführt werden. Externe CDNs und Cloud-Abhängigkeiten sind mit dem Offline-Ziel unvereinbar.

### Wichtige Pfade

```text
.
├── app/
│   ├── bin/console                 # migrate, user:create
│   ├── config/                     # Laufzeitkonfiguration und Angebots-Schema
│   ├── migrations/                 # additive, sortierte SQL-Migrationen
│   ├── public/                     # Apache DocumentRoot, Front Controller, Assets
│   ├── src/
│   │   ├── Application/            # Anwendungsservices und Bootstrap
│   │   ├── Domain/                 # Kalkulation, Validierung, Fachausnahmen
│   │   ├── Http/                   # Router, Request/Response, Controller, Session
│   │   ├── Infrastructure/         # SQLite, Repositories, JSON- und PDF-Speicher
│   │   ├── Security/               # Rollen, Autorisierung, vertrauliche Daten
│   │   ├── Support/                # Uhr, UUID und kleine Hilfen
│   │   └── View/                   # Template-Rendering
│   ├── templates/                  # Seiten und gemeinsames Layout
│   └── tests/                      # eigener PHP-Testläufer, Unit/Integration/JS
├── docs/                            # öffentliche und fachliche Dokumentation
├── .github/workflows/quality.yml    # CI
├── Dockerfile
└── compose.yml
```

Laufzeitdaten liegen außerhalb des Anwendungscodes in den über `Config` konfigurierten Verzeichnissen:

- `APP_DATABASE_PATH` – SQLite-Datei;
- `APP_OFFERS_PATH` – Entwürfe, Snapshots und archivierte Angebots-PDFs;
- `APP_DOCUMENTS_PATH` – Dokumentdaten;
- `APP_TEMPLATES_PATH` – Vorlagendaten.

In Docker sind diese Pfade Unterverzeichnisse von `/var/www/data` im Volume `calight-data`. Datenbanken, Angebote, Dokumente, Logs, `.env` und andere Laufzeitdateien dürfen nicht eingecheckt werden. Datenverzeichnisse dürfen nie im Projektordner oder unter `app/public` liegen; `StoragePaths` verhindert den Start bei Datenpfaden im Webverzeichnis, `php bin/console storage:check` zeigt die Konfiguration. Neue Dateiablagen nutzen Unterordner von `APP_DOCUMENTS_PATH` (z. B. `daily-reports/`), speichern Pfade relativ und nur aus internen IDs. Details: `docs/storage.md`.

### Laufzeitfluss

1. `app/public/index.php` lädt Composer, `Bootstrap`, Session und Request.
2. `Application\Bootstrap` verdrahtet PDO, Repositories, Services und Controller ohne Dependency-Injection-Framework.
3. `Application` registriert Kernrouten und führt vor dem Router die zentrale `AuthorizationPolicy` aus.
4. Controller übersetzen HTTP in Service-Aufrufe; Fachlogik gehört in Domain- oder Application-Klassen.
5. Repositories kapseln vorbereitete SQL-Abfragen. Templates geben bereits vorbereitete Daten aus und escapen über `$escape`.

Diese Schichtung SOLL beibehalten werden. Geschäftsregeln nicht in Templates oder parallel im JavaScript implementieren. Das Frontend darf Vorschauen und Interaktion anbieten; der Server bleibt maßgeblich für Validierung, Berechnung, Autorisierung und Persistenz.

## 4. Fachliche Grundregeln

### 4.1 IDs, Geschäftsnummern und Nummernkreise

- Interne Beziehungen verwenden UUIDs. Sichtbare Geschäftsnummern sind davon strikt getrennt.
- Nummernkreise liegen in `number_ranges`; vergebene Werte werden in `assigned_numbers` dauerhaft reserviert.
- Vergabe erfolgt serverseitig und transaktionssicher über `NumberRangeRepository`/SQLite.
- Vergebene Nummern dürfen nicht wiederverwendet werden. Lücken sind zulässig.
- Manuelle Nummern müssen eindeutig sein und verändern den automatischen Zähler nicht.
- Formate verwenden insbesondere `{K}`, `{JJJJ}`, `{JJ}`, `{MM}` und `{NR}`; Reset kann nie, jährlich oder monatlich erfolgen.
- Eine Entwurfserstellung darf keine verbindliche Angebots- oder Nachtragsnummer verbrauchen.
- Bestehende Kreise umfassen unter anderem Kunden, Projekte, Stammpositionen, Angebote, Aufträge und Nachträge. Nachträge verwenden den Kreis `change_order` mit Standardpräfix `NA`.
- Rechnungsnummern werden nicht durch Calight vergeben. Externe Lexware-Referenzen sind fachlich davon getrennt.

Keine zweite Nummernlogik in einem Controller oder Frontend anlegen.

### 4.2 Dezimalwerte und Berechnung

- Verbindliche Berechnungen verwenden `Brick\Math\BigDecimal`, niemals PHP-Floats.
- `Domain\Calculation\Decimal` definiert: Kosten 4, Mengen 3, Zeiten 3, Prozente 2, Geld 2 Nachkommastellen.
- Intern/API/JSON werden Dezimalstrings mit Punkt verwendet; die UI formatiert deutsch.
- Kaufmännische Rundung erfolgt mit `RoundingMode::HalfUp` an den fachlich festgelegten Stellen.
- Der `PriceCalculator` rundet den kalkulierten Einheitspreis auf zwei Stellen, dann den Positionsgesamtpreis. Einzelne Bausteine dürfen nicht vorzeitig gerundet werden.
- Ein manueller Verkaufspreis ändert interne Kosten und Kalkulationsbausteine nicht.
- Kalkulations- und Summenlogik aus `OfferCalculator`, `PriceCalculator` und `DocumentTaxCalculator` wiederverwenden. Keine zweite Berechnungsengine in PDF, Controller oder JavaScript schaffen.

### 4.3 Stammdaten

- Der Artikelstamm (`cost_items`) umfasst Material, Lohn, Fremdleistungen und sonstige Kosten.
- Stammartikel enthalten Kosten-/Einkaufspreis, Zuschlag und daraus berechneten Verkaufspreis; Lieferant und Lieferantenartikelnummer sind optional.
- Der Positionsstamm (`service_templates`) enthält wiederverwendbare Leistungspositionen mit beliebig vielen Kalkulationsbausteinen.
- Kategorien sind hierarchisch; Zyklen und ungültige Referenzen müssen verhindert werden.
- Lieferanten und Schlepptexte sind eigenständige Stammdaten.
- `priceMode=master` bedeutet: Preis stammt aus Artikelstamm, wird aber nur durch eine ausdrücklich ausgelöste Aktualisierung übernommen.
- `priceMode=manual` bedeutet eigener, vom Artikelstamm unabhängiger Kostenpreis/Aufschlag. Legacy `locked` wird als `manual` normalisiert.
- Eine Stammdatenreferenz darf gespeicherte Dokumente niemals beim Öffnen unbemerkt verändern.
- Vollständiger JSON-Austausch erfolgt je Bereich (`categories`, `suppliers`, `cost-items`, `trailing-texts`, `services`), transaktional und ohne implizites Löschen fehlender Datensätze. Reihenfolge bei Systemübertragung gemäß `docs/master-data-json.md` beachten.

### 4.4 Kunden und Projekte

- Kunden sind zentrale Geschäftspartner in SQLite. Automatische oder manuelle Kundennummern sind zulässig.
- Privatpersonen und Unternehmen, Hauptdaten, Kontakte und mehrere Adressen werden unterstützt.
- Referenzierte Kunden dürfen nicht physisch gelöscht werden; Archivierung erhält Beziehungen und Nummern.
- Projekte gehören höchstens einem Kunden und sind eine **optionale** organisatorische Klammer.
- Angebote und Aufträge dürfen ohne Projekt existieren; ein Kunde bleibt Pflicht.
- Keine Dummy-Kunden oder Dummy-Projekte automatisch erzeugen.
- Baustellenadresse und Ansprechpartner eines Projekts sind unabhängig von der Rechnungsadresse.
- Bestehende projektfreie und ältere projektgebundene Datensätze müssen weiter lesbar bleiben.

### 4.5 Angebote und Angebots-JSON

- Angebotsmetadaten liegen in SQLite (`offers`), der vollständige Entwurf in `APP_OFFERS_PATH`.
- `OfferJsonStore` schreibt `draft.json` atomar über eine temporäre Datei und Dateisperre.
- Das aktuelle Dokumentformat trägt `schemaVersion: 1`. Neue kompatible Felder sind sorgfältig durch Validator, Normalizer, Speicherung, Import/Export, Berechnung, UI und Tests zu führen.
- Titel können beliebig tief verschachtelt sein. Knoten sind Titel, Position oder Text.
- Positionen unterstützen normal, Bedarf und Alternative. Ausgeschlossene Titel vererben ihre Ausschlusswirkung an Unterelemente.
- Bedarf und Alternative werden angeboten und bepreist, zählen aber nicht zur regulären Angebots- und übergeordneten Normalsumme.
- Berechnete Felder werden serverseitig erzeugt und nicht im kanonischen Entwurfs-/Export-JSON gespeichert.
- Importierte Berechnungsergebnisse sind nicht vertrauenswürdig und werden verworfen; IDs und geschützte Dokumentidentität werden validiert.
- Ein Schlepptext in einer Position ist ein unabhängiger Snapshot und wird nicht automatisch aus dem Stamm aktualisiert.
- JSON-Import in einen bestehenden Entwurf benötigt Vorschau, Bestätigung und aktuellen ETag.
- Kunden-PDF und interne Kalkulations-PDF sind getrennte Ausgaben. Vertrauliche Kosten dürfen nie in den normalen Angebotsdruck gelangen.

### 4.6 Prüfung, Abgabe, Status und Unveränderlichkeit

- Entwürfe haben Status `draft` und zunächst keine Geschäftsnummer.
- Bei erster verbindlicher Abgabe führt `OfferSubmissionValidator` die zentrale Prüfung aus. Blockierende Fehler und ausdrücklich zu bestätigende Warnungen bleiben getrennt.
- Der Abschluss vergibt die Nummer, erzeugt `snapshot.json` und `submitted.pdf`, speichert SHA-256-Prüfsummen und markiert Dateien schreibgeschützt.
- Abgegebene Stände dürfen inhaltlich **niemals** verändert, neu berechnet oder aus aktuellen Stammdaten/PDF-Einstellungen nachgeneriert werden.
- Das archivierte Kunden-PDF ist der verbindliche Originaldruck. Eine interne Kalkulations-PDF darf es nicht ersetzen.
- Statuswerte sind `draft`, `submitted` (Abgegeben), `commissioned` (Angenommen) und `rejected` (Abgelehnt).
- Inhaltsänderungen sind nur am Entwurf zulässig. Geschäftsstatuswechsel verändern weder Snapshot noch PDF.
- Die API muss Schreibschutz, Prüfung und Konfliktschutz ebenso durchsetzen wie die UI.

### 4.7 Revisionen

- Eine neue Revision entsteht aus einem bereits abgegebenen Stand, bleibt unter derselben Angebotsnummer und erhält eine fortlaufende `revision_number`.
- Revision 0 wird in der Oberfläche nicht ausdrücklich als „Revision 0“ bezeichnet.
- Jede abgegebene Revision besitzt eigene unveränderliche Snapshot- und PDF-Dateien.
- Pro Revisionskette darf höchstens ein Entwurf offen und höchstens eine Revision angenommen sein; partielle eindeutige SQLite-Indizes sichern dies zusätzlich.
- `revision_root_id` und `previous_revision_id` bilden die Historie. Vorherige Revisionen nicht überschreiben.
- Kopieren erzeugt einen neuen unabhängigen Entwurf ohne Geschäftsnummer, keine heimliche Revision.

### 4.8 Aufträge

- Ein Angebotsauftrag kann nur aus einer angenommenen normalen Angebotsrevision entstehen. Dieselbe Revision darf nicht doppelt beauftragt werden.
- Die Auftragsgrundlage wird als unveränderliches `basis_json` mit SHA-256-Prüfsumme gespeichert. Angebots-, Stamm- oder Kundendatenänderungen dürfen sie nicht verändern.
- Direkte Regieaufträge benötigen einen Kunden, aber weder Angebot noch Projekt. Für sie keine fiktive ursprüngliche Auftragssumme erzeugen.
- Auftragsstatus folgen ausschließlich `open → in_progress → completed → invoiced` (Offen → In Bearbeitung → Abgeschlossen → Abgerechnet).
- Statuswechsel werden transaktional in `order_status_history` protokolliert.
- Auftragsbezeichnung und optionale Beschreibung dürfen separat bearbeitet werden; Nummer, Herkunft und Snapshots bleiben unverändert.
- `invoiced` bedeutet nachgehaltenen Geschäftsstatus, nicht eigene Rechnungsstellung durch Calight.

### 4.9 Nachträge

- Nachträge sind `offers` mit `document_type=change_order` und gehören zu genau einem Auftrag.
- Sie verwenden den vorhandenen Angebotseditor, dieselbe Kalkulation, Abgabeprüfung, Revisionen und PDF-Infrastruktur.
- Nachträge erhalten erst bei Abgabe eine eigene Nummer aus dem Nummernkreis `change_order`/`NA`.
- Ein Nachtrag wird nur über den zugehörigen Auftrag angenommen. Allgemeine Angebots-Statusaktionen dürfen diese Regel nicht umgehen.
- Erst der angenommene Nachtrag wird als unveränderliche zusätzliche Auftragsgrundlage in `order_addenda` übernommen.
- `offer_revision_id` und `revision_root_id` sind eindeutig; doppelte Übernahme ist verboten.
- Der ursprüngliche Auftragssnapshot bleibt unverändert. Ursprüngliche Summe, angenommene Nachträge und aktuelle Gesamtauftragssumme bleiben getrennt.
- Offene oder abgelehnte Nachträge zählen nicht zur Auftragssumme.

### 4.10 Benutzer, Mitarbeiter und vertrauliche Kosten

- Benutzerkonten authentifizieren Personen. Mitarbeiter bilden interne Arbeitskräfte für Zeit- und Kostenbuchungen ab. Es sind bewusst verschiedene Konzepte.
- Ein Mitarbeiter kann optional genau einem Benutzer zugeordnet sein. Mitarbeiter ohne Login und Benutzer ohne Mitarbeiterdatensatz sind zulässig.
- Mitarbeiter besitzen Anzeigename, optionale Personalnummer, internen Stundenkostensatz und Aktivstatus.
- Der interne Stundenkostensatz ist tatsächlicher kalkulatorischer Personalaufwand, kein Verkaufssatz und keine Angebots-Lohnkostengruppe.
- Beim Zeitbucheintrag werden Kostensatz und Lohnkosten als historischer Snapshot gespeichert. Spätere Mitarbeiteränderungen dürfen alte Buchungen nicht verändern.
- Deaktivierung erhält historische Beziehungen.

### 4.11 Tagesberichte und Zeiterfassung

- Tagesberichte (sichtbarer Name seit Migration `0041`, technisch weiterhin `daily_reports`/`DailyReportService`) sind interne Dokumentation. Kostenziel optional: Auftrag ODER Kostenstelle ODER keines (nie beides). Kunde und Projekt sind optionaler Kontext (`daily_reports.customer_id` seit `0041` nullable); ein Projekt nur mit seinem Kunden. Mit Auftrag kommen Kunde und Projekt aus dem Auftrag (Widersprüche werden abgelehnt; ein kundenloser Bericht übernimmt beim Zuordnen den Auftragskunden). Kundenlose Berichte (z. B. Lagerinventur auf K120) funktionieren vollständig inkl. Stunden, Anhängen, Materialanforderungen (`material_requests.customer_id` nullable, `cost_center_id` aus dem Bericht; kein künstlicher Kunde/Auftrag) und PDF (ohne Empfänger und leere Zeilen). Es wird nie ein Auftrag für einen Bericht angelegt. Mehrere Berichte pro Auftrag und Tag sind zulässig.
- Kostenziel (Auftrag desselben Kunden und passenden Projekts ODER allgemeine Kostenstelle ODER keines) setzen, wechseln oder entfernen nur mit `orders.write` (`DailyReportService::assignOrder`), protokolliert in `daily_report_order_events` (Auftrag und Kostenstelle). Die Stunden folgen dem Kostenziel des Berichts (keine neue Buchung, kein separater Übernahmeschritt). Materialanforderungen behalten Kunde und ggf. Auftrag des Berichts; eine Kostenstelle erzeugt nie einen Auftrag.
- Jede Mitarbeiterstundenposition ist tatsächliche Arbeitszeit mit genau einer zentralen Buchung (siehe 4.17); Stundenkorrekturen am Bericht (Büro, bzw. Ersteller beim eigenen Bericht) wirken sofort, nachvollziehbar über `daily_report_time_entries.updated_by_user_id`. Positionen werden per Upsert gespeichert (stabile IDs), damit ergänzte Uhrzeiten erhalten bleiben. Dauer in ganzen Minuten. `labor_counts_for_order` ist nur noch historisch relevant (Migration `0037`).
- Bautagesberichte haben keinen Abschluss-Status; PDFs werden bei jedem Abruf neu erzeugt und nicht archiviert.
- Ein Bericht enthält Datum, optionale Bezeichnung, Tätigkeit, Bemerkungen und mehrere Zeiteinträge.
- Zeiteinträge enthalten Mitarbeiter, Arbeitsdatum, Dauer, optionale Tätigkeit und optional ein stabiles Ziel in der Auftragsgrundlage.
- Zuordnung erfolgt anhand interner IDs als Auftrag, Titel oder Position; niemals anhand sichtbarer Nummern oder Texte.
- Ziele können aus ursprünglicher Auftragsgrundlage oder angenommenem Nachtrag stammen. Direkte Regieaufträge ohne Struktur bleiben auf Auftragsebene.
- Dauer × Kostensatz-Snapshot ergibt die Ist-Lohnkosten mit exakter Dezimalarithmetik.
- Kostenfelder werden nur mit `costs.read` ausgeliefert/angezeigt.
- Techniker sehen eigene Berichte immer, fremde nur an freigegebenen Aufträgen (derzeit nicht Status `open`) und fremde Berichte ohne Auftrag nie; sie buchen nur eigene Zeiten und bearbeiten nur eigene Berichte. Für Berichte ohne Auftrag erhalten sie aktive Kunden und nicht archivierte Projekte nur mit Nummer und Name.
- Berichte verwenden ihre eigene Versionsnummer für konkurrierende Änderungen. Sie benötigen bewusst keine globale Auftragssperre.
- **Anhänge** (`daily_report_attachments`) liegen über `AttachmentStore` unter `APP_DOCUMENTS_PATH/daily-reports/<bericht>/<anhang>.<ext>`. Der Dateityp wird mit `finfo` am Inhalt geprüft (Whitelist in `DailyReportService::ATTACHMENT_TYPES`), Speicherpfade entstehen nur aus UUIDs. Auslieferung ausschließlich über die geschützte Route mit denselben Leserechten wie der Bericht; nur Vorschaubilder inline, alles andere als Download. Hinzufügen/Entfernen wie Bearbeiten des Berichts.
- **Materialanforderungen** (`material_requests`) sind eigenständige Datensätze mit Pflicht-Kundenbezug (`customer_id`), Auftragsbezug (Pflicht außer bei Anforderungen aus einem Bericht ohne Auftrag; es wird nie ein Auftrag erzeugt) und optionalem Bericht (`ON DELETE SET NULL`). Sie werden nur über die eigene API geändert, nie über das Speichern eines Berichts. Erfassen mit `reports.write`, Erledigen/Wiedereröffnen nur mit `materials.manage` (Büro/Admin); Monteure ändern nur eigene offene Anforderungen freigegebener Aufträge. Erledigte Anforderungen bleiben erhalten. Sie sind keine Bestellung und keine Ist-Kosten.

### 4.12 Aufmaße

- Aufmaße (`measurements`, `measurement_items`, `measurement_source_totals`) erfassen **Zusatzmengen** verbindlicher Positionen; der Fortschritt ist die Summe abgeschlossener Aufmaße. Positionsziele: `order|addendum : Quell-ID : Knoten-ID` (gleiches Schema wie die Zeitzuordnung der Bautagesberichte).
- Verbindliche Positionen liefert ausschließlich `Domain\Order\OrderBasisPositions` aus `orders.basis_json` und `order_addenda`; nur verkaufsseitige Werte (Menge, Einheit, `effectiveUnitPrice`), niemals Kosten. Keine Neukalkulation und kein Rückgriff auf Stammdaten.
- Bewertung: Menge × Einzelpreis, HalfUp auf Cent; Nachlass/Aufschlag je Quelldokument nur anteilig auf Normalpositionen (`subtotalNet`/`adjustmentSigned`).
- Abschluss (`measurements.complete`) vergibt die Nummer, schreibt Summen fest und ist unveränderlich. Überschreitungen der vereinbarten Menge brauchen `confirmOverrun`. Korrekturen nur über neue Korrekturaufmaße (`correction_of_id`, negative Mengen zulässig, Gesamtmenge ≥ 0).
- Die Überschreitungs- und Nullprüfung gegen abgeschlossene Mengen erfolgt im selben `BEGIN IMMEDIATE` wie der Abschluss; Entwürfe sind über `version` geschützt.

### 4.13 Rapporte

- Tabellen `work_reports`, `work_report_labor`, `work_report_materials` (Leistungsnachweis) und `work_report_billing_items` (kaufmännische Ebene). Der Leistungsnachweis enthält nie Preise; die kaufmännischen Positionen verändern ihn nie.
- **Datumslogik:** Leistungsdatum je Stundenposition (`work_report_labor.work_date`, Migration `0036`), Einreichungsdatum ausschließlich `submitted_at` (Serverzeit beim Einreichen, keine Eingabe). `work_reports.work_date/work_date_to` ist nur der aus den Positionen abgeleitete Leistungszeitraum (Listen, Suche, Sortierung); ohne Stunden bleibt das Anlagedatum. Die Prüfsumme enthält das Positionsdatum nur, wenn es gesetzt ist – historische eingereichte Rapporte (Positionen ohne Datum) behalten ihre Prüfsummenbildung und zeigen ihr Leistungsdatum im Kopf. Korrekturen übernehmen die Positionsdaten (historisch: Leistungsdatum des Originals). Die Zeiterfassung zeigt Rapportstunden am Positionsdatum (`COALESCE(l.work_date, w.work_date)`), nur als Referenz.
- Status `draft → submitted → processed`; `submitted` vergibt die Nummer (`work_report`/`RP`) und schreibt `submitted_sha256` fest. Danach sind Kopf, Stunden und Material unveränderlich; Korrekturen nur als neuer Rapport mit `correction_of_id`.
- Kundenbestätigung (`confirmation_*`) ist unabhängig vom Status, einmalig und per CHECK an `submitted_sha256` gebunden.
- Ein Rapport gehört direkt zu einem Kunden (`work_reports.customer_id`, Pflicht); `order_id` ist im Entwurf optional. **Ein Rapport darf ohne Auftrag begonnen werden; spätestens bei der Einreichung muss ein Auftrag zugeordnet oder automatisch angelegt werden** (`WorkReportService::submit`: bestehender Auftrag des Rapportkunden im Status `open`/`in_progress`, für Techniker nur freigegebene, oder `createOrder` → `OrderService::createRegie` mit „Regiearbeiten – Einsatzort/Leistungsdatum“). Diese Anlage ist das einzige Auftragsanlegen ohne `orders.write` und nur für den eigenen bearbeitbaren Entwurf und dessen Kunden zulässig. Zuordnung, Auftragsanlage, Nummernvergabe und Prüfsumme laufen in einer Transaktion (`BEGIN IMMEDIATE`, Versionsprüfung; `OrderService::create` startet in einer laufenden Transaktion keine eigene). Der Auftrag wird vor der Prüfsumme gesetzt. Korrekturen übernehmen den Auftrag des Originals. Bei Anlage mit Auftrag muss dieser zum Kunden gehören. Historische eingereichte Rapporte ohne Auftrag bleiben gültig. Nachträgliche Zuordnung nur mit `workreports.process`, nur solange `order_id` leer ist, protokolliert über `order_assigned_at`/`order_assigned_by_user_id`; Prüfsumme, Bestätigung und Abrechnung bleiben unverändert.
- Sichtbarkeit für Techniker: eigene Rapporte immer, fremde nur an freigegebenen Aufträgen, fremde eigenständige nie (Repository-Filter und `accessibleReport`). Die Kundenauswahl für Rapporte liefert nur Nummer und Name aktiver Kunden.
- Tabellenneubauten mit Kindtabellen (`ON DELETE CASCADE`): Bei aktiven Fremdschlüsseln schreibt SQLite Verweise beim Umbenennen um; das Löschen der umbenannten Alttabelle würde Kindzeilen löschen. Kindtabellen deshalb mit neu aufbauen und vor der Alttabelle entfernen (Vorbild: Migration `0026`).
- Preise, Beträge, Abrechnungspositionen und der Artikelkatalog (nur Verkaufspreise) sind ausschließlich mit `workreports.process` zugänglich – serverseitig in `WorkReportService`, nicht nur in der Oberfläche.
- Rapportstunden mit Mitarbeiter sind tatsächliche Arbeitszeit (zentrale Buchung, siehe 4.17); Arbeiten im Rapport gehören nicht zusätzlich in einen Bautagesbericht. Maßgeblich sind Entwürfe ohne Korrekturbezug und die gültige eingereichte Fassung. **Ein Korrekturrapport ersetzt seinen Vorgänger vollständig** (`superseded_by_id`, atomar beim Einreichen über `WorkReportRepository::supersede`; konkurrierende Korrekturen scheitern). Nur die gültige Fassung wird korrigiert. Ersetzte Fassungen bleiben vollständig erhalten (Nummer, Inhalt, PDF, Prüfsumme, Bestätigung), zählen aber nicht. Positionen werden per Upsert gespeichert (stabile IDs); Stunden in ganzen Minuten. Keine Bindung an Angebotspositionen.

### 4.14 Dokumentausgabe und Kundenbestätigung

- PDFs entstehen ausschließlich mit `SimplePdf`; neue Dokumentarten verwenden den gemeinsamen Briefbogen `Application\LetterheadPdf` (Firmendaten nur aus den Dokumenteinstellungen). Das Wasserzeichen „ENTWURF“ gehört nur auf Entwürfe.
- Kundenfähige Leistungsnachweise (Rapport-PDF, Kundenansicht zur Unterschrift) enthalten nie kaufmännische Positionen, Verkaufspreise oder Kosten. Bautagesbericht-PDFs enthalten keine Kostensätze und keine Materialanforderungen.
- Verbindliche PDFs (eingereichte Rapporte, abgeschlossene Aufmaße) über `DocumentArchive` je fachlichem Stand (`source_sha256`) einmal erzeugen und danach unverändert ausliefern; nie überschreiben. Entwürfe werden nicht archiviert.
- Bestätigungsarten von Rapporten: `signature` (Unterschriftsbild), `returned_document` (hochgeladenes Original), `manual` (ohne Nachweis, ausdrücklich gekennzeichnet). Eine Unterschrift wird nur mit passender `submittedSha256` angenommen; jede Bestätigung ist einmalig und per CHECK an `submitted_sha256` gebunden. „Versendet“ (`confirmation_requested_*`) ist keine Bestätigung.
- Nachweise und archivierte PDFs liegen unter `APP_DOCUMENTS_PATH/work-reports/<rapport>/` bzw. `measurements/<aufmaß>/`, Auslieferung nur über geschützte Routen (`FileResponse`). Rücklauf-Upload und „versendet“ erfordern `workreports.process`; Unterschrift erfassen dürfen Ersteller und Büro.

### 4.15 Bestellungen (Phase 3.4.1, vorgangsorientiert seit 3.4.1.1)

- Eine Bestellung ist Beschaffung beim Lieferanten: intern (Entwurf → „Bestellen“ mit archiviertem PDF je Fassung) oder extern erfasst (sofort „Bestellt“, ohne Pflicht zu Positionen). Zwei getrennte Anlegedialoge; Lieferanten können darin mit demselben Stammdatenmodell (`MasterDataService::createSupplier`, keine Doppelanlage gleichen Namens) angelegt werden. Eine Übernahme aus Materialanforderungen ist bewusst gestrichen und darf nicht wieder eingeführt werden.
- Nach dem Bestellen folgen unveränderliche Ereignisse: `purchase_order_confirmations` (genau eine `is_current`), `purchase_order_deliveries`, `purchase_order_invoices` (Belegarten), `purchase_order_payments`. `delivery_status`/`payment_status` sind abgeleitet (Status des zuletzt erfassten Ereignisses); `invoiced_net_total` ist abgeleitet. Alles wird in `purchase_order_history` protokolliert, ohne Beträge im Text.
- Kostenwirkung der Belege ausschließlich über `Domain\Purchase\PurchaseCostCalculator`; Belege nie löschen (Korrektur über Gutschrift/Stornobeleg). Ist-Kosten: `actual_net_total` **oder** Belege – nie beides. Ursprünglicher Bestellwert, bestätigter Wert und Ist-Kosten bleiben getrennt. Kosten je Auftrag nur über `PurchaseOrderService::costsByOrder`. Gespeicherte Verteilungen ≤ Ist-Kosten; sinken die Ist-Kosten später, wird die Verteilung als ungültig gekennzeichnet (`allocationExceeded`, Verlauf), nie still gekürzt.
- Ausgegebene PDFs (`kind = own_order`) und Belege von Vorgängen nie überschreiben oder löschen.
- Rechte: `purchases.read`/`purchases.write`; Beträge, Preise, Belege und Zahlungen zusätzlich `costs.read` (serverseitig gefiltert). Dateien unter `APP_DOCUMENTS_PATH/purchase-orders/<bestellung>/`.

### 4.16 Dokumente und Anhänge (Phase 3.4.2)

- Calight ist kein DMS. Fachdokumente bleiben in ihren Modulen; `DocumentService` liefert nur eine Leseprojektion mit Links auf die Download-Routen der Module. Keine Kopien, keine Verschiebung bestehender Dateien.
- Allgemeine Anhänge: `attachments` + `attachment_links` (Kunde, Ansprechpartner, Projekt, Angebot, Auftrag); Datei einmal unter `attachments/JJJJ/MM/<uuid>.<ext>`.
- Rechte: `documents.read` für Übersicht und Dokumentbereiche; je Quelle zusätzlich das Leserecht des Fachmoduls (Lieferantenrechnungen und Bestell-PDFs `costs.read`). Anhänge nur sichtbar, wenn **alle** zugeordneten Objekte lesbar sind; Schreiben erfordert Schreibrecht auf jedes Ziel. Neue Dokumentquellen (z. B. Lieferscheine) als weitere Methode in `DocumentRepository`/`DocumentService` ergänzen.

### 4.17 Zeiterfassung (Phase 3.4.3)

- **Zentrale Stundenbasis (Migration `0037`):** `time_entries` ist die einzige maßgebliche Basis tatsächlich geleisteter Mitarbeiterstunden. Herkunft `source_kind` = `work_report` (je Rapport-Stundenposition genau eine Buchung, `work_report_labor_id` UNIQUE), `daily_report` (`daily_report_entry_id` UNIQUE) oder `manual`. Berichtsbuchungen pflegt ausschließlich `ReportTimeBookings` in der Transaktion des Berichtsdienstes; ihre Dauer kommt aus dem Bericht und ist in der Zeiterfassung nicht änderbar. Ergänzt werden nur Beginn/Ende/Pause (Ende − Beginn − Pause muss exakt passen, DB-CHECK), Reisezeit (Teil der Nettozeit) und Einsatzart. Ändern sich Dauer, Datum oder Mitarbeiter im Bericht, werden ergänzte Uhrzeiten entfernt (nie erfunden). Uhrzeiten sind optional (`start_time/end_time` NULL); Betriebseinstellung `time_tracking.times_optional` (`operational_settings`, nur `operations.manage`) bestimmt, ob Buchungen ohne Uhrzeiten vollständig sind. Weitere Tabellen: `time_day_details`, `time_private_trips`, `time_expenses` (Beleg unter `expenses/`). Die frühere Quellenwahl und die Altfallangaben wurden mit Migration `0038` entfernt; es gibt keine konkurrierenden Stundenquellen. **Rapporte, Bautagesberichte und manuelle Zeiterfassung erzeugen gemeinsam genau eine zentrale Ist-Stundenbasis.** `daily_reports.labor_counts_for_order`/`labor_assigned_*` bestehen nur noch aus Schemagründen und werden nicht ausgewertet. Keine Lohn-, Steuer- oder vollständige Reisekostenabrechnung. Tagesanteile über Mitternacht nur über `TimeTrackingService::split` (ohne Uhrzeiten: ganz am Buchungstag).
- **Einreichung und Festschreibung (Migration `0042`):** Verbindlich ist der Status je Mitarbeiter und Kalendertag (`time_day_locks`: ohne Eintrag offen, `submitted`, `approved`, `reopened`). Woche (ISO-KW) und Monat sind Sammelaktionen (`TimeApprovalService`): Eine Einreichung sperrt die noch offenen bzw. geöffneten Tage ihres abgeschlossenen Zeitraums – auch arbeitsfreie; bereits eingereichte/festgeschriebene Tage bleiben unverändert. Die Sperre greift **zentral per Datenbank-Trigger** auf `time_entries` (alter und neuer Tag, Folgetag über Mitternacht, auch Kaskaden aus Tages- und Rapportpositionen), `time_day_details` und `time_expenses`; der Abbruch `calight_time_locked` wird in `Application` zu einer 422-Meldung. Auch Büro und Administrator müssen zuerst wieder öffnen. Prüfungen: Sperrfehler (Überschneidungen, fehlende Pflicht-Uhrzeiten, ungültige Intervalle/Reisezeit, inkonsistente Berichtsbuchungen, Stunden aus normalen Rapportentwürfen, unzulässiger Zeitraum) verhindern Einreichung und Festschreibung; Warnungen (Tagesarbeitszeit, Pausen, ununterbrochene Arbeitszeit, Ruhezeit, Sonn-/bundesweite Feiertage, leere Werktage, fehlende Zuordnung, Uhrzeiten fehlen, Reiseangaben, offene Korrekturentwürfe) muss das Büro gesammelt bestätigen. Grenzwerte sind Betriebseinstellungen (`time_limits.*`). Festschreibung erzeugt atomar einen unveränderlichen PDF-Stundennachweis mit festem Datenstand (`time_statements`, Trigger gegen Änderung/Löschung, Datei unter `time-statements/<Mitarbeiter>/`); ein vollständig über Wochen festgeschriebener Monat erhält automatisch seinen Monatsnachweis. Wiederöffnen/Zurückgeben nur mit Begründung (`time_lock_events`); betroffene Wochen- und Monatsnachweise werden „ersetzt“, nach erneuter Festschreibung entsteht die nächste Revision.
- **Reisekosten und Auslagen (Migration `0043`):** Je Mitarbeiter und Tag (`time_day_details`): Kategorie der Auswärtstätigkeit (`none`, `single_short`, `single_long`, `arrival`, `full`, `departure`), Inland/Ausland, optionale Abwesenheitsdauer, Übernachtung, gestellte Mahlzeiten, Einsatzort, Bemerkung. Mehrere dienstliche Privat-Pkw-Fahrten je Tag (`time_private_trips`: Kilometer, Strecke, Zweck, optional Auftrag ODER Kostenstelle). Auslagen unverändert (`time_expenses`, ein Belegspeicher). Die Verpflegungspauschale ist ein **rechnerischer Referenzbetrag** (`TravelAllowance`): ausschließlich aus Kategorie, Mahlzeiten und Betriebseinstellungen (`travel.*`, Standard nach § 9 EStG: 14/14/28/14 €, Kürzung 20/40/40 % der vollen Tagespauschale, nie negativ, Cent-Arithmetik), **nie aus der Arbeitszeit**; Ausland wird dokumentiert, aber nicht berechnet. Kilometerbetrag = km × `travel.km_rate`. Keine Beträge in PHP/JS verdrahten, keine Auszahlung oder Lohnbuchung. Alle Reiseangaben unterliegen der Tagessperre (Trigger) und erscheinen mit Summen im festgeschriebenen Nachweis (Werte zum Zeitpunkt der Festschreibung).
- Ist-Stunden eines Auftrags ausschließlich über `TimeTrackingService::actualHoursForOrder` aus der zentralen Basis: alle Buchungen mit diesem Auftrag (alle Herkünfte, jede genau einmal). Keine zusätzliche Summierung aus den Berichtstabellen. Korrekturrapporte: Nur die gültige Fassung hat Buchungen; der Zustand „ersetzt“ (`superseded_by_id`) ist reguläre Fachlogik. Allgemeine Zeiten ohne Auftrag werden nie einem Auftrag zugeschlagen. Auslagen und Kilometer werden nicht bewertet.
- Rechte: `timesheet.own` (eigener Mitarbeiterdatensatz über `employees.user_id`, nur aktiv, Techniker nur freigegebene Aufträge), `timesheet.manage` (alle Mitarbeiter, Korrektur, Auswertung). Kostensatz-Snapshots nur mit `costs.read`.

### 4.17a Allgemeine Kostenstellen und gemeinsames Kostenziel (Migration `0039`)

- `cost_centers` (Stammdaten → Kostenstellen): dauerhafte, eindeutige Nummer ohne Jahresbezug (z. B. `K100`, Eindeutigkeit ohne Groß-/Kleinschreibung), Bezeichnung, Beschreibung, aktiv/inaktiv. Verwendete Kostenstellen werden nie gelöscht, nur deaktiviert; deaktivierte bleiben in bisherigen Zuordnungen und Auswertungen. Pflege nur mit `cost_centers.manage` (Administratoren), Übersicht mit `master_data.read`, Beträge nur mit `costs.read` (`CostCenterService`). Die Beispielliste K100–K190 liegt in `config/cost-center-template.json` und wird nur auf ausdrücklichen Wunsch übernommen (idempotent, vorhandene Nummern unverändert); keine automatische Anlage.
- **Grundregel:** Jede Arbeitszeitbuchung und jede kostenrelevante Zuordnung hat höchstens ein Kostenziel – `order_id` ODER `cost_center_id` (DB-CHECK). Betroffen: `time_entries` (manuelle Buchungen und – seit `0040` – Bautagesberichtstunden, die dem Kostenziel ihres Berichts folgen; Rapportstunden nie, DB-CHECK), `daily_reports` (`0040`: Auftrag ODER Kostenstelle ODER keines; der Kunde ist optional (seit `0041`) und kein Kostenziel; Zielwechsel über `DailyReportService::assignOrder`, protokolliert in `daily_report_order_events` inkl. Kostenstelle), `time_expenses`, `purchase_orders` und `purchase_order_allocations` (je Verteilzeile genau ein Ziel). Auftrags-Ist-Stunden und Auftragskosten berücksichtigen nur `order_id`.
- **Gemeinsames Suchfeld „Auftrag / Kostenstelle“:** `CostTargets` liefert die Auswahl (Aufträge mit den Freigaberegeln des Fachmoduls plus Kostenstellen, ohne Beträge) und prüft die Auswahl einheitlich (neu nur aktive Kostenstellen, nie beides). Im Client `Calight.costTargetOptions/costTargetSelect/parseCostTarget` auf Basis von `searchableSelect`; der Wert trägt Typ und ID (`order:<id>`/`cost_center:<id>`), nie wird anhand des Nummernpräfixes entschieden.
- Keine Buchhaltung: keine Konten, Steuern, Umlagen, Zuschläge oder Kostenstellenhierarchie. Bestellkosten je Kostenstelle nutzen dieselbe Ist-Kosten-Regel wie Aufträge (`PurchaseOrderService::actualCostOf`).

### 4.18 Fachliche Abgrenzung späterer Module

Folgende Punkte sind Zielbild, soweit nicht durch aktuelle Migrationen/Code implementiert:

- **Tagesbericht:** interne Dokumentation tatsächlicher Arbeiten und Termine; kein abrechenbarer Leistungsnachweis. Ein Auftrag ist optional, auch dauerhaft. Die organisatorische Zuordnung zu einem Auftrag ist nicht gleichbedeutend mit der Zuordnung seiner Zeiten als Auftragskosten.
- **Rapport (Abgrenzung):** abrechenbarer Leistungsnachweis; neue eingereichte Rapporte benötigen einen Auftrag (siehe 4.13).
- **Aufmaß:** seit Phase 3.3 implementiert (siehe 4.12); Nachweis ausgeführter Mengen vereinbarter Leistungen, keine Rechnung und kein Materialverbrauch. Die PDF-Ausgabe folgt in Phase 3.2.3.
- **Rapport:** seit Phase 3.4 implementiert (siehe 4.13); Nachweis abrechenbarer Regieleistungen, nicht identisch mit internen Zeiten. PDF, Unterschrift und Übermittlung folgen in Phase 3.2.3.
- **Nachkalkulation:** späterer Soll-Ist-Vergleich aus unveränderlichen Grundlagen, Ist-Kosten und separat erfasster Erlösseite.
- **Abrechnungstracking:** später nur Referenzen auf extern erstellte Rechnungen und Status. Keine Faktura oder Zahlungsbuchhaltung.
- Artikelgenauer Materialverbrauch, Lager und Wareneingang, CardDAV und eine eigene mobile Monteuransicht sind noch keine implementierten Prozesse, solange Code und Migrationen nichts anderes zeigen. Materialanforderungen und Berichtsanhänge sind seit Phase 3.2.2 implementiert.

**Ein Bautagesbericht ist kein Rapport. Ein Aufmaß ist keine Rechnung. Calight ersetzt keine Rechnungsstellung.** Künftige Module dürfen diese Datenarten nicht aus Bequemlichkeit vermischen.

## 5. Sicherheit, Rechte und Konkurrenzschutz

### Rollen und Berechtigungen

`Security\RolePermissions` definiert feste Gruppen:

- `admin`: alle Rechte (`*`);
- `office`: Kunden, Projekte, Angebote, Aufträge, Stammdaten, Kosten, Einstellungen, Mitarbeiter und Berichte gemäß Matrix;
- `technician`: Dashboard, freigegebene Aufträge und Berichte; keine vertraulichen Kosten.

Die historische Rolle `employee` wird nur für bereits bestehende PHP-Sessions als Vollzugriff toleriert; Datenbankrollen sind `admin`, `office`, `technician`. Migration `0020` hat bestehende Benutzer zu Administratoren übernommen, um Rechteverlust zu verhindern.

Verbindliche Regeln:

- `AuthorizationPolicy` ist die zentrale HTTP-Rechtematrix. Neue Routen MÜSSEN dort passend eingeordnet werden.
- Servicebezogene Zusatzregeln, etwa „Techniker nur eigene Berichte“, bleiben serverseitig im zuständigen Service.
- UI-Ausblendung ist Komfort, niemals Sicherheitsgrenze.
- Fehlende Rechte liefern für APIs HTTP 403 mit „Keine Berechtigung“.
- `ConfidentialDataFilter` und gezielte Response-Filter schützen Kosten, Deckungsbeiträge und Kalkulationsdetails. Neue Listen-/Export-APIs müssen auf Datenlecks geprüft werden.
- Keine parallele improvisierte Rollenmatrix in Controller oder JavaScript anlegen.

### Authentifizierung und Konten

- Bestehende Session-Authentifizierung weiterverwenden, keine zweite Authentifizierung einführen.
- Nur aktive Benutzer können angemeldet bleiben; `Application::handle` aktualisiert Sitzungsrolle/-name aus der Datenbank.
- Login regeneriert die Session-ID. Cookies sind `HttpOnly`, `SameSite=Lax` und bei `APP_SECURE_COOKIES=1` sicher.
- Passwörter werden mit PHP `password_hash`/`password_verify` verwaltet; CLI-Mindestlänge ist acht Zeichen.
- Das letzte aktive Administratorkonto darf nicht deaktiviert oder herabgestuft werden.
- Zugangsdaten, Hashes und Sessions dürfen weder geloggt noch exportiert werden.

### CSRF, Validierung und SQL

- Jede zustandsändernde Browser-/API-Anfrage benötigt den bestehenden CSRF-Schutz. JSON-APIs verwenden üblicherweise `X-CSRF-Token`.
- Eingaben serverseitig validieren; `required`, Clientprüfungen und Modale verbessern nur die Bedienung.
- SQL-Werte über vorbereitete PDO-Abfragen binden. Keine Nutzereingaben in SQL konkatenieren.
- HTML über `$escape` ausgeben. Nur bewusst erzeugtes, geprüftes Markup darf ungeescaped sein.
- API-Fehlercodes unterscheiden: 401 Anmeldung, 403 Rechte, 409 Fach-/Versionskonflikt, 419 CSRF, 422 Validierung, 423 Sperre, 428 fehlende Vorbedingung, soweit bestehender Vertrag.

### Bearbeitungssperren und ETags

- Angebotsentwürfe verwenden eine kurzlebige serverseitige Bearbeitungssperre (`document_locks`, derzeit 120 Sekunden).
- Nur der Eigentümer mit gültigem Lock-Token darf schreiben; aktive Bearbeitung verlängert die Sperre. Abgelaufene Sperren dürfen nicht dauerhaft blockieren.
- Nur Administratoren dürfen eine fremde Sperre bewusst übernehmen. Die eigene Sperre ist kein Übernahmefall.
- Sperren gelten pro Dokument, niemals pauschal für den gesamten Auftrag.
- ETag/`If-Match`, Datenbankversion und Dateisperre bleiben zusätzlicher Optimistic-Locking-Schutz. Eine Bearbeitungssperre ersetzt ETags nicht.
- Bautagesberichte verwenden Versionsprüfung, damit mehrere Benutzer verschiedene Berichte desselben Auftrags parallel bearbeiten können.

## 6. Datenbank, Dateien und Migrationen

- SQLite-Verbindungen aktivieren Fremdschlüssel, `busy_timeout=5000` und WAL-Modus.
- Migrationen sind lexikografisch sortierte, versionierte SQL-Dateien in `app/migrations`; `schema_migrations` protokolliert jede Anwendung.
- Neue Schemaänderungen ausschließlich als nächste additive Migration anlegen. Bereits veröffentlichte Migrationen nicht nachträglich verändern.
- Migrationen müssen in einer Transaktion laufen, vorhandene Daten übernehmen und Fremdschlüssel/Indizes/Checks bewusst behandeln.
- Für komplexe Tabellenneubauten unter SQLite bestehende Beziehungen, Self-References und `PRAGMA defer_foreign_keys` prüfen; Migration `0018` ist ein relevantes Beispiel.
- Keine produktive Datenbank löschen, zurücksetzen, leeren oder als Experiment migrieren.
- Migrationen niemals eigenständig gegen eine produktive Installation ausführen. Nur den erforderlichen Befehl dokumentieren.
- Tests dürfen ausschließlich temporäre isolierte SQLite-Dateien und temporäre Angebotsverzeichnisse verwenden.
- Entwürfe, Snapshots und PDFs nicht durch Reparaturskripte stillschweigend verändern.
- Atomare Dateischreibweise, Prüfsummen und Transaktionsgrenzen bei kombinierten Datei-/DB-Vorgängen erhalten. Fehlschläge müssen aufräumen oder zurückrollen.
- Löschregeln bewusst wählen: Geschäfts- und Archivdaten meist `RESTRICT`; abhängige reine Bearbeitungsdaten nur bei fachlich sicherem Besitz `CASCADE`.

Vor jeder Migration sind Upgrade-Pfad, Datenübernahme, Downtime/Locking, eindeutige Indizes und Verhalten alter Datensätze zu prüfen.

## 7. Frontend- und UI-Konventionen

### Gemeinsame Bausteine

- `app/templates/layout/app.php`: Topbar, berechtigungsabhängige Sidebar, Flashmeldungen, globale Assets.
- `app/public/assets/app.css`: zentrale Variablen, Buttons, Karten, Tabellen, Formulare, Breadcrumbs, Badges, responsive Regeln.
- `app/public/assets/calight.js`: gemeinsame Fetch-/Form-/Format-Hilfen; vor neuer Logik prüfen.
- `app/public/assets/modal.js`: globales natives `<dialog>` mit Fokusmanagement, Tastaturbedienung, Validierung und Dirty-Guard.
- `app/public/assets/list-view.js`: persistenter Karten-/Tabellenumschalter und zugängliche Zeilen-/Kartennavigation.
- `app/public/assets/ui.js`: Lucide-Icon-Dekoration, Aktionsmenüs, globale Löschbestätigung und `Strg/Cmd+S`.
- Lokale Lucide-SVGs liegen in `app/public/assets/icons/lucide`; keine CDN-Icons.

Vor einer neuen UI-Komponente vorhandene Helfer und CSS-Klassen durchsuchen und wiederverwenden.

Verbindliche Regeln für Buttons, Menüs, Suchfelder, Formulare, Status, Dialoge und responsive Darstellung stehen in [`docs/ui-guidelines.md`](docs/ui-guidelines.md). Stammdatensuche mit Vorschlägen nutzt `Calight.suggest`.

### Übersichts- und Detailseiten

Der aktuelle Standard aus Phase 3.2.1.2 gilt für Kunden, Projekte, Angebote, Aufträge und Bautagesberichte:

- Übersichten nutzen die volle Inhaltsbreite für Suche, Filter, Sortierung und Ergebnisse.
- Kein dauerhaft sichtbares großes Erstellungs-/Bearbeitungsformular neben der Liste.
- Einheitlicher Seitenkopf mit Beschreibung und primärer „Neu“-Aktion.
- Kompakte Neuanlage im globalen Dialog; komplexe Bearbeitung auf eigener Detailseite.
- Tabellenansicht ist Desktop-Standard, Kartenansicht Mobil-Standard; bewusste Auswahl wird pro Benutzer/Modul in `localStorage` gespeichert.
- Karten und Tabellenzeilen dürfen den Datensatz öffnen. Redundante „Öffnen/Ansehen“-Aktionen vermeiden.
- Aktionen innerhalb klickbarer Zeilen müssen die Zeilennavigation stoppen.
- Bei vielen Nebenaktionen ein bestehendes Drei-Punkte-/Kontextmenü verwenden.
- Breadcrumbs sind echte Links; letzter Eintrag ist die aktuelle Seite.
- Leere, Lade-, Fehler-, Erfolg- und deaktivierte Zustände müssen verständlich sein.

### Listenstatus, Pagination und Sortierung

Seit Phase 3.2.1.3 gilt für die fünf Übersichten:

- Suche, Filter, Sortierung und Pagination laufen **serverseitig**. Keine Liste lädt alle Datensätze, um im Browser zu filtern oder zu blättern.
- Backend: `Support\ListQuery` validiert Parameter (`q`, Modulfilter, `sort`, `dir`, `page`, `per_page` mit 10/25/50/100), `Infrastructure\Persistence\Paginator` baut Zähl- und Seitenabfrage. Jedes Repository definiert seine sortierbaren SQL-Ausdrücke als Whitelist (`*_SORTS` mit natürlicher Richtung) und einen eindeutigen Tie-Breaker. Nutzereingaben gelangen nur als gebundene Parameter in SQL.
- Listen-APIs antworten mit `{data: {items, total, page, perPage, pageCount, sort, dir}}`; zu große Seitenzahlen werden auf die letzte Seite begrenzt.
- Textsuche nutzt die in `ConnectionFactory` registrierte SQLite-Funktion `calight_fold()` für Unicode-gerechte Groß-/Kleinschreibung. Direkt erzeugte PDO-Verbindungen ohne diese Funktion können die Listensuche nicht ausführen.
- Werte aus Dokument-JSON, die gelistet oder sortiert werden (Angebotstitel und -nettosumme), werden in SQLite gespiegelt: `offers.list_*` wird über `list_etag` als veraltet erkannt und von `OfferService` beim Listenabruf aktualisiert. Für sortierbare Geldwerte nur zum Ordnen `CAST(... AS REAL)` verwenden; angezeigte Beträge bleiben exakte Dezimalstrings.
- Frontend: `CalightListView.createListPage` in `list-view.js` hält URL, Bedienelemente, Browser-History, Sortierköpfe (`header()`), Sortierauswahl und Pagination synchron. Module liefern nur Endpunkt, Filterfelder, Sortierdefinitionen und Tabellen-/Karteninhalt.
- Die URL ist die maßgebliche Quelle des Listenstatus; Standardwerte stehen nicht in der URL. Nur die Tabellen-/Kartenansicht liegt in `localStorage`. Suche erzeugt pro Eingabe höchstens einen History-Eintrag; Filter, Sortierung und Seitenwechsel sind über Zurück/Vorwärts nachvollziehbar.
- Neue Sortierfelder müssen im Repository (`*_SORTS`) und in der Moduldefinition im JavaScript (`sorts`) mit gleicher natürlicher Richtung ergänzt werden.

### Buttons, Icons und Status

- Primäre Aktionen: `.button` (blau). Sekundäre Aktionen: `.button-light`/`.table-action`.
- Destruktive Aktionen: weiß/rot umrandet (`.button-danger-light`, `.danger-text`, `.icon-button-danger`), **niemals dunkelblauer Hintergrund**.
- Reine Kopier- und Löschaktionen dürfen kompakte Icon-Buttons ohne Text verwenden, benötigen aber `aria-label` und `title`.
- „Bearbeiten“ verwendet das lokale Lucide-Icon `square-pen`.
- Gleiche Aktion erhält überall dasselbe Icon und dieselbe deutsche Beschriftung.
- Status mit `.status-badge` und vorhandenen Statusklassen darstellen; fachliche Statuswerte nicht nur für Farben umbenennen.
- Buttons einer Zeile/Karte rechts ausrichten, wenn das gemeinsame Layout dies vorsieht.
- Alle Lösch-/Entfernen-Aktionen müssen über `CalightModal.confirm` beziehungsweise den globalen Interceptor bestätigt werden. Kein natives `confirm()`/`alert()` einführen.

### Formulare und Zugänglichkeit

- Labels, Pflichtstern und Eingaben so ausrichten, dass Felder derselben Zeile gleich hoch starten.
- Zusammengehörige Felder semantisch gruppieren; lange Formulare gehören auf Detailseiten, nicht in überladene Modale.
- Native Elemente und sichtbare Fokuszustände beibehalten. Icon-only-Buttons brauchen zugängliche Namen.
- Escape, Fokus-Rückgabe, Fokusfalle und Schutz ungespeicherter Dialogwerte über das globale Modal lösen.
- `Strg+S`/`Cmd+S` über vorhandene globale Logik integrieren, nicht pro Seite neu erfinden.
- Das wiederverwendbare Schrittdesign verwendet `.step-section-heading`, `.step-section-number` und `.step-section-actions`.
- Responsive Verhalten ohne globales horizontales Seiten-Overflow; Tabellen dürfen in ihrem eigenen Wrapper horizontal scrollen.
- Keine unnötigen Inline-Styles, duplizierten CSS-Blöcke oder seitenindividuellen Varianten bei vorhandener gemeinsamer Lösung.

### Angebotseditor

- Bestehenden Editor und seine Zustandsverwaltung erweitern, keinen zweiten Editor bauen.
- Verschachtelung, Drag-and-drop, Mehrfachaufklappen, Suche, Undo/Redo, Dirty-State, Lock und Read-only-Modus erhalten.
- Reine UI-Zustände wie Aufklappen und Filtern gehören nicht in Angebots-JSON oder Undo-History.
- Snapshot-basierte Undo/Redo-History bleibt browserlokal und ist kein Auditlog.
- Abgegebene/angenommene/abgelehnte Dokumente sauber read-only rendern; keine verbliebenen Event-Handler dürfen lokale Inhaltsänderungen verursachen.
- Serverberechnung bleibt autoritativ; asynchrone Antworten dürfen neueren oder wiederhergestellten Zustand nicht überschreiben.

## 8. Coding- und Architekturregeln

### Verbindlich für neuen oder geänderten Code

- `declare(strict_types=1);` in PHP-Dateien beibehalten.
- Verständliche Klassen-, Methoden- und Variablennamen verwenden.
- PHP möglichst PSR-12-konform und JavaScript/CSS konsistent formatieren.
- Keine minifizierten, extrem langen oder dicht aneinandergeketteten neuen Quellcodezeilen.
- Kleine, klar abgegrenzte Funktionen; Kommentare erklären fachliche Gründe und Sicherheitsgrenzen, nicht triviale Syntax.
- Bestehende Services, Repositories, Controller, Domain-Logik und UI-Helfer wiederverwenden.
- Fachlogik nur einmal implementieren. PDF, Listenansicht und Frontend konsumieren Ergebnisse, berechnen sie nicht unabhängig neu.
- Keine unnötigen globalen Zustände. Bestehende globale Browser-Helfer (`CalightModal`, `CalightListView`) nur gezielt erweitern.
- Exceptions und HTTP-Antworten müssen verständliche deutsche Meldungen liefern, ohne interne Pfade oder Geheimnisse preiszugeben.
- Öffentliche Verträge (JSON, APIs, gespeicherte Dokumente) kompatibel erweitern oder eine ausdrücklich geplante Migration vorsehen.

Im Bestand existieren einige sehr kompakte Einzeiler, besonders in jüngeren PHP-/JavaScript-Dateien. Das ist technischer Bestand, **kein Stilvorbild**. Bei gezielten Änderungen darf der unmittelbar betroffene Bereich lesbarer formatiert werden; keine globale Formatierungs- oder Refactoring-Aktion ohne Auftrag.

### Änderungsscope

- Nur den für die Aufgabe erforderlichen Bereich verändern.
- Keine „bei Gelegenheit“-Umbauten oder neue allgemeine Engines ohne nachgewiesenen Nutzen.
- Große Klassen nur gezielt refaktorieren, wenn die konkrete Aufgabe dadurch sicherer oder deutlich wartbarer wird.
- Keine grundlegende Architekturentscheidung eigenmächtig ändern.
- Bei unklarer Fachentscheidung zuerst Code, Tests, Git-Historie und Dokumentation prüfen; verbleibende Entscheidung offen benennen.

## 9. Tests und Qualitätssicherung

### Vorhandene Struktur

- `app/tests/run.php` ist ein projektspezifischer Testläufer, kein PHPUnit.
- `app/tests/Unit` prüft Kalkulation, Validierung, Normalisierung, Sicherheit und PDF-Bausteine.
- `app/tests/Integration` prüft Migrationen, Persistenz, Stammdaten, Kunden/Projekte, Angebotsabgabe/-revision, Aufträge, Nachträge, Rechte/Sperren und Bautagesberichte.
- `app/tests/js` enthält Node-basierte JavaScript-Tests.
- Composer-Script `test` ruft `php tests/run.php` auf.
- GitHub Actions installiert Abhängigkeiten, validiert Composer, prüft PHP-Syntax, führt PHP-Tests und JS-Syntax aus, validiert Compose und baut das Image.

### Verbindliche Agentenregel

**Entwicklungsagenten dürfen PHP-Unit- und PHP-Integrationstests in diesem Projekt nicht eigenständig ausführen.** Das gilt auch für `composer test`, `php app/tests/run.php` und indirekte Befehle, die diesen Läufer starten. Der Projektinhaber führt sie in seiner Entwicklungsumgebung aus.

Agenten SOLLEN relevante PHP-Tests erstellen oder aktualisieren, sofern eine Fachänderung dies erfordert, aber sie als **nicht ausgeführt** melden.

Zulässig, sofern Werkzeuge vorhanden und die Aufgabe nichts anderes sagt:

```bash
find app/public/assets -name '*.js' -print0 | xargs -0 -n1 node --check
node --test app/tests/js/*.test.js
composer validate --strict --working-dir=app
docker compose config --quiet
git diff --check
```

Eine PHP-Syntaxprüfung mit `php -l` ist eine statische Prüfung, kein PHP-Test, und darf grundsätzlich ausgeführt werden. Bei einer reinen Dokumentationsaufgabe ist sie unnötig.

Keine erfolgreichen Browser-, PHP-, Docker- oder Datenbankprüfungen behaupten, wenn sie nicht tatsächlich liefen. Nicht verfügbare Werkzeuge, ausgelassene PHP-Tests und noch manuell zu prüfende Abläufe im Abschlussbericht nennen.

### Testdaten

- Tests müssen temporäre Verzeichnisse und isolierte SQLite-Datenbanken erzeugen und zuverlässig aufräumen.
- Niemals `database/app.sqlite`, Docker-Volumes oder konfigurierte Produktionspfade für Tests verwenden.
- Kritische Regeln serverseitig testen: Rechte, CSRF/Lock-Voraussetzungen, ETags/Versionen, Transaktionen, Unveränderlichkeit, Rundung und vertrauliche Daten.

## 10. Git und Arbeitsablauf

### Vor Beginn

1. `git status --short` prüfen.
2. Aktuellen Branch und relevante Historie prüfen.
3. Uncommittete Änderungen als Eigentum des Benutzers behandeln.
4. Betroffene Dokumentation, Codepfade, Migrationen und Tests lesen.

### Während der Arbeit

- Fremde Änderungen nicht überschreiben oder „aufräumen“.
- Keine destruktiven Befehle wie `git reset --hard`, `git clean -fd` oder unaufgefordertes Checkout/Restore.
- Keine produktiven Daten, Volumes, `.env` oder Zugangsdaten verändern.
- Änderungen in nachvollziehbaren Schritten implementieren und fokussierte Diffs prüfen.
- Bei einem unerwartet schmutzigen oder überlappenden Bereich stoppen und den Konflikt melden, statt zu raten.

### Abschluss

- `git diff` und `git diff --check` prüfen.
- Zulässige Prüfungen ausführen; PHP-Tests ausdrücklich dem Besitzer überlassen.
- Kleine, logisch abgegrenzte lokale Commits bevorzugen.
- Nur committen, wenn der Auftrag dies verlangt oder die laufende Aufgabenvereinbarung es ausdrücklich vorsieht.
- **Niemals eigenständig pushen.** Ein Push ist nur nach ausdrücklicher aktueller Anweisung zulässig.
- Abschlussbericht nennt Änderungen, Prüfungen, ausgelassene Prüfungen, Migrationen, manuelle Checks, Commit-Hash und verbleibenden Git-Status.

Empfohlener Ablauf größerer Aufgaben:

1. Ist-Zustand und Datenverträge analysieren.
2. Fachliche Anforderungen und Sicherheitsgrenzen klären.
3. Wiederverwendbare Komponenten identifizieren.
4. Datenmodelländerungen zuerst migrationssicher planen.
5. Backendregeln und danach UI implementieren.
6. Relevante Tests ergänzen, aber PHP-Tests nicht ausführen.
7. Zulässige statische/JS-Prüfungen durchführen.
8. Diffs, Datenlecks, Rechte und Kompatibilität kontrollieren.
9. Lokal committen, falls beauftragt.
10. Ehrlich und knapp berichten.

## 11. Zusammenarbeit verschiedener Entwicklungsagenten

- Das Repository ist die gemeinsame technische Wissensgrundlage. Keine Kenntnis früherer Chats voraussetzen.
- Vorhandene Entscheidungen und Komponenten zuerst verstehen; nicht bei jedem Agentenwechsel neu entwerfen.
- Widersprüche zwischen Code, Tests und Dokumentation offen benennen.
- Keine parallelen Projektregeln, Rechte- oder Berechnungsmodelle einführen.
- Git-Historie und uncommittierte Änderungen respektieren.
- Bei Arbeitsteilung Zuständigkeiten und Dateibereiche klar trennen. Jeder Agent prüft dennoch die für seine Änderung geltenden Regeln selbst.
- Übergaben müssen konkrete geänderte Pfade, nicht ausgeführte Prüfungen und offene Risiken nennen.
- Autonomie ist erwünscht, solange fachliche Integrität, Sicherheit, Kompatibilität und der vereinbarte Scope eingehalten werden.

## 12. Typische Fehler, die zu vermeiden sind

- Dokumentations-Roadmap als aktuellen Implementierungsstand behandeln, ohne Code/Migrationen zu prüfen.
- `projectId` wieder verpflichtend machen oder Dummy-Projekte anlegen.
- Angebotsnummer beim Entwurf verbrauchen.
- Abgegebene Snapshots/PDFs neu berechnen oder überschreiben.
- Stammdatenpreise beim Öffnen automatisch in Dokumente übernehmen.
- Floats für Geld, Mengen, Zuschläge oder Stundenkosten verwenden.
- Bedarf/Alternative in reguläre Summen einrechnen.
- Nachtrag wie eine normale Revision behandeln oder doppelt in den Auftrag übernehmen.
- Auftragsgrundlage statt einer separaten Nachtragsgrundlage verändern.
- Bautagesbericht, Rapport, Aufmaß und Rechnung vermischen.
- Vertrauliche Kosten nur im Frontend verstecken, aber in API/Export ausliefern.
- Neue Route ohne `AuthorizationPolicy`, CSRF, Validierung oder Konfliktschutz ergänzen.
- Lock als Ersatz für ETag verwenden oder einen ganzen Auftrag wegen eines einzelnen Dokuments sperren.
- Eigenen Modal-, Bestätigungs-, Listenansichts- oder Icon-Mechanismus parallel zu den globalen Helfern bauen.
- Native `alert()`/`confirm()` verwenden.
- Rote Löschaktion auf dunkelblauem Primärbutton darstellen.
- Große Formulare dauerhaft auf Übersichtsseiten anzeigen.
- Externe Laufzeitassets/CDNs oder unnötige Abhängigkeiten einführen.
- Produktionsmigrationen oder PHP-Tests eigenständig ausführen.
- Testresultate, Browserprüfung oder visuelle Qualität behaupten, ohne sie geprüft zu haben.

## 13. Pflege dieser Datei

Diese Datei enthält dauerhafte Grundsätze, keine detaillierte Feature-Chronik. Bei Architektur- oder Prozessänderungen muss sie zusammen mit den betroffenen Fach- und Installationsdokumenten aktualisiert werden. Neue Regeln sollen:

- auf aktuellem Code oder einer ausdrücklichen fachlichen Entscheidung beruhen;
- Ist-Zustand und Planung klar trennen;
- konkrete Pfade oder Komponenten nennen, wenn dies die Regel prüfbar macht;
- bestehende Abschnitte präzisieren statt widersprüchliche Duplikate anzuhängen.

Ausführliche Installationsbefehle bleiben in `docs/README.md`, Phasenplanung in `docs/ROADMAP.md` und formatspezifische Details in den jeweiligen Dokumenten unter `docs/`.
