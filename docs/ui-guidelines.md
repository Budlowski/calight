# UI-Richtlinien

Kurze, verbindliche Regeln für eine einheitliche Oberfläche. Grundlage ist das bestehende Erscheinungsbild: dunkelblaue Navigation, helle Inhaltsflächen, kompakte Formulare, lokale Lucide-Icons. Kein Framework, keine UI-Bibliothek.

Technische Bausteine: `app.css` (Variablen und der Abschnitt „Gemeinsame UI-Muster“ am Dateiende), `calight.js` (u. a. `Calight.suggest`), `modal.js`, `list-view.js` und `ui.js` (Icons, Aktionsmenüs, Löschbestätigung, `Strg+S`).

## Aktionen und Buttons

- **Hierarchie:** höchstens eine Primäraktion (`.button`) je Bereich. Sekundäraktionen nutzen `.button-light`, Löschen `.button-danger-light` oder einen Menüeintrag. Löschen wird global über `ui.js` bestätigt.
- **Ort:** Seitenaktionen stehen rechts im Seitenkopf (Smartphone: unter dem Titel). Abschnittsbezogene Aktionen gehören an den jeweiligen Abschnitt, z. B. die kaufmännische Bearbeitung am Rapport.
- **Menüs:** Seltene Aktionen kommen unter „Weitere Aktionen“ (`details.context-menu`, Icon `menu`). Fachlich zusammengehörige Wege erhalten ein eigenes Menü, z. B. „Bestätigung“. Häufige Aktionen bleiben direkt sichtbar. Menüs werden nicht verschachtelt.
- **Beschriftung:** kurz und einheitlich.
  - Neue Datensätze in Listen: „<Objekt> anlegen“ (z. B. „Kunde anlegen“, „Artikel anlegen“). Im Dialog „Anlegen“.
  - Formulare: „Speichern“. Bei Entwürfen „Entwurf speichern“, wo der Unterschied zum Einreichen wichtig ist.
  - Statuswechsel: „Einreichen …“, „Abschließen …“ (Auslassungspunkte, wenn ein Dialog folgt), „Bearbeiten“ zum Wiederöffnen.
  - Dokumente im Seitenkopf: „PDF“ (Icon `file-down`), bei Entwürfen „PDF-Vorschau“.
- **Umbruch:** Beschriftungen brechen nie um (`white-space: nowrap`). Bei Platzmangel bricht die Aktionsleiste als Ganzes um.

## Icons

Nur lokale Lucide-SVGs (`assets/icons/lucide`, CSS-Klasse `.icon-<name>`), Größe 16 px, Abstand 7 px zur Beschriftung. Gleiche Funktion, gleiches Icon:

| Funktion | Icon |
|---|---|
| Anlegen/Hinzufügen | `plus` |
| Speichern | `save` |
| Bearbeiten/Unterschrift | `square-pen` |
| Löschen/Entfernen | `trash` |
| PDF/Download/Export | `file-down` |
| Hochladen/Import | `file-up` |
| Kopieren/Korrektur | `copy` |
| Zurücksetzen/Aktualisieren | `refresh-cw` |
| Einreichen/Abschließen/Bestätigung | `clipboard-check` |
| Weitere Aktionen | `menu` |

Reine Icon-Buttons brauchen `aria-label` und `title`.

## Suchfelder für Stammdaten

- Listen-Suchfelder: `type="search"`, Platzhalter „<Felder> durchsuchen“, volle Breite auf dem Smartphone.
- Suche mit Vorschlagsliste: `Calight.suggest({ input, list, clear, load, render, choose })`.
  - Daten liefert immer ein berechtigungsgeprüfter Serverendpunkt.
  - Bedienung: Pfeiltasten, Enter, Escape, Leeren-Knopf (×), ab 2 Zeichen, „Keine Treffer.“.
  - Veraltete Antworten werden verworfen.
