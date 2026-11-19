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
  - Dokumente: „PDF“ bzw. „PDF herunterladen“ (Icon `file-down`), „PDF-Vorschau“ (Icon `eye`).
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
- Kurze, feste Auswahllisten bleiben `<select>`.

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

- Geldbeträge und Mengen stehen rechtsbündig.
- Leere Listen zeigen einen Text statt einer leeren Tabelle.
- Auf dem Smartphone steht die Suche über die volle Breite, Filter stehen zweispaltig.

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

## Bekannte offene Punkte

- **Kunden- und Auftragsauswahl** in Anlegedialogen nutzt `<select>`. Bei sehr großen Kundenbeständen sollte sie auf `Calight.suggest` mit Serversuche umgestellt werden.
- **Artikelauswahl** im Angebotseditor und in den Stammdaten-Bausteinen nutzt `<datalist>` mit clientseitig geladenen Stammdaten. Eine Umstellung auf `Calight.suggest` braucht eigene Suchendpunkte.
- **Angebotseditor:** Er bleibt ein bewusstes Speziallayout mit eigenen Dichteregeln.
