<?php
declare(strict_types=1);

/* Web/API: JobStore, Api::dispatch, Einstellungen, Zeitplan, Hooks (eingebunden von tests/run.php) */

use ZBG\{Api, Cron, History, Job, JobStore, RunState, Zfs};

$webMounts = "cache /mnt/cache zfs rw 0 0\ncache/appdata /mnt/cache/appdata zfs rw 0 0\ncache/domains /mnt/cache/domains zfs rw 0 0\nshfs /mnt/user fuse.shfs rw 0 0\n";
$webFixture = static function (bool $withAppdata) use ($row, $webMounts): void {
    Zfs::setFixture([
        'pools' => [['name' => 'cache', 'size' => '1', 'alloc' => '0', 'free' => '1', 'health' => 'ONLINE', 'capacity' => '1', 'frag' => '0']],
        'mounts' => $webMounts,
        'datasets' => array_values(array_filter([
            $row('cache', '/mnt/cache'),
            $withAppdata ? $row('cache/appdata', '/mnt/cache/appdata') : null,
            $row('cache/domains', '/mnt/cache/domains'),
        ])),
    ]);
};
$webFixture(true);
$webJob = ['id' => 'web-a', 'name' => 'Web A', 'enabled' => true, 'source' => ['dataset' => 'cache/appdata'],
    'target' => ['path' => '/mnt/user/backup/zfs/appdata', 'mode' => 'mirror']];

/* Api::dispatch */
throws('Api: unbekannte Aktion', static fn() => Api::dispatch('gibts.nicht', []));
throws('Api: fehlender Parameter id', static fn() => Api::dispatch('jobs.delete', []));
throws('Api: leerer Parameter id', static fn() => Api::dispatch('jobs.toggle', ['id' => '']));
throws('Api: ungültiger Cron-Ausdruck', static fn() => Api::dispatch('cron.next', ['expr' => '99 * * * *']));
eq('Api: Cron, der nie zutrifft, liefert keine Termine', Api::dispatch('cron.next', ['expr' => '0 0 31 2 *']), []);
eq('Cron: 31. Februar → nie', Cron::next('0 0 31 2 *'), null);

/* include/api.php: nur POST, CSRF-Prüfung (eigener Prozess, da exit/header) */
$apiPhp = realpath(__DIR__ . '/../../src/usr/local/emhttp/plugins/zfs-backup-guard/include/api.php');
$apiCall = static function (string $method, array $post) use ($apiPhp): string {
    $code = '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . '; $_POST = ' . var_export($post, true) . '; include ' . var_export($apiPhp, true) . ';';
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
};
check('API-Endpunkt: GET → 405 „Nur POST erlaubt“', str_contains($apiCall('GET', []), 'Nur POST erlaubt'));
check('API-Endpunkt: falsches CSRF-Token → 403', str_contains($apiCall('POST', ['action' => 'overview', 'csrf_token' => 'falsch-' . bin2hex(random_bytes(4))]), 'CSRF'));

/* Speichern, Umbenennen über originalId, Revision */
$saved = JobStore::save($webJob);
$webJobCount = count(JobStore::all());
$rev = JobStore::revision(JobStore::get('web-a'));
$updated = Api::dispatch('jobs.save', ['job' => ['name' => 'Web A neu'] + $webJob, 'originalId' => 'web-a', 'revision' => $rev]);
eq('Job: Bearbeiten über originalId ersetzt in place', [JobStore::get('web-a')['name'], count(JobStore::all())], ['Web A neu', $webJobCount]);
throws('Job: veraltete Revision abgewiesen (anderer Tab)', static fn() => Api::dispatch('jobs.save', ['job' => ['name' => 'Web A alt'] + $webJob, 'originalId' => 'web-a', 'revision' => $rev]));
eq('Job: abgewiesene Revision ändert nichts', JobStore::get('web-a')['name'], 'Web A neu');
JobStore::save(['name' => 'Web A ohne Revision'] + $webJob, 'web-a');
eq('Job: ohne Revision weiterhin Last-Write-Wins (kompatibel)', JobStore::get('web-a')['name'], 'Web A ohne Revision');
throws('Job: Umbenennen der ID über originalId abgewiesen', static fn() => Api::dispatch('jobs.save', ['job' => ['id' => 'web-b'] + $webJob, 'originalId' => 'web-a']));
throws('Job: Revision eines gelöschten Jobs abgewiesen', static fn() => JobStore::save(['id' => 'web-weg', 'name' => 'Weg'] + $webJob, 'web-weg', 'abc'));

