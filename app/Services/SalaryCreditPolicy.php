<?php

namespace App\Services;

final class SalaryCreditPolicy
{
    public const DEFAULT_CREDIT_RATE = 0.25;
    public const CREDIT_RATE = self::DEFAULT_CREDIT_RATE;
    public const MIN_CREDIT_RATE = 0.0;
    public const MAX_CREDIT_RATE = 1.0;
    public const EMPLOYMENT_TYPES = ['plantilla', 'cos', 'part_time'];

    public static function creditLimit(float $monthlySalary, float $creditRate = self::DEFAULT_CREDIT_RATE): float
    {
        $rate = min(self::MAX_CREDIT_RATE, max(self::MIN_CREDIT_RATE, $creditRate));
        return round(max(0, $monthlySalary) * $rate, 2);
    }

    public static function rateFromPercentage(float $percentage): float
    {
        return round($percentage / 100, 4);
    }

    public static function percentageFromRate(float $rate): float
    {
        return round($rate * 100, 2);
    }

    public static function isValidCreditPercentage(float $percentage): bool
    {
        return $percentage >= 0 && $percentage <= 100;
    }

    public static function parseSalaryStep($value): ?int
    {
        $text = trim((string) $value);
        return preg_match('/^[1-8]$/D', $text) === 1 ? (int) $text : null;
    }

    public static function parseCreditPercentage($value): ?float
    {
        $text = trim((string) $value);
        if (preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D', $text) !== 1) {
            return null;
        }

        $percentage = (float) $text;
        return self::isValidCreditPercentage($percentage) ? $percentage : null;
    }

    public static function parseMonthlySalary($value): ?float
    {
        $text = trim((string) $value);
        return preg_match('/^[1-9][0-9]{0,8}(?:\.[0-9]{1,2})?$/D', $text) === 1
            ? (float) $text
            : null;
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

    public static function isFutureEffectiveDate(string $value): bool
    {
        return self::isValidEffectiveDate($value)
            && $value > ibems_business_date();
    }
}
