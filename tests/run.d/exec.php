<?php
declare(strict_types=1);

/* Bereich exec: Runner, RunState, Exec, Docker, Notify, Cron, History (ohne echtes Docker/ZFS: Exec::fake). */

use ZBG\{Cron, Docker, Exec, History, JobStore, Runner, RunState, Zfs};

$execCalls = [];
$execFake = static function (callable $answer) use (&$execCalls): void {
    $execCalls = [];
    Exec::fake(static function (array $cmd, int $timeout) use (&$execCalls, $answer): ?array {
        $execCalls[] = $cmd;
        return $answer($cmd) ?? ['code' => 0, 'out' => '', 'err' => ''];
    });
};
$inspectJson = static fn(bool $running, bool $paused = false): array => ['code' => 0, 'out' => json_encode(['Id' => 'abc123', 'State' => ['Running' => $running, 'Paused' => $paused]]), 'err' => ''];
$privateRunner = static fn(string $name): ReflectionMethod => new ReflectionMethod(Runner::class, $name);

Zfs::setFixture([
    'pools' => [['name' => 'cache', 'size' => '1', 'alloc' => '1', 'free' => '0', 'health' => 'ONLINE', 'capacity' => '1', 'frag' => '1']],
    'datasets' => [$row('cache', '/mnt/cache'), $row('cache/appdata', '/mnt/cache/appdata')],
    'mounts' => "cache /mnt/cache zfs rw 0 0\ncache/appdata /mnt/cache/appdata zfs rw 0 0",
]);

