<?php

namespace App\Controllers;

use App\Models\BalanceModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\TransactionModel;
use App\Models\UserModel;
use App\Services\SettingService;

class DashboardController extends BaseController
{
    public function index()
    {
        $user = current_user();
        $stats = [];
        $quickActions = [];

        if ($user !== null) {
            $txnModel = new TransactionModel();
            $settingService = new SettingService();
            $threshold = (int) $settingService->get('low_stock_threshold', '10');

            if (in_array($user['role'], ['ADMIN', 'ACCOUNTING_OFFICE'], true)) {
                $stats = [
                    ['label' => 'Users', 'value' => (new UserModel())->countAllResults()],
                    ['label' => 'Stores', 'value' => (new StoreModel())->countAllResults()],
                    ['label' => 'Transactions', 'value' => $txnModel->countAllResults()],
                    ['label' => 'Debt Total', 'value' => 'PHP ' . number_format((float) ((new BalanceModel())->selectSum('current_debt')->first()['current_debt'] ?? 0), 2)],
                    ['label' => 'Low Stock Items', 'value' => (new ProductModel())->where('stock_qty <=', $threshold)->countAllResults()],
                ];
                $quickActions = [
                    ['label' => 'Open Users', 'href' => '/admin/users', 'style' => 'primary'],
                    ['label' => 'View Products', 'href' => '/admin/products', 'style' => 'outline-primary'],
                    ['label' => 'Open Settings', 'href' => '/settings', 'style' => 'outline-dark'],
                ];
            } elseif ($user['role'] === 'STORE_SYSTEM') {
                $today = date('Y-m-d');
                $assignedStore = (new StoreModel())->where('officer_id', (int) $user['id'])->first();
                $todaySales = 0;
                $todayCount = 0;
                $lowStockCount = 0;
                if ($assignedStore !== null) {
                    $todaySales = $txnModel
                        ->selectSum('amount')
                        ->where('DATE(created_at)', $today)
                        ->where('store_id', (int) $assignedStore['id'])
                        ->first()['amount'] ?? 0;
                    $todayCount = $txnModel
                        ->where('DATE(created_at)', $today)
                        ->where('store_id', (int) $assignedStore['id'])
                        ->countAllResults();
                    $lowStockCount = (new ProductModel())
                        ->where('store_id', (int) $assignedStore['id'])
                        ->where('stock_qty <=', $threshold)
                        ->countAllResults();
                }

                $stats = [
                    ['label' => 'Today Sales', 'value' => 'PHP ' . number_format((float) $todaySales, 2)],
                    ['label' => 'Today Transactions', 'value' => $todayCount],
                    ['label' => 'Low Stock Items', 'value' => $lowStockCount],
                    ['label' => 'Sync Queue', 'value' => 'Use POS Terminal'],
                ];
                $quickActions = [
                    ['label' => 'Launch POS', 'href' => '/pos', 'style' => 'success'],
                    ['label' => 'Manage Inventory', 'href' => '/store/inventory', 'style' => 'outline-warning'],
                ];
            } elseif ($user['role'] === 'USER') {
                $balanceRow = null;
                if ($user['id'] !== null) {
                    $balanceRow = (new BalanceModel())->find((int) $user['id']);
                }

                $stats = [
                    ['label' => 'My Transactions', 'value' => $txnModel->where('user_id', (int) $user['id'])->countAllResults()],
                    ['label' => 'My Debt', 'value' => 'PHP ' . number_format((float) ($balanceRow['current_debt'] ?? 0), 2)],
                    ['label' => 'Available Credit', 'value' => 'PHP ' . number_format((float) (($balanceRow['credit_limit'] ?? 0) - ($balanceRow['current_debt'] ?? 0)), 2)],
                ];
                $quickActions = [
                    ['label' => 'View My Account', 'href' => '/me', 'style' => 'primary'],
                ];
            }
        }

        return view('dashboard/index', [
            'title' => 'Dashboard',
            'user' => $user,
            'stats' => $stats,
            'quickActions' => $quickActions,
        ]);
    }
}
