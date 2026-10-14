<?php
declare(strict_types=1);

namespace ZBG;

/** Argument-array execution; streamed children run in their own process group. */
final class Exec
{
    private static $observer = null;

    /** Runner journals child process groups so an unexpectedly killed worker is recoverable. */
    public static function observe(?callable $observer): void
    {
        self::$observer = $observer;
    }

    public static function terminateGroup(int $pid, int $signal = 15): void
    {
        if ($pid <= 1) {
            return;
        }
        if (function_exists('posix_kill')) {
            @posix_kill(-$pid, $signal);
            @posix_kill($pid, $signal);
            return;
        }
        foreach ([-$pid, $pid] as $target) {
            $p = proc_open(['/bin/kill', '-' . $signal, '--', (string)$target], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $unused);
            if (is_resource($p)) {
                proc_close($p);
            }
        }
    }
    public static function run(array $cmd, int $timeout = 60): array
    {
        return self::execute($cmd, null, null, $timeout, null);
    }

    /** @return array{code:int,out:string,err:string} Captured output is a bounded tail. */
    public static function stream(array $cmd, callable $log, ?callable $cancel = null, int $timeout = 0): array
    {
        return self::execute($cmd, $log, $cancel, $timeout, 1048576);
    }

    private static function execute(array $cmd, ?callable $log, ?callable $cancel, int $timeout, ?int $cap): array
    {
        if (!$cmd) {
            throw new \InvalidArgumentException('Leerer Prozessaufruf');
        }
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : null);
        $argv = array_map('strval', $cmd);
        if ($setsid !== null) {
            $argv = array_merge([$setsid, '--'], $argv);
        } elseif ($cancel !== null) {
            throw new \RuntimeException('setsid fehlt: sicherer Prozessgruppen-Abbruch nicht möglich');
        }
        $env = array_merge(getenv(), ['LC_ALL' => 'C', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);
        $proc = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'Prozess konnte nicht gestartet werden: ' . $cmd[0]];
        }
        $status = proc_get_status($proc);
        $pid = (int)$status['pid'];
        $captured = [1 => '', 2 => ''];
        $pending = [1 => '', 2 => ''];
        $exit = null;
        $stopped = null;
        $reason = null;
        $failure = null;
        $began = microtime(true);
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        try {
            if (self::$observer !== null) {
                (self::$observer)(['pid' => $pid, 'identity' => RunState::pidIdentity($pid)]);
            }
            while (true) {
                if ($stopped === null) {
                    try {
                        $cancelled = $cancel !== null && $cancel();
                    } catch (\Throwable $e) {
                        $failure = $e;
                        $cancelled = true;
                    }
                    if ($cancelled || ($timeout > 0 && microtime(true) - $began >= $timeout)) {
                        $reason = $cancelled ? 'Abgebrochen' : "Zeitüberschreitung nach {$timeout}s";
                        $stopped = microtime(true);
                        self::signal($proc, $pid, 15, $setsid !== null);
                    }
                } elseif (microtime(true) - $stopped > 2) {
                    self::signal($proc, $pid, 9, $setsid !== null);
                }
                $read = array_values(array_filter($pipes, static fn($p) => !feof($p)));
                if ($read) {
                    $w = $e = null;
                    if (@stream_select($read, $w, $e, 0, 200000) !== false) {
                        foreach ($read as $pipe) {
                            $n = $pipe === $pipes[1] ? 1 : 2;
                            $chunk = fread($pipe, 65536);
                            if ($chunk === false || $chunk === '') {
                                continue;
                            }
                            $captured[$n] .= $chunk;
                            if ($cap !== null && strlen($captured[$n]) > $cap) {
                                $captured[$n] = substr($captured[$n], -$cap);
                            }
                            if ($log !== null) {
                                $pending[$n] .= $chunk;
                                $lines = preg_split('/[\r\n]/', $pending[$n]);
                                $pending[$n] = array_pop($lines);
                                foreach ($lines as $line) {
                                    if ($line !== '') {
                                        $log($line);
                                    }
                                }
                                if (strlen($pending[$n]) >= 65536) {
                                    $log($pending[$n]);
                                    $pending[$n] = '';
                                }
                            }
                        }
                    }
                } else {
                    usleep(20000);
                }
                $status = proc_get_status($proc);
                if (!$status['running'] && $exit === null) {
                    $exit = (int)$status['exitcode'];
                }
                if ((!$status['running'] && feof($pipes[1]) && feof($pipes[2])) || ($stopped !== null && microtime(true) - $stopped > 4)) {
                    break;
                }
            }
            if ($log !== null) {
                foreach ($pending as $line) {
                    if ($line !== '') {
                        $log($line);
                    }
                }
            }
        } catch (\Throwable $e) {
            self::signal($proc, $pid, 9, $setsid !== null);
            $failure = $e;
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $closed = proc_close($proc);
        }
        if (self::$observer !== null) {
            (self::$observer)(null);
        }
        if ($failure !== null) {
            throw $failure;
        }
        if ($reason !== null) {
            $captured[2] .= "\n" . $reason;
        }
        return ['code' => $reason !== null ? ($reason === 'Abgebrochen' ? 130 : 124) : (($exit !== null && $exit >= 0) ? $exit : $closed), 'out' => $captured[1], 'err' => trim($captured[2])];
    }

    private static function signal($proc, int $pid, int $signal, bool $group): void
    {
        if ($group && $pid > 1) {
            self::terminateGroup($pid, $signal);
        }
        @proc_terminate($proc, $signal);
    }

    public static function ok(array $cmd, int $timeout = 60): string
    {
        $r = self::run($cmd, $timeout);
        if ($r['code'] !== 0) {
            throw new \RuntimeException(sprintf('%s fehlgeschlagen (%d): %s', $cmd[0], $r['code'], $r['err'] ?: $r['out']));
        }
        return $r['out'];
    }

    public static function rows(string $out, array $cols): array
    {
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line !== '') {
                $parts = explode("\t", $line);
                $rows[] = array_combine($cols, array_slice(array_pad($parts, count($cols), ''), 0, count($cols)));
            }
        }
        return $rows;
    }
}
