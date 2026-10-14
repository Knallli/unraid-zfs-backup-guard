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

    private static function inspect(string $name): array
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $name)) {
            throw new UserError('Ungültiger Container-Name');
        }
        $container = json_decode(Exec::ok(['docker', 'inspect', '--format', '{{json .}}', $name], 30), true);
        $state = is_array($container) ? ($container['State'] ?? null) : null;
        if (!is_array($state) || !is_string($container['Id'] ?? null)) {
            throw new \RuntimeException("Container-Zustand nicht lesbar: $name");
        }
        $state['Id'] = $container['Id'];
        return $state;
    }

    /** Journal intent before mutation, so cancellation or a killed worker can recover it. */
    public static function quiesce(string $mode, array $names, callable $journal, callable $log, ?callable $cancel = null): array
    {
        $entries = [];
        if ($mode === 'crash') {
            return $entries;
        }
        foreach (array_unique($names) as $name) {
            if ($cancel !== null && $cancel()) {
                throw new \RuntimeException('Abgebrochen');
            }
            $state = self::inspect($name);
            if (empty($state['Running']) || !empty($state['Paused'])) {
                $log("Container $name unverändert (nicht laufend oder bereits pausiert)");
                continue;
            }
            $entries[] = ['name' => $name, 'id' => $state['Id'], 'mode' => $mode];
            $journal($entries);
            $log("Container $mode: $name");
            $command = $mode === 'pause' ? ['docker', 'pause', $state['Id']] : ['docker', 'stop', '--time', '30', $state['Id']];
            $result = Exec::stream($command, $log, $cancel, 90);
            if ($result['code'] !== 0) {
                throw new \RuntimeException("Container $name konnte nicht angehalten werden: " . $result['err']);
            }
        }
        return $entries;
    }

    public static function recover(array $entries, callable $log, ?callable $journal = null): void
    {
        $pending = $entries;
        $errors = [];
        foreach ($entries as $key => $entry) {
            try {
                $identity = $entry['id'];
                $state = self::inspect($identity);
                if ($entry['mode'] === 'pause' && !empty($state['Paused'])) {
                    $log('Container fortsetzen: ' . $entry['name']);
                    Exec::ok(['docker', 'unpause', $identity], 60);
                } elseif ($entry['mode'] === 'stop' && empty($state['Running'])) {
                    $log('Container starten: ' . $entry['name']);
                    Exec::ok(['docker', 'start', $identity], 90);
                }
                unset($pending[$key]);
                if ($journal !== null) {
                    $journal(array_values($pending));
                }
            } catch (\Throwable $e) {
                $errors[] = $entry['name'] . ': ' . $e->getMessage();
            }
        }
        if ($errors) {
            throw new \RuntimeException('Container-Wiederherstellung fehlgeschlagen: ' . implode('; ', $errors));
        }
    }
}
