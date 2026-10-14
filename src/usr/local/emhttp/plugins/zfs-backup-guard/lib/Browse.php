<?php
declare(strict_types=1);

namespace ZBG;

/** Read-only browsing with one canonical source boundary for snapshots and local versions. */
final class Browse
{
    public const MAX_ENTRIES = 2000;

    public static function list(string $dataset, string $snapshot, string $rel): array
    {
        return ['dataset' => $dataset, 'snapshot' => $snapshot]
            + self::directory(self::snapshotRoot($dataset, $snapshot), $rel);
    }

    public static function version(string $job, string $version, string $rel): array
    {
        $config = JobStore::get($job);
        $listing = !empty($config['target']['ssh'])
            ? self::remoteDirectory($config, self::remoteRoot($config, $version), $rel)
            : self::directory(Versions::root($config, $version), $rel, true);
        return ['job' => $job, 'version' => $version] + $listing;
    }

    /** Only a completed version listed for this job may be accessed on SSH. */
    public static function remoteRoot(array $job, string $version): string
    {
        if (!Versions::validName($version)) {
            throw new UserError('Ungültiger Versionsname');
        }
        $root = null;
        foreach (Versions::list($job) as $entry) {
            if ($entry['name'] === $version) {
                $root = $entry['path'];
                break;
            }
        }
        $expected = rtrim(Paths::normalizeTarget($job['target']['path']), '/') . '/' . $version;
        if ($root !== $expected) {
            throw new UserError('Abgeschlossene eigene Remote-Version nicht gefunden');
        }
        $script = 'set -eu; root=$1; [ -d "$root" ] && [ ! -L "$root" ]; '
            . 'canonical=$(realpath -e -- "$root" && printf .); canonical=${canonical%.}; canonical=${canonical%?}; '
            . '[ "$canonical" = "$root" ] || { echo "Remote-Version enthält Symlink-Eltern" >&2; exit 1; }; '
            . 'printf "%s\\000" "$canonical"';
        $out = Exec::ok(Remote::command($job['target']['ssh'], ['sh', '-c', $script, 'zbg', $root]));
        if ($out !== $root . "\0") {
            throw new UserError('Remote-Version konnte nicht kanonisch bestätigt werden');
        }
        return $root;
    }

