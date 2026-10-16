<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Job-Modell (PLAN 3.1): Normalisierung mit Defaults und strikter Validierung.
 */
final class Job
{
    public const KEEP_SNAP = ['hourly', 'daily', 'weekly', 'monthly', 'yearly'];
    public const KEEP_VERS = ['daily', 'weekly', 'monthly'];

    public static function defaults(): array
    {
        return [
            'id' => '',
            'name' => '',
            'enabled' => true,
            'source' => ['dataset' => '', 'recursive' => true, 'exclude' => []],
            'method' => 'rsync',
            'target' => ['path' => '', 'mode' => 'versioned', 'dataset' => '', 'ssh' => null, 'adoptExisting' => false],
            'schedule' => '0 2 * * *',
            'verify' => false,
            'system' => false,
            'snapshots' => ['keep' => ['hourly' => 0, 'daily' => 7, 'weekly' => 4, 'monthly' => 3, 'yearly' => 0]],
            'versions' => ['keep' => ['daily' => 14, 'weekly' => 8, 'monthly' => 6]],
            'consistency' => ['mode' => 'crash', 'containers' => []],
            'hooks' => ['pre' => null, 'post' => null],
            'notify' => 'errors',
            'maxAgeHours' => 30,
            'healthcheckUrl' => null,
        ];
    }

