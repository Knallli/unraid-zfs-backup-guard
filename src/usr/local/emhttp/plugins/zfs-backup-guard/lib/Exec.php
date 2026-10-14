<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Prozessaufrufe ausschließlich mit Argument-Arrays (keine Shell, kein Quoting-Risiko).
 */
final class Exec
{
    /** @return array{code:int,out:string,err:string} */
    public static function run(array $cmd, int $timeout = 60): array
    {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(array_map('strval', $cmd), $spec, $pipes, null, ['LC_ALL' => 'C', 'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin']);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'Prozess konnte nicht gestartet werden: ' . $cmd[0]];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $w = $e = null;
            if (@stream_select($read, $w, $e, 0, 200000) === false) {
                break;
            }
            foreach ($read as $r) {
                $chunk = fread($r, 65536);
                if ($r === $pipes[1]) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc);
                $err .= "\nZeitüberschreitung nach {$timeout}s";
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'out' => $out, 'err' => trim($err)];
    }

    /** Führt aus und liefert stdout, wirft bei Fehler. */
    public static function ok(array $cmd, int $timeout = 60): string
    {
        $r = self::run($cmd, $timeout);
        if ($r['code'] !== 0) {
            throw new \RuntimeException(sprintf('%s fehlgeschlagen (%d): %s', $cmd[0], $r['code'], $r['err']));
        }
        return $r['out'];
    }

    /** Tab-getrennte Ausgabe (zfs -H) in Zeilen-Arrays. */
    public static function rows(string $out, array $cols): array
    {
        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $rows[] = array_combine($cols, array_pad($parts, count($cols), ''));
        }
        return $rows;
    }
}
