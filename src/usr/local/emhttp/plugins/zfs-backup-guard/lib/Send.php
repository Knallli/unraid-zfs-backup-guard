<?php
declare(strict_types=1);

namespace ZBG;

/** Individual streams deliberately avoid -R/-F: excludes and foreign data stay untouched. */
final class Send
{
    private const OWNER = 'zbg:job';
    private const SOURCE = 'zbg:source';

    public static function preflight(array $job, array $plan): array
    {
        $ctx = self::inspect($job, $plan);
        $total = 0;
        $datasets = $protected = [];
        foreach ($ctx['items'] as $item) {
            $estimate = self::estimate($item, null);
            self::space(['available' => $item['available']], $estimate);
            $total += $estimate;
            $datasets[] = ['dataset' => $item['source'], 'target' => $item['target'],
                'mode' => $item['resume'] !== null ? 'resume' : ($item['base'] !== null ? 'incremental' : 'full'),
                'base' => $item['base']['name'] ?? null, 'estimatedBytes' => $estimate];
            $protected[$item['source']] = self::protection($item);
        }
        self::space($ctx, $total);
        return ['estimatedBytes' => $total, 'availableBytes' => $ctx['available'], 'datasets' => $datasets,
            'protectedSnapshots' => $protected, 'warnings' => []];
    }

    public static function transfer(array $job, array $plan, string $snapshot, callable $log, ?callable $cancel = null): array
    {
        if (!Zfs::isOwn($snapshot, (string)$job['id'])) {
            throw new UserError('Kein eigener Replikations-Snapshot');
        }
        $ctx = self::inspect($job, $plan);
        $estimates = [];
        foreach ($ctx['items'] as $i => $item) {
            self::findOwn($item['snapshots'], $item['source'] . '@' . $snapshot, (string)$job['id']);
            $estimates[$i] = self::estimate($item, $snapshot);
            self::space(['available' => $item['available']], $estimates[$i]);
        }
        self::space($ctx, array_sum($estimates));
        $pv = Exec::run(['bash', '-c', 'command -v pv']);
        $meter = $pv['code'] === 0 && trim($pv['out']) !== '' ? trim($pv['out']) : null;
        $result = ['bytes' => 0, 'files' => 0, 'estimatedBytes' => array_sum($estimates),
            'protectedSnapshots' => [], 'datasets' => []];
        foreach ($ctx['items'] as $item) {
            self::cancel($cancel);
            $bytes = 0;
            $measured = false;
            // Finish exactly the stream described by the token before starting a newer snapshot.
            if ($item['resume'] !== null) {
                $resume = $item['resume'];
                $log('Fortsetzen: ' . $item['source'] . ' → ' . $item['target']);
                $stats = self::pipe($job, $item, ['zfs', 'send', '-vP', '-t', $resume['token']],
                    $resume['snapshot'], $meter, $log, $cancel);
                $bytes += $stats['bytes'];
                $measured = $stats['measured'];
                self::finish($job, $item, $resume['snapshot'], $log);
                // A completed resume may itself be the snapshot requested by the caller.
                $item['base'] = $resume['snapshot'];
                $item['resume'] = null;
            }
            $wanted = self::findOwn($item['snapshots'], $item['source'] . '@' . $snapshot, (string)$job['id']);
            if (($item['base']['guid'] ?? null) !== $wanted['guid']) {
                $cmd = self::sendCommand($item, $wanted['name'], false);
                $log(($item['base'] ? 'Inkrementell: ' : 'Vollständig: ') . $item['source'] . ' → ' . $item['target']);
                $stats = self::pipe($job, $item, $cmd, $wanted, $meter, $log, $cancel);
                $bytes += $stats['bytes'];
                $measured = $measured || $stats['measured'];
                self::finish($job, $item, $wanted, $log);
            }
            $result['bytes'] += $bytes;
            $result['protectedSnapshots'][$item['source']] = [$wanted['name']];
            $result['datasets'][] = ['dataset' => $item['source'], 'target' => $item['target'],
                'bytes' => $bytes, 'files' => 0, 'bytesMeasured' => $measured, 'snapshot' => $snapshot];
        }
        return $result;
    }

