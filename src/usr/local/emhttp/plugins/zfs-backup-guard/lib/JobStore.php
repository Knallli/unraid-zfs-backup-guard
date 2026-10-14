<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Persistenz von jobs.json und settings.json auf dem Flash (atomar per rename).
 */
final class JobStore
{
    public static function file(string $name): string
    {
        return configDir() . '/' . $name;
    }

    public static function readJson(string $name, array $default): array
    {
        $f = self::file($name);
        if (!is_file($f)) {
            return $default;
        }
        $data = json_decode((string)file_get_contents($f), true);
        return is_array($data) ? $data : $default;
    }

    public static function writeJson(string $name, array $data): void
    {
        $dir = configDir();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Kann $dir nicht anlegen");
        }
        $tmp = self::file($name . '.tmp');
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX);
        rename($tmp, self::file($name));
    }

    /** @return list<array> */
    public static function all(): array
    {
        $jobs = self::readJson('jobs.json', ['jobs' => []])['jobs'] ?? [];
        return array_values(array_map(static fn($j) => array_replace_recursive(Job::defaults(), $j), $jobs));
    }

    public static function get(string $id): array
    {
        foreach (self::all() as $j) {
            if ($j['id'] === $id) {
                return $j;
            }
        }
        throw new UserError("Job nicht gefunden: $id");
    }

    /** Speichert (neu oder Update). $originalId erlaubt Umbenennen der ID beim Bearbeiten. */
    public static function save(array $input, ?string $originalId = null): array
    {
        $job = Job::normalize($input);
        $jobs = self::all();
        $replaced = false;
        foreach ($jobs as $i => $j) {
            if ($j['id'] === $job['id'] && $j['id'] !== $originalId) {
                throw new UserError("Ein Job mit der ID {$job['id']} existiert bereits");
            }
        }
        foreach ($jobs as $i => $j) {
            if ($originalId !== null && $j['id'] === $originalId) {
                $jobs[$i] = $job;
                $replaced = true;
            }
        }
        if (!$replaced) {
            $jobs[] = $job;
        }
        self::writeJson('jobs.json', ['version' => 1, 'jobs' => array_values($jobs)]);
        return $job;
    }

    public static function delete(string $id): void
    {
        $jobs = array_values(array_filter(self::all(), static fn($j) => $j['id'] !== $id));
        self::writeJson('jobs.json', ['version' => 1, 'jobs' => $jobs]);
    }

    public static function setEnabled(string $id, bool $on): array
    {
        $job = self::get($id);
        $job['enabled'] = $on;
        return self::save($job, $id);
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
        $s = self::settings();
        if (isset($in['logDir'])) {
            $s['logDir'] = Paths::clean((string)$in['logDir']);
            if (!str_starts_with($s['logDir'], '/mnt/') && !str_starts_with($s['logDir'], '/var/log/')) {
                throw new UserError('Log-Verzeichnis muss unter /mnt oder /var/log liegen (nicht auf dem Flash)');
            }
        }
        if (isset($in['globalLock'])) {
            $s['globalLock'] = (bool)$in['globalLock'];
        }
        if (isset($in['historyLimit'])) {
            $s['historyLimit'] = max(50, min(5000, (int)$in['historyLimit']));
        }
        self::writeJson('settings.json', $s);
        return $s;
    }
}
