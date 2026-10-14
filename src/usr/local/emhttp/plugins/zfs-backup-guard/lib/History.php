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
        $dir = is_dir(dirname($base)) ? $base : '/var/log/zfs-backup-guard';
        return $dir . '/history';
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
        $last = $runs[0] ?? null;
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
            $last !== null && ($last['result'] ?? '') === 'failed' => 'failed',
            $lastOk === null => 'never',
            $age > $job['maxAgeHours'] * 3600 => 'stale',
            default => 'ok',
        };
        return ['state' => $state, 'lastRun' => $last, 'lastSuccess' => $lastOk, 'ageSeconds' => $age];
    }
}
