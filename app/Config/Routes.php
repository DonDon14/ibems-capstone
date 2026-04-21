<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->post('pos/transactions', 'PosController::createTransaction');
$routes->get('test-pos', 'PosController::testTransaction');

$routes->post('auth/login', 'AuthController::login');
$routes->get('auth/logout', 'AuthController::logout');
$routes->get('auth/me', 'AuthController::me');
$routes->get('test-login', 'AuthController::testLogin');