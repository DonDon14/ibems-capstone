<?php

namespace App\Services;

final class DashboardAlertPagination
{
    /**
     * @param list<array<string, mixed>> $alerts
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int|bool>}
     */
    public static function paginate(array $alerts, int $requestedPage, int $perPage = 5): array
    {
        $perPage = max(1, min(25, $perPage));
        $total = count($alerts);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $requestedPage), $totalPages);
        $offset = ($page - 1) * $perPage;
        $items = array_values(array_slice($alerts, $offset, $perPage));

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => $total === 0 ? 0 : $offset + count($items),
                'has_previous' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
        ];
    }
}