    private static function remoteDirectory(array $job, string $root, string $rel): array
    {
        $rel = self::relativePath($rel);
        if ($rel === '.zfs' || str_starts_with($rel, '.zfs/')) {
            throw new UserError('ZFS-Steuerverzeichnisse können nicht durchsucht werden');
        }
        $script = <<<'SH'
set -eu
root=$1; rel=$2
dir=$(realpath -e -- "$root/$rel" && printf .)
dir=${dir%.}; dir=${dir%?}
case "$dir" in "$root"|"$root"/*) ;; *) echo "Pfad verlässt Remote-Version" >&2; exit 1;; esac
case "$dir" in "$root/.zfs"|"$root/.zfs/"*) exit 1;; esac
[ -d "$dir" ]
printf '%s\000' "$dir"
find "$dir" -mindepth 1 -maxdepth 1 -printf '%f\000%y\000%s\000%T@\000'
SH;
        $out = Exec::ok(Remote::command($job['target']['ssh'], ['sh', '-c', $script, 'zbg', $root, $rel]), 120);
        $fields = explode("\0", $out);
        $dir = array_shift($fields);
        if ($dir === null || !Paths::within($root, $dir) || array_pop($fields) !== '' || count($fields) % 4 !== 0) {
            throw new UserError('Ungültige Remote-Verzeichnisantwort');
        }
        $entries = [];
        $truncated = false;
        for ($i = 0, $n = count($fields); $i < $n; $i += 4) {
            $name = $fields[$i];
            if ($name === '.zfs' || ($dir === $root && $name === '.zbg-version.json')) {
                continue;
            }
            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $entries[] = ['name' => $name,
                'type' => $fields[$i + 1] === 'l' ? 'link' : ($fields[$i + 1] === 'd' ? 'dir' : 'file'),
                'size' => (int)$fields[$i + 2], 'mtime' => (int)(float)$fields[$i + 3]];
        }
        usort($entries, static fn($a, $b) => ($a['type'] !== 'dir') <=> ($b['type'] !== 'dir')
            ?: strnatcasecmp($a['name'], $b['name']));
        return ['path' => substr($dir, strlen($root)) ?: '/', 'entries' => $entries, 'truncated' => $truncated];
    }

    public static function clone(string $dataset, string $rel): array
    {
        return ['dataset' => $dataset] + self::directory(Restore::cloneRoot($dataset), $rel);
    }

    public static function snapshotRoot(string $dataset, string $snapshot): string
    {
        self::assertSnapshot($dataset, $snapshot);
        $root = realpath(Zfs::snapshotDir($dataset, $snapshot));
        if ($root === false || !is_dir($root)) {
            throw new UserError('Snapshot-Verzeichnis nicht lesbar');
        }
        return $root;
    }

    /** A directory with a matching name on disk alone is not proof of a ZFS snapshot. */
    public static function assertSnapshot(string $dataset, string $snapshot): void
    {
        Zfs::assertDataset($dataset);
        if ($snapshot === '' || str_contains($snapshot, '/') || str_contains($snapshot, "\0")
            || $snapshot === '.' || $snapshot === '..' || str_contains($snapshot, '@')) {
            throw new UserError('Ungültiger Snapshot-Name');
        }
        foreach (Zfs::snapshots($dataset) as $entry) {
            if ($entry['dataset'] === $dataset && $entry['snapshot'] === $snapshot) {
                return;
            }
        }
        throw new UserError("Snapshot nicht gefunden: $dataset@$snapshot");
    }

    public static function relativePath(string $rel): string
    {
        if (str_contains($rel, "\0") || str_contains($rel, '\\')) {
            throw new UserError('Ungültiger Pfad');
        }
        $parts = [];
        foreach (explode('/', $rel) as $part) {
            if ($part === '..') {
                throw new UserError('„..“ ist in Pfaden nicht erlaubt');
            }
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        return implode('/', $parts);
    }

    /** Keep a leaf symlink intact, but never traverse an escaping parent symlink. */
    public static function sourcePath(string $root, string $rel): string
    {
        $rel = self::relativePath($rel);
        if ($rel === '.zfs' || str_starts_with($rel, '.zfs/')) {
            throw new UserError('ZFS-Steuerverzeichnisse können nicht durchsucht werden');
        }
        if ($rel === '') {
            return $root;
        }
        $parent = realpath($root . '/' . dirname($rel));
        if ($parent === false || !Paths::within($root, $parent)) {
            throw new UserError('Pfad verlässt die Sicherung oder wurde nicht gefunden');
        }
        $path = $parent . '/' . basename($rel);
        if (@lstat($path) === false) {
            throw new UserError("Pfad nicht gefunden: /$rel");
        }
        return $path;
    }

    private static function directory(string $root, string $rel, bool $version = false): array
    {
        $root = realpath($root);
        if ($root === false) {
            throw new UserError('Sicherungsverzeichnis nicht lesbar');
        }
        $path = self::sourcePath($root, $rel);
        $dir = realpath($path);
        if ($dir === false || !Paths::within($root, $dir) || Paths::within($root . '/.zfs', $dir) || !is_dir($dir)) {
            throw new UserError('Pfad ist kein lesbares Verzeichnis innerhalb der Sicherung');
        }
        $h = @opendir($dir);
        if ($h === false) {
            throw new UserError('Verzeichnis kann nicht gelesen werden');
        }
        $entries = [];
        $truncated = false;
        try {
            while (($name = readdir($h)) !== false) {
                if ($name === '.' || $name === '..' || ($dir === $root && $name === '.zfs')
                    || ($version && $dir === $root && $name === '.zbg-version.json')) {
                    continue;
                }
                if (count($entries) >= self::MAX_ENTRIES) {
                    $truncated = true;
                    break;
                }
                $p = $dir . '/' . $name;
                $st = @lstat($p);
                if ($st === false) {
                    throw new UserError("Dateiinformationen nicht lesbar: $name");
                }
                $entries[] = [
                    'name' => $name,
                    'type' => is_link($p) ? 'link' : (is_dir($p) ? 'dir' : 'file'),
                    'size' => (int)$st['size'],
                    'mtime' => (int)$st['mtime'],
                ];
            }
        } finally {
            closedir($h);
        }
        usort($entries, static fn($a, $b) => ($a['type'] !== 'dir') <=> ($b['type'] !== 'dir')
            ?: strnatcasecmp($a['name'], $b['name']));
        return [
            'path' => substr($dir, strlen($root)) ?: '/',
            'entries' => $entries,
            'truncated' => $truncated,
        ];
    }
}
