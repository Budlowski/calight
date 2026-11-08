# Kalkulationssoftware – Phase 0

Dieses Verzeichnis enthält das technische Fundament der Kalkulationssoftware. Daten werden ausschließlich in den zentral konfigurierten Containerpfaden gespeichert; unter `public/` liegen keine Datenbank-, Angebots- oder Dokumentdateien.

## Installation im bestehenden Container

Die folgenden Befehle verändern weder Dockerfile noch Compose-Konfiguration:

```bash
docker exec -w /var/www/app kalkulationssoftware composer install --no-dev --optimize-autoloader
docker exec -w /var/www/app kalkulationssoftware php bin/console migrate
docker exec -it -w /var/www/app kalkulationssoftware php bin/console user:create fabi "Fabi"
```

Das Passwort wird verdeckt abgefragt und nicht als Kommandozeilenargument gespeichert. Es muss mindestens acht Zeichen lang sein.

Für die automatisierten Prüfungen werden die normalen lokalen Abhängigkeiten benötigt:

```bash
docker exec -w /var/www/app kalkulationssoftware composer install
docker exec -w /var/www/app kalkulationssoftware composer test
```

## Konfiguration

Die Anwendung verwendet ohne weitere Konfiguration die verbindlichen Pfade:

```text
/var/www/data/database/app.sqlite
/var/www/data/offers
/var/www/data/documents
/var/www/data/templates
```

Nur für isolierte Tests können diese mit `APP_DATABASE_PATH`, `APP_OFFERS_PATH`, `APP_DOCUMENTS_PATH` und `APP_TEMPLATES_PATH` überschrieben werden. Für HTTPS ist `APP_SECURE_COOKIES=1` zu setzen. Standardzeitzone ist `Europe/Berlin`.

## Browserprüfung

1. `http://omv:8085` öffnen; ohne Sitzung muss die Anmeldung erscheinen.
2. Mit dem per CLI angelegten Benutzer anmelden.
3. Unter „Projekte“ ein Projekt anlegen.
4. Im Projekt einen Angebotsentwurf anlegen.
5. Die Bezeichnung ändern und explizit speichern.
6. Seite neu laden; die geänderte Bezeichnung muss erhalten bleiben.
7. Den Entwurf gleichzeitig in zwei Tabs öffnen. Nach dem Speichern in Tab 1 muss Tab 2 beim Speichern einen Konflikt melden.
8. Ohne Speichern von der bearbeiteten Seite weg navigieren; der Browser muss vor Datenverlust warnen.

## Grenzen von Phase 0

- Der Angebotseditor ist absichtlich nur ein Ende-zu-Ende-Gerüst. Baumeditor, Bausteine und Preisvorschau folgen in Phase 1.
- Nummernkreise sind in der Migration vorbereitet, werden aber erst in Phase 1 vergeben und konfiguriert.
- Verbindliche Revisionen und PDFs gehören ebenfalls zu Phase 1.
- Das Rollenfeld ist erweiterbar vorbereitet; Phase 0 unterscheidet fachlich noch keine Berechtigungen zwischen Rollen.

## Phase 1.1 – Stammdaten

Die additive Migration `0002_master_data` richtet Kategorien, Lieferanten, den einheitlichen Kostenstamm sowie Leistungspositionen mit Kalkulationsbausteinen ein. Auf einer weiteren Installation wird sie mit dem normalen Migrationsbefehl ausgeführt:

```bash
docker exec -w /var/www/app kalkulationssoftware php bin/console migrate
```

Die Stammdatenverwaltung ist nach der Anmeldung unter `/master-data` erreichbar. Sie arbeitet dynamisch über die geschützte JSON-API; Schreibzugriffe benötigen die aktive Sitzung und ein gültiges CSRF-Token.

Automatisierte Prüfungen:

```bash
docker exec -w /var/www/app kalkulationssoftware composer test
```
