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

$routes->get('user/dashboard', 'PageController::userDashboard', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/history', 'PageController::userHistory', ['filter' => 'role:USER,ADMIN']);

$routes->get('accounting/debts', 'AccountingController::debts', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN']);
