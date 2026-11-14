<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* ZFS: Retention mit Klonen/Holds, Replikate im Plan, zfs send        */
/* ------------------------------------------------------------------ */

use ZBG\{Exec, Remote, RunState, Send, UserError, Zfs};

if (!method_exists(Exec::class, 'fake')) {
    check('ZFS-Tests: Exec::fake vorhanden', false, 'Exec::fake fehlt');
    return;
}

$zbgFixture = (new ReflectionProperty(Zfs::class, 'fixture'))->getValue();
$priv = static fn(string $class, string $method) => Closure::bind(
    static fn(...$args) => $class::$method(...$args), null, $class);

/** Minimales ZFS: Datasets mit Properties, Snapshots je Dataset in Erstellungsreihenfolge. */
final class FakeZfs
{
    public array $props = [];
    public array $snaps = [];
    public array $calls = [];
    public array $resumeOut = ['code' => 0, 'out' => '', 'err' => ''];
    /** @var callable|null */
    public $onReceive = null;

    public function prop(string $name, string $prop): array
    {
        return $this->props[$name][$prop] ?? (str_contains($name, '@') && $prop === 'userrefs' ? ['0', '-'] : ['-', '-']);
    }

    public function snap(string $ds, string $snap, string $guid, int $time, ?string $job, array $props = []): void
    {
        $this->snaps[$ds][] = ['name' => "$ds@$snap", 'guid' => $guid, 'time' => $time];
        if ($job !== null) {
            $props['zbg:job'] = [$job, 'local'];
        }
        $this->props["$ds@$snap"] = $props;
    }

    public function __invoke(array $cmd, int $timeout): ?array
    {
        $this->calls[] = $cmd;
        $ok = static fn(string $out = '') => ['code' => 0, 'out' => $out, 'err' => ''];
        $last = end($cmd);
        $j = implode(' ', array_slice($cmd, 0, -1));
        if ($cmd[0] === 'zpool') {
            return $ok("$last\tONLINE\t100000000000\n");
        }
        if ($cmd[0] === 'bash' && ($cmd[1] ?? '') === '-c') {
            return ['code' => 1, 'out' => '', 'err' => ''];
        }
        if ($cmd[0] === 'bash') {
            ($this->onReceive)($cmd[4]);
            return $ok();
        }
        if ($j === 'zfs list -Hp -r -t filesystem,volume -o name,type,available') {
            $out = '';
            foreach (array_keys($this->props) as $n) {
                if (!str_contains($n, '@') && ($n === $last || str_starts_with($n, $last . '/'))) {
                    $out .= "$n\tfilesystem\t100000000000\n";
                }
            }
            return $ok($out);
        }
        if (str_starts_with($j, 'zfs list -Hp -r -d 1 -t snapshot -o name,guid,creation')) {
            return $ok(implode('', array_map(static fn($s) => "{$s['name']}\t{$s['guid']}\t{$s['time']}\n", $this->snaps[$last] ?? [])));
        }
        if ($j === 'zfs get -Hp -r -d 1 -t snapshot -o name,value,source zbg:job') {
            return $ok(implode('', array_map(fn($s) => $s['name'] . "\t" . implode("\t", $this->prop($s['name'], 'zbg:job')) . "\n", $this->snaps[$last] ?? [])));
        }
        if (str_starts_with($j, 'zfs list -Hp -t snapshot -o name,used,refer,creation,zbg:job')) {
            return $ok(implode('', array_map(fn($s) => "{$s['name']}\t0\t0\t{$s['time']}\t" . $this->prop($s['name'], 'zbg:job')[0] . "\n", $this->snaps[$last] ?? [])));
        }
        if (str_starts_with($j, 'zfs get -Hp -t snapshot -s local -o name,value')) {
            $out = '';
            foreach ($this->snaps[$last] ?? [] as $s) {
                if ($this->prop($s['name'], 'zbg:job')[1] === 'local') {
                    $out .= $s['name'] . "\t" . $this->prop($s['name'], 'zbg:job')[0] . "\n";
                }
            }
            return $ok($out);
        }
        if (array_slice($cmd, 0, 5) === ['zfs', 'get', '-Hp', '-o', 'property,value,source']) {
            $out = '';
            foreach (explode(',', $cmd[5]) as $p) {
                $out .= "$p\t" . implode("\t", $this->prop($last, $p)) . "\n";
            }
            return $ok($out);
        }
        if ($cmd[1] === 'destroy') {
            [$ds] = explode('@', $last, 2);
            $this->snaps[$ds] = array_values(array_filter($this->snaps[$ds], static fn($s) => $s['name'] !== $last));
            return $ok();
        }
        if ($cmd[1] === 'send' && $cmd[2] === '-nvt') {
            return $this->resumeOut;
        }
        if ($cmd[1] === 'send' && $cmd[2] === '-nP') {
            return $ok("size\t1000\n");
        }
        if ($cmd[1] === 'receive' && $cmd[2] === '-A') {
            $this->props[$last]['receive_resume_token'] = ['-', '-'];
            return $ok();
        }
        if ($cmd[1] === 'set' || $cmd[1] === 'inherit') {
            if ($cmd[1] === 'set') {
                foreach (array_slice($cmd, 2, -1) as $kv) {
                    [$k, $v] = explode('=', $kv, 2);
                    $this->props[$last][$k] = [$v, 'local'];
                }
            }
            return $ok();
        }
        return ['code' => 99, 'out' => '', 'err' => 'Fake: unerwartet ' . implode(' ', $cmd)];
    }

