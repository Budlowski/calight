# Datenablage und Speicherpfade

Calight trennt strikt zwischen **Quellcode**, **interner Anwendungsdaten** und (künftig) **ausgegebenen Dokumentarchiven**. `docs/` enthält ausschließlich Projektdokumentation.

## Verzeichnisse im Container

```text
/var/www/app/                    Anwendung (aus dem Image, wird bei Updates ersetzt)
/var/www/app/public/             einziges öffentliches Webverzeichnis (Apache DocumentRoot)
/var/www/data/                   persistente Anwendungsdaten (Volume bzw. Bind-Mount)
├── database/app.sqlite          SQLite-Datenbank             APP_DATABASE_PATH
├── offers/                      Angebotsentwürfe, Snapshots,  APP_OFFERS_PATH
│                                archivierte Angebots-PDFs
├── documents/                   Dokumente und Anhänge         APP_DOCUMENTS_PATH
│   └── daily-reports/<bericht>/<anhang>.<ext>   Anhänge von Bautagesberichten
└── templates/                   benutzerdefinierte Vorlagen   APP_TEMPLATES_PATH (derzeit ungenutzt)
```

Weitere Unterordner von `documents/` (z. B. `measurements/`, `reports/`, `orders/`, `delivery-notes/`) entstehen erst, wenn die jeweilige Funktion Dateien speichert.

Die Pfade werden ausschließlich über die Umgebungsvariablen `APP_DATABASE_PATH`, `APP_OFFERS_PATH`, `APP_DOCUMENTS_PATH` und `APP_TEMPLATES_PATH` festgelegt (`app/config/app.php`, Standardwerte wie oben). Im Docker-Betrieb genügt es, **ein** persistentes Verzeichnis nach `/var/www/data` einzubinden; die Variablen müssen dann nicht geändert werden.

## Regeln

- Datenverzeichnisse liegen nie im Projektordner (Git-Arbeitsverzeichnis) und nie unter `app/public`. Liegt ein Datenpfad im öffentlichen Webverzeichnis, startet Calight nicht.
- Dateien werden nur unter intern erzeugten Namen (UUIDs) gespeichert; Originalnamen sind reine Metadaten in der Datenbank. Die Datenbank speichert Pfade **relativ** zu `APP_DOCUMENTS_PATH` (z. B. `daily-reports/<bericht>/<anhang>.pdf`).
- Anhänge werden ausschließlich über berechtigungsgeprüfte Routen ausgeliefert; Zugriffe und Löschungen sind auf das jeweilige Ablageverzeichnis begrenzt (auch gegen Symlinks).
- `.gitignore` und `.dockerignore` schließen versehentlich im Projektordner liegende Laufzeitdaten aus.
- Künftige kundenseitige Dokumentarchive (PDF-Ausgabe, Phase 3.2.3) werden **zusätzlich** und getrennt von diesen internen Daten konfigurierbar sein.

## Prüfung

```bash
docker exec -w /var/www/app <container> php bin/console storage:check
```

Der Befehl ändert nichts. Er zeigt jeden Pfad, ob er existiert und beschreibbar ist, und warnt, wenn ein Pfad im Anwendungs- oder einem `docs`-Verzeichnis liegt oder sich Pfade überschneiden.

Die tatsächlichen Mounts eines Containers zeigt:

```bash
docker inspect <container> --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
```

## Umzug falsch abgelegter Daten (manuell)

Hintergrund: Werden Datenverzeichnisse aus dem Projektordner eingebunden (z. B. `./docs` als Dokumentenablage), landen Anhänge in der Projektdokumentation. Der Umzug ist ein reines Kopieren auf dem Host; **Datenbankverweise müssen nicht angepasst werden**, weil sie relativ zum jeweiligen Ablageverzeichnis gespeichert sind.

Beispiel mit einem neuen Datenverzeichnis `/srv/calight-data` (Pfad frei wählbar, außerhalb des Projektordners):

1. **Ist-Zustand festhalten:** `docker inspect …` (siehe oben) und `storage:check` ausführen und notieren, welches Host-Verzeichnis auf welches Ziel zeigt.
2. **Container stoppen**, damit SQLite und Dateien in einem konsistenten Zustand sind.
3. **Sicherung** der bisherigen Host-Verzeichnisse anlegen (z. B. `tar czf calight-backup-$(date +%F).tar.gz <alte Verzeichnisse>`).
4. **Zielstruktur anlegen** und Daten **kopieren** (nicht verschieben):
   ```bash
   mkdir -p /srv/calight-data/{database,offers,documents,templates}
   cp -a <alt>/database/.  /srv/calight-data/database/     # inkl. app.sqlite, ggf. -wal/-shm
   cp -a <alt>/offers/.    /srv/calight-data/offers/
   cp -a <alte Dokumentenablage>/daily-reports /srv/calight-data/documents/   # z. B. ./docs/daily-reports
   cp -a <alt>/templates/. /srv/calight-data/templates/
   chown -R 33:33 /srv/calight-data      # www-data im Container
   ```
5. **Compose-Konfiguration** auf einen einzigen Mount umstellen und die `APP_*_PATH`-Standardwerte verwenden:
   ```yaml
   volumes:
     - /srv/calight-data:/var/www/data
   ```
6. **Container starten**, `storage:check` ausführen und stichprobenartig ein Angebot (PDF) und einen Anhang eines Bautagesberichts öffnen.
7. Erst nach erfolgreicher Prüfung die alten Kopien im Projektordner **von Hand** entfernen (z. B. `docs/daily-reports/`). Die Sicherung aus Schritt 3 aufbewahren.
