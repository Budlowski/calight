# Datenbereinigung und Produktivstatus

**Einstellungen → System → Datenbereinigung** (nur Administratoren, Recht `system.purge`, Migration `0050`).

Mit dieser Funktion werden Testdaten vor der Produktivnahme entfernt. Benutzerkonten und Einstellungen bleiben erhalten, Stammdaten nur, solange sie nicht ausgewählt sind. Geschäftsunterlagen können gesetzlichen Aufbewahrungspflichten unterliegen. Nach der Produktivnahme ist die Funktion deshalb dauerhaft gesperrt (siehe Produktivstatus).

Code:
- `app/src/Application/SystemPurgePlanner.php`: Analyse.
- `app/src/Application/SystemPurgeService.php`: Ablauf, Ausführung und Protokoll.
- `app/src/Application/SystemPurgeBackup.php`: Sicherung.
- `app/src/Http/SystemPurgeController.php`: Routen.
- `app/public/assets/system-purge.js`: Oberfläche.

## Bereiche

| Bereich | Tabellen (zusätzlich zur Haupttabelle) | Zeitraum |
|---|---|---|
| Kunden | Ansprechpartner, Adressen | – |
| Projekte | – | – |
| Aufträge einschließlich Nachträge | Statusverlauf, Mitarbeiterzuordnung, angenommene Nachträge, Nachtragsdokumente (`offers` mit `document_type = 'change_order'`) | – |
| Angebote einschließlich Revisionen | alle Revisionen (`document_type = 'offer'`), JSON/Snapshots/PDFs unter `APP_OFFERS_PATH` | – |
| Aufmaße | Positionen, Summen, archivierte PDFs | – |
| Rapporte einschließlich Korrekturen | Stunden-, Material-, Abrechnungspositionen, zentrale Zeitbuchungen der Positionen, archivierte PDFs, Rücklaufnachweise | Leistungszeitraum **und** jedes Positionsdatum im Zeitraum |
| Tagesberichte | Stundenpositionen, zentrale Zeitbuchungen, Protokolle, Anhänge, festgeschriebene Fassungen (PDF) | Berichtsdatum |
| Zeiterfassung einschließlich Abwesenheiten und Freigaben | manuelle Zeitbuchungen, Abwesenheiten, Einreichungen, Tagessperren, Freigabeprotokoll, Stundennachweise (PDF) | Buchungs-/Abwesenheitstag; Einreichungen nur, wenn ihr ganzer Zeitraum enthalten ist |
| Reisekosten und Auslagen | Tagesangaben (Auswärtstätigkeit/Verpflegung), Privat-Pkw-Fahrten, Auslagen mit Beleg | Tag |
| Bestellungen | Positionen, Kostenverteilungen, Bestätigungen, Lieferungen, Rechnungen/Gutschriften, Zahlungen, Belege, Protokoll, Verknüpfungen zu Materialanforderungen | – |
| Materialanforderungen | – | – |
| Sonstige Geschäftsdokumente | allgemeine Dokumente und ihre Verknüpfungen | – |
| Mitarbeiter, Lieferanten, Artikelstamm, Positionsstamm (mit Bestandteilen), Kategorien und Schlepptexte, Kostenstellen | – | – |

Bereiche ohne eindeutiges fachliches Datum oder mit zeitraumübergreifenden Abhängigkeiten lassen sich nur vollständig bereinigen. Im Zeitraummodus sind sie deshalb mit Begründung deaktiviert.

Standardauswahl: alle Geschäftsdaten. Stammdaten bleiben erhalten.

Nie bereinigt werden:
- Benutzerkonten, Rollen und Rechte,
- Firmen-, Dokument- und Betriebseinstellungen und die Modulkonfiguration,
- die Nummernkreiskonfiguration,
- Migrationen,
- Benutzer- und Bereinigungsprotokolle,
- Sicherungen.

## Abhängigkeiten und Löschregeln

- **Keine stillen Kaskaden.** Nach der Auswahl prüft der Planer generisch alle Fremdschlüssel (`PRAGMA foreign_key_list`). Jede nicht ausgewählte Zeile, die auf eine zu löschende verweist, blockiert die Ausführung. Das gilt bei `RESTRICT` ebenso wie bei `CASCADE` (würde unbemerkt mitgelöscht) und `SET NULL` (würde unbemerkt verändert). Die Vorschau nennt den fehlenden Bereich.
- **Gesperrte Zeiterfassungstage.** Buchungen, Tagesangaben, Fahrten, Auslagen und Abwesenheiten auf eingereichten oder festgeschriebenen Tagen werden nur zusammen mit der Sperre entfernt, also mit dem Bereich Zeiterfassung für denselben Zeitraum.
- **Dokumente.** Verknüpfungen zu gelöschten Kunden, Ansprechpartnern, Projekten, Angeboten oder Aufträgen werden entfernt. Ein Dokument mit weiterer Verknüpfung bleibt samt Datei erhalten. Ein Dokument, das ohne jede Verknüpfung zurückbliebe, blockiert, solange „Sonstige Geschäftsdokumente“ nicht ausgewählt ist.
- **Dateien.** Gelöscht werden nur Dateien, auf die keine verbleibende Zeile mehr verweist. Pfade mit `..`, absolute Pfade oder Rückwärtsschrägstriche blockieren die Ausführung.
- **Zeitraum.** Vorgänge, die über die Zeitraumgrenze reichen, blockieren statt teilweise gelöscht zu werden. Dazu zählen Korrekturketten, Einreichungen und Verweise von außerhalb.
- **Löschreihenfolge.** Kinder werden vor Eltern gelöscht, auch bei Selbstverweisen wie Revisionen und Korrekturen. Die Fremdschlüssel bleiben dabei aktiv. Die Unveränderlichkeits- und Sperrtrigger für Löschungen lassen nur während einer laufenden Bereinigung (`system_purge_guard`) Löschungen zu. Alle übrigen Trigger und Prüfungen bleiben wirksam.

