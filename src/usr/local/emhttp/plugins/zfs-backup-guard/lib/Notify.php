<?php
declare(strict_types=1);

namespace ZBG;

final class Notify
{
    public static function healthcheck(array $job, string $event): void
    {
        $url = $job['healthcheckUrl'] ?? null;
        if (!$url) {
            return;
        }
        $suffix = match ($event) {
            'start' => '/start',
            'success' => '',
            'fail' => '/fail',
            default => throw new \InvalidArgumentException('Unbekanntes Healthcheck-Ereignis'),
        };
        $position = strpos($url, '?');
        $base = $position === false ? $url : substr($url, 0, $position);
        $query = $position === false ? '' : substr($url, $position);
        Exec::ok(['curl', '--fail', '--silent', '--show-error', '--connect-timeout', '10', '--max-time', '20', '--output', '/dev/null', '--', rtrim($base, '/') . $suffix . $query], 25);
    }

    public static function result(array $job, array $record): void
    {
        if (($job['notify'] ?? 'errors') === 'none' || (($record['result'] ?? '') === 'success' && ($job['notify'] ?? '') !== 'all')) {
            return;
        }
        $success = ($record['result'] ?? '') === 'success';
        $message = $success ? 'Backup erfolgreich: ' . Paths::human((int)$record['bytes']) . ', ' . (int)$record['files'] . ' Dateien, ' . (int)$record['duration'] . ' s' : ('Backup ' . (($record['result'] ?? '') === 'cancelled' ? 'abgebrochen' : 'fehlgeschlagen') . ': ' . ($record['error'] ?? 'Unbekannter Fehler'));
        self::send($job, $success ? 'normal' : 'alert', $message);
    }

    public static function stale(array $job, array $status): void
    {
        if (($job['notify'] ?? 'errors') !== 'none') {
            $message = $status['ageSeconds'] === null ? 'Noch kein erfolgreiches Backup vorhanden' : 'Letztes erfolgreiches Backup vor ' . round($status['ageSeconds'] / 3600, 1) . ' Stunden (Grenze ' . $job['maxAgeHours'] . ' Stunden)';
            self::send($job, 'warning', $message);
        }
    }

    private static function send(array $job, string $importance, string $message): void
    {
        $script = '/usr/local/emhttp/webGui/scripts/notify';
        if (is_file($script)) {
            Exec::ok([$script, '-e', 'ZFS Backup Guard', '-s', 'ZFS Backup Guard: ' . $job['name'], '-d', $message, '-i', $importance], 30);
        }
    }
}