/* SSH-Ziel: lokale Überlappungsprüfung entfällt */
eq('Job: SSH-Ziel mit lokal überlappendem Pfad erlaubt', Job::normalize(['target' => ['path' => '/mnt/cache/appdata/backup', 'mode' => 'mirror', 'ssh' => ['host' => 'backup.example']]] + $webJob)['target']['ssh']['host'], 'backup.example');
throws('Job: lokales Ziel in der Quelle weiter abgewiesen', static fn() => Job::normalize(['target' => ['path' => '/mnt/cache/appdata/backup', 'mode' => 'mirror']] + $webJob));

/* Zeitplan: Quoting, %-Escaping, pausierte Jobs fehlen */
JobStore::save(['id' => 'web-off', 'name' => 'Web Off', 'enabled' => false, 'target' => ['path' => '/mnt/user/backup/zfs/web-off', 'mode' => 'mirror']] + $webJob);
throws('Job: Ziel im Ziel eines anderen Jobs abgewiesen', static fn() => JobStore::save(['id' => 'web-nested', 'name' => 'Web Nested', 'enabled' => false, 'target' => ['path' => '/mnt/user/backup/zfs/appdata/nested', 'mode' => 'mirror']] + $webJob));
$cron = file_get_contents(JobStore::file('zfs-backup-guard.cron'));
check('Cron: aktiver Job mit gequoteten Argumenten', (bool)preg_match("~^0 2 \\* \\* \\* '/usr/bin/php' '[^']+/bin/zbg' 'run' 'web-a' '--scheduled' >/dev/null 2>&1$~m", $cron), $cron);
check('Cron: pausierter Job nicht geplant', !str_contains($cron, "'web-off'"));
check('Cron: Altersprüfung stündlich', str_contains($cron, "0 * * * * '/usr/bin/php'") && str_contains($cron, "'check-age'"));
$schedCmd = (new ReflectionMethod(ZBG\Schedule::class, 'command'))->invoke(null, '/x%y/zbg', ['run', 'a']);
check('Cron: % wird für cron escaped', str_contains($schedCmd, "'/x\\%y/zbg'") && !preg_match('~(?<!\\\\)%~', $schedCmd), $schedCmd);

/* Pausieren ohne Dataset (Pool weg) */
$webFixture(false);
$paused = JobStore::setEnabled('web-a', false);
check('Job: Pausieren gelingt trotz fehlendem Dataset', $paused['enabled'] === false && JobStore::get('web-a')['enabled'] === false);
check('Cron: pausierter Job entfernt', !str_contains(file_get_contents(JobStore::file('zfs-backup-guard.cron')), "'web-a'"));
check('Job: Umschalten ändert nur das Flag', JobStore::setEnabled('web-a', true)['enabled'] === true && JobStore::get('web-a')['name'] === 'Web A ohne Revision');
check('Cron: wieder aktivierter Job geplant', str_contains(file_get_contents(JobStore::file('zfs-backup-guard.cron')), "'web-a'"));
$webFixture(true);

/* Einstellungen */
$settingsBefore = JobStore::settings();
throws('Einstellungen: Log-Verzeichnis auf dem Flash abgewiesen', static fn() => JobStore::saveSettings(['logDir' => '/boot/logs']));
throws('Einstellungen: relatives Log-Verzeichnis abgewiesen', static fn() => JobStore::saveSettings(['logDir' => 'mnt/x']));
throws('Einstellungen: „..“ abgewiesen', static fn() => JobStore::saveSettings(['logDir' => '/mnt/user/../../etc']));
eq('Einstellungen: historyLimit nach unten begrenzt', JobStore::saveSettings(['historyLimit' => 1])['historyLimit'], 50);
eq('Einstellungen: historyLimit nach oben begrenzt', JobStore::saveSettings(['historyLimit' => 999999])['historyLimit'], 5000);
eq('Einstellungen: ungültige Eingabe ändert logDir nicht', JobStore::settings()['logDir'], $settingsBefore['logDir']);
$apiSettings = Api::dispatch('settings.get', []);
check('Einstellungen: genutztes Verzeichnis im Payload', isset($apiSettings['activeLogDir'], $apiSettings['logFallback']) && $apiSettings['activeLogDir'] === dirname(History::dir()));