- Auswahl aus vielen Stammdaten in Dialogen: `Calight.searchableSelect(select)`.
  - Erweitert ein vorhandenes `<select>` um ein Suchfeld mit Vorschlägen. Durchsucht werden nur die bereits vom Server gelieferten, berechtigungsgeprüften Optionen.
  - Der Wert bleibt im `<select>`, Formulardaten und `change`-Ereignisse sind unverändert.
  - Freitext ohne Auswahl ist ungültig.
  - Eingesetzt für die Kundenauswahl (Rapport, Regieauftrag, Angebot) und die Auftragsauswahl (Aufmaß). Tagesberichte wählen zuerst „Auftrag / Kostenstelle“; mit Auftrag erscheint der Kunde als Kontexttext, sonst sind Kunde („Ohne Kunde“) und Projekt optionale Felder. Fehlende Kunden werden in Listen als „–“ angezeigt.
- Kurze, feste Auswahllisten und Listenfilter bleiben `<select>`.

## Formulare

- Beschriftung über dem Feld (`label > .field-label`). Pflichtfelder sind mit „*“ markiert, optionale Felder mit „(optional)“, wo es Missverständnisse vermeidet.
- Höhe `--control-height` (34 px), ab Tablet 40 px. Auf dem Smartphone 16 px Schrift, damit iOS nicht zoomt.
- Deaktivierte und schreibgeschützte Felder sind einheitlich grau hinterlegt.
- Ungespeicherte Änderungen:
  - werden auf Bearbeitungsseiten gekennzeichnet bzw. beim Verlassen abgefragt (`beforeunload`, Dialog-Dirty-Guard);
  - Speichern ist gegen doppeltes Absenden geschützt;
  - Rückmeldungen erscheinen als `.notice` nahe der auslösenden Aktion.

## Listen

`list-view.js` regelt Suche, Filter, Sortierung, Pagination, URL-Zustand und Karten-/Tabellenansicht.

- **Werkzeugleiste der Übersichten (zweistufig, `list-view.js`):** eine Zeile mit Suche, Hauptfilter (Modul wählt per `primaryFilters`, Standard: erster Filter), Knopf „Filter (n)“, Sortierung, Anzahl und Ansicht. Weitere Filter liegen im aufklappbaren Filterbereich; aktive Filter erscheinen darunter als Chips mit × sowie „Alle zurücksetzen“. Filter brauchen ein `aria-label` (dient als Beschriftung im Filterbereich und auf den Chips).
- Geldbeträge und Mengen stehen rechtsbündig.
- **Stammdaten** (Artikel, Positionen, Schlepptexte, Kategorien, Lieferanten) nutzen dieselbe Tabellen-/Kartenansicht je Reiter (`master-data.js`). Bearbeitung im Modal; Stammpositionen mit Kalkulationsbausteinen auf eigener Seite (`/master-data/services/{id}`). Suche und Filter laufen hier bewusst im Browser, weil der Stamm einmal vollständig geladen wird; der gewählte Reiter steht in der URL (`#suppliers`).
- Leere Listen zeigen einen Text statt einer leeren Tabelle.
- Auf dem Smartphone steht die Suche über die volle Breite, Filter stehen zweispaltig.

- **Dokumente:** Die zentrale Seite „Dokumente“ ist nur lesend (keine Upload- oder Löschaktionen). Hochladen geschieht im Bereich „Dokumente“ des Geschäftsobjekts (`templates/partials/document-section.php`, `document-section.js`); kein Ordner- oder Baumlayout.

## Status und Meldungen

Status-Badges (`.status-badge`) nutzen eine gemeinsame Palette (`--status-*` in `app.css`) und immer eine Textbezeichnung, nie nur Farbe:

| Bedeutung | Beispiele |
|---|---|
| neutral | Entwurf, Offen |
| info | Eingereicht, Abgeschlossen |
| warning | In Bearbeitung, offene Materialanforderung |
| success | Aktiv, Bearbeitet, Erledigt |
| special | Vom Kunden bestätigt |
| muted | Archiviert, Abgelehnt |

Meldungen: `.notice` mit `notice-success`, `notice-warning` oder `notice-error`.

## Dialoge

