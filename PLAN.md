# ZFS Backup Guard – Plan

Unraid-Plugin, das die ZFS-Backup-User-Scripts ablöst. Es bietet Snapshots mit Aufbewahrungsregeln, Replikation per rsync oder `zfs send` (lokal oder remote), Zeitplanung, Überwachung und Wiederherstellung, alles mit moderner Weboberfläche.

Status: Phase 1 implementiert; Veröffentlichung 2026.10.14 enthält Übersicht, Konfiguration und lesende Analyse. Backup-Ausführung, aktive Zeitplanung und Wiederherstellung sind noch nicht verfügbar. Bestehende Backup-Skripte bleiben erforderlich.

---

## 1. Ausgangslage (Ist-Analyse vom 14.10.2026, nur lesend erhoben)

**Pools:** `cache` (912G, ZFS, 82 % voll) mit den Datasets `appdata`, `data`, `domains` (inkl. `domains/Github Actions`, **mit Leerzeichen**), `fastshare`, `logs`, `lxc`, `main`, `system`, `timemachine`. Das Array besteht aus 3× XFS/LUKS (USB) mit dem Ziel-Share `/mnt/user/backup`. sanoid, syncoid, mbuffer, pv und zstd sind vorhanden.

**User Scripts:**
| Skript | Zeitplan | Funktion |
|---|---|---|
| `zfs snapshot new2093513041` | **aktiv, täglich 02:00** | rekursiver Snapshot von `cache/{main,appdata,domains,lxc}`, danach rsync `--delete --inplace -x` nach `/mnt/user/backup/zfs-test/<name>`, Kind-Datasets per Exclude geschützt, danach **alle eigenen Snapshots löschen** |
| `Autosnapshot`, `autosnapshot system` | deaktiviert | SpaceInvaderOne: sanoid-Retention 7d/4w/3m, rsync- oder syncoid-Replikation, Unraid-Notify |
| `ZFS Snapshot Domains/Immich/LXC/new` | deaktiviert | ältere Varianten |

**Schwächen des aktiven Skripts**
- Es gibt **nur eine Version**: ein Spiegel mit `--delete`. Gelöschte oder verschlüsselte (Ransomware-)Dateien sind nach dem nächsten Lauf auch im Backup weg.
- Es bleiben keine lokalen Snapshots erhalten, also keine schnelle Wiederherstellung vom Pool selbst.
- Es gibt keine Benachrichtigung, keinen Verlauf und keine Altersüberwachung („letztes erfolgreiches Backup“).
- Es gibt keine Sperre gegen parallele Läufe und keine Prüfung, ob das Ziel-Array gemountet ist.
- Datenbanken und VMs werden nur crash-konsistent gesichert, Container werden nie angehalten.
- `--inplace` auf das Array-Ziel kollidiert mit Hardlink-Versionierung, falls diese später kommt.

---

## 2. Ziele / Nicht-Ziele

**Ziele**
1. Alle Funktionen der vorhandenen Skripte abdecken, danach können die User Scripts entfernt werden.
2. Mehrere **Jobs**, jeder mit eigener Quelle, eigenem Ziel, eigener Methode, eigenem Zeitplan und eigener Retention.
3. **3-2-1-tauglich:** lokale Snapshots, eine Kopie auf dem Array und optional eine Remote-Kopie (SSH oder `zfs send`).
4. Sicher vor Datenverlust: Es werden **nur eigene Snapshots** gelöscht, aufgeräumt wird nur nach Erfolg, alles ist im Probelauf vorab sichtbar.
5. Lückenlose Überwachung über Verlauf, Unraid-Benachrichtigungen, optional einen Healthchecks-Ping und eine Warnung bei veraltetem Backup.
6. Wiederherstellung einzelner Dateien oder Ordner direkt aus der UI.

**Nicht-Ziele**
- Kein In-place-Rollback von Datasets aus der UI (`zfs rollback` nur mit Hinweis, Ausführung manuell).
- Kein eigener Verschlüsselungs- oder Deduplizierungs-Store (kein Restic/Borg). Eventuell später als Methode.
- Keine Abhängigkeit von sanoid: Die Retention wird nativ implementiert, sanoid-Snapshots werden ignoriert.

---

## 3. Funktionsumfang

