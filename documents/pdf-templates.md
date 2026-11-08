# PDF-Dokumentvorlagen

Firmendaten und Dokumenttexte werden zentral unter **Einstellungen** gespeichert und nicht in Angebotsentwürfe kopiert. Vorschau und Download verwenden denselben PDF-Renderer und können den aktuellen, noch ungespeicherten Editorzustand verarbeiten.

Die Standardvorlage verarbeitet ausschließlich kundenrelevante Angaben: Gliederung, Texte, Mengen, Einheiten sowie Verkaufs- und Gesamtpreise. Einkaufspreise, interne Kostensätze, Zuschläge und Deckungsbeiträge werden nicht ausgegeben.

Normale, Bedarfs- und Alternativtitel werden mit ihrer jeweiligen Titelart ausgegeben. Ausgeschlossene Titel und ihre Inhalte bleiben vollständig sichtbar und erhalten eigene Titelsummen, tragen jedoch nicht zur regulären Angebots- oder übergeordneten Normalsumme bei. Frei platzierbare Textpositionen erscheinen ohne Positionsnummer und Preis exakt an ihrer Stelle in der Gliederung.

Konfigurierbar sind Akzentfarbe, maximaler Logorahmen und Logoposition, Grundschrift, Seitenränder, kompaktes oder DIN-5008-orientiertes Anschriftenfeld, Projektinformationen sowie Kopf-, Fuß- und leere Titelzeilen. Das Logo wird proportional und zentriert in seinen maximalen Rahmen eingepasst; seine Oberkante ist an der Oberkante der Firmenüberschrift ausgerichtet. Es wird niemals gestreckt und erscheint ausschließlich auf der ersten Seite. Der Standardrahmen beträgt 180 × 100 pt. Leere Titel sind standardmäßig ausgeblendet. Bedarfs- und Alternativpositionen werden sichtbar mit `[Bedarf]` beziehungsweise `[Alternative]` gekennzeichnet, bepreist und ausdrücklich von den regulären Summen ausgenommen.

Die standardmäßig aktive Abschlussseite listet die summenbildenden Haupttitel mit ihren Nettosummen auf. Positionen außerhalb eines Titels werden in einer eigenen Zeile zusammengefasst. Die Titelübersicht kann in den Dokumenteinstellungen deaktiviert werden. Netto-, Steuer- und Gesamtbetrag bleiben als untrennbarer Summenblock auf derselben Seite; Angebotsbedingungen werden ebenfalls möglichst als zusammenhängender Block umbrochen.

Einleitung, Schlusstext, Zahlungsbedingungen, zusätzliche Hinweise und Angebotsgültigkeit besitzen globale Standardwerte. Im Angebotseditor können diese Angaben je Entwurf überschrieben werden. Die individuellen Werte liegen im Angebots-JSON und werden deshalb bereits in der Vorschau des ungespeicherten Editorzustands berücksichtigt. Das Anpassen dieser Angaben vergibt keine Angebotsnummer.

Der Dateiname von PDF-Downloads ist über eine globale Vorlage konfigurierbar. Standard ist `{JJJJ}-{MM}-{TT}_{DOKUMENTTYP}_{DOKUMENTNUMMER}`. Unterstützt werden `{JJJJ}`, `{MM}`, `{TT}`, `{DOKUMENTTYP}` und `{DOKUMENTNUMMER}`. Ein Angebotsentwurf ohne vergebene Geschäftsnummer verwendet `Entwurf` als Dokumentnummer und verbraucht dadurch weiterhin keine Angebotsnummer.

Die Steuerberechnung liegt außerhalb des PDF-Renderers. Die Dokumentlogik liefert Netto-, Steuer- und Bruttobetrag für die gewählte Behandlung `standard` oder `exempt`; dadurch bleibt der Renderer frei von fest hinterlegten Steuersätzen.

Ein angebotsweiter Rabatt oder Aufschlag wird im Summenblock zwischen regulärer Netto-Zwischensumme und angepasster Angebotsnettosumme ausgewiesen. Erst die angepasste Nettosumme bildet die Grundlage der Umsatzsteuer.

Weitere Vorlagen können später als eigene Renderer hinter der zentralen Vorlagenauswahl ergänzt werden. Ein visueller Vorlageneditor ist bewusst nicht vorgesehen.
