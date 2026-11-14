<?php
declare(strict_types=1);
// Jobübergreifende Zielkonflikte beim Speichern (JobStore::save → Paths::jobTargetConflict).
(static function (): void {
    $a = ['id' => 'conflict-a', 'method' => 'rsync', 'target' => ['path' => '/mnt/disk1/bk', 'ssh' => null]];
    $b = ['id' => 'conflict-b', 'method' => 'rsync', 'target' => ['path' => '/mnt/disk1/bk/b', 'ssh' => null]];
    $c = ['id' => 'conflict-c', 'method' => 'rsync', 'target' => ['path' => '/mnt/disk1/other', 'ssh' => null]];
    check('Koordination: verschachtelte Jobziele kollidieren', is_string(ZBG\Paths::jobTargetConflict($b, [$a])));
    check('Koordination: Elternziel kollidiert', is_string(ZBG\Paths::jobTargetConflict($a, [$b])));
    check('Koordination: Geschwisterziele kollidieren nicht', ZBG\Paths::jobTargetConflict($c, [$a]) === null);
    check('Koordination: eigener Job kollidiert nicht', ZBG\Paths::jobTargetConflict($a, [$a]) === null);
    $prod = array_map(static fn($n) => ['id' => $n, 'method' => 'rsync', 'target' => ['path' => '/mnt/user/backup/zfs/' . $n, 'ssh' => null]], ['appdata', 'domains', 'lxc']);
    check('Koordination: Produktionsziele kollidieren nicht', ZBG\Paths::jobTargetConflict($prod[0], $prod) === null && ZBG\Paths::jobTargetConflict($prod[2], $prod) === null);
})();