### 3.1 Job-Modell
```jsonc
{
  "id": "appdata-array",
  "name": "Appdata → Array",
  "enabled": true,
  "source": { "dataset": "cache/appdata", "recursive": true, "exclude": ["cache/appdata/plex-transcode"] },
  "method": "rsync",                 // rsync | zfs
  "target": {
    "path": "/mnt/user/backup/zfs/appdata",   // rsync
    "mode": "versioned",                       // mirror | versioned
    "ssh": null                                // {host,user,port,key} für Remote
  },
  "schedule": "0 2 * * *",
  "snapshots": { "keep": { "hourly": 0, "daily": 7, "weekly": 4, "monthly": 3, "yearly": 0 } },
  "versions":  { "keep": { "daily": 14, "weekly": 8, "monthly": 6 } },   // nur versioned
  "consistency": { "mode": "crash", "stopContainers": [] },            // crash | pause | stop
  "hooks": { "pre": null, "post": null },
  "notify": "errors",                // all | errors | none
  "maxAgeHours": 30,
  "healthcheckUrl": null
}
```

### 3.2 Ablauf eines Laufs
1. **Vorprüfungen:** Sperre (`flock` pro Job und global für I/O-schwere Läufe), Pool ONLINE, Ziel gemountet und beschreibbar, freier Platz am Ziel ≥ geschätztes Delta, das Ziel liegt nicht im Quell-Dataset.
2. **Konsistenz:** optional ausgewählte Container `docker pause`/`stop`. Dann folgt sofort der Snapshot (`zfs snapshot -r`, atomar, dauert Millisekunden), danach werden die Container sofort wieder gestartet. **Die Ausfallzeit beträgt nur Sekunden**, die Kopie läuft anschließend aus dem Snapshot.
3. **Snapshot-Name:** `zbg_<jobid>_<YYYYmmdd-HHMMSS>` plus User-Property `zbg:job=<id>`. Nur Snapshots mit beidem werden jemals gelöscht.
4. **Replikation:**
   - **rsync, mirror:** Quelle ist `<mountpoint>/.zfs/snapshot/<snap>/`, Optionen `-aHAX --numeric-ids --delete --delete-excluded -x`. Kind-Datasets werden per Exclude geschützt und einzeln verarbeitet, wie bisher, aber mit korrekter Behandlung von Leerzeichen, `mountpoint=none/legacy` und `canmount=off`.
   - **rsync, versioned:** Das Ziel bekommt die Struktur `<path>/<YYYY-mm-dd_HHMMSS>/…` mit `--link-dest=<letzte Version>`. Unveränderte Dateien sind Hardlinks und kosten kaum Platz. Dazu kommt ein Symlink `latest`. Funktioniert auf XFS. `--inplace` wird hier nicht verwendet.
   - **zfs send (lokal oder SSH):** inkrementell `-I` ab dem letzten gemeinsamen Snapshot, `-w` (raw) bei verschlüsselten Quellen, Empfang mit `zfs receive -s -u` (fortsetzbar über `receive_resume_token`). Optional `mbuffer` und `pv` für Durchsatz und Fortschritt. Kein `-F` ohne ausdrückliche Option. Auf dem Ziel gilt eine eigene Retention.
5. **Prüfung (optional, z. B. wöchentlich):** `rsync -n --checksum` gegen den Snapshot. Abweichungen werden gezählt und gemeldet.
6. **Aufräumen:** Snapshot-Retention (gestaffelt stündlich/täglich/wöchentlich/monatlich/jährlich), Versions-Retention am Ziel. Läuft **nur nach erfolgreichem Lauf**, der neueste Snapshot wird nie gelöscht, und es bleibt immer mindestens ein gemeinsamer Snapshot für `zfs send` erhalten.
7. **Abschluss:** Eintrag im Verlauf (Dauer, übertragene Bytes, Dateien, Fehler), Unraid-Notify, Healthchecks-Ping (`/start`, Erfolg, `/fail`).

### 3.3 Überwachung
- Dashboard-Ampel pro Job: grün (< maxAge), gelb (überfällig), rot (letzter Lauf fehlgeschlagen).
- Stündlicher Cron-Check `zbg check-age`. Wird das Alter überschritten, kommt eine Unraid-Warnung.
- Optional eine Unraid-Dashboard-Kachel (`Menu="Dashboard:0"`) mit den Jobs.

### 3.4 Wiederherstellung
- Browser: Dataset → Snapshot oder Version → Verzeichnisbaum (über `.zfs/snapshot` bzw. Versionsordner, mit Pfad-Whitelist).
- **Datei oder Ordner wiederherstellen** nach `<Original>/restore-<datum>/` (Default, nichts wird überschrieben) oder an einen frei gewählten Zielpfad.
- Snapshot klonen zum Durchsuchen (`zfs clone` → temporäres Dataset, aufräumbar).
- Anzeige des passenden `zfs rollback`-Befehls mit Warnung, ohne Ausführung.

