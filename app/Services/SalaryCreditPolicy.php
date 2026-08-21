<?php

namespace App\Services;

final class SalaryCreditPolicy
{
    public const CREDIT_RATE = 0.25;
    public const EMPLOYMENT_TYPES = ['plantilla', 'cos', 'part_time'];

    public static function creditLimit(float $monthlySalary): float
    {
        return round(max(0, $monthlySalary) * self::CREDIT_RATE, 2);
    }

    public static function normalizeEmploymentType(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function normalizeSalaryGrade(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function isValidEffectiveDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
