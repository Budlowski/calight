# Kalkulationsbausteine und Preisquellen

Kalkulationsbausteine verwenden genau eine von zwei Preisquellen:

- **Aus Artikelstamm _Kennung_** übernimmt Kosten-EP und Aufschlag aus dem verknüpften Stammartikel. Änderungen am Artikelstamm werden ausschließlich über eine ausdrücklich ausgelöste Preisaktualisierung übernommen.
- **Eigener Preis** hält Kosten-EP und Aufschlag unabhängig vom Artikelstamm fest. Eine vorhandene Artikelreferenz bleibt sichtbar, wird aber bei Preisaktualisierungen nicht berücksichtigt.

Der frühere Modus `locked` wird beim Laden oder Speichern als `manual` (Eigener Preis) normalisiert. Kostenpreis und Artikelreferenz bleiben dabei unverändert. Die Migration `0015_normalize_component_price_modes.sql` überführt zusätzlich gespeicherte Bausteine des Positionsstamms.

## Preisermittlung einer Angebotsposition

Eine Position verwendet entweder die Kalkulation ihrer Bausteine oder einen eigenen Einheitspreis. Die Auswahl befindet sich im Abschnitt **Preisermittlung**. Ein eigener Einheitspreis löscht die Kalkulationsbausteine nicht; sie bleiben als interne Kalkulationsgrundlage und Dokumentation erhalten.

## Stammartikel aus einem Baustein

Die Aktion **Als Stammartikel speichern** legt über die bestehende Artikelstamm-Validierung einen neuen Datensatz an. Eine vorhandene Artikelnummer wird nicht überschrieben. Nach erfolgreicher Anlage kann der Anwender den Angebotsbaustein ausdrücklich mit dem neuen Stammartikel verknüpfen. Ohne diese Bestätigung bleibt der Angebotsbaustein unverändert.
