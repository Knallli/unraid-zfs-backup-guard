<?php
declare(strict_types=1);

namespace ZBG;

final class Docker
{
    /** @return list<array{name:string,image:string,state:string,status:string}> leer, wenn Docker nicht läuft */
    public static function containers(): array
    {
        if (!file_exists('/var/run/docker.sock')) {
            return [];
        }
        $r = Exec::run(['docker', 'ps', '-a', '--format', "{{.Names}}\t{{.Image}}\t{{.State}}\t{{.Status}}"], 20);
        if ($r['code'] !== 0) {
            return [];
        }
        $list = Exec::rows($r['out'], ['name', 'image', 'state', 'status']);
        usort($list, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $list;
    }
}
