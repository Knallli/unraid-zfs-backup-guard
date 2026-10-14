<?php
declare(strict_types=1);

namespace ZBG;

final class Runner
{
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
        $lock = RunState::lockJob($id, $inherited === '');
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
                    throw new UserError('Job läuft bereits');
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
            $log = static function (string $line) use (&$state, $id, $logHandle, &$lastWrite): void {
                if (fwrite($logHandle, '[' . date('c') . '] ' . $line . "\n") === false) {
                    throw new \RuntimeException('Lauf-Log kann nicht geschrieben werden');
                }
                fflush($logHandle);
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
            register_shutdown_function(static function () use (&$finished, &$state, $id, $journal): void {
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
                    RunState::write($id, $state);
                    History::append($id, $state);
                } catch (\Throwable $e) {
                    error_log('ZFS Backup Guard recovery: ' . $e->getMessage());
                }
            });
            try {
                if (!empty(JobStore::settings()['globalLock'])) {
                    $step('waiting-io-lock');
                    $ioLock = fopen(RunState::dir() . '/global-io.lock', 'c');
                    if ($ioLock === false) {
                        throw new \RuntimeException('Globale I/O-Sperre kann nicht geöffnet werden');
                    }
                    while (!flock($ioLock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                        if (!$wouldBlock) {
                            throw new \RuntimeException('Globale I/O-Sperre fehlgeschlagen');
                        }
                        $checkCancel();
                        usleep(200000);
                    }
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
                    $state['result'] = 'dryrun';
                } else {
                    Notify::healthcheck($job, 'start');
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
            if (!$dryRun) {
                try {
                    Notify::result($job, $state);
                } catch (\Throwable $e) {
                    $state['result'] = 'failed';
                    $state['error'] = trim(($state['error'] ?? '') . '; Benachrichtigung: ' . $e->getMessage(), '; ');
                    $log($e->getMessage());
                }
                try {
                    Notify::healthcheck($job, $state['result'] === 'success' ? 'success' : 'fail');
                } catch (\Throwable $e) {
                    $wasSuccess = $state['result'] === 'success';
                    $state['result'] = 'failed';
                    $state['error'] = trim(($state['error'] ?? '') . '; Healthcheck: ' . $e->getMessage(), '; ');
                    $log($e->getMessage());
                    if ($wasSuccess) {
                        try {
                            Notify::healthcheck($job, 'fail');
                        } catch (\Throwable $failure) {
                            $log('Healthcheck /fail: ' . $failure->getMessage());
                        }
                    }
                }
            }
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
        $log('Hook: ' . $real);
        $result = Exec::stream(['bash', $real], $log, $cancel);
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
        Docker::recover($state['containers'] ?? [], $log, static function (array $entries) use (&$state, $id): void {
            $state['containers'] = $entries;
            RunState::write($id, $state);
        });
        if ($unexpected) {
            $state['state'] = $state['result'] = 'failed';
            $state['step'] = 'finished';
            $state['error'] = 'Worker unerwartet beendet; Container wiederhergestellt';
            $state['end'] = time();
            $state['duration'] = $state['end'] - $state['start'];
            RunState::write($id, $state);
            History::append($id, $state);
        }
        RunState::clearCancel($id);
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
            try {
                $lock = RunState::lockJob($id, true);
            } catch (UserError $e) {
                return $state;
            }
            try {
                self::recoverStale($id, RunState::read($id));
                $state = RunState::read($id);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
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

    public static function cancelAll(): void
    {
        $states = self::status();
        foreach ($states as $id => $state) {
            if (($state['state'] ?? '') === 'running') {
                self::cancel($id);
            }
        }
        $deadline = microtime(true) + 180;
        do {
            $pending = [];
            foreach ($states as $id => $state) {
                $current = self::status($id);
                if (($current['state'] ?? '') === 'running' || !empty($current['containers'])) {
                    $pending[] = $id;
                }
            }
            if (!$pending) {
                return;
            }
            usleep(200000);
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Array-Stopp wartet auf Läufe/Container: ' . implode(', ', $pending));
    }

    public static function log(string $id, ?string $run = null): array
    {
        if ($run === null) {
            $run = self::status($id)['runId'] ?? null;
        }
        if ($run === null) {
            return [];
        }
        $path = RunState::logPath($id, $run);
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
        foreach (JobStore::all() as $job) {
            $status = History::status($job, History::forJob($job['id'], 5000));
            if ($job['enabled'] && in_array($status['state'], ['stale', 'never', 'failed'], true)) {
                try {
                    Notify::stale($job, $status);
                } catch (\Throwable $e) {
                    $status['notificationError'] = $e->getMessage();
                }
            }
            $result[$job['id']] = $status;
        }
        return $result;
    }
}
