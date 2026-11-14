<?php
declare(strict_types=1);

/* Explicit, destructive ONLY to uniquely named datasets created by this test.
 * php tests/integration.php <pool> --allow-zfs [--ssh] [--docker-image=<local-image>]
 * Never runs an existing job or changes production cron / SSH identities.
 */
if (!in_array('--allow-zfs', $argv, true) || !isset($argv[1]) || !preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*$~', $argv[1])) {
    fwrite(STDERR, "Usage: php tests/integration.php <pool> --allow-zfs [--ssh] [--docker-image=<local-image>]\n");
    exit(2);
}
$pool = $argv[1];
$token = bin2hex(random_bytes(6));
$work = sys_get_temp_dir() . '/zbg-integration-' . $token;
mkdir($work, 0700);
putenv('ZBG_CONFIG_DIR=' . $work . '/config');
require __DIR__ . '/../src/usr/local/emhttp/plugins/zfs-backup-guard/include/bootstrap.php';

use ZBG\{Browse, Exec, History, Job, JobStore, Restore, Retention, Runner, RunState, Versions, Zfs};

$created = [];
$mountpoints = []; // explicit mountpoints: ZFS leaves them as empty dirs (= Unraid shares) after destroy
$clones = [];
$container = null;
$sshd = null;
$authorizedKey = null;
$httpWorker = null;
$checks = 0;
$source = $pool . '/zbg-integration-source-' . $token;
$target = $pool . '/zbg-integration-target-' . $token;
$receive = $pool . '/zbg-integration-recv-' . $token;
$sshReceive = $pool . '/zbg-integration-ssh-recv-' . $token;
$sourcePath = '/mnt/' . $pool . '/zbg-integration-source-' . $token;
$targetPath = '/mnt/' . $pool . '/zbg-integration-target-' . $token;

