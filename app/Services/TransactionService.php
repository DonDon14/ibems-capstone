<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreDaySessionModel;
use App\Models\StoreModel;
use App\Models\StorePaymentMethodModel;
use App\Models\TransactionItemModel;
use App\Models\TransactionModel;
use App\Models\TransactionPaymentModel;
use App\Models\UserModel;
use Config\Database;
use Config\Services;

class TransactionService
{
    public function createTransaction(array $request, int $actorId, string $role): array
    {
        $validation = Services::validation();
        $validation->setRules([
            'store_id' => 'required|is_natural_no_zero',
            'payment_method' => 'permit_empty|max_length[50]',
            'payments' => 'permit_empty',
            'customer_user_id' => 'permit_empty|is_natural_no_zero',
            'customer_type' => 'permit_empty|in_list[walk_in,faculty,staff,student]',
            'debt_pin' => 'permit_empty|regex_match[/^[0-9]{4,6}$/]',
            'debt_account_type' => 'permit_empty|in_list[employee,department]',
            'department_id' => 'permit_empty|is_natural_no_zero',
            'department_requester_user_id' => 'permit_empty|is_natural_no_zero',
            'department_requester_name' => 'permit_empty|max_length[160]',
            'department_approver_user_id' => 'permit_empty|is_natural_no_zero',
            'department_pin' => 'permit_empty|regex_match[/^[0-9]{4,6}$/]',
            'items' => 'required',
        ]);

        if (!$validation->run($request)) {
            return $this->error($validation->getErrors()[array_key_first($validation->getErrors())] ?? 'Invalid transaction payload.');
        }

        $storeId = (int) ($request['store_id'] ?? 0);
        $paymentMethod = strtolower(trim((string) ($request['payment_method'] ?? '')));
        $customerUserId = isset($request['customer_user_id']) ? (int) $request['customer_user_id'] : null;
        $customerType = (string) ($request['customer_type'] ?? 'walk_in');
        $debtPin = trim((string) ($request['debt_pin'] ?? ''));
        $debtAccountType = strtolower(trim((string) ($request['debt_account_type'] ?? 'employee')));
        $departmentId = (int) ($request['department_id'] ?? 0);
        $departmentRequesterUserId = (int) ($request['department_requester_user_id'] ?? 0);
        $departmentRequesterName = trim((string) ($request['department_requester_name'] ?? ''));
        $departmentApproverUserId = (int) ($request['department_approver_user_id'] ?? 0);
        $departmentPin = trim((string) ($request['department_pin'] ?? ''));
        $cashReceived = isset($request['cash_received']) ? (float) $request['cash_received'] : null;
        $requestedPayments = $request['payments'] ?? [];
        $items = $request['items'] ?? [];

        if (!is_array($items) || $items === []) {
            return $this->error('At least one transaction item is required.');
        }

        $itemValidation = Services::validation();
        $itemValidation->setRules([
            'product_id' => 'required|is_natural_no_zero',
            'qty' => 'required|is_natural_no_zero',
        ]);

        $aggregatedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !$itemValidation->run($item)) {
                return $this->error('Invalid item at line ' . ($index + 1) . '.');
            }