    public function ran(string $needle): bool
    {
        foreach ($this->calls as $c) {
            if (str_contains(implode(' ', $c), $needle)) {
                return true;
            }
        }
        return false;
    }
}

try {
    /* Zfs::destroyBlocker */
    eq('ZFS: ohne Klon/Hold löschbar', [Zfs::destroyBlocker('', '0'), Zfs::destroyBlocker('-', '0')], [null, null]);
    check('ZFS: abhängiger Klon blockiert', str_contains((string)Zfs::destroyBlocker('cache/restore-1', '0'), 'cache/restore-1'));
    check('ZFS: Hold blockiert', Zfs::destroyBlocker('', '2') !== null);

    /* Zfs::prune: Klon überspringen, Rest löschen, kein Abbruch */
    Zfs::setFixture(['pools' => [['name' => 'cache', 'size' => 1, 'alloc' => 0, 'free' => 1, 'health' => 'ONLINE', 'capacity' => 0]],
        'datasets' => [['name' => 'cache', 'mountpoint' => '/mnt/cache', 'mounted' => 'yes'],
            ['name' => 'cache/appdata', 'mountpoint' => '/mnt/cache/appdata', 'mounted' => 'yes'],
            ['name' => 'cache/appdata/repl', 'mountpoint' => 'none', 'mounted' => 'no'],
            ['name' => 'cache/appdata/plain', 'mountpoint' => '/mnt/cache/appdata/plain', 'mounted' => 'yes']],
        'mounts' => '', 'replicas' => ['cache/appdata/repl' => 'tank/data']]);
    $fz = new FakeZfs();
    $fz->props['cache/appdata'] = [];
    $fz->snap('cache/appdata', 'zbg_mirror_20261101-020000', '1', ts('2026-11-01 02:00'), 'mirror', ['clones' => ['cache/appdata-restore', 'local']]);
    $fz->snap('cache/appdata', 'zbg_mirror_20261102-020000', '2', ts('2026-11-02 02:00'), 'mirror');
    $fz->snap('cache/appdata', 'zbg_mirror_20261103-020000', '3', ts('2026-11-03 02:00'), 'mirror', ['userrefs' => ['1', '-']]);
    $fz->snap('cache/appdata', 'zbg_mirror_20261104-020000', '4', ts('2026-11-04 02:00'), 'mirror');
    Exec::fake($fz);
    $lines = [];
    $job = ['id' => 'mirror', 'method' => 'rsync', 'snapshots' => ['keep' => ['daily' => 1], 'removeAfterRsync' => false]];
    try {
        Zfs::prune($job, ['items' => [['name' => 'cache/appdata', 'action' => 'copy']]], [], static function (string $l) use (&$lines) { $lines[] = $l; });
        check('ZFS-Retention: Klon/Hold bricht den Lauf nicht ab', true);
    } catch (Throwable $e) {
        check('ZFS-Retention: Klon/Hold bricht den Lauf nicht ab', false, $e->getMessage());
    }
    eq('ZFS-Retention: nur freie Snapshots gelöscht', array_column($fz->snaps['cache/appdata'], 'guid'), ['1', '3', '4']);
    eq('ZFS-Retention: Warnungen für Klon und Hold', count(array_filter($lines, static fn($l) => str_starts_with($l, 'WARNUNG: Snapshot-Retention übersprungen'))), 2);

    /* Plan: Replikate werden übersprungen bzw. als Quelle abgewiesen */
    $p = Zfs::plan('cache/appdata', true, []);
    eq('Plan: Replikat übersprungen', array_column($p['items'], 'name'), ['cache/appdata', 'cache/appdata/plain']);
    eq('Plan: Replikat-Grund', $p['skipped'], [['name' => 'cache/appdata/repl', 'reason' => 'replica']]);
    check('Plan: Replikat-Warnung', (bool)array_filter($p['warnings'], static fn($w) => str_contains($w['msg'], 'cache/appdata/repl')));
    throws('Plan: Replikat als Quelle abgewiesen', static fn() => Zfs::plan('cache/appdata/repl', false, []));

    /* Send: Flags */
    $cmd = $priv(Send::class, 'sendCommand');
    eq('Send: unverschlüsselt -L -e -c und -i', $cmd(['encrypted' => false, 'base' => ['name' => 't/d@a']], 't/d@b', false),
        ['zfs', 'send', '-vP', '-p', '-L', '-e', '-c', '-i', 't/d@a', 't/d@b']);
    eq('Send: Schätzung roh mit denselben Flags', $cmd(['encrypted' => true, 'base' => null], 't/d@b', true), ['zfs', 'send', '-nP', '-p', '-w', 't/d@b']);

    /* Send: Fortschritt gedrosselt, letzte Zeile bleibt */
    $now = 0.0;
    $got = [];
    [$line, $done] = $priv(Send::class, 'progress')(static function (string $l) use (&$got) { $got[] = $l; }, static function () use (&$now) { return $now; });
    foreach ([[0, "02:00:00\t100\tt/d@b"], [1, "02:00:01\t200\tt/d@b"], [2, "full\tt/d@b\t900"], [3, 'ZBG_BYTES=300'], [31, "02:00:31\t400\tt/d@b"], [32, "02:00:32\t500\tt/d@b"]] as [$t, $l]) {
        $now = (float)$t;
        $line($l);
    }
    $stats = $done();
    eq('Send: Fortschritt höchstens alle 30 s, letzte Zeile am Ende', $got, ["02:00:00\t100\tt/d@b", "full\tt/d@b\t900", "02:00:31\t400\tt/d@b", "02:00:32\t500\tt/d@b"]);
    eq('Send: Bytes trotz Drosselung', $stats, ['bytes' => 500, 'measured' => true]);

    $sendJob = ['id' => 'repl', 'method' => 'zfs', 'source' => ['dataset' => 'tank/data'],
        'target' => ['dataset' => 'backup/zbg/data', 'ssh' => null], 'versions' => ['keep' => ['daily' => 1]]];
    $sendPlan = ['items' => [['name' => 'tank/data']]];
    $world = static function (): FakeZfs {
        $f = new FakeZfs();
        $f->props['tank/data'] = ['encryption' => ['off', 'default'], 'type' => ['filesystem', '-'], 'written@zbg_repl_20261102-020000' => ['5000', '-']];
        $f->props['backup'] = $f->props['backup/zbg'] = [];
        $f->props['backup/zbg/data'] = ['zbg:job' => ['repl', 'local'], 'zbg:source' => ['tank/data', 'local'],
            'written@zbg_repl_20261101-020000' => ['0', '-'], 'written@autosnap_daily' => ['0', '-']];
        $f->snap('tank/data', 'zbg_repl_20261101-020000', '11', ts('2026-11-01 02:00'), 'repl');
        $f->snap('tank/data', 'autosnap_daily', '12', ts('2026-11-01 12:00'), null);
        $f->snap('tank/data', 'zbg_repl_20261102-020000', '13', ts('2026-11-02 02:00'), 'repl');
        $f->snap('backup/zbg/data', 'zbg_repl_20261101-020000', '11', ts('2026-11-01 02:00'), 'repl');
        $f->onReceive = static function (string $pipeline) use ($f): void {
            $f->snap('backup/zbg/data', 'zbg_repl_20261102-020000', '13', ts('2026-11-02 02:00'), null);
            $f->props['backup/zbg/data']['receive_resume_token'] = ['-', '-'];
        };
        return $f;
    };

    /* Send: fremder GUID-gleicher Snapshot (altes -I) als neuester Ziel-Snapshot ist kein Dauerfehler */
    $fz = $world();
    $fz->snap('backup/zbg/data', 'autosnap_daily', '12', ts('2026-11-01 12:00'), null);
    Exec::fake($fz);
    try {
        $pre = Send::preflight($sendJob, $sendPlan);
        eq('Send: fremder gemeinsamer Ziel-Snapshot wird Basis', $pre['datasets'][0]['base'], 'tank/data@autosnap_daily');
        check('Send: Schätzung mit -i und -c', $fz->ran('zfs send -nP -p -L -e -c -i tank/data@autosnap_daily tank/data@zbg_repl_20261102-020000'));
    } catch (Throwable $e) {
        check('Send: fremder gemeinsamer Ziel-Snapshot wird Basis', false, $e->getMessage());
    }
    // ein Ziel-Snapshot ohne Quell-GUID bleibt ein Fehler
    $fz = $world();
    $fz->snap('backup/zbg/data', 'manual', '99', ts('2026-11-01 13:00'), null);
    Exec::fake($fz);
    throws('Send: fremder Ziel-Snapshot ohne Quelle weiterhin abgewiesen', static fn() => Send::preflight($sendJob, $sendPlan));

    /* Send: Resume-Token auf gelöschten Quell-Snapshot */
    $staleOut = ['code' => 255, 'out' => '', 'err' => "resume token contents:\nnvlist version: 0\n\tfromguid = 11\n\ttoguid = 0x1f\n\ttoname = tank/data@zbg_repl_20261101-140000\n"
        . "cannot resume send: 'tank/data@zbg_repl_20261101-140000' used in the initial send no longer exists"];
    $fz = $world();
    $fz->props['backup/zbg/data']['receive_resume_token'] = ['1-abc-def', '-'];
    $fz->resumeOut = $staleOut;
    Exec::fake($fz);
    $lines = [];
    try {
        $pre = Send::preflight($sendJob, $sendPlan);
        eq('Send: veralteter Token blockiert Preflight nicht', $pre['datasets'][0]['mode'], 'incremental');
        check('Send: Preflight verwirft nichts', !$fz->ran('receive -A'));
        Send::transfer($sendJob, $sendPlan, 'zbg_repl_20261102-020000', static function (string $l) use (&$lines) { $lines[] = $l; });
        $idx = array_keys(array_filter($fz->calls, static fn($c) => array_slice($c, 0, 3) === ['zfs', 'receive', '-A']));
        $pipe = array_keys(array_filter($fz->calls, static fn($c) => $c[0] === 'bash' && ($c[1] ?? '') === '-o'));
        check('Send: eigener veralteter Empfang per receive -A verworfen, dann normal gesendet',
            count($idx) === 1 && $fz->calls[$idx[0]][3] === 'backup/zbg/data' && count($pipe) === 1 && $pipe[0] > $idx[0]);
        check('Send: Stream mit -i', str_contains($fz->calls[$pipe[0]][4] ?? '', "'-i' 'tank/data@zbg_repl_20261101-020000'"));
        check('Send: Verwerfen protokolliert', (bool)array_filter($lines, static fn($l) => str_contains($l, 'zfs receive -A')));
    } catch (Throwable $e) {
        check('Send: veralteter Token wird behoben', false, $e->getMessage());
    }
    // fremdes Ziel: nie automatisch verwerfen
    $fz = $world();
    $fz->props['backup/zbg/data']['receive_resume_token'] = ['1-abc-def', '-'];
    $fz->props['backup/zbg/data']['zbg:job'] = ['other', 'local'];
    $fz->resumeOut = $staleOut;
    Exec::fake($fz);
    try {
        Send::preflight($sendJob, $sendPlan);
        check('Send: veralteter Token auf fremdem Ziel abgewiesen', false, 'keine Exception');
    } catch (UserError $e) {
        check('Send: veralteter Token auf fremdem Ziel abgewiesen', str_contains($e->getMessage(), 'zfs receive -A') && !$fz->ran('receive -A'), $e->getMessage());
    }

    /* Send::prune: Klon auf dem Ziel überspringen */
    $fz = $world();
    $fz->snaps['backup/zbg/data'] = [];
    $fz->snap('backup/zbg/data', 'zbg_repl_20261030-020000', '8', ts('2026-10-30 02:00'), 'repl', ['clones' => ['backup/zbg/clone', 'local']]);
    $fz->snap('backup/zbg/data', 'zbg_repl_20261031-020000', '9', ts('2026-10-31 02:00'), 'repl');
    $fz->snap('backup/zbg/data', 'zbg_repl_20261101-020000', '11', ts('2026-11-01 02:00'), 'repl');
    Exec::fake($fz);
    $lines = [];
    try {
        Send::prune($sendJob, $sendPlan, static function (string $l) use (&$lines) { $lines[] = $l; });
        eq('Send-Retention: Klon bleibt, Rest gelöscht', array_column($fz->snaps['backup/zbg/data'], 'guid'), ['8', '11']);
        check('Send-Retention: Warnung für Klon', (bool)array_filter($lines, static fn($l) => str_starts_with($l, 'WARNUNG: Ziel-Retention übersprungen')));
    } catch (Throwable $e) {
        check('Send-Retention: Klon bricht nicht ab', false, $e->getMessage());
    }

    // Ungemountete Ziel-Pool-Wurzel: das Fake-Inventar kennt keine Mounts, die Preflights oben laufen trotzdem durch
    check('Send: Pool-Root-Mount nicht gefordert', !$fz->ran('name,type,mounted,available'));
} finally {
    Exec::fake(null);
    Zfs::setFixture($zbgFixture);
}

/* Remote: SSH-Multiplexing */
$args = Remote::ssh(['host' => 'backup.example', 'user' => 'root', 'port' => 22]);
$opt = static fn(array $a, string $k) => current(array_filter($a, static fn($x) => str_starts_with((string)$x, $k . '='))) ?: null;
check('SSH: Hostschlüssel weiter strikt', in_array('StrictHostKeyChecking=yes', $args, true));
check('SSH: Multiplexing aktiv', in_array('ControlMaster=auto', $args, true) && in_array('ControlPersist=60', $args, true));
$control = substr((string)$opt($args, 'ControlPath'), strlen('ControlPath='));
check('SSH: ControlPath im Laufzeitverzeichnis', str_starts_with($control, RunState::dir() . '/ssh/') && strlen($control) <= 80, $control);
eq('SSH: Socket-Verzeichnis 0700', is_dir(RunState::dir() . '/ssh') ? fileperms(RunState::dir() . '/ssh') & 0777 : null, 0700);
check('SSH: anderer Port eigener Socket', $opt(Remote::ssh(['host' => 'backup.example', 'port' => 2222]), 'ControlPath') !== $opt($args, 'ControlPath'));