Nur `CalightModal` bzw. `Calight.createDialog`. Aufbau:
- Titel, Schließen-Knopf (×), Inhalt scrollbar.
- Fußzeile rechts: „Abbrechen“ links von der Primäraktion.
- Escape schließt. Bei ungespeicherten Eingaben wird nachgefragt.
- Kritische Aktionen nutzen `CalightModal.confirm` mit eindeutigem Text.
- Ein kleiner Dialog über einem offenen Dialog (z. B. „Lieferant anlegen“ im Bestelldialog) nutzt `CalightModal.openOver`; der untere Dialog behält seine Eingaben.
- Aktionsmenüs in scrollenden Tabellen öffnen am Bildschirm ausgerichtet (`ui.js`), damit der Tabellencontainer sie nicht abschneidet; beim Scrollen schließen sie.

## Navigation

**Verwaltungsbereiche (Stammdaten, Einstellungen):** gemeinsame vertikale Unternavigation (`partials/admin-nav.php`, `.admin-shell`/`.admin-nav`, ca. 210 px, hell, Gruppenüberschriften in Kapitälchen, aktiver Eintrag mit blauer Randmarkierung). Unter 900 px ersetzt ein einklappbares Menü (`.admin-nav-compact`) die Liste. Keine horizontalen Reiter für Verwaltungsbereiche und keine Brotkrümel auf diesen Seiten; Bereiche derselben Seite schalten über den URL-Hash um (`data-tab`, `data-settings-tab`). Neue Verwaltungsseiten werden nur im Partial ergänzt – mit Recht und Modulprüfung, ohne leere Platzhalter.

**Verwaltungsformulare:** Anlegen/Bearbeiten im globalen Dialog (`Calight.createDialog`, `wide: true` = 760 px) mit `.admin-form`/`.admin-form-grid` (zwei Spalten, mobil eine; lange Felder `.span-all`), Hilfetexte als `.field-hint` direkt unter dem Feld, abgesetzte Abschnitte `.admin-form-section` (z. B. „Status und Sicherheit“, „Benutzerkonto“ mit Auswahlkarten `.radio-cards`), Protokolle einklappbar am Ende. Fußbereich nur Abbrechen und Speichern/Anlegen; Zusatzaktionen wie „Passwort zurücksetzen“ stehen im Formular und öffnen einen gestapelten Dialog (`CalightModal.openOver`), damit Eingaben erhalten bleiben. Listen nutzen die volle Breite (keine dauerhaft eingeblendeten Editoren). Kostensätze und Beträge zeigen zwei Nachkommastellen; Eingabefelder behalten die gespeicherte Genauigkeit.

**Einstellungsformulare (Arbeitserfassung):** Zeilen mit Beschriftung und Hilfetext links, Eingabe mit Einheit (Std., Min., €, %, €/km) in fester rechter Spalte (`.operations-row`), fachliche Untergruppen (z. B. Pausen), mobil untereinander. Änderungen werden gesammelt und über eine feste Speichern-Leiste (`.operations-savebar`, Strg+S) gespeichert, mit Verwerfen und Schutz vor Verlassen.

**Rollen & Rechte:** einklappbare Gruppen je Fachbereich, auf Desktop Vergleichstabelle der drei Rollen, mobil Liste mit Rollenkennzeichen; technische Rechteschlüssel nur auf Wunsch („Technische Schlüssel anzeigen“). Reine Information, keine Bearbeitung.

