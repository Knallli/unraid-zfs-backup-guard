<?php
declare(strict_types=1);

namespace ZBG;

/** ZFS inventory, exact atomic snapshots and ownership-checked retention. */
final class Zfs
{
    public const SNAP_PREFIX = 'zbg_';
    public const JOB_PROP = 'zbg:job';
    private const NAME_RE = '~^[A-Za-z0-9][A-Za-z0-9_.:\- /]*$~';

    /** Max. Länge eines Snapshot-Namens inkl. „@“: zbg_ + 40 Zeichen ID + _JJJJMMTT-HHMMSS */
    public const SNAP_SUFFIX_MAX = 1 + 4 + 40 + 16;
    public const NAME_MAX = 255;

    private static ?array $datasetCache = null;
    private static ?array $poolCache = null;
    /** @var array{datasets?:list<array>,pools?:list<array>,mounts?:string}|null Testdaten statt Systemaufrufen */
    private static ?array $fixture = null;

    public static function setFixture(?array $f): void
    {
        self::$fixture = $f;
        self::$datasetCache = self::$poolCache = null;
    }

    public static function pools(): array
    {
        if (self::$poolCache !== null) {
            return self::$poolCache;
        }
        $rows = self::$fixture['pools'] ?? Exec::rows(
            Exec::ok(['zpool', 'list', '-Hp', '-o', 'name,size,alloc,free,health,capacity,fragmentation']),
            ['name', 'size', 'alloc', 'free', 'health', 'capacity', 'frag']
        );
        return self::$poolCache = array_map(static fn(array $r) => [
            'name' => $r['name'],
            'size' => (int)$r['size'],
            'alloc' => (int)$r['alloc'],
            'free' => (int)$r['free'],
            'health' => $r['health'],
            'capacity' => (int)$r['capacity'],
            'fragmentation' => (int)($r['frag'] ?? 0),
            // Boot-Pool: Unraid läuft hier nicht vom USB-Stick, sondern von einem ZFS-Pool (z. B. NVMe-Mirror)
            'boot' => $r['name'] === self::bootPool(),
        ], $rows);
    }

    public static function pool(string $name): ?array
    {
        foreach (self::pools() as $p) {
            if ($p['name'] === $name) {
                return $p;
            }
        }
        return null;
    }

    /** Dataset, das unter /boot eingehängt ist (Unraid-Boot auf ZFS), sonst null (klassischer USB-Stick). */
    public static function bootDataset(): ?string
    {
        foreach (self::mounts() as $ds => $path) {
            if ($path === '/boot') {
                return $ds;
            }
        }
        return null;
    }

    public static function bootPool(): ?string
    {
        $b = self::bootDataset();
        return $b === null ? null : explode('/', $b)[0];
    }

    public const DS_COLS = ['name', 'type', 'used', 'avail', 'refer', 'usedsnap', 'mountpoint', 'canmount', 'mounted',
        'encryption', 'keystatus', 'compressratio', 'origin', 'snapdir', 'readonly', 'snapshot_limit'];

    public static function datasets(): array
    {
        if (self::$datasetCache !== null) {
            return self::$datasetCache;
        }
        $rows = self::$fixture['datasets'] ?? Exec::rows(Exec::ok([
            'zfs', 'list', '-Hp', '-t', 'filesystem,volume', '-o',
            'name,type,used,avail,refer,usedbysnapshots,mountpoint,canmount,mounted,encryption,keystatus,compressratio,origin,snapdir,readonly,snapshot_limit',
        ]), self::DS_COLS);
        return self::$datasetCache = self::enrich($rows, self::mounts());
    }

