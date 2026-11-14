<?php
declare(strict_types=1);

namespace ZBG;

final class Versions
{
    /** Y-m-d_His (Ortszeit des Laufs), bei Kollision mit Suffix -2 … -99; Prüfung unabhängig von der Zeitzone. */
    public static function validName(string $name): bool
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}_\d{6})(?:-[2-9]|-[1-9]\d)?$/D', $name, $m)) {
            return false;
        }
        // UTC: eine Ortszeit in der Sommerzeit-Lücke (02:xx am Umstellungstag) bleibt gültig
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d_His', $m[1], new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d_His') === $m[1];
    }

    public static function list(array $job): array
    {
        if ($job['method'] !== 'rsync' || $job['target']['mode'] !== 'versioned') {
            return [];
        }
        if (!empty($job['target']['ssh'])) {
            return Rsync::versions($job);
        }
        $base = Paths::normalizeTarget($job['target']['path']);
        if (!is_dir($base) || is_link($base)) {
            return [];
        }
        $result = [];
        foreach (new \DirectoryIterator($base) as $entry) {
            $name = $entry->getFilename();
            if (!self::validName($name) || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $manifest = self::manifest($entry->getPathname(), $job['id'], $name);
            if ($manifest !== null) {
                $result[] = ['name' => $name, 'time' => $manifest['time'], 'path' => $entry->getPathname()];
            }
        }
        usort($result, static fn($a, $b) => $b['time'] <=> $a['time'] ?: strnatcmp($b['name'], $a['name']));
        return $result;
    }

    public static function manifest(string $path, string $job, string $name): ?array
    {
        $file = $path . '/.zbg-version.json';
        if (is_link($file) || !is_file($file)) {
            return null;
        }
        $value = json_decode((string)file_get_contents($file), true);
        return is_array($value) && ($value['job'] ?? null) === $job && ($value['name'] ?? null) === $name && !empty($value['complete']) && is_int($value['time'] ?? null) && $value['time'] > 0 ? $value : null;
    }

    public static function root(array $job, string $version): string
    {
        if (!empty($job['target']['ssh'])) {
            throw new UserError('Remote-Versionen sind nicht lokal durchsuchbar');
        }
        if (!self::validName($version)) {
            throw new UserError('Ungültiger Versionsname');
        }
        foreach (self::list($job) as $entry) {
            if ($entry['name'] === $version) {
                $root = realpath($entry['path']);
                $base = realpath($job['target']['path']);
                if ($root !== false && $base !== false && Paths::within($base, $root) && dirname($root) === $base) {
                    return $root;
                }
            }
        }
        throw new UserError('Abgeschlossene eigene Version nicht gefunden');
    }

    public static function complete(array $job, string $stage, string $name, int $time): string
    {
        $base = rtrim($job['target']['path'], '/');
        if (dirname($stage) !== $base || !str_starts_with(basename($stage), '.zbg-incomplete-') || is_link($stage) || !is_dir($stage) || !self::validName($name)) {
            throw new UserError('Ungültiger Versionsabschluss');
        }
        $final = $base . '/' . $name;
        // doppelte Ortszeit (Zeitumstellung) oder zwei Läufe in derselben Sekunde: Suffix statt Abbruch
        for ($n = 2; file_exists($final) || is_link($final); $n++) {
            if ($n > 99) {
                throw new UserError('Version existiert bereits');
            }
            $final = $base . '/' . $name . '-' . $n;
        }
        $name = basename($final);
        self::assertLatest($job);
        if (is_file($stage . '/.zbg-stage.json') && !is_link($stage . '/.zbg-stage.json')) {
            @unlink($stage . '/.zbg-stage.json');
        }
        RunState::atomic($stage . '/.zbg-version.json', ['job' => $job['id'], 'name' => $name, 'time' => $time, 'complete' => true]);
        if (!rename($stage, $final)) {
            throw new \RuntimeException('Version kann nicht atomar abgeschlossen werden');
        }
        $temporary = $base . '/.zbg-latest-' . bin2hex(random_bytes(8));
        if (!symlink($name, $temporary) || !rename($temporary, $base . '/latest')) {
            @unlink($temporary);
            throw new \RuntimeException('latest kann nicht atomar aktualisiert werden');
        }
        return $final;
    }

    public static function assertLatest(array $job): void
    {
        $latest = rtrim($job['target']['path'], '/') . '/latest';
        if (!file_exists($latest) && !is_link($latest)) {
            return;
        }
        $name = is_link($latest) ? readlink($latest) : false;
        if ($name === false || !self::validName($name) || self::manifest(dirname($latest) . '/' . $name, $job['id'], $name) === null) {
            throw new UserError('latest ist kein eigener Versionszeiger und wird nicht ersetzt');
        }
    }

    public static function prune(array $job, callable $log): void
    {
        $versions = self::list($job);
        $protect = [];
        $latest = rtrim($job['target']['path'], '/') . '/latest';
        if (is_link($latest)) {
            $protect[] = (string)readlink($latest);
        }
        $retention = Retention::plan($versions, $job['versions']['keep'], $protect);
        foreach ($retention['destroy'] as $name) {
            $root = self::root($job, $name);
            $log("Versions-Retention: $name");
            Exec::ok(['rm', '-rf', '--one-file-system', '--', $root], 0);
            if (is_dir($root)) {
                throw new \RuntimeException("Version konnte nicht vollständig gelöscht werden: $name");
            }
        }
    }
}
