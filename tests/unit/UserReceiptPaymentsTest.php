<?php

use App\Controllers\UserController;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;

final class UserReceiptPaymentsTest extends CIUnitTestCase
{
    private function fixture(bool $withPayments)
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $db->query('CREATE TABLE transactions (id INTEGER, client_txn_id TEXT, created_at TEXT, payment_method TEXT, amount REAL, customer_type TEXT, user_id INTEGER, store_id INTEGER)');
        $db->query('CREATE TABLE stores (id INTEGER, store_name TEXT)');
        $db->query('CREATE TABLE users (id INTEGER, name TEXT)');
        $db->query('CREATE TABLE products (id INTEGER, name TEXT)');
        $db->query('CREATE TABLE transaction_items (id INTEGER, transaction_id INTEGER, product_id INTEGER, qty INTEGER, unit_price REAL, line_total REAL, item_name_snapshot TEXT, sku_snapshot TEXT, variant_snapshot TEXT, unit_code_snapshot TEXT)');
        $db->query("INSERT INTO stores VALUES (1, 'Fixture Store')");
        $db->query("INSERT INTO users VALUES (7, 'Fixture Customer')");
        $db->query("INSERT INTO transactions VALUES (10, 'fixture-ref', '2026-10-03 03:00:00', 'split', 30, 'faculty', 7, 1)");
        $db->query("INSERT INTO transaction_items VALUES (1, 10, 1, 2, 15, 30, 'Fixture Pen', '', '', 'piece')");
        if ($withPayments) {
            $db->query('CREATE TABLE transaction_payments (id INTEGER, transaction_id INTEGER, payment_method TEXT, amount REAL, cash_received REAL, change_due REAL)');
            $db->query("INSERT INTO transaction_payments VALUES (1, 10, 'cash', 15, 20, 5), (2, 10, 'debt', 15, NULL, NULL), (3, 99, 'cash', 999, 999, 0)");
        }
        return $db;
    }

    private function details($db, int $userId)
    {
        session()->set('user_id', $userId);
        $controller = new UserController();
        $controller->initController(Services::request(), new \CodeIgniter\HTTP\Response(config('App')), Services::logger());
        // Execute the actual controller method against an isolated connection.
        $source = file_get_contents(APPPATH . 'Controllers/UserController.php');
        $start = strpos($source, 'public function transactionDetails(');
        $end = strpos($source, 'public function receiptPage(', $start);
        $method = trim(substr($source, $start, $end - $start));
        $method = str_replace('public function transactionDetails(int $transactionId)', 'function (int $transactionId) use ($db)', $method);
        $method = str_replace('$db = Database::connect();', '', $method);
        $load = eval('return ' . $method . ';');
        return $load->call($controller, 10);
    }

    public function testOwnedReceiptHasOnlyItsSplitPaymentLinesAndTender(): void
    {
        $db = $this->fixture(true);
        try {
            $response = $this->details($db, 7);
            $payload = json_decode($response->getBody(), true);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame([
                ['payment_method' => 'cash', 'amount' => 15, 'cash_received' => 20, 'change_due' => 5],
                ['payment_method' => 'debt', 'amount' => 15, 'cash_received' => null, 'change_due' => null],
            ], $payload['transaction']['payments']);
            $this->assertSame('Fixture Pen', $payload['transaction']['items'][0]['name']);
            $this->assertSame(404, $this->details($db, 8)->getStatusCode());
            $this->assertSame(401, $this->details($db, 0)->getStatusCode());
        } finally {
            $db->close();
            session()->remove('user_id');
        }
    }

    public function testLegacyReceiptWithoutPaymentTableStillLoads(): void
    {
        $db = $this->fixture(false);
        try {
            $response = $this->details($db, 7);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame([], json_decode($response->getBody(), true)['transaction']['payments']);
        } finally {
            $db->close();
            session()->remove('user_id');
        }
    }
}