    /** Aktive ZFS-Mounts aus /proc/mounts: dataset => Pfad (für mountpoint=legacy). */
    public static function mounts(): array
    {
        $raw = self::$fixture['mounts'] ?? (string)@file_get_contents('/proc/mounts');
        $m = [];
        foreach (explode("\n", $raw) as $line) {
            $p = explode(' ', $line);
            if (($p[2] ?? '') === 'zfs') {
                $dec = static fn(string $s) => preg_replace_callback('~\\\\([0-7]{3})~', static fn($x) => chr(octdec($x[1])), $s);
                $m[$dec($p[0])] = $dec($p[1]);
            }
        }
        return $m;
    }

    /**
     * Normalisiert Rohzeilen und bewertet jedes Dataset auf Sonderfälle.
     * readable = Dateien sind über <path>/.zfs/snapshot lesbar (Voraussetzung für rsync und Browser).
     */
    public static function enrich(array $rows, array $mounts): array
    {
        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }
        $bootDs = null;
        foreach ($mounts as $ds => $p) {
            if ($p === '/boot') {
                $bootDs = $ds;
            }
        }
        $bootPool = $bootDs === null ? null : explode('/', $bootDs)[0];
        $list = [];
        foreach ($rows as $r) {
            $name = $r['name'];
            $pool = explode('/', $name)[0];
            $isVol = ($r['type'] ?? 'filesystem') === 'volume';
            $mp = $r['mountpoint'] ?? '-';
            $mounted = ($r['mounted'] ?? 'no') === 'yes';
            $locked = ($r['keystatus'] ?? '-') === 'unavailable';
            $path = null;
            if (!$isVol && $mounted) {
                $path = str_starts_with($mp, '/') ? $mp : ($mounts[$name] ?? null);
            }
            $issues = [];
            $add = static function (string $code, string $level, string $msg) use (&$issues) {
                $issues[] = ['code' => $code, 'level' => $level, 'msg' => $msg];
            };
            $isBoot = $name === $bootDs || $mp === '/boot';
            if ($isBoot) {
                $add('boot', 'info', 'Unraid-Boot-Dataset (/boot): Snapshot und Kopie sind ein vollständiges Flash-Backup. Zurückspielen nur manuell.');
            } elseif ($pool === $bootPool) {
                $add('bootpool', 'info', "Liegt im Boot-Pool $pool");
            }
            if ($isVol) {
                $add('volume', 'info', 'Zvol (Blockgerät): keine Dateien, nur per zfs send sicherbar');
            } elseif ($locked) {
                $add('locked', 'warn', 'Verschlüsselt, Schlüssel nicht geladen: nicht gemountet, rsync unmöglich, zfs send nur roh (-w)');
            } elseif (!$mounted) {
                $why = match (true) {
                    $mp === 'none' => 'mountpoint=none',
                    ($r['canmount'] ?? 'on') === 'off' => 'canmount=off',
                    ($r['canmount'] ?? 'on') === 'noauto' => 'canmount=noauto',
                    $mp === 'legacy' => 'mountpoint=legacy, nicht eingehängt',
                    default => 'nicht gemountet',
                };
                $add('unmounted', 'warn', "Nicht gemountet ($why): rsync überspringt dieses Dataset, zfs send funktioniert");
            } elseif ($mp === 'legacy') {
                $add('legacy', 'info', "mountpoint=legacy, eingehängt unter $path");
            }
            $parent = strrpos($name, '/') !== false ? substr($name, 0, strrpos($name, '/')) : null;
            if ($parent !== null && $path !== null && isset($byName[$parent])) {
                $pmp = $byName[$parent]['mountpoint'] ?? '-';
                $leaf = substr($name, strlen($parent) + 1);
                if (str_starts_with($pmp, '/') && $path !== rtrim($pmp, '/') . '/' . $leaf) {
                    $inside = Paths::within($pmp, $path);
                    $add('detached', 'info', $inside
                        ? "Abweichender Mountpoint innerhalb des Eltern-Datasets ($path)"
                        : "Mountpoint außerhalb des Eltern-Datasets ($path)");
                }
            }
            if (($r['origin'] ?? '-') !== '-' && ($r['origin'] ?? '') !== '') {
                $add('clone', 'info', "Klon von {$r['origin']}: zfs send überträgt ihn als eigenständiges Dataset");
            }
            $limit = $r['snapshot_limit'] ?? 'none';
            if ($limit !== 'none' && $limit !== '-' && $limit !== '18446744073709551615') {
                $add('snaplimit', 'warn', "snapshot_limit=$limit kann neue Snapshots verhindern");
            }
            if (strlen($name) + self::SNAP_SUFFIX_MAX > self::NAME_MAX) {
                $add('namelen', 'block', 'Name zu lang: Snapshot-Name würde 255 Zeichen überschreiten');
            }
            if (preg_match('~[^A-Za-z0-9_.:/\-]~', $name)) {
                $add('chars', 'info', 'Enthält Leerzeichen oder Sonderzeichen – wird korrekt behandelt');
            }
            $list[] = [
                'name' => $name,
                'pool' => $pool,
                'type' => $isVol ? 'volume' : 'filesystem',
                'used' => (int)($r['used'] ?? 0),
                'avail' => (int)($r['avail'] ?? 0),
                'refer' => (int)($r['refer'] ?? 0),
                'usedSnap' => (int)($r['usedsnap'] ?? 0),
                'mountpoint' => $mp,
                'path' => $path,
                'canmount' => $r['canmount'] ?? '-',
                'mounted' => $mounted,
                'encrypted' => !in_array($r['encryption'] ?? 'off', ['off', '-'], true),
                'locked' => $locked,
                'readonly' => ($r['readonly'] ?? 'off') === 'on',
                'origin' => ($r['origin'] ?? '-') === '-' ? null : $r['origin'],
                'compressratio' => (float)($r['compressratio'] ?? 1),
                'readable' => $path !== null && !$locked,
                'boot' => $isBoot,
                'bootPool' => $pool === $bootPool,
                'issues' => $issues,
            ];
        }
        return $list;
    }

    /**
     * Plant, was ein Job mit dieser Quelle tatsächlich verarbeitet (für Editor-Vorschau und Runner).
     *
     * - Ausgeschlossene Datasets schließen ihren ganzen Teilbaum aus.
     * - Jedes Dataset wird einzeln aus seinem eigenen .zfs/snapshot kopiert. Das Ziel ergibt sich aus dem
     *   Mountpfad relativ zur Quelle; Kinder außerhalb des Quellpfads landen unter .datasets/<relativer Name>.
     * - Unterpfade, an denen andere Datasets eingehängt sind, werden beim Eltern-rsync ausgeschlossen
     *   (sonst würde --delete die Kopie des Kindes löschen).
     */
    public static function plan(string $source, bool $recursive, array $exclude): array
    {
        $all = self::datasets();
        $root = null;
        foreach ($all as $d) {
            if ($d['name'] === $source) {
                $root = $d;
            }
        }
        if ($root === null) {
            throw new UserError("Dataset nicht gefunden: $source");
        }
        $isExcluded = static function (string $n) use ($exclude): bool {
            foreach ($exclude as $e) {
                if ($n === $e || str_starts_with($n, $e . '/')) {
                    return true;
                }
            }
            return false;
        };
        $members = [];
        $skipped = [];
        foreach ($all as $d) {
            $isMember = $d['name'] === $source || ($recursive && str_starts_with($d['name'], $source . '/'));
            if (!$isMember) {
                continue;
            }
            if ($isExcluded($d['name'])) {
                $skipped[] = ['name' => $d['name'], 'reason' => 'excluded'];
                continue;
            }
            $members[] = $d;
        }
        $base = $root['readable'] ? rtrim((string)$root['path'], '/') : null;
        $items = [];
        $mountedPaths = [];
        foreach ($all as $d) {
            if ($d['path'] !== null && $d['name'] !== $source) {
                $mountedPaths[$d['name']] = rtrim($d['path'], '/');
            }
        }
        // auch Mounts anderer Pools (z. B. fremdes Dataset unterhalb der Quelle); Snapshot-Automounts ignorieren
        foreach (self::mounts() as $name => $mp) {
            if (!str_contains($name, '@') && $name !== $source && !isset($mountedPaths[$name])) {
                $mountedPaths[$name] = rtrim($mp, '/');
            }
        }
        foreach ($members as $d) {
            $rel = $d['name'] === $source ? '' : substr($d['name'], strlen($source) + 1);
            $copy = $d['readable'];
            $targetRel = null;
            if ($copy) {
                $p = rtrim((string)$d['path'], '/');
                $targetRel = ($base !== null && Paths::within($base, $p)) ? ltrim(substr($p, strlen($base)), '/') : '.datasets/' . $rel;
            }
            // Mountpunkte anderer Datasets (auch fremder Pools) unterhalb dieses Pfads: beim rsync ausschließen
            $protect = [];
            if ($copy) {
                $p = rtrim((string)$d['path'], '/');
                foreach ($mountedPaths as $other => $op) {
                    if ($other !== $d['name'] && $op !== $p && Paths::within($p, $op)) {
                        $protect[] = '/' . ltrim(substr($op, strlen($p)), '/') . '/';
                    }
                }
                sort($protect);
            }
            $items[] = [
                'name' => $d['name'],
                'rel' => $rel,
                'action' => $copy ? 'copy' : 'snapshot-only',
                'path' => $d['path'],
                'targetRel' => $targetRel,
                'protect' => $protect,
                'used' => $d['used'],
                'issues' => $d['issues'],
            ];
        }
        $warnings = [];
        if ($root['bootPool']) {
            $warnings[] = ['level' => 'info', 'msg' => 'Quelle liegt im Unraid-Boot-Pool. Ideal als Flash-Backup; ein Rollback des laufenden /boot ist nicht vorgesehen.'];
        }
        $pool = self::pool($root['pool']);
        if ($pool && $pool['health'] !== 'ONLINE') {
            $warnings[] = ['level' => $pool['health'] === 'DEGRADED' ? 'warn' : 'block', 'msg' => "Pool {$pool['name']} ist {$pool['health']}"];
        }
        $snapOnly = array_filter($items, static fn($i) => $i['action'] === 'snapshot-only');
        if ($snapOnly) {
            $warnings[] = ['level' => 'warn', 'msg' => count($snapOnly) . ' Dataset(s) sind nicht lesbar und werden per rsync nicht kopiert (nur Snapshot)'];
        }
        return ['source' => $source, 'items' => $items, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    public static function dataset(string $name): array
    {
        foreach (self::datasets() as $ds) {
            if ($ds['name'] === $name) {
                return $ds;
            }
        }
        throw new UserError("Dataset nicht gefunden: $name");
    }

    public static function assertDataset(string $name): void
    {
        if (!preg_match(self::NAME_RE, $name) || str_contains($name, '@')) {
            throw new UserError('Ungültiger Dataset-Name');
        }
        self::dataset($name);
    }

    /**
     * Snapshots eines Datasets (oder aller). Eigene Snapshots sind an Präfix UND Property erkennbar.
     */
    public static function snapshots(?string $dataset = null, bool $recursive = false): array
    {
        $cmd = ['zfs', 'list', '-Hp', '-t', 'snapshot', '-o', 'name,used,refer,creation,' . self::JOB_PROP, '-s', 'creation'];
        if ($dataset !== null) {
            self::assertDataset($dataset);
            if (!$recursive) {
                array_push($cmd, '-d', '1');
            } else {
                $cmd[] = '-r';
            }
            $cmd[] = $dataset;
        }
        $out = Exec::ok($cmd, 120);
        $propertyCommand = ['zfs', 'get', '-Hp', '-t', 'snapshot', '-s', 'local', '-o', 'name,value'];
        if ($dataset !== null) {
            array_push($propertyCommand, ...($recursive ? ['-r'] : ['-d', '1']));
        }
        $propertyCommand[] = self::JOB_PROP;
        if ($dataset !== null) {
            $propertyCommand[] = $dataset;
        }
        $localOwners = [];
        foreach (Exec::rows(Exec::ok($propertyCommand, 120), ['name', 'job']) as $property) {
            $localOwners[$property['name']] = $property['job'];
        }
        $list = [];
        foreach (Exec::rows($out, ['name', 'used', 'refer', 'creation', 'job']) as $r) {
            [$ds, $snap] = explode('@', $r['name'], 2);
            $job = $r['job'] === '-' ? null : $r['job'];
            $list[] = [
                'dataset' => $ds,
                'snapshot' => $snap,
                'used' => (int)$r['used'],
                'refer' => (int)$r['refer'],
                'creation' => (int)$r['creation'],
                'job' => $job,
                'own' => self::isOwn($snap, $job) && ($localOwners[$r['name']] ?? null) === $job,
                'kind' => self::classify($snap, $job),
            ];
        }
        return $list;
    }

    public static function isOwn(string $snap, ?string $job): bool
    {
        return $job !== null
            && preg_match('~^' . self::SNAP_PREFIX . '([a-z0-9-]+)_\d{8}-\d{6}$~', $snap, $m) === 1
            && $m[1] === $job;
    }

    /** Herkunft für die Anzeige: zbg | sanoid | syncoid | userscript | manual */
    public static function classify(string $snap, ?string $job): string
    {
        return match (true) {
            self::isOwn($snap, $job) => 'zbg',
            str_starts_with($snap, 'autosnap_') => 'sanoid',
            str_starts_with($snap, 'syncoid_') => 'syncoid',
            str_starts_with($snap, 'rsync_auto') => 'userscript',
            default => 'manual',
        };
    }

    /** Verzeichnis eines Snapshots, nur für gemountete Datasets mit echtem Mountpoint. */
    public static function snapshotDir(string $dataset, string $snapshot): string
    {
        $ds = self::dataset($dataset);
        if (!$ds['readable']) {
            $why = $ds['issues'][0]['msg'] ?? "mountpoint={$ds['mountpoint']}";
            throw new UserError("Dateien von $dataset sind nicht lesbar: $why");
        }
        // Snapshot-Namen dürfen u. a. Leerzeichen enthalten (manuell angelegt) – nur Pfadtrenner verbieten
        if ($snapshot === '' || str_contains($snapshot, '/') || str_contains($snapshot, "\0") || $snapshot === '.' || $snapshot === '..') {
            throw new UserError('Ungültiger Snapshot-Name');
        }
        // .zfs ist auch bei snapdir=hidden per Pfad erreichbar; der Zugriff mountet den Snapshot automatisch
        $dir = rtrim((string)$ds['path'], '/') . '/.zfs/snapshot/' . $snapshot;
        if (!is_dir($dir)) {
            throw new UserError("Snapshot nicht gefunden: $dataset@$snapshot");
        }
        return $dir;
    }

    public static function refresh(): void
    {
        self::$datasetCache = self::$poolCache = null;
    }

    /** One ZFS transaction, without -r: exclusions are never snapshotted accidentally. */
    public static function snapshotSelected(array $job, array $plan, string $snapshot): void
    {
        if (!self::isOwn($snapshot, $job['id'])) {
            throw new UserError('Ungültiger eigener Snapshot-Name');
        }
        $cmd = ['zfs', 'snapshot', '-o', self::JOB_PROP . '=' . $job['id']];
        foreach ($plan['items'] as $item) {
            self::assertDataset($item['name']);
            $name = $item['name'] . '@' . $snapshot;
            if (strlen($name) > self::NAME_MAX) {
                throw new UserError("Snapshot-Name ist zu lang: $name");
            }
            $cmd[] = $name;
        }
        if (count($cmd) === 4) {
            throw new UserError('Keine Datasets ausgewählt');
        }
        Exec::ok($cmd, 120);
    }

    public static function destroyOwned(string $dataset, string $snapshot, string $job): void
    {
        self::assertDataset($dataset);
        if (!self::isOwn($snapshot, $job)) {
            throw new UserError('Fremder Snapshot wird nicht gelöscht');
        }
        $name = $dataset . '@' . $snapshot;
        $property = explode("\t", trim(Exec::ok(['zfs', 'get', '-Hp', '-o', 'value,source', self::JOB_PROP, $name])));
        if (($property[0] ?? '') !== $job || ($property[1] ?? '') !== 'local') {
            throw new UserError("Snapshot hat keine lokale Eigentümerschaft: $name");
        }
        Exec::ok(['zfs', 'destroy', $name], 120);
    }

    /** Löschen aus der Oberfläche: nur eigene Snapshots, nie während der Job läuft, nie die ZFS-Replikationsbasis. */
    public static function destroyByUser(string $dataset, string $snapshot): void
    {
        $own = array_values(array_filter(self::snapshots($dataset), static fn($s) => $s['own']));
        $hit = current(array_filter($own, static fn($s) => $s['snapshot'] === $snapshot)) ?: null;
        if ($hit === null) {
            throw new UserError('Nur eigene Snapshots von Backup Guard können gelöscht werden');
        }
        $lock = RunState::lockJob($hit['job'], true);
        try {
            $job = current(array_filter(JobStore::all(), static fn($j) => $j['id'] === $hit['job'])) ?: null;
            $jobSnaps = array_values(array_filter($own, static fn($s) => $s['job'] === $hit['job']));
            // ponytail: schützt nur den neuesten Snapshot; ein älterer Resume-Basis-Snapshot nach Abbruch wird nicht erkannt
            if (($job['method'] ?? '') === 'zfs' && end($jobSnaps)['snapshot'] === $snapshot) {
                throw new UserError('Der neueste Snapshot ist die Basis der nächsten inkrementellen Replikation und bleibt erhalten');
            }
            self::destroyOwned($dataset, $snapshot, $hit['job']);
        } finally {
            fclose($lock);
        }
    }

    /** Bytes seit dem letzten eigenen Snapshot des Jobs (ZFS "written@"), ohne Snapshot das referenzierte Volumen. */
    public static function changedSince(string $dataset, string $job): int
    {
        $own = array_values(array_filter(self::snapshots($dataset), static fn($s) => $s['own'] && $s['job'] === $job));
        $prop = $own === [] ? 'referenced' : 'written@' . end($own)['snapshot'];
        return (int)trim(Exec::ok(['zfs', 'get', '-Hp', '-o', 'value', $prop, $dataset]));
    }

    public static function prune(array $job, array $plan, array $protected, callable $log, ?callable $cancel = null): void
    {
        foreach ($plan['items'] as $item) {
            $items = [];
            foreach (self::snapshots($item['name']) as $snapshot) {
                if ($snapshot['own'] && $snapshot['job'] === $job['id']) {
                    $items[] = ['name' => $snapshot['snapshot'], 'time' => $snapshot['creation']];
                }
            }
            $protect = array_map(static fn($name) => str_contains($name, '@') ? explode('@', $name, 2)[1] : $name, $protected[$item['name']] ?? []);
            $retention = Retention::plan($items, $job['snapshots']['keep'], $protect);
            foreach ($retention['destroy'] as $name) {
                if ($cancel !== null && $cancel()) {
                    throw new \RuntimeException('Abgebrochen');
                }
                $log("Snapshot-Retention: {$item['name']}@$name");
                self::destroyOwned($item['name'], $name, $job['id']);
            }
        }
    }
}
