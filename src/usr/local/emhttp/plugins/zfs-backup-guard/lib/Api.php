<?php
declare(strict_types=1);

namespace ZBG;

final class Api
{
    public static function dispatch(string $action, array $p): mixed
    {
        return match ($action) {
            'overview' => self::overview(),
            'datasets' => ['pools' => Zfs::pools(), 'datasets' => Zfs::datasets()],
            'snapshots' => Zfs::snapshots(self::optStr($p, 'dataset'), (bool)($p['recursive'] ?? false)),
            'jobs.save' => JobStore::save((array)($p['job'] ?? []), self::optStr($p, 'originalId')),
            'jobs.delete' => JobStore::delete(self::str($p, 'id')) ?? true,
            'jobs.toggle' => JobStore::setEnabled(self::str($p, 'id'), (bool)($p['enabled'] ?? false)),
            'target.check' => Paths::checkTarget(self::str($p, 'path'), self::optStr($p, 'source')),
            'containers' => Docker::containers(),
            'source.plan' => Zfs::plan(self::str($p, 'dataset'), (bool)($p['recursive'] ?? true), array_map('strval', (array)($p['exclude'] ?? []))),
            'downstream' => Downstream::plans(),
            'browse' => Browse::list(self::str($p, 'dataset'), self::str($p, 'snapshot'), (string)($p['path'] ?? '')),
            'browse.clone' => Browse::clone(self::str($p, 'dataset'), (string)($p['path'] ?? '')),
            'history' => ($id = self::optStr($p, 'job')) ? History::forJob($id, 500) : History::all(500),
            'migration.scan' => UserScripts::scan(),
            'migration.import' => self::importScript(self::str($p, 'script')),
            'settings.get' => JobStore::settings(),
            'settings.save' => JobStore::saveSettings((array)($p['settings'] ?? [])),
            'cron.next' => self::cronNext(self::str($p, 'expr')),
            'run.start' => Runner::start(self::str($p, 'id'), (bool)($p['dryRun'] ?? false), (bool)($p['verify'] ?? false)),
            'run.cancel' => Runner::cancel(self::str($p, 'id')),
            'run.status' => Runner::status(self::optStr($p, 'id')),
            'run.log', 'history.log' => Runner::log(self::str($p, 'id'), self::optStr($p, 'run')),
            'versions' => Versions::list(JobStore::get(self::str($p, 'id'))),
            'browse.version' => Browse::version(self::str($p, 'job'), self::str($p, 'version'), (string)($p['path'] ?? '')),
            'restore' => Restore::copy($p),
            'snapshots.clone' => Restore::clone(self::str($p, 'dataset'), self::str($p, 'snapshot')),
            'clones.list' => Restore::clones(),
            'clones.remove' => Restore::removeClone(self::str($p, 'dataset')) ?? true,
            'snapshots.rollback-command' => Restore::rollbackCommand(self::str($p, 'dataset'), self::str($p, 'snapshot')),
            default => throw new UserError("Unbekannte Aktion: $action"),
        };
    }

    private static function overview(): array
    {
        $jobs = [];
        $ownCount = [];
        foreach (Zfs::snapshots() as $s) {
            if ($s['own']) {
                $ownCount[$s['job']] = ($ownCount[$s['job']] ?? 0) + 1;
            }
        }
        $used = array_column(Zfs::datasets(), 'used', 'name');
        foreach (JobStore::all() as $job) {
            $runs = History::forJob($job['id'], 30);
            $jobs[] = [
                'job' => $job,
                'snapshotCount' => $ownCount[$job['id']] ?? 0,
                'sourceBytes' => $used[$job['source']['dataset']] ?? 0,
                'status' => History::status($job, $runs),
                'nextRun' => $job['enabled'] ? Cron::next($job['schedule']) : null,
                'downstream' => $job['method'] === 'rsync' && !$job['target']['ssh'] ? Downstream::forTarget($job['target']['path']) : [],
                'runs' => array_map(static fn($r) => [
                    'start' => $r['start'] ?? 0,
                    'duration' => $r['duration'] ?? 0,
                    'bytes' => $r['bytes'] ?? 0,
                    'result' => $r['result'] ?? 'unknown',
                ], array_reverse($runs)),
            ];
        }
        $migration = [];
        try {
            $migration = array_values(array_filter(UserScripts::scan(), static fn($s) => $s['active']));
        } catch (\Throwable) {
        }
        return [
            'version' => VERSION,
            'phase' => 6,
            'runnerAvailable' => true,
            'runs' => Runner::status(),
            'now' => time(),
            'jobs' => $jobs,
            'pools' => Zfs::pools(),
            'migration' => array_map(static fn($s) => ['script' => $s['script'], 'jobs' => count($s['proposals'])], $migration),
        ];
    }

    private static function importScript(string $name): array
    {
        foreach (UserScripts::scan() as $script) {
            if ($script['script'] !== $name) {
                continue;
            }
            $inputs = [];
            foreach ($script['proposals'] as $proposal) {
                // Existing User Scripts remain active until the user explicitly cuts over.
                $proposal['enabled'] = false;
                $proposal['target']['adoptExisting'] = $proposal['target']['mode'] === 'mirror'
                    && !empty($proposal['migration']['keepPath'])
                    && ($proposal['migration']['oldTarget'] ?? null) === $proposal['target']['path'];
                $inputs[] = $proposal;
            }
            return JobStore::saveBatch($inputs);
        }
        throw new UserError('Backup-Skript nicht gefunden');
    }

    private static function cronNext(string $expr): array
    {
        if (!Cron::valid($expr)) {
            throw new UserError('Ungültiger Cron-Ausdruck');
        }
        $out = [];
        $t = time();
        for ($i = 0; $i < 3 && ($t = Cron::next($expr, $t)) !== null; $i++) {
            $out[] = $t;
        }
        return $out;
    }

    private static function str(array $p, string $k): string
    {
        $v = $p[$k] ?? null;
        if (!is_string($v) || $v === '') {
            throw new UserError("Parameter $k fehlt");
        }
        return $v;
    }

    private static function optStr(array $p, string $k): ?string
    {
        $v = $p[$k] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
