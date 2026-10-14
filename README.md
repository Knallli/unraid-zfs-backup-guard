# ZFS Backup Guard

Unraid-Plugin für ZFS-Snapshots, lokale und entfernte Backups, Zeitplanung, Überwachung und Wiederherstellung.

## Installation

Voraussetzungen: Unraid 6.12 oder neuer mit ZFS und PHP 8. Die Serverprüfung erfolgt auf Unraid 7.4 / PHP 8.4. Für Hintergrundläufe und Abbruch werden `setsid`, POSIX-/PCNTL-Unterstützung und die normalen Unraid-Kommandozeilenwerkzeuge verwendet.

```sh
plugin install https://raw.githubusercontent.com/Knallli/unraid-zfs-backup-guard/main/plugin/zfs-backup-guard.plg
```

Oberfläche: **Settings → ZFS Backup Guard**. Der Installer lädt das Paket aus dem öffentlichen GitHub-Release und prüft die in der `.plg` eingetragene MD5. Das Dashboard zeigt Jobstatus und Backup-Alter.

## Backup-Jobs

- **rsync mirror:** Snapshot-basierter Spiegel mit Löschabgleich. Kind-Dataset-Ziele und Plugin-Metadaten werden vor Löschungen geschützt. Fremde Mounts werden nicht durchquert.
- **rsync versioned:** Abgeschlossene Versionen mit Hardlinks für unveränderte Dateien und einem atomar aktualisierten `latest`-Zeiger. Fehlgeschlagene Zwischenversionen werden weder als vollständig angezeigt noch automatisch zur letzten guten Version erklärt.
- **ZFS:** Individuelle Streams für die ausgewählten Datasets, lokal oder über SSH. GUID-geprüfte gemeinsame Snapshots ermöglichen inkrementelle Streams; verschlüsselte Quellen werden roh übertragen. Unterbrochene, diesem Job eindeutig zugeordnete Empfangsstreams werden fortgesetzt. Kein erzwungenes Rollback (`receive -F`). Ziel-Datasets bleiben ungemountet und schreibgeschützt.
- **Konsistenz:** Crash-konsistent oder ausgewählte Docker-Container für den Snapshot pausieren/stoppen. Nur tatsächlich veränderte Container werden danach zurückgesetzt; zuvor gestoppte Container werden nicht gestartet. VMs bleiben crash-konsistent.
- **Prüfung:** Optionaler rsync-Prüfsummenvergleich gegen den Snapshot; Abweichungen werden als Fehler behandelt. ZFS-Empfang wird über Snapshot-GUIDs bestätigt.
- **Retention:** Gestaffelte Snapshot-/Versionsaufbewahrung nur nach Erfolg; der neueste Stand und notwendige gemeinsame Replikationsbasen bleiben erhalten. Snapshot-Löschung benötigt passenden Namen **und** lokale `zbg:job`-Eigentümerschaft. Fremde Snapshots bleiben unangetastet.

Quellen mit `mountpoint=none`, ungemountete Datasets und Zvols haben keinen Dateizugriff. Bei rsync werden sie als „nur Snapshot“ ausgewiesen; zur vollständigen Replikation solcher Quellen ZFS wählen. Abweichende Kind-Mountpoints und Dataset-Namen mit Leerzeichen werden unterstützt.

### Ziele und SSH

Dateiziele müssen kanonisch unter `/mnt` liegen, gemountet und beschreibbar sein und dürfen nicht innerhalb der Quelle liegen. Neue Zielverzeichnisse sind leer; das Plugin schreibt eine Job-Eigentumsmarkierung. Einen bestehenden Spiegel kann man nur mit der ausdrücklichen Option **Vorhandenen Spiegel übernehmen** verwenden. Diese Option erlaubt rsync-Löschungen am Spiegel; sie ist kein Probelauf. Fremde Eigentumsmarkierungen und nicht zugeordnete ZFS-Ziele werden nicht überschrieben.

