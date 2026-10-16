<?php
declare(strict_types=1);

namespace ZBG;

/** Erfasst Metadaten der Systemdisk(s) (Partitionen, EFI, LUKS-Header) für das Wiederherstellen eines Unraid-Boots. */
final class System
{
    private const EFI_TYPE = 'c12a7328-f81f-11d2-ba4b-00a0c93ec93b';

    /** Gerätenamen der Boot-Disks aus `zpool status -P` (Vdev-Partitionen -> Elterndisk). */
    public static function bootDisks(string $zpoolStatus, ?callable $parentOf = null): array
    {
        $parentOf ??= static fn(string $dev): string => trim(Exec::run(['lsblk', '-no', 'PKNAME', '/dev/' . $dev])['out']);
        $disks = [];
        preg_match_all('~^\s*/dev/(\S+)\s+\S+~m', $zpoolStatus, $m);
        foreach ($m[1] as $dev) {
            $dev = basename($dev);
            $parent = $parentOf($dev);
            $disks[] = $parent !== '' ? basename(strtok($parent, "\n")) : $dev;
        }
        return array_values(array_unique($disks));
    }

    /**
     * Wählt Partitionen aus dem lsblk-JSON (`blockdevices`).
     * @return array{bios:string[],efi:string[],luks:string[]} bios/efi nur auf $bootDisks, luks auf allen Disks (ohne Mapper)
     */
    public static function select(array $blockdevices, array $bootDisks): array
    {
        $out = ['bios' => [], 'efi' => [], 'luks' => []];
        $walk = static function (array $nodes, ?string $disk) use (&$walk, &$out, $bootDisks): void {
            foreach ($nodes as $n) {
                $type = (string)($n['type'] ?? '');
                $name = (string)($n['name'] ?? '');
                $fs = (string)($n['fstype'] ?? '');
                if ($type === 'disk') {
                    $disk = $name;
                }
                if ($type === 'part' || $type === 'disk') {
                    $boot = in_array($disk, $bootDisks, true);
                    $label = (string)($n['partlabel'] ?? '');
                    if ($type === 'part' && $boot && $label === 'BIOS Boot Partition' && self::bytes($n['size'] ?? 0) <= 8 * 1048576) {
                        $out['bios'][] = $name;
                    }
                    if ($type === 'part' && $boot && (strtolower((string)($n['parttype'] ?? '')) === self::EFI_TYPE || ($fs === 'vfat' && $label === 'EFI System Partition'))) {
                        $out['efi'][] = $name;
                    }
                    if ($fs === 'crypto_LUKS') {
                        $out['luks'][] = $name;
                    }
                }
                $walk((array)($n['children'] ?? []), $disk);
            }
        };
        $walk($blockdevices, null);
        return $out;
    }

    private static function bytes(mixed $v): int
    {
        return is_numeric($v) ? (int)$v : 0;
    }