### 3.5 Migration aus User Scripts
- Assistent liest `/boot/config/plugins/user.scripts/scripts/*/script` und erkennt:
  - `declare -A BACKUP_JOBS=(…)`, daraus werden rsync-Jobs (aktuell 4: main, appdata, domains, lxc → `/mnt/user/backup/zfs-test/…`)
  - SpaceInvaderOne-Variablen (`source_pool`, `source_dataset`, `snapshot_days/weeks/months`, `replication`, `parent_destination_folder`, `remote_server`)
- Vorschlag: **dasselbe Ziel weiterverwenden** und auf `versioned` umstellen. Dafür wird ein neuer Unterordner angelegt, der alte Spiegel bleibt bis zur manuellen Löschung liegen.
- Checkliste: das User-Script-Schedule auf „disabled“ setzen. Das macht der Benutzer, das Plugin ändert keine fremden Plugin-Configs.

---

## 4. Architektur

```
src/usr/local/emhttp/plugins/zfs-backup-guard/
├── ZFSBackupGuard.page       # Menu="Utilities", Markdown="false", Icon/Tag="archive"
├── ZFSBackupGuardDash.page   # optional Dashboard-Kachel
├── include/api.php           # JSON-API (POST + csrf_token)
├── lib/
│   ├── Zfs.php               # list/snapshot/destroy/send/receive, Property-Parsing (-Hp)
│   ├── Job.php, JobStore.php # Validierung, Persistenz
│   ├── Runner.php            # Ablauf 3.2, Sperren, Fortschritt
│   ├── Rsync.php, Send.php   # Methoden
│   ├── Retention.php         # gestaffelte Aufbewahrung (rein funktional, testbar)
│   ├── Schedule.php          # .cron-Datei + update_cron
│   ├── Notify.php            # Unraid notify + Healthchecks
│   └── Restore.php
├── bin/zbg                   # PHP-CLI: run <job> [--dry-run]|check-age|list|restore|import
└── assets/{app.js,app.css}
plugin/zfs-backup-guard.plg
build.sh
tests/                        # Retention- und Pfad-Tests mit Fixtures
```

- **Persistenz:** `/boot/config/plugins/zfs-backup-guard/jobs.json` und `settings.json`. Verlauf und Logs liegen in `/mnt/cache/system/zfs-backup-guard/` (konfigurierbar, nicht auf dem Flash wegen der Schreiblast), Fallback `/var/log/zfs-backup-guard/`. Der Verlauf wird auf 500 Einträge pro Job begrenzt.
- **Zeitplan:** Die Datei `/boot/config/plugins/zfs-backup-guard/zfs-backup-guard.cron` wird generiert, danach läuft `/usr/local/sbin/update_cron`. Das ist der Standardweg, den Unraid für Plugins vorsieht.
- **Läufe:** Ein Lauf startet als Hintergrundprozess (`setsid`) mit PID-Datei und JSON-Fortschrittsdatei. Die UI fragt den Status ab und kann abbrechen (SIGTERM → sauberes Aufräumen, Container wieder starten).
- **Sicherheit:** API nur über POST mit Unraid-`csrf_token`. Dataset-Namen werden gegen `zfs list` validiert, Pfade per `realpath` gegen eine Whitelist (`/mnt/*`). Alle Argumente laufen über `escapeshellarg` oder `proc_open` mit Arrays. SSH-Schlüssel werden nur referenziert (Pfad), nie gespeichert oder angezeigt.
- **Robustheit:** Ein abgebrochener Lauf behält seinen Snapshot, der nächste Lauf erkennt ihn. Ein Array-Stopp während eines Laufs wird über das Event `stopping_svcs` abgefangen: Der Lauf wird abgebrochen und die Container werden wieder gestartet.

---

## 5. UI-Konzept

Kein externes CDN, das Design passt sich an das Unraid-Theme an (hell/dunkel); Akzentfarbe Teal/Blau.