    public static function prune(array $job, array $plan, callable $log): void
    {
        $ctx = self::inspect($job, $plan);
        foreach ($ctx['items'] as $item) {
            if ($item['resume'] !== null) {
                $log('Ziel-Retention ausgelassen: unvollständiger Empfang für ' . $item['target']);
                continue;
            }
            $common = self::common($item['snapshots'], $item['targetSnapshots'], (string)$job['id']);
            if ($common === null) {
                throw new \RuntimeException('Kein gemeinsamer Snapshot vor Ziel-Retention');
            }
            $protect = [];
            $owned = [];
            foreach ($item['targetSnapshots'] as $snap) {
                if ($snap['guid'] === $common['guid']) {
                    $protect[] = $snap['name'];
                }
                if (self::owned($snap, (string)$job['id'])) {
                    $owned[] = ['name' => $snap['name'], 'time' => $snap['time']];
                }
            }
            $retention = Retention::plan($owned, $job['versions']['keep'] ?? [], $protect);
            foreach ($retention['destroy'] as $name) {
                // Recheck ownership immediately before every exact, nonrecursive destroy.
                self::assertOwner($ctx['ssh'], $item['target'], $job, $item['source']);
                $fresh = self::snapshots($ctx['ssh'], $item['target']);
                self::findOwn($fresh, $name, (string)$job['id']);
                self::ok($ctx['ssh'], ['zfs', 'destroy', $name]);
                $log('Ziel-Snapshot gelöscht: ' . $name);
            }
        }
    }

