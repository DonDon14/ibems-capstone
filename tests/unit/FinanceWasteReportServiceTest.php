<?php

use App\Services\FinanceWasteReportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FinanceWasteReportServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $tn = static fn (string $table): string => $db->getPrefix() . $table;
        foreach (['inventory_movements', 'daily_food_offerings', 'food_daily_batches', 'products', 'product_families', 'stores'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $tn($table));
        }
        $db->query('CREATE TABLE ' . $tn('stores') . ' (id INTEGER PRIMARY KEY, store_name TEXT)');
        $db->query('CREATE TABLE ' . $tn('product_families') . ' (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query('CREATE TABLE ' . $tn('products') . ' (id INTEGER PRIMARY KEY, name TEXT, unit_code TEXT)');
        $db->query('CREATE TABLE ' . $tn('inventory_movements') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, product_id INTEGER, type TEXT, qty INTEGER, reason TEXT, waste_disposition TEXT, created_at TEXT)');
        $db->query('CREATE TABLE ' . $tn('food_daily_batches') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, family_id INTEGER, business_date TEXT, closed INTEGER, leftover INTEGER, disposition TEXT)');
        $db->query('CREATE TABLE ' . $tn('daily_food_offerings') . ' (id INTEGER PRIMARY KEY, store_id INTEGER, product_id INTEGER, batch_id INTEGER, business_date TEXT, closed INTEGER, leftover INTEGER, disposition TEXT)');
        $db->resetDataCache();
        $db->table('stores')->insertBatch([['id' => 1, 'store_name' => 'Main'], ['id' => 2, 'store_name' => 'Annex']]);
        $db->table('product_families')->insert(['id' => 1, 'name' => 'Rice meal']);
        $db->table('products')->insert(['id' => 1, 'name' => 'Sandwich', 'unit_code' => 'piece']);
        $db->table('food_daily_batches')->insertBatch([
            ['id' => 1, 'store_id' => 1, 'family_id' => 1, 'business_date' => '2026-09-23', 'closed' => 1, 'leftover' => 3, 'disposition' => 'Spoiled'],
            ['id' => 2, 'store_id' => 1, 'family_id' => 1, 'business_date' => '2026-09-23', 'closed' => 1, 'leftover' => 4, 'disposition' => 'Donated'],
            ['id' => 3, 'store_id' => 2, 'family_id' => 1, 'business_date' => '2026-09-23', 'closed' => 1, 'leftover' => 2, 'disposition' => 'waste'],
        ]);
        $db->table('daily_food_offerings')->insertBatch([
            ['id' => 1, 'store_id' => 1, 'product_id' => 1, 'batch_id' => 0, 'business_date' => '2026-09-23', 'closed' => 1, 'leftover' => 2, 'disposition' => 'Discarded'],
            ['id' => 2, 'store_id' => 1, 'product_id' => 1, 'batch_id' => 1, 'business_date' => '2026-09-23', 'closed' => 1, 'leftover' => 3, 'disposition' => 'Spoiled'],
            ['id' => 3, 'store_id' => 1, 'product_id' => 1, 'batch_id' => 0, 'business_date' => '2026-09-23', 'closed' => 0, 'leftover' => 8, 'disposition' => 'Spoiled'],
        ]);
    }

    public function testExplicitWasteExcludesDonationOpenAndSharedOfferingDuplicates(): void
    {
        $report = (new FinanceWasteReportService())->build('2026-09-23', '2026-09-23', 1);
        $this->assertCount(2, $report['rows']);
        $this->assertSame(5, $report['total_quantity']);
        $this->assertSame(['Shared batch', 'Independent offering'], array_column($report['rows'], 'source'));
    }

    public function testStoreAndDateFilters(): void
    {
        $report = (new FinanceWasteReportService())->build('2026-09-23', '2026-09-23', 2);
        $this->assertSame(2, $report['total_quantity']);
        $empty = (new FinanceWasteReportService())->build('2026-09-22', '2026-09-22');
        $this->assertSame([], $empty['rows']);
    }

    public function testOnlyExplicitNegativeStockWasteCountsAtManilaBoundary(): void
    {
        Database::connect()->table('inventory_movements')->insertBatch([
            ['store_id' => 1, 'product_id' => 1, 'type' => 'adjustment', 'qty' => -4, 'reason' => 'Shelf loss', 'waste_disposition' => 'spoiled', 'created_at' => '2026-09-22 16:00:00'],
            ['store_id' => 1, 'product_id' => 1, 'type' => 'adjustment', 'qty' => -2, 'reason' => 'Discarded', 'waste_disposition' => null, 'created_at' => '2026-09-23 01:00:00'],
            ['store_id' => 1, 'product_id' => 1, 'type' => 'adjustment', 'qty' => 3, 'reason' => 'Correction', 'waste_disposition' => 'spoiled', 'created_at' => '2026-09-23 02:00:00'],
            ['store_id' => 2, 'product_id' => 1, 'type' => 'adjustment', 'qty' => -7, 'reason' => 'Other store', 'waste_disposition' => 'discarded', 'created_at' => '2026-09-23 02:00:00'],
            ['store_id' => 1, 'product_id' => 1, 'type' => 'adjustment', 'qty' => -5, 'reason' => 'Prior day', 'waste_disposition' => 'discarded', 'created_at' => '2026-09-22 15:59:59'],
        ]);

        $report = (new FinanceWasteReportService())->build('2026-09-23', '2026-09-23', 1);
        $this->assertSame(9, $report['total_quantity']);
        $this->assertSame(['piece' => 4, 'portions' => 3, 'servings' => 2], $report['unit_totals']);
        $stockRows = array_values(array_filter($report['rows'], static fn (array $row): bool => $row['source'] === 'Inventory reduction'));
        $this->assertCount(1, $stockRows);
        $this->assertSame('2026-09-23', $stockRows[0]['business_date']);
        $this->assertSame(4, $stockRows[0]['quantity']);
        $this->assertSame('piece', $stockRows[0]['unit']);
    }
}