try {
    // Cron: "*/2" im Tag gilt als unbeschränkt → UND-Verknüpfung mit dem Wochentag (wie cron)
    $cronSets = Cron::parse('0 0 */2 * 1');
    check('Cron: */2 im Tag ist für die ODER-Regel unbeschränkt', $cronSets['dom'] === false && $cronSets['dow'] === true);
    eq('Cron: */2 + Montag = ungerader Montag', Cron::next('0 0 */2 * 1', ts('2026-10-14 00:00')), ts('2026-10-19 00:00'));

    // Docker: neu erstellter Container ("No such object") gilt als erledigt statt ewig zu werfen
    $execFake(static fn(array $cmd): ?array => $cmd[1] === 'inspect' ? ['code' => 1, 'out' => '', 'err' => 'Error: No such object: abc123'] : null);
    $journalSeen = null;
    $recoveryLines = [];
    try {
        Docker::recover([['name' => 'gone', 'id' => 'abc123', 'mode' => 'stop']], static function (string $l) use (&$recoveryLines): void { $recoveryLines[] = $l; }, static function (array $e) use (&$journalSeen): void { $journalSeen = $e; });
        $recoverThrew = false;
    } catch (Throwable) {
        $recoverThrew = true;
    }
    check('Docker: verschwundener Container wirft nicht', !$recoverThrew);
    eq('Docker: verschwundener Container aus Journal entfernt', $journalSeen, []);
    check('Docker: verschwundener Container mit Warnung protokolliert', (bool)preg_grep('/existiert nicht mehr/', $recoveryLines));

    // Docker: ein noch laufendes "docker stop" wird abgewartet, danach wird gestartet
    Docker::$settleSeconds = 5;
    $inspects = 0;
    $execFake(static function (array $cmd) use (&$inspects, $inspectJson): ?array {
        if ($cmd[1] === 'inspect') {
            return $inspectJson(++$inspects < 3);
        }
        return null;
    });
    Docker::recover([['name' => 'db', 'id' => 'abc123', 'mode' => 'stop']], static function (string $l): void {});
    check('Docker: Stop-Wiederherstellung wartet, bis der Container steht, und startet ihn', in_array(['docker', 'start', 'abc123'], $execCalls, true) && $inspects >= 3);

    // Docker: stop-Befehl bekommt keinen Abbruch-Callback (Daemon würde sonst weiter stoppen)
    $execFake(static fn(array $cmd): ?array => $cmd[1] === 'inspect' ? $inspectJson(true) : null);
    $quiesceSource = (string)file_get_contents((new ReflectionClass(Docker::class))->getFileName());
    check('Docker: stop/pause ohne Abbruch-Callback', str_contains($quiesceSource, 'Exec::stream($command, $log, null, 90)'));

    // Runner: unrettbarer Container → Lauf abgeschlossen, status() wirft nicht, cancelAll wirft nicht
    Docker::$settleSeconds = 0;
    $execFake(static fn(array $cmd): ?array => match ($cmd[0]) {
        'docker' => ['code' => 1, 'out' => '', 'err' => 'Cannot connect to the Docker daemon'],
        default => null,
    });
    RunState::write('exec-stale', ['id' => '20261014-020000-abcdef01', 'runId' => '20261014-020000-abcdef01', 'job' => 'exec-stale', 'state' => 'running', 'pid' => 0, 'start' => time() - 3600, 'containers' => [['name' => 'db', 'id' => 'abc123', 'mode' => 'stop']]]);
    try {
        $staleStatus = Runner::status('exec-stale');
        $statusThrew = false;
    } catch (Throwable $e) {
        $statusThrew = true;
        $staleStatus = [];
    }
    check('Runner: status() wirft trotz fehlgeschlagener Wiederherstellung nicht', !$statusThrew);
    eq('Runner: abgebrochener Worker wird trotzdem abgeschlossen', $staleStatus['state'] ?? null, 'failed');
    check('Runner: Wiederherstellungsfehler wird gemeldet', str_contains((string)($staleStatus['recoveryError'] ?? ''), 'Docker daemon'));
    eq('Runner: Abschluss steht im Verlauf', array_column(History::forJob('exec-stale'), 'runId'), ['20261014-020000-abcdef01']);
    try {
        $warnings = Runner::cancelAll(2, 0);
        $cancelThrew = false;
    } catch (Throwable) {
        $cancelThrew = true;
        $warnings = [];
    }
    check('Runner: cancelAll wirft nie, meldet Warnungen', !$cancelThrew && (bool)preg_grep('/^exec-stale: /', $warnings), json_encode($warnings));
    check('Runner: cancelAll setzt Array-Stopp-Markierung', RunState::stopping());
    throws('Runner: start() während Array-Stopp abgelehnt', static fn() => Runner::start('fixture-b'));
    JobStore::setEnabled('fixture-b', true);
    $execFake(static fn(array $cmd): ?array => null);
    $skipped = Runner::run(JobStore::get('fixture-b'), false, false, true);
    eq('Runner: geplanter Lauf während Array-Stopp übersprungen', [$skipped['result'] ?? null, $skipped['reason'] ?? null], ['skipped', 'Array wird gestoppt']);
    check('Runner: Übersprung ins Syslog', (bool)array_filter($execCalls, static fn(array $c): bool => $c[0] === 'logger'));
    Runner::arrayStarted();
    check('Runner: Ereignis started hebt Array-Stopp auf', !RunState::stopping());

    // Runner: geplanter Lauf, während der Job läuft → übersprungen und im laufenden Log vermerkt
    $overlapLog = getenv('ZBG_CONFIG_DIR') . '/overlap.log';
    touch($overlapLog);
    RunState::write('fixture-b', ['state' => 'running', 'runId' => '20261014-020000-abcdef02', 'pid' => getmypid(), 'pidIdentity' => RunState::pidIdentity(getmypid()), 'start' => time(), 'log' => $overlapLog]);
    $overlap = Runner::run(JobStore::get('fixture-b'), false, false, true);
    eq('Runner: überlappender geplanter Lauf übersprungen', $overlap['reason'] ?? null, 'Job läuft bereits');
    check('Runner: Übersprung im laufenden Log', str_contains((string)file_get_contents($overlapLog), 'Geplanter Lauf übersprungen'));
    RunState::write('fixture-b', ['state' => 'success', 'pid' => 0]);

    // Healthcheck: Fehler sind nur Warnungen
    $execFake(static fn(array $cmd): ?array => $cmd[0] === 'curl' ? ['code' => 7, 'out' => '', 'err' => 'Failed to connect'] : null);
    $hcJob = ['name' => 'HC', 'healthcheckUrl' => 'https://hc.example/ping/x', 'notify' => 'errors'];
    $hcLines = [];
    $hcLog = static function (string $l) use (&$hcLines): void { $hcLines[] = $l; };
    try {
        $privateRunner('ping')->invoke(null, $hcJob, 'start', $hcLog);
        $pingThrew = false;
    } catch (Throwable) {
        $pingThrew = true;
    }
    check('Healthcheck: /start-Fehler bricht den Lauf nicht ab', !$pingThrew && (bool)preg_grep('/^WARNUNG: Healthcheck \(start\)/', $hcLines));
    $hcState = ['result' => 'success', 'bytes' => 1, 'files' => 1, 'duration' => 1, 'error' => null];
    $notify = $privateRunner('notify');
    $notifyArgs = [$hcJob, &$hcState, $hcLog];
    $notify->invokeArgs(null, $notifyArgs);
    eq('Healthcheck: Erfolgs-Ping-Fehler lässt Erfolg bestehen', $hcState['result'], 'success');

    // Hooks: nicht root-eigene oder fremd beschreibbare Skripte werden verweigert
    $hookDir = str_starts_with(dirname(__DIR__, 2), '/mnt/') ? dirname(__DIR__, 2) : null;
    if ($hookDir !== null) {
        $hookFile = $hookDir . '/.zbg-hook-test-' . getmypid() . '.sh';
        file_put_contents($hookFile, "#!/bin/bash\necho hi\n");
        chmod($hookFile, 0666);
        try {
            $privateRunner('hook')->invoke(null, $hookFile, static function (string $l): void {}, static fn(): bool => false);
            check('Hook: beschreibbares Skript verweigert', false, 'keine Exception');
        } catch (ZBG\UserError $e) {
            check('Hook: beschreibbares Skript verweigert', str_contains($e->getMessage(), 'muss root gehören'), $e->getMessage());
        } finally {
            @unlink($hookFile);
        }
    }

    // Fortschrittszeilen gedrosselt, letzte bleibt erhalten
    $written = [];
    [$throttledLog, $throttledFlush] = $privateRunner('throttled')->invoke(null, static function (string $l) use (&$written): void { $written[] = $l; }, 3600.0);
    foreach (['    1,000  10%  1.00MB/s  0:01:00', '    2,000  20%  1.00MB/s  0:00:50', '    3,000  30%  1.00MB/s  0:00:40', 'Datei fertig', '    4,000  40%  1.00MB/s  0:00:30'] as $l) {
        $throttledLog($l);
    }
    $throttledFlush();
    eq('Log: Fortschritt gedrosselt, letzte Zeile vor Meldung und am Ende erhalten', $written, ['    1,000  10%  1.00MB/s  0:01:00', '    3,000  30%  1.00MB/s  0:00:40', 'Datei fertig', '    4,000  40%  1.00MB/s  0:00:30']);

    // Verlaufskürzung löscht Logs der herausgefallenen Läufe (historyLimit 3 aus run.php)
    $logDir = dirname(History::dir()) . '/logs';
    RunState::ensureDirectory($logDir);
    $logRuns = [];
    for ($i = 1; $i <= 4; $i++) {
        $runId = '20261014-02000' . $i . '-abcdef0' . $i;
        $logRuns[] = $runId;
        $path = RunState::logPath('exec-log', $runId);
        file_put_contents($path, "Zeile $i\n");
        History::append('exec-log', ['runId' => $runId, 'start' => $i, 'end' => $i, 'result' => 'success', 'log' => $path]);
    }
    eq('Log: Logs gekürzter Läufe gelöscht, übrige bleiben', array_map(static fn(string $r): bool => is_file(RunState::logPath('exec-log', $r)), $logRuns), [false, true, true, true]);

    // Runner::log liest den gespeicherten Pfad, auch wenn History::dir() inzwischen woanders liegt
    $altDir = getenv('ZBG_CONFIG_DIR') . '/alt/logs';
    RunState::ensureDirectory($altDir);
    $altRun = '20261014-030000-abcdef09';
    file_put_contents($altDir . '/exec-log-' . $altRun . '.log', "alt\n");
    RunState::write('exec-log', ['state' => 'success', 'pid' => 0, 'runId' => $altRun, 'log' => $altDir . '/exec-log-' . $altRun . '.log']);
    eq('Log: gespeicherter Log-Pfad wird verwendet', Runner::log('exec-log'), ['alt']);

    // check-age: nur bei Statuswechsel melden
    $execFake(static fn(array $cmd): ?array => null);
    Runner::checkAge();
    $notified = json_decode((string)file_get_contents(RunState::dir() . '/age-notified.json'), true);
    eq('check-age: gemeldeter Status gespeichert', $notified['fixture-b'] ?? null, 'stale:record-5');
    JobStore::setEnabled('fixture-b', false);
    Runner::checkAge();
    $notified = json_decode((string)file_get_contents(RunState::dir() . '/age-notified.json'), true);
    check('check-age: deaktivierter Job wird zurückgesetzt', !isset($notified['fixture-b']));

    // Sperren werden nicht an Kindprozesse vererbt (close-on-exec)
    Exec::fake(null);
    if (is_dir('/proc/self/fd')) {
        $childLock = RunState::lockJob('exec-lock', true);
        $fds = Exec::run(['sh', '-c', 'ls -l /proc/$$/fd'])['out'];
        check('RunState: Job-Sperre nicht an Kindprozesse vererbt', $fds !== '' && !str_contains($fds, 'exec-lock.lock'), $fds);
        fclose($childLock);
    }
} finally {
    Exec::fake(null);
    Docker::$settleSeconds = 40;
    RunState::setStopping(false);
}
