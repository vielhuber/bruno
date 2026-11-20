# <img src="public/assets/icon.svg" width="28" height="28" alt=""> bruno

Installierbare Lern-App mit Anki als Datenspeicher und Lernplan.

## Installation

PHP ≥ 8.3 (cURL, Fileinfo, Mbstring, PDO SQLite, `proc_open`), Python ≥ 3.10 mit passendem [Anki-Wheel](https://pypi.org/project/anki/) und HTTPS. Den DocumentRoot auf `public/` setzen. Kein Composer, kein Frontend-Build.

```sh
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp config.example.php config.local.php
chmod 600 config.local.php
read -rs password
printf '%s\n' "$password" | php bin/bruno.php user lerner@example.org
unset password
```

Fehlt auf dem Shared Host `ensurepip`, die beiden Python-Schritte durch `python3 -m venv --without-pip .venv` und `python3 -m pip --python .venv/bin/python install -r requirements.txt` ersetzen.

`config.local.php`: privates Datenverzeichnis, KI-Schlüssel für `ai.rebuhleiv.xyz` und optional den fal.ai-Schlüssel setzen. Web und Cron müssen als derselbe Benutzer laufen. Vorlagen dürfen höchstens 8 MB groß sein; bei großen Büchern einzelne Kapitel hochladen. Schülerunterlagen vor der KI-Verarbeitung anonymisieren.

## Anki

Eine gemeinsame AnkiWeb-Sammlung, ein bruno-Konto je Stapel. Zum ersten Download mit einer leeren lokalen Sammlung starten:

```sh
read -rs password
printf '%s\n' "$password" | php bin/bruno.php sync-login anki@example.org
unset password
php bin/bruno.php sync
php bin/bruno.php assign lerner@example.org 'Name des vorhandenen Stapels'
```

Nur reguläre Stapel ohne Unterstapel können zugewiesen werden. Neue Stapel gehören automatisch dem erstellenden Konto. Bestehende Karten bleiben bei Ergänzungen unverändert. Bei einem vollständigen Sync-Konflikt wird nichts überschrieben. `sync --initial-upload` erlaubt ausschließlich einen von Anki als Upload gekennzeichneten Erstabgleich.

Beispiel für den Benutzer-Crontab (absolute Pfade anpassen):

```cron
*/5 * * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php sync
* * * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php media
17 3 * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php backup
```

Bei Hosting ohne Benutzer-Crontab können dieselben Aufträge per HTTPS auf `/maintenance.php?job=sync`, `job=media` und `job=backup` geplant werden. HTTP-Basic-Benutzer: `bruno-cron`. Ein zufälliges, separates Passwort verwenden und nur dessen SHA-256-Hash als `cron_password_hash` hinterlegen. Die URLs enthalten keine Zugangsdaten.

Die täglichen `.colpkg`-Backups enthalten die Sammlung samt Medien. Unter „Backups“ lassen sich zusätzliche Sicherungen anlegen. `backup_delete_email` in `config.local.php` bestimmt, welches Konto einzelne Backups löschen darf. Die Zugangsdatenbank separat privat sichern. Stapel-Audio, -Video und Vorlagen sind in den Stapelmetadaten verlinkt; Ankis Funktion zum Löschen ungenutzter Medien kann sie als ungenutzt einstufen. Offline werden keine privaten Stapel zwischengespeichert. Frontend-Grundstruktur: [boilerplate](https://github.com/vielhuber/boilerplate/tree/061b7cd5270cfb0eca2d738abf7db7c4567efc60).
