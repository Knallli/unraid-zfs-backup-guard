<?php
declare(strict_types=1);

/*
 * Minimaler Testlauf ohne Abhängigkeiten: php tests/run.php
 * Läuft auf dem Unraid-Server (PHP 8.4) oder lokal, ohne ZFS anzufassen (Fixtures).
 */

putenv('ZBG_CONFIG_DIR=' . sys_get_temp_dir() . '/zbg-test-config-' . getmypid());
putenv('ZBG_ZEROBYTE_DB=' . sys_get_temp_dir() . '/zbg-test-zerobyte-' . getmypid() . '.db');
date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/../src/usr/local/emhttp/plugins/zfs-backup-guard/include/bootstrap.php';

use ZBG\{Cron, Downstream, History, Job, JobStore, Paths, Retention, RunState, System, UserError, UserScripts, Zfs};

$failures = 0;
$count = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
        echo "✗ $name" . ($detail !== '' ? "\n    $detail" : '') . "\n";
    }
}
function eq(string $name, mixed $actual, mixed $expected): void
{
    check($name, $actual === $expected, 'erwartet ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', erhalten ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
}
function throws(string $name, callable $fn): void
{
    try {
        $fn();
        check($name, false, 'keine Exception');
    } catch (UserError) {
        check($name, true);
    }
}
function ts(string $s): int
{
    return (new DateTimeImmutable($s, new DateTimeZone('Europe/Berlin')))->getTimestamp();
}
function dailySnaps(string $from, int $days, string $time = '02:00'): array
{
    $out = [];
    $d = new DateTimeImmutable("$from $time", new DateTimeZone('Europe/Berlin'));
    for ($i = 0; $i < $days; $i++) {
        $t = $d->modify("+$i day");
        $out[] = ['name' => 's' . $t->format('Ymd-Hi'), 'time' => $t->getTimestamp()];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Retention                                                           */
/* ------------------------------------------------------------------ */

$r = Retention::plan([], ['daily' => 7]);
eq('Retention: leere Liste', [$r['keep'], $r['destroy']], [[], []]);

$one = [['name' => 'a', 'time' => ts('2026-10-14 02:00')]];
eq('Retention: alles 0 → neuester bleibt', Retention::plan($one, [])['keep'], ['a']);

// 400 Tage täglich, 7 T / 4 W / 3 M (borg-Semantik): 14 Snapshots, ältester ≈ 2,5 Monate (Monatsenden Jul–Sep)
$snaps = dailySnaps('2025-09-10', 400);
$r = Retention::plan($snaps, ['daily' => 7, 'weekly' => 4, 'monthly' => 3]);
eq('Retention 7/4/3: Anzahl', count($r['keep']), 14);
eq('Retention 7/4/3: neuester behalten', $r['keep'][0], end($snaps)['name']);
$oldest = min(array_map(static fn($n) => array_column($snaps, 'time', 'name')[$n], $r['keep']));
$ageDays = (end($snaps)['time'] - $oldest) / 86400;
check('Retention 7/4/3: ältester ≈ 2,5 Monate', $ageDays > 70 && $ageDays < 100, "Alter $ageDays Tage");
eq('Retention: keep + destroy = alle', count($r['keep']) + count($r['destroy']), 400);
check('Retention: keine Überschneidung', !array_intersect($r['keep'], $r['destroy']));

// Wochengrenze: ISO-Woche (So 2026-10-11 gehört zu KW 41, Mo 2026-10-12 zu KW 42)
$w = [
    ['name' => 'so', 'time' => ts('2026-10-11 23:00')],
    ['name' => 'mo', 'time' => ts('2026-10-12 01:00')],
];
eq('Retention: ISO-Woche So/Mo getrennt', Retention::plan($w, ['weekly' => 2])['keep'], ['mo', 'so']);

// Jahreswechsel mit ISO-Woche 53 (2026-12-31 Do → KW 53, 2027-01-01 Fr → KW 53 desselben ISO-Jahres)
$y = [
    ['name' => 'a', 'time' => ts('2026-12-31 02:00')],
    ['name' => 'b', 'time' => ts('2027-01-01 02:00')],
];
eq('Retention: KW 53 über Jahreswechsel = eine Woche', Retention::plan($y, ['weekly' => 5])['keep'], ['b']);

// Schaltjahr: 29.02.2028 eigener Tag
$l = [
    ['name' => 'feb28', 'time' => ts('2028-02-28 02:00')],
    ['name' => 'feb29', 'time' => ts('2028-02-29 02:00')],
    ['name' => 'mar01', 'time' => ts('2028-03-01 02:00')],
];
eq('Retention: Schaltjahr', Retention::plan($l, ['daily' => 3])['keep'], ['mar01', 'feb29', 'feb28']);

// Sommerzeit: zwei Läufe in der doppelten Stunde 02:xx am 25.10.2026 sind ein Tag
$dst = [
    ['name' => 'x1', 'time' => ts('2026-10-25 01:30')],
    ['name' => 'x2', 'time' => ts('2026-10-25 03:30')],
];
eq('Retention: Zeitumstellung, gleicher Tag', Retention::plan($dst, ['daily' => 1])['keep'], ['x2']);

// Geschützter (gemeinsamer) Snapshot bleibt immer
$r = Retention::plan(dailySnaps('2026-01-01', 30), ['daily' => 2], ['s20260101-0200']);
check('Retention: geschützter bleibt', in_array('s20260101-0200', $r['keep'], true));
eq('Retention: geschützt + 2 täglich', count($r['keep']), 3);

// Unsortierte Eingabe
$shuffled = dailySnaps('2026-01-01', 10);
shuffle($shuffled);
eq('Retention: unsortiert → neuester', Retention::plan($shuffled, ['daily' => 1])['keep'], ['s20260110-0200']);

/* ------------------------------------------------------------------ */
/* Cron                                                                */
/* ------------------------------------------------------------------ */

foreach (['0 2 * * *', '*/15 * * * *', '0 */6 * * *', '30 3 * * 0', '0 4 1 * *', '0 3 * * 1-5', '0 0 * * 7', '5,35 1-3 * 1,6 *'] as $e) {
    check("Cron gültig: $e", Cron::valid($e));
}
foreach (['', '* * * *', '60 * * * *', '* 24 * * *', '0 0 0 * *', '0 0 * 13 *', 'a b c d e', '*/0 * * * *', '5-1 * * * *'] as $e) {
    check("Cron ungültig: '$e'", !Cron::valid($e));
}
eq('Cron next täglich 02:00', Cron::next('0 2 * * *', ts('2026-10-14 21:00')), ts('2026-10-15 02:00'));
eq('Cron next gleich Minute später', Cron::next('0 2 * * *', ts('2026-10-14 01:59')), ts('2026-10-14 02:00'));
eq('Cron next sonntags', Cron::next('0 3 * * 0', ts('2026-10-14 12:00')), ts('2026-10-18 03:00'));
eq('Cron next 7 = Sonntag', Cron::next('0 3 * * 7', ts('2026-10-14 12:00')), ts('2026-10-18 03:00'));
eq('Cron next Monatsende', Cron::next('0 4 1 * *', ts('2026-12-15 00:00')), ts('2027-01-01 04:00'));
eq('Cron next 29.02.', Cron::next('0 0 29 2 *', ts('2026-03-01 00:00')), ts('2028-02-29 00:00'));
eq('Cron next alle 6 h', Cron::next('0 */6 * * *', ts('2026-10-14 13:10')), ts('2026-10-14 18:00'));
eq('Cron dom ODER dow', Cron::next('0 0 13 * 5', ts('2026-10-14 00:00')), ts('2026-10-16 00:00'));

/* ------------------------------------------------------------------ */
/* Pfade                                                               */
/* ------------------------------------------------------------------ */

eq('Paths: clean', Paths::clean('/mnt//user/./backup/'), '/mnt/user/backup');
throws('Paths: .. verboten', static fn() => Paths::clean('/mnt/user/../etc'));
throws('Paths: relativ verboten', static fn() => Paths::clean('mnt/user'));
eq('Paths: Ziel mit Leerzeichen', Paths::normalizeTarget('/mnt/user/backup/Github Actions'), '/mnt/user/backup/Github Actions');
throws('Paths: Ziel nicht unter /mnt', static fn() => Paths::normalizeTarget('/boot/config'));
throws('Paths: Share-Wurzel als Ziel', static fn() => Paths::normalizeTarget('/mnt/user'));
check('Paths: within', Paths::within('/mnt/cache/appdata', '/mnt/cache/appdata/x'));
check('Paths: within gleich', Paths::within('/mnt/cache/appdata', '/mnt/cache/appdata'));
check('Paths: nicht within (Präfix)', !Paths::within('/mnt/cache/app', '/mnt/cache/appdata'));

/* ------------------------------------------------------------------ */
/* ZFS-Sonderfälle (Fixture nach dem echten System + konstruierte Fälle) */
/* ------------------------------------------------------------------ */

$row = static fn(string $name, string $mp, array $o = []) => array_replace(array_combine(Zfs::DS_COLS, [
    $name, 'filesystem', '1000', '5000', '900', '100', $mp, 'on', 'yes', 'off', '-', '1.50', '-', 'hidden', 'off', 'none',
]), $o);
$mounts = implode("\n", [
    'cache /mnt/cache zfs rw 0 0',
    'cache/appdata /mnt/cache/appdata zfs rw 0 0',
    'cache/appdata/immich /mnt/cache/appdata/immich zfs rw 0 0',
    'cache/domains /mnt/cache/domains zfs rw 0 0',
    'cache/domains/Github\\040Actions /mnt/cache/domains/Github\\040Actions zfs rw 0 0',
    'cache/legacy /srv/legacy zfs rw 0 0',
    'cache/lxc /mnt/cache/lxc zfs rw 0 0',
    'cache/lxc/pihole /var/lib/lxc/pihole/rootfs zfs rw 0 0',
    'cache/lxc/inner /mnt/cache/lxc/custom/inner zfs rw 0 0',
    'other/foreign /mnt/cache/appdata/foreign zfs rw 0 0',
    'flash/boot /boot zfs rw,noatime 0 0',
    'shfs /mnt/user fuse.shfs rw 0 0',
]);
Zfs::setFixture([
    'pools' => [
        ['name' => 'cache', 'size' => '1', 'alloc' => '1', 'free' => '0', 'health' => 'ONLINE', 'capacity' => '82', 'frag' => '1'],
        ['name' => 'flash', 'size' => '1', 'alloc' => '1', 'free' => '0', 'health' => 'ONLINE', 'capacity' => '17', 'frag' => '1'],
    ],
    'mounts' => $mounts,
    'datasets' => [
        $row('cache', '/mnt/cache'),
        $row('cache/appdata', '/mnt/cache/appdata'),
        $row('cache/appdata/immich', '/mnt/cache/appdata/immich'),
        $row('cache/appdata/off', '/mnt/cache/appdata/off', ['canmount' => 'off', 'mounted' => 'no']),
        $row('cache/appdata/off/child', '/mnt/cache/appdata/off/child', ['mounted' => 'no']),
        $row('cache/domains', '/mnt/cache/domains'),
        $row('cache/domains/Github Actions', '/mnt/cache/domains/Github Actions'),
        $row('cache/domains/vm1', '-', ['type' => 'volume', 'mounted' => '-']),
        $row('cache/legacy', 'legacy'),
        $row('cache/lxc', '/mnt/cache/lxc'),
        $row('cache/lxc/pihole', '/var/lib/lxc/pihole/rootfs'),
        $row('cache/lxc/inner', '/mnt/cache/lxc/custom/inner'),
        $row('cache/none', 'none', ['mounted' => 'no']),
        $row('cache/noauto', '/mnt/cache/noauto', ['canmount' => 'noauto', 'mounted' => 'no']),
        $row('cache/vault', '/mnt/cache/vault', ['encryption' => 'aes-256-gcm', 'keystatus' => 'unavailable', 'mounted' => 'no']),
        $row('cache/clone', '/mnt/cache/clone', ['origin' => 'cache/appdata@zbg_appdata_20261014-020000']),
        $row('cache/limited', '/mnt/cache/limited', ['snapshot_limit' => '10']),
        $row('cache/' . str_repeat('x', 200), '/mnt/cache/long'),
        $row('flash', 'none', ['mounted' => 'no']),
        $row('flash/boot', '/boot', ['canmount' => 'noauto']),
    ],
]);
$ds = array_column(Zfs::datasets(), null, 'name');
$codes = static fn(string $n) => array_column($ds[$n]['issues'], 'code');

eq('ZFS: Boot-Dataset erkannt (NVMe-Boot)', Zfs::bootDataset(), 'flash/boot');
eq('ZFS: Boot-Pool', Zfs::bootPool(), 'flash');
check('ZFS: flash/boot lesbar (noauto, aber gemountet)', $ds['flash/boot']['readable'] && $ds['flash/boot']['boot']);
check('ZFS: flash (none) nicht lesbar', !$ds['flash']['readable'] && in_array('unmounted', $codes('flash'), true));
check('ZFS: Pool flash als Boot markiert', Zfs::pool('flash')['boot'] && !Zfs::pool('cache')['boot']);
eq('ZFS: Leerzeichen-Pfad aus /proc/mounts', $ds['cache/domains/Github Actions']['path'], '/mnt/cache/domains/Github Actions');
check('ZFS: Leerzeichen Hinweis', in_array('chars', $codes('cache/domains/Github Actions'), true));
check('ZFS: zvol nicht lesbar', $ds['cache/domains/vm1']['type'] === 'volume' && !$ds['cache/domains/vm1']['readable']);
eq('ZFS: legacy über /proc/mounts', $ds['cache/legacy']['path'], '/srv/legacy');
check('ZFS: ungemountetes canmount=off nicht als Dateiquelle nutzbar', !$ds['cache/appdata/off']['readable'] && $ds['cache/appdata/off']['path'] === null);
check('ZFS: ungemountetes noauto nicht als Dateiquelle nutzbar', !$ds['cache/noauto']['readable'] && $ds['cache/noauto']['path'] === null);
check('ZFS: mountpoint=none hat keinen Dateipfad', $ds['cache/none']['path'] === null);
check('ZFS: Schlüssel nicht geladen', $ds['cache/vault']['locked'] && !$ds['cache/vault']['readable']);
check('ZFS: Klon', in_array('clone', $codes('cache/clone'), true));
check('ZFS: snapshot_limit', in_array('snaplimit', $codes('cache/limited'), true));
check('ZFS: Namenslänge', in_array('namelen', $codes('cache/' . str_repeat('x', 200)), true));
check('ZFS: abweichender Mountpoint außerhalb', in_array('detached', $codes('cache/lxc/pihole'), true));
check('ZFS: abweichender Mountpoint innerhalb', in_array('detached', $codes('cache/lxc/inner'), true));

// Plan: appdata rekursiv – off-Teilbaum nur Snapshot, fremder Pool-Mount geschützt
$p = Zfs::plan('cache/appdata', true, []);
$items = array_column($p['items'], null, 'name');
eq('Plan: Wurzel kopiert nach ./', $items['cache/appdata']['targetRel'], '');
eq('Plan: Kind nach ./immich', $items['cache/appdata/immich']['targetRel'], 'immich');
eq('Plan: ungemountetes Kind nur Snapshot', $items['cache/appdata/off']['action'], 'snapshot-only');
eq('Plan: Wurzel schützt Kind- und Fremd-Mounts vor --delete', $items['cache/appdata']['protect'], ['/foreign/', '/immich/']);

// Ausschluss schließt Teilbaum aus
$p = Zfs::plan('cache/appdata', true, ['cache/appdata/off']);
eq('Plan: Ausschluss Teilbaum', array_column($p['skipped'], 'name'), ['cache/appdata/off', 'cache/appdata/off/child']);

// Nicht rekursiv
eq('Plan: nicht rekursiv nur Wurzel', count(Zfs::plan('cache/appdata', false, [])['items']), 1);

// lxc: Kind außerhalb → .datasets/<rel>, Kind mit abweichendem Pfad innerhalb → nach Mountpfad
$p = array_column(Zfs::plan('cache/lxc', true, [])['items'], null, 'name');
eq('Plan: Mount außerhalb → .datasets', $p['cache/lxc/pihole']['targetRel'], '.datasets/pihole');
eq('Plan: Mount innerhalb, anderer Pfad', $p['cache/lxc/inner']['targetRel'], 'custom/inner');
eq('Plan: lxc schützt custom/inner', $p['cache/lxc']['protect'], ['/custom/inner/']);

// domains: zvol nur Snapshot, Leerzeichen-Kind kopiert
$p = array_column(Zfs::plan('cache/domains', true, [])['items'], null, 'name');
eq('Plan: zvol nur Snapshot', $p['cache/domains/vm1']['action'], 'snapshot-only');
eq('Plan: Leerzeichen-Kind', $p['cache/domains/Github Actions']['targetRel'], 'Github Actions');

// Boot als Quelle ist erlaubt
$p = Zfs::plan('flash/boot', false, []);
eq('Plan: Boot kopierbar', $p['items'][0]['action'], 'copy');

// Snapshot-Klassifizierung
check('Snapshot eigen', Zfs::isOwn('zbg_appdata_20261014-020000', 'appdata'));
check('Snapshot fremde Property', !Zfs::isOwn('zbg_appdata_20261014-020000', 'other'));
check('Snapshot ohne Property', !Zfs::isOwn('zbg_appdata_20261014-020000', null));
check('Snapshot Präfix-Fälschung', !Zfs::isOwn('zbg_appdata_x', 'appdata'));
eq('Snapshot sanoid', Zfs::classify('autosnap_2026-03-01_00:00:01_monthly', null), 'sanoid');
eq('Snapshot user script', Zfs::classify('rsync_auto_20261013_020001', null), 'userscript');

/* ------------------------------------------------------------------ */
/* Job-Validierung                                                     */
/* ------------------------------------------------------------------ */

$base = ['name' => 'Appdata → Array', 'source' => ['dataset' => 'cache/appdata'], 'target' => ['path' => '/mnt/user/backup/zfs-test/appdata', 'mode' => 'mirror']];
$j = Job::normalize($base);
eq('Job: ID aus Name', $j['id'], 'appdata-array');
eq('Job: system standardmäßig aus', $j['system'], false);
throws('Job: system nur mit rsync', static fn() => Job::normalize(['system' => true, 'method' => 'zfs', 'target' => ['dataset' => 'tank/x']] + $base, false));
eq('Job: system mit rsync', Job::normalize(['system' => true] + $base, false)['system'], true);

$lsblk = json_decode('{"blockdevices":[
 {"name":"nvme0n1","type":"disk","children":[
  {"name":"nvme0n1p1","type":"part","size":1048576,"partlabel":"BIOS Boot Partition"},
  {"name":"nvme0n1p2","type":"part","size":534773760,"fstype":"vfat","partlabel":"EFI System Partition","parttype":"c12a7328-f81f-11d2-ba4b-00a0c93ec93b"},
  {"name":"nvme0n1p3","type":"part","fstype":"zfs_member","partlabel":"Unraid Boot Partition"},
  {"name":"nvme0n1p4","type":"part","fstype":"crypto_LUKS","children":[{"name":"nvme0n1p4","type":"crypt","fstype":"zfs_member"}]}]},
 {"name":"sda","type":"disk","children":[{"name":"sda1","type":"part","fstype":"crypto_LUKS"}]},
 {"name":"md1p1","type":"md","fstype":"xfs"}]}', true)['blockdevices'];
eq('System: Geräteauswahl', System::select($lsblk, ['nvme0n1']), ['bios' => ['nvme0n1p1'], 'efi' => ['nvme0n1p2'], 'luks' => ['nvme0n1p4', 'sda1']]);
eq('System: Boot-Disks aus zpool status', System::bootDisks("  pool: flash\n\tNAME  STATE\n\tflash  ONLINE\n\t  mirror-0  ONLINE\n\t    /dev/nvme1n1p3  ONLINE  0 0 0\n\t    /dev/nvme0n1p3  ONLINE  0 0 0\n", static fn(string $d): string => preg_replace('~p\d+$~', '', $d)), ['nvme1n1', 'nvme0n1']);
eq('Job: Slug Umlaute', Job::slug('Über Größe'), 'ueber-groesse');
throws('Job: Ziel in Quelle', static fn() => Job::normalize(['target' => ['path' => '/mnt/cache/appdata/backup']] + $base));
throws('Job: Ausschluss nicht unter Quelle', static fn() => Job::normalize(['source' => ['dataset' => 'cache/appdata', 'exclude' => ['cache/domains']]] + $base));
throws('Job: unbekanntes Dataset', static fn() => Job::normalize(['source' => ['dataset' => 'cache/gibtsnicht']] + $base));
throws('Job: Dataset mit @', static fn() => Job::normalize(['source' => ['dataset' => 'cache/appdata@x']] + $base));
throws('Job: Cron ungültig', static fn() => Job::normalize(['schedule' => '99 * * * *'] + $base));
throws('Job: zfs-Ziel in Quelle', static fn() => Job::normalize(['method' => 'zfs', 'target' => ['dataset' => 'cache/appdata/copy']] + $base));
throws('Job: SSH-Host mit Shell-Zeichen', static fn() => Job::normalize(['target' => ['path' => '/mnt/user/b/x', 'ssh' => ['host' => 'a;rm -rf /']]] + $base));
eq('Job: Boot als Quelle erlaubt', Job::normalize(['name' => 'Boot', 'source' => ['dataset' => 'flash/boot'], 'target' => ['path' => '/mnt/user/backup/zfs/boot']])['source']['dataset'], 'flash/boot');
eq('Job: Retention gekappt', Job::normalize(['snapshots' => ['keep' => ['daily' => 99999]]] + $base)['snapshots']['keep']['daily'], 1000);

/* ------------------------------------------------------------------ */
/* Zerobyte-Erkennung (Test-Datenbank im echten Schema-Ausschnitt)     */
/* ------------------------------------------------------------------ */

if (class_exists(SQLite3::class)) {
    $dbf = getenv('ZBG_ZEROBYTE_DB');
    @unlink($dbf);
    $db = new SQLite3($dbf);
    $db->exec("CREATE TABLE volumes_table (id INTEGER PRIMARY KEY, name TEXT, type TEXT, config TEXT);
        CREATE TABLE backup_schedules_table (id INTEGER PRIMARY KEY, name TEXT, volume_id INTEGER, enabled INTEGER, cron_expression TEXT,
            include_paths TEXT, retention_policy TEXT, last_backup_status TEXT, last_backup_at INTEGER);
        INSERT INTO volumes_table VALUES (1, 'backup-directory', 'directory', '{\"backend\":\"directory\",\"path\":\"/mnt/user/backup\"}');
        INSERT INTO volumes_table VALUES (2, 'nas', 'smb', '{\"backend\":\"smb\",\"password\":\"geheim\",\"path\":\"/x\"}');
        INSERT INTO backup_schedules_table VALUES (1, 'AppData Daily', 1, 1, '00 05 * * *', '[\"/zfs-test/appdata\",\"/zfs-test/domains\",\"/zfs-test/lxc\"]', '{\"keepDaily\":7}', 'success', 1760000000000);
        INSERT INTO backup_schedules_table VALUES (2, 'SMB', 2, 1, '0 1 * * *', '[]', '{}', 'success', 0);");
    $db->close();
    $plans = Downstream::plans();
    eq('Zerobyte: nur Verzeichnis-Volumes', count($plans), 1);
    check('Zerobyte: keine Zugangsdaten gelesen', !str_contains(json_encode($plans), 'geheim'));
    eq('Zerobyte: Ziel appdata erfasst', Downstream::forTarget('/mnt/user/backup/zfs-test/appdata')[0]['name'] ?? null, 'AppData Daily');
    eq('Zerobyte: Unterordner voll erfasst', Downstream::forTarget('/mnt/user/backup/zfs-test/appdata/current')[0]['coverage'] ?? null, 'full');
    eq('Zerobyte: main nicht erfasst', Downstream::forTarget('/mnt/user/backup/zfs-test/main'), []);
    eq('Zerobyte: Elternordner nur teilweise', Downstream::forTarget('/mnt/user/backup/zfs-test')[0]['coverage'] ?? null, 'partial');
    eq('Zerobyte: Zeitstempel ms → s', $plans[0]['lastAt'], 1760000000);
    @unlink($dbf);
} else {
    echo "(SQLite3 fehlt – Zerobyte-Tests übersprungen)\n";
}

/* ------------------------------------------------------------------ */
/* Migration                                                           */
/* ------------------------------------------------------------------ */

$script = <<<'SH'
#!/bin/bash
declare -A BACKUP_JOBS=(
    ["cache/main"]="/mnt/user/backup/zfs-test/main"
    ["cache/appdata"]="/mnt/user/backup/zfs-test/appdata"
    ["cache/domains/Github Actions"]="/mnt/user/backup/zfs-test/gha"
)
SH;
$prop = UserScripts::parse($script, '0 2 * * *');
eq('Migration: 3 Jobs', count($prop), 3);
eq('Migration: Dataset mit Leerzeichen', $prop[2]['source']['dataset'], 'cache/domains/Github Actions');
eq('Migration: Zeitplan übernommen', $prop[0]['schedule'], '0 2 * * *');
$sio = UserScripts::parse("source_pool=\"cache\"\nsource_dataset=\"appdata\"\nsnapshot_days=\"7\"\nsnapshot_weeks=4 # Kommentar\nreplication=\"rsync\"\n", 'kaputt');
eq('Migration: SpaceInvaderOne', [$sio[0]['source']['dataset'], $sio[0]['snapshots']['keep']['weekly'], $sio[0]['schedule']], ['cache/appdata', 4, '0 2 * * *']);
eq('Migration: kein Backup-Skript', UserScripts::parse("echo hallo\n", null), []);

/* Persistenz: atomarer Import, konkurrierende Änderungen und aktive Jobs */
$fixtureA = ['id' => 'fixture-a', 'name' => 'Fixture A', 'enabled' => true] + $base;
$fixtureB = ['id' => 'fixture-b', 'name' => 'Fixture B', 'enabled' => true] + $base;
$imported = JobStore::saveBatch([$fixtureA, $fixtureB]);
eq('Import: aktive Vorschläge werden deaktiviert gespeichert', array_column($imported, 'enabled'), [false, false]);
$beforeImportConflict = JobStore::all();
throws('Import: vorhandene ID wird nicht überschrieben', static fn() => JobStore::saveBatch([$fixtureA]));
eq('Import: Konflikt lässt gesamte Konfiguration unverändert', JobStore::all(), $beforeImportConflict);
throws('Import: doppelte IDs im Batch abgelehnt', static fn() => JobStore::saveBatch([
    ['id' => 'batch-duplicate', 'name' => 'First'] + $base,
    ['id' => 'batch-duplicate', 'name' => 'Second'] + $base,
]));
eq('Import: ungültiger Batch legt keinen Teilbestand an', JobStore::all(), $beforeImportConflict);
JobStore::setEnabled('fixture-a', true);
$cronOutput = file_get_contents(JobStore::file('zfs-backup-guard.cron'));
check('Cron: aktivierter Job vorhanden, deaktivierter nicht', str_contains($cronOutput, 'fixture-a') && !str_contains($cronOutput, 'fixture-b'));
JobStore::delete('fixture-a');
check('Cron: gelöschter Job wird nicht mehr geplant', !str_contains(file_get_contents(JobStore::file('zfs-backup-guard.cron')), 'fixture-a'));

$jobLock = RunState::lockJob('fixture-b');
throws('Job: gesperrter Job nicht löschbar', static fn() => JobStore::delete('fixture-b'));
fclose($jobLock);
RunState::write('fixture-b', ['state' => 'running', 'pid' => getmypid(), 'pidIdentity' => RunState::pidIdentity(getmypid()), 'start' => time()]);
throws('Job: laufender Job nicht umbenennbar', static fn() => JobStore::save(['id' => 'renamed-b', 'name' => 'Renamed'] + $base, 'fixture-b'));
throws('Job: ID-Änderung grundsätzlich abgelehnt', static fn() => JobStore::save(['id' => 'renamed-c', 'name' => 'Renamed'] + $base, 'fixture-a'));
check('Paths::aliases: normaler Pfad unverändert', Paths::aliases('/mnt/cache/appdata') === ['/mnt/cache/appdata']);
check('Paths::aliases: User-Share enthält den Pfad selbst', in_array('/mnt/user/appdata/x', Paths::aliases('/mnt/user/appdata/x'), true));
$rsyncCmd = (new ReflectionMethod(ZBG\Rsync::class, 'command'))->invoke(null, ['target' => []],
    ['items' => [['name' => 'p/data', 'rel' => '', 'targetRel' => ''], ['name' => 'p/data/vm', 'rel' => 'vm', 'targetRel' => null]]],
    ['name' => 'p/data', 'rel' => '', 'targetRel' => '', 'protect' => []], '/src/', '/dst', null, false, false);
check('Rsync: gesperrtes Kind auch unter .datasets geschützt', in_array('--filter=P /.datasets/vm/***', $rsyncCmd, true) && in_array('--filter=P /vm/***', $rsyncCmd, true));
check('Rsync: .zbg-system reserviert', in_array('--filter=P /.zbg-system', $rsyncCmd, true));
RunState::write('fixture-b', ['state' => 'success', 'pid' => 0]);

if (function_exists('pcntl_fork')) {
    $children = [];
    foreach (['concurrent-a', 'concurrent-b', 'concurrent-c'] as $id) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                JobStore::save(['id' => $id, 'name' => $id, 'enabled' => false] + $base);
                exit(0);
            } catch (Throwable) { exit(1); }
        }
        if ($pid < 0) { throw new RuntimeException('Fork fehlgeschlagen'); }
        $children[] = $pid;
    }
    $allChildrenOk = true;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $allChildrenOk = $allChildrenOk && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    }
    $storedIds = array_column(JobStore::all(), 'id');
    check('Persistenz: konkurrierende Änderungen gehen nicht verloren', $allChildrenOk && !array_diff(['concurrent-a', 'concurrent-b', 'concurrent-c'], $storedIds));
}

