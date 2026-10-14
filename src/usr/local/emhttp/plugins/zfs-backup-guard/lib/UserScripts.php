<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Erkennt Backup-Skripte des User-Scripts-Plugins (nur lesend) und schlägt Jobs vor (PLAN 3.5).
 */
final class UserScripts
{
    public const DIR = '/boot/config/plugins/user.scripts';

    public static function scan(?string $base = null): array
    {
        $base ??= self::DIR;
        $schedule = json_decode((string)@file_get_contents($base . '/schedule.json'), true) ?: [];
        $found = [];
        foreach (glob($base . '/scripts/*/script') ?: [] as $file) {
            $src = (string)file_get_contents($file);
            $name = basename(dirname($file));
            $sched = $schedule[$file] ?? null;
            $freq = $sched['frequency'] ?? 'disabled';
            $cron = match ($freq) {
                'custom' => $sched['custom'] ?? null,
                'hourly' => '0 * * * *',
                'daily' => '0 0 * * *',
                'weekly' => '0 0 * * 0',
                'monthly' => '0 0 1 * *',
                default => null,
            };
            $proposals = self::parse($src, $cron);
            if (!$proposals) {
                continue;
            }
            $found[] = [
                'script' => $name,
                'path' => $file,
                'active' => $freq !== 'disabled',
                'schedule' => $cron,
                'kind' => $proposals[0]['_kind'],
                'proposals' => array_map(static function ($p) {
                    unset($p['_kind']);
                    return $p;
                }, $proposals),
            ];
        }
        usort($found, static fn($a, $b) => $b['active'] <=> $a['active'] ?: strcasecmp($a['script'], $b['script']));
        return $found;
    }

    public static function parse(string $src, ?string $cron): array
    {
        $cron = ($cron && Cron::valid($cron)) ? $cron : '0 2 * * *';
        $out = [];
        if (preg_match('~declare\s+-A\s+BACKUP_JOBS=\((.*?)\n\s*\)~s', $src, $m)) {
            preg_match_all('~\[\s*"?([^"\]]+)"?\s*\]\s*=\s*"([^"]+)"~', $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $ds, $target]) {
                $leaf = basename($ds);
                $out[] = self::proposal('rsync-mirror', $ds, $leaf, dirname($target), $cron, [
                    'oldTarget' => $target,
                ]);
            }
            return $out;
        }
        $v = static function (string $key) use ($src): ?string {
            return preg_match('~^\s*' . $key . '\s*=\s*"?([^"\n#]*?)"?\s*(?:#.*)?$~m', $src, $mm) ? trim($mm[1]) : null;
        };
        $pool = $v('source_pool');
        $ds = $v('source_dataset');
        if ($pool && $ds) {
            $p = self::proposal('spaceinvader', "$pool/$ds", basename($ds), $v('parent_destination_folder') ?: '/mnt/user/backup', $cron, [
                'oldReplication' => $v('replication'),
                'remote' => $v('remote_server') ?: null,
            ]);
            $p['snapshots']['keep'] = [
                'hourly' => 0,
                'daily' => (int)($v('snapshot_days') ?? 7),
                'weekly' => (int)($v('snapshot_weeks') ?? 4),
                'monthly' => (int)($v('snapshot_months') ?? 3),
                'yearly' => 0,
            ];
            $out[] = $p;
        }
        return $out;
    }

    private static function proposal(string $kind, string $ds, string $leaf, string $oldBase, string $cron, array $extra): array
    {
        // Wird das alte Ziel bereits weitergesichert (z. B. Zerobyte), bleibt der Pfad gleich und wird ein Spiegel:
        // Der nachgelagerte Plan muss nicht angepasst werden und liefert selbst die Versionierung.
        $old = $extra['oldTarget'] ?? null;
        $down = $old ? Downstream::forTarget($old) : [];
        if ($down) {
            return array_replace_recursive(Job::defaults(), [
                '_kind' => $kind,
                'id' => Job::slug($leaf),
                'name' => ucfirst($leaf) . ' → Array',
                'source' => ['dataset' => $ds, 'recursive' => true, 'exclude' => []],
                'target' => ['path' => $old, 'mode' => 'mirror'],
                'schedule' => $cron,
                'migration' => $extra + ['keepPath' => true, 'downstream' => $down[0]['tool'] . ' „' . $down[0]['name'] . '“ (' . $down[0]['cron'] . ')'],
            ]);
        }
        // Neuer Unterordner neben dem alten Spiegel, der alte bleibt bis zur manuellen Löschung liegen.
        $base = preg_replace('~/zfs-test$~', '/zfs', rtrim($oldBase, '/'));
        if ($base === rtrim($oldBase, '/')) {
            $base .= '/zbg';
        }
        return array_replace_recursive(Job::defaults(), [
            '_kind' => $kind,
            'id' => Job::slug($leaf),
            'name' => ucfirst($leaf) . ' → Array',
            'source' => ['dataset' => $ds, 'recursive' => true, 'exclude' => []],
            'target' => ['path' => $base . '/' . $leaf, 'mode' => 'versioned'],
            'schedule' => $cron,
            'migration' => $extra,
        ]);
    }
}