    /** Schreibt alle Artefakte nach $dir (frisches lokales Staging-Verzeichnis) und liefert eine Zusammenfassung. */
    public static function capture(string $dir, callable $log): array
    {
        foreach (['info', 'partitions', 'bios', 'efi', 'luks'] as $sub) {
            mkdir("$dir/$sub", 0700);
        }
        $put = static function (string $file, string $data) use ($dir): void {
            file_put_contents("$dir/$file", $data);
            chmod("$dir/$file", 0600);
        };
        // Optionale Infos: Fehler landen als Text in der Datei und als Warnung im Log
        $info = static function (string $file, array $cmd) use ($put, $log): string {
            $r = Exec::run($cmd);
            if ($r['code'] !== 0) {
                $log('WARNUNG: ' . $cmd[0] . ' fehlgeschlagen: ' . $r['err']);
            }
            $put("info/$file", $r['code'] === 0 ? $r['out'] : "FEHLER ({$r['code']}): {$r['err']}\n");
            return $r['code'] === 0 ? $r['out'] : '';
        };
        if (is_readable('/etc/unraid-version')) {
            $put('info/unraid-version', (string)file_get_contents('/etc/unraid-version'));
        }
        $info('uname.txt', ['uname', '-a']);
        $lsblk = $info('lsblk.json', ['lsblk', '-J', '-b', '-o', 'NAME,PKNAME,PATH,SIZE,TYPE,FSTYPE,LABEL,UUID,PARTUUID,PARTLABEL,PARTTYPE,MOUNTPOINT,MODEL,SERIAL']);
        $info('blkid.txt', ['blkid']);
        $info('efibootmgr.txt', ['efibootmgr', '-v']);
        $status = $info('zpool-status.txt', ['zpool', 'status', '-P']);
        $info('zpool-get.txt', ['zpool', 'get', '-H', 'all']);
        $info('zfs-get.txt', ['zfs', 'get', '-H', '-s', 'local,received', 'all', '-t', 'filesystem,volume']);
        $info('findmnt.json', ['findmnt', '-J']);

        $tree = json_decode($lsblk, true)['blockdevices'] ?? [];
        if ($tree === []) {
            throw new \RuntimeException('lsblk lieferte keine Geräte');
        }
        $pool = Zfs::bootPool();
        $disks = $pool !== null ? self::bootDisks(Exec::ok(['zpool', 'status', '-P', $pool])) : [];
        if (!$disks) {
            $src = trim(Exec::run(['findmnt', '-no', 'SOURCE', '/boot'])['out']);
            if (str_starts_with($src, '/dev/')) {
                $parent = trim(Exec::run(['lsblk', '-no', 'PKNAME', $src])['out']);
                $disks = [$parent !== '' ? basename(strtok($parent, "\n")) : basename($src)];
            }
        }
        if (!$disks) {
            $log('Systemdisk: keine Boot-Disk ermittelt, Partitionsschritte übersprungen');
        }
        $sel = self::select($tree, $disks);
        $flat = [];
        $index = static function (array $nodes) use (&$index, &$flat): void {
            foreach ($nodes as $n) {
                $flat[$n['name']] ??= $n;
                $index((array)($n['children'] ?? []));
            }
        };
        $index($tree);

        foreach ($disks as $d) {
            $dev = '/dev/' . $d;
            Exec::ok(['sgdisk', '--backup=' . "$dir/partitions/$d.sgdisk", $dev]);
            chmod("$dir/partitions/$d.sgdisk", 0600);
            $r = Exec::run(['sfdisk', '--dump', $dev]);
            if ($r['code'] === 0) {
                $put("partitions/$d.sfdisk", $r['out']);
            } else {
                $log('WARNUNG: sfdisk --dump ' . $d . ' fehlgeschlagen: ' . $r['err']);
            }
        }
        foreach ($sel['bios'] as $p) {
            try {
                Exec::ok(['dd', 'if=/dev/' . $p, "of=$dir/bios/$p.img", 'bs=1M', 'status=none']);
                chmod("$dir/bios/$p.img", 0600);
            } catch (\RuntimeException $e) {
                $log('WARNUNG: BIOS-Boot-Partition ' . $p . ': ' . $e->getMessage());
            }
        }
        foreach ($sel['efi'] as $p) {
            try {
                self::copyEfi('/dev/' . $p, "$dir/efi/$p");
            } catch (\RuntimeException $e) {
                $log('WARNUNG: EFI-Partition ' . $p . ': ' . $e->getMessage());
            }
        }
        foreach ($sel['luks'] as $p) {
            $uuid = preg_replace('~[^A-Za-z0-9-]~', '', (string)($flat[$p]['uuid'] ?? '')) ?: 'unbekannt';
            $base = "$dir/luks/{$p}_$uuid";
            Exec::ok(['cryptsetup', 'luksHeaderBackup', '/dev/' . $p, '--header-backup-file', "$base.img"]);
            chmod("$base.img", 0600);
            $dump = Exec::run(['cryptsetup', 'luksDump', '/dev/' . $p]);
            $put("luks/{$p}_$uuid.txt", $dump['code'] === 0 ? $dump['out'] : "FEHLER: {$dump['err']}\n");
        }
        foreach (['info', 'partitions', 'bios', 'efi', 'luks'] as $sub) {
            chmod("$dir/$sub", 0700);
        }
        $put('WIEDERHERSTELLEN.md', self::guide($disks, $flat, $pool, $sel));
        $log(sprintf('Systemdisk: %d Boot-Disk(s), %d EFI, %d BIOS, %d LUKS-Header', count($disks), count($sel['efi']), count($sel['bios']), count($sel['luks'])));
        return ['bootDisks' => $disks, 'pool' => $pool] + $sel;
    }

