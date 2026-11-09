# Calight

**Open-Source-Kalkulations- und Angebotssoftware für Handwerk und Dienstleistung.**


Calight ist eine schlanke, selbst gehostete Anwendung zur Erstellung und Kalkulation von Angeboten. Sie richtet sich an Handwerksbetriebe, Einzelunternehmer und Dienstleister, die ihre Leistungen transparent und detailliert kalkulieren möchten.

## Funktionen

- Angebote mit verschachtelten Titeln und Positionen
- Artikel- und Positionsstamm
- Kalkulation von Material, Lohn, Fremdleistungen und sonstigen Kosten
- Einkaufspreise, Aufschläge und Verkaufspreise
- Kunden- und Projektverwaltung
- Bedarfs- und Alternativpositionen
- Interne Kalkulationsübersicht
- PDF-Ausgabe und JSON-Import/-Export

## Keine Buchhaltungssoftware

Calight konzentriert sich auf **Kalkulation und Angebotserstellung**. Es soll Buchhaltungs- und Rechnungssoftware wie Lexware ergänzen, nicht ersetzen.

## Auftragsabwicklung

```mermaid
flowchart TD
    K["Kunde"]
    P["Projekt (optional)"]
    A["Angebot"]
    AU["Auftrag"]
    BT["Bautagesbericht"]
    AM["Aufmaß"]
    RP["Rapport"]
    RE["Rechnung<br>(ext.)"]
    NK["Nachkalkulation"]

    K --> P
    K --> A
    P --> A

    K -->|"Direkter Regieauftrag"| AU
    P -->|"Direkter Regieauftrag"| AU
    A --> AU

    AU --> BT
    AU --> AM
    AU --> RP

    AU -->|"Nachtrag"| NA["Nachtragsangebot"]
    NA --> AU

    AM --> RE
    RP --> RE

    BT -->|"Ist-Zeiten und Ist-Kosten"| NK
    AU -->|"Soll-Kalkulation"| NK
    RE -->|"Erlöse"| NK

    classDef main fill:#e3effa,stroke:#608bb2,color:#193d5c
    classDef docs fill:#e6f2e8,stroke:#76a17a,color:#24532b
    classDef result fill:#fff0d8,stroke:#bd9855,color:#654917

    class K,P,A,AU main
    class BT,AM,RP,NA docs
    class RE,NK result
```

## Technik

- PHP, Apache und SQLite
- HTML, CSS und Vanilla JavaScript
- Docker / Self-Hosting
- Keine Cloud erforderlich

## Entwicklung

Calight ist ein **Vibe-Coding-Projekt**, das überwiegend mit **OpenAI Codex und GPT-5.6** entwickelt wird. Die fachliche Konzeption basiert auf praktischen Anforderungen aus dem Elektrohandwerk.

Das Projekt befindet sich in aktiver Entwicklung. Änderungen und Fehler sind möglich.

## Lizenz

Calight ist freie Open-Source-Software unter der **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Die Software darf auch kommerziell kostenlos genutzt, verändert und weitergegeben werden. Dabei gelten die Bedingungen der AGPL-3.0.

Siehe [LICENSE](LICENSE).


## Bilder

![Calight Angebotseditor Titel / Positionen](images/angebotseditor_1.png)
Calight Angebotseditor Titel / Positionen

![Calight Angebotseditor Positionskalkulation](images/angebotseditor_2.png)
Calight Angebotseditor Positionskalkulation

![Calight Angebotseditor Kalkulationsübersicht](images/angebotseditor_3.png)
Calight Angebotseditor Kalkulationsübersicht