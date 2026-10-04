<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class UserCatalogAvailabilityTest extends CIUnitTestCase
{
    public function testAvailabilityFilterMatchesDisplayedStockPolicy(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        try {
            $db->query('CREATE TABLE stores (id INTEGER, is_active INTEGER)');
            $db->query('CREATE TABLE products (id INTEGER, store_id INTEGER, is_active INTEGER, stock_qty INTEGER, stock_policy TEXT)');
            $db->table('stores')->insert(['id' => 1, 'is_active' => 1]);
            $db->table('products')->insertBatch([
                ['id' => 1, 'store_id' => 1, 'is_active' => 1, 'stock_qty' => 0, 'stock_policy' => 'untracked'],
                ['id' => 2, 'store_id' => 1, 'is_active' => 1, 'stock_qty' => 0, 'stock_policy' => 'tracked'],
                ['id' => 3, 'store_id' => 1, 'is_active' => 1, 'stock_qty' => 5, 'stock_policy' => 'tracked'],
                ['id' => 4, 'store_id' => 1, 'is_active' => 1, 'stock_qty' => 0, 'stock_policy' => null],
                ['id' => 5, 'store_id' => 1, 'is_active' => 0, 'stock_qty' => 5, 'stock_policy' => 'untracked'],
            ]);
            $source = file_get_contents(APPPATH . 'Controllers/UserController.php');
            $start = strpos($source, '$applyFilters = static function');
            $end = strpos($source, '};', $start) + 2;
            $closure = substr($source, $start, $end - $start);
            $q = $category = '';
            $storeId = 0;
            foreach (['available' => [1, 3], 'out' => [2, 4], '' => [1, 2, 3, 4]] as $availability => $expected) {
                eval($closure);
                $rows = $applyFilters($db->table('products p')->join('stores s', 's.id = p.store_id'))->select('p.id')->orderBy('p.id')->get()->getResultArray();
                $this->assertSame($expected, array_map('intval', array_column($rows, 'id')));
            }
        } finally {
            $db->close();
        }
    }
}
