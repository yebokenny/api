<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class ScoreSeries
{
    public static function fromRows(Collection $rows): array
    {
        $points = $rows->map(fn ($row) => [
            'at' => Carbon::parse($row->DateComputed),
            'score' => (float) $row->ScoreGiven,
        ]);

        return [
            'week' => self::days($points, now()->subDays(6)->startOfDay(), 7),
            'month' => self::weeks($points, 4),
            'quarter' => self::months($points, 3),
            'year' => self::months($points, 12),
        ];
    }

    private static function days(Collection $points, Carbon $start, int $count): array
    {
        $buckets = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $start->copy()->addDays($i);
            $buckets[] = [
                'label' => $day->format('D'),
                'score' => round(self::sumBetween($points, $day->copy()->startOfDay(), $day->copy()->endOfDay()), 1),
            ];
        }

        return $buckets;
    }

    private static function weeks(Collection $points, int $count): array
    {
        $buckets = [];
        $start = now()->subWeeks($count - 1)->startOfWeek();
        for ($i = 0; $i < $count; $i++) {
            $from = $start->copy()->addWeeks($i);
            $to = $from->copy()->endOfWeek();
            $buckets[] = [
                'label' => $from->format('j M'),
                'score' => round(self::sumBetween($points, $from, $to), 1),
            ];
        }

        return $buckets;
    }

    private static function months(Collection $points, int $count): array
    {
        $buckets = [];
        $start = now()->startOfMonth()->subMonths($count - 1);
        for ($i = 0; $i < $count; $i++) {
            $from = $start->copy()->addMonths($i)->startOfMonth();
            $to = $from->copy()->endOfMonth();
            $buckets[] = [
                'label' => $from->format('M'),
                'score' => round(self::sumBetween($points, $from, $to), 1),
            ];
        }

        return $buckets;
    }

    private static function sumBetween(Collection $points, Carbon $from, Carbon $to): float
    {
        return (float) $points
            ->filter(fn ($point) => $point['at']->betweenIncluded($from, $to))
            ->sum('score');
    }
}
