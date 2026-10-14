<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Erkennt, ob ein Backup-Ziel von einem nachgelagerten Tool weitergesichert wird (3-2-1).
 * Unterstützt: Zerobyte (restic-UI). Nur lesend, es werden nie Zugangsdaten gelesen.
 */
final class Downstream
{
    private static ?array $cache = null;

    /** Kandidaten für die Zerobyte-Datenbank (appdata-Mount des Containers oder Standardpfad). */
    public static function zerobyteDb(): ?string
    {
        $env = getenv('ZBG_ZEROBYTE_DB');
        if ($env) {
            return is_file($env) ? $env : null;
        }
        $candidates = ['/mnt/user/appdata/zerobyte/data/zerobyte.db', '/mnt/cache/appdata/zerobyte/data/zerobyte.db'];
        if (file_exists('/var/run/docker.sock')) {
            $r = Exec::run(['docker', 'inspect', '-f', '{{range .Mounts}}{{if eq .Destination "/var/lib/zerobyte"}}{{.Source}}{{end}}{{end}}', 'zerobyte'], 10);
            if ($r['code'] === 0 && trim($r['out']) !== '') {
                array_unshift($candidates, rtrim(trim($r['out']), '/') . '/data/zerobyte.db');
            }
        }
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Alle Zerobyte-Zeitpläne mit ihren effektiven Host-Pfaden.
     * @return list<array{tool:string,name:string,enabled:bool,cron:string,paths:list<string>,retention:array,lastStatus:?string,lastAt:?int}>
     */
    public static function plans(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        $db = self::zerobyteDb();
        if ($db === null || !class_exists(\SQLite3::class)) {
            return self::$cache;
        }
        try {
            $h = new \SQLite3($db, SQLITE3_OPEN_READONLY);
            $h->busyTimeout(2000);
            // Nur unkritische Spalten. volumes_table.config kann bei SMB/NFS Zugangsdaten enthalten,
            // deshalb wird ausschließlich der Pfad von Verzeichnis-Volumes per json_extract gelesen.
            $res = $h->query("SELECT s.name, s.enabled, s.cron_expression, s.include_paths, s.retention_policy,
                    s.last_backup_status, s.last_backup_at, v.type, json_extract(v.config, '$.path') AS vpath
                FROM backup_schedules_table s LEFT JOIN volumes_table v ON v.id = s.volume_id");
            while ($res && ($row = $res->fetchArray(SQLITE3_ASSOC))) {
                if ($row['type'] !== 'directory' || !is_string($row['vpath']) || !str_starts_with($row['vpath'], '/')) {
                    continue;
                }
                $base = rtrim($row['vpath'], '/');
                $inc = json_decode((string)$row['include_paths'], true) ?: [];
                $paths = $inc ? array_map(static fn($p) => $base . '/' . ltrim((string)$p, '/'), $inc) : [$base];
                $at = $row['last_backup_at'];
                self::$cache[] = [
                    'tool' => 'Zerobyte',
                    'name' => (string)$row['name'],
                    'enabled' => (bool)$row['enabled'],
                    'cron' => (string)$row['cron_expression'],
                    'paths' => $paths,
                    'retention' => json_decode((string)$row['retention_policy'], true) ?: [],
                    'lastStatus' => $row['last_backup_status'],
                    'lastAt' => is_numeric($at) ? (int)($at > 1e11 ? $at / 1000 : $at) : null,
                ];
            }
            $h->close();
        } catch (\Throwable $e) {
            error_log('zfs-backup-guard: Zerobyte nicht lesbar: ' . $e->getMessage());
        }
        return self::$cache;
    }

    /**
     * Welche nachgelagerten Pläne erfassen diesen Zielpfad? Treffer, wenn ein Planpfad das Ziel enthält
     * (vollständig) oder im Ziel liegt (teilweise).
     */
    public static function forTarget(string $path): array
    {
        $out = [];
        foreach (self::plans() as $p) {
            foreach ($p['paths'] as $pp) {
                $full = Paths::within($pp, $path);
                $partial = !$full && Paths::within($path, $pp);
                if ($full || $partial) {
                    $out[] = $p + ['coverage' => $full ? 'full' : 'partial', 'matchedPath' => $pp];
                    break;
                }
            }
        }
        return $out;
    }

    /** Ungefähre Übersetzung von /mnt/user/<share>/… auf Unraid-Share-Einstellungen. */
    public static function shareInfo(string $path): ?array
    {
        if (!preg_match('~^/mnt/user/([^/]+)~', $path, $m)) {
            return null;
        }
        $cfg = @parse_ini_file('/boot/config/shares/' . $m[1] . '.cfg') ?: [];
        if (!$cfg) {
            return ['share' => $m[1]];
        }
        return [
            'share' => $m[1],
            'useCache' => $cfg['shareUseCache'] ?? 'no',
            'pool' => $cfg['shareCachePool'] ?? null,
            'pool2' => $cfg['shareCachePool2'] ?? null,
        ];
    }
}
