<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Minimaler 5-Feld-Cron-Parser (Minute Stunde Tag Monat Wochentag) für Validierung und „nächster Lauf“.
 */
final class Cron
{
    private const RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    public static function valid(string $expr): bool
    {
        try {
            self::parse($expr);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return array{0:int[],1:int[],2:int[],3:int[],4:int[],dom:bool,dow:bool} */
    public static function parse(string $expr): array
    {
        $f = preg_split('~\s+~', trim($expr));
        if (count($f) !== 5) {
            throw new \InvalidArgumentException('5 Felder erwartet');
        }
        $sets = [];
        foreach ($f as $i => $field) {
            $sets[$i] = self::field($field, ...self::RANGES[$i]);
        }
        if (in_array(7, $sets[4], true)) {
            $sets[4] = array_values(array_unique(array_merge(array_diff($sets[4], [7]), [0])));
        }
        $sets['dom'] = $f[2] !== '*';
        $sets['dow'] = $f[4] !== '*';
        return $sets;
    }

    private static function field(string $field, int $min, int $max): array
    {
        $vals = [];
        foreach (explode(',', $field) as $part) {
            if (!preg_match('~^(\*|\d+(?:-\d+)?)(?:/(\d+))?$~', $part, $m)) {
                throw new \InvalidArgumentException("Ungültig: $part");
            }
            $step = isset($m[2]) ? (int)$m[2] : 1;
            if ($step < 1) {
                throw new \InvalidArgumentException('Schritt < 1');
            }
            if ($m[1] === '*') {
                [$a, $b] = [$min, $max];
            } elseif (str_contains($m[1], '-')) {
                [$a, $b] = array_map('intval', explode('-', $m[1]));
            } else {
                $a = (int)$m[1];
                $b = isset($m[2]) ? $max : $a;
            }
            if ($a < $min || $b > $max || $a > $b) {
                throw new \InvalidArgumentException("Bereich $part");
            }
            for ($v = $a; $v <= $b; $v += $step) {
                $vals[] = $v;
            }
        }
        sort($vals);
        return array_values(array_unique($vals));
    }

    /** Nächster Zeitpunkt nach $from (Unix-Sekunden, lokale Zeit). */
    public static function next(string $expr, ?int $from = null): ?int
    {
        $s = self::parse($expr);
        $t = (new \DateTimeImmutable('@' . (($from ?? time()) - (($from ?? time()) % 60) + 60)))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $limit = $t->modify('+5 years');
        while ($t < $limit) {
            if (!in_array((int)$t->format('n'), $s[3], true)) {
                $t = $t->modify('first day of next month')->setTime(0, 0);
                continue;
            }
            $domOk = in_array((int)$t->format('j'), $s[2], true);
            $dowOk = in_array((int)$t->format('w'), $s[4], true);
            $dayOk = ($s['dom'] && $s['dow']) ? ($domOk || $dowOk) : ($domOk && $dowOk);
            if (!$dayOk) {
                $t = $t->modify('+1 day')->setTime(0, 0);
                continue;
            }
            if (!in_array((int)$t->format('G'), $s[1], true)) {
                $t = $t->setTime((int)$t->format('G'), 0)->modify('+1 hour');
                continue;
            }
            if (!in_array((int)$t->format('i'), $s[0], true)) {
                $t = $t->modify('+1 minute');
                continue;
            }
            return $t->getTimestamp();
        }
        return null;
    }
}