## Ablauf und Sicherheitsprüfungen

1. **Auswahl prüfen.** Der Server analysiert die Auswahl und speichert die Vorschau (`system_purge_previews`). Die Vorschau ist 30 Minuten gültig und enthält einen Fingerabdruck über Auswahl, Konflikte, Nummernkreise und den vollständigen Inhalt jeder betroffenen Zeile. Ändert sich die Auswahl im Browser, wird die Vorschau verworfen.
2. **Sicherung erstellen.** Pflicht für jede Vorschau. Gelingt die Sicherung nicht, wird nichts gelöscht.
3. **Ausführen.** Erforderlich sind:
   - das aktuelle Passwort des Administrators (serverseitig mit `password_verify` geprüft),
   - der exakt eingegebene Bestätigungstext `DATEN LÖSCHEN`,
   - die ausdrückliche Bestätigung,
   - eine ungenutzte, gültige Vorschau,
   - eine höchstens 30 Minuten alte, noch vollständig vorhandene Sicherung dieser Vorschau.

   Innerhalb von `BEGIN IMMEDIATE` prüft der Server dann zwei Dinge:
   - Der Inhaltsfingerabdruck der Datenbank entspricht noch der Sicherung, sonst gibt es einen Konflikt (HTTP 409).
   - Der Fingerabdruck der Vorschau ist unverändert.

   Danach löscht er die Zeilen und setzt die Nummernkreise zurück. Anschließend vergleicht er `PRAGMA foreign_key_check` mit dem Stand vor der Löschung. Die Vorschau wird als verwendet markiert, damit ein wiederholter Request keine zweite Löschung auslöst.

   Bei einem Fehler wird die Transaktion vollständig zurückgerollt; das Protokoll erhält den Eintrag `failed`, die Sicherung bleibt erhalten. Dateien werden erst nach erfolgreichem Festschreiben gelöscht. Fehler dabei werden als Hinweis protokolliert.
4. **Protokoll.** Jede Ausführung wird in `system_purge_runs` festgehalten, mit Administrator, Zeitpunkt, Bereichen, Datensatzanzahlen, Sicherung, Ergebnis und Dateihinweisen. Trigger verhindern, dass Einträge geändert oder gelöscht werden.

## Sicherung und Wiederherstellung

Ziel ist `APP_BACKUPS_PATH`, im Docker-Standard `/var/www/data/backups` im persistenten Volume. Das Verzeichnis darf nicht in `APP_DOCUMENTS_PATH` oder `APP_OFFERS_PATH` liegen und diese nicht enthalten. Je Sicherung entsteht `calight-purge-<Datum>-<Zeit>-<ID>/` mit folgendem Inhalt:

- `database.sqlite`: konsistente Kopie über SQLite `VACUUM INTO`. Sie wird mit `PRAGMA integrity_check` geprüft und ihr Inhaltsfingerabdruck mit der Live-Datenbank verglichen.
- `documents/` und `offers/`: vollständige Kopien, jede Datei per SHA-256 geprüft.
- `manifest.json`: Zeitpunkt, Vorschau, Auswahl, Quellpfade, Migrationsstand und Prüfsummen. Zugangsdaten enthält sie nicht.

Vorher prüft der Server Speicherplatz und Schreibrechte. Calight löscht Sicherungen nie.

**Wiederherstellung (manuell, keine Automatik):**
1. Calight anhalten (Container stoppen).
2. Die aktuelle Datenbank samt `-wal`/`-shm` beiseitelegen.
3. `database.sqlite` nach `APP_DATABASE_PATH` kopieren.
4. `documents/` und `offers/` an die Pfade aus `manifest.json` zurückkopieren und vorhandene Inhalte vorher sichern.
5. Prüfsummen mit `manifest.json` vergleichen.
6. Calight starten.

## Nummernkreise (Option)

Ohne Option bleiben alle Reservierungen (`assigned_numbers`) und Zählerstände unverändert; gelöschte Nummern werden nie wieder vergeben.

Mit Option, die nur bei vollständiger Bereinigung möglich ist:
- **Zurückgesetzt** wird ein Kreis nur, wenn keine vergebene Nummer mehr zu einem verbleibenden Datensatz gehört.
- **Reservierungen.** Die Reservierungen dieses Kreises werden entfernt.
- **Neuer Zählerstand.** Der Zähler wird auf den kleinsten automatisch vergebenen Wert gesetzt.
- **Konfiguration.** Format, Präfix und Rücksetzmodus bleiben unverändert.
- **Vorschau.** Sie zeigt jeden betroffenen Kreis mit altem und neuem Zählerstand bzw. dem Grund, warum er nicht zurückgesetzt wird.

## Produktivstatus

Auf derselben Seite lässt sich der Produktivstatus aktivieren, mit Passwort und dem Bestätigungstext `PRODUKTIVBETRIEB` (`system_production_status`). Danach ist die Datenbereinigung dauerhaft gesperrt:
- in der Oberfläche,
- im Dienst,
- in der Datenbank selbst: Ein Trigger verhindert das Setzen von `system_purge_guard`.

Trigger verhindern Ändern und Löschen des Status. Eine Rücknahme über die Anwendung gibt es nicht.
