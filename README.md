# <img src="public/assets/icon.svg" width="28" height="28" alt=""> bruno

Installierbare Lern-App mit Anki als Datenspeicher und Lernplan.

## Installation

PHP ≥ 8.3 (cURL, Fileinfo, Mbstring, PDO SQLite, `proc_open`), Composer, Python ≥ 3.10 mit passendem [Anki-Wheel](https://pypi.org/project/anki/) und HTTPS. Den DocumentRoot auf `public/` setzen. Kein Frontend-Build.

Der Konfetti-Effekt nutzt [Canvas Confetti 1.9.4](https://github.com/catdad/canvas-confetti/releases/tag/1.9.4) (ISC), unverändert und lokal eingebunden.

Die Buttons nutzen einen lokal eingebundenen Teilfont von [Noto Color Emoji](https://fonts.google.com/specimen/Noto+Color+Emoji) (SIL OFL 1.1). Kommt ein neues Button-Emoji hinzu, erzeugt man den Teilfont mit [`text=`](https://developers.google.com/fonts/docs/css2#optimizing_your_font_requests) für die verwendeten Zeichen neu.

```sh
composer install --no-dev
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp config.example.php config.local.php
chmod 600 config.local.php
read -rs password
printf '%s\n' "$password" | php bin/bruno.php user lerner@example.org
unset password
```

Fehlt auf dem Shared Host `ensurepip`, die beiden Python-Schritte durch `python3 -m venv --without-pip .venv` und `python3 -m pip --python .venv/bin/python install -r requirements.txt` ersetzen.

`config.local.php`: privates Datenverzeichnis und KI-Schlüssel für `ai.rebuhleiv.xyz` setzen (auch für Bild-Eselsbrücken). Kartenvorschläge, Lerntexte und Textbewertungen nutzen `gpt-6.2-sol` über CLIProxyAPI und aihelper. Sprachantworten verwenden weiterhin Gemini. Für neue Lernvideos ist ein fal.ai-Schlüssel nötig. Web und Cron müssen als derselbe Benutzer laufen.

Beim Erstellen sind 1 bis 250 Karten wählbar (Standard: 10). Die KI liest bis zu zehn Vorlagen mit insgesamt höchstens 8 MB. Schülerunterlagen vor dem Upload anonymisieren. Gibt man die Kartenvorschau frei, speichert bruno den Stapel direkt in Anki. Pro Stapel lässt sich ein Lernvideo erzeugen oder ersetzen. Die geschätzte Gebühr steht am Button. Vorhandenes Audio bleibt abspielbar; neue Audiodateien werden nicht beauftragt.

Ein separates Login-Cookie hält die Anmeldung 30 Tage lang ab dem Login, auch nach einem Deployment. Alle angemeldeten Konten können unter „Benutzer“ Konten verwalten und Passwörter zurücksetzen. Das eigene Profil ist über die E-Mail-Adresse im Menü erreichbar. Beim Löschen bleiben Anki-Stapel erhalten. Das letzte Konto lässt sich nicht löschen.

Nach dem Speichern ergänzt der Media-Cron fehlende Merksätze und Merkbilder automatisch in allen als Vokabeln gekennzeichneten Stapeln. Vorhandene Merksätze und Bilder bleiben erhalten. Der Media-Cron verarbeitet bis zu zehn Merksätze und startet höchstens ein Merkbild pro Lauf. Bei einfachen Vorder-/Rückseitenkarten erscheinen beide auch auf der Anki-Rückseite. Bei Kartenvorlagen, die das Antwortfeld in der Frage verwenden, bleiben sie in bruno sichtbar.

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

`php bin/bruno.php mark-vocabulary-decks` markiert alle vorhandenen regulären Anki-Stapel als Vokabeln, auch Unterstapel und nicht zugewiesene Stapel. Die Zuordnung, Sichtbarkeit und Lernstände bleiben unverändert.

Beispiel für den Benutzer-Crontab (absolute Pfade anpassen):

```cron
*/30 * * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php sync --scheduled
* * * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php media
17 3 * * * cd /pfad/zu/bruno && /usr/bin/php bin/bruno.php backup
```

Bei Hosting ohne Benutzer-Crontab können dieselben Aufträge per HTTPS auf `/maintenance.php?job=sync`, `job=media` und `job=backup` geplant werden. HTTP-Basic-Benutzer: `bruno-cron`. Ein zufälliges, separates Passwort verwenden und nur dessen SHA-256-Hash als `cron_password_hash` hinterlegen. Die URLs enthalten keine Zugangsdaten.

Unter „Anki-Sync“ lassen sich Status und letzter erfolgreicher Abgleich sehen, ein manueller Lauf starten und das gemeinsame Intervall einstellen. Den Sync-Cronjob alle 30 Minuten aufrufen. Bei einem längeren Intervall überspringt bruno zu frühe Aufrufe, ohne Anki zu öffnen.

Die täglichen `.colpkg`-Backups enthalten die Sammlung samt Medien. Alle angemeldeten Konten können unter „Backups“ zusätzliche Sicherungen anlegen und einzelne Backups nach Bestätigung löschen. Die Zugangsdatenbank separat privat sichern. Medien liegen in Ankis `collection.media`, nicht in einer zusätzlichen Ablage von bruno. Stapel-Audio, -Video und Vorlagen sind in den Stapelmetadaten verlinkt; Ankis Funktion zum Löschen ungenutzter Medien kann sie als ungenutzt einstufen. Offline werden keine privaten Stapel zwischengespeichert. Frontend-Grundstruktur: [boilerplate](https://github.com/vielhuber/boilerplate/tree/061b7cd5270cfb0eca2d738abf7db7c4567efc60).