// Umzug des Log-Verzeichnisses braucht einen Pfad unter /mnt (WSL: /mnt/c/…, Unraid: Share)
$repoRoot = (string)realpath(__DIR__ . '/../..');
$moveBase = $repoRoot . '/.zbg-web-test-' . getmypid();
if (str_starts_with($repoRoot, '/mnt/')) {
    try {
        mkdir($moveBase, 0700, true);
        History::append('web-a', ['runId' => 'web-move-1', 'start' => 1, 'end' => 2, 'result' => 'success']);
        $oldBase = dirname(History::dir());
        RunState::ensureDirectory($oldBase . '/logs');
        file_put_contents($oldBase . '/logs/web-a-test.log', "log\n");
        $historyBefore = History::forJob('web-a');

        RunState::write('web-a', ['state' => 'running', 'pid' => getmypid(), 'pidIdentity' => RunState::pidIdentity(getmypid()), 'start' => time()]);
        throws('Einstellungen: Umzug während laufendem Job abgewiesen', static fn() => JobStore::saveSettings(['logDir' => $moveBase . '/neu']));
        RunState::write('web-a', ['state' => 'success', 'pid' => 0]);
        eq('Einstellungen: abgewiesener Umzug lässt logDir', JobStore::settings()['logDir'], $settingsBefore['logDir']);

        $moved = JobStore::saveSettings(['logDir' => $moveBase . '/neu']);
        eq('Einstellungen: neues Log-Verzeichnis gespeichert', $moved['logDir'], $moveBase . '/neu');
        eq('Einstellungen: Verlauf zieht mit um', History::forJob('web-a'), $historyBefore);
        check('Einstellungen: Logs ziehen mit um', is_file($moveBase . '/neu/logs/web-a-test.log') && !is_dir($oldBase . '/history') && !is_dir($oldBase . '/logs'));

        mkdir($moveBase . '/belegt/history', 0700, true);
        file_put_contents($moveBase . '/belegt/history/web-a.jsonl', "{}\n");
        throws('Einstellungen: belegtes Ziel wird nicht überschrieben', static fn() => JobStore::saveSettings(['logDir' => $moveBase . '/belegt']));
        eq('Einstellungen: belegtes Ziel lässt logDir', JobStore::settings()['logDir'], $moveBase . '/neu');
        eq('Einstellungen: belegtes Ziel lässt Verlauf', History::forJob('web-a'), $historyBefore);
        throws('Einstellungen: neues Verzeichnis im alten Verlaufsordner abgewiesen', static fn() => JobStore::saveSettings(['logDir' => $moveBase . '/neu/history/x']));

        /* Hooks: Datei muss existieren, regulär, root-eigen und nicht gruppen-/weltbeschreibbar sein */
        $hook = $moveBase . '/hook.sh';
        file_put_contents($hook, "#!/bin/bash\n");
        chmod($hook, 0755);
        throws('Hook: fehlende Datei abgewiesen', static fn() => Job::normalize(['hooks' => ['pre' => $moveBase . '/fehlt.sh']] + $webJob));
        symlink($hook, $moveBase . '/link.sh');
        throws('Hook: Symlink abgewiesen', static fn() => Job::normalize(['hooks' => ['post' => $moveBase . '/link.sh']] + $webJob));
        eq('Hook: ohne Systemprüfung nur Pfadnormalisierung', Job::normalize(['hooks' => ['pre' => $moveBase . '/fehlt.sh']] + $webJob, false)['hooks']['pre'], $moveBase . '/fehlt.sh');
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && fileowner($hook) === 0) {
            eq('Hook: root-eigene Datei 0755 erlaubt', Job::normalize(['hooks' => ['pre' => $hook]] + $webJob)['hooks']['pre'], $hook);
            chmod($hook, 0777);
            clearstatcache();
            if ((fileperms($hook) & 0022) !== 0) {
                throws('Hook: weltbeschreibbare Datei abgewiesen', static fn() => Job::normalize(['hooks' => ['pre' => $hook]] + $webJob));
            }
        } elseif (fileowner($hook) !== 0) {
            throws('Hook: nicht root-eigene Datei abgewiesen', static fn() => Job::normalize(['hooks' => ['pre' => $hook]] + $webJob));
        }
    } finally {
        RunState::write('web-a', ['state' => 'success', 'pid' => 0]);
        JobStore::writeJson('settings.json', $settingsBefore);
        removeFixtureTree($moveBase);
    }
}
if (!is_writable('/mnt')) {
    $beforeUnreachable = JobStore::settings();
    throws('Einstellungen: nicht erreichbares Log-Verzeichnis abgewiesen (kein stilles RAM-Ausweichen)', static fn() => JobStore::saveSettings(['logDir' => '/mnt/zbg-gibts-nicht-' . getmypid() . '/logs']));
    eq('Einstellungen: nicht erreichbares Verzeichnis lässt logDir', JobStore::settings()['logDir'], $beforeUnreachable['logDir']);
}

JobStore::delete('web-a');
JobStore::delete('web-off');
