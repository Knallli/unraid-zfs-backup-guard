<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Nur-lesender Verzeichnisbrowser innerhalb eines Snapshots (Pfad-Whitelist per realpath).
 */
final class Browse
{
    public const MAX_ENTRIES = 2000;

    public static function list(string $dataset, string $snapshot, string $rel): array
    {
        $root = realpath(Zfs::snapshotDir($dataset, $snapshot));
        if ($root === false) {
            throw new UserError('Snapshot-Verzeichnis nicht lesbar');
        }
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel !== '' && preg_match('~(^|/)\.\.(/|$)~', $rel)) {
            throw new UserError('Ungültiger Pfad');
        }
        $dir = realpath($root . ($rel === '' ? '' : '/' . $rel));
        if ($dir === false || !Paths::within($root, $dir) || !is_dir($dir)) {
            throw new UserError('Pfad nicht gefunden');
        }
        $entries = [];
        $truncated = false;
        $h = opendir($dir);
        while (($name = readdir($h)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (count($entries) >= self::MAX_ENTRIES) {
                $truncated = true;
                break;
            }
            $p = $dir . '/' . $name;
            $st = @lstat($p);
            $entries[] = [
                'name' => $name,
                'type' => is_link($p) ? 'link' : (is_dir($p) ? 'dir' : 'file'),
                'size' => $st ? (int)$st['size'] : 0,
                'mtime' => $st ? (int)$st['mtime'] : 0,
            ];
        }
        closedir($h);
        usort($entries, static fn($a, $b) => ($a['type'] !== 'dir') <=> ($b['type'] !== 'dir') ?: strnatcasecmp($a['name'], $b['name']));
        return [
            'dataset' => $dataset,
            'snapshot' => $snapshot,
            'path' => substr($dir, strlen($root)) ?: '/',
            'entries' => $entries,
            'truncated' => $truncated,
        ];
    }
}
