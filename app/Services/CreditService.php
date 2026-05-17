<?php

namespace App\Services;

class CreditService
{
    public function computeLimit(?float $salary): float
    {
        if ($salary === null || $salary <= 0) {
            return 1000.0;
        }

        $calculated = round($salary * 0.30, 2);
        $calculated = max($calculated, 1000.0);

        return min($calculated, 15000.0);
    }

    public function canBorrow(float $currentDebt, float $creditLimit, float $amount): bool
    {
        $available = $creditLimit - $currentDebt;

        return $available >= $amount;
    }
}
