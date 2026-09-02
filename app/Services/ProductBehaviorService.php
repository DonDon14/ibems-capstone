<?php

namespace App\Services;

final class ProductBehaviorService
{
    public const ITEM_TYPES = ['stock_item', 'prepared_item', 'manufactured_item', 'service', 'deposit'];
    public const STOCK_POLICIES = ['tracked', 'untracked'];
    public const UNIT_CODES = ['piece', 'bottle', 'can', 'pack', 'serving', 'meal', 'tray', 'gallon', 'liter', 'container', 'service'];
    public const CAPABILITIES = ['retail', 'food_service', 'production', 'refill_service', 'container_deposits'];

    public static function normalize(array $input): array
    {
        $itemType = strtolower(trim((string) ($input['item_type'] ?? 'stock_item')));
        $stockPolicy = strtolower(trim((string) ($input['stock_policy'] ?? 'tracked')));
        $unitCode = strtolower(trim((string) ($input['unit_code'] ?? 'piece')));

        if (!in_array($itemType, self::ITEM_TYPES, true)) {
            throw new \InvalidArgumentException('Select a valid product type.');
        }
        if (!in_array($stockPolicy, self::STOCK_POLICIES, true)) {
            throw new \InvalidArgumentException('Select a valid stock policy.');
        }
        if (!in_array($unitCode, self::UNIT_CODES, true)) {
            throw new \InvalidArgumentException('Select a valid selling unit.');
        }
        if (in_array($itemType, ['service', 'deposit'], true) && $stockPolicy !== 'untracked') {
            throw new \InvalidArgumentException('Services and refundable deposits must use untracked stock.');
        }

        return ['item_type' => $itemType, 'stock_policy' => $stockPolicy, 'unit_code' => $unitCode];
    }

    public static function tracksStock(array $product): bool
    {
        return strtolower((string) ($product['stock_policy'] ?? 'tracked')) === 'tracked';
    }

    public static function normalizeCapabilities(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        $capabilities = array_values(array_unique(array_filter(array_map(
            static fn ($item): string => strtolower(trim((string) $item)),
            is_array($value) ? $value : []
        ), static fn (string $item): bool => in_array($item, self::CAPABILITIES, true))));
        return $capabilities === [] ? ['retail'] : $capabilities;
    }
}
