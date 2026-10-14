<?php
declare(strict_types=1);

namespace ZBG;

/**
 * Gestaffelte Aufbewahrung (Großvater-Vater-Sohn), rein funktional.
 *
 * Für jede Stufe (Stunde, Tag, ISO-Woche, Monat, Jahr) wird pro Periode der neueste Eintrag
 * behalten, bis N Einträge erreicht sind. Einträge, die schon eine feinere Stufe behält, zählen
 * nicht mit (Semantik von borg prune). Der neueste und alle geschützten Einträge bleiben immer.
 */
final class Retention
{
    public const PERIODS = [
        'hourly' => 'Y-m-d H',
        'daily' => 'Y-m-d',
        'weekly' => 'o-\WW',
        'monthly' => 'Y-m',
        'yearly' => 'Y',
    ];

    /**
     * @param list<array{name:string,time:int}> $items
     * @param array<string,int> $keep z. B. ['daily'=>7,'weekly'=>4]
     * @param list<string> $protect Namen, die nie gelöscht werden (z. B. gemeinsamer Snapshot für zfs send)
     * @return array{keep:list<string>,destroy:list<string>,reasons:array<string,list<string>>}
     */
    public static function plan(array $items, array $keep, array $protect = [], ?\DateTimeZone $tz = null): array
    {
        $tz ??= new \DateTimeZone(date_default_timezone_get());
        usort($items, static fn($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));

        // Stufen wie bei borg prune: Was eine feinere Stufe behält, zählt nicht für gröbere.
        // So reichen 7 täglich + 4 wöchentlich + 3 monatlich wirklich ~4 Monate zurück.
        $reasons = [];
        foreach (self::PERIODS as $level => $fmt) {
            $n = (int)($keep[$level] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $last = null;
            $count = 0;
            foreach ($items as $it) {
                $period = (new \DateTimeImmutable('@' . $it['time']))->setTimezone($tz)->format($fmt);
                if ($period === $last) {
                    continue;
                }
                $last = $period;
                if (!isset($reasons[$it['name']])) {
                    $reasons[$it['name']][] = $level;
                    if (++$count >= $n) {
                        break;
                    }
                }
            }
        }
        if ($items) {
            $reasons[$items[0]['name']][] = 'newest';
        }
        foreach ($protect as $p) {
            foreach ($items as $it) {
                if ($it['name'] === $p) {
                    $reasons[$p][] = 'protected';
                }
            }
        }

        $k = $d = [];
        foreach ($items as $it) {
            if (isset($reasons[$it['name']])) {
                $k[] = $it['name'];
            } else {
                $d[] = $it['name'];
            }
        }
        return ['keep' => $k, 'destroy' => $d, 'reasons' => $reasons];
    }
}
