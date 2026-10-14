<?php
declare(strict_types=1);

namespace ZBG;

/** SSH executes one POSIX-quoted command, never an interpolated argument list. */
final class Remote
{
    public static function quote(array $cmd): string
    {
        if (!$cmd) {
            throw new \InvalidArgumentException('Leerer Befehl');
        }
        return implode(' ', array_map(static function ($arg): string {
            $arg = (string)$arg;
            if (str_contains($arg, "\0")) {
                throw new \InvalidArgumentException('NUL im Befehlsargument');
            }
            return "'" . str_replace("'", "'\"'\"'", $arg) . "'";
        }, $cmd));
    }

    public static function command(?array $ssh, array $cmd): array
    {
        if ($ssh === null) {
            self::quote($cmd);
            return array_map('strval', $cmd);
        }
        return array_merge(self::ssh($ssh), ['--', (string)($ssh['user'] ?? 'root') . '@' . $ssh['host'], self::quote($cmd)]);
    }

    /** SSH transport argv, also suitable for a quoted rsync -e command. */
    public static function ssh(array $ssh): array
    {
        $host = (string)($ssh['host'] ?? '');
        $user = (string)($ssh['user'] ?? 'root');
        $port = (string)($ssh['port'] ?? 22);
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9.\-:\[\]]*$~', $host)
            || !preg_match('~^[a-z_][a-z0-9_\-]*$~', $user)
            || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
            throw new UserError('SSH-Host, Benutzer oder Port ungültig');
        }
        $args = ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3',
            '-p', $port];
        $key = (string)($ssh['key'] ?? '');
        if ($key !== '') {
            if (!str_starts_with($key, '/') || str_contains($key, "\0") || !is_file($key) || !is_readable($key)) {
                throw new UserError('SSH-Schlüssel muss eine lesbare absolute Datei sein');
            }
            $mode = fileperms($key);
            if ($mode === false || ($mode & 0077) !== 0) {
                throw new UserError('SSH-Schlüssel darf nur für seinen Besitzer zugänglich sein (chmod 600)');
            }
            array_push($args, '-o', 'IdentitiesOnly=yes', '-i', $key);
        }
        $knownHosts = (string)($ssh['knownHosts'] ?? '');
        if ($knownHosts !== '') {
            if (!str_starts_with($knownHosts, '/') || str_contains($knownHosts, "\0") || !is_file($knownHosts) || !is_readable($knownHosts)) {
                throw new UserError('SSH-Hostschlüsseldatei muss eine lesbare absolute Datei sein');
            }
            array_push($args, '-o', 'UserKnownHostsFile=' . $knownHosts);
        }
        return $args;
    }
}
