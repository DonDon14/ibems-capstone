<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'AuthController::login');
$routes->get('/login', 'AuthController::login');
$routes->post('/auth/login', 'AuthController::attempt');
$routes->post('/auth/logout', 'AuthController::logout', ['filter' => 'auth']);

$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/dashboard', 'DashboardController::index');
    $routes->get('/me', 'UserPortalController::index');
    $routes->post('/me/profile', 'UserPortalController::updateProfile');
    $routes->post('/me/password', 'UserPortalController::updatePassword');

    $routes->group('/admin', ['filter' => 'role:ADMIN'], static function ($routes) {
        $routes->get('users', 'Admin\\UserController::index');
        $routes->post('users', 'Admin\\UserController::store');
        $routes->get('stores', 'Admin\\StoreController::index');
        $routes->post('stores', 'Admin\\StoreController::store');
        $routes->get('products', 'Admin\\ProductController::index');
    });

    $routes->group('/store', ['filter' => 'role:STORE_SYSTEM'], static function ($routes) {
        $routes->get('inventory', 'Store\\InventoryController::index');
        $routes->post('inventory/products', 'Store\\InventoryController::createProduct');
        $routes->post('inventory/products/(:num)/stock', 'Store\\InventoryController::updateStock/$1');
        $routes->post('inventory/products/(:num)/photo', 'Store\\InventoryController::updatePhoto/$1');
        $routes->post('inventory/products/(:num)/toggle', 'Store\\InventoryController::toggleStatus/$1');
    });

    $routes->group('/hr', ['filter' => 'role:ADMIN,ACCOUNTING_OFFICE'], static function ($routes) {
        $routes->get('import-csv', 'HrController::index');
        $routes->post('import-csv', 'HrController::importCsv');
        $routes->get('import-csv/(:num)/result', 'HrController::result/$1');
    });

    $routes->group('/pos', ['filter' => 'role:STORE_SYSTEM,ADMIN'], static function ($routes) {
        $routes->get('/', 'PosController::index');
        $routes->post('scan', 'PosController::scan');
        $routes->post('transactions', 'PosController::createTransaction');
        $routes->get('receipt/(:segment)', 'PosController::receipt/$1');
    });

    $routes->post('/sync/transactions/batch', 'SyncController::syncTransactionsBatch', ['filter' => 'role:STORE_SYSTEM,ADMIN']);

    $routes->group('/accounting', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN'], static function ($routes) {
        $routes->get('/', 'AccountingController::index');
        $routes->post('credit-override/(:num)', 'AccountingController::creditOverride/$1');
        $routes->post('settle/global', 'AccountingController::globalSettle');
    });

    $routes->group('/reports', ['filter' => 'role:ACCOUNTING_OFFICE,ADMIN'], static function ($routes) {
        $routes->get('daily-sales', 'ReportsController::dailySales');
        $routes->get('debt-aging', 'ReportsController::debtAging');
        $routes->get('low-stock', 'ReportsController::lowStock');
        $routes->get('settlement-history', 'ReportsController::settlementHistory');
        $routes->get('audit-trail', 'ReportsController::auditTrail');
    });

    $routes->group('/settings', ['filter' => 'role:ADMIN'], static function ($routes) {
        $routes->get('/', 'SettingsController::index');
        $routes->post('/', 'SettingsController::save');
    });
});
