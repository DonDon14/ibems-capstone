<?php

namespace App\Services;

final class DashboardPeriod
{
    /** @return array{key:string,from:string,to:string,start:string,end:string,label:string,bucket:string} */
    public static function resolve(?string $requested): array
    {
        $key = strtolower(trim((string) $requested));
        if (!in_array($key, ['day', 'week', 'month', 'year'], true)) {
            $key = 'day';
        }

        $zone = new \DateTimeZone('Asia/Manila');
        $today = new \DateTimeImmutable(ibems_business_date(), $zone);
        $from = match ($key) {
            'week' => $today->modify('monday this week'),
            'month' => $today->modify('first day of this month'),
            'year' => $today->setDate((int) $today->format('Y'), 1, 1),
            default => $today,
        };

        $fromDate = $from->format('Y-m-d');
        $toDate = $today->format('Y-m-d');
        $fromBounds = ibems_business_day_utc_bounds($fromDate);
        $toBounds = ibems_business_day_utc_bounds($toDate);
        $label = match ($key) {
            'week' => 'This week · ' . $from->format('M j') . '–' . $today->format('M j, Y'),
            'month' => 'This month · ' . $today->format('F Y'),
            'year' => 'This year · ' . $today->format('Y'),
            default => 'Today · ' . $today->format('M j, Y'),
        };

        return [
            'key' => $key,
            'from' => $fromDate,
            'to' => $toDate,
            'start' => $fromBounds['start'],
            'end' => $toBounds['end'],
            'label' => $label,
            'bucket' => $key === 'year' ? 'month' : 'day',
        ];
    }

    /** @param array{from:string,to:string,bucket:string} $period @return list<array{key:string,date:string}> */
    public static function trendSeed(array $period): array
    {
        $zone = new \DateTimeZone('Asia/Manila');
        $cursor = new \DateTimeImmutable($period['from'], $zone);
        $end = new \DateTimeImmutable($period['to'], $zone);
        $rows = [];
        while ($cursor <= $end) {
            $isMonthly = $period['bucket'] === 'month';
            $rows[] = [
                'key' => $isMonthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d'),
                'date' => $isMonthly ? $cursor->format('Y-m-01') : $cursor->format('Y-m-d'),
            ];
            $cursor = $cursor->modify($isMonthly ? 'first day of next month' : '+1 day');
        }
        return $rows;
    }

    /** @param array{bucket:string} $period */
    public static function bucketKey(string $businessDate, array $period): string
    {
        return $period['bucket'] === 'month' ? substr($businessDate, 0, 7) : $businessDate;
    }

    /** @param array<string, mixed> $period @return array{key:string,from:string,to:string,label:string,bucket:string} */
    public static function publicMeta(array $period): array
    {
        return array_intersect_key($period, array_flip(['key', 'from', 'to', 'label', 'bucket']));
    }
}
