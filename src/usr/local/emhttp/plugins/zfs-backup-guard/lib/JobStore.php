<?php
declare(strict_types=1);

namespace ZBG;

/** Atomic persistence; the stable configuration lock serializes read/modify/write transactions. */
final class JobStore
{
    private static $lock = null;
    private static ?string $lockedDir = null;

    public static function file(string $name): string
    {
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Ungültiger Konfigurationsdateiname');
        }
        return configDir() . '/' . $name;
    }

    /** Also used by Schedule so JSON and cron changes share one transaction boundary. */
    public static function synchronized(callable $operation): mixed
    {
        if (is_resource(self::$lock)) {
            if (self::$lockedDir !== configDir()) {
                throw new \RuntimeException('Konfigurationspfad während einer Transaktion geändert');
            }
            return $operation();
        }
        $dir = configDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Kann $dir nicht anlegen");
        }
        $lock = fopen(self::file('.config.lock'), 'c+be');
        if ($lock === false) {
            throw new \RuntimeException('Konfigurationssperre kann nicht geöffnet werden');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('Konfigurationssperre kann nicht gesetzt werden');
        }
        self::$lock = $lock;
        self::$lockedDir = $dir;
        try {
            return $operation();
        } finally {
            self::$lock = null;
            self::$lockedDir = null;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function readJson(string $name, array $default): array
    {
        $f = self::file($name);
        if (!file_exists($f)) {
            return $default;
        }
        $raw = file_get_contents($f);
        if ($raw === false) {
            throw new \RuntimeException("Kann $f nicht lesen");
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Ungültige JSON-Konfiguration: $f", 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException("Ungültige Konfiguration: $f");
        }
        return $data;
    }

    public static function writeJson(string $name, array $data): void
    {
        $raw = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        self::atomicWrite($name, $raw);
    }

    /** Unique same-directory temporary files prevent races and preserve the old file on failure. */
    public static function atomicWrite(string $name, string $data): void
    {
        self::synchronized(static function () use ($name, $data): void {
            $file = self::file($name);
            $tmp = self::file('.' . $name . '.' . bin2hex(random_bytes(12)) . '.tmp');
            $stream = fopen($tmp, 'x+b');
            if ($stream === false) {
                throw new \RuntimeException("Kann temporäre Datei für $file nicht anlegen");
            }
            try {
                if (!chmod($tmp, 0600)) {
                    throw new \RuntimeException("Kann Dateirechte für $tmp nicht setzen");
                }
                $length = strlen($data);
                for ($offset = 0; $offset < $length;) {
                    $written = fwrite($stream, substr($data, $offset));
                    if ($written === false || $written === 0) {
                        throw new \RuntimeException("Kann $tmp nicht vollständig schreiben");
                    }
                    $offset += $written;
                }
                if (!fflush($stream) || (function_exists('fsync') && !fsync($stream))) {
                    throw new \RuntimeException("Kann $tmp nicht dauerhaft speichern");
                }
                fclose($stream);
                $stream = null;
                if (!rename($tmp, $file)) {
                    throw new \RuntimeException("Kann $file nicht atomar ersetzen");
                }
                self::syncDirectory();
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (is_file($tmp) && !unlink($tmp)) {
                    throw new \RuntimeException("Kann temporäre Datei $tmp nicht entfernen");
                }
            }
        });
    }

    /** Persist directory entries as well as file contents before reporting a durable commit. */
    public static function syncDirectory(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('fsync')) {
            return;
        }
        $directory = fopen(configDir(), 'r');
        if ($directory === false) {
            throw new \RuntimeException('Konfigurationsverzeichnis kann nicht synchronisiert werden');
        }
        try {
            if (!fsync($directory)) {
                throw new \RuntimeException('Konfigurationsverzeichnis kann nicht dauerhaft gespeichert werden');
            }
        } finally {
            fclose($directory);
        }
    }

    /** @return list<array> */
    public static function all(): array
    {
        $jobs = self::readJson('jobs.json', ['jobs' => []])['jobs'] ?? null;
        if (!is_array($jobs)) {
            throw new \RuntimeException('Ungültige Job-Liste in jobs.json');
        }
        foreach ($jobs as &$job) {
            if (!is_array($job) || !isset($job['id']) || !is_string($job['id'])) {
                throw new \RuntimeException('Ungültiger Job in jobs.json');
            }
            $job = array_replace_recursive(Job::defaults(), $job);
        }
        unset($job);
        return array_values($jobs);
    }

    public static function get(string $id): array
    {
        foreach (self::all() as $job) {
            if ($job['id'] === $id) {
                return $job;
            }
        }
        throw new UserError("Job nicht gefunden: $id");
    }

    /** Optimistic concurrency token of a stored job (editor sends it back with jobs.save). */
    public static function revision(array $job): string
    {
        return substr(hash('sha256', json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** $originalId identifies the previous ID when editing a job; $revision (optional) rejects stale editor saves. */
    public static function save(array $input, ?string $originalId = null, ?string $revision = null): array
    {
        $job = Job::normalize($input);
        if ($originalId !== null && $originalId !== $job['id']) {
            throw new UserError('Die Job-ID kann nicht geändert werden; Snapshots und Ziel gehören zur ID. Neuen Job anlegen.');
        }
        return self::mutateJobs(array_values(array_unique(array_filter([$originalId, $job['id']], static fn($id) => $id !== null))),
            static function (array &$jobs) use ($job, $originalId, $revision): array {
                if ($revision !== null && $originalId !== null) {
                    $stored = array_values(array_filter($jobs, static fn($existing) => $existing['id'] === $originalId))[0] ?? null;
                    if ($stored === null) {
                        throw new UserError('Job wurde inzwischen gelöscht – Seite neu laden');
                    }
                    if (!hash_equals(self::revision($stored), $revision)) {
                        throw new UserError('Job wurde inzwischen geändert (anderer Tab oder CLI) – Seite neu laden und Änderungen erneut vornehmen');
                    }
                }
                foreach ($jobs as $existing) {
                    if ($existing['id'] === $job['id'] && $existing['id'] !== $originalId) {
                        throw new UserError("Ein Job mit der ID {$job['id']} existiert bereits");
                    }
                }
                $conflict = Paths::jobTargetConflict($job, $jobs);
                if ($conflict !== null) {
                    throw new UserError($conflict);
                }
                foreach ($jobs as $i => $existing) {
                    if ($originalId !== null && $existing['id'] === $originalId) {
                        $jobs[$i] = $job;
                        return $job;
                    }
                }
                $jobs[] = $job;
                return $job;
            });
    }

    /** Import is all-or-nothing and never activates schedules without explicit user action. */
    public static function saveBatch(array $inputs): array
    {
        $batch = [];
        foreach ($inputs as $input) {
            if (!is_array($input)) {
                throw new UserError('Ungültiger Import-Job');
            }
            $input['enabled'] = false;
            $job = Job::normalize($input);
            if (isset($batch[$job['id']])) {
                throw new UserError("Doppelte Job-ID im Import: {$job['id']}");
            }
            $batch[$job['id']] = $job;
        }
        if ($batch === []) {
            return [];
        }
        return self::mutateJobs(array_keys($batch), static function (array &$jobs) use ($batch): array {
            foreach ($jobs as $existing) {
                if (isset($batch[$existing['id']])) {
                    throw new UserError("Ein Job mit der ID {$existing['id']} existiert bereits");
                }
            }
            array_push($jobs, ...array_values($batch));
            return array_values($batch);
        });
    }

    public static function delete(string $id): void
    {
        self::mutateJobs([$id], static function (array &$jobs) use ($id): void {
            $jobs = array_values(array_filter($jobs, static fn($job) => $job['id'] !== $id));
        });
    }

    public static function setEnabled(string $id, bool $on): array
    {
        return self::mutateJobs([$id], static function (array &$jobs) use ($id, $on): array {
            foreach ($jobs as $i => $job) {
                if ($job['id'] === $id) {
                    $job['enabled'] = $on;
                    // Nur das Flag umschalten: Pausieren darf nicht an Systemprüfungen scheitern (Pool/Dataset weg),
                    // sonst feuert Cron weiter. Der gespeicherte Datensatz wurde beim Speichern bereits validiert.
                    $jobs[$i] = $job;
                    return $jobs[$i];
                }
            }
            throw new UserError("Job nicht gefunden: $id");
        });
    }

    private static function mutateJobs(array $ids, callable $mutation): mixed
    {
        return self::synchronized(static function () use ($ids, $mutation): mixed {
            $locks = [];
            sort($ids, SORT_STRING);
            try {
                foreach ($ids as $id) {
                    $locks[] = RunState::lockJob($id, true);
                    if (RunState::active($id)) {
                        throw new UserError("Laufender Job kann nicht geändert werden: $id");
                    }
                }
                $jobs = self::all();
                $result = $mutation($jobs);
                $file = self::file('jobs.json');
                $previous = is_file($file) ? file_get_contents($file) : null;
                if ($previous === false) {
                    throw new \RuntimeException('Vorherige Job-Konfiguration kann nicht gelesen werden');
                }
                try {
                    self::writeJson('jobs.json', ['version' => 1, 'jobs' => array_values($jobs)]);
                    Schedule::sync();
                } catch (\Throwable $failure) {
                    try {
                        if ($previous === null) {
                            if (file_exists($file) && !unlink($file)) {
                                throw new \RuntimeException('Neue Job-Konfiguration kann nicht zurückgenommen werden');
                            }
                            self::syncDirectory();
                        } else {
                            self::atomicWrite('jobs.json', $previous);
                        }
                        Schedule::sync();
                    } catch (\Throwable $rollback) {
                        throw new \RuntimeException('Job-Änderung fehlgeschlagen: ' . $failure->getMessage()
                            . '; Wiederherstellung fehlgeschlagen, Zeitplan prüfen: ' . $rollback->getMessage(), 0, $failure);
                    }
                    throw new \RuntimeException('Job-Änderung zurückgenommen: ' . $failure->getMessage(), 0, $failure);
                }
                return $result;
            } finally {
                foreach (array_reverse($locks) as $lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        });
    }

    public static function settings(): array
    {
        return array_replace([
            'logDir' => '/mnt/cache/system/zfs-backup-guard',
            'globalLock' => true,
            'historyLimit' => 500,
        ], self::readJson('settings.json', []));
    }

    public static function saveSettings(array $in): array
    {
        return self::synchronized(static function () use ($in): array {
            $previous = self::settings();
            $settings = $previous;
            if (isset($in['logDir'])) {
                $settings['logDir'] = Paths::clean((string)$in['logDir']);
                if (!str_starts_with($settings['logDir'], '/mnt/') && !str_starts_with($settings['logDir'], '/var/log/')) {
                    throw new UserError('Log-Verzeichnis muss unter /mnt oder /var/log liegen (nicht auf dem Flash)');
                }
            }
            if (isset($in['globalLock'])) {
                $settings['globalLock'] = (bool)$in['globalLock'];
            }
            if (isset($in['historyLimit'])) {
                $settings['historyLimit'] = max(50, min(5000, (int)$in['historyLimit']));
            }
            if ($settings['logDir'] === Paths::clean((string)$previous['logDir'])) {
                self::writeJson('settings.json', $settings);
                return $settings;
            }
            // Verlauf und Logs ziehen mit um; sonst zeigen alle Jobs „nie gelaufen“ und die Altersprüfung alarmiert.
            $locks = [];
            try {
                foreach (self::all() as $job) {
                    try {
                        $locks[] = RunState::lockJob($job['id'], true);
                    } catch (UserError) {
                        throw new UserError('Log-Verzeichnis kann nicht geändert werden, während ein Job läuft');
                    }
                    if (RunState::active($job['id'])) {
                        throw new UserError('Log-Verzeichnis kann nicht geändert werden, während ein Job läuft');
                    }
                }
                $from = dirname(History::dir());
                self::writeJson('settings.json', $settings);
                try {
                    $to = dirname(History::dir());
                    if ($to !== $settings['logDir']) {
                        throw new UserError("Log-Verzeichnis {$settings['logDir']} ist nicht erreichbar oder nicht beschreibbar (Pool/Array gestartet?)");
                    }
                    if ($from !== $to) {
                        self::moveLogData($from, $to);
                    }
                } catch (\Throwable $failure) {
                    self::writeJson('settings.json', $previous);
                    throw $failure;
                }
            } finally {
                foreach (array_reverse($locks) as $lock) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
            return $settings;
        });
    }

    /** Verschiebt history/ und logs/ ins neue Log-Verzeichnis; belegte Ziele werden nie zusammengeführt. */
    private static function moveLogData(string $from, string $to): void
    {
        $moves = [];
        foreach (['history', 'logs'] as $sub) {
            $src = "$from/$sub";
            $dst = "$to/$sub";
            if (!is_dir($src) || is_link($src) || self::emptyDir($src)) {
                continue;
            }
            if (Paths::within($src, $dst) || Paths::within($dst, $src)) {
                throw new UserError('Neues Log-Verzeichnis darf nicht im alten Verlaufs-/Log-Ordner liegen (oder umgekehrt)');
            }
            if (file_exists($dst) || is_link($dst)) {
                if (!is_dir($dst) || is_link($dst) || !self::emptyDir($dst)) {
                    throw new UserError("Im neuen Log-Verzeichnis existiert bereits $dst mit Inhalt – leeren oder anderes Verzeichnis wählen");
                }
            }
            $moves[] = [$src, $dst];
        }
        $done = [];
        try {
            foreach ($moves as [$src, $dst]) {
                self::moveTree($src, $dst);
                $done[] = [$src, $dst];
            }
        } catch (\Throwable $failure) {
            foreach (array_reverse($done) as [$src, $dst]) {
                try {
                    self::moveTree($dst, $src);
                } catch (\Throwable) {
                }
            }
            throw new \RuntimeException('Verlauf/Logs konnten nicht verschoben werden: ' . $failure->getMessage(), 0, $failure);
        }
    }

    private static function emptyDir(string $dir): bool
    {
        return (new \FilesystemIterator($dir))->valid() === false;
    }

    private static function moveTree(string $src, string $dst): void
    {
        RunState::ensureDirectory(dirname($dst));
        if (is_dir($dst) && !is_link($dst) && !rmdir($dst)) {
            throw new \RuntimeException("Leeres Ziel $dst kann nicht ersetzt werden");
        }
        if (@rename($src, $dst)) {
            return;
        }
        // Anderes Dateisystem: erst vollständig kopieren, dann Quelle entfernen.
        try {
            self::copyTree($src, $dst);
        } catch (\Throwable $failure) {
            self::removeTree($dst);
            throw $failure;
        }
        self::removeTree($src);
    }

    private static function copyTree(string $src, string $dst): void
    {
        if (!@mkdir($dst, 0700) && !is_dir($dst)) {
            throw new \RuntimeException("Kann $dst nicht anlegen");
        }
        foreach (new \FilesystemIterator($src, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $target = $dst . '/' . $entry->getFilename();
            if ($entry->isLink()) {
                continue;
            }
            if ($entry->isDir()) {
                self::copyTree($entry->getPathname(), $target);
            } elseif (!@copy($entry->getPathname(), $target)) {
                throw new \RuntimeException("Kann {$entry->getPathname()} nicht kopieren");
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            self::removeTree($entry->getPathname());
        }
        @rmdir($path);
    }
}