    private static function inspect(array $job, array $plan): array
    {
        $ssh = $job['target']['ssh'] ?? null;
        $target = (string)($job['target']['dataset'] ?? '');
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:\- /]*$~', $target) || !str_contains($target, '/')
            || str_contains($target, '//') || str_ends_with($target, '/')) {
            throw new UserError('Replikationsziel muss ein Dataset unter einem Pool sein');
        }
        $sourceRoot = (string)$job['source']['dataset'];
        if ($ssh === null && ($target === $sourceRoot || str_starts_with($target, $sourceRoot . '/')
            || str_starts_with($sourceRoot, $target . '/'))) {
            throw new UserError('Quelle und Replikationsziel dürfen nicht ineinander liegen');
        }
        $pool = explode('/', $target)[0];
        $poolRows = Exec::rows(self::ok($ssh, ['zpool', 'list', '-Hp', '-o', 'name,health,free', $pool]), ['name', 'health', 'free']);
        if (count($poolRows) !== 1 || $poolRows[0]['health'] !== 'ONLINE') {
            throw new UserError('Ziel-Pool ist nicht ONLINE: ' . $pool);
        }
        $inventory = [];
        foreach (Exec::rows(self::ok($ssh, ['zfs', 'list', '-Hp', '-r', '-t', 'filesystem,volume', '-o', 'name,type,mounted,available', $pool]),
            ['name', 'type', 'mounted', 'available']) as $row) {
            $inventory[$row['name']] = $row;
        }
        if (($inventory[$pool]['mounted'] ?? 'no') !== 'yes') {
            throw new UserError('Ziel-Pool-Root ist nicht gemountet: ' . $pool);
        }
        $ancestor = $target;
        while (!isset($inventory[$ancestor])) {
            $ancestor = self::parent($ancestor);
        }
        $available = min((int)$poolRows[0]['free'], (int)$inventory[$ancestor]['available']);
        $items = [];
        $planned = [];
        $planItems = $plan['items'] ?? [];
        usort($planItems, static fn($a, $b) => substr_count($a['name'], '/') <=> substr_count($b['name'], '/') ?: strcmp($a['name'], $b['name']));
        foreach ($planItems as $entry) {
            $source = (string)$entry['name'];
            if ($source !== $sourceRoot && !str_starts_with($source, $sourceRoot . '/')) {
                throw new UserError('Dataset außerhalb der Replikationsquelle');
            }
            $rel = $source === $sourceRoot ? '' : substr($source, strlen($sourceRoot) + 1);
            $dest = $target . ($rel === '' ? '' : '/' . $rel);
            if (!isset($inventory[self::parent($dest)]) && !isset($planned[self::parent($dest)])) {
                throw new UserError('Ziel-Eltern-Dataset fehlt: ' . self::parent($dest));
            }
            $properties = self::properties(null, $source, ['encryption', 'type']);
            $snapshots = self::snapshots(null, $source);
            $destSnaps = isset($inventory[$dest]) ? self::snapshots($ssh, $dest) : [];
            $capacityAncestor = $dest;
            while (!isset($inventory[$capacityAncestor])) {
                $capacityAncestor = self::parent($capacityAncestor);
            }
            $item = ['job' => (string)$job['id'], 'source' => $source, 'target' => $dest, 'snapshots' => $snapshots,
                'targetSnapshots' => $destSnaps, 'encrypted' => ($properties['encryption']['value'] ?? 'off') !== 'off'
                    && ($properties['encryption']['value'] ?? '-') !== '-',
                'type' => $properties['type']['value'] ?? 'filesystem', 'base' => null, 'resume' => null,
                'available' => min($available, (int)$inventory[$capacityAncestor]['available'])];
            if (isset($inventory[$dest])) {
                $props = self::properties($ssh, $dest, [self::OWNER, self::SOURCE, 'receive_resume_token']);
                $token = $props['receive_resume_token']['value'] ?? '-';
                $marker = self::marker($ssh, $dest);
                $owner = self::hasOwner($props, $job, $source);
                if ($token !== '-' && $token !== '') {
                    $item['resume'] = self::resume($item, $token, (string)$job['id'], $marker);
                    $pending = $item['resume']['snapshot'];
                    if (!$owner && !self::validMarker($marker, $job, $item, $pending)) {
                        throw new UserError('Fremder unvollständiger Empfang: ' . $dest);
                    }
                    if ($marker !== null && !self::validMarker($marker, $job, $item, $pending)) {
                        throw new UserError('Empfangs-Metadaten gehören nicht zu diesem Stream: ' . $dest);
                    }
                } elseif (!$owner) {
                    throw new UserError('Vorhandenes Ziel gehört nicht ausdrücklich diesem Job und dieser Quelle: ' . $dest);
                }
                $item['base'] = self::common($snapshots, $destSnaps, (string)$job['id']);
                if ($item['resume'] === null) {
                    if ($item['base'] === null) {
                        throw new UserError('Vorhandenes Ziel ohne eigenen gemeinsamen GUID-Snapshot: ' . $dest);
                    }
                    $latest = $destSnaps ? $destSnaps[count($destSnaps) - 1] : null;
                    if (($latest['guid'] ?? null) !== $item['base']['guid']) {
                        throw new UserError('Ziel enthält neuere oder fremde Snapshots; kein erzwungenes Rollback: ' . $dest);
                    }
                    $baseName = substr($latest['name'], strpos($latest['name'], '@') + 1);
                    $written = self::properties($ssh, $dest, ['written@' . $baseName]);
                    if (($written['written@' . $baseName]['value'] ?? '-') !== '0') {
                        throw new UserError('Ziel wurde seit dem gemeinsamen Snapshot verändert: ' . $dest);
                    }
                }
            }
            $items[] = $item;
            $planned[$dest] = true;
        }
        if (!$items) {
            throw new UserError('Keine Datasets für Replikation ausgewählt');
        }
        return ['ssh' => $ssh, 'items' => $items, 'available' => $available];
    }

    private static function snapshots(?array $ssh, string $dataset): array
    {
        $out = self::ok($ssh, ['zfs', 'list', '-Hp', '-r', '-d', '1', '-t', 'snapshot', '-o', 'name,guid,creation', '-s', 'createtxg', $dataset]);
        $owners = [];
        $props = self::ok($ssh, ['zfs', 'get', '-Hp', '-r', '-d', '1', '-t', 'snapshot', '-o', 'name,value,source', self::OWNER, $dataset]);
        foreach (Exec::rows($props, ['name', 'value', 'source']) as $p) {
            $owners[$p['name']] = $p;
        }
        $result = [];
        foreach (Exec::rows($out, ['name', 'guid', 'time']) as $snap) {
            if (!str_starts_with($snap['name'], $dataset . '@')) {
                continue;
            }
            $result[] = ['name' => $snap['name'], 'guid' => self::guid($snap['guid']), 'time' => (int)$snap['time'],
                'job' => ($owners[$snap['name']]['source'] ?? '') === 'local' ? ($owners[$snap['name']]['value'] ?? null) : null];
        }
        return $result;
    }

    private static function properties(?array $ssh, string $dataset, array $names): array
    {
        $out = self::ok($ssh, ['zfs', 'get', '-Hp', '-o', 'property,value,source', implode(',', $names), $dataset]);
        $props = [];
        foreach (Exec::rows($out, ['property', 'value', 'source']) as $row) {
            $props[$row['property']] = $row;
        }
        return $props;
    }

    private static function owned(array $snap, string $job): bool
    {
        $name = substr($snap['name'], strpos($snap['name'], '@') + 1);
        return $snap['job'] === $job && Zfs::isOwn($name, $job);
    }

    private static function findOwn(array $snapshots, string $name, string $job): array
    {
        foreach ($snapshots as $snap) {
            if ($snap['name'] === $name && self::owned($snap, $job)) {
                return $snap;
            }
        }
        throw new UserError('Eigener Snapshot mit lokaler Job-Property fehlt: ' . $name);
    }

    private static function common(array $source, array $target, string $job): ?array
    {
        $guids = [];
        foreach ($target as $snap) {
            if (self::owned($snap, $job)) {
                $guids[$snap['guid']] = true;
            }
        }
        $common = null;
        foreach ($source as $snap) {
            if (self::owned($snap, $job) && isset($guids[$snap['guid']])) {
                $common = $snap;
            }
        }
        return $common;
    }

    private static function resume(array $item, string $token, string $job, ?array $marker): array
    {
        // OpenZFS prints decoded token fields to stderr on some releases.
        $decoded = Exec::run(['zfs', 'send', '-nvt', $token], 120);
        if ($decoded['code'] !== 0) {
            throw new UserError('Resume-Token ist auf der Quelle nicht fortsetzbar: ' . $decoded['err']);
        }
        $text = $decoded['out'] . "\n" . $decoded['err'];
        if (!preg_match('~^\s*toname\s*=\s*(.*?)\s*$~m', $text, $name)
            || !preg_match('~^\s*toguid\s*=\s*(0x[0-9a-fA-F]+|[0-9]+)\s*$~m', $text, $guid)) {
            throw new UserError('Resume-Token enthält keine eindeutige Quellidentität');
        }
        $snapshot = null;
        foreach ($item['snapshots'] as $candidate) {
            if ($candidate['name'] === $name[1] && $candidate['guid'] === self::guid($guid[1])) {
                $snapshot = $candidate;
            }
        }
        if ($snapshot === null || (!self::owned($snapshot, $job)
            && !self::validMarker($marker, ['id' => $job], $item, $snapshot))) {
            throw new UserError('Resume-Token gehört zu einer anderen Quelle');
        }
        $base = null;
        if (preg_match('~^\s*fromguid\s*=\s*(0x[0-9a-fA-F]+|[0-9]+)\s*$~m', $text, $from) && self::guid($from[1]) !== '0') {
            foreach ($item['snapshots'] as $candidate) {
                if ($candidate['guid'] === self::guid($from[1])
                    && (self::owned($candidate, $job) || self::validMarker($marker, ['id' => $job], $item, $candidate))) {
                    foreach ($item['targetSnapshots'] as $target) {
                        if ($target['guid'] === $candidate['guid']
                            && (self::owned($target, $job) || self::validMarker($marker, ['id' => $job], $item, $candidate))) {
                            $base = $candidate;
                        }
                    }
                }
            }
            if ($base === null) {
                throw new UserError('Eigene gemeinsame Resume-Basis fehlt');
            }
        }
        return ['token' => $token, 'snapshot' => $snapshot, 'base' => $base, 'marker' => $marker];
    }

    private static function sendCommand(array $item, string $snapshot, bool $estimate): array
    {
        $cmd = ['zfs', 'send', $estimate ? '-nP' : '-vP', '-p'];
        if ($item['encrypted']) {
            $cmd[] = '-w';
        }
        if ($item['base'] !== null) {
            array_push($cmd, '-I', $item['base']['name']);
        }
        $cmd[] = $snapshot;
        return $cmd;
    }

    private static function estimate(array $item, ?string $snapshot): int
    {
        $latest = null;
        foreach ($item['snapshots'] as $snap) {
            if (self::owned($snap, $item['job'])) {
                $latest = $snap;
            }
        }
        if ($snapshot !== null) {
            foreach ($item['snapshots'] as $snap) {
                if ($snap['name'] === $item['source'] . '@' . $snapshot) {
                    $latest = $snap;
                }
            }
        }
        $bytes = 0;
        if ($item['resume'] !== null) {
            $r = Exec::run(['zfs', 'send', '-nP', '-t', $item['resume']['token']], 120);
            if ($r['code'] !== 0) {
                throw new UserError('Resume-Schätzung fehlgeschlagen: ' . $r['err']);
            }
            $bytes += self::estimatedSize($r['out'] . "\n" . $r['err']);
            // The newer snapshot will be sent incrementally after the interrupted one.
            $item['base'] = $item['resume']['snapshot'];
        }
        if ($latest !== null && ($item['base']['guid'] ?? null) !== $latest['guid']) {
            $r = Exec::run(self::sendCommand($item, $latest['name'], true), 120);
            if ($r['code'] !== 0) {
                throw new UserError('Send-Schätzung fehlgeschlagen: ' . $r['err']);
            }
            $bytes += self::estimatedSize($r['out'] . "\n" . $r['err']);
        }
        if ($snapshot === null) {
            $property = $latest === null ? 'referenced' : 'written@' . substr($latest['name'], strpos($latest['name'], '@') + 1);
            $props = self::properties(null, $item['source'], [$property]);
            $value = $props[$property]['value'] ?? '-';
            if (!ctype_digit($value)) {
                throw new UserError('Quellgröße nicht bestimmbar: ' . $item['source']);
            }
            $bytes += (int)$value;
        }
        return $bytes;
    }

    private static function estimatedSize(string $text): int
    {
        if (preg_match('~^size\s+([0-9]+)\s*$~m', $text, $match)) {
            return (int)$match[1];
        }
        throw new UserError('ZFS liefert keine maschinenlesbare Stream-Größe');
    }

    private static function pipe(array $job, array $item, array $send, array $snapshot, ?string $meter, callable $log, ?callable $cancel): array
    {
        self::cancel($cancel);
        $ssh = $job['target']['ssh'] ?? null;
        $parent = self::parent($item['target']);
        $marker = self::markerName($item['target']);
        $manifest = $item['resume']['marker'] ?? ['job' => $job['id'], 'source' => $item['source'], 'target' => $item['target'],
            'snapshot' => $snapshot['name'], 'guid' => $snapshot['guid'],
            'baseGuid' => ($item['resume']['base'] ?? $item['base'])['guid'] ?? null];
        $pending = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        // Written before receive: even an incomplete first full stream has an explicit local owner.
        self::ok($ssh, ['zfs', 'set', $marker . '=' . $pending, $parent]);
        $receive = ['zfs', 'receive', '-s', '-u'];
        if ($item['resume'] === null) {
            array_push($receive, '-o', self::OWNER . '=' . $job['id'],
                '-o', self::SOURCE . '=' . $item['source'], '-o', 'readonly=on');
            if ($item['type'] === 'filesystem') {
                array_push($receive, '-o', 'mountpoint=none', '-o', 'canmount=off');
            }
        }
        $receive[] = $item['target'];
        $pipeline = Remote::quote($send);
        if ($meter !== null) {
            $pipeline .= ' | ' . Remote::quote([$meter, '-f', '-n', '-b'])
                . ' 2> >(while IFS= read -r n; do printf "ZBG_BYTES=%s\\n" "$n" >&2; done)';
        } else {
            // Coreutils fallback counts the actual stream, including small sends with no progress tick.
            $pipeline .= ' | tee >(count=$(wc -c); printf "ZBG_BYTES=%s\\n" "$count" >&2)';
        }
        $pipeline .= ' | ' . Remote::quote(Remote::command($ssh, $receive));
        $pipeline .= '; status=$?; wait; exit "$status"';
        $bytes = 0;
        $measured = false;
        $streamLog = static function (string $line) use ($log, &$bytes, &$measured): void {
            if (preg_match('~^ZBG_BYTES=([0-9]+)$~', trim($line), $match)) {
                $bytes = max($bytes, (int)$match[1]);
                $measured = true;
                $log('Übertragen: ' . $bytes . ' Bytes');
            } else {
                if (preg_match('~^\d{2}:\d{2}:\d{2}\s+([0-9]+)\s+~', $line, $match)) {
                    $bytes = max($bytes, (int)$match[1]);
                    $measured = true;
                }
                $log($line);
            }
        };
        $run = Exec::stream(['bash', '-o', 'pipefail', '-c', $pipeline], $streamLog, $cancel);
        if ($run['code'] !== 0) {
            throw new \RuntimeException('ZFS-Replikation fehlgeschlagen (' . $run['code'] . '): ' . $run['err']);
        }
        return ['bytes' => $bytes, 'measured' => $measured];
    }

    private static function finish(array $job, array $item, array $wanted, callable $log): void
    {
        $ssh = $job['target']['ssh'] ?? null;
        $target = self::snapshots($ssh, $item['target']);
        $found = false;
        $sourceGuids = [];
        foreach ($item['snapshots'] as $snap) {
            if (self::owned($snap, (string)$job['id'])) {
                $sourceGuids[$snap['guid']] = true;
            }
        }
        foreach ($target as $snap) {
            if ($snap['guid'] === $wanted['guid']) {
                $found = true;
            }
            // -I may carry foreign intermediate snapshots; never adopt those.
            $name = substr($snap['name'], strpos($snap['name'], '@') + 1);
            if (isset($sourceGuids[$snap['guid']]) && Zfs::isOwn($name, (string)$job['id'])) {
                self::ok($ssh, ['zfs', 'set', self::OWNER . '=' . $job['id'], $snap['name']]);
            }
        }
        if (!$found) {
            throw new \RuntimeException('Empfangener Snapshot-GUID stimmt nicht überein: ' . $item['target']);
        }
        self::ok($ssh, ['zfs', 'set', self::OWNER . '=' . $job['id'], self::SOURCE . '=' . $item['source'], $item['target']]);
        self::assertOwner($ssh, $item['target'], $job, $item['source']);
        self::ok($ssh, ['zfs', 'inherit', self::markerName($item['target']), self::parent($item['target'])]);
        $log('Empfang per GUID bestätigt: ' . $item['target']);
    }

    private static function hasOwner(array $props, array $job, string $source): bool
    {
        return ($props[self::OWNER]['source'] ?? '') === 'local'
            && ($props[self::OWNER]['value'] ?? '') === (string)$job['id']
            && ($props[self::SOURCE]['source'] ?? '') === 'local'
            && ($props[self::SOURCE]['value'] ?? '') === $source;
    }

    private static function assertOwner(?array $ssh, string $target, array $job, string $source): void
    {
        if (!self::hasOwner(self::properties($ssh, $target, [self::OWNER, self::SOURCE]), $job, $source)) {
            throw new UserError('Lokale Ziel-Eigentümerschaft fehlt: ' . $target);
        }
    }

    private static function markerName(string $target): string
    {
        return 'zbg:recv-' . substr(hash('sha256', $target), 0, 24);
    }

    private static function marker(?array $ssh, string $target): ?array
    {
        $name = self::markerName($target);
        $prop = self::properties($ssh, self::parent($target), [$name])[$name] ?? [];
        if (($prop['source'] ?? '') !== 'local') {
            return null;
        }
        $value = json_decode($prop['value'], true);
        return is_array($value) ? $value : null;
    }

    private static function validMarker(?array $marker, array $job, array $item, array $snapshot): bool
    {
        if ($marker === null || ($marker['job'] ?? '') !== (string)$job['id']
            || ($marker['source'] ?? '') !== $item['source'] || ($marker['target'] ?? '') !== $item['target']) {
            return false;
        }
        $start = -1;
        $startFound = ($marker['baseGuid'] ?? null) === null;
        $current = $end = null;
        foreach ($item['snapshots'] as $index => $candidate) {
            if ($candidate['guid'] === ($marker['baseGuid'] ?? null)) {
                $start = $index;
                $startFound = true;
            }
            if ($candidate['guid'] === $snapshot['guid'] && $candidate['name'] === $snapshot['name']) {
                $current = $index;
            }
            if ($candidate['name'] === ($marker['snapshot'] ?? '')
                && $candidate['guid'] === ($marker['guid'] ?? '') && self::owned($candidate, (string)$job['id'])) {
                $end = $index;
            }
        }
        return $startFound && $current !== null && $end !== null
            && (($marker['baseGuid'] ?? null) === null ? $current === $end : ($current >= $start && $current <= $end));
    }

    private static function protection(array $item): array
    {
        $protect = [];
        foreach ([$item['base'], $item['resume']['snapshot'] ?? null, $item['resume']['base'] ?? null] as $snap) {
            if ($snap !== null) {
                $protect[] = $snap['name'];
            }
        }
        if (isset($item['resume']['marker']['snapshot'])) {
            $protect[] = $item['resume']['marker']['snapshot'];
        }
        return array_values(array_unique($protect));
    }

    private static function space(array $ctx, int $bytes): void
    {
        if ($bytes > $ctx['available']) {
            throw new UserError('Zu wenig Platz im Ziel-Pool: benötigt ca. ' . $bytes . ', verfügbar ' . $ctx['available'] . ' Bytes');
        }
    }

    private static function parent(string $dataset): string
    {
        $position = strrpos($dataset, '/');
        if ($position === false) {
            throw new UserError('Kein Eltern-Dataset: ' . $dataset);
        }
        return substr($dataset, 0, $position);
    }

    /** GUIDs exceed PHP_INT_MAX: compare decimal strings without float conversion. */
    private static function guid(string $value): string
    {
        if (!str_starts_with(strtolower($value), '0x')) {
            return ltrim($value, '0') ?: '0';
        }
        $decimal = '0';
        foreach (str_split(substr(strtolower($value), 2)) as $digit) {
            $carry = (int)hexdec($digit);
            $next = '';
            for ($i = strlen($decimal) - 1; $i >= 0; --$i) {
                $n = ((int)$decimal[$i]) * 16 + $carry;
                $next = (string)($n % 10) . $next;
                $carry = intdiv($n, 10);
            }
            while ($carry > 0) {
                $next = (string)($carry % 10) . $next;
                $carry = intdiv($carry, 10);
            }
            $decimal = ltrim($next, '0') ?: '0';
        }
        return $decimal;
    }

    private static function cancel(?callable $cancel): void
    {
        if ($cancel !== null && $cancel()) {
            throw new \RuntimeException('Replikation abgebrochen');
        }
    }

    private static function ok(?array $ssh, array $cmd): string
    {
        return Exec::ok(Remote::command($ssh, $cmd), 120);
    }
}
