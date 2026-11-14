<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Laufverlauf als JSONL pro Job im Log-Verzeichnis (nicht auf dem Flash).
 */
final class History
{
    public static function dir(): string
    {
        $base = JobStore::settings()['logDir'];
        $probe = $base;
        while (!is_dir($probe) && $probe !== dirname($probe)) {
            $probe = dirname($probe);
        }
        $mounted = !str_starts_with($base, '/mnt/') || (Paths::mountFor($probe)['mountpoint'] ?? '/') !== '/';
        if (!is_writable($probe) || !$mounted) {
            $base = '/var/log/zfs-backup-guard';
        }
        return $base . '/history';
    }

    public static function append(string $id, array $record): void
    {
        RunState::assertId($id);
        RunState::ensureDirectory(self::dir());
        $file = self::dir() . '/' . $id . '.jsonl';
        $lock = @fopen($file . '.lock', 'ce');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Verlaufsperre fehlgeschlagen');
        }
        try {
            $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            // Recovery and finalization may both observe a completed worker.
            foreach ($lines as $line) {
                $previous = json_decode($line, true);
                if (!empty($record['runId']) && ($previous['runId'] ?? null) === $record['runId']) {
                    return;
                }
            }
            $lines[] = json_encode($record + ['job' => $id], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $limit = max(1, min(5000, (int)JobStore::settings()['historyLimit']));
            $tmp = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($tmp, implode("\n", array_slice($lines, -$limit)) . "\n") === false || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Verlauf kann nicht gespeichert werden');
            }
            foreach (array_slice($lines, 0, max(0, count($lines) - $limit)) as $line) {
                self::removeLog($id, json_decode($line, true));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Lauf-Log eines aus dem Verlauf gefallenen Laufs löschen (gespeicherter und aktueller Pfad). */
    private static function removeLog(string $id, mixed $record): void
    {
        $run = is_array($record) ? (string)($record['runId'] ?? '') : '';
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $run)) {
            return;
        }
        $name = $id . '-' . $run . '.log';
        $paths = [RunState::logPath($id, $run)];
        if (is_string($record['log'] ?? null) && basename($record['log']) === $name && basename(dirname($record['log'])) === 'logs') {
            $paths[] = $record['log'];
        }
        foreach (array_unique($paths) as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
    }

    /** @return list<array> neueste zuerst */
    public static function forJob(string $id, int $limit = 100): array
    {
        $f = self::dir() . '/' . $id . '.jsonl';
        if (!preg_match('~^[a-z0-9-]+$~', $id) || !is_file($f)) {
            return [];
        }
        $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$limit);
        $out = [];
        foreach (array_reverse($lines) as $l) {
            $r = json_decode($l, true);
            if (is_array($r)) {
                $out[] = $r + ['job' => $id];
            }
        }
        return $out;
    }

    public static function all(int $limit = 200): array
    {
        $all = [];
        foreach (JobStore::all() as $j) {
            array_push($all, ...self::forJob($j['id'], $limit));
        }
        usort($all, static fn($a, $b) => ($b['start'] ?? 0) <=> ($a['start'] ?? 0));
        return array_slice($all, 0, $limit);
    }

    /** Ampel nach PLAN 3.3: ok | stale | failed | never | disabled */
    public static function status(array $job, array $runs): array
    {
        $realRuns = array_values(array_filter($runs, static fn($r) => empty($r['dryRun'])));
        $last = $realRuns[0] ?? null;
        $lastOk = null;
        foreach ($runs as $r) {
            if (($r['result'] ?? '') === 'success' && empty($r['dryRun'])) {
                $lastOk = $r;
                break;
            }
        }
        $age = $lastOk ? time() - (int)$lastOk['end'] : null;
        $state = match (true) {
            !$job['enabled'] => 'disabled',
            $last !== null && in_array($last['result'] ?? '', ['failed', 'cancelled'], true) => 'failed',
            $lastOk === null => 'never',
            $age > $job['maxAgeHours'] * 3600 => 'stale',
            default => 'ok',
        };
        return ['state' => $state, 'lastRun' => $last, 'lastSuccess' => $lastOk, 'ageSeconds' => $age];
    }
}
