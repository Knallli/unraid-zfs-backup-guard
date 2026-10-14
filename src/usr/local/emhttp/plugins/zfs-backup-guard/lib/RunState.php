<?php
declare(strict_types=1);

namespace ZBG;

final class RunState
{
    public static function dir(): string
    {
        return getenv('ZBG_STATE_DIR') ?: (configDir() === '/boot/config/plugins/zfs-backup-guard' ? '/var/run/zfs-backup-guard' : configDir() . '/runtime');
    }

    public static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Verzeichnis kann nicht angelegt werden: $dir");
        }
    }

    public static function assertId(string $id): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $id)) {
            throw new UserError('Ungültige Job-ID');
        }
    }

    public static function lockJob(string $id, bool $nonBlocking = false)
    {
        self::assertId($id);
        self::ensureDirectory(self::dir());
        $handle = @fopen(self::dir() . '/' . $id . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Job-Sperre kann nicht geöffnet werden');
        }
        if (!flock($handle, LOCK_EX | ($nonBlocking ? LOCK_NB : 0), $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock) {
                throw new UserError("Job läuft bereits: $id");
            }
            throw new \RuntimeException('Job-Sperre kann nicht gesetzt werden');
        }
        return $handle;
    }

    public static function read(string $id): array
    {
        self::assertId($id);
        $data = @file_get_contents(self::dir() . '/' . $id . '.json');
        return $data === false ? [] : (json_decode($data, true) ?: []);
    }

    public static function write(string $id, array $state): void
    {
        self::assertId($id);
        self::ensureDirectory(self::dir());
        self::atomic(self::dir() . '/' . $id . '.json', $state);
        $pidFile = self::dir() . '/' . $id . '.pid';
        if (($state['state'] ?? '') === 'running' && (int)($state['pid'] ?? 0) > 1) {
            $pid = (string)$state['pid'] . "\n";
            if (@file_get_contents($pidFile) !== $pid) {
                $tmp = $pidFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (file_put_contents($tmp, $pid) === false || !rename($tmp, $pidFile)) {
                    @unlink($tmp);
                    throw new \RuntimeException('PID-Datei kann nicht geschrieben werden');
                }
            }
        } else {
            @unlink($pidFile);
        }
    }

    public static function atomic(string $path, array $value): void
    {
        $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Datei kann nicht geschrieben werden: $path");
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Datei kann nicht atomar ersetzt werden: $path");
        }
    }

    public static function pidAlive(int $pid): bool
    {
        if ($pid < 2) {
            return false;
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat !== false && ($end = strrpos($stat, ')')) !== false && str_starts_with(ltrim(substr($stat, $end + 1)), 'Z ')) {
            return false;
        }
        return function_exists('posix_kill') ? @posix_kill($pid, 0) : is_dir('/proc/' . $pid);
    }

    public static function pidIdentity(int $pid): string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false || ($end = strrpos($stat, ')')) === false) {
            return '';
        }
        $fields = explode(' ', trim(substr($stat, $end + 1)));
        return (string)($fields[19] ?? '');
    }

    public static function active(string $id): bool
    {
        $s = self::read($id);
        if (($s['state'] ?? '') !== 'running') {
            return false;
        }
        $pid = (int)($s['pid'] ?? 0);
        if ($pid === 0) {
            return time() - (int)($s['start'] ?? 0) < 20;
        }
        return self::pidAlive($pid) && (empty($s['pidIdentity']) || $s['pidIdentity'] === self::pidIdentity($pid));
    }

    public static function requestCancel(string $id): void
    {
        $state = self::read($id);
        if (($state['state'] ?? '') === 'running') {
            self::atomic(self::dir() . '/' . $id . '.cancel', ['runId' => $state['runId'] ?? '']);
        }
    }

    public static function cancelled(string $id): bool
    {
        $data = @file_get_contents(self::dir() . '/' . $id . '.cancel');
        if ($data === false) {
            return false;
        }
        $cancel = json_decode($data, true);
        return ($cancel['runId'] ?? null) === (self::read($id)['runId'] ?? null);
    }

    public static function clearCancel(string $id): void
    {
        @unlink(self::dir() . '/' . $id . '.cancel');
    }

    public static function logPath(string $id, string $runId): string
    {
        self::assertId($id);
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $runId)) {
            throw new UserError('Ungültige Lauf-ID');
        }
        $dir = dirname(History::dir()) . '/logs';
        return $dir . '/' . $id . '-' . $runId . '.log';
    }
}
