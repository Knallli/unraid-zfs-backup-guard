<?php
declare(strict_types=1);

namespace ZBG;

final class Runner
{
    private const STOPPING = 'Array wird gestoppt: keine neuen Backup-Läufe bis zum nächsten Array-Start';
    /** Höchstwartezeit auf die globale I/O-Sperre bzw. Laufzeit eines Hooks. */
    public const IO_LOCK_TIMEOUT = 6 * 3600;
    public const HOOK_TIMEOUT = 3600;
    /** Fortschrittszeilen (rsync --info=progress2) höchstens alle 10 s ins Lauf-Log. */
    private const PROGRESS_INTERVAL = 10.0;

    private static function runId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    }

    private static function initial(string $id, string $run, bool $dryRun): array
    {
        return ['id' => $run, 'job' => $id, 'pid' => 0, 'runId' => $run, 'state' => 'running', 'result' => null, 'step' => 'starting', 'dataset' => null, 'start' => time(), 'end' => null, 'duration' => 0, 'bytes' => 0, 'files' => 0, 'progress' => [], 'error' => null, 'dryRun' => $dryRun, 'log' => RunState::logPath($id, $run), 'containers' => [], 'datasets' => [], 'verification' => ['checked' => false, 'mismatches' => 0], 'snapshot' => null];
    }

    public static function start(string $id, bool $dryRun = false, bool $verify = false): array
    {
        $lock = RunState::lockJob($id, true);
        try {
            $job = JobStore::get($id);
            if (RunState::active($id)) {
                throw new UserError('Job läuft bereits');
            }
            if (RunState::stopping()) {
                throw new UserError(self::STOPPING);
            }
            self::recoverStale($id, RunState::read($id));
            $run = self::runId();
            $state = self::initial($id, $run, $dryRun);
            RunState::clearCancel($id);
            RunState::write($id, $state);
            $cli = realpath(__DIR__ . '/../bin/zbg');
            if ($cli === false) {
                throw new \RuntimeException('Gebündelte CLI fehlt');
            }
            $php = PHP_SAPI === 'cli' ? PHP_BINARY : '/usr/bin/php';
            if (!is_executable($php)) {
                throw new \RuntimeException('PHP-CLI fehlt');
            }
            $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : '/bin/setsid';
            if (!is_executable($setsid)) {
                throw new \RuntimeException('setsid fehlt');
            }
            // Unraid's php.ini prepends web CSRF checks; inherited CGI request
            // variables must not turn this trusted CLI worker into another web request.
            $cmd = [$setsid, $php, '-d', 'auto_prepend_file=', $cli, 'worker', $id];
            if ($dryRun) {
                $cmd[] = '--dry-run';
            }
            if ($verify || !empty($job['verify'])) {
                $cmd[] = '--verify';
            }
            RunState::ensureDirectory(dirname($state['log']));
            $shell = 'ZBG_RUN_ID=' . escapeshellarg($run) . ' ' . implode(' ', array_map('escapeshellarg', $cmd)) . ' </dev/null >/dev/null 2>>' . escapeshellarg($state['log']) . ' & echo $!';
            $pid = (int)trim(Exec::ok(['sh', '-c', $shell]));
            if ($pid < 2) {
                throw new \RuntimeException('Hintergrundprozess konnte nicht gestartet werden');
            }
            $state['pid'] = $pid;
            $state['pidIdentity'] = RunState::pidIdentity($pid);
            RunState::write($id, $state);
            return $state;
        } catch (\Throwable $e) {
            if (isset($state) && ($state['state'] ?? '') === 'running') {
                $state['state'] = $state['result'] = 'failed';
                $state['error'] = $e->getMessage();
                $state['end'] = time();
                $state['duration'] = $state['end'] - $state['start'];
                RunState::write($id, $state);
                History::append($id, $state);
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function run(array $job, bool $dryRun = false, bool $verify = false, bool $scheduled = false): array
    {
        $id = (string)($job['id'] ?? '');
        $inherited = getenv('ZBG_RUN_ID') ?: '';
        try {
            $lock = RunState::lockJob($id, $inherited === '');
        } catch (UserError $e) {
            if ($scheduled && str_starts_with($e->getMessage(), 'Job läuft bereits')) {
                return self::skip($id, 'Job läuft bereits');
            }
            throw $e;
        }
        $ioLock = null;
        $finished = false;
        $cancelled = false;
        $state = [];
        $logHandle = null;
        $previousSignals = [];
        try {
            // A caller may have loaded the job before an edit acquired the same lock.
            $job = JobStore::get($id);
            if ($scheduled && !$job['enabled']) {
                return ['job' => $id, 'state' => 'skipped', 'result' => 'skipped', 'reason' => 'disabled'];
            }
            $old = RunState::read($id);
            if ($inherited !== '') {
                if (($old['runId'] ?? '') !== $inherited || ($old['state'] ?? '') !== 'running') {
                    throw new UserError('Laufreservierung ist nicht mehr gültig');
                }
                $state = $old;
            } else {
                if (RunState::active($id)) {
                    if ($scheduled) {
                        return self::skip($id, 'Job läuft bereits');
                    }
                    throw new UserError('Job läuft bereits');
                }
                if (RunState::stopping()) {
                    if ($scheduled) {
                        return self::skip($id, 'Array wird gestoppt');
                    }
                    throw new UserError(self::STOPPING);
                }
                self::recoverStale($id, $old);
                $state = self::initial($id, self::runId(), $dryRun);
                RunState::clearCancel($id);
            }
            $state['pid'] = getmypid();
            $state['pidIdentity'] = RunState::pidIdentity(getmypid());
            $state['dryRun'] = $dryRun;
            $state['verify'] = $verify || !empty($job['verify']);
            RunState::ensureDirectory(dirname($state['log']));
            $logHandle = fopen($state['log'], 'ab');
            if ($logHandle === false) {
                throw new \RuntimeException('Lauf-Log kann nicht geöffnet werden');
            }
            RunState::write($id, $state);
            $lastWrite = 0.0;
            [$write, $flushProgress] = self::throttled(static function (string $line) use ($logHandle): void {
                if (fwrite($logHandle, '[' . date('c') . '] ' . $line . "\n") === false) {
                    throw new \RuntimeException('Lauf-Log kann nicht geschrieben werden');
                }
                fflush($logHandle);
            }, self::PROGRESS_INTERVAL);
            $log = static function (string $line) use (&$state, $id, &$lastWrite, $write): void {
                $write($line);
                if (str_starts_with($line, 'Dataset: ')) {
                    $state['dataset'] = substr($line, 9);
                    $state['progress'] = [];
                }
                if (str_starts_with($line, 'ZBG-STATS:')) {
                    $stats = json_decode(substr($line, 10), true);
                    foreach (['bytes', 'files', 'datasets', 'verification'] as $field) {
                        if (is_array($stats) && array_key_exists($field, $stats)) {
                            $state[$field] = $stats[$field];
                        }
                    }
                }
                if (preg_match('/([\d,]+)\s+(\d+)%\s+(\S+)\s+(\S+)/', $line, $m)) {
                    $state['progress'] = ['percent' => (int)$m[2], 'rate' => $m[3], 'eta' => $m[4], 'line' => $line];
                }
                if (microtime(true) - $lastWrite > 0.4) {
                    $state['duration'] = time() - $state['start'];
                    RunState::write($id, $state);
                    $lastWrite = microtime(true);
                }
            };
            $step = static function (string $name) use (&$state, $id, $log): void {
                $state['step'] = $name;
                RunState::write($id, $state);
                $log('Schritt: ' . $name);
            };
            $journal = static function (array $entries) use (&$state, $id): void {
                $state['containers'] = $entries;
                RunState::write($id, $state);
            };
            Exec::observe(static function (?array $child) use (&$state, $id): void {
                $state['child'] = $child;
                RunState::write($id, $state);
            });
            $cancel = static function () use (&$cancelled, $id): bool {
                return $cancelled || RunState::cancelled($id);
            };
            $checkCancel = static function () use ($cancel): void {
                if ($cancel()) {
                    throw new \RuntimeException('Abgebrochen');
                }
            };
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
                foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                    $previousSignals[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function () use (&$cancelled): void { $cancelled = true; });
                }
            }
            register_shutdown_function(static function () use (&$finished, &$state, $id, $journal, $job): void {
                if ($finished || !$state || ($state['state'] ?? '') !== 'running') {
                    return;
                }
                try {
                    self::stopChild($state);
                    $recoveryLog = static function (string $line) use (&$state): void {
                        file_put_contents($state['log'], '[' . date('c') . '] Recovery: ' . $line . "\n", FILE_APPEND | LOCK_EX);
                    };
                    Docker::recover($state['containers'] ?? [], $recoveryLog, $journal);
                    $state['state'] = $state['result'] = 'failed';
                    $state['error'] = 'Worker unerwartet beendet: ' . (error_get_last()['message'] ?? 'Prozessende');
                    $state['end'] = time();
                    $state['duration'] = $state['end'] - $state['start'];
                    self::notify($job, $state, $recoveryLog);
                    RunState::write($id, $state);
                    History::append($id, $state);
                } catch (\Throwable $e) {
                    error_log('ZFS Backup Guard recovery: ' . $e->getMessage());
                }
            });
            try {
                if (!empty(JobStore::settings()['globalLock'])) {
                    $step('waiting-io-lock');
                    $ioPath = RunState::dir() . '/global-io.lock';
                    $ioLock = fopen($ioPath, 'ce');
                    if ($ioLock === false) {
                        throw new \RuntimeException('Globale I/O-Sperre kann nicht geöffnet werden');
                    }
                    $waitSince = time();
                    $waitLogged = false;
                    while (!flock($ioLock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                        if (!$wouldBlock) {
                            throw new \RuntimeException('Globale I/O-Sperre fehlgeschlagen');
                        }
                        if (!$waitLogged) {
                            $holder = trim((string)@file_get_contents($ioPath));
                            $log('Warte auf globale I/O-Sperre' . ($holder !== '' ? ' (gehalten von Job ' . $holder . ')' : '') . ', höchstens ' . intdiv(self::IO_LOCK_TIMEOUT, 3600) . ' h');
                            $waitLogged = true;
                        }
                        if (time() - $waitSince > self::IO_LOCK_TIMEOUT) {
                            throw new \RuntimeException('Globale I/O-Sperre nach ' . intdiv(self::IO_LOCK_TIMEOUT, 3600) . ' h nicht erhalten; anderer Job oder hängender Prozess hält sie: ' . (trim((string)@file_get_contents($ioPath)) ?: 'unbekannt'));
                        }
                        $checkCancel();
                        usleep(200000);
                    }
                    ftruncate($ioLock, 0);
                    fwrite($ioLock, $id . "\n");
                    fflush($ioLock);
                }
                $step('preflight');
                $checkCancel();
                Zfs::refresh();
                $plan = Zfs::plan($job['source']['dataset'], $job['source']['recursive'], $job['source']['exclude']);
                foreach ($plan['items'] as $item) {
                    $ds = Zfs::dataset($item['name']);
                    $pool = Zfs::pool($ds['pool']);
                    if ($pool === null || $pool['health'] !== 'ONLINE') {
                        throw new UserError('Quell-Pool ist nicht ONLINE: ' . $ds['pool']);
                    }
                    foreach ($ds['issues'] as $issue) {
                        if ($issue['level'] === 'block') {
                            throw new UserError($issue['msg']);
                        }
                    }
                    if ($job['method'] === 'rsync' && $item['action'] !== 'copy') {
                        $log('WARNUNG: nur Snapshot, keine Dateikopie: ' . $item['name']);
                    }
                }
                foreach ($plan['warnings'] as $warning) {
                    $log(strtoupper($warning['level']) . ': ' . $warning['msg']);
                }
                $state['plan'] = $plan;
                $state['preflight'] = $job['method'] === 'rsync' ? Rsync::preflight($job, $plan, $cancel) : Send::preflight($job, $plan);
                $checkCancel();
                if ($dryRun) {
                    foreach ($plan['items'] as $item) {
                        $log('Probelauf: atomarer Snapshot ' . $item['name'] . '; ' . ($job['method'] === 'zfs' ? 'zfs send' : $item['action']));
                    }
                    if (!empty($job['system'])) {
                        $log('Systemdisk: würde Partitionen, EFI-Dateien und LUKS-Header sichern');
                    }
                    $state['result'] = 'dryrun';
                } else {
                    self::ping($job, 'start', $log);
                    self::hook($job['hooks']['pre'], $log, $cancel);
                    $checkCancel();
                    $step('consistency');
                    try {
                        Docker::quiesce($job['consistency']['mode'], $job['consistency']['containers'], $journal, $log, $cancel);
                        $checkCancel();
                        $step('snapshot');
                        $snapshot = Zfs::SNAP_PREFIX . $id . '_' . date('Ymd-His');
                        Zfs::snapshotSelected($job, $plan, $snapshot);
                        $state['snapshot'] = $snapshot;
                        RunState::write($id, $state);
                    } finally {
                        Docker::recover($state['containers'], $log, $journal);
                    }
                    $checkCancel();
                    $step('transfer');
                    $transfer = $job['method'] === 'rsync' ? Rsync::transfer($job, $plan, $snapshot, $log, $cancel, $state['verify']) : Send::transfer($job, $plan, $snapshot, $log, $cancel);
                    foreach (['bytes', 'files', 'datasets', 'verification', 'version'] as $field) {
                        if (array_key_exists($field, $transfer)) {
                            $state[$field] = $transfer[$field];
                        }
                    }
                    $checkCancel();
                    self::hook($job['hooks']['post'], $log, $cancel);
                    $checkCancel();
                    $step('retention');
                    Zfs::prune($job, $plan, $transfer['protectedSnapshots'] ?? [], $log, $cancel);
                    $checkCancel();
                    if ($job['method'] === 'rsync') {
                        Rsync::prune($job, $log);
                    } else {
                        Send::prune($job, $plan, $log);
                    }
                    $checkCancel();
                    $state['result'] = 'success';
                }
            } catch (\Throwable $e) {
                if ($e instanceof VerificationFailure) {
                    foreach (['bytes', 'files', 'datasets', 'verification'] as $field) {
                        $state[$field] = $e->result[$field];
                    }
                }
                $state['result'] = $cancel() ? 'cancelled' : 'failed';
                $state['error'] = $e->getMessage();
                $log('FEHLER: ' . $e->getMessage());
            } finally {
                if (!empty($state['containers'])) {
                    try {
                        Docker::recover($state['containers'], $log, $journal);
                    } catch (\Throwable $e) {
                        $state['result'] = 'failed';
                        $state['error'] = trim(($state['error'] ?? '') . '; ' . $e->getMessage(), '; ');
                        $log($e->getMessage());
                    }
                }
            }
            $state['step'] = 'finished';
            $state['end'] = time();
            $state['duration'] = $state['end'] - $state['start'];
            $flushProgress();
            self::notify($job, $state, $log);
            $state['state'] = $state['result'] === 'dryrun' ? 'success' : $state['result'];
            RunState::write($id, $state);
            History::append($id, $state);
            $finished = true;
            RunState::clearCancel($id);
            return $state;
        } catch (\Throwable $e) {
            if ($state && (int)($state['pid'] ?? 0) === getmypid()) {
                $recoveryLog = static function (string $line) use (&$state): void {
                    file_put_contents($state['log'], '[' . date('c') . '] Recovery: ' . $line . "\n", FILE_APPEND | LOCK_EX);
                };
                try {
                    Docker::recover($state['containers'] ?? [], $recoveryLog, static function (array $entries) use (&$state, $id): void {
                        $state['containers'] = $entries;
                        RunState::write($id, $state);
                    });
                } catch (\Throwable $recovery) {
                    $state['error'] = $recovery->getMessage();
                }
                $state['state'] = $state['result'] = 'failed';
                $state['step'] = 'finished';
                $state['error'] = trim($e->getMessage() . '; ' . ($state['error'] ?? ''), '; ');
                $state['end'] = time();
                $state['duration'] = $state['end'] - $state['start'];
                self::notify($job, $state, $recoveryLog);
                $state['state'] = $state['result'];
                RunState::write($id, $state);
                History::append($id, $state);
                $finished = true;
                return $state;
            }
            throw $e;
        } finally {
            // Shutdown recovery still sees unfinished state if an error escapes finalization.
            Exec::observe(null);
            foreach ($previousSignals as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if (is_resource($logHandle)) {
                fclose($logHandle);
            }
            if (is_resource($ioLock)) {
                flock($ioLock, LOCK_UN);
                fclose($ioLock);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function hook(?string $path, callable $log, callable $cancel): void
    {
        if ($path === null) {
            return;
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new UserError('Hook ist nicht lesbar: ' . $path);
        }
        Paths::normalizeScript($real);
        // Hooks laufen als root: ein über SMB beschreibbares Skript wäre sonst eine Rechteausweitung.
        $stat = @stat($real);
        if ($stat === false || $stat['uid'] !== 0 || ($stat['mode'] & 0022) !== 0) {
            throw new UserError('Hook wird nicht ausgeführt: ' . $real . ' muss root gehören und darf nicht für Gruppe/andere beschreibbar sein (chown root:root, chmod 755)');
        }
        $log('Hook: ' . $real);
        $result = Exec::stream(['bash', $real], $log, $cancel, self::HOOK_TIMEOUT);
        if ($result['code'] !== 0) {
            throw new \RuntimeException('Hook fehlgeschlagen: ' . ($result['err'] ?: $result['out']));
        }
    }

    private static function stopChild(array &$state): void
    {
        $child = $state['child'] ?? null;
        if ($child !== null && RunState::pidAlive((int)$child['pid']) && !empty($child['identity']) && RunState::pidIdentity((int)$child['pid']) === $child['identity']) {
            Exec::terminateGroup((int)$child['pid']);
            usleep(300000);
            Exec::terminateGroup((int)$child['pid'], 9);
        }
        $state['child'] = null;
    }

    private static function recoverStale(string $id, array $state): void
    {
        if (!$state || RunState::active($id)) {
            return;
        }
        $unexpected = ($state['state'] ?? '') === 'running';
        if (!$unexpected && empty($state['containers'])) {
            return;
        }
        self::stopChild($state);
        $log = static function (string $line) use ($state): void {
            if (!empty($state['log'])) {
                file_put_contents($state['log'], '[' . date('c') . '] Recovery: ' . $line . "\n", FILE_APPEND | LOCK_EX);
            }
        };
        $failure = null;
        try {
            Docker::recover($state['containers'] ?? [], $log, static function (array $entries) use (&$state, $id): void {
                $state['containers'] = $entries;
                RunState::write($id, $state);
            });
        } catch (\Throwable $e) {
            $failure = $e;
            $log('WARNUNG: ' . $e->getMessage());
        }
        // Auch bei fehlgeschlagener Wiederherstellung abschließen, sonst bliebe der Lauf für immer "running".
        if ($unexpected) {
            $state['state'] = $state['result'] = 'failed';
            $state['step'] = 'finished';
            $state['error'] = 'Worker unerwartet beendet; ' . ($failure === null ? 'Container wiederhergestellt' : $failure->getMessage());
            $state['end'] = time();
            $state['duration'] = $state['end'] - (int)($state['start'] ?? $state['end']);
            try {
                self::notify(JobStore::get($id), $state, $log);
            } catch (\Throwable $e) {
                $log('WARNUNG: Benachrichtigung nicht möglich: ' . $e->getMessage());
            }
            $state['state'] = $state['result'];
            RunState::write($id, $state);
            History::append($id, $state);
        }
        RunState::clearCancel($id);
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * \r-Fortschrittsupdates (rsync --info=progress2) kämen sonst als eigene Zeilen ins Lauf-Log:
     * höchstens eine pro Intervall; die zuletzt zurückgehaltene wird vor der nächsten normalen Zeile bzw. beim Flush geschrieben.
     * @return array{0:callable(string):void,1:callable():void}
     */
    private static function throttled(callable $write, float $interval): array
    {
        $last = 0.0;
        $held = null;
        $flush = static function () use (&$held, $write): void {
            if ($held !== null) {
                $line = $held;
                $held = null;
                $write($line);
            }
        };
        $filtered = static function (string $line) use (&$last, &$held, $write, $flush, $interval): void {
            $progress = (bool)preg_match('/^\s*[\d,]+\s+\d+%\s/', $line);
            if ($progress && microtime(true) - $last < $interval) {
                $held = $line;
                return;
            }
            if ($progress) {
                $held = null;
                $last = microtime(true);
            } else {
                $flush();
            }
            $write($line);
        };
        return [$filtered, $flush];
    }

    /** Ergebnis-Benachrichtigung und Healthcheck-Ping für einen abgeschlossenen echten Lauf (ein Weg für alle Abschlüsse). */
    private static function notify(array $job, array &$state, callable $log): void
    {
        if (!empty($state['dryRun'])) {
            return;
        }
        try {
            Notify::result($job, $state);
        } catch (\Throwable $e) {
            $state['result'] = 'failed';
            $state['error'] = trim(($state['error'] ?? '') . '; Benachrichtigung: ' . $e->getMessage(), '; ');
            $log('FEHLER: Benachrichtigung: ' . $e->getMessage());
        }
        self::ping($job, ($state['result'] ?? '') === 'success' ? 'success' : 'fail', $log);
    }

    /** Healthcheck-Fehler sind nur Warnungen: ein nicht erreichbarer Dienst darf kein Backup verhindern oder entwerten. */
    private static function ping(array $job, string $event, callable $log): void
    {
        try {
            Notify::healthcheck($job, $event);
        } catch (\Throwable $e) {
            try {
                $log('WARNUNG: Healthcheck (' . $event . ') fehlgeschlagen: ' . $e->getMessage());
            } catch (\Throwable) {
            }
        }
    }

    /** Geplanter Lauf übersprungen: cron verwirft die Ausgabe, daher ins laufende Log und ins Syslog. */
    private static function skip(string $id, string $reason): array
    {
        $message = 'Geplanter Lauf übersprungen: ' . $reason;
        try {
            $current = RunState::read($id);
            if (($current['state'] ?? '') === 'running' && !empty($current['log']) && is_file($current['log'])) {
                @file_put_contents($current['log'], '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
            }
            Exec::run(['logger', '-t', 'zfs-backup-guard', "Job $id: $message"], 10);
        } catch (\Throwable) {
        }
        return ['job' => $id, 'state' => 'skipped', 'result' => 'skipped', 'reason' => $reason];
    }

    public static function status(?string $id = null): array
    {
        if ($id === null) {
            $states = [];
            foreach (glob(RunState::dir() . '/*.json') ?: [] as $file) {
                $job = basename($file, '.json');
                if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $job)) {
                    $states[$job] = self::status($job);
                }
            }
            return $states;
        }
        $state = RunState::read($id);
        if ($state && !RunState::active($id) && (($state['state'] ?? '') === 'running' || !empty($state['containers']))) {
            // Wiederherstellungsfehler dürfen die Statusabfrage (Übersicht, Array-Stopp) nie abbrechen.
            try {
                $lock = RunState::lockJob($id, true);
            } catch (\Throwable $e) {
                return $state;
            }
            try {
                self::recoverStale($id, RunState::read($id));
            } catch (\Throwable $e) {
                $recoveryError = $e->getMessage();
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $state = RunState::read($id);
            if (isset($recoveryError)) {
                $state['recoveryError'] = $recoveryError;
            }
        }
        if (($state['state'] ?? '') === 'running') {
            $state['duration'] = time() - $state['start'];
        }
        return $state;
    }

    public static function cancel(string $id): array
    {
        $state = self::status($id);
        if (($state['state'] ?? '') !== 'running') {
            return $state;
        }
        RunState::requestCancel($id);
        if (RunState::active($id) && function_exists('posix_kill') && (int)($state['pid'] ?? 0) > 1) {
            @posix_kill((int)$state['pid'], SIGTERM);
        }
        return self::status($id);
    }

    /**
     * Array-Stopp: neue Läufe sperren, laufende abbrechen und auf Container-Wiederherstellung warten.
     * Wirft nie (Ereignis-Skript und Plugin-Entfernung laufen mit set -e); liefert Warnungen.
     * @return list<string>
     */
    public static function cancelAll(int $timeout = 180, int $containerGrace = 30): array
    {
        $warnings = [];
        try {
            RunState::setStopping(true);
        } catch (\Throwable $e) {
            $warnings[] = $e->getMessage();
        }
        $states = self::status();
        foreach ($states as $id => $state) {
            if (($state['state'] ?? '') === 'running') {
                try {
                    self::cancel($id);
                } catch (\Throwable $e) {
                    $warnings[] = "$id: " . $e->getMessage();
                }
            }
        }
        $deadline = microtime(true) + $timeout;
        $idleSince = null;
        while (true) {
            $running = $containers = [];
            foreach ($states as $id => $state) {
                $current = self::status($id);
                if (($current['state'] ?? '') === 'running') {
                    $running[] = $id;
                } elseif (!empty($current['containers'])) {
                    $containers[$id] = $current['recoveryError'] ?? 'Container nicht wiederhergestellt';
                }
            }
            if (!$running && !$containers) {
                return $warnings;
            }
            $idleSince = $running ? null : ($idleSince ?? microtime(true));
            // Nur noch nicht wiederherstellbare Container: nicht den ganzen Array-Stopp aufhalten.
            if (microtime(true) >= $deadline || ($idleSince !== null && microtime(true) - $idleSince >= $containerGrace)) {
                foreach ($running as $id) {
                    $warnings[] = "$id: Lauf nach {$timeout}s noch nicht beendet";
                }
                foreach ($containers as $id => $error) {
                    $warnings[] = "$id: $error";
                }
                return $warnings;
            }
            usleep(200000);
        }
    }

    /** Ereignis "started": Sperre für neue Läufe aus dem Array-Stopp aufheben. */
    public static function arrayStarted(): void
    {
        RunState::setStopping(false);
    }

    public static function log(string $id, ?string $run = null): array
    {
        $state = self::status($id);
        $run ??= $state['runId'] ?? null;
        if ($run === null) {
            return [];
        }
        $path = RunState::logPath($id, $run);
        // Gespeicherten Pfad bevorzugen: History::dir() kann inzwischen auf /var/log ausgewichen sein (oder zurück).
        $stored = ($state['runId'] ?? null) === $run ? ($state['log'] ?? null) : null;
        if ($stored === null) {
            foreach (History::forJob($id, 5000) as $record) {
                if (($record['runId'] ?? null) === $run) {
                    $stored = $record['log'] ?? null;
                    break;
                }
            }
        }
        if (is_string($stored) && basename($stored) === basename($path) && basename(dirname($stored)) === 'logs' && is_file($stored)) {
            $path = $stored;
        }
        if (!is_file($path)) {
            return [];
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Log kann nicht gelesen werden');
        }
        try {
            $size = filesize($path);
            if ($size > 1048576) {
                fseek($file, -1048576, SEEK_END);
                fgets($file);
            }
            return preg_split('/\r?\n/', rtrim((string)stream_get_contents($file), "\r\n")) ?: [];
        } finally {
            fclose($file);
        }
    }

    public static function checkAge(): array
    {
        $result = [];
        $file = RunState::dir() . '/age-notified.json';
        $previous = json_decode((string)@file_get_contents($file), true) ?: [];
        $current = [];
        foreach (JobStore::all() as $job) {
            $status = History::status($job, History::forJob($job['id'], 5000));
            // auch fehlgeschlagene Jobs warnen, sobald der letzte Erfolg zu alt ist; "never" ohne Erfolg nicht (neue Jobs)
            $tooOld = $status['ageSeconds'] !== null && $status['ageSeconds'] > $job['maxAgeHours'] * 3600;
            $alert = !$job['enabled'] ? null : ($status['state'] === 'stale' ? 'stale' : ($status['state'] === 'failed' && $tooOld ? 'failed-old' : null));
            // Nur beim Wechsel melden, nicht stündlich erneut; ein Fehlschlag selbst meldet bereits Notify::result.
            $key = $alert === null ? null : $alert . ':' . ($status['lastSuccess']['runId'] ?? '');
            if ($key !== null && ($previous[$job['id']] ?? null) !== $key) {
                try {
                    Notify::stale($job, $status);
                } catch (\Throwable $e) {
                    $status['notificationError'] = $e->getMessage();
                    $key = $previous[$job['id']] ?? null;
                }
            }
            if ($key !== null) {
                $current[$job['id']] = $key;
            }
            $result[$job['id']] = $status;
        }
        if ($current !== $previous) {
            try {
                RunState::ensureDirectory(RunState::dir());
                RunState::atomic($file, $current);
            } catch (\Throwable) {
            }
        }
        return $result;
    }
}
