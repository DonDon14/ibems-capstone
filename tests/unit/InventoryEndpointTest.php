<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

/**
 * @internal
 */
final class InventoryEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSchema();
        $this->withRoutes([
            ['GET', 'store/inventory/movements', 'StoreController::inventoryMovements'],
            ['POST', 'store/inventory/add-product', 'StoreController::addProduct'],
            ['POST', 'store/inventory/restock', 'StoreController::restock'],
            ['POST', 'store/inventory/adjust-stock', 'StoreController::adjustStock'],
        ]);
    }

    public function testInventoryMovementsHonorsRecentActivityLimit(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'BAR-101', 20, $now);

        for ($index = 1; $index <= 12; $index++) {
            $db->table('inventory_movements')->insert([
                'product_id' => 101,
                'store_id' => 1,
                'type' => 'restock',
                'qty' => $index,
                'reason' => 'Movement ' . $index,
                'created_at' => $now,
            ]);
        }

        $result = $this->actingAsStoreOfficer()->get('store/inventory/movements?store_id=1&limit=8');

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);
        $this->assertCount(8, $body['movements'] ?? []);
        $this->assertSame('Movement 12', $body['movements'][0]['reason'] ?? null);
    }

    public function testAddProductPersistsMetadataAndInitialStockMovement(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);

        $result = $this->inventoryPost('store/inventory/add-product', [
                'store_id' => 1,
                'sku' => 'INV-001',
                'name' => 'Notebook',
                'category' => 'Supplies',
                'supplier' => 'Campus Supplier',
                'barcode' => 'BAR-001',
                'sell_price' => 25,
                'initial_stock' => 12,
                'unit_cost' => 10,
                'low_stock_threshold' => 4,
                'location_bin' => 'A1-B2',
                'reason' => 'Opening stock',
            ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame('success', $body['status'] ?? null);

        $product = $db->table('products')->where('sku', 'INV-001')->get()->getRowArray();
        $this->assertNotNull($product);
        $this->assertSame('Campus Supplier', $product['supplier'] ?? null);
        $this->assertSame('A1-B2', $product['location_bin'] ?? null);
        $this->assertSame(4, (int) ($product['low_stock_threshold'] ?? -1));
        $this->assertSame(12, (int) ($product['stock_qty'] ?? 0));

        $movement = $db->table('inventory_movements')
            ->where('product_id', (int) $product['id'])
            ->where('type', 'restock')
            ->get()
            ->getRowArray();
        $this->assertNotNull($movement);
        $this->assertSame(12, (int) ($movement['qty'] ?? 0));
        $this->assertSame(10.0, (float) ($movement['unit_cost'] ?? 0));
        $this->assertSame(120.0, (float) ($movement['total_cost'] ?? 0));
        $this->assertSame(180.0, (float) ($movement['expected_profit'] ?? 0));
    }

    public function testAddProductRejectsDuplicateSkuWithinStore(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'DUP-SKU', 'BAR-101', 5, $now);

        $result = $this->inventoryPost('store/inventory/add-product', [
                'store_id' => 1,
                'sku' => 'DUP-SKU',
                'name' => 'Duplicate SKU',
                'sell_price' => 20,
                'initial_stock' => 1,
                'unit_cost' => 5,
            ]);

        $result->assertStatus(409);
        $body = $this->jsonBody($result);
        $this->assertSame('SKU already exists in this store.', $body['message'] ?? null);
        $this->assertSame(1, $db->table('products')->countAllResults());
    }

    public function testAddProductRejectsDuplicateBarcodeWithinStore(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'DUP-BAR', 5, $now);

        $result = $this->inventoryPost('store/inventory/add-product', [
                'store_id' => 1,
                'sku' => 'SKU-102',
                'name' => 'Duplicate Barcode',
                'barcode' => 'DUP-BAR',
                'sell_price' => 20,
                'initial_stock' => 1,
                'unit_cost' => 5,
            ]);

        $result->assertStatus(409);
        $body = $this->jsonBody($result);
        $this->assertSame('Barcode already exists in this store.', $body['message'] ?? null);
        $this->assertSame(1, $db->table('products')->countAllResults());
    }

    public function testRestockUpdatesStockPriceAndMovementCosts(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'BAR-101', 5, $now);

        $result = $this->inventoryPost('store/inventory/restock', [
                'store_id' => 1,
                'product_id' => 101,
                'qty' => 7,
                'unit_cost' => 8,
                'sell_price' => 15,
                'reason' => 'Supplier delivery',
            ]);

        $result->assertOK();

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(12, (int) ($product['stock_qty'] ?? 0));
        $this->assertSame(15.0, (float) ($product['price'] ?? 0));

        $movement = $db->table('inventory_movements')
            ->where('product_id', 101)
            ->where('type', 'restock')
            ->get()
            ->getRowArray();
        $this->assertNotNull($movement);
        $this->assertSame(7, (int) ($movement['qty'] ?? 0));
        $this->assertSame(56.0, (float) ($movement['total_cost'] ?? 0));
        $this->assertSame(49.0, (float) ($movement['expected_profit'] ?? 0));
    }

    public function testRestockRejectsFractionalQuantityWithoutChangingStock(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'BAR-101', 5, $now);

        $result = $this->inventoryPost('store/inventory/restock', [
            'store_id' => 1,
            'product_id' => 101,
            'qty' => 1.5,
            'unit_cost' => 8,
            'sell_price' => 15,
            'reason' => 'Invalid fractional delivery',
        ]);

        $result->assertStatus(400);
        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(5, (int) ($product['stock_qty'] ?? -1));
        $this->assertSame(0, $db->table('inventory_movements')->where('product_id', 101)->countAllResults());
    }

    public function testAdjustStockSetsActualQuantityAndWritesMovement(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'BAR-101', 5, $now);

        $result = $this->inventoryPost('store/inventory/adjust-stock', [
                'store_id' => 1,
                'product_id' => 101,
                'actual_qty' => 2,
                'reason' => 'Physical count',
            ]);

        $result->assertOK();
        $body = $this->jsonBody($result);
        $this->assertSame(-3, (int) ($body['diff_qty'] ?? 0));

        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(2, (int) ($product['stock_qty'] ?? 0));

        $movement = $db->table('inventory_movements')
            ->where('product_id', 101)
            ->where('type', 'adjustment')
            ->get()
            ->getRowArray();
        $this->assertNotNull($movement);
        $this->assertSame(-3, (int) ($movement['qty'] ?? 0));
        $this->assertSame('Physical count', $movement['reason'] ?? null);
    }

    public function testAdjustStockRejectsFractionalActualQuantityWithoutChangingStock(): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $this->seedStore($now);
        $this->seedProduct(101, 'SKU-101', 'BAR-101', 5, $now);

        $result = $this->inventoryPost('store/inventory/adjust-stock', [
            'store_id' => 1,
            'product_id' => 101,
            'actual_qty' => 2.5,
            'reason' => 'Invalid fractional count',
        ]);

        $result->assertStatus(400);
        $product = $db->table('products')->where('id', 101)->get()->getRowArray();
        $this->assertSame(5, (int) ($product['stock_qty'] ?? -1));
        $this->assertSame(0, $db->table('inventory_movements')->where('product_id', 101)->countAllResults());
    }

    private function actingAsStoreOfficer(): self
    {
        return $this->withSession([
            'logged_in' => true,
            'user_id' => 7,
            'role' => 'STORE_SYSTEM',
            'available_roles' => ['STORE_SYSTEM'],
        ]);
    }

    private function inventoryPost(string $path, array $payload)
    {
        $security = service('security');
        $payload[$security->getTokenName()] = $security->getHash();

        return $this->actingAsStoreOfficer()
            ->withBodyFormat('json')
            ->post($path, $payload);
    }

    private function jsonBody($result): array
    {
        $body = json_decode((string) $result->getJSON(), true);
        $this->assertIsArray($body);

        return $body;
    }

    private function seedStore(string $now): void
    {
        $db = Database::connect();
        $db->table('users')->insert([
            'id' => 7,
            'employee_id' => 'EMP-7',
            'name' => 'Store Officer',
            'email' => 'store.officer@example.test',
            'password_hash' => password_hash('123456', PASSWORD_BCRYPT),
            'role' => 'STORE_SYSTEM',
            'user_type' => 'staff',
            'base_salary' => 0,
            'is_active' => 1,
            'created_at' => $now,
        ]);
        $db->table('user_roles')->insert([
            'user_id' => 7,
            'role' => 'STORE_SYSTEM',
            'created_at' => $now,
        ]);
        $db->table('stores')->insert([
            'id' => 1,
            'store_name' => 'Test Store',
            'officer_id' => 7,
            'is_active' => 1,
            'created_at' => $now,
        ]);
    }

    private function seedProduct(int $id, string $sku, string $barcode, int $stockQty, string $now): void
    {
        Database::connect()->table('products')->insert([
            'id' => $id,
            'store_id' => 1,
            'sku' => $sku,
            'name' => 'Seed Product',
            'variant_label' => null,
            'category' => 'General',
            'supplier' => 'Seed Supplier',
            'image_url' => null,
            'barcode' => $barcode,
            'price' => 10,
            'stock_qty' => $stockQty,
            'low_stock_threshold' => 3,
            'location_bin' => 'Seed Bin',
            'is_active' => 1,
            'updated_at' => $now,
        ]);
    }

    private function resetSchema(): void
    {
        $db = Database::connect();
        $prefix = $db->getPrefix();
        $tn = static fn (string $name): string => $prefix . $name;

        $tables = [
            'audit_logs',
            'inventory_movements',
            'products',
            'store_categories',
            'stores',
            'user_roles',
            'users',
        ];

        foreach ($tables as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }

        $db->query('CREATE TABLE ' . $tn('users') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id TEXT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password_hash TEXT,
            debt_pin_hash TEXT,
            role TEXT NOT NULL,
            user_type TEXT NOT NULL,
            qr_token TEXT,
            profile_image_url TEXT,
            base_salary REAL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('user_roles') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('stores') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_name TEXT NOT NULL,
            officer_id INTEGER,
            logo_url TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('store_categories') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 10,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('products') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_id INTEGER NOT NULL,
            sku TEXT NOT NULL,
            name TEXT NOT NULL,
            variant_label TEXT,
            category TEXT,
            supplier TEXT,
            image_url TEXT,
            barcode TEXT,
            price REAL NOT NULL DEFAULT 0,
            stock_qty INTEGER NOT NULL DEFAULT 0,
            item_type TEXT NOT NULL DEFAULT \'stock_item\',
            stock_policy TEXT NOT NULL DEFAULT \'tracked\',
            unit_code TEXT NOT NULL DEFAULT \'piece\',
            low_stock_threshold INTEGER NOT NULL DEFAULT 10,
            location_bin TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('inventory_movements') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            store_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            qty INTEGER NOT NULL,
            unit_cost REAL,
            total_cost REAL,
            expected_profit REAL,
            reason TEXT,
            txn_id INTEGER,
            created_at TEXT
        )');

        $db->query('CREATE TABLE ' . $tn('audit_logs') . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_id INTEGER,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            payload_json TEXT,
            created_at TEXT
        )');
    }
}
