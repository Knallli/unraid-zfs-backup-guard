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
        self::assertTargetMappings($plan);
        $base = self::base($job);
        $conflict = Paths::jobTargetConflict($job, JobStore::all());
        if ($conflict !== null) {
            throw new UserError($conflict);
        }
        $ssh = $job['target']['ssh'] ?? null;
        $owner = self::owner($job);
        if ($ssh === null) {
            // vor jedem Zugriff aufs Ziel: auch /mnt/user/<share>/… gegen den realen Pool-/Disk-Pfad der Quelle
            foreach ($plan['items'] as $item) {
                $source = Zfs::dataset($item['name']);
                if ($source['path'] !== null) {
                    $sourcePath = realpath($source['path']) ?: $source['path'];
                    if (Paths::overlap($base, $sourcePath)) {
                        throw new UserError("Quelle {$item['name']} ($sourcePath) und Ziel $base überlappen sich (auch über /mnt/user-Shares geprüft)");
                    }
                }
            }
        }
        $probe = self::remote($job, '
base=$1; expected=$2; adopt=$3; mode=$4; probe=$base
while [ ! -e "$probe" ] && [ ! -L "$probe" ]; do probe=$(dirname -- "$probe"); done
[ -d "$probe" ] && [ -w "$probe" ] || { echo "Ziel nicht beschreibbar" >&2; exit 1; }
[ "$(realpath -m -- "$base")" = "$base" ] || { echo "Ziel enthält Symlink" >&2; exit 1; }
if [ "$mode" = mirror ] && [ -d "$base" ]; then
 find "$base" -xdev -mindepth 2 -maxdepth 4 -name .zbg-target.json 2>/dev/null | while IFS= read -r marker; do
  if [ -L "$marker" ] || [ "$(cat -- "$marker" 2>/dev/null)" != "$expected" ]; then printf "ZBG-FOREIGN:%s\n" "${marker#"$base"/}"; fi
 done || true
fi
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
', [$base, $owner, $job['target']['mode'] === 'mirror' && !empty($job['target']['adoptExisting']) ? 'yes' : 'no', $job['target']['mode']]);
        $rows = [];
        foreach (explode("\n", trim($probe)) as $row) {
            if (!str_starts_with($row, 'ZBG-FOREIGN:')) {
                $rows[] = $row;
            } elseif (!self::mirroredMarker($plan, substr($row, 12))) {
                throw new UserError('Im Ziel liegt unter ./' . dirname(substr($row, 12)) . ' das Ziel eines anderen Jobs (.zbg-target.json); der Spiegel würde es per --delete löschen');
            }
        }
        $existing = $rows[0] ?? '';
        $mount = $rows[1] ?? '';
        $free = (int)trim($rows[count($rows) - 1] ?? '0');
        if ($ssh === null) {
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
        // Delta laut ZFS statt rsync --dry-run: kein zusätzlicher Lauf über den kompletten Dateibaum
        // ponytail: Versionsmodus kopiert geänderte Dateien ganz, written@ unterschätzt dort große, wenig geänderte Dateien
        $estimated = 0;
        $datasets = [];
        foreach ($plan['items'] as $item) {
            if ($cancel !== null && $cancel()) {
                throw new \RuntimeException('Abgebrochen');
            }
            if ($item['action'] !== 'copy') {
                continue;
            }
            $bytes = Zfs::changedSince($item['name'], $job['id']);
            $estimated += $bytes;
            $datasets[] = ['dataset' => $item['name'], 'estimatedBytes' => $bytes];
        }
        if ($free < $estimated) {
            throw new UserError('Zu wenig Zielplatz: benötigt ' . Paths::human($estimated) . ', frei ' . Paths::human($free));
        }
        return ['estimatedBytes' => $estimated, 'free' => $free, 'mountpoint' => $mount, 'previous' => $previous, 'datasets' => $datasets];
    }

    /** Eine fremde .zbg-target.json im Ziel ist nur zulässig, wenn sie als normale Datei aus der Quelle gespiegelt wurde. */
    private static function mirroredMarker(array $plan, string $rel): bool
    {
        $best = null;
        foreach ($plan['items'] as $item) {
            $t = $item['action'] === 'copy' ? (string)$item['targetRel'] : null;
            if ($t !== null && ($t === '' || str_starts_with($rel, $t . '/')) && ($best === null || strlen($t) > strlen((string)$best['targetRel']))) {
                $best = $item;
            }
        }
        if ($best === null || empty($best['path'])) {
            return false;
        }
        $t = (string)$best['targetRel'];
        $source = rtrim($best['path'], '/') . '/' . ($t === '' ? $rel : substr($rel, strlen($t) + 1));
        return is_file($source) && !is_link($source);
    }

    private static function join(string $root, ?string $relative): string
    {
        return rtrim($root, '/') . ($relative === null || $relative === '' ? '' : '/' . $relative);
    }

    /** Artificial target subtrees must not hide ordinary data from another source. */
    private static function assertTargetMappings(array $plan, ?string $snapshot = null): void
    {
        $items = array_values(array_filter($plan['items'], static fn(array $item): bool => $item['action'] === 'copy'));
        foreach ($items as $parent) {
            $prefix = (string)$parent['targetRel'];
            foreach ($items as $child) {
                if ($parent['name'] === $child['name']) {
                    continue;
                }
                $target = (string)$child['targetRel'];
                if ($target === $prefix) {
                    throw new UserError("Dataset-Zielkollision: {$parent['name']} und {$child['name']} verwenden denselben Zielpfad ./$target");
                }
                if ($prefix !== '' && !str_starts_with($target, $prefix . '/')) {
                    continue;
                }
                $relative = $prefix === '' ? $target : substr($target, strlen($prefix) + 1);
                $mountPath = self::join($parent['path'], $relative);
                if ($mountPath === rtrim($child['path'], '/') && in_array('/' . $relative . '/', $parent['protect'], true)) {
                    // A physical child mount is deliberately excluded from the parent copy.
                    continue;
                }
                $source = $snapshot === null ? $parent['path'] : Zfs::snapshotDir($parent['name'], $snapshot);
                $path = rtrim($source, '/');
                $parts = explode('/', $relative);
                foreach ($parts as $index => $part) {
                    $path .= '/' . $part;
                    clearstatcache(true, $path);
                    if (is_link($path) || (file_exists($path) && ($index === count($parts) - 1 || !is_dir($path)))) {
                        throw new UserError("Dataset-Zielkollision: Ziel ./$target für {$child['name']} verdeckt Quelldaten von {$parent['name']} unter $path");
                    }
                }
            }
        }
    }

    private static function command(array $job, array $plan, array $item, string $source, string $target, ?string $previous, bool $dryRun, bool $checksum): array
    {
        $cmd = ['rsync', '-aHAX', '--numeric-ids', '--delete', '--delete-excluded', '-x', '--stats', '--protect-args'];
        if (($job['target']['mode'] ?? '') === 'mirror' && !$dryRun) {
            // wie das alte User-Script: geänderte Dateien direkt überschreiben statt komplett neu schreiben (nie bei Hardlink-Versionen)
            $cmd[] = '--inplace';
        }
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
        $own = (string)($item['rel'] ?? '');
        foreach ($plan['items'] as $other) {
            if ($other['name'] === $item['name']) {
                continue;
            }
            if ($other['targetRel'] === null) {
                // nicht kopierbares Kind (nicht gemountet/gesperrt): dessen Zielordner nie per --delete leeren
                $orel = (string)($other['rel'] ?? '');
                if ($orel !== '' && ($own === '' || str_starts_with($orel, $own . '/'))) {
                    $protect[] = '/' . ($own === '' ? $orel : substr($orel, strlen($own) + 1)) . '/';
                }
                if ($orel !== '' && $prefix === '') {
                    // außerhalb gemountete Kinder liegen unter .datasets/<rel>
                    $protect[] = '/.datasets/' . $orel . '/';
                }
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
        foreach (['.zfs', '.zbg-target.json', '.zbg-version.json', '.zbg-system', '.zbg-stage.json', '.zbg-owner-*', '.zbg-latest-*'] as $reserved) {
            array_push($cmd, '--filter=P /' . $reserved, '--filter=- /' . $reserved);
        }
        if ($previous !== null && !$checksum) {
            $cmd[] = '--link-dest=' . $previous;
        }
        $target = self::sshTarget($job, $cmd, $target);
        array_push($cmd, '--', $source, rtrim($target, '/') . '/');
        return $cmd;
    }

    /** Hängt bei SSH-Zielen `-e` an $cmd und liefert das Ziel als user@host:pfad. */
    private static function sshTarget(array $job, array &$cmd, string $target): string
    {
        $ssh = $job['target']['ssh'] ?? null;
        if ($ssh === null) {
            return $target;
        }
        return self::sshRemote($ssh, $cmd) . $target;
    }

    /** Hängt `-e <ssh>` an ein rsync-Kommando und liefert das Adresspräfix user@host: (IPv6 in Klammern). */
    public static function sshRemote(array $ssh, array &$cmd): string
    {
        array_push($cmd, '-e', Remote::quote(Remote::ssh($ssh)));
        $host = (string)$ssh['host'];
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        return ($ssh['user'] ?? 'root') . '@' . $host . ':';
    }

    /** Systemdisk-Metadaten lokal erfassen und nach <Ziel>/.zbg-system/ übertragen. */
    private static function system(array $job, string $destination, ?string $previous, callable $log, ?callable $cancel): array
    {
        $stage = sys_get_temp_dir() . '/zbg-system-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700)) {
            throw new \RuntimeException('Staging-Ordner nicht erstellbar');
        }
        try {
            $log('Systemdisk: Partitionen, EFI, LUKS-Header');
            $summary = System::capture($stage, $log);
            $target = self::join($destination, '.zbg-system');
            self::remote($job, 'root=$1; t=$2; [ "$(realpath -m -- "$t")" = "$t" ] || { echo "Ziel-Symlink verweigert" >&2; exit 1; }; mkdir -p -- "$t"', [$destination, $target]);
            // frisch erzeugte Dateien haben neue mtimes: per Prüfsumme vergleichen, damit nur echte Änderungen übertragen bzw. sonst hart verlinkt werden
            $cmd = ['rsync', '-a', '--checksum', '--delete', '--numeric-ids', '--protect-args'];
            if ($previous !== null) {
                $cmd[] = '--link-dest=' . self::join($previous, '.zbg-system');
            }
            $remote = self::sshTarget($job, $cmd, $target);
            array_push($cmd, '--', $stage . '/', $remote . '/');
            $run = Exec::stream($cmd, $log, $cancel);
            if ($run['code'] !== 0) {
                throw new \RuntimeException('Rsync fehlgeschlagen für Systemdisk: ' . ($run['err'] ?: $run['out']));
            }
            return $summary;
        } finally {
            self::rmTree($stage);
        }
    }

    private static function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                is_dir("$dir/$e") && !is_link("$dir/$e") ? self::rmTree("$dir/$e") : @unlink("$dir/$e");
            }
        }
        @rmdir($dir);
    }

    private static function stats(string $output): array
    {
        preg_match('/Total transferred file size:\s*([\d,]+) bytes/', $output, $bytes);
        preg_match('/Number of (?:regular )?files transferred:\s*([\d,]+)/', $output, $files);
        return ['bytes' => (int)str_replace(',', '', $bytes[1] ?? '0'), 'files' => (int)str_replace(',', '', $files[1] ?? '0')];
    }

    public static function transfer(array $job, array $plan, string $snapshot, callable $log, ?callable $cancel = null, bool $verify = false): array
    {
        self::assertTargetMappings($plan, $snapshot);
        $base = self::base($job);
        $versioned = $job['target']['mode'] === 'versioned';
        $versions = $versioned ? self::versions($job) : [];
        $previous = $versions[0]['path'] ?? null;
        $time = time();
        $name = date('Y-m-d_His', $time);
        $destination = $versioned ? $base . '/.zbg-incomplete-' . $name . '-' . bin2hex(random_bytes(4)) : $base;
        self::prepare($job, $base, $destination, !$versioned && !empty($job['target']['adoptExisting']), $log);
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
            self::remote($job, 'root=$1; target=$2; [ "$(realpath -m -- "$target")" = "$target" ] || { echo "Ziel-Symlink verweigert" >&2; exit 1; }; mkdir -p -- "$target"; [ "$(realpath -- "$target")" = "$target" ] || { echo "Dataset-Zielordner ist nicht kanonisch (Symlink?): $target" >&2; exit 1; }', [$destination, $target]);
            $source = Zfs::snapshotDir($item['name'], $snapshot) . '/';
            $log('Dataset: ' . $item['name']);
            if (!$versioned && count(array_diff(scandir($source) ?: [], ['.', '..'])) === 0) {
                self::remote($job, 't=$1; [ -z "$(find "$t" -mindepth 1 -maxdepth 1 ! -name ".zbg-*" ! -name ".datasets" -print -quit)" ] || { echo "Snapshot-Quelle ist leer, das Ziel nicht: --delete verweigert" >&2; exit 1; }', [$target]);
            }
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
        if (!empty($job['system'])) {
            $result['system'] = self::system($job, $destination, $previous, $log, $cancel);
            if ($cancel !== null && $cancel()) {
                throw new \RuntimeException('Abgebrochen');
            }
        }
        if ($versioned) {
            $result['version'] = empty($job['target']['ssh'])
                ? basename(Versions::complete($job, $destination, $name, $time))
                : self::completeRemote($job, $destination, $name, $time);
        }
        return $result;
    }

    /** Ziel übernehmen bzw. prüfen, eigene liegengebliebene Staging-Ordner entfernen und neuen anlegen. */
    private static function prepare(array $job, string $base, string $destination, bool $adopt, callable $log): void
    {
        $out = self::remote($job, '
base=$1; owner=$2; target=$3; adopt=$4
[ "$(realpath -m -- "$base")" = "$base" ] || { echo "Ziel enthält einen Symlink: $base" >&2; exit 1; }
mkdir -p -- "$base"
if [ -e "$base/.zbg-target.json" ] || [ -L "$base/.zbg-target.json" ]; then
 [ ! -L "$base/.zbg-target.json" ] && [ "$(cat -- "$base/.zbg-target.json")" = "$owner" ] || { echo "Fremde Ziel-Eigentümerschaft" >&2; exit 1; }
else
 [ "$adopt" = yes ] || [ -z "$(find "$base" -mindepth 1 -maxdepth 1 -print -quit)" ] || { echo "Nichtleeres Ziel ohne eigene Eigentümerschaft (.zbg-target.json fehlt); neues Ziel wählen" >&2; exit 1; }
 tmp=$(mktemp "$base/.zbg-owner-XXXXXXXX"); printf "%s\\n" "$owner" > "$tmp"
 if ! ln -T -- "$tmp" "$base/.zbg-target.json"; then
  rm -f -- "$tmp"
  [ ! -L "$base/.zbg-target.json" ] && [ "$(cat -- "$base/.zbg-target.json")" = "$owner" ] || { echo "Eigentümer-Markierung .zbg-target.json konnte nicht angelegt werden (Hardlink fehlgeschlagen)" >&2; exit 1; }
 else rm -f -- "$tmp"; fi
fi
if [ "$target" != "$base" ]; then
 for stale in "$base"/.zbg-incomplete-*; do
  [ -d "$stale" ] && [ ! -L "$stale" ] && [ -f "$stale/.zbg-stage.json" ] && [ ! -L "$stale/.zbg-stage.json" ] || continue
  [ "$(cat -- "$stale/.zbg-stage.json")" = "$owner" ] || continue
  echo "Entferne abgebrochene Version ${stale##*/}"
  rm -rf --one-file-system -- "$stale"
 done
 mkdir -- "$target" || { echo "Staging-Ordner existiert bereits: $target" >&2; exit 1; }
 printf "%s\\n" "$owner" > "$target/.zbg-stage.json"
fi
', [$base, self::owner($job), $destination, $adopt ? 'yes' : 'no'], 0);
        foreach (explode("\n", trim($out)) as $line) {
            if ($line !== '') {
                $log($line);
            }
        }
    }

    public static function versions(array $job): array
    {
        if (empty($job['target']['ssh'])) {
            return Versions::list($job);
        }
        $base = self::base($job);
        $out = self::remote($job, 'base=$1; [ -d "$base" ] || exit 0; [ "$(realpath -- "$base")" = "$base" ] || { echo "Versionsziel enthält einen Symlink: $base" >&2; exit 1; }; for dir in "$base"/*; do [ -d "$dir" ] && [ ! -L "$dir" ] && [ -f "$dir/.zbg-version.json" ] && [ ! -L "$dir/.zbg-version.json" ] || continue; printf "%s\\t" "${dir##*/}"; cat -- "$dir/.zbg-version.json"; printf "\\n"; done', [$base]);
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
        usort($entries, static fn($a, $b) => $b['time'] <=> $a['time'] ?: strnatcmp($b['name'], $a['name']));
        return $entries;
    }

    /** Schließt die Remote-Version ab; bei Namenskollision (z. B. Zeitumstellung) mit Suffix -2, -3, … */
    private static function completeRemote(array $job, string $stage, string $name, int $time): string
    {
        $base = self::base($job);
        $entries = self::versions($job);
        $latest = trim(self::remote($job, 'if [ -L "$1/latest" ]; then readlink -- "$1/latest"; elif [ -e "$1/latest" ]; then echo "Fremdes latest" >&2; exit 1; fi', [$base]));
        if ($latest !== '' && !in_array($latest, array_column($entries, 'name'), true)) {
            throw new UserError('Remote latest ist kein eigener Versionszeiger');
        }
        $manifest = explode('@@ZBG-NAME@@', json_encode(['job' => $job['id'], 'name' => '@@ZBG-NAME@@', 'time' => $time, 'complete' => true], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 2);
        $final = trim(self::remote($job, '
base=$1; stage=$2; name=$3; head=$4; tail=$5; final=$name; n=1
while [ -e "$base/$final" ] || [ -L "$base/$final" ]; do
 n=$((n + 1)); [ "$n" -le 99 ] || { echo "Version $name existiert bereits (auch mit Suffix)" >&2; exit 1; }
 final=$name-$n
done
rm -f -- "$stage/.zbg-stage.json"
tmp="$stage/.zbg-version-$$"; printf "%s%s%s\\n" "$head" "$final" "$tail" > "$tmp"; mv -T -- "$tmp" "$stage/.zbg-version.json"
mv -T -- "$stage" "$base/$final" || { echo "Version $final konnte nicht abgeschlossen werden" >&2; exit 1; }
link="$base/.zbg-latest-$$"; ln -s -- "$final" "$link"; mv -Tf -- "$link" "$base/latest"
printf "%s\\n" "$final"', [$base, $stage, $name, $manifest[0], $manifest[1]]));
        if (!Versions::validName($final) || !str_starts_with($final, $name)) {
            throw new \RuntimeException('Remote-Versionsabschluss lieferte ungültigen Namen: ' . $final);
        }
        return $final;
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
            self::remote($job, 'path=$1; expected=$2; [ -d "$path" ] && [ ! -L "$path" ] && [ ! -L "$path/.zbg-version.json" ] && [ "$(cat -- "$path/.zbg-version.json")" = "$expected" ] || { echo "Version $path ist nicht eindeutig eigene Version, nicht gelöscht" >&2; exit 1; }; rm -rf --one-file-system -- "$path"; [ ! -e "$path" ] || { echo "Version $path konnte nicht vollständig gelöscht werden" >&2; exit 1; }', [$entry['path'], $expected], 0);
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