Die Sidebar ist nach Arbeitsbereichen gegliedert: **Allgemein** (Übersicht, Kunden, Projekte, Dokumente), **Auftragsabwicklung** (Angebote, Aufträge, Aufmaße), **Arbeitserfassung** (Zeiterfassung, Tagesberichte, Rapporte), **Beschaffung** (Bestellungen), **Verwaltung**.
- Ein Eintrag erscheint nur für implementierte, aktive Module (`ModuleService`, im Template `$moduleEnabled`) und mit Leserecht; eine Gruppe nur mit mindestens einem Eintrag.
- Es gibt keine deaktivierten Platzhalter. **Kaufmännisch** (Abrechnung, Nachkalkulation), Kontakte und Kalender kommen erst mit ihrer Implementierung hinzu.
- Materialbedarf ist ein Reiter unter Bestellungen (`partials/procurement-tabs.php`, „Bestellungen | Materialbedarf“); ohne Bestellrecht führt der Beschaffungseintrag direkt zum Materialbedarf.
- Seiten deaktivierter Module bleiben lesbar und zeigen oben einen Warnhinweis; alle Bearbeitungs-, Erstellungs-, Lösch- und Statusaktionen sind ausgeblendet (`Calight.moduleReadOnly`/`Calight.readOnly`).
- Einstellungen → Module: kompakte vertikale Liste (Icon, Bezeichnung, Beschreibung, Schalter); Basissystem „Immer aktiv“, geplante Module gedämpft „In Planung“; offene Vorgänge erst im Bestätigungsdialog beim Deaktivieren.
- Auftragsdetail: Reiter (`.tabs`, Auswahl im URL-Hash) Übersicht · Leistungen · Ausführung · Material · Aufmaße · Dokumente; Kennzahlen als kompaktes Raster (`.order-kpi-grid`), keine leeren Reiter.
- Die Zeiterfassung steht unter „Arbeitserfassung“ (Woche als Startansicht, Woche und Monat als Kalenderraster mit immer sieben Spalten, kompakte Zeitblöcke statt breiter Tabelle, Summenbegriffe „Nettozeit gesamt / Davon Reisezeit / Übrige Arbeitszeit“, optionale Tagesangaben im Dialog „Reisekosten und Auslagen“ mit den Abschnitten Auswärtstätigkeit und Verpflegung, dienstliche Privat-Pkw-Fahrten (Liste mit „+ Fahrt“) und Auslagen; Beträge nur als „rechnerisch“ gekennzeichnete Referenzwerte vom Server). Die Zeiterfassung ist ein Stundenzettel: Kennzahlen „Arbeitszeit / Davon Reisezeit / Ohne Uhrzeit“ gleichwertig und zurückhaltend, je Buchung eine kompakte Zeile (Uhrzeit bzw. „Ohne Uhrzeit“ · Tätigkeit oder Bericht als Link · Herkunft, Auftrag/Kunde · Stunden rechts · Aktion), Änderungsangaben nur im Bearbeitungsdialog, Plausibilitätshinweise als dezente Zeile. Buchungen zeigen ihre Herkunft als neutrales Textkennzeichen (`time-source`: Rapport, Tagesbericht, Manuell) mit Link zum Bericht; Berichtsdauern sind in der Zeiterfassung nicht editierbar („Uhrzeiten ergänzen“). „Ohne Uhrzeit“ ist neutral; nur bei deaktivierter Betriebseinstellung wird es als unvollständig markiert. Kostenziele werden immer über ein gemeinsames Feld „Auftrag / Kostenstelle“ gewählt (`Calight.costTargetSelect`, Kostenstellen mit dezentem Zusatz „(Kostenstelle)“); keine getrennten Auswahlfelder oder Typumschalter. Freigabe der Zeiterfassung: Tagesstatus als kleines Kennzeichen in Kalenderzellen (⧗ eingereicht, ✓ festgeschrieben, ↺ zur Korrektur geöffnet), Zeitraumstatus als `time-lock-badge`, darunter die kompakte Freigabekarte (Sperrfehler sichtbar, Hinweise eingeklappt, Nachweise mit Revision, Protokoll eingeklappt); gesperrte Tage blenden Bearbeitungsaktionen aus. Prüfhinweise am Kalendertag: Sperrfehler rot mit ✕ (Vorrang), Warnungen orange mit ⚠ und farbiger Randmarkierung der Zelle, sonst neutral; die Tagesansicht zeigt die konkreten Hinweise, die Gesamtliste unter dem Kalender ist eingeklappt. Abwesenheiten erscheinen mit Bezeichnung und dezenter Hintergrundfarbe (Kalenderzelle, eigene Zeile „keine Arbeitszeit“ in der Tagesansicht). Tagesbericht-Detail: Status (`status-badge is-report-*`) und Aktionen im Kopfbereich wie beim Rapport (Speichern, Einreichen, PDF bzw. PDF-Vorschau, Festschreiben, Zurückgeben, Wieder öffnen, Stornieren, Löschen nur je Status); Berichtsdatum kompakt (200 px), Bezeichnung über die Restbreite, mobil untereinander; Stundenzeilen Mitarbeiter · Datum · Dauer · Tätigkeit · Entfernen, Stunden ohne nachgestellte Nullen. Büroprüfung als Reiter „Freigaben“ in der Zeiterfassung (keine eigene Navigation). Vorschlagslisten (`Calight.searchableSelect`/`suggest`) richten sich in Dialogen und scrollenden Containern am Bildschirm aus und klappen bei Platzmangel nach oben auf.