1. **Übersicht:** Jobkarten mit Ampel, „letztes Backup vor 6 h“, nächster Lauf, Größe und Delta. Darunter eine Sparkline für Dauer und Datenmenge der letzten 30 Läufe, dazu die Buttons „Jetzt ausführen“, „Probelauf“ und „Pausieren“.
2. **Live-Lauf:** Fortschritt pro Dataset (Schritt, Durchsatz, ETA) und ein mitlaufendes Log mit Abbruch-Button.
3. **Job-Editor (Assistent):** Quelle (Dataset-Baum mit Größen, Excludes zum Anklicken) → Methode und Ziel (mit Live-Prüfung: gemountet, freier Platz, gleicher Pool?) → Zeitplan (Presets und Cron mit Klartext-Vorschau „täglich 02:00“) → Retention (Schieberegler mit Vorschau „≈ 14 Snapshots, ältester ~3 Monate“) → Konsistenz (Container-Auswahl aus `docker ps`) → Zusammenfassung mit Probelauf.
4. **Snapshots:** Tabelle pro Dataset (eigene und fremde Snapshots unterschieden, `used`/`referenced`) mit „Durchsuchen“ und „Klonen“.
5. **Wiederherstellen:** Dateibrowser mit Zeitleiste (Versionen und Snapshots) und „Wiederherstellen nach …“.
6. **Verlauf:** filterbare Tabelle mit Detail-Log pro Lauf.
7. **Migration:** Banner, solange User-Script-Backups erkannt werden.

---

## 6. Migrationsablauf (konkret für dieses System)

1. Plugin installieren und den Assistenten öffnen. Er importiert die 4 Jobs aus `zfs snapshot new…`.
2. Jobs anpassen. Vorschlag:
   - `appdata` und `main`: versioned nach `/mnt/user/backup/zfs/<name>`, täglich 02:00, Snapshots 24 h / 7 d / 4 w / 3 m, Container mit DBs (Postgres, MariaDB, Valkey) per `pause` für den Snapshot
   - `domains`: versioned, VMs bleiben crash-konsistent (Hinweis anzeigen)
   - `lxc`: versioned
3. Einen Probelauf aller Jobs machen und die Ausgabe prüfen.
4. Den ersten echten Lauf manuell starten und den Restore-Test einer Datei durchführen.
5. Den User-Script-Zeitplan deaktivieren (manuell). Nach 2 Wochen fehlerfreier Läufe kann `/mnt/user/backup/zfs-test` gelöscht werden (manuell).

---

## 7. Umsetzungsphasen

| Phase | Inhalt | Ergebnis |
|---|---|---|
| P1 | Gerüst, Build, plg, Page, API, Zfs.php (lesend), Snapshot-Übersicht | installierbar, nur lesend |
| P2 | Job-Modell, Runner, rsync mirror, Sperren, Verlauf, Notify, Probelauf | Ersatz des aktiven Skripts |
| P3 | Retention, versioned rsync (`--link-dest`), Konsistenzmodi, Zeitplan/Cron | volle Ablösung lokal |
| P4 | `zfs send` lokal und per SSH, fortsetzbar, Ziel-Retention | Remote/3-2-1 |
| P5 | Restore-Browser, Klon, Prüfung, Altersüberwachung, Dashboard-Kachel, Migrationsassistent | UI komplett |
| P6 | Doku, Changelog, GitHub-Release (`Knallli/unraid-zfs-backup-guard`) | CA-tauglich |

---

## 8. Tests & Validierung

- `php -l` für alle PHP-Dateien (auf dem Server in `/tmp`)
- `shellcheck` für die Skripte
- **Unit-Tests für Retention** (Schaltjahre, Wochengrenzen, leere Listen, „neuester bleibt immer“, gemeinsamer Snapshot bleibt)
- Pfad-Tests: Datasets mit Leerzeichen, verschachtelte und abweichende Mountpoints, `canmount=off`
- Integrationstest auf dem Server mit einem **Wegwerf-Dataset** `cache/zbg-test` (nur nach Freigabe): Snapshot, rsync mirror/versioned, Retention, Restore, danach aufräumen
- `zfs send`-Test lokal: `cache/zbg-test` nach `cache/zbg-test-recv`
- Fehlerfälle: Ziel nicht gemountet, voll, Abbruch mitten im Lauf, Container-Neustart nach Abbruch

---

## 9. Offene Fragen

1. Soll das Array-Backup **versioned** werden (empfohlen) oder ein Spiegel bleiben?
2. Ist ein Remote-Ziel gewünscht (zweiter Server, Hetzner, Freund), und wenn ja, mit ZFS oder nur rsync/SSH?
3. Welche Container dürfen für den Snapshot kurz pausiert werden?
4. Sollen `timemachine` (323G) und `system` (Docker-Image) bewusst ausgeschlossen bleiben?
5. Healthchecks-URL vorhanden (das User Script „Healthcheck“ existiert)?
