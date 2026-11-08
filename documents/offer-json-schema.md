# Calight Angebots-JSON v1

Calight verwendet für Speicherung, Import und Export dasselbe Dokumentformat. Die maschinenlesbare Definition liegt unter `app/config/offer-schema-v1.json`.

- `schemaVersion` ist aktuell `1`.
- IDs sind UUIDs; bei einem neuen Import werden sie neu erzeugt.
- `offerNumber` bleibt bei Entwürfen `null`.
- Geld-, Mengen- und Prozentwerte sind Dezimalstrings mit Punkt als Dezimaltrennzeichen.
- `nodes` enthält rekursiv Titel und Positionen; Bausteine liegen unter `position.components`.
- Importierte Berechnungsergebnisse werden serverseitig neu berechnet.
- Stammdatenreferenzen verändern niemals automatisch Stammdaten.

Beim Import in einen vorhandenen Entwurf bleiben interne ID, Projektbezug, Status, Erstellzeit und Angebotsnummer geschützt. Die Übernahme erfordert Vorschau, Bestätigung und den aktuellen ETag. Exportdateien enthalten keine Zugangsdaten, Serverpfade oder Firmeneinstellungen.
