<?php

namespace App\Services;

use App\Models\ProductModel;
use App\Models\StoreCapabilityModel;
use App\Models\StoreCategoryModel;
use App\Models\StorePaymentMethodModel;
use CodeIgniter\Database\BaseConnection;

final class StoreCatalogQueryService
{
    /** @return array<string, mixed> */
    public function products(int $storeId): array
    {
        return [
            'status' => 'success',
            'store_id' => $storeId,
            'products' => (new ProductModel())->getActiveProductsByStore($storeId),
        ];
    }

    /** @param list<array<string, mixed>> $stores @return array<string, mixed> */
    public function stores(array $stores): array
    {
        return ['status' => 'success', 'stores' => $stores, 'default_store_id' => $stores[0]['id'] ?? null];
    }

    /** @return array<string, mixed> */
    public function categories(BaseConnection $db, int $storeId): array
    {
        $categories = (new StoreCategoryModel())->getActiveByStore($storeId);
        $productCounts = [];
        $productRows = $db->table('products')
            ->select('category, COUNT(id) AS product_count')
            ->where('store_id', $storeId)
            ->groupBy('category')
            ->get()
            ->getResultArray();
        foreach ($productRows as $productRow) {
            $productCounts[strtolower(trim((string) ($productRow['category'] ?? '')))] = (int) ($productRow['product_count'] ?? 0);
        }

        return [
            'status' => 'success',
            'store_id' => $storeId,
            'categories' => array_map(static function (array $row) use ($productCounts): array {
                $categoryKey = strtolower(trim((string) ($row['name'] ?? '')));

                return [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                    'product_count' => (int) ($productCounts[$categoryKey] ?? 0),
                ];
            }, $categories),
        ];
    }

    /** @return array<string, mixed> */
    public function capabilities(int $storeId): array
    {
        return [
            'status' => 'success',
            'store_id' => $storeId,
            'capabilities' => (new StoreCapabilityModel())->getForStore($storeId),
            'available' => ProductBehaviorService::CAPABILITIES,
        ];
    }

