<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'PageController::login');

$routes->post('auth/login', 'AuthController::login');
$routes->get('auth/logout', 'AuthController::logout');
$routes->get('auth/me', 'AuthController::me');

$routes->post('pos/transactions', 'PosController::createTransaction', ['filter' => 'role:STORE_SYSTEM,ADMIN']);

$routes->get('login', 'PageController::login');
$routes->get('dashboard', 'PageController::dashboard', ['filter' => 'role:ADMIN,STORE_SYSTEM,ACCOUNTING_OFFICE']);

$routes->get('admin/dashboard', 'AdminController::dashboard', ['filter' => 'role:ADMIN']);
$routes->get('admin/store-ops', 'AdminController::storeOps', ['filter' => 'role:ADMIN']);
$routes->get('admin/accounting-debts', 'AdminController::accountingDebts', ['filter' => 'role:ADMIN']);
$routes->get('admin/accounting-debts/data', 'AdminController::accountingDebtsData', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view', 'AdminController::userView', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view/data', 'AdminController::userViewData', ['filter' => 'role:ADMIN']);
$routes->get('admin/user-view/(:num)', 'AdminController::userViewDetail/$1', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/create', 'AdminController::createUser', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/update', 'AdminController::updateUser', ['filter' => 'role:ADMIN']);
$routes->post('admin/user-view/import-csv', 'AdminController::importUsersCsv', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores', 'AdminController::stores', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/data', 'AdminController::storesData', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/(:num)', 'AdminController::storeDetails/$1', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/(:num)/data', 'AdminController::storeDetailsData/$1', ['filter' => 'role:ADMIN']);
$routes->get('admin/stores/officers', 'AdminController::officers', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/create', 'AdminController::createStore', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/update', 'AdminController::updateStore', ['filter' => 'role:ADMIN']);
$routes->post('admin/stores/toggle-status', 'AdminController::toggleStoreStatus', ['filter' => 'role:ADMIN']);

$routes->get('store/pos', 'StoreController::pos', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/history', 'StoreController::history', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/inventory', 'StoreController::inventory', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/staff-records', 'StoreController::staffRecords', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/my-stores', 'StoreController::myStores', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/products', 'StoreController::products', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/debt-customers', 'StoreController::debtCustomers', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/transactions', 'StoreController::transactions', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/staff-transactions', 'StoreController::staffTransactions', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/transactions/(:num)', 'StoreController::transactionDetails/$1', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->get('store/inventory/movements', 'StoreController::inventoryMovements', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/inventory/restock', 'StoreController::restock', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/inventory/adjust-stock', 'StoreController::adjustStock', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/inventory/add-product', 'StoreController::addProduct', ['filter' => 'role:STORE_SYSTEM,ADMIN']);
$routes->post('store/inventory/update-product', 'StoreController::updateProduct', ['filter' => 'role:STORE_SYSTEM,ADMIN']);

$routes->get('user/dashboard', 'UserController::dashboard', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/history', 'UserController::history', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/summary', 'UserController::summary', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/transactions', 'UserController::transactions', ['filter' => 'role:USER,ADMIN']);

$routes->get('accounting/dashboard', 'AccountingController::dashboard', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts', 'AccountingController::debts', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/data', 'AccountingController::debtsData', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/profile', 'AccountingController::debtProfile', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/history', 'AccountingController::debtHistory', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->get('accounting/debts/daily-summary', 'AccountingController::dailySummary', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/import-csv', 'AccountingController::importCsv', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/deduct', 'AccountingController::deductDebt', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/deduct-full', 'AccountingController::deductFullDebt', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
$routes->post('accounting/debts/credit-limit', 'AccountingController::updateCreditLimit', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