    /** EFI-Partition read-only kopieren (vorhandener Mount oder privater Temp-Mount). */
    private static function copyEfi(string $dev, string $to): void
    {
        mkdir($to, 0700);
        $mp = trim(Exec::run(['findmnt', '-no', 'TARGET', $dev])['out']);
        if ($mp !== '') {
            Exec::ok(['cp', '-a', '--', rtrim($mp, '/') . '/.', $to . '/'], 300);
            return;
        }
        $tmp = sys_get_temp_dir() . '/zbg-efi-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        $mounted = false;
        try {
            Exec::ok(['mount', '-o', 'ro', '-t', 'vfat', '--', $dev, $tmp]);
            $mounted = true;
            Exec::ok(['cp', '-a', '--', "$tmp/.", $to . '/'], 300);
        } finally {
            if ($mounted) {
                Exec::run(['umount', $tmp]);
            }
            @rmdir($tmp);
        }
    }

    private static function guide(array $disks, array $flat, ?string $pool, array $sel): string
    {
        $md = "# Systemdisk wiederherstellen\n\nErzeugt von ZFS Backup Guard am " . date('Y-m-d H:i:s') . ".\n\n## Dieses System\n\n";
        $md .= '- Boot-Pool: ' . ($pool ?? 'keiner (USB-Flash)') . "\n";
        foreach ($disks as $d) {
            $md .= sprintf("- Boot-Disk: /dev/%s (Modell: %s, Seriennummer: %s), Partitionstabelle: `partitions/%s.sgdisk`\n", $d, $flat[$d]['model'] ?? '?', $flat[$d]['serial'] ?? '?', $d);
        }
        foreach ($sel['efi'] as $p) {
            $md .= "- EFI-Partition: /dev/$p, Dateien in `efi/$p/`\n";
        }
        foreach ($sel['luks'] as $p) {
            $md .= "- LUKS: /dev/$p (UUID " . ($flat[$p]['uuid'] ?? '?') . "), Header in `luks/`\n";
        }
        $disk = $disks[0] ?? 'DISK';
        $md .= <<<MD

## Schritte

1. Neue oder ersetzte Disk: In der Unraid-GUI den Pool neu bootfähig machen (Pools → Boot-Pool / „Make Bootable“, intern `mkbootpool`). Alternativ die Partitionstabelle zurückspielen:
   `sgdisk --load-backup=partitions/$disk.sgdisk /dev/$disk`
   WARNUNG: Das überschreibt die Partitionstabelle von /dev/$disk. Vorher das Zielgerät prüfen (Modell/Seriennummer oben).
2. /boot-Inhalt aus dieser Backup-Version zurückkopieren (ohne `.zbg-system`):
   `rsync -a --exclude=.zbg-system <Backup-Version>/ /boot/`
3. EFI-Dateien bei Bedarf auf die gemountete EFI-Partition kopieren (Inhalt von `efi/<Partition>/`).
4. LUKS-Header nur bei beschädigtem Header zurückspielen:
   `cryptsetup luksHeaderRestore /dev/<Gerät> --header-backup-file luks/<Gerät>_<UUID>.img`
   WARNUNG: Nur auf das exakt richtige Gerät anwenden und vorher die UUID mit `luks/<Gerät>_<UUID>.txt` vergleichen. Ein falscher Header macht Daten unlesbar.
5. Die LUKS-Header-Backups zusammen mit der Passphrase geben Zugriff auf die Daten. Das Backup-Ziel entsprechend geschützt halten.

MD;
        return $md;
    }
}
