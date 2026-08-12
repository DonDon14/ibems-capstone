<?php

namespace App\Services;

final class StoreDayReviewPolicy
{
    public function isIndependentReviewer(int $actorId, array $session): bool
    {
        $closingOperatorId = (int) ($session['closed_by'] ?? 0);

        return $actorId > 0 && ($closingOperatorId <= 0 || $actorId !== $closingOperatorId);
    }
}
