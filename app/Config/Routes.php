<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'PageController::login');

$routes->post('auth/login', 'AuthController::login');
$routes->post('auth/logout', 'AuthController::logout');
$routes->get('auth/logout', 'AuthController::logout');
$routes->get('auth/me', 'AuthController::me');
$routes->get('auth/select-role', 'AuthController::selectRolePage');
$routes->post('auth/select-role', 'AuthController::selectRole');

$routes->post('pos/transactions', 'PosController::createTransaction', ['filter' => 'role:STORE_SYSTEM']);

$routes->get('login', 'PageController::login');
$routes->get('dashboard', 'PageController::dashboard', ['filter' => 'role:ADMIN,STORE_SYSTEM,STORE_SUPERVISOR,ACCOUNTING_OFFICE']);

$routes->get('admin/dashboard', 'AdminController::dashboard', ['filter' => 'role:ADMIN']);
$routes->get('admin/dashboard/data', 'AdminController::dashboardData', ['filter' => 'role:ADMIN']);
$routes->get('admin/store-ops', 'AdminController::storeOps', ['filter' => 'role:ADMIN']);
$routes->get('admin/accounting-debts', 'AdminController::accountingDebts', ['filter' => 'role:ADMIN']);
$routes->get('admin/accounting-debts/data', 'AdminController::accountingDebtsData', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view', 'AdminController::userView', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view/data', 'AdminController::userViewData', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view/(:num)', 'AdminController::userViewDetail/$1', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/create', 'AdminController::createUser', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/update', 'AdminController::updateUser', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/import-csv', 'AdminController::importUsersCsv', ['filter' => 'role:ADMIN']);
$routes->get('admin/products', 'AdminController::products', ['filter' => 'role:ADMIN']);
$routes->get('admin/products/data', 'AdminController::productsData', ['filter' => 'role:ADMIN']);
$routes->get('admin/audit', 'AdminController::audit', ['filter' => 'role:ADMIN']);
$routes->get('admin/audit/data', 'AdminController::auditData', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores', 'AdminController::stores', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/data', 'AdminController::storesData', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/(:num)', 'AdminController::storeDetails/$1', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/(:num)/data', 'AdminController::storeDetailsData/$1', ['filter' => 'role:ADMIN']);
$routes->post('admin/store-day-sessions/(:num)/review', 'AdminController::reviewStoreDayVariance/$1', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/officers', 'AdminController::officers', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/create', 'AdminController::createStore', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/update', 'AdminController::updateStore', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/toggle-status', 'AdminController::toggleStoreStatus', ['filter' => 'role:ADMIN']);