    /** @return array<string, mixed> */
    public function paymentMethods(BaseConnection $db, int $storeId): array
    {
        $model = new StorePaymentMethodModel();
        $model->ensureDefaults($storeId);
        $methods = $model->getActiveByStore($storeId);
        $accountsByMethod = [];
        if ($db->tableExists('payment_destination_accounts')) {
            $accounts = $db->table('payment_destination_accounts')
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->orderBy('sort_order', 'ASC')
                ->orderBy('account_name', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($accounts as $account) {
                $accountsByMethod[(int) $account['payment_method_id']][] = [
                    'id' => (int) $account['id'],
                    'account_name' => (string) $account['account_name'],
                    'masked_number' => self::maskPaymentAccount((string) $account['account_number']),
                    'image_url' => (string) ($account['image_url'] ?? ''),
                ];
            }
        }

        return [
            'status' => 'success',
            'store_id' => $storeId,
            'supports_split_payment' => in_array('transaction_payments', $db->listTables(), true),
            'methods' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
                'icon_class' => (string) ($row['icon_class'] ?? ''),
                'image_url' => (string) ($row['image_url'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_system_reserved' => ibems_bool($row['is_system_reserved'] ?? false),
                'requires_destination_account' => !in_array((string) $row['code'], ['cash', 'debt'], true),
                'destination_accounts' => $accountsByMethod[(int) $row['id']] ?? [],
            ], $methods),
        ];
    }

    /** @return array<string, mixed> */
    public function paymentAccounts(BaseConnection $db, int $storeId): array
    {
        if (!$db->tableExists('payment_destination_accounts')) {
            return ['status' => 'success', 'accounts' => [], 'migration_required' => true];
        }

        $rows = $db->table('payment_destination_accounts a')
            ->select('a.*, m.code AS payment_method, m.label AS payment_method_label, m.icon_class')
            ->join('store_payment_methods m', 'm.id = a.payment_method_id')
            ->where('a.store_id', $storeId)
            ->where('a.is_active', true)
            ->orderBy('m.sort_order', 'ASC')
            ->orderBy('a.sort_order', 'ASC')
            ->orderBy('a.account_name', 'ASC')
            ->get()
            ->getResultArray();

        return ['status' => 'success', 'accounts' => array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'payment_method_id' => (int) $row['payment_method_id'],
            'payment_method' => (string) $row['payment_method'],
            'payment_method_label' => (string) $row['payment_method_label'],
            'icon_class' => (string) ($row['icon_class'] ?? ''),
            'account_name' => (string) $row['account_name'],
            'account_number' => (string) $row['account_number'],
            'masked_number' => self::maskPaymentAccount((string) $row['account_number']),
            'image_url' => (string) ($row['image_url'] ?? ''),
        ], $rows)];
    }

    /** @return array<string, mixed> */
    public function debtCustomers(BaseConnection $db, string $search, int $page, int $pageSize, string $sortBy, string $sortDirection): array
    {
        $page = max(1, $page);
        $pageSize = max(10, min(100, $pageSize));
        $sortColumns = ['name' => 'u.name', 'debt' => 'b.current_debt', 'credit' => 'b.credit_limit'];
        $sortColumn = $sortColumns[strtolower(trim($sortBy))] ?? $sortColumns['name'];
        $sortDirection = strtolower(trim($sortDirection)) === 'desc' ? 'DESC' : 'ASC';
        $purchaseCardsAvailable = $db->tableExists($db->getPrefix() . 'user_purchase_cards', false);
        $builder = $db->table('users u')
            ->select('u.id, u.employee_id, u.qr_token, u.name, u.email, u.profile_image_url, u.user_type, u.is_active, u.debt_pin_hash, b.credit_limit, b.current_debt')
            ->join('balances b', 'b.user_id = u.id', 'inner')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff']);
        if ($purchaseCardsAvailable) {
            $builder->select('pc.is_locked AS purchase_card_is_locked, pc.unlocked_until AS purchase_card_unlocked_until')
                ->join('user_purchase_cards pc', 'pc.user_id = u.id', 'left');
        }
        if ($search !== '') {
            $builder->groupStart()->like('u.name', $search)->orLike('u.email', $search)->orLike('u.employee_id', $search)->orWhere('u.qr_token', $search)->groupEnd();
        }

        $total = (clone $builder)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $rows = $builder->orderBy($sortColumn, $sortDirection)->orderBy('u.id', 'ASC')
            ->limit($pageSize, ($page - 1) * $pageSize)->get()->getResultArray();
        $customers = array_map(static function (array $row) use ($purchaseCardsAvailable, $search): array {
            $row['available_credit'] = max(0, (float) $row['credit_limit'] - (float) $row['current_debt']);
            $row['is_active'] = in_array($row['is_active'] ?? false, [true, 1, '1', 't', 'true'], true);
            $row['has_debt_pin'] = trim((string) ($row['debt_pin_hash'] ?? '')) !== '';
            $row['qr_match'] = $search !== '' && hash_equals((string) ($row['qr_token'] ?? ''), $search);
            $row['authorization_mode'] = $purchaseCardsAvailable ? 'card_unlock' : 'pin';
            if ($purchaseCardsAvailable) {
                $until = trim((string) ($row['purchase_card_unlocked_until'] ?? ''));
                $isUnlocked = !ibems_bool($row['purchase_card_is_locked'] ?? true)
                    && $until !== '' && strtotime($until) !== false && strtotime($until) > time();
                $row['purchase_card_locked'] = !$isUnlocked;
                $row['purchase_card_unlocked_until'] = $isUnlocked ? $until : null;
            }
            unset($row['debt_pin_hash'], $row['qr_token'], $row['purchase_card_is_locked']);

            return $row;
        }, $rows);

        return ['status' => 'success', 'customers' => $customers, 'pagination' => [
            'page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => $totalPages,
        ]];
    }

    private static function maskPaymentAccount(string $number): string
    {
        $clean = trim($number);
        if (mb_strlen($clean) <= 4) {
            return $clean;
        }

        return str_repeat('•', max(0, mb_strlen($clean) - 4)) . mb_substr($clean, -4);
    }
}