            $productId = (int) $item['product_id'];
            $qty = (int) $item['qty'];
            $aggregatedItems[$productId] = ($aggregatedItems[$productId] ?? 0) + $qty;
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->error('You cannot create transactions for this store.', 403);
        }

        $daySessionModel = new StoreDaySessionModel();
        $todaySession = $daySessionModel->getToday($storeId);
        if (!$todaySession || (string) ($todaySession['status'] ?? '') !== 'open') {
            return $this->error('Store day is not open. Open today\'s store session before creating transactions.');
        }

        $paymentMethodModel = new StorePaymentMethodModel();
        $paymentMethodModel->ensureDefaults($storeId);
        if ($paymentMethod === '' && (!is_array($requestedPayments) || $requestedPayments === [])) {
            return $this->error('Invalid or disabled payment method.');
        }

        $userModel = new UserModel();
        $customer = null;
        if ($customerUserId) {
            $customer = $userModel->getActiveUserById($customerUserId);
            if (!$customer) {
                return $this->error('Selected customer not found.');
            }
            $customerType = (string) ($customer['user_type'] ?? 'walk_in');
        }

        $productModel = new ProductModel();
        $productSnapshot = [];
        $totalAmount = 0.0;
        foreach ($aggregatedItems as $productId => $qty) {
            $product = $productModel->find($productId);
            if (!$product) {
                return $this->error('Product not found.');
            }
            if ((int) ($product['store_id'] ?? 0) !== $storeId) {
                return $this->error('Product does not belong to this store.');
            }
            if ((int) ($product['is_active'] ?? 0) !== 1) {
                return $this->error('Product is inactive: ' . ($product['name'] ?? ('#' . $productId)));
            }

            $price = (float) ($product['price'] ?? 0);
            $lineTotal = $price * $qty;
            $totalAmount += $lineTotal;

            $productSnapshot[$productId] = [
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $price,
                'line_total' => $lineTotal,
            ];
        }

        try {
            $paymentLines = $this->normalizePaymentLines(
                is_array($requestedPayments) ? $requestedPayments : [],
                $paymentMethod,
                $totalAmount,
                $cashReceived,
                $paymentMethodModel,
                $storeId
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }
        $paymentMethod = count($paymentLines) > 1 ? 'split' : (string) $paymentLines[0]['payment_method'];
        $supportsPaymentLines = $this->transactionPaymentsTableExists(Database::connect());
        if (count($paymentLines) > 1 && !$supportsPaymentLines) {
            return $this->error('Split payment is not available until the transaction payments migration is applied.');
        }
        $cashLine = array_values(array_filter($paymentLines, static fn (array $line): bool => $line['payment_method'] === 'cash'))[0] ?? null;
        $debtLine = array_values(array_filter($paymentLines, static fn (array $line): bool => $line['payment_method'] === 'debt'))[0] ?? null;
        $debtAmount = (float) ($debtLine['amount'] ?? 0);
        $cashReceived = $cashLine['cash_received'] ?? null;
        $changeDue = $cashLine['change_due'] ?? null;

        $balanceModel = new BalanceModel();
        $debtCashbookModel = new DebtCashbookEntryModel();
        $debtBalanceBefore = null;
        if ($debtLine !== null) {
            if ($debtAccountType === 'department') {
                $db = Database::connect();
                if (!$db->tableExists('department_debt_periods')) {
                    return $this->error('Department debt is unavailable until the latest database migration is applied.', 503);
                }
                if ($departmentId <= 0 || $departmentApproverUserId <= 0 || $departmentRequesterName === '') {
                    return $this->error('Select a department and approver, then identify the person requesting the purchase.');
                }
                if ($departmentPin === '') {
                    return $this->error('Enter the department approver PIN to authorize this charge.');
                }
                if ($departmentRequesterUserId > 0) {
                    $requester = $userModel->getActiveUserById($departmentRequesterUserId);
                    if (!$requester) {
                        return $this->error('Selected department requester was not found.');
                    }
                    // When a requester account is selected, its canonical name
                    // wins over client-supplied display text in the audit trail.
                    $departmentRequesterName = trim((string) ($requester['name'] ?? ''));
                }
                $customerUserId = null;
                $customer = null;
                $customerType = 'walk_in';
                $pinAuthorization = (new DepartmentAuthorizationService())->authorize(
                    $departmentId,
                    $departmentApproverUserId,
                    $departmentPin,
                    $actorId,
                    $storeId,
                    $debtAmount
                );
            } else {
                if (!$customerUserId) {
                    return $this->error('Please select a faculty/staff customer for employee debt transactions.');
                }
                if (!in_array($customerType, ['faculty', 'staff'], true)) {
                    return $this->error('Only faculty/staff can use debt payment.');
                }
                if ($debtPin === '') {
                    return $this->error('Enter the customer debt PIN to authorize this debt transaction.');
                }
                if (!$customer || trim((string) ($customer['debt_pin_hash'] ?? '')) === '') {
                    return $this->error('Selected customer has no debt PIN set. Ask them to set it in their user portal first.');
                }
                $pinAuthorization = (new DebtPinAuthorizationService())->authorize(
                    $customerUserId,
                    (string) $customer['debt_pin_hash'],
                    $debtPin,
                    $actorId,
                    $storeId,
                    $debtAmount
                );
            }
            if (($pinAuthorization['status'] ?? 'error') !== 'success') {
                return $pinAuthorization;
            }
        }

        $transactionModel = new TransactionModel();
        $transactionItemModel = new TransactionItemModel();
        $transactionPaymentModel = new TransactionPaymentModel();
        $movementModel = new InventoryMovementModel();
        $auditLogModel = new AuditLogModel();

        $db = Database::connect();
        $db->transBegin();

        $clientTxnId = uniqid('txn_', true);
        $createdAt = date('Y-m-d H:i:s');

        try {
            if ($debtLine !== null && $debtAccountType === 'employee') {
                $debtBalanceBefore = $balanceModel->getBalanceForUpdate((int) $customerUserId);
                if (!$debtBalanceBefore) {
                    throw new \RuntimeException('Balance record not found.');
                }

                $availableCredit = (float) $debtBalanceBefore['credit_limit']
                    - (float) $debtBalanceBefore['current_debt'];
                if ($availableCredit < $debtAmount) {
                    throw new \RuntimeException('Insufficient credit.');
                }
            }

            $txnId = $transactionModel->insert([
                'client_txn_id' => $clientTxnId,
                'user_id' => $customerUserId,
                'customer_type' => $customerType,
                'store_id' => $storeId,
                'amount' => $totalAmount,
                'payment_method' => $paymentMethod,
                'status' => 'completed',
                'created_at' => $createdAt,
            ]);

            if (!$txnId) {
                throw new \RuntimeException('Failed to create transaction.');
            }

            foreach ($supportsPaymentLines ? $paymentLines : [] as $paymentLine) {
                $paymentPayload = [
                    'transaction_id' => $txnId,
                    'payment_method' => $paymentLine['payment_method'],
                    'amount' => $paymentLine['amount'],
                    'cash_received' => $paymentLine['cash_received'],
                    'change_due' => $paymentLine['change_due'],
                    'created_at' => $createdAt,
                ];
                if (array_key_exists('destination_account_id', $paymentLine)) {
                    $paymentPayload['destination_account_id'] = $paymentLine['destination_account_id'];
                    $paymentPayload['destination_account_name'] = $paymentLine['destination_account_name'];
                    $paymentPayload['destination_account_number'] = $paymentLine['destination_account_number'];
                }
                if (!$transactionPaymentModel->insert($paymentPayload)) {
                    throw new \RuntimeException('Failed to create transaction payment line.');
                }
            }

            foreach ($productSnapshot as $row) {
                $product = $row['product'];
                $qty = (int) $row['qty'];

                if (!$transactionItemModel->insert([
                    'transaction_id' => $txnId,
                    'product_id' => (int) $product['id'],
                    'qty' => $qty,
                    'unit_price' => $row['unit_price'],
                    'line_total' => $row['line_total'],
                    'created_at' => $createdAt,
                ])) {
                    throw new \RuntimeException('Failed to create transaction item.');
                }

                // Atomic stock deduction to avoid race conditions during concurrent checkout.
                if (!$productModel->deductStockIfAvailable((int) $product['id'], $qty)) {
                    throw new \RuntimeException('Insufficient stock for ' . (string) ($product['name'] ?? 'product') . '.');
                }

                if (!$movementModel->insert([
                    'product_id' => (int) $product['id'],
                    'store_id' => $storeId,
                    'type' => 'sale',
                    'qty' => $qty,
                    'reason' => 'POS sale',
                    'txn_id' => $txnId,
                    'created_at' => $createdAt,
                ])) {
                    throw new \RuntimeException('Failed to create inventory movement.');
                }
            }

            if (!$auditLogModel->insert([
                'actor_id' => $actorId,
                'action' => 'CREATE_TRANSACTION',
                'entity' => 'transactions',
                'entity_id' => $txnId,
                'payload_json' => json_encode([
                    'customer_user_id' => $customerUserId,
                    'customer_type' => $customerType,
                    'debt_account_type' => $debtLine !== null ? $debtAccountType : null,
                    'department_id' => $debtAccountType === 'department' ? $departmentId : null,
                    'department_requester_user_id' => $debtAccountType === 'department' && $departmentRequesterUserId > 0 ? $departmentRequesterUserId : null,
                    'department_requester_name' => $debtAccountType === 'department' ? $departmentRequesterName : null,
                    'department_approver_user_id' => $debtAccountType === 'department' ? $departmentApproverUserId : null,
                    'store_id' => $storeId,
                    'payment_method' => $paymentMethod,
                    'amount' => $totalAmount,
                    'cash_received' => $cashReceived,
                    'change_due' => $changeDue,
                    'payments' => $paymentLines,
                    'items' => array_values(array_map(static function (int $productId, int $qty): array {
                        return ['product_id' => $productId, 'qty' => $qty];
                    }, array_keys($aggregatedItems), $aggregatedItems)),
                ]),
                'created_at' => $createdAt,
            ])) {
                throw new \RuntimeException('Failed to write audit log.');
            }

            if ($debtLine !== null && $debtAccountType === 'employee') {
                if (!$balanceModel->addDebt((int) $customerUserId, $debtAmount)) {
                    throw new \RuntimeException('Failed to update debt balance.');
                }

                $debtBalanceAfter = $balanceModel->getBalanceByUserId((int) $customerUserId);
                $creditLimit = (float) ($debtBalanceAfter['credit_limit'] ?? $debtBalanceBefore['credit_limit'] ?? 0);
                $debtBefore = (float) ($debtBalanceBefore['current_debt'] ?? 0);
                $debtAfter = (float) ($debtBalanceAfter['current_debt'] ?? ($debtBefore + $debtAmount));
                $availableAfter = max(0, $creditLimit - $debtAfter);

                $debtCashbookModel->insert([
                    'user_id' => $customerUserId,
                    'entry_type' => 'debt_purchase',
                    'direction' => 'debit',
                    'amount' => $debtAmount,
                    'debt_before' => $debtBefore,
                    'debt_after' => $debtAfter,
                    'credit_limit_snapshot' => $creditLimit,
                    'available_credit_snapshot' => $availableAfter,
                    'reference_type' => 'transaction',
                    'reference_id' => $txnId,
                    'actor_id' => $actorId > 0 ? $actorId : null,
                    'remarks' => 'POS debt purchase',
                    'meta_json' => json_encode([
                        'store_id' => $storeId,
                        'payment_method' => 'debt',
                        'transaction_payment_amount' => $debtAmount,
                        'transaction_id' => (int) $txnId,
                    ]),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            if ($debtLine !== null && $debtAccountType === 'department') {
                (new DepartmentDebtService($db))->recordPurchase(
                    $departmentId,
                    (int) $txnId,
                    $debtAmount,
                    $departmentRequesterUserId > 0 ? $departmentRequesterUserId : null,
                    $departmentRequesterName,
                    $departmentApproverUserId,
                    $actorId,
                    $storeId,
                    $createdAt
                );
            }

            if (!$db->transStatus()) {
                throw new \RuntimeException('Transaction failed.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->error($e->getMessage() !== '' ? $e->getMessage() : 'Transaction failed.');
        }

        return [
            'status' => 'success',
            'transaction_id' => (int) $txnId,
            'client_txn_id' => $clientTxnId,
            'created_at' => $createdAt,
            'total_amount' => $totalAmount,
            'cash_received' => $cashReceived,
            'change_due' => $changeDue,
            'payments' => $paymentLines,
            'code' => 200,
        ];
    }

    private function normalizePaymentLines(
        array $requestedPayments,
        string $legacyMethod,
        float $totalAmount,
        ?float $legacyCashReceived,
        StorePaymentMethodModel $paymentMethodModel,
        int $storeId
    ): array {
        $rawLines = $requestedPayments;
        if ($rawLines === []) {
            $rawLines = [[
                'payment_method' => $legacyMethod,
                'amount' => $totalAmount,
                'cash_received' => $legacyCashReceived,
            ]];
        }

        $lines = [];
        $seen = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                throw new \InvalidArgumentException('Invalid payment line.');
            }
            $method = strtolower(trim((string) ($rawLine['payment_method'] ?? '')));
            $amount = round((float) ($rawLine['amount'] ?? 0), 2);
            if ($method === '' || !$paymentMethodModel->isAllowedForStore($storeId, $method)) {
                throw new \InvalidArgumentException('Invalid or disabled payment method.');
            }
            if (isset($seen[$method])) {
                throw new \InvalidArgumentException('Duplicate payment method.');
            }
            if (!is_finite($amount) || $amount <= 0) {
                throw new \InvalidArgumentException('Each payment amount must be greater than zero.');
            }
            $seen[$method] = true;
            $lines[] = [
                'payment_method' => $method,
                'amount' => $amount,
                'cash_received' => isset($rawLine['cash_received']) ? (float) $rawLine['cash_received'] : null,
                'change_due' => null,
            ];
            $lineIndex = array_key_last($lines);
            if (!in_array($method, ['cash', 'debt'], true)) {
                $db = Database::connect();
                if ($db->tableExists('payment_destination_accounts')) {
                    $accountId = (int) ($rawLine['destination_account_id'] ?? 0);
                    $account = $accountId > 0 ? (new PaymentDestinationAccountModel())->find($accountId) : null;
                    if (!$account || (int) $account['store_id'] !== $storeId || !ibems_bool($account['is_active'] ?? false)) {
                        throw new \InvalidArgumentException('Select an active receiving account for ' . $method . '.');
                    }
                    $methodRow = $paymentMethodModel->find((int) $account['payment_method_id']);
                    if (!$methodRow || (string) $methodRow['code'] !== $method) {
                        throw new \InvalidArgumentException('The selected receiving account does not match the payment method.');
                    }
                    $lines[$lineIndex]['destination_account_id'] = $accountId;
                    $lines[$lineIndex]['destination_account_name'] = (string) $account['account_name'];
                    $lines[$lineIndex]['destination_account_number'] = (string) $account['account_number'];
                }
            }
        }

        $allocatedCents = array_sum(array_map(static fn (array $line): int => (int) round($line['amount'] * 100), $lines));
        if ($allocatedCents !== (int) round($totalAmount * 100)) {
            throw new \InvalidArgumentException('Payment amounts must equal the transaction total.');
        }

        foreach ($lines as &$line) {
            if ($line['payment_method'] !== 'cash') {
                $line['cash_received'] = null;
                continue;
            }
            $received = $line['cash_received'];
            if ($received === null && count($lines) === 1) {
                $received = $legacyCashReceived;
            }
            if ($received === null || !is_finite($received) || $received < $line['amount']) {
                throw new \InvalidArgumentException('Cash received must cover the cash payment amount.');
            }
            $line['cash_received'] = round($received, 2);
            $line['change_due'] = round($received - $line['amount'], 2);
        }
        unset($line);

        return $lines;
    }

    private function transactionPaymentsTableExists($db): bool
    {
        $tableName = $db->getPrefix() . 'transaction_payments';
        try {
            if (stripos((string) ($db->DBDriver ?? ''), 'SQLite') !== false) {
                return $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$tableName])
                    ->getRowArray() !== null;
            }
            if (stripos((string) ($db->DBDriver ?? ''), 'Postgre') !== false) {
                return $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?", [$tableName])
                    ->getRowArray() !== null;
            }
            return $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$tableName])
                ->getRowArray() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function error(string $message, int $code = 400): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }
}
