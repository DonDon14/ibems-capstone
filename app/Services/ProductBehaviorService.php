<?php

namespace App\Services;

final class ProductBehaviorService
{
    public const ITEM_TYPES = ['stock_item', 'prepared_item', 'manufactured_item', 'service', 'deposit', 'rental'];
    public const TYPE_CAPABILITIES = ['stock_item' => 'retail', 'prepared_item' => 'food_service', 'manufactured_item' => 'production', 'service' => 'refill_service', 'deposit' => 'container_deposits', 'rental' => 'rental'];

    public static function allowedTypes(array $capabilities): array
    {
        return array_keys(array_filter(self::TYPE_CAPABILITIES, static fn ($capability) => in_array($capability, $capabilities, true)));
    }

    public static function requiresInternalCustomer(int $storeId): bool
    {
        return in_array('refill_service', (new \App\Models\StoreCapabilityModel())->getForStore($storeId), true);
    }

    /** Serialize capability changes and sale commits for the same store. Call inside a transaction. */
    public static function lockStore(\CodeIgniter\Database\BaseConnection $db, int $storeId): void
    {
        if ($db->DBDriver === 'SQLite3') {
            // Acquire SQLite's writer lock before reading authorization or balances.
            // A deferred read followed by a write can otherwise fail immediately
            // when a competing checkout holds the writer, despite busyTimeout.
            if (!$db->table('stores')->set('id', 'id', false)->where('id', $storeId)->update()) {
                throw new \RuntimeException('Unable to lock store operations.');
            }
        } else {
            $db->query('SELECT id FROM ' . $db->protectIdentifiers($db->prefixTable('stores')) . ' WHERE id = ? FOR UPDATE', [$storeId]);
        }
    }

    public static function assertStoreAllows(int $storeId, array $product): void
    {
        $type = $product['item_type'] ?? 'stock_item';
        $allowed = self::allowedTypes((new \App\Models\StoreCapabilityModel())->getForStore($storeId));
        if (!in_array($type, $allowed, true)) {
            throw new \InvalidArgumentException('This store has disabled the activity required for ' . str_replace('_', ' ', $type) . '. Enable it in Store settings before adding or selling this item.');
        }
    }
    public const STOCK_POLICIES = ['tracked', 'untracked'];
    public const UNIT_CODES = ['piece', 'bottle', 'can', 'pack', 'serving', 'meal', 'tray', 'gallon', 'liter', 'container', 'service'];
    public const CAPABILITIES = ['retail', 'food_service', 'production', 'refill_service', 'container_deposits', 'rental'];

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
        if (in_array($itemType, ['service', 'deposit', 'rental'], true) && $stockPolicy !== 'untracked') {
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