## Responsive und Barrierearmut

- Sichtprüfung bei etwa 1600, 1000 und 390 px.
  - **Hinweis:** Headless-Chromium rendert mindestens 500 px breit. Für echte 390 px die Seite in einem 390 px breiten `iframe` aufnehmen.
- Keine horizontale Überbreite. Tabellen scrollen in ihrem eigenen Container.
- Touch-Ziele ab Tablet mindestens 40 px.
- Sichtbare Fokusmarkierung (`--focus-ring`).
- Keine Funktion nur per Hover.
- Aktionsmenüs:
  - öffnen in Richtung Bildschirmmitte und bleiben im Bildschirm;
  - werden bei wenig Höhe scrollbar;
  - lassen sich mit Pfeiltasten und Escape bedienen;
  - melden `aria-expanded`.

## Mobiles Frontend (`/mobile/`)

Eigenständige Oberfläche für Monteure (`templates/layout/mobile.php`, `templates/mobile/*`, `assets/mobile.css`, `assets/mobile.js`). Sie baut auf `app.css` auf (Variablen, Buttons, Statusbadges, Kontextmenü, Hinweise) und führt keine zweite Komponentensammlung ein.

- **Aufbau:** kompakter Kopf (Calight, Benutzermenü als `details.context-menu`), Inhalt mit normalem Dokument-Scrolling, feste Navigation unten mit höchstens vier Einträgen (Start · Aufträge · Zeiten · Mehr). Keine Seitenleiste. Einträge nur für aktive Module und vorhandene Rechte.
- **Darstellung:** Karten (`.mobile-card`) und kompakte Listen (`.mobile-list`, ganze Zeile als Link, Status rechts). Keine Tabellen, keine horizontale Überbreite. Lange Texte brechen um (`overflow-wrap: anywhere`).
- **Bedienung:** Touch-Ziele mindestens 44 px (`--mobile-touch`), Eingabefelder mit 16 px Schrift (kein Zoom unter iOS), Suche als normales GET-Formular. Telefonnummern als `tel:`, E-Mail als `mailto:`, Adressen mit ausdrücklichem Link „In Karte öffnen“ (öffnet einen externen Kartendienst nur auf Antippen).
- **Ränder:** `viewport-fit=cover` und `env(safe-area-inset-*)` für Kopf, Inhalt und Navigation; bei geöffneter Bildschirmtastatur wird die untere Navigation ausgeblendet (`mobile-keyboard-open`).
- **Breiten:** Inhalt maximal 760 px, Tablet/Desktop mit dreispaltigen Schnellzugriffen; dieselbe Struktur auf allen Geräten.
- **Kaufmännische Werte:** Mobile Seiten zeigen keine Preise, Summen, Kosten oder Kalkulationsdaten – auch nicht für Büro und Administratoren. Persönliche Beträge der Zeiterfassung (rechnerische Verpflegungspauschale, Kilometerbetrag, eigene Auslagen) sind keine kaufmännischen Auftragswerte und werden angezeigt.
- **Monteure in der Büroansicht:** Ohne `commercial.read` blenden Auftragsdetail (Summen), Auftragsliste (Summe) und Aufmaße (EP, Betrag, Summen, PDF) die Beträge aus; die Oberfläche bleibt ohne leere Spalten bedienbar.
- **Keine Attrappen:** Noch nicht umgesetzte mobile Funktionen erscheinen nicht als Schaltflächen. Vorübergehend führen Einstiege zu den vorhandenen responsiven Seiten (z. B. `/daily-reports?create_for=<auftrag>`); „Mehr“ weist darauf hin.
- **Verbindung:** Ohne Verbindung erscheint oben ein Warnhinweis; Seitenaufrufe ohne Verbindung beantwortet der Service Worker mit einer Hinweisseite. Es wird nichts zwischengespeichert.
- **Zeiterfassung mobil:** Umschalter Tag · Woche · Monat (`.mobile-segmented`), Vor/Zurück und „Zu heute“. Woche als vertikale Liste der sieben Tage, Monat als Raster mit sieben schmalen Spalten (kein horizontales Scrollen); Tagesstatus mit denselben Zeichen wie die Büroansicht (⧗ eingereicht, ✓ festgeschrieben, ↺ geöffnet), Sperrfehler rot ✕, Warnungen orange ⚠, Abwesenheiten mit Hintergrundfarbe.
- **Formulare mobil:** als Vollbild-Blatt (`.mobile-sheet`) über der Navigation mit Kopf (Zurück/Titel), einer Spalte großer Felder (`.mobile-field`, Zeitfelder paarweise), festen Aktionen unten (Löschen · Abbrechen · Speichern). Zurück-Taste und Abbrechen fragen bei ungespeicherten Eingaben nach (`CalightModal.confirm`), ebenso das Verlassen der Seite. Nach dem Speichern zurück zur Tagesansicht mit kurzer Erfolgsmeldung. Belege per „Foto aufnehmen“ (`capture="environment"`) oder „Datei wählen“.
- **Tagesberichte und Rapporte mobil:** Übersicht als Karten mit Status-Chips (Suche als GET-Formular, „Neu“ oben rechts); Bearbeitung im gemeinsamen Editor der Büroansicht im mobilen Layout: Abschnitte als Karten, Felder einspaltig in voller Breite, Stunden- und Materialpositionen als Karten mit Löschen unten rechts, Aktionen als große, umbrechende Schaltflächen oben. Neue Links in gemeinsamen Skripten immer über `Calight.paths` erzeugen, nie fest auf `/daily-reports` o. Ä.
- **Oberflächenwechsel:** Büro/Administratoren über „Mobile Ansicht“ in der Kopfleiste der Büroansicht bzw. „Zur Büroansicht“ im mobilen Benutzermenü; die Auswahl wird gespeichert.