    /** @param bool $checkSystem Dataset gegen zfs list prüfen (in Tests abschaltbar) */
    public static function normalize(array $in, bool $checkSystem = true): array
    {
        $d = self::defaults();
        $j = $d;

        $j['name'] = trim((string)($in['name'] ?? ''));
        if ($j['name'] === '' || mb_strlen($j['name']) > 80) {
            throw new UserError('Name fehlt oder ist zu lang (max. 80 Zeichen)');
        }
        $j['id'] = (string)($in['id'] ?? '') ?: self::slug($j['name']);
        if (!preg_match('~^[a-z0-9][a-z0-9-]{0,39}$~', $j['id'])) {
            throw new UserError('ID darf nur a–z, 0–9 und „-“ enthalten (max. 40)');
        }
        $j['enabled'] = (bool)($in['enabled'] ?? true);

        $src = $in['source'] ?? [];
        $j['source']['dataset'] = trim((string)($src['dataset'] ?? ''));
        if ($j['source']['dataset'] === '') {
            throw new UserError('Quell-Dataset fehlt');
        }
        if ($checkSystem) {
            Zfs::assertDataset($j['source']['dataset']);
        }
        $j['source']['recursive'] = (bool)($src['recursive'] ?? true);
        $ex = [];
        foreach ((array)($src['exclude'] ?? []) as $e) {
            $e = (string)$e;
            if (!str_starts_with($e, $j['source']['dataset'] . '/')) {
                throw new UserError("Ausschluss $e liegt nicht unter der Quelle");
            }
            $ex[] = $e;
        }
        $j['source']['exclude'] = array_values(array_unique($ex));

        $j['method'] = self::oneOf($in['method'] ?? 'rsync', ['rsync', 'zfs'], 'Methode');
        $t = $in['target'] ?? [];
        if ($j['method'] === 'rsync') {
            $j['target']['path'] = Paths::normalizeTarget((string)($t['path'] ?? ''));
            $j['target']['mode'] = self::oneOf($t['mode'] ?? 'versioned', ['mirror', 'versioned'], 'Zielmodus');
            $j['target']['adoptExisting'] = $j['target']['mode'] === 'mirror' && (bool)($t['adoptExisting'] ?? false);
            $sourceMount = self::mountOf($j['source']['dataset'], $checkSystem);
            foreach (self::ssh($t['ssh'] ?? null) !== null ? [] : ($checkSystem ? Paths::aliases($j['target']['path']) : [$j['target']['path']]) as $alias) {
                if (str_starts_with($alias . '/', $sourceMount . '/')) {
                    throw new UserError('Das Ziel liegt innerhalb der Quelle');
                }
            }
        } else {
            $j['target']['dataset'] = trim((string)($t['dataset'] ?? ''));
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9_.:\-/]*$~', $j['target']['dataset'])) {
                throw new UserError('Ziel-Dataset ungültig');
            }
            if ($j['target']['dataset'] === $j['source']['dataset']
                || str_starts_with($j['target']['dataset'], $j['source']['dataset'] . '/')) {
                throw new UserError('Ziel-Dataset darf nicht in der Quelle liegen');
            }
        }
        $j['target']['ssh'] = self::ssh($t['ssh'] ?? null);

        $j['schedule'] = trim((string)($in['schedule'] ?? $d['schedule']));
        if (!Cron::valid($j['schedule'])) {
            throw new UserError('Zeitplan ist kein gültiger Cron-Ausdruck');
        }
        $j['schedule'] = implode(' ', preg_split('~\s+~', $j['schedule']));
        $j['verify'] = (bool)($in['verify'] ?? false);
        $j['system'] = (bool)($in['system'] ?? false);
        if ($j['system']) {
            if ($j['method'] !== 'rsync') {
                throw new UserError('Systemdisk-Backup nur mit rsync-Methode');
            }
            if ($checkSystem && !Zfs::dataset($j['source']['dataset'])['boot']) {
                throw new UserError('Systemdisk-Backup nur für das Unraid-Boot-Dataset (/boot)');
            }
        }
        foreach (self::KEEP_SNAP as $k) {
            $j['snapshots']['keep'][$k] = self::int($in['snapshots']['keep'][$k] ?? $d['snapshots']['keep'][$k], 0, 1000);
        }
        foreach (self::KEEP_VERS as $k) {
            $j['versions']['keep'][$k] = self::int($in['versions']['keep'][$k] ?? $d['versions']['keep'][$k], 0, 1000);
        }
        $c = $in['consistency'] ?? [];
        $j['consistency']['mode'] = self::oneOf($c['mode'] ?? 'crash', ['crash', 'pause', 'stop'], 'Konsistenzmodus');
        $j['consistency']['containers'] = array_values(array_filter(array_map('strval', (array)($c['containers'] ?? [])),
            static fn($n) => preg_match('~^[A-Za-z0-9][A-Za-z0-9_.\-]*$~', $n) === 1));

        foreach (['pre', 'post'] as $h) {
            $v = $in['hooks'][$h] ?? null;
            $j['hooks'][$h] = ($v === null || $v === '') ? null : Paths::normalizeScript((string)$v);
        }
        $j['notify'] = self::oneOf($in['notify'] ?? 'errors', ['all', 'errors', 'none'], 'Benachrichtigung');
        $j['maxAgeHours'] = self::int($in['maxAgeHours'] ?? 30, 1, 24 * 90);
        $url = trim((string)($in['healthcheckUrl'] ?? ''));
        if ($url !== '' && !preg_match('~^https?://[^\s]+$~', $url)) {
            throw new UserError('Healthcheck-URL ungültig');
        }
        $j['healthcheckUrl'] = $url ?: null;
        return $j;
    }

    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        return substr(trim((string)preg_replace('~[^a-z0-9]+~', '-', $s), '-'), 0, 40) ?: 'job';
    }

    private static function mountOf(string $dataset, bool $checkSystem): string
    {
        if (!$checkSystem) {
            return '/mnt/' . $dataset;
        }
        $path = Zfs::dataset($dataset)['path'];
        return $path ? rtrim($path, '/') : '/nonexistent';
    }

    private static function ssh(mixed $s): ?array
    {
        if (!is_array($s) || trim((string)($s['host'] ?? '')) === '') {
            return null;
        }
        $host = trim((string)$s['host']);
        $user = trim((string)($s['user'] ?? 'root'));
        if (!preg_match('~^[A-Za-z0-9.\-:\[\]]+$~', $host) || !preg_match('~^[a-z_][a-z0-9_\-]*$~', $user)) {
            throw new UserError('SSH-Host oder -Benutzer ungültig');
        }
        $key = trim((string)($s['key'] ?? ''));
        $knownHosts = trim((string)($s['knownHosts'] ?? ''));
        return [
            'host' => $host,
            'user' => $user,
            'port' => self::int($s['port'] ?? 22, 1, 65535),
            'key' => $key === '' ? null : Paths::normalizeScript($key),
            'knownHosts' => $knownHosts === '' ? null : Paths::normalizeScript($knownHosts),
        ];
    }

    private static function oneOf(mixed $v, array $allowed, string $what): string
    {
        if (!in_array($v, $allowed, true)) {
            throw new UserError("$what ungültig");
        }
        return $v;
    }

    private static function int(mixed $v, int $min, int $max): int
    {
        if (!is_numeric($v)) {
            throw new UserError('Zahl erwartet');
        }
        return max($min, min($max, (int)$v));
    }
}
