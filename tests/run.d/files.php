<?php
declare(strict_types=1);

/* Bereich Dateien: Pfad-Aliase, Job-Zielkonflikte, Staging-Aufräumen, Versionsnamen, Restore-Ziele. */

use ZBG\{Paths, Restore, Rsync, Versions, Zfs};

$filesTmp = sys_get_temp_dir() . '/zbg-files-' . getmypid() . '-' . bin2hex(random_bytes(3));
$filesFake = $filesTmp . '/mnt';
foreach (['cache/appdata', 'cache/backup/zfs', 'disk1/backup/zfs', 'user/appdata', 'user/backup'] as $d) {
    mkdir("$filesFake/$d", 0700, true);
}
$filesWsl = is_dir('/mnt/wsl') && is_writable('/mnt/wsl') ? '/mnt/wsl/zbg-files-' . getmypid() . '-' . bin2hex(random_bytes(3)) : null;
$filesPriv = static fn(string $class, string $method) => (new ReflectionMethod($class, $method));
$filesMsg = static function (callable $fn): string {
    try {
        $fn();
        return '';
    } catch (Throwable $e) {
        return get_class($e) . ': ' . $e->getMessage();
    }
};

try {
    /* 1: /mnt/user-Aliase */
    Paths::$mntRoot = $filesFake;
    eq('Aliase: User-Share → realer Pool-/Disk-Ordner', Paths::aliases('/mnt/user/appdata/zbg'), ['/mnt/user/appdata/zbg', '/mnt/cache/appdata/zbg']);
    check('Überlappung: /mnt/user/appdata/zbg liegt in Quelle /mnt/cache/appdata', Paths::overlap('/mnt/user/appdata/zbg', '/mnt/cache/appdata'));
    check('Überlappung: Ziel oberhalb der Quelle', Paths::overlap('/mnt/user/appdata', '/mnt/cache/appdata/immich'));
    foreach (['appdata', 'domains', 'lxc'] as $n) {
        check("Produktion: /mnt/user/backup/zfs/$n überlappt cache/$n nicht", !Paths::overlap("/mnt/user/backup/zfs/$n", "/mnt/cache/$n"));
    }
    $row2 = static fn(string $name, string $mp) => array_combine(Zfs::DS_COLS, [$name, 'filesystem', '1000', '5000', '900', '100', $mp, 'on', 'yes', 'off', '-', '1.50', '-', 'hidden', 'off', 'none']);
    $fxFiles = static function (array $paths) use ($row2): void {
        Zfs::setFixture([
            'pools' => [['name' => 'cache', 'size' => '1', 'alloc' => '0', 'free' => '1', 'health' => 'ONLINE', 'capacity' => '0', 'frag' => '0']],
            'datasets' => array_map($row2, array_keys($paths), array_values($paths)),
            'mounts' => implode("\n", array_map(static fn($n, $p) => "$n " . str_replace(' ', '\\040', $p) . ' zfs rw 0 0', array_keys($paths), array_values($paths))),
        ]);
    };
    $fxFiles(['cache' => '/mnt/cache', 'cache/appdata' => '/mnt/cache/appdata']);
    $err = $filesMsg(static fn() => Rsync::preflight(['id' => 'files-inside', 'target' => ['path' => '/mnt/user/appdata/zbg', 'mode' => 'mirror']], Zfs::plan('cache/appdata', false, [])));
    check('Preflight: Ziel /mnt/user/appdata/zbg für cache/appdata abgewiesen', str_contains($err, 'UserError') && str_contains($err, 'überlappen'), $err);
    $err = $filesMsg(static fn() => Rsync::preflight(['id' => 'files-prod', 'target' => ['path' => '/mnt/user/backup/zfs/appdata', 'mode' => 'mirror']], Zfs::plan('cache/appdata', false, [])));
    check('Preflight: Produktionsziel nicht als Überlappung abgewiesen', !str_contains($err, 'überlappen') && !str_contains($err, 'überschneidet'), $err);
    Paths::$mntRoot = '/mnt';

    /* 2: Job-Zielkonflikte */
    $rj = static fn(string $id, string $path, ?array $ssh = null, string $mode = 'mirror') => ['id' => $id, 'name' => $id, 'method' => 'rsync', 'target' => ['path' => $path, 'mode' => $mode, 'ssh' => $ssh]];
    $prod = [$rj('appdata', '/mnt/user/backup/zfs/appdata'), $rj('domains', '/mnt/user/backup/zfs/domains'), $rj('lxc', '/mnt/user/backup/zfs/lxc')];
    foreach ($prod as $j) {
        eq("Zielkonflikt: Produktion {$j['id']} konfliktfrei", Paths::jobTargetConflict($j, $prod), null);
    }
    check('Zielkonflikt: B innerhalb von A', is_string(Paths::jobTargetConflict($rj('b', '/mnt/disk1/bk/b'), [$rj('a', '/mnt/disk1/bk')])));
    check('Zielkonflikt: A oberhalb von B', is_string(Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk'), [$rj('b', '/mnt/disk1/bk/b')])));
    check('Zielkonflikt: gleiches Ziel', is_string(Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk/'), [$rj('b', '/mnt/disk1/bk', null, 'versioned')])));
    eq('Zielkonflikt: Präfix ohne Verschachtelung', Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk'), [$rj('b', '/mnt/disk1/bk2')]), null);
    eq('Zielkonflikt: eigener Job ignoriert', Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk'), [$rj('a', '/mnt/disk1/bk')]), null);
    eq('Zielkonflikt: zfs-Job ignoriert', Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk'), [['id' => 'z', 'method' => 'zfs', 'target' => ['dataset' => 'tank/x']]]), null);
    $h1 = ['host' => 'nas', 'port' => 22];
    eq('Zielkonflikt: lokal vs. remote getrennt', Paths::jobTargetConflict($rj('a', '/mnt/disk1/bk'), [$rj('b', '/mnt/disk1/bk', $h1)]), null);
    eq('Zielkonflikt: anderer Port = anderer Host', Paths::jobTargetConflict($rj('a', '/mnt/x/bk', $h1), [$rj('b', '/mnt/x/bk', ['host' => 'nas', 'port' => 2222])]), null);
    check('Zielkonflikt: gleicher Remote-Host verschachtelt', is_string(Paths::jobTargetConflict($rj('a', '/mnt/x/bk', ['host' => 'NAS', 'user' => 'u']), [$rj('b', '/mnt/x/bk/b', $h1)])));
    Paths::$mntRoot = $filesFake;
    check('Zielkonflikt: /mnt/user-Alias erkannt', is_string(Paths::jobTargetConflict($rj('a', '/mnt/user/backup/zfs'), [$rj('b', '/mnt/disk1/backup/zfs/b')])));
    Paths::$mntRoot = '/mnt';

    /* 8: SSH-Adresse */
    $cmd = ['rsync'];
    eq('SSH-Adresse: IPv6 in Klammern', Rsync::sshRemote(['host' => '2001:db8::1', 'user' => 'bk', 'port' => 22], $cmd), 'bk@[2001:db8::1]:');
    check('SSH-Adresse: -e angehängt', $cmd[1] === '-e' && str_contains($cmd[2], "'ssh'"));

    /* 5: Versionsnamen */
    check('Versionsname: altes Format', Versions::validName('2026-10-14_020000'));
    check('Versionsname: Sommerzeit-Lücke (Europe/Berlin)', Versions::validName('2026-03-29_023000'));
    check('Versionsname: Suffix -2', Versions::validName('2026-10-25_023000-2') && Versions::validName('2026-10-25_023000-99'));
    foreach (['2026-10-25_023000-1', '2026-10-25_023000-02', '2026-10-25_023000-100', '2026-13-01_000000', '2026-10-25_023000-', 'latest'] as $bad) {
        check("Versionsname ungültig: $bad", !Versions::validName($bad));
    }

    if ($filesWsl !== null) {
        /* 5: Kollision beim Abschluss → Suffix; Sortierung */
        $vbase = "$filesWsl/versions";
        $vjob = ['id' => 'files-v', 'method' => 'rsync', 'target' => ['path' => $vbase, 'mode' => 'versioned'], 'versions' => ['keep' => []]];
        mkdir($vbase, 0700, true);
        $mk = static function (string $suffix) use ($vbase): string {
            $s = "$vbase/.zbg-incomplete-$suffix";
            mkdir($s);
            file_put_contents("$s/.zbg-stage.json", "{}\n");
            return $s;
        };
        $first = Versions::complete($vjob, $mk('a'), '2026-10-25_023000', ts('2026-10-25 02:30 +02:00'));
        $second = Versions::complete($vjob, $mk('b'), '2026-10-25_023000', ts('2026-10-25 02:30 +01:00'));
        eq('Version: doppelte Ortszeit bekommt Suffix', [basename($first), basename($second)], ['2026-10-25_023000', '2026-10-25_023000-2']);
        eq('Version: latest zeigt auf Suffix-Version', readlink("$vbase/latest"), '2026-10-25_023000-2');
        check('Version: Staging-Marker entfernt', !file_exists("$second/.zbg-stage.json"));
        eq('Version: Liste sortiert neueste zuerst', array_column(Versions::list($vjob), 'name'), ['2026-10-25_023000-2', '2026-10-25_023000']);

        /* 3 + 6: liegengebliebene eigene Staging-Ordner */
        $sbase = "$filesWsl/stage";
        $sjob = ['id' => 'files-s', 'target' => ['path' => $sbase, 'mode' => 'versioned']];
        $owner = json_encode(['job' => 'files-s', 'mode' => 'versioned'], JSON_UNESCAPED_SLASHES);
        mkdir($sbase, 0700, true);
        file_put_contents("$sbase/.zbg-target.json", "$owner\n");
        foreach (['own' => $owner, 'foreign' => '{"job":"other","mode":"versioned"}', 'legacy' => null] as $k => $marker) {
            mkdir("$sbase/.zbg-incomplete-$k/data", 0700, true);
            if ($marker !== null) {
                file_put_contents("$sbase/.zbg-incomplete-$k/.zbg-stage.json", "$marker\n");
            }
        }
        $logged = [];
        $filesPriv(Rsync::class, 'prepare')->invoke(null, $sjob, $sbase, "$sbase/.zbg-incomplete-new", false, static function (string $l) use (&$logged): void { $logged[] = $l; });
        check('Staging: eigener abgebrochener Ordner entfernt', !file_exists("$sbase/.zbg-incomplete-own"));
        check('Staging: fremder und unmarkierter Ordner bleiben', is_dir("$sbase/.zbg-incomplete-foreign") && is_dir("$sbase/.zbg-incomplete-legacy"));
        eq('Staging: neuer Ordner trägt Job-Marker', trim((string)@file_get_contents("$sbase/.zbg-incomplete-new/.zbg-stage.json")), $owner);
        check('Staging: Aufräumen protokolliert', count($logged) === 1 && str_contains($logged[0], '.zbg-incomplete-own'));
        check('Rsync: Staging-Marker vor --delete geschützt', in_array('--filter=P /.zbg-stage.json', $filesPriv(Rsync::class, 'command')->invoke(null, ['target' => ['mode' => 'versioned']], ['items' => []], ['name' => 'p', 'rel' => '', 'targetRel' => '', 'protect' => []], '/s/', '/d', null, false, false), true));

        $ubase = "$filesWsl/unowned";
        mkdir("$ubase/x", 0700, true);
        $err = $filesMsg(static fn() => $filesPriv(Rsync::class, 'prepare')->invoke(null, ['id' => 'u', 'target' => ['path' => $ubase, 'mode' => 'mirror']], $ubase, $ubase, false, static function (): void {}));
        check('Remote-Skript: nichtleeres fremdes Ziel nennt Grund', str_contains($err, 'Nichtleeres Ziel'), $err);

        /* 2: fremde .zbg-target.json tiefer im Spiegelziel */
        $msrc = "$filesWsl/src";
        $mbase = "$filesWsl/mirror";
        mkdir("$msrc/sub", 0700, true);
        mkdir("$mbase/nested/b", 0700, true);
        $fxFiles(['cache' => '/mnt/cache', 'cache/src' => $msrc]);
        $mjob = ['id' => 'files-m', 'target' => ['path' => $mbase, 'mode' => 'mirror']];
        file_put_contents("$mbase/.zbg-target.json", json_encode(['job' => 'files-m', 'mode' => 'mirror'], JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents("$mbase/nested/b/.zbg-target.json", "{\"job\":\"b\",\"mode\":\"mirror\"}\n");
        $err = $filesMsg(static fn() => Rsync::preflight($mjob, Zfs::plan('cache/src', false, [])));
        check('Preflight: fremdes Job-Ziel im Spiegel abgewiesen', str_contains($err, 'nested/b'), $err);
        removeFixtureTree("$mbase/nested");
        mkdir("$mbase/sub", 0700, true);
        file_put_contents("$mbase/sub/.zbg-target.json", "{}\n");
        file_put_contents("$msrc/sub/.zbg-target.json", "{}\n");
        $err = $filesMsg(static fn() => Rsync::preflight($mjob, Zfs::plan('cache/src', false, [])));
        check('Preflight: aus der Quelle gespiegelte Markierung erlaubt', !str_contains($err, 'anderen Jobs'), $err);
    }

    /* 4: Restore-Ziel nie im RAM */
    $storage = $filesPriv(Restore::class, 'assertStorage');
    throws('Restore: fehlende Disk /mnt/<x> wird nicht angelegt', static fn() => $storage->invoke(null, '/mnt/zbg-missing-' . getmypid() . '/a/restore-1'));
    if ($filesWsl !== null) {
        throws('Restore: tmpfs-Ziel abgewiesen', static fn() => $storage->invoke(null, "$filesWsl/neu/restore-1"));
    }
    $real = '/mnt/c/Windows/Temp';
    if (is_dir($real) && (Paths::mountFor($real)['mountpoint'] ?? '/') !== '/') {
        eq('Restore: Ziel auf echtem Mount erlaubt', $filesMsg(static fn() => $storage->invoke(null, "$real/zbg-neu/restore-1", 1)), '');
        throws('Restore: zu wenig Platz', static fn() => $storage->invoke(null, "$real/zbg-neu/restore-1", PHP_INT_MAX));
    }

    /* 7: Standardziel eines Kind-Datasets unter .datasets/<rel> */
    $fxFiles(['cache' => '/mnt/cache', 'cache/lxc' => '/mnt/cache/lxc', 'cache/lxc/pihole' => '/var/lib/lxc/pihole/rootfs', 'cache/lxc/inner' => '/mnt/cache/lxc/custom/inner']);
    $orig = static fn(string $base) => $filesPriv(Restore::class, 'originalPath')->invoke(null, ['source' => ['dataset' => 'cache/lxc', 'recursive' => true, 'exclude' => []]], $base);
    eq('Restore: .datasets/<rel> → echter Kind-Mountpunkt', $orig('.datasets/pihole/etc'), '/var/lib/lxc/pihole/rootfs/etc');
    eq('Restore: Kind-Wurzel', $orig('.datasets/pihole'), '/var/lib/lxc/pihole/rootfs');
    eq('Restore: Wurzel', $orig(''), '/mnt/cache/lxc');
    eq('Restore: Kind innerhalb', $orig('custom/inner/x'), '/mnt/cache/lxc/custom/inner/x');
    eq('Restore: normaler Unterordner', $orig('custom/other'), '/mnt/cache/lxc/custom/other');
} finally {
    Paths::$mntRoot = '/mnt';
    removeFixtureTree($filesTmp);
    if ($filesWsl !== null) {
        removeFixtureTree($filesWsl);
    }
}