Remote-Dateiziele benötigen SSH, rsync sowie GNU-Shell-/Coreutils-Werkzeuge (`find`, `stat`, `realpath`, `df`, `mv`, `rm`). ZFS-Ziele benötigen zusätzlich OpenZFS und einen ONLINE-Zielpool. Schlüssel werden als vorhandene Dateipfade referenziert, nicht kopiert. SSH arbeitet ohne interaktive Passwortabfrage und mit strikter Hostschlüsselprüfung. Den Hostschlüssel vorher vertrauenswürdig in `known_hosts` hinterlegen oder eine eigene **Known-Hosts-Datei** referenzieren; unbekannte/geänderte Hostschlüssel werden abgewiesen.

### Probelauf und Abbruch

Der Probelauf führt Vorprüfungen aus und zeigt den Plan, erzeugt aber keine Snapshots, startet keine Hooks, verändert keine Container oder Ziele und räumt nichts auf. Er zählt nicht als erfolgreiches Backup.

Job-Sperren verhindern Doppelstarts; standardmäßig serialisiert zusätzlich eine globale I/O-Sperre die Läufe. Die Oberfläche bietet Live-Status, Logs und Abbruch. Abbruch sowie das Unraid-Event `stopping_svcs` stoppen laufende Unterprozesse und stellen Containerzustände wieder her. Unvollständige Backups und ihre Snapshots bleiben zur Fehleranalyse/Fortsetzung erhalten.

## Zeitplanung und Benachrichtigungen

Aktivierte Jobs werden über `/boot/config/plugins/zfs-backup-guard/zfs-backup-guard.cron` und Unraids `update_cron` eingeplant. Speichern, Pausieren und Löschen aktualisieren die Cron-Datei. Ein stündlicher Alterscheck warnt bei Überschreitung des eingestellten maximalen Backup-Alters.

Unraid-Benachrichtigungen: alle Ereignisse, nur Fehler oder keine. Optional lässt sich eine Healthchecks-URL für Start, Erfolg und Fehler hinterlegen. Vor-/Nach-Hooks müssen vorhandene Skripte sein; sie laufen mit den Rechten des Plugins (root) und müssen vertrauenswürdig sein.

## Wiederherstellung

Snapshot- und Versionsbrowser zeigen Dateien und Ordner; versionierte SSH-Backups können ebenfalls durchsucht und auf den lokalen Server zurückkopiert werden. Standardmäßig entsteht ein neuer `restore-…`-Ordner neben dem Original. Ein eigenes Ziel muss ein **neuer** kanonischer `/mnt`-Pfad sein. Vorhandene Ziele werden nicht überschrieben. Relative, innerhalb der Auswahl liegende Symlinks werden erhalten; ausbrechende/zyklische Links und Gerätedateien werden abgewiesen. Fehlgeschlagene Kopien behalten ihren als unvollständig gemeldeten Restore-Ordner.

Snapshots können in temporäre, ausdrücklich dem Plugin zugeordnete Datasets geklont, durchsucht und wieder entfernt werden. Die Oberfläche zeigt einen Rollback-Befehl mit Warnung; ein In-place-Rollback wird **nicht ausgeführt**.

## Migration

Der Assistent erkennt bestehende User-Script-Backups und importiert Jobs atomar **deaktiviert**. Bereits durch Zerobyte weitergesicherte Spiegel können ihren bisherigen Pfad behalten; sonst wird ein neuer Versionspfad vorgeschlagen. Vorhandene Spiegel und User-Script-Dateien/Zeitpläne werden nicht gelöscht oder verändert.

1. Vorschläge prüfen, Quellen/Ziele und Konsistenz anpassen.
2. Probelauf, echter Testlauf und Dateiwiederherstellung prüfen.
3. Alten User-Script-Zeitplan selbst deaktivieren.
4. Neue Jobs aktivieren. Alte Backup-Verzeichnisse erst nach eigener Prüfung löschen.

## CLI

