# ZFS Backup Guard

Unraid-Plugin zur Übersicht und Konfiguration von ZFS-Backups.

## Stand: 2026.10.14

Diese Veröffentlichung enthält Phase 1: Dataset- und Snapshot-Übersicht, Snapshot-Dateibrowser, Job-Assistent mit Validierung, Erkennung vorhandener User-Script-Backups und Zerobyte-Backup-Ziele. Konfigurationen können gespeichert werden; ZFS und vorhandene Backup-Skripte werden dabei nicht verändert.

**Noch keine Backup-Ausführung, Cron-Aktivierung oder Wiederherstellung per Klick.** Ein aktivierter Job ist in dieser Version nur eine gespeicherte Einstellung. Vorhandene Backup-Skripte und deren Zeitpläne müssen weiterlaufen. `zbg run` und `zbg check-age` sind noch nicht verfügbar. Die Entwicklungsoberfläche unter `dev/` verwendet Mock-Daten, nicht den Server.

## Installation

Voraussetzungen: Unraid 6.12 oder neuer mit ZFS und PHP 8. Die Veröffentlichung wurde auf Unraid 7.4 mit PHP 8.4 geprüft.

In der Unraid-Konsole:

```sh
plugin install https://raw.githubusercontent.com/Knallli/unraid-zfs-backup-guard/main/plugin/zfs-backup-guard.plg
```

Die Oberfläche erscheint unter **Settings → ZFS Backup Guard**. Das Plugin lädt sein Slackware-Paket aus dem öffentlichen GitHub-Release. Die Paket-MD5 wird beim Build in die `.plg` eingetragen.

Konfiguration: `/boot/config/plugins/zfs-backup-guard/`. Die Deinstallation lässt gespeicherte Konfigurationen bestehen.

## CLI

```sh
zbg version
zbg datasets
zbg snapshots [dataset]
zbg list
zbg import
```

`import` zeigt erkannte Vorschläge als JSON und verändert keine fremden Konfigurationen.

## Entwicklung

```sh
bash build.sh
php tests/run.php
```

Der Build benötigt GNU tar, xz und die üblichen GNU-Kommandozeilenwerkzeuge. Das Paket entsteht unter `dist/`. Die Tests verwenden Fixtures und temporäre Konfigurationen; sie erstellen oder löschen keine echten ZFS-Datasets. SQLite3 ist für die Zerobyte-Testfälle erforderlich.

Der weitere Funktionsplan steht in `PLAN.md`.
