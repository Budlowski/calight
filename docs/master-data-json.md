# Stammdaten als JSON austauschen

Calight exportiert und importiert Stammdaten immer vollständig je Bereich:

- Artikelstamm (`cost-items`)
- Positionsstamm (`services`)
- Kategorien einschließlich Hierarchie (`categories`)
- Lieferanten (`suppliers`)
- Schlepptexte (`trailing-texts`)

Jede Datei enthält `format`, `schemaVersion`, `scope`, `exportedAt` und die vollständige Liste `items`. Archivierte Datensätze sind enthalten. Kategorien werden als verschachtelter Baum über `children` ausgegeben. Berechnete Anzeigewerte werden nicht exportiert; sie werden nach dem Import erneut aus den Kosten- und Zuschlagswerten berechnet.

Beim Import werden Datensätze anhand ihrer internen ID zusammengeführt. Vorhandene Datensätze werden aktualisiert, neue Datensätze angelegt. Nicht in der Datei enthaltene Datensätze werden nicht gelöscht. Der Import eines Bereichs läuft in einer SQLite-Transaktion vollständig oder gar nicht.

Für eine Übertragung auf eine andere Calight-Installation gilt diese Reihenfolge:

1. Kategorien
2. Lieferanten
3. Artikelstamm
4. Schlepptexte
5. Positionsstamm

Damit sind alle Referenzen verfügbar, bevor abhängige Artikel und Stammpositionen importiert werden.
