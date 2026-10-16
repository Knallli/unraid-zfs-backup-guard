<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Pfadprüfung: alles muss unter /mnt (Ziele) bzw. /boot oder /mnt (Skripte, Schlüssel) liegen.
 */
final class Paths
{
    public const TARGET_ROOT = '/mnt/';

    public static function clean(string $p): string
    {
        if ($p === '' || $p[0] !== '/' || str_contains($p, "\0")) {
            throw new UserError('Absoluter Pfad erwartet');
        }
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new UserError('„..“ ist in Pfaden nicht erlaubt');
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out);
    }

    public static function normalizeTarget(string $p): string
    {
        $p = self::clean(trim($p));
        if (!str_starts_with($p . '/', self::TARGET_ROOT) || substr_count($p, '/') < 3) {
            throw new UserError('Ziel muss unterhalb von /mnt/<share>/ liegen');
        }
        return $p;
    }

    public static function normalizeScript(string $p): string
    {
        $p = self::clean(trim($p));
        if (!str_starts_with($p, '/boot/') && !str_starts_with($p, '/mnt/') && !str_starts_with($p, '/root/.ssh/')) {
            throw new UserError('Datei muss unter /boot, /mnt oder /root/.ssh liegen');
        }
        return $p;
    }

    /** Liegt $child (realpath) innerhalb von $root (realpath)? */
    /** Pfad plus die realen Pool-/Disk-Pfade, hinter denen ein /mnt/user[0]/<share>-Pfad liegt. */
    public static function aliases(string $path): array
    {
        $out = [$path];
        if (preg_match('~^/mnt/user0?/([^/]+)(/.*)?$~', $path, $m)) {
            foreach (glob('/mnt/*/' . addcslashes($m[1], '*?[]\\'), GLOB_ONLYDIR) ?: [] as $dir) {
                if (!preg_match('~^/mnt/user0?/~', $dir . '/')) {
                    $out[] = $dir . ($m[2] ?? '');
                }
            }
        }
        return $out;
    }

    public static function within(string $root, string $child): bool
    {
        $root = rtrim($root, '/') . '/';
        return $child . '/' === $root || str_starts_with($child . '/', $root);
    }

    /** Mountpunkt aus /proc/mounts, der den Pfad enthält (längstes Präfix). */
    public static function mountFor(string $path): ?array
    {
        $best = null;
        foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$dev, $mp, $type] = array_pad(explode(' ', $line), 3, '');
            $mp = str_replace('\\040', ' ', $mp);
            if (self::within($mp, $path) && ($best === null || strlen($mp) > strlen($best['mountpoint']))) {
                $best = ['device' => $dev, 'mountpoint' => $mp, 'type' => $type];
            }
        }
        return $best;
    }

    /**
     * Live-Prüfung eines rsync-Ziels für den Editor.
     * @return array{path:string,exists:bool,mounted:bool,writable:bool,free:int|null,fs:string|null,checks:array}
     */
    public static function checkTarget(string $path, ?string $sourceDataset): array
    {
        $path = self::normalizeTarget($path);
        $probe = $path;
        while (!is_dir($probe) && $probe !== '/') {
            $probe = dirname($probe);
        }
        $real = realpath($probe) ?: $probe;
        $mount = self::mountFor($real);
        $share = implode('/', array_slice(explode('/', $path), 0, 4));
        $arrayUp = (self::mountFor('/mnt/user')['mountpoint'] ?? '') === '/mnt/user';
        $mounted = $mount !== null && $mount['mountpoint'] !== '/' && (!str_starts_with($path, '/mnt/user/') || $arrayUp);
        $free = $mounted ? (int)@disk_free_space($real) : null;
        $checks = [];
        $checks[] = ['ok' => $mounted, 'label' => $mounted ? "Gemountet ({$mount['type']} auf {$mount['mountpoint']})" : 'Ziel ist nicht gemountet – Array gestartet?'];
        $checks[] = ['ok' => is_dir($share), 'label' => is_dir($share) ? "Share $share vorhanden" : "Share $share existiert nicht"];
        $writable = $mounted && is_writable($real);
        $checks[] = ['ok' => $writable, 'label' => $writable ? 'Beschreibbar' : 'Nicht beschreibbar'];
        if ($sourceDataset) {
            $ds = Zfs::dataset($sourceDataset);
            $srcPool = $ds['pool'];
            $inside = $ds['path'] !== null && self::within($ds['path'], $real);
            $checks[] = ['ok' => !$inside, 'label' => $inside ? 'Ziel liegt in der Quelle!' : 'Ziel liegt außerhalb der Quelle'];
            // Gleicher Pool? Direkt (/mnt/<pool>/…, ZFS-Mount) oder über einen Share, der den Pool nutzt
            $samePool = false;
            $why = '';
            if ($mount && $mount['type'] === 'zfs' && explode('/', $mount['device'])[0] === $srcPool) {
                [$samePool, $why] = [true, "liegt direkt auf Pool $srcPool"];
            }
            $share = self::shareInfo($path);
            if (!$samePool && $share && in_array($share['useCache'] ?? 'no', ['yes', 'only', 'prefer'], true)
                && in_array($srcPool, [$share['pool'] ?? null, $share['pool2'] ?? null], true)) {
                [$samePool, $why] = [true, "Share „{$share['share']}“ nutzt Pool $srcPool (Cache: {$share['useCache']})"];
            }
            $checks[] = ['ok' => !$samePool, 'warn' => $samePool, 'label' => $samePool
                ? "Gleicher Pool wie die Quelle – $why. Schützt nicht vor Pool-Ausfall"
                : ($share ? "Share „{$share['share']}“ liegt nicht auf Pool $srcPool" : 'Anderes Gerät als die Quelle')];
            if ($free !== null) {
                $enough = $free > $ds['refer'];
                $checks[] = ['ok' => $enough, 'warn' => !$enough, 'label' => 'Freier Platz ' . self::human($free) . ' (Quelle ' . self::human($ds['used']) . ')'];
            }
        }
        return [
            'path' => $path, 'exists' => is_dir($path), 'mounted' => $mounted, 'writable' => $writable,
            'free' => $free, 'fs' => $mount['type'] ?? null, 'checks' => $checks,
        ];
    }

    /** Ungefähre Übersetzung von /mnt/user/<share>/… auf Unraid-Share-Einstellungen. */
    private static function shareInfo(string $path): ?array
    {
        if (!preg_match('~^/mnt/user/([^/]+)~', $path, $m)) {
            return null;
        }
        $cfg = @parse_ini_file('/boot/config/shares/' . $m[1] . '.cfg') ?: [];
        if (!$cfg) {
            return ['share' => $m[1]];
        }
        return [
            'share' => $m[1],
            'useCache' => $cfg['shareUseCache'] ?? 'no',
            'pool' => $cfg['shareCachePool'] ?? null,
            'pool2' => $cfg['shareCachePool2'] ?? null,
        ];
    }

    public static function human(int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $v = (float)$b;
        while ($v >= 1024 && $i < count($u) - 1) {
            $v /= 1024;
            $i++;
        }
        return number_format($v, $v < 10 && $i > 0 ? 1 : 0, ',', '.') . ' ' . $u[$i];
    }
}