## Bekannte offene Punkte

- **Projektauswahl** (Angebot, Regieauftrag) bleibt ein `<select>`, weil ihre Optionen je nach Kunde dynamisch ein- und ausgeblendet werden. Das Zusammenspiel mit `searchableSelect` müsste dafür erweitert werden.
- **Auftragsauswahl im Rapport-Dialog** bleibt ein `<select>`. Sie ist auf die Aufträge des gewählten Kunden beschränkt und daher kurz.
- **Artikelauswahl** im Angebotseditor und in den Stammdaten-Bausteinen nutzt `<datalist>` mit clientseitig geladenen Stammdaten. Eine Umstellung auf `Calight.suggest` braucht eigene Suchendpunkte und war nicht Teil des UI-Durchgangs.
- **Übersicht (Dashboard):** Sie zeigt noch den Platzhaltertext aus Phase 0. Ein fachlicher Inhalt (z. B. offene Rapporte und Materialanforderungen) ist eine eigene Aufgabe.
- **Angebotseditor:** Er ist ein bewusstes Speziallayout. Die dichte Positionstabelle konnte ohne PHP-Laufzeit nicht mit echten Daten geprüft werden.
- **Mobiles Frontend:** Die Sichtprüfung von Startseite und Auftragsdetail lief nur über statische Nachbauten bei 390 und 1000 px; Verhalten auf echten Geräten (Safe Areas, Tastatur, Installation als App) ist noch manuell zu prüfen.
- **Sichtprüfung:** Ohne PHP lief sie nur über statische Nachbauten der Vorlagen mit Testdaten. Serverseitig gerenderte Inhalte (Auftragsdetail, Dashboard, Angebotskopf) zeigen dabei alle Zweige zugleich bzw. keine echten Werte.
