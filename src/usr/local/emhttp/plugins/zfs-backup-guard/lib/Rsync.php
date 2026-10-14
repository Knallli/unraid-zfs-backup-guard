<?php
declare(strict_types=1);

namespace ZBG;

final class Rsync
{
    private static function remote(array $job, string $script, array $args = [], int $timeout = 60): string
    {
        return Exec::ok(Remote::command($job['target']['ssh'] ?? null, array_merge(['sh', '-c', 'set -eu; ' . $script, 'zbg'], $args)), $timeout);
    }

    private static function owner(array $job): string
    {
        return json_encode(['job' => $job['id'], 'mode' => $job['target']['mode']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function base(array $job): string
    {
        return Paths::normalizeTarget($job['target']['path']);
    }

    /** The nearest existing ancestor must be mounted, writable and canonical. */
    public static function preflight(array $job, array $plan, ?callable $cancel = null): array
    {
        $base = self::base($job);
        $ssh = $job['target']['ssh'] ?? null;
        $owner = self::owner($job);
        $probe = self::remote($job, '
base=$1; expected=$2; adopt=$3; probe=$base
while [ ! -e "$probe" ] && [ ! -L "$probe" ]; do probe=$(dirname -- "$probe"); done
[ -d "$probe" ] && [ -w "$probe" ] || { echo "Ziel nicht beschreibbar" >&2; exit 1; }
[ "$(realpath -m -- "$base")" = "$base" ] || { echo "Ziel enthält Symlink" >&2; exit 1; }
mount=$(findmnt -n -o TARGET -T "$probe")
[ -n "$mount" ] && [ "$mount" != / ] || { echo "Ziel nicht gemountet" >&2; exit 1; }
fstype=$(findmnt -n -o FSTYPE -T "$probe")
if [ "$fstype" = zfs ]; then device=$(findmnt -n -o SOURCE -T "$probe"); pool=${device%%/*}; [ "$(zpool list -H -o health "$pool")" = ONLINE ] || { echo "Ziel-Pool nicht ONLINE" >&2; exit 1; }; fi
if [ -e "$base/.zbg-target.json" ] || [ -L "$base/.zbg-target.json" ]; then
 [ ! -L "$base/.zbg-target.json" ] && [ "$(cat -- "$base/.zbg-target.json")" = "$expected" ] || { echo "Fremde Ziel-Eigentümerschaft" >&2; exit 1; }
elif [ "$adopt" != yes ] && [ -d "$base" ] && [ -n "$(find "$base" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
 echo "Nichtleeres Ziel ohne eigene Eigentümerschaft; neues Ziel wählen" >&2; exit 1
fi
printf "%s\\n%s\\n" "$probe" "$mount"
df -B1 --output=avail -- "$probe"
', [$base, $owner, $job['target']['mode'] === 'mirror' && !empty($job['target']['adoptExisting']) ? 'yes' : 'no']);
        $rows = explode("\n", trim($probe));
        $existing = $rows[0] ?? '';
        $mount = $rows[1] ?? '';
        $free = (int)trim($rows[count($rows) - 1] ?? '0');
        if ($ssh === null) {
            foreach ($plan['items'] as $item) {
                $source = Zfs::dataset($item['name']);
                if ($source['path'] !== null) {
                    $sourcePath = realpath($source['path']) ?: $source['path'];
                    if (Paths::within($sourcePath, $base) || Paths::within($base, $sourcePath)) {
                        throw new UserError('Quelle und Ziel dürfen sich nicht überlappen');
                    }
                }
            }
            $targetMount = Paths::mountFor($existing);
            if (($targetMount['type'] ?? '') === 'zfs') {
                $pool = Zfs::pool(explode('/', $targetMount['device'])[0]);
                if ($pool === null || $pool['health'] !== 'ONLINE') {
                    throw new UserError('Ziel-Pool ist nicht ONLINE');
                }
            }
        }
        $versions = $job['target']['mode'] === 'versioned' ? self::versions($job) : [];
        $previous = $versions[0]['path'] ?? null;
        if ($ssh === null && $job['target']['mode'] === 'versioned') {
            Versions::assertLatest($job);
        }
        $estimated = 0;
        $datasets = [];
        foreach ($plan['items'] as $item) {
            if ($cancel !== null && $cancel()) {
                throw new \RuntimeException('Abgebrochen');
            }
            if ($item['action'] !== 'copy') {
                continue;
            }
            $destination = $job['target']['mode'] === 'mirror' ? self::join($base, $item['targetRel']) : ($previous === null ? null : self::join($previous, $item['targetRel']));
            if ($destination === null || !self::exists($job, $destination)) {
                $destination = $existing . '/.zbg-estimate-' . bin2hex(random_bytes(8));
            }
            $cmd = self::command($job, $plan, $item, rtrim($item['path'], '/') . '/', $destination, null, true, false);
            $result = Exec::stream($cmd, static function (string $line): void {}, $cancel, 300);
            if ($result['code'] !== 0) {
                throw new \RuntimeException('Rsync-Vorprüfung fehlgeschlagen: ' . ($result['err'] ?: $result['out']));
            }
            $stats = self::stats($result['out']);
            $estimated += $stats['bytes'];
            $datasets[] = ['dataset' => $item['name'], 'estimatedBytes' => $stats['bytes']];
        }
        if ($free < $estimated) {
            throw new UserError('Zu wenig Zielplatz: benötigt ' . Paths::human($estimated) . ', frei ' . Paths::human($free));
        }
        return ['estimatedBytes' => $estimated, 'free' => $free, 'mountpoint' => $mount, 'previous' => $previous, 'datasets' => $datasets];
    }

    private static function exists(array $job, string $path): bool
    {
        $r = Exec::run(Remote::command($job['target']['ssh'] ?? null, ['test', '-d', $path]));
        if (!in_array($r['code'], [0, 1], true)) {
            throw new \RuntimeException('Zielabfrage fehlgeschlagen: ' . $r['err']);
        }
        return $r['code'] === 0;
    }

    private static function join(string $root, ?string $relative): string
    {
        return rtrim($root, '/') . ($relative === null || $relative === '' ? '' : '/' . $relative);
    }

    private static function command(array $job, array $plan, array $item, string $source, string $target, ?string $previous, bool $dryRun, bool $checksum): array
    {
        $cmd = ['rsync', '-aHAX', '--numeric-ids', '--delete', '--delete-excluded', '-x', '--stats', '--protect-args'];
        if ($dryRun) {
            $cmd[] = '--dry-run';
        } else {
            $cmd[] = '--info=progress2';
        }
        if ($checksum) {
            array_push($cmd, '--checksum', '--out-format=ZBG-CHECK:%i %n%L');
        }
        $protect = $item['protect'];
        $prefix = (string)$item['targetRel'];
        foreach ($plan['items'] as $other) {
            if ($other['name'] === $item['name'] || $other['targetRel'] === null) {
                continue;
            }
            $rel = (string)$other['targetRel'];
            if ($rel !== $prefix && ($prefix === '' || str_starts_with($rel, $prefix . '/'))) {
                $protect[] = '/' . ($prefix === '' ? $rel : substr($rel, strlen($prefix) + 1)) . '/';
            }
        }
        foreach (array_unique($protect) as $path) {
            $pattern = strtr(rtrim($path, '/'), ['\\' => '\\\\', '[' => '\\[', ']' => '\\]', '*' => '\\*', '?' => '\\?']) . '/***';
            array_push($cmd, '--filter=P ' . $pattern, '--filter=- ' . $pattern);
        }
        foreach (['.zfs', '.zbg-target.json', '.zbg-version.json', '.zbg-owner-*', '.zbg-latest-*'] as $reserved) {
            array_push($cmd, '--filter=P /' . $reserved, '--filter=- /' . $reserved);
        }
        if ($previous !== null && !$checksum) {
            $cmd[] = '--link-dest=' . $previous;
        }
        $ssh = $job['target']['ssh'] ?? null;
        if ($ssh !== null) {
            array_push($cmd, '-e', Remote::quote(Remote::ssh($ssh)));
            $host = $ssh['host'];
            if (str_contains($host, ':') && $host[0] !== '[') {
                $host = '[' . $host . ']';
            }
            $target = ($ssh['user'] ?? 'root') . '@' . $host . ':' . $target;
        }
        array_push($cmd, '--', $source, rtrim($target, '/') . '/');
        return $cmd;
    }

    private static function stats(string $output): array
    {
        preg_match('/Total transferred file size:\s*([\d,]+) bytes/', $output, $bytes);
        preg_match('/Number of (?:regular )?files transferred:\s*([\d,]+)/', $output, $files);
        return ['bytes' => (int)str_replace(',', '', $bytes[1] ?? '0'), 'files' => (int)str_replace(',', '', $files[1] ?? '0')];
    }

    public static function transfer(array $job, array $plan, string $snapshot, callable $log, ?callable $cancel = null, bool $verify = false): array
    {
        $base = self::base($job);
        $versioned = $job['target']['mode'] === 'versioned';
        $versions = $versioned ? self::versions($job) : [];
        $previous = $versions[0]['path'] ?? null;
        $time = time();
        $name = date('Y-m-d_His', $time);
        $destination = $versioned ? $base . '/.zbg-incomplete-' . $name . '-' . bin2hex(random_bytes(4)) : $base;
        self::remote($job, '
base=$1; owner=$2; target=$3; adopt=$4
[ "$(realpath -m -- "$base")" = "$base" ] || exit 1
mkdir -p -- "$base"
if [ -e "$base/.zbg-target.json" ] || [ -L "$base/.zbg-target.json" ]; then
 [ ! -L "$base/.zbg-target.json" ] && [ "$(cat -- "$base/.zbg-target.json")" = "$owner" ] || { echo "Fremde Ziel-Eigentümerschaft" >&2; exit 1; }
else
 [ "$adopt" = yes ] || [ -z "$(find "$base" -mindepth 1 -maxdepth 1 -print -quit)" ] || exit 1
 tmp=$(mktemp "$base/.zbg-owner-XXXXXXXX"); printf "%s\\n" "$owner" > "$tmp"
 if ! ln -T -- "$tmp" "$base/.zbg-target.json"; then rm -f -- "$tmp"; [ ! -L "$base/.zbg-target.json" ] && [ "$(cat -- "$base/.zbg-target.json")" = "$owner" ] || exit 1; else rm -f -- "$tmp"; fi
fi
if [ "$target" != "$base" ]; then mkdir -- "$target"; fi
', [$base, self::owner($job), $destination, !$versioned && !empty($job['target']['adoptExisting']) ? 'yes' : 'no']);
        $result = ['bytes' => 0, 'files' => 0, 'datasets' => [], 'verification' => ['checked' => $verify, 'mismatches' => 0], 'version' => null];
        foreach ($plan['items'] as $item) {
            if ($cancel !== null && $cancel()) {
                throw new \RuntimeException('Abgebrochen');
            }
            if ($item['action'] !== 'copy') {
                $log('WARNUNG: nur Snapshot, keine Dateikopie: ' . $item['name']);
                $result['datasets'][] = ['dataset' => $item['name'], 'action' => 'snapshot-only', 'bytes' => 0, 'files' => 0, 'verification' => ['checked' => false, 'mismatches' => 0]];
                continue;
            }
            $target = self::join($destination, $item['targetRel']);
            self::remote($job, 'root=$1; target=$2; [ "$(realpath -m -- "$target")" = "$target" ] || { echo "Ziel-Symlink verweigert" >&2; exit 1; }; mkdir -p -- "$target"; [ "$(realpath -- "$target")" = "$target" ]', [$destination, $target]);
            $source = Zfs::snapshotDir($item['name'], $snapshot) . '/';
            $log('Dataset: ' . $item['name']);
            $run = Exec::stream(self::command($job, $plan, $item, $source, $target, $previous === null ? null : self::join($previous, $item['targetRel']), false, false), $log, $cancel);
            if ($run['code'] !== 0) {
                throw new \RuntimeException('Rsync fehlgeschlagen für ' . $item['name'] . ': ' . ($run['err'] ?: $run['out']));
            }
            $stats = self::stats($run['out']);
            $mismatches = 0;
            if ($verify) {
                $log('Prüfsummenprüfung: ' . $item['name']);
                $checkLog = static function (string $line) use ($log, &$mismatches): void {
                    if (str_starts_with($line, 'ZBG-CHECK:')) {
                        $mismatches++;
                    }
                    $log($line);
                };
                $check = Exec::stream(self::command($job, $plan, $item, $source, $target, null, true, true), $checkLog, $cancel);
                if ($check['code'] !== 0) {
                    throw new \RuntimeException('Prüfsummenprüfung fehlgeschlagen: ' . ($check['err'] ?: $check['out']));
                }
            }
            $stats += ['dataset' => $item['name'], 'action' => 'copy', 'verification' => ['checked' => $verify, 'mismatches' => $mismatches]];
            $result['datasets'][] = $stats;
            $result['bytes'] += $stats['bytes'];
            $result['files'] += $stats['files'];
            $result['verification']['mismatches'] += $mismatches;
            $log('ZBG-STATS:' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        if ($result['verification']['mismatches'] > 0) {
            // Preserve measured results in the failure, not a fictitious successful version.
            throw new VerificationFailure($result);
        }
        if ($cancel !== null && $cancel()) {
            throw new \RuntimeException('Abgebrochen');
        }
        if ($versioned) {
            if (empty($job['target']['ssh'])) {
                Versions::complete($job, $destination, $name, $time);
            } else {
                self::completeRemote($job, $destination, $name, $time);
            }
            $result['version'] = $name;
        }
        return $result;
    }

    public static function versions(array $job): array
    {
        if (empty($job['target']['ssh'])) {
            return Versions::list($job);
        }
        $base = self::base($job);
        $out = self::remote($job, 'base=$1; [ -d "$base" ] || exit 0; [ "$(realpath -- "$base")" = "$base" ]; for dir in "$base"/*; do [ -d "$dir" ] && [ ! -L "$dir" ] && [ -f "$dir/.zbg-version.json" ] && [ ! -L "$dir/.zbg-version.json" ] || continue; printf "%s\\t" "${dir##*/}"; cat -- "$dir/.zbg-version.json"; printf "\\n"; done', [$base]);
        $entries = [];
        foreach (explode("\n", trim($out)) as $line) {
            $parts = explode("\t", $line, 2);
            if (count($parts) !== 2 || !Versions::validName($parts[0])) {
                continue;
            }
            $manifest = json_decode($parts[1], true);
            if (($manifest['job'] ?? '') === $job['id'] && ($manifest['name'] ?? '') === $parts[0] && !empty($manifest['complete']) && is_int($manifest['time'] ?? null)) {
                $entries[] = ['name' => $parts[0], 'time' => $manifest['time'], 'path' => $base . '/' . $parts[0]];
            }
        }
        usort($entries, static fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
        return $entries;
    }

    private static function completeRemote(array $job, string $stage, string $name, int $time): void
    {
        $base = self::base($job);
        $entries = self::versions($job);
        $latest = trim(self::remote($job, 'if [ -L "$1/latest" ]; then readlink -- "$1/latest"; elif [ -e "$1/latest" ]; then echo "Fremdes latest" >&2; exit 1; fi', [$base]));
        if ($latest !== '' && !in_array($latest, array_column($entries, 'name'), true)) {
            throw new UserError('Remote latest ist kein eigener Versionszeiger');
        }
        $manifest = json_encode(['job' => $job['id'], 'name' => $name, 'time' => $time, 'complete' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::remote($job, 'base=$1; stage=$2; name=$3; manifest=$4; [ ! -e "$base/$name" ] && [ ! -L "$base/$name" ]; tmp="$stage/.zbg-version-$$"; printf "%s\\n" "$manifest" > "$tmp"; mv -T -- "$tmp" "$stage/.zbg-version.json"; mv -T -- "$stage" "$base/$name"; link="$base/.zbg-latest-$$"; ln -s -- "$name" "$link"; mv -Tf -- "$link" "$base/latest"', [$base, $stage, $name, $manifest]);
    }

    public static function prune(array $job, callable $log): void
    {
        if ($job['target']['mode'] !== 'versioned') {
            return;
        }
        if (empty($job['target']['ssh'])) {
            Versions::prune($job, $log);
            return;
        }
        $entries = self::versions($job);
        $latest = trim(self::remote($job, '[ ! -L "$1/latest" ] || readlink -- "$1/latest"', [self::base($job)]));
        $retention = Retention::plan($entries, $job['versions']['keep'], $latest === '' ? [] : [$latest]);
        foreach ($retention['destroy'] as $name) {
            $entry = array_values(array_filter($entries, static fn($entry) => $entry['name'] === $name))[0];
            $expected = json_encode(['job' => $job['id'], 'name' => $name, 'time' => $entry['time'], 'complete' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $log("Remote Versions-Retention: $name");
            self::remote($job, 'path=$1; expected=$2; [ -d "$path" ] && [ ! -L "$path" ] && [ ! -L "$path/.zbg-version.json" ]; [ "$(cat -- "$path/.zbg-version.json")" = "$expected" ]; rm -rf --one-file-system -- "$path"; [ ! -e "$path" ]', [$entry['path'], $expected], 0);
        }
    }
}

final class VerificationFailure extends \RuntimeException
{
    public array $result;

    public function __construct(array $result)
    {
        $this->result = $result;
        parent::__construct('Prüfsummenabweichungen: ' . $result['verification']['mismatches']);
    }
}