function expect(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
    echo "PASS $message\n";
}
function fresh(): void { Zfs::setFixture(null); }
function snapshotNames(string $dataset): array
{
    fresh();
    return array_column(Zfs::snapshots($dataset, true), 'snapshot');
}
function nextSecond(): void
{
    $second = time();
    while (time() === $second) { usleep(25000); }
}
function runBackup(array $job, bool $dry = false, bool $verify = false): array
{
    nextSecond();
    fresh();
    $r = Runner::run($job, $dry, $verify);
    expect(($r['result'] ?? '') === ($dry ? 'dryrun' : 'success') && ($r['state'] ?? '') === 'success',
        'backup completes: ' . $job['id'] . ($dry ? ' (dry-run)' : '') . (($r['error'] ?? '') ? ': ' . $r['error'] : ''));
    return $r;
}
function jobFor(string $id, string $mode = 'mirror'): array
{
    global $source, $targetPath;
    fresh();
    return Job::normalize(['id' => $id, 'name' => $id, 'enabled' => false,
        'source' => ['dataset' => $source, 'recursive' => true, 'exclude' => [$source . '/excluded']],
        'target' => ['path' => $targetPath . '/' . $id, 'mode' => $mode], 'notify' => 'none',
        'snapshots' => ['keep' => ['hourly' => 0, 'daily' => 1, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0]],
        'versions' => ['keep' => ['daily' => 3, 'weekly' => 0, 'monthly' => 0]]]);
}
function restoreSelectionCases(array $params, string $prefix): void
{
    $refused = [
        'link-only' => ['/link-stable'],
        'sibling' => ['/restore-sibling'],
        'chain-intermediate' => ['/stable.txt', '/link-chain'],
        'subtree-chain-intermediate' => ['/restore-tree/document.txt', '/restore-tree/chain'],
        'dangling-only' => ['/restore-tree/dangling'],
    ];
    foreach ($refused as $name => $paths) {
        $destination = $prefix . '/refused-' . $name . '/destination';
        try {
            Restore::copy($params + ['paths' => $paths, 'target' => $destination]);
            expect(false, 'restore must reject an incomplete link selection: ' . $name);
        } catch (ZBG\UserError) {
            expect(true, 'restore rejects an incomplete link selection: ' . $name);
        }
        expect(!file_exists($destination) && !is_link($destination) && !file_exists(dirname($destination)),
            'selection rejection creates neither destination nor parent: ' . $name);
    }
    $chain = Restore::copy($params + [
        'paths' => ['/stable.txt', '/link-stable', '/link-chain'], 'target' => $prefix . '/chain']);
    expect(is_link($chain['target'] . '/link-stable') && is_link($chain['target'] . '/link-chain')
        && file_get_contents($chain['target'] . '/link-chain') === "unchanged\n",
        'restore preserves a completely selected link chain');
    expect(!file_exists($chain['target'] . '/alpha.txt'), 'restore does not copy unselected siblings');
    $tree = Restore::copy($params + [
        'paths' => ['/restore-tree', '/restore-tree/link'], 'target' => $prefix . '/tree']);
    expect(is_link($tree['target'] . '/restore-tree/link') && is_link($tree['target'] . '/restore-tree/chain')
        && file_get_contents($tree['target'] . '/restore-tree/chain') === "selected subtree\n",
        'restore preserves links inside a selected subtree after selection minimization');
    expect(is_link($tree['target'] . '/restore-tree/dangling')
        && readlink($tree['target'] . '/restore-tree/dangling') === 'missing.txt'
        && !file_exists($tree['target'] . '/restore-tree/missing.txt'),
        'restore preserves an existing dangling relative link inside a selected subtree');
    $siblings = Restore::copy($params + [
        'paths' => ['/restore-sibling', '/stable.txt'], 'target' => $prefix . '/siblings']);
    expect(is_link($siblings['target'] . '/restore-sibling/link')
        && file_get_contents($siblings['target'] . '/restore-sibling/link') === "unchanged\n",
        'restore preserves a subtree link when its sibling target is also selected');
}
function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) { removeTree($entry->getPathname()); }
    rmdir($path);
}
function awaitFinished(string $id, int $seconds = 20): array
{
    $deadline = microtime(true) + $seconds;
    do {
        $state = Runner::status($id);
        if (!in_array($state['state'] ?? '', ['running', 'starting', 'queued'], true)) { return $state; }
        usleep(100000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Worker did not finish: ' . $id);
}

try {
    expect((Zfs::pool($pool)['health'] ?? '') === 'ONLINE', 'test pool ONLINE');
    $http = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($http === false) { throw new RuntimeException($error); }
    $httpAddress = stream_socket_get_name($http, false);
    $httpWorker = pcntl_fork();
    if ($httpWorker < 0) { throw new RuntimeException('Cannot fork healthcheck fixture'); }
    if ($httpWorker === 0) {
        for ($request = 0; $request < 4; $request++) {
            $client = stream_socket_accept($http, 30);
            if ($client === false) { exit(1); }
            stream_set_timeout($client, 5);
            $requestLine = fgets($client);
            file_put_contents($work . '/healthcheck-requests', $requestLine, FILE_APPEND);
            while (($header = fgets($client)) !== false && trim($header) !== '') {}
            fwrite($client, 'HTTP/1.1 ' . ($request === 3 ? '500 Error' : '204 No Content') .
                "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            fclose($client);
        }
        fclose($http);
        exit(0);
    }
    fclose($http);
    $healthJob = ['healthcheckUrl' => 'http://' . $httpAddress . '/ping?token=test'];
    foreach (['start', 'success', 'fail'] as $event) { ZBG\Notify::healthcheck($healthJob, $event); }
    $httpFailed = false;
    try {
        ZBG\Notify::healthcheck($healthJob, 'success');
    } catch (RuntimeException) { $httpFailed = true; }
    expect($httpFailed, 'healthcheck HTTP failure propagates');
    pcntl_waitpid($httpWorker, $httpStatus);
    $httpWorker = null;
    expect(pcntl_wexitstatus($httpStatus) === 0 &&
        file_get_contents($work . '/healthcheck-requests') ===
        "GET /ping/start?token=test HTTP/1.1\r\nGET /ping?token=test HTTP/1.1\r\nGET /ping/fail?token=test HTTP/1.1\r\nGET /ping?token=test HTTP/1.1\r\n",
        'real healthcheck requests route start/success/fail and preserve query');
    foreach ([[$source, $sourcePath], [$target, $targetPath]] as [$dataset, $path]) {
        Exec::ok(['zfs', 'create', '-o', 'mountpoint=' . $path, $dataset]);
        $created[] = $dataset;
        $mountpoints[] = $path;
    }
    Exec::ok(['zfs', 'create', $source . '/child with spaces']);
    Exec::ok(['zfs', 'create', $source . '/excluded']);
    fresh();
    file_put_contents($sourcePath . '/alpha.txt', "alpha v1\n");
    file_put_contents($sourcePath . '/stable.txt', "unchanged\n");
    file_put_contents($sourcePath . '/child with spaces/beta.txt', "child v1\n");
    file_put_contents($sourcePath . '/excluded/private.txt', "excluded\n");
    symlink('alpha.txt', $sourcePath . '/relative-link');
    JobStore::writeJson('settings.json', ['logDir' => $work . '/logs', 'globalLock' => true, 'historyLimit' => 500]);
    mkdir($work . '/logs');

    $mirror = jobFor('integration-mirror');
    JobStore::save($mirror);
    $before = snapshotNames($source);
    runBackup($mirror, true);
    expect(snapshotNames($source) === $before, 'dry-run creates no snapshots');
    expect(!file_exists($mirror['target']['path']), 'dry-run creates no target');
    expect(History::status($mirror, History::forJob($mirror['id']))['lastSuccess'] === null, 'dry-run is not a successful backup');
    runBackup($mirror, false, true);
    expect(file_get_contents($mirror['target']['path'] . '/alpha.txt') === "alpha v1\n", 'mirror copies root data');
    expect(file_get_contents($mirror['target']['path'] . '/child with spaces/beta.txt') === "child v1\n", 'mirror copies child dataset with spaces');
    expect(!file_exists($mirror['target']['path'] . '/excluded/private.txt'), 'excluded dataset not copied');
    expect(is_link($mirror['target']['path'] . '/relative-link'), 'symlink copied without dereferencing');
    mkdir($mirror['target']['path'] . '/excluded', 0700, true);
    file_put_contents($mirror['target']['path'] . '/excluded/keep.txt', 'protected');
    unlink($sourcePath . '/alpha.txt');
    unlink($sourcePath . '/relative-link');
    runBackup($mirror);
    expect(!file_exists($mirror['target']['path'] . '/alpha.txt'), 'mirror propagates source deletions');
    expect(file_get_contents($mirror['target']['path'] . '/excluded/keep.txt') === 'protected', 'mirror never deletes protected child target');
    $own = array_values(array_filter(Zfs::snapshots($source), fn($s) => $s['own'] && $s['job'] === $mirror['id']));
    expect(count($own) === 1, 'successful retention keeps daily newest');
    $foreignName = 'zbg_' . $mirror['id'] . '_20000101-000000';
    Exec::ok(['zfs', 'snapshot', $source . '@' . $foreignName]);
    file_put_contents($sourcePath . '/alpha.txt', "alpha v2\n");
    runBackup($mirror);
    expect(in_array($foreignName, snapshotNames($source), true), 'prefix without ownership property is never pruned');
    $mirrorSnapshot = array_values(array_filter(Zfs::snapshots($source),
        fn($s) => $s['own'] && $s['job'] === $mirror['id']))[0]['snapshot'];
    $mirrorPlan = Zfs::plan($source, true, $mirror['source']['exclude']);
    try {
        ZBG\Rsync::transfer($mirror, $mirrorPlan, $mirrorSnapshot,
            static function (string $line) use ($source, $mirror): void {
                if ($line === 'Prüfsummenprüfung: ' . $source) {
                    // Real post-copy corruption, not a mocked rsync result.
                    file_put_contents($mirror['target']['path'] . '/alpha.txt', "corrupt!\n");
                }
            }, null, true);
        expect(false, 'checksum corruption must fail verification');
    } catch (ZBG\VerificationFailure $error) {
        expect($error->result['verification']['mismatches'] === 1, 'checksum detects one actually corrupted file');
    }
    symlink('stable.txt', $sourcePath . '/link-stable');
    symlink('link-stable', $sourcePath . '/link-chain');
    mkdir($sourcePath . '/restore-tree');
    file_put_contents($sourcePath . '/restore-tree/document.txt', "selected subtree\n");
    symlink('document.txt', $sourcePath . '/restore-tree/link');
    symlink('link', $sourcePath . '/restore-tree/chain');
    symlink('missing.txt', $sourcePath . '/restore-tree/dangling');
    mkdir($sourcePath . '/restore-sibling');
    symlink('../stable.txt', $sourcePath . '/restore-sibling/link');
    symlink('/etc/passwd', $sourcePath . '/escape-link');
    symlink('cycle-link', $sourcePath . '/cycle-link');

    $versioned = jobFor('integration-versioned', 'versioned');
    JobStore::save($versioned);
    runBackup($versioned);
    $v1 = Versions::list($versioned)[0];
    // Exercise daily retention without waiting a day: age only this test-owned
    // completed version's metadata; its real files remain unchanged.
    $manifestPath = $v1['path'] . '/.zbg-version.json';
    $manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifest['time'] = strtotime('yesterday noon');
    RunState::atomic($manifestPath, $manifest);
    unlink($sourcePath . '/escape-link');
    unlink($sourcePath . '/cycle-link');
    file_put_contents($sourcePath . '/alpha.txt', "alpha v3\n");
    runBackup($versioned, false, true);
    $v2 = Versions::list($versioned)[0];
    expect($v1['name'] !== $v2['name'], 'versioned publishes distinct completed versions');
    expect(file_get_contents($v1['path'] . '/alpha.txt') === "alpha v2\n", 'older version preserves changed content');
    expect(fileinode($v1['path'] . '/stable.txt') === fileinode($v2['path'] . '/stable.txt'), 'unchanged version files are hardlinks');
    expect(realpath($versioned['target']['path'] . '/latest') === realpath($v2['path']), 'latest points at newest completed version');
    $restore = Restore::copy(['job' => $versioned['id'], 'version' => $v1['name'], 'paths' => ['/alpha.txt'], 'target' => $targetPath . '/restored-version']);
    expect(file_get_contents($restore['target'] . '/alpha.txt') === "alpha v2\n", 'version restore recovers historical content');
    try {
        Restore::copy(['job' => $versioned['id'], 'version' => $v1['name'], 'paths' => ['/alpha.txt'], 'target' => $restore['target']]);
        expect(false, 'existing restore target must be rejected');
    } catch (ZBG\UserError) { expect(true, 'existing restore target rejected without overwrite'); }
    $linkedRestore = Restore::copy(['job' => $versioned['id'], 'version' => $v1['name'],
        'paths' => ['/stable.txt', '/link-stable'], 'target' => $targetPath . '/restored-link']);
    expect(is_link($linkedRestore['target'] . '/link-stable') && file_get_contents($linkedRestore['target'] . '/link-stable') === "unchanged\n", 'safe relative link restored with its target');
    restoreSelectionCases(['job' => $versioned['id'], 'version' => $v1['name']],
        $targetPath . '/selection-local');
    foreach (['escape-link', 'cycle-link'] as $unsafeLink) {
        try {
            Restore::copy(['job' => $versioned['id'], 'version' => $v1['name'], 'paths' => ['/' . $unsafeLink],
                'target' => $targetPath . '/refused-' . $unsafeLink]);
            expect(false, 'unsafe restore link must be rejected');
        } catch (ZBG\UserError) { expect(true, 'unsafe restore link rejected: ' . $unsafeLink); }
        expect(!file_exists($targetPath . '/refused-' . $unsafeLink), 'unsafe restore creates no destination');
    }
    $snap = array_values(array_filter(Zfs::snapshots($source), fn($s) => $s['job'] === $versioned['id'] && $s['own']))[0];
    $snapshotRestore = Restore::copy(['dataset' => $source, 'snapshot' => $snap['snapshot'], 'paths' => ['/stable.txt'], 'target' => $targetPath . '/restored-snapshot']);
    expect(file_get_contents($snapshotRestore['target'] . '/stable.txt') === "unchanged\n", 'snapshot restore copies selected file');
    restoreSelectionCases(['dataset' => $source, 'snapshot' => $snap['snapshot']],
        $targetPath . '/selection-snapshot');
    try {
        Browse::list($source, $snap['snapshot'], '../');
        expect(false, 'snapshot traversal must be rejected');
    } catch (ZBG\UserError) { expect(true, 'snapshot traversal rejected'); }
    $clone = Restore::clone($source, $snap['snapshot']);
    $clones[] = $clone['dataset'];
    expect(in_array('stable.txt', array_column(Browse::clone($clone['dataset'], '/')['entries'], 'name'), true), 'owned clone can be browsed');
    Restore::removeClone($clone['dataset']);
    $clones = [];
    expect(!in_array($clone['dataset'], array_column(Restore::clones(), 'dataset'), true), 'owned clone cleaned up');

    // Isolated real rsync source: cleanup must distinguish copied datasets from
    // snapshot-only children, and must never consume another owner's snapshots.
    $cleanupSource = $pool . '/zbg-integration-cleanup-source-' . $token;
    $cleanupPath = $sourcePath . '-cleanup';
    Exec::ok(['zfs', 'create', '-o', 'mountpoint=' . $cleanupPath, $cleanupSource]);
    $created[] = $cleanupSource;
    $mountpoints[] = $cleanupPath;
    Exec::ok(['zfs', 'create', $cleanupSource . '/copied']);
    Exec::ok(['zfs', 'create', '-o', 'mountpoint=none', $cleanupSource . '/snapshot-only']);
    Exec::ok(['zfs', 'create', '-V', '16M', $cleanupSource . '/volume']);
    file_put_contents($cleanupPath . '/document.txt', "cleanup v1\n");
    file_put_contents($cleanupPath . '/copied/child.txt', "copied child\n");
    $cleanupJob = jobFor('integration-cleanup', 'versioned');
    $cleanupJob['source'] = ['dataset' => $cleanupSource, 'recursive' => true, 'exclude' => []];
    $cleanupJob['snapshots']['keep'] = ['hourly' => 0, 'daily' => 0, 'weekly' => 0, 'monthly' => 0, 'yearly' => 0];
    $cleanupJob = Job::normalize($cleanupJob);
    JobStore::save($cleanupJob);
    runBackup($cleanupJob);
    $cleanupPlan = Zfs::plan($cleanupSource, true, []);
    $copiedDatasets = [$cleanupSource, $cleanupSource . '/copied'];
    $snapshotOnlyDatasets = [$cleanupSource . '/snapshot-only', $cleanupSource . '/volume'];
    foreach (array_merge($copiedDatasets, $snapshotOnlyDatasets) as $dataset) {
        $own = array_values(array_filter(Zfs::snapshots($dataset),
            fn($s) => $s['own'] && $s['job'] === $cleanupJob['id']));
        expect(count($own) === 1, 'default rsync keeps newest snapshot with zero retention: ' . $dataset);
    }
    $cleanupVersion = Versions::list($cleanupJob)[0];
    $cleanupManifestPath = $cleanupVersion['path'] . '/.zbg-version.json';
    $cleanupManifest = json_decode((string)file_get_contents($cleanupManifestPath), true, 512, JSON_THROW_ON_ERROR);
    $cleanupManifest['time'] = strtotime('yesterday noon');
    RunState::atomic($cleanupManifestPath, $cleanupManifest);
    foreach (['20000101-000000', '20000102-000000'] as $stamp) {
        Zfs::snapshotSelected($cleanupJob, $cleanupPlan, 'zbg_' . $cleanupJob['id'] . '_' . $stamp);
    }
    $cleanupForeign = 'zbg_' . $cleanupJob['id'] . '_19990101-000000';
    $otherOwner = 'integration-cleanup-other';
    $otherSnapshot = 'zbg_' . $otherOwner . '_20000101-000000';
    foreach ($copiedDatasets as $dataset) {
        Exec::ok(['zfs', 'snapshot', $dataset . '@' . $cleanupForeign]);
        Exec::ok(['zfs', 'snapshot', '-o', 'zbg:job=' . $otherOwner, $dataset . '@' . $otherSnapshot]);
    }
    $cleanupJob['snapshots']['removeAfterRsync'] = true;
    $cleanupJob = Job::normalize($cleanupJob);
    JobStore::save($cleanupJob, $cleanupJob['id']);
    $beforeCleanupDryRun = snapshotNames($cleanupSource);
    runBackup($cleanupJob, true);
    expect(snapshotNames($cleanupSource) === $beforeCleanupDryRun,
        'cleanup-enabled dry-run preserves all source snapshots');

    file_put_contents($cleanupPath . '/document.txt', "cleanup v2\n");
    $beforeCleanupFailure = snapshotNames($cleanupSource);
    $cleanupLatest = readlink($cleanupJob['target']['path'] . '/latest');
    // Flip the target read-only only after preflight, so failure exercises the
    // real transfer path after the new source snapshots have been committed.
    $cleanupFailureHook = $targetPath . '/cleanup-readonly-hook.sh';
    file_put_contents($cleanupFailureHook, "#!/bin/sh\nzfs set readonly=on " . escapeshellarg($target) . "\n");
    chmod($cleanupFailureHook, 0700);
    $cleanupJob['hooks']['pre'] = $cleanupFailureHook;
    JobStore::save($cleanupJob, $cleanupJob['id']);
    try {
        nextSecond();
        fresh();
        $cleanupFailed = Runner::run($cleanupJob);
    } finally {
        $cleanupJob['hooks']['pre'] = null;
        Exec::ok(['zfs', 'set', 'readonly=off', $target]);
        JobStore::save($cleanupJob, $cleanupJob['id']);
    }
    expect(($cleanupFailed['result'] ?? $cleanupFailed['state'] ?? '') === 'failed',
        'cleanup-enabled backup fails on a real read-only destination');
    expect(!array_diff($beforeCleanupFailure, snapshotNames($cleanupSource)),
        'failure before cleanup preserves every pre-existing source snapshot');
    foreach ($copiedDatasets as $dataset) {
        $own = array_values(array_filter(Zfs::snapshots($dataset),
            fn($s) => $s['own'] && $s['job'] === $cleanupJob['id']));
        expect(count($own) === 4, 'failed rsync also retains its newly created source snapshot: ' . $dataset);
    }
    expect(readlink($cleanupJob['target']['path'] . '/latest') === $cleanupLatest
        && file_get_contents($cleanupVersion['path'] . '/document.txt') === "cleanup v1\n",
        'failed cleanup-enabled backup preserves the readable published version');

    runBackup($cleanupJob, false, true);
    foreach ($copiedDatasets as $dataset) {
        $snapshots = Zfs::snapshots($dataset);
        $own = array_filter($snapshots, fn($s) => $s['own'] && $s['job'] === $cleanupJob['id']);
        expect(count($own) === 0, 'successful rsync removes older and newest own snapshots: ' . $dataset);
        $names = array_column($snapshots, 'snapshot');
        expect(in_array($cleanupForeign, $names, true) && in_array($otherSnapshot, $names, true),
            'cleanup preserves unowned matching names and another job owner: ' . $dataset);
    }
    foreach ($snapshotOnlyDatasets as $dataset) {
        $own = array_values(array_filter(Zfs::snapshots($dataset),
            fn($s) => $s['own'] && $s['job'] === $cleanupJob['id']));
        expect(count($own) === 1 && !in_array($own[0]['snapshot'], $beforeCleanupFailure, true),
            'snapshot-only dataset uses normal retention and retains the latest backup: ' . $dataset);
    }
    $cleanupVersions = Versions::list($cleanupJob);
    expect(count($cleanupVersions) === 2
        && file_get_contents($cleanupVersion['path'] . '/document.txt') === "cleanup v1\n",
        'source snapshot cleanup leaves the older target version readable');
    expect(file_get_contents($cleanupJob['target']['path'] . '/latest/document.txt') === "cleanup v2\n"
        && file_get_contents($cleanupJob['target']['path'] . '/latest/copied/child.txt') === "copied child\n",
        'verified target files remain readable after all copied source snapshots are removed');

    $send = jobFor('integration-send');
    $send['method'] = 'zfs';
    $send['target'] = ['dataset' => $receive, 'path' => '', 'mode' => 'versioned', 'ssh' => null];
    $send = Job::normalize($send);
    JobStore::save($send);
    $created[] = $receive;
    runBackup($send);
    file_put_contents($sourcePath . '/alpha.txt', "alpha v4\n");
    runBackup($send);
    fresh();
    $recvSnaps = Exec::ok(['zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', '-r', $receive]);
    expect(str_contains($recvSnaps, '@zbg_' . $send['id'] . '_'), 'incremental receive has owned snapshot');
    expect(str_contains(Exec::ok(['zfs', 'list', '-H', '-o', 'name', '-r', $receive]), 'child with spaces'), 'send receives recursive child with spaces');
    expect(!str_contains(Exec::ok(['zfs', 'list', '-H', '-o', 'name', '-r', $receive]), '/excluded'), 'send respects dataset exclusion');

    // Leave a genuine resumable incremental receive, then finish it through the runner.
    $base = array_values(array_filter(Zfs::snapshots($source), fn($s) => $s['own'] && $s['job'] === $send['id']));
    usort($base, fn($a, $b) => $b['creation'] <=> $a['creation']);
    file_put_contents($sourcePath . '/resume-payload.bin', random_bytes(4 * 1024 * 1024));
    nextSecond();
    $resumeSnap = 'zbg_' . $send['id'] . '_' . date('Ymd-His');
    Exec::ok(['zfs', 'snapshot', '-o', 'zbg:job=' . $send['id'], $source . '@' . $resumeSnap]);
    $stream = $work . '/partial-stream';
    Exec::ok(['bash', '-o', 'pipefail', '-c', ZBG\Remote::quote(['zfs', 'send', '-p', '-I',
        $source . '@' . $base[0]['snapshot'], $source . '@' . $resumeSnap]) . ' > ' . escapeshellarg($stream)], 120);
    clearstatcache(true, $stream);
    Exec::ok(['truncate', '-s', (string)intdiv(filesize($stream), 2), $stream]);
    $interrupted = Exec::run(['bash', '-o', 'pipefail', '-c',
        ZBG\Remote::quote(['zfs', 'receive', '-s', '-u', $receive]) . ' < ' . escapeshellarg($stream)], 120);
    expect($interrupted['code'] !== 0, 'truncated stream interrupts receive');
    expect(trim(Exec::ok(['zfs', 'get', '-H', '-o', 'value', 'receive_resume_token', $receive])) !== '-', 'interruption creates actual resume token');
    runBackup($send);
    expect(trim(Exec::ok(['zfs', 'get', '-H', '-o', 'value', 'receive_resume_token', $receive])) === '-', 'runner completes interrupted receive');

    $encryptedSource = $pool . '/zbg-integration-encrypted-source-' . $token;
    $encryptedReceive = $pool . '/zbg-integration-encrypted-recv-' . $token;
    $keyFile = $work . '/encryption.key';
    file_put_contents($keyFile, random_bytes(32));
    chmod($keyFile, 0600);
    Exec::ok(['zfs', 'create', '-o', 'encryption=aes-256-gcm', '-o', 'keyformat=raw',
        '-o', 'keylocation=file://' . $keyFile, '-o', 'mountpoint=' . $sourcePath . '-encrypted', $encryptedSource]);
    $created[] = $encryptedSource;
    $mountpoints[] = $sourcePath . '-encrypted';
    file_put_contents($sourcePath . '-encrypted/secret.txt', 'encrypted fixture');
    Exec::ok(['zfs', 'unmount', $encryptedSource]);
    Exec::ok(['zfs', 'unload-key', $encryptedSource]);
    fresh();
    $encryptedJob = $send;
    $encryptedJob['id'] = 'integration-encrypted';
    $encryptedJob['name'] = 'encrypted locked source';
    $encryptedJob['source'] = ['dataset' => $encryptedSource, 'recursive' => false, 'exclude' => []];
    $encryptedJob['target']['dataset'] = $encryptedReceive;
    JobStore::save($encryptedJob);
    $created[] = $encryptedReceive;
    runBackup($encryptedJob);
    expect(trim(Exec::ok(['zfs', 'get', '-H', '-o', 'value', 'encryption', $encryptedReceive])) !== 'off', 'raw receive preserves encryption without loading source key');

    $volumeSource = $pool . '/zbg-integration-volume-source-' . $token;
    $volumeReceive = $pool . '/zbg-integration-volume-recv-' . $token;
    Exec::ok(['zfs', 'create', '-V', '16M', $volumeSource]);
    $created[] = $volumeSource;
    fresh();
    $volumeJob = $send;
    $volumeJob['id'] = 'integration-volume';
    $volumeJob['name'] = 'zvol fixture';
    $volumeJob['source'] = ['dataset' => $volumeSource, 'recursive' => false, 'exclude' => []];
    $volumeJob['target']['dataset'] = $volumeReceive;
    JobStore::save($volumeJob);
    $created[] = $volumeReceive;
    runBackup($volumeJob);
    expect(trim(Exec::ok(['zfs', 'get', '-H', '-o', 'value', 'type', $volumeReceive])) === 'volume', 'zvol receive retains block-device type');
    expect(trim(Exec::ok(['zfs', 'get', '-Hp', '-o', 'value', 'volsize', $volumeReceive])) === '16777216', 'zvol receive retains capacity');

    // Failure keeps prior completed versions/latest and never runs retention.
    $beforeFail = snapshotNames($source);
    $latest = readlink($versioned['target']['path'] . '/latest');
    Exec::ok(['zfs', 'set', 'readonly=on', $target]);
    nextSecond();
    $failed = Runner::run($versioned);
    Exec::ok(['zfs', 'set', 'readonly=off', $target]);
    expect(($failed['result'] ?? $failed['state'] ?? '') === 'failed', 'unwritable destination fails backup');
    expect(readlink($versioned['target']['path'] . '/latest') === $latest, 'failed backup leaves latest unchanged');
    expect(!array_diff($beforeFail, snapshotNames($source)), 'failed backup prunes no snapshots');

    $requestMethod = getenv('REQUEST_METHOD');
    putenv('REQUEST_METHOD=POST');
    try { Runner::start($mirror['id'], true); }
    finally { putenv($requestMethod === false ? 'REQUEST_METHOD' : 'REQUEST_METHOD=' . $requestMethod); }
    $webWorker = awaitFinished($mirror['id']);
    expect(($webWorker['state'] ?? '') === 'success' && ($webWorker['dryRun'] ?? false),
        'detached worker finishes despite inherited web request environment');

    // Detached worker, same-job exclusion and cancellation while a real hook is running.
    $hook = $targetPath . '/slow-hook.sh';
    file_put_contents($hook, "#!/bin/sh\nsleep 30\n");
    chmod($hook, 0700);
    $cancelJob = jobFor('integration-cancel');
    $cancelJob['hooks']['pre'] = $hook;
    JobStore::save($cancelJob);
    Runner::start($cancelJob['id']);
    try {
        Runner::start($cancelJob['id']);
        expect(false, 'same job must not start twice');
    } catch (ZBG\UserError) { expect(true, 'same-job concurrent start rejected'); }
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && !str_contains(json_encode(Runner::status($cancelJob['id'])), 'hook')) { usleep(100000); }
    Runner::cancel($cancelJob['id']);
    $cancelled = awaitFinished($cancelJob['id']);
    expect(($cancelled['state'] ?? '') === 'cancelled', 'detached worker cancellation terminates real child');

    if (in_array('--ssh', $argv, true)) {
        $sshDir = $targetPath . '/ssh-fixture';
        mkdir($sshDir, 0700);
        Exec::ok(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $sshDir . '/client']);
        Exec::ok(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $sshDir . '/host']);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) { throw new RuntimeException($error); }
        $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $pub = trim(file_get_contents($sshDir . '/host.pub'));
        file_put_contents($sshDir . '/known_hosts', '[127.0.0.1]:' . $port . ' ' . $pub . "\n");
        // StrictModes rejects world-writable pool roots and /tmp ancestors.
        // Use an isolated file under root's home, never its actual authorized_keys.
        $authorizedKey = '/root/.zbg-integration-key-' . $token;
        copy($sshDir . '/client.pub', $authorizedKey);
        chmod($authorizedKey, 0600);
        file_put_contents($sshDir . '/sshd_config', "ListenAddress 127.0.0.1\nPort $port\nHostKey $sshDir/host\nAuthorizedKeysFile $authorizedKey\nPidFile $sshDir/sshd.pid\nPermitRootLogin yes\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nUsePAM no\nStrictModes yes\nLogLevel ERROR\n");
        $sshd = proc_open(['/usr/sbin/sshd', '-D', '-e', '-f', $sshDir . '/sshd_config'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $sshDir . '/sshd.log', 'a'], 2 => ['file', $sshDir . '/sshd.log', 'a']], $pipes);
        if (!is_resource($sshd)) { throw new RuntimeException('Cannot start isolated sshd'); }
        $ssh = ['host' => '127.0.0.1', 'port' => $port, 'user' => 'root', 'key' => $sshDir . '/client', 'knownHosts' => $sshDir . '/known_hosts'];
        $deadline = microtime(true) + 5;
        do {
            $probe = Exec::run(ZBG\Remote::command($ssh, ['true']), 2);
            if ($probe['code'] === 0) { break; }
            usleep(100000);
        } while (microtime(true) < $deadline);
        expect($probe['code'] === 0, 'isolated SSH trusts generated host key: ' .
            ($probe['err'] ?? '') . (string)file_get_contents($sshDir . '/sshd.log'));
        $sshJob = jobFor('integration-ssh-rsync', 'versioned');
        $sshJob['target']['ssh'] = $ssh;
        JobStore::save($sshJob);
        $oddName = "space and\nnewline.txt";
        file_put_contents($sourcePath . '/' . $oddName, 'odd filename content');
        runBackup($sshJob);
        file_put_contents($sourcePath . '/alpha.txt', "ssh incremental\n");
        runBackup($sshJob, false, true);
        expect(file_get_contents($sshJob['target']['path'] . '/latest/alpha.txt') === "ssh incremental\n", 'remote versioned rsync copies verified content');
        $sshVersion = Versions::list($sshJob)[0];
        $remoteListing = Browse::version($sshJob['id'], $sshVersion['name'], '/');
        expect(in_array($oddName, array_column($remoteListing['entries'], 'name'), true), 'remote version browser preserves whitespace/newline filenames');
        $remoteRestore = Restore::copy(['job' => $sshJob['id'], 'version' => $sshVersion['name'],
            'paths' => ['/' . $oddName, '/stable.txt', '/link-stable'], 'target' => $targetPath . '/restored-ssh']);
        expect(file_get_contents($remoteRestore['target'] . '/' . $oddName) === 'odd filename content', 'remote version restore copies exact selected filename');
        expect(is_link($remoteRestore['target'] . '/link-stable') && file_get_contents($remoteRestore['target'] . '/link-stable') === "unchanged\n", 'remote version restore preserves safe links');
        restoreSelectionCases(['job' => $sshJob['id'], 'version' => $sshVersion['name']],
            $targetPath . '/selection-ssh');
        $sshSend = $send;
        $sshSend['id'] = 'integration-ssh-send';
        $sshSend['name'] = 'SSH send';
        $sshSend['target']['dataset'] = $sshReceive;
        $sshSend['target']['ssh'] = $ssh;
        JobStore::save($sshSend);
        $created[] = $sshReceive;
        runBackup($sshSend);
        file_put_contents($sourcePath . '/alpha.txt', "ssh send incremental\n");
        runBackup($sshSend);
        expect(str_contains(Exec::ok(['zfs', 'list', '-H', '-t', 'snapshot', '-o', 'name', '-r', $sshReceive]), '@zbg_' . $sshSend['id']), 'SSH ZFS incremental receive succeeds');
    }

    foreach ($argv as $argument) {
        if (!str_starts_with($argument, '--docker-image=')) { continue; }
        $image = substr($argument, strlen('--docker-image='));
        Exec::ok(['docker', 'image', 'inspect', $image]); // Do not pull images or use production containers.
        $container = 'zbg-integration-' . $token;
        Exec::ok(['docker', 'run', '-d', '--name', $container, '--network', 'none', '--read-only', '--entrypoint', '/bin/sh', $image, '-c', 'while :; do sleep 60; done']);
        foreach (['pause', 'stop'] as $mode) {
            $consistent = jobFor('integration-' . $mode);
            $consistent['consistency'] = ['mode' => $mode, 'containers' => [$container]];
            JobStore::save($consistent);
            runBackup($consistent);
            expect(trim(Exec::ok(['docker', 'inspect', '-f', '{{.State.Running}} {{.State.Paused}}', $container])) === 'true false', 'container resumed after ' . $mode . ' snapshot');
        }
        Exec::ok(['docker', 'stop', $container]);
        runBackup($consistent);
        expect(trim(Exec::ok(['docker', 'inspect', '-f', '{{.State.Running}}', $container])) === 'false', 'initially stopped container remains stopped');
        Exec::ok(['docker', 'start', $container]);
        $journal = [];
        ZBG\Docker::quiesce('pause', [$container],
            static function (array $entries) use (&$journal): void { $journal = $entries; },
            static function (string $line): void {});
        $deadWorker = pcntl_fork();
        if ($deadWorker === 0) { sleep(60); exit(0); }
        if ($deadWorker < 0) { throw new RuntimeException('Cannot fork recovery fixture'); }
        RunState::write('integration-recovery', ['state' => 'running', 'pid' => $deadWorker,
            'pidIdentity' => RunState::pidIdentity($deadWorker), 'start' => time(),
            'runId' => 'recovery-' . $token, 'containers' => $journal]);
        posix_kill($deadWorker, SIGKILL);
        pcntl_waitpid($deadWorker, $childStatus);
        $recovered = Runner::status('integration-recovery');
        expect($recovered['state'] === 'failed' && $recovered['containers'] === [],
            'dead worker recovery clears durable container journal and records failure');
        expect(trim(Exec::ok(['docker', 'inspect', '-f', '{{.State.Running}} {{.State.Paused}}', $container])) === 'true false',
            'dead worker recovery unpauses actual container');
    }
    echo "ALL $checks INTEGRATION CHECKS PASSED\n";
} catch (Throwable $error) {
    fwrite(STDERR, "INTEGRATION FAILED: " . $error . "\n");
    $failedCode = 1;
} finally {
    try { Runner::cancelAll(); } catch (Throwable $e) { fwrite(STDERR, 'Worker cleanup: ' . $e->getMessage() . "\n"); }
    if ($httpWorker !== null && $httpWorker > 0) {
        posix_kill($httpWorker, SIGTERM);
        pcntl_waitpid($httpWorker, $httpStatus);
    }
    if (is_resource($sshd)) { proc_terminate($sshd); proc_close($sshd); }
    if ($authorizedKey !== null) { unlink($authorizedKey); }
    if ($container !== null) { Exec::run(['docker', 'rm', '-f', $container]); }
    foreach ($clones as $clone) { try { Restore::removeClone($clone); } catch (Throwable $e) { fwrite(STDERR, 'Clone cleanup: ' . $e->getMessage() . "\n"); } }
    Exec::run(['zfs', 'set', 'readonly=off', $target]);
    foreach (array_reverse($created) as $dataset) {
        if (!str_starts_with($dataset, $pool . '/zbg-integration-') || !str_ends_with($dataset, $token)) { continue; }
        // Kurz belegte Datasets (gerade beendete Kindprozesse) erneut versuchen, Fehler melden.
        for ($attempt = 1; ; $attempt++) {
            $destroyed = Exec::run(['zfs', 'destroy', '-r', $dataset], 120);
            if ($destroyed['code'] === 0 || Exec::run(['zfs', 'list', '-H', '-o', 'name', $dataset])['code'] !== 0) { break; }
            if ($attempt >= 10) { fwrite(STDERR, 'Dataset cleanup: ' . $dataset . ': ' . trim($destroyed['err'] ?? '') . "\n"); break; }
            sleep(3);
        }
    }
    foreach (array_reverse($mountpoints) as $path) {
        if (str_contains(basename($path), $token) && is_dir($path) && !is_link($path)) { @rmdir($path); }
    }
    removeTree($work);
}
exit($failedCode ?? 0);