$fixtureLog = getenv('ZBG_CONFIG_DIR') . '/logs';
mkdir($fixtureLog, 0700);
JobStore::writeJson('settings.json', ['logDir' => $fixtureLog, 'historyLimit' => 3, 'globalLock' => true]);
for ($i = 1; $i <= 5; $i++) {
    History::append('fixture-b', ['runId' => 'record-' . $i, 'start' => $i, 'end' => $i + 1, 'result' => 'success']);
}
eq('Verlauf: Begrenzung behält neueste echte Läufe', array_column(History::forJob('fixture-b'), 'runId'), ['record-5', 'record-4', 'record-3']);
$statusJob = Job::normalize(['enabled' => true] + $fixtureB);
$realSuccess = ['result' => 'success', 'start' => time() - 60, 'end' => time() - 10];
$dryFailure = ['result' => 'failed', 'dryRun' => true, 'start' => time(), 'end' => time()];
eq('Status: Probelauf ersetzt keine echte Backup-Ampel', History::status($statusJob, [$dryFailure, $realSuccess])['state'], 'ok');
eq('Status: nur Probeläufe zählen nicht als Backup', History::status($statusJob, [$dryFailure])['state'], 'never');

function removeFixtureTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        removeFixtureTree($entry->getPathname());
    }
    rmdir($path);
}
removeFixtureTree(getenv('ZBG_CONFIG_DIR'));

echo $failures ? "\n$failures von $count Tests fehlgeschlagen\n" : "Alle $count Tests bestanden\n";
exit($failures ? 1 : 0);
