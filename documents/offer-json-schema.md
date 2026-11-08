# Calight Angebots-JSON v1

Calight verwendet für Speicherung, Import und Export dasselbe Dokumentformat. Die maschinenlesbare Definition liegt unter `app/config/offer-schema-v1.json`.

- `schemaVersion` ist aktuell `1`.
- Vorhandene Element-IDs bleiben erhalten. Fehlende IDs von Titeln, Positionen und Kalkulationsbausteinen werden beim Import serverseitig ergänzt; ungültige und doppelte IDs werden abgelehnt. Nur die Identität des Angebots selbst wird beim Import als neuer Entwurf neu vergeben.
- `offerNumber` bleibt bei Entwürfen `null`.
- Geld-, Mengen- und Prozentwerte sind Dezimalstrings mit Punkt als Dezimaltrennzeichen. Beim Speichern werden Mengen auf drei, Kostenpreise auf vier sowie Zuschläge und Verkaufspreise auf zwei Nachkommastellen normalisiert.
- `nodes` enthält rekursiv Titel und Positionen; Bausteine liegen unter `position.components`.
- `manualUnitPrice` ist die einzige manuelle Verkaufspreisvorgabe einer Position. `priceMode` bestimmt den Preisbezug eines Bausteins. `calculation`, `componentSalesPrices`, `calculatedUnitPrice` und `effectiveUnitPrice` sind ausschließlich serverseitig berechnete Ergebnisse. Das frühere Bausteinfeld `salesPrice` wird beim Import nur noch toleriert und vor dem Speichern entfernt.
- Importierte Berechnungsergebnisse werden verworfen und serverseitig neu berechnet.
- Zeitstempel werden als ISO-8601 in UTC mit dem Suffix `Z` gespeichert. Vorhandene Zeitstempel mit Offset werden beim nächsten Speichern korrekt in UTC überführt.
- Das optionale Objekt `documentTerms` speichert individuelle Einleitung, Schlusstext, Zahlungsbedingungen, zusätzliche Hinweise und Angebotsgültigkeit. Fehlende beziehungsweise leere individuelle Texte werden bei der Dokumentausgabe durch die globalen Standardwerte ersetzt. `validityMode` ist `default`, `duration` oder `date`.
- Stammdatenreferenzen verändern niemals automatisch Stammdaten.

Beim Import in einen vorhandenen Entwurf bleiben interne ID, Projektbezug, Status, Erstellzeit und Angebotsnummer geschützt. Die Übernahme erfordert Vorschau, Bestätigung und den aktuellen ETag. Exportdateien enthalten keine Zugangsdaten, Serverpfade oder Firmeneinstellungen.

Die Konsolidierung bleibt kompatibel mit `schemaVersion: 1`, da keine fachliche Dokumentstruktur geändert wurde. Bestehende Dokumente werden beim Lesen weiterhin akzeptiert und beim nächsten Speichern kanonisch normalisiert.

Lesen und Exportieren verändern eine vorhandene Datei nicht. Erst ein regulärer Speichervorgang normalisiert ältere Dezimalstrings, entfernt das Legacy-Feld `salesPrice`, berechnet `calculation` neu und schreibt Zeitstempel in UTC. Manuelle Preisvorgaben, Preisbezug, IDs und Stammdatenreferenzen bleiben dabei erhalten.
