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
$routes->get('store/products', 'StoreController::products', ['filter' => 'role:STORE_SYSTEM,ADMIN']);

$routes->get('user/dashboard', 'PageController::userDashboard', ['filter' => 'role:USER,ADMIN']);
$routes->get('user/history', 'PageController::userHistory', ['filter' => 'role:USER,ADMIN']);