$routes->get('store-admin', 'StoreAdminController::dashboard', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/dashboard', 'StoreAdminController::dashboard', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/dashboard/data', 'StoreAdminController::dashboardData', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/stores', 'StoreAdminController::stores', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/stores/data', 'StoreAdminController::storesData', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/stores/(:num)', 'StoreAdminController::storeDetails/$1', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->get('store-admin/stores/(:num)/data', 'StoreAdminController::storeDetailsData/$1', ['filter' => 'role:STORE_SUPERVISOR']);
$routes->post('store-admin/store-day-sessions/(:num)/review', 'StoreAdminController::reviewStoreDayVariance/$1', ['filter' => 'role:STORE_SUPERVISOR']);

$routes->get('store/pos', 'StoreController::pos', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/dashboard', 'StoreController::dashboard', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/history', 'StoreController::history', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/inventory', 'StoreController::inventory', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/reports', 'StoreController::reports', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/staff-records', 'StoreController::staffRecords', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/settings', 'StoreController::settings', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/my-stores', 'StoreController::myStores', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/products', 'StoreController::products', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/categories', 'StoreController::categories', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/categories/create', 'StoreController::createCategory', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/categories/update', 'StoreController::updateCategory', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/categories/delete', 'StoreController::deleteCategory', ['filter' => 'role:STORE_SYSTEM']);
$routes->get('store/payment-methods', 'StoreController::paymentMethods', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/payment-methods/create', 'StoreController::createPaymentMethod', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/payment-methods/update', 'StoreController::updatePaymentMethod', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/payment-methods/delete', 'StoreController::deletePaymentMethod', ['filter' => 'role:STORE_SYSTEM']);
$routes->get('store/day-session/status', 'StoreController::daySessionStatus', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/day-session/open', 'StoreController::openDaySession', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/day-session/close', 'StoreController::closeDaySession', ['filter' => 'role:STORE_SYSTEM']);
$routes->get('store/opening-balance/status', 'StoreController::openingBalanceStatus', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/opening-balance/set', 'StoreController::setOpeningBalance', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/opening-balance/reset', 'StoreController::resetOpeningBalance', ['filter' => 'role:ADMIN']);
$routes->get('store/cash-movements', 'StoreController::cashMovements', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/cash-movements/create', 'StoreController::createCashMovement', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/debt-repayments/create', 'StoreController::createDebtRepayment', ['filter' => 'role:STORE_SYSTEM']);
$routes->get('store/reports/summary', 'StoreController::reportSummary', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/debt-customers', 'StoreController::debtCustomers', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/transactions', 'StoreController::transactions', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/staff-transactions', 'StoreController::staffTransactions', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/transactions/(:num)', 'StoreController::transactionDetails/$1', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/receipt/(:num)', 'StoreController::receiptPage/$1', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/inventory/movements', 'StoreController::inventoryMovements', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/inventory/restock', 'StoreController::restock', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/inventory/adjust-stock', 'StoreController::adjustStock', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/inventory/add-product', 'StoreController::addProduct', ['filter' => 'role:STORE_SYSTEM']);
$routes->post('store/inventory/update-product', 'StoreController::updateProduct', ['filter' => 'role:STORE_SYSTEM']);

$routes->get('user/dashboard', 'UserController::dashboard', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/dashboard/data', 'UserController::dashboardData', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/debt-pin/status', 'UserController::debtPinStatus', ['filter' => 'role:USER,ADMIN']);
$routes->post('user/debt-pin/set', 'UserController::setDebtPin', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/history', 'UserController::history', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/summary', 'UserController::summary', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/transactions', 'UserController::transactions', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/transactions/(:num)', 'UserController::transactionDetails/$1', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/receipt/(:num)', 'UserController::receiptPage/$1', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/cashbook', 'UserController::cashbook', ['filter' => 'role:USER,ADMIN']);

$routes->get('accounting/dashboard', 'AccountingController::dashboard', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/dashboard/data', 'AccountingController::dashboardData', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts', 'AccountingController::debts', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/data', 'AccountingController::debtsData', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/profile', 'AccountingController::debtProfile', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/history', 'AccountingController::debtHistory', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/daily-summary', 'AccountingController::dailySummary', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/deduction-workflow', 'AccountingController::deductionWorkflowData', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/deduction-periods', 'AccountingController::createDeductionPeriod', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->post('accounting/deduction-batches', 'AccountingController::prepareDeductionBatch', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->post('accounting/deduction-batch-items/(:num)/confirm', 'AccountingController::confirmDeductionResult/$1', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->get('accounting/settlement/preview', 'AccountingController::settlementPreview', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/settlement/apply', 'AccountingController::applySettlementRun', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->get('accounting/settlement/runs', 'AccountingController::settlementRuns', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/settlement/runs/(:num)', 'AccountingController::settlementRunDetails/$1', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/preview-csv', 'AccountingController::previewImportCsv', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/import-csv', 'AccountingController::importCsv', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->post('accounting/debts/deduct', 'AccountingController::deductDebt', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->post('accounting/debts/deduct-full', 'AccountingController::deductFullDebt', ['filter' => 'role:ACCOUNTING_OFFICE']);
$routes->post('accounting/debts/credit-limit', 'AccountingController::updateCreditLimit', ['filter' => 'role:ACCOUNTING_OFFICE']);
