<?php

namespace App\Casts;

use CodeIgniter\DataCaster\Cast\FloatCast;

/** Accept integral DECIMAL values returned as integers by SQLite. */
final class NumericFloatCast extends FloatCast
{
    public static function get(mixed $value, array $params = [], ?object $helper = null): float
    {
        return is_int($value) ? (float) $value : parent::get($value, $params, $helper);
    }
}