```sh
zbg list
zbg datasets
zbg snapshots [dataset]
zbg import [script-name]
zbg run <id> [--dry-run] [--verify]
zbg start <id> [--dry-run] [--verify]
zbg status [id]
zbg cancel <id>
zbg cancel-all
zbg log <id> [run-id]
zbg check-age
zbg schedule
zbg versions <id>
zbg restore <dataset> <snapshot> <path> [new-target]
zbg restore-version <id> <version> <path> [new-target]
zbg clone <dataset> <snapshot>
zbg clones
zbg clone-remove <dataset>
zbg rollback-command <dataset> <snapshot>
zbg version
```

Manuelle Läufe sind auch für deaktivierte Jobs erlaubt. Cron-Läufe berücksichtigen den aktivierten Zustand. `import` ohne Namen liest nur; mit Namen speichert es deaktivierte Vorschläge.

## Persistenz und Deinstallation

Konfiguration: `/boot/config/plugins/zfs-backup-guard/jobs.json` und `settings.json`, atomar und gegen konkurrierende Änderungen geschützt. Aktive Jobs können nicht umbenannt oder gelöscht werden.

Verlauf/Logs liegen standardmäßig unter `/mnt/cache/system/zfs-backup-guard/`, bei nicht verfügbarem Speicher unter `/var/log/zfs-backup-guard/`. Der Verlauf wird auf das konfigurierte Limit begrenzt (Standard: 500 pro Job). Laufzustände und Sperren liegen im RAM unter `/var/run/zfs-backup-guard/`.

Deinstallation bricht Läufe ab und entfernt Cron-Einträge und Programmdateien. Konfiguration, Backups, Snapshots und bewusst angelegte Klone bleiben erhalten. Klone vorher in der Oberfläche entfernen, falls sie nicht benötigt werden.

## Entwicklung und Tests

```sh
bash build.sh
php tests/run.php
php tests/integration.php cache --allow-zfs --ssh
# Optional: Container-Konsistenz mit einem bereits lokal vorhandenen /bin/sh-Image:
php tests/integration.php cache --allow-zfs --ssh --docker-image=alpine:latest
```

Der Build benötigt GNU tar, xz und GNU-Kommandozeilenwerkzeuge und erzeugt ein deterministisches Slackware-Paket unter `dist/`; Version und Paket-MD5 werden in der `.plg` aktualisiert. Fixture-Tests benötigen SQLite3 für die Zerobyte-Fälle und nutzen keine echten ZFS-Datasets. Integrationstests benötigen root/OpenZFS und erstellen ausschließlich zufällig benannte Test-Datasets im angegebenen Pool; sie räumen diese anschließend auf. `--ssh` startet einen eigenen Loopback-SSHD mit eigenen Schlüsseln, ohne produktive SSH-Identitäten zu ändern. Docker-Tests verwenden ausschließlich einen eigens angelegten Testcontainer und ziehen keine Images nach.

Die Entwicklungsoberfläche `dev/` verwendet ausdrücklich Mock-Daten. Die reale Unraid-Oberfläche muss separat geprüft werden.

Für 2026.10.14a auf Unraid 7.4.0-rc.1 geprüft: 120 Fixture-Tests und 74 reale Integrationsprüfungen mit dem Pool `cache`, isoliertem Loopback-SSH und einem eigenen Container aus dem bereits vorhandenen Image `postgres:17-alpine`. Die installierte Weboberfläche wurde zusätzlich mit einem echten manuell gestarteten Backup, Datei-Restore und Dashboard-Status geprüft. Bestehende Backup-Skripte und deren Zeitpläne wurden dabei nicht geändert.

`PLAN.md` dokumentiert den ursprünglichen Funktionsplan. `plugin/zfs-backup-guard.xml` enthält die Metadaten nach dem [Community-Applications-Pluginformat](https://ca.unraid.net/submit/help/repository-xml). Die Aufnahme in Community Applications erfordert eine separate manuelle Einreichung und Prüfung; sie ist nicht automatisch durch Veröffentlichung des Repositorys erfolgt.
