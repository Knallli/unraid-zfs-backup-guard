<?php
declare(strict_types=1);

namespace ZBG;

/** Non-destructive copies and explicitly owned, read-only temporary snapshot clones. */
final class Restore
{
    private const CLONE_PROP = 'zbg:restore';
    private const ORIGIN_PROP = 'zbg:restore-origin';
    private const CREATED_PROP = 'zbg:restore-created';
    private const CLONE_RE = '~^([A-Za-z0-9][A-Za-z0-9_.:-]*)/zbg-restore-(\d{8}-\d{6})-([a-f0-9]{16})$~';

    public static function copy(array $params): array
    {
        @set_time_limit(0);
        $version = isset($params['version']) && $params['version'] !== '';
        $job = null;
        if ($version) {
            $job = JobStore::get(self::parameter($params, 'job'));
            if (!empty($job['target']['ssh'])) {
                return self::copyRemote($params, $job);
            }
            $root = Versions::root($job, self::parameter($params, 'version'));
        } else {
            $root = Browse::snapshotRoot(self::parameter($params, 'dataset'), self::parameter($params, 'snapshot'));
        }
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new UserError('Sicherungsverzeichnis nicht lesbar');
        }
        $paths = $params['paths'] ?? null;
        if (!is_array($paths) || $paths === []) {
            throw new UserError('Mindestens einen wiederherzustellenden Pfad auswählen');
        }
        $selected = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new UserError('Ungültiger Wiederherstellungspfad');
            }
            $rel = Browse::relativePath($path);
            if ($rel === '.zfs' || str_starts_with($rel, '.zfs/')) {
                throw new UserError('ZFS-Steuerverzeichnisse sind keine wiederherstellbaren Dateien');
            }
            if ($version && ($rel === '.zbg-version.json' || str_starts_with($rel, '.zbg-version.json/'))) {
                throw new UserError('Versionsmetadaten sind keine wiederherstellbaren Dateien');
            }
            $source = Browse::sourcePath($root, $rel);
            // Copying through a parent alias would change the geometry of relative links.
            if ($rel !== '' && dirname($source) !== $root . (dirname($rel) === '.' ? '' : '/' . dirname($rel))) {
                throw new UserError('Wiederherstellung durch einen Verzeichnis-Symlink ist nicht erlaubt');
            }
            $selected[$rel] = $source;
        }
        uksort($selected, static fn($a, $b) => strlen((string)$a) <=> strlen((string)$b));
        $minimal = [];
        foreach ($selected as $rel => $source) {
            $rel = (string)$rel;
            $covered = false;
            foreach ($minimal as $parent => $_) {
                if ((string)$parent === '' || str_starts_with($rel, (string)$parent . '/')) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                $minimal[$rel] = $source;
            }
        }
        $base = self::commonParent(array_map('strval', array_keys($minimal)));
        $custom = $params['target'] ?? null;
        if ($custom !== null && (!is_string($custom) || $custom === '')) {
            throw new UserError('Ungültiger Zielpfad');
        }
        if ($custom === null) {
            $ds = Zfs::dataset($version ? $job['source']['dataset'] : self::parameter($params, 'dataset'));
            if (empty($ds['path'])) {
                throw new UserError('Original nicht gemountet – bitte einen neuen Zielpfad unter /mnt angeben');
            }
            $original = rtrim((string)$ds['path'], '/') . ($base === '' ? '' : '/' . $base);
            $custom = $original . '/restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        }
        $target = self::newTarget($custom);
        if (Paths::within($root, $target)) {
            throw new UserError('Wiederherstellungsziel liegt innerhalb der Sicherung');
        }
        $targets = [];
        $count = 0;
        foreach ($minimal as $rel => $source) {
            $rel = (string)$rel;
            $suffix = $base === '' ? $rel : substr($rel, strlen($base) + 1);
            $destination = $target . ($suffix === '' ? '' : '/' . $suffix);
            self::inspect($root, $source, $destination, $target, $count, $version);
            $targets[] = $destination;
        }
        // All sources, links and target ancestors are checked before creating anything.
        self::createDirectory(dirname($target));
        if (!@mkdir($target, 0700)) {
            throw new UserError('Ziel konnte nicht exklusiv angelegt werden (existiert es bereits?)');
        }
        $actual = realpath($target);
        if ($actual !== $target || is_link($target)) {
            throw new UserError('Zielpfad wurde während der Wiederherstellung verändert');
        }
        $sourceBase = $root . ($base === '' ? '' : '/' . $base);
        $cmd = ['rsync', '-aHAX', '--numeric-ids', '--ignore-existing', '--relative'];
        if ($base === '') {
            $cmd[] = '--exclude=/.zfs';
        }
        if ($version && $base === '') {
            $cmd[] = '--exclude=/.zbg-version.json';
        }
        $cmd[] = '--';
        foreach ($minimal as $rel => $_) {
            $rel = (string)$rel;
            $suffix = $base === '' ? $rel : substr($rel, strlen($base) + 1);
            $cmd[] = $sourceBase . '/./' . $suffix;
        }
        $cmd[] = $target . '/';
        $result = Exec::stream($cmd, static function (string $line): void {}, null, 0);
        if ($result['code'] !== 0) {
            throw new UserError('Wiederherstellung fehlgeschlagen; unvollständige Kopie bleibt unter ' . $target
                . ': ' . ($result['err'] ?: $result['out']));
        }
        return ['target' => $target, 'targets' => $targets, 'count' => $count];
    }

    private static function copyRemote(array $params, array $job): array
    {
        $root = Browse::remoteRoot($job, self::parameter($params, 'version'));
        if (!isset($params['paths']) || !is_array($params['paths']) || $params['paths'] === []) {
            throw new UserError('Mindestens einen wiederherzustellenden Pfad auswählen');
        }
        $selected = [];
        foreach ($params['paths'] as $path) {
            if (!is_string($path)) {
                throw new UserError('Ungültiger Wiederherstellungspfad');
            }
            $rel = Browse::relativePath($path);
            if ($rel === '.zfs' || str_starts_with($rel, '.zfs/')
                || $rel === '.zbg-version.json' || str_starts_with($rel, '.zbg-version.json/')) {
                throw new UserError('Steuer- und Versionsmetadaten können nicht wiederhergestellt werden');
            }
            $selected[$rel] = true;
        }
        uksort($selected, static fn($a, $b) => strlen((string)$a) <=> strlen((string)$b));
        $minimal = [];
        foreach ($selected as $rel => $_) {
            $rel = (string)$rel;
            foreach ($minimal as $parent) {
                if ($parent === '' || str_starts_with($rel, $parent . '/')) {
                    continue 2;
                }
            }
            $minimal[] = $rel;
        }
        $base = self::commonParent($minimal);
        $sourceBase = $root . ($base === '' ? '' : '/' . $base);
        $custom = $params['target'] ?? null;
        if ($custom !== null && (!is_string($custom) || $custom === '')) {
            throw new UserError('Ungültiger Zielpfad');
        }
        if ($custom === null) {
            $original = Zfs::dataset($job['source']['dataset']);
            if (empty($original['path'])) {
                throw new UserError('Original nicht gemountet – bitte einen neuen Zielpfad unter /mnt angeben');
            }
            $custom = rtrim($original['path'], '/') . ($base === '' ? '' : '/' . $base)
                . '/restore-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        }
        $target = self::newTarget($custom);
        $count = self::inspectRemote($job, $root, $sourceBase, $minimal);
        self::createDirectory(dirname($target));
        if (!@mkdir($target, 0700) || realpath($target) !== $target || is_link($target)) {
            throw new UserError('Ziel konnte nicht exklusiv und sicher angelegt werden');
        }
        $ssh = $job['target']['ssh'];
        $host = $ssh['host'];
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $remote = ($ssh['user'] ?? 'root') . '@' . $host . ':';
        $cmd = ['rsync', '-aHAX', '--numeric-ids', '--ignore-existing', '--relative',
            '--safe-links', '--protect-args', '-e', Remote::quote(Remote::ssh($ssh))];
        if ($base === '') {
            array_push($cmd, '--exclude=/.zfs', '--exclude=/.zbg-version.json');
        }
        $cmd[] = '--';
        $targets = [];
        foreach ($minimal as $rel) {
            $suffix = $base === '' ? $rel : substr($rel, strlen($base) + 1);
            $cmd[] = $remote . $sourceBase . '/./' . $suffix;
            $targets[] = $target . ($suffix === '' ? '' : '/' . $suffix);
        }
        $cmd[] = $target . '/';
        $result = Exec::stream($cmd, static function (string $line): void {}, null, 0);
        if ($result['code'] !== 0) {
            throw new UserError('Remote-Wiederherstellung fehlgeschlagen; unvollständige Kopie bleibt unter '
                . $target . ': ' . ($result['err'] ?: $result['out']));
        }
        // --safe-links is defence in depth, never permission to silently omit unsafe links.
        $copied = 0;
        foreach ($targets as $destination) {
            self::inspect($target, $destination, $destination, $target, $copied, true);
        }
        if ($copied !== $count) {
            throw new UserError('Remote-Wiederherstellung unvollständig (fehlende oder übersprungene Einträge); '
                . 'Kopie bleibt unter ' . $target);
        }
        return ['target' => $target, 'targets' => $targets, 'count' => $copied];
    }

    private static function inspectRemote(array $job, string $root, string $base, array $selected): int
    {
        $inspect = <<<'SH'
set -eu
root=$1; base=$2; shift 2
for p do
 if [ -L "$p" ]; then
  link=$(readlink -- "$p" && printf .); link=${link%.}; link=${link%?}
  case "$link" in ''|/*) echo "Absoluter oder leerer Remote-Symlink" >&2; exit 1;; esac
  resolved=$(realpath -m -- "${p%/*}/$link" && printf .)
  resolved=${resolved%.}; resolved=${resolved%?}
  case "$resolved" in "$root"|"$root"/*) ;; *) echo "Remote-Symlink verlässt Version" >&2; exit 1;; esac
  case "$resolved" in "$base"|"$base"/*) ;; *) echo "Remote-Symlink verlässt Wiederherstellungswurzel" >&2; exit 1;; esac
 elif [ ! -d "$p" ] && [ ! -f "$p" ]; then
  echo "Remote-Spezialdatei kann nicht wiederhergestellt werden" >&2; exit 1
 fi
 printf 'ZBG-ENTRY\n'
done
SH;
        $script = <<<'SH'
set -eu
root=$1; base=$2; inspect=$3; control=$4; manifest=$5; shift 5
canonical=$(realpath -e -- "$root" && printf .); canonical=${canonical%.}; canonical=${canonical%?}
[ "$canonical" = "$root" ] && [ ! -L "$root" ]
for rel do
 p=$root; [ -z "$rel" ] || p=$root/$rel
 if [ "$p" != "$root" ]; then
  parent=${p%/*}
  canonical=$(realpath -e -- "$parent" && printf .); canonical=${canonical%.}; canonical=${canonical%?}
  [ "$canonical" = "$parent" ] || { echo "Remote-Quellpfad enthält Symlink-Eltern" >&2; exit 1; }
 fi
 [ -e "$p" ] || [ -L "$p" ]
 find "$p" \( -path "$control" -o -path "$manifest" \) -prune -o -exec sh -c "$inspect" zbg "$root" "$base" {} +
done
SH;
        $escapePattern = static fn(string $path): string => strtr($path,
            ['\\' => '\\\\', '[' => '\\[', ']' => '\\]', '*' => '\\*', '?' => '\\?']);
        $args = array_merge(['sh', '-c', $script, 'zbg', $root, $base, $inspect,
            $escapePattern($root . '/.zfs'), $escapePattern($root . '/.zbg-version.json')], $selected);
        $count = 0;
        $result = Exec::stream(Remote::command($job['target']['ssh'], $args),
            static function (string $line) use (&$count): void {
                if ($line === 'ZBG-ENTRY') {
                    $count++;
                }
            }, null, 0);
        if ($result['code'] !== 0) {
            throw new UserError('Remote-Wiederherstellung nicht sicher möglich: ' . ($result['err'] ?: $result['out']));
        }
        return $count;
    }

    public static function clone(string $dataset, string $snapshot): array
    {
        Browse::assertSnapshot($dataset, $snapshot);
        $ds = Zfs::dataset($dataset);
        if ($ds['type'] !== 'filesystem') {
            throw new UserError('Nur Dateisystem-Snapshots können zum Durchsuchen geklont werden');
        }
        $created = time();
        $token = bin2hex(random_bytes(8));
        $leaf = 'zbg-restore-' . date('Ymd-His', $created) . '-' . $token;
        $name = $ds['pool'] . '/' . $leaf;
        $mountpoint = self::newTarget('/mnt/' . $leaf);
        $origin = $dataset . '@' . $snapshot;
        Exec::ok(['zfs', 'clone', '-o', 'mountpoint=' . $mountpoint, '-o', 'canmount=on',
            '-o', 'readonly=on', '-o', 'devices=off', '-o', 'exec=off', '-o', 'setuid=off',
            '-o', self::CLONE_PROP . '=' . $token, '-o', self::ORIGIN_PROP . '=' . $origin,
            '-o', self::CREATED_PROP . '=' . $created, $origin, $name], 120);
        return ['dataset' => $name, 'mountpoint' => $mountpoint, 'origin' => $origin, 'created' => $created];
    }

    public static function clones(): array
    {
        $rows = Exec::rows(Exec::ok(['zfs', 'list', '-Hp', '-t', 'filesystem', '-o', 'name,mountpoint,origin']),
            ['name', 'mountpoint', 'origin']);
        $clones = [];
        foreach ($rows as $row) {
            if (!preg_match(self::CLONE_RE, $row['name'])) {
                continue;
            }
            $clone = self::ownedClone($row);
            if ($clone !== null) {
                $clones[] = $clone;
            }
        }
        usort($clones, static fn($a, $b) => $b['created'] <=> $a['created']);
        return $clones;
    }

    public static function cloneRoot(string $dataset): string
    {
        $clone = self::checkedClone($dataset);
        $root = realpath($clone['mountpoint']);
        if (trim(Exec::ok(['zfs', 'get', '-Hp', '-o', 'value', 'mounted', $dataset])) !== 'yes'
            || $root !== $clone['mountpoint'] || !is_dir($root)) {
            throw new UserError('Temporärer Klon ist nicht sicher gemountet');
        }
        return $root;
    }

    public static function removeClone(string $dataset): void
    {
        self::checkedClone($dataset);
        // No recursion, force, promotion or destruction of dependants.
        Exec::ok(['zfs', 'destroy', $dataset], 120);
    }

    private static function checkedClone(string $dataset): array
    {
        if (!preg_match(self::CLONE_RE, $dataset)) {
            throw new UserError('Kein temporärer Wiederherstellungs-Klon');
        }
        $rows = Exec::rows(Exec::ok(['zfs', 'list', '-Hp', '-t', 'filesystem', '-o', 'name,mountpoint,origin', $dataset]),
            ['name', 'mountpoint', 'origin']);
        if (count($rows) !== 1 || $rows[0]['name'] !== $dataset || ($clone = self::ownedClone($rows[0])) === null) {
            throw new UserError('Klon-Eigentümerschaft konnte nicht nachgewiesen werden');
        }
        return $clone;
    }

    public static function rollbackCommand(string $dataset, string $snapshot): array
    {
        Browse::assertSnapshot($dataset, $snapshot);
        return [
            'command' => 'zfs rollback ' . escapeshellarg($dataset . '@' . $snapshot),
            'warning' => 'ACHTUNG: Rollback ersetzt den aktuellen Dataset-Inhalt durch diesen Snapshot. '
                . 'Neuere Änderungen gehen verloren. Dieser Befehl wird vom Plugin niemals ausgeführt; '
                . 'vor einer manuellen Ausführung Anwendungen stoppen und den aktuellen Zustand sichern.',
        ];
    }

    private static function ownedClone(array $row): ?array
    {
        if (!preg_match(self::CLONE_RE, $row['name'], $match)
            || $row['mountpoint'] !== '/mnt/' . substr($row['name'], strlen($match[1]) + 1)
            || $row['origin'] === '-' || !str_contains($row['origin'], '@')) {
            return null;
        }
        $properties = [];
        foreach (Exec::rows(Exec::ok(['zfs', 'get', '-Hp', '-o', 'property,value,source',
            self::CLONE_PROP . ',' . self::ORIGIN_PROP . ',' . self::CREATED_PROP, $row['name']]),
            ['property', 'value', 'source']) as $property) {
            if ($property['source'] === 'local') {
                $properties[$property['property']] = $property['value'];
            }
        }
        $created = $properties[self::CREATED_PROP] ?? '';
        if (($properties[self::CLONE_PROP] ?? '') !== $match[3]
            || ($properties[self::ORIGIN_PROP] ?? '') !== $row['origin']
            || !ctype_digit($created) || date('Ymd-His', (int)$created) !== $match[2]) {
            return null;
        }
        return ['dataset' => $row['name'], 'mountpoint' => $row['mountpoint'],
            'origin' => $row['origin'], 'created' => (int)$created];
    }

    private static function parameter(array $params, string $name): string
    {
        $value = $params[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UserError("Parameter $name fehlt");
        }
        return $value;
    }

    private static function commonParent(array $paths): string
    {
        $common = null;
        foreach ($paths as $path) {
            $parent = $path === '' || dirname($path) === '.' ? [] : explode('/', dirname($path));
            if ($common === null) {
                $common = $parent;
                continue;
            }
            $length = 0;
            while (isset($common[$length], $parent[$length]) && $common[$length] === $parent[$length]) {
                $length++;
            }
            $common = array_slice($common, 0, $length);
        }
        return implode('/', $common ?? []);
    }

    /** Resolve links component-by-component, including dangling internal link targets. */
    private static function canonicalPath(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")) {
            throw new UserError('Ungültiger absoluter Pfad');
        }
        $pending = explode('/', $path);
        $resolved = [];
        $links = 0;
        while ($pending !== []) {
            $part = array_shift($pending);
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
                continue;
            }
            $probe = '/' . implode('/', array_merge($resolved, [$part]));
            if (is_link($probe)) {
                if (++$links > 40 || ($link = @readlink($probe)) === false || $link === '') {
                    throw new UserError('Ungültiger oder zyklischer Symlink');
                }
                if ($link[0] === '/') {
                    $resolved = [];
                }
                $pending = array_merge(explode('/', $link), $pending);
            } else {
                if (@lstat($probe) !== false && $pending !== [] && !is_dir($probe)) {
                    throw new UserError('Pfad enthält eine Datei statt eines Verzeichnisses');
                }
                $resolved[] = $part;
            }
        }
        return '/' . implode('/', $resolved);
    }

    private static function newTarget(string $path): string
    {
        $path = Paths::clean($path);
        if (!str_starts_with($path, '/mnt/') || str_contains($path, '\\')) {
            throw new UserError('Neues Ziel muss unter /mnt liegen');
        }
        if (@lstat($path) !== false) {
            throw new UserError('Ziel existiert bereits; vorhandene Dateien werden niemals überschrieben');
        }
        $mnt = realpath('/mnt');
        $resolved = self::canonicalPath($path);
        if ($mnt === false || $mnt === '/' || $resolved === $mnt || !Paths::within($mnt, $resolved)
            || !str_starts_with($resolved, '/mnt/') || @lstat($resolved) !== false) {
            throw new UserError('Ziel verlässt /mnt oder existiert bereits');
        }
        return $resolved;
    }

    private static function createDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new UserError('Zielverzeichnis wurde durch einen Symlink ersetzt');
        }
        if (is_dir($path)) {
            if (realpath($path) !== $path) {
                throw new UserError('Zielverzeichnis wurde verändert');
            }
            return;
        }
        if (@lstat($path) !== false) {
            throw new UserError('Ziel-Elternpfad ist kein Verzeichnis');
        }
        self::createDirectory(dirname($path));
        if (!@mkdir($path, 0700) || realpath($path) !== $path || is_link($path)) {
            throw new UserError('Ziel-Elternverzeichnis konnte nicht sicher angelegt werden');
        }
    }

    private static function inspect(string $root, string $source, string $destination, string $target,
        int &$count, bool $version): void
    {
        $st = @lstat($source);
        if ($st === false) {
            throw new UserError('Quelldatei kann nicht gelesen werden: ' . $source);
        }
        $count++;
        $type = $st['mode'] & 0170000;
        if ($type === 0120000) {
            $link = @readlink($source);
            if ($link === false || $link === '') {
                throw new UserError('Symlink kann nicht gelesen werden: ' . $source);
            }
            $sourceLink = self::canonicalPath($link[0] === '/' ? $link : dirname($source) . '/' . $link);
            $targetLink = self::canonicalPath($link[0] === '/' ? $link : dirname($destination) . '/' . $link);
            if (!Paths::within($root, $sourceLink) || !Paths::within($target, $targetLink)) {
                throw new UserError('Symlink verlässt die Sicherung oder das neue Wiederherstellungsverzeichnis: ' . $source);
            }
            return;
        }
        if ($type === 0100000) {
            return;
        }
        if ($type !== 0040000) {
            throw new UserError('Spezialdateien können nicht als Dateien wiederhergestellt werden: ' . $source);
        }
        $dir = @opendir($source);
        if ($dir === false) {
            throw new UserError('Quellverzeichnis kann nicht gelesen werden: ' . $source);
        }
        try {
            while (($name = readdir($dir)) !== false) {
                if ($name === '.' || $name === '..' || ($version && $source === $root && $name === '.zbg-version.json')) {
                    continue;
                }
                if ($source === $root && $name === '.zfs') {
                    continue;
                }
                self::inspect($root, $source . '/' . $name, $destination . '/' . $name, $target, $count, $version);
            }
        } finally {
            closedir($dir);
        }
    }
}
