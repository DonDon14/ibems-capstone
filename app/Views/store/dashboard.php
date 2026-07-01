<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>
<?php
    $storeName = (string) (($activeStore['store_name'] ?? null) ?: 'Store Portal');
    $summary = is_array($summary ?? null) ? $summary : [];
    $readiness = is_array($readiness ?? null) ? $readiness : [];
    $lowStockProducts = is_array($lowStockProducts ?? null) ? $lowStockProducts : [];
    $recentTransactions = is_array($recentTransactions ?? null) ? $recentTransactions : [];
    $paymentBreakdown = is_array($paymentBreakdown ?? null) ? $paymentBreakdown : [];
    $money = static fn ($value): string => ibems_money($value);
    $paymentLabel = static function ($method): string {
        $key = strtolower((string) $method);
        return match ($key) {
            'gcash' => 'GCash',
            'walk_in' => 'Walk In',
            default => ucwords(str_replace('_', ' ', $key)),
        };
    };
?>
<section class="dashboard-shell">
    <div class="dashboard-title">
        <h3>Store Dashboard</h3>
        <p><?= esc($storeName) ?> operations snapshot and daily starting point.</p>
    </div>

    <div class="dashboard-grid">
        <?= view('components/stat_card', ['title' => 'Today Sales', 'value' => ibems_money($summary['today_sales'] ?? 0), 'icon' => 'bi bi-cash-coin', 'tone' => 'sales']) ?>
        <?= view('components/stat_card', ['title' => 'Transactions Today', 'value' => (string) ((int) ($summary['today_transactions'] ?? 0)), 'icon' => 'bi bi-receipt', 'tone' => 'users']) ?>
        <?= view('components/stat_card', ['title' => 'Active Products', 'value' => (string) ((int) ($summary['active_products'] ?? 0)), 'icon' => 'bi bi-box-seam', 'tone' => 'finance']) ?>
        <?= view('components/stat_card', ['title' => 'Low Stock Items', 'value' => (string) ((int) ($summary['low_stock'] ?? 0)), 'icon' => 'bi bi-exclamation-triangle', 'tone' => 'warning']) ?>
    </div>

    <div class="dash-panels">
        <article class="dash-panel store-readiness-panel">
            <div class="panel-heading-line">
                <h4><i class="bi bi-clipboard2-check"></i> Store Readiness</h4>
                <span class="status-pill <?= ! empty($readiness['is_opened']) ? 'is-ready' : 'is-warning' ?>">
                    <?= ! empty($readiness['is_opened']) ? 'Open Today' : (! empty($readiness['is_closed']) ? 'Closed Today' : 'Opening Required') ?>
                </span>
            </div>
            <p>
                <?= ! empty($readiness['is_opened'])
                    ? 'Today&apos;s store day is open. POS transactions can proceed.'
                    : (! empty($readiness['is_closed'])
                        ? 'Today&apos;s store day is closed. POS transactions are locked unless an admin reopens the day.'
                        : 'Open today&apos;s store day in POS before recording transactions.') ?>
            </p>
            <div class="readiness-grid">
                <div>
                    <span>Opening Cash</span>
                    <strong><?= esc($money($readiness['opening_cash'] ?? $readiness['opening_balance'] ?? 0)) ?></strong>
                    <small>Business date: <?= esc((string) ($readiness['business_date'] ?? date('Y-m-d'))) ?></small>
                </div>
                <div>
                    <span>Opening E-Cash</span>
                    <strong><?= esc($money($readiness['opening_ecash'] ?? 0)) ?></strong>
                    <small>Starting e-cash balance</small>
                </div>
                <div>
                    <span>Expected Cash</span>
                    <strong><?= esc($money($readiness['expected_cash_on_hand'] ?? 0)) ?></strong>
                    <small>Opening + cash sales + movements</small>
                </div>
                <div>
                    <span>Expected E-Cash</span>
                    <strong><?= esc($money($readiness['expected_ecash_on_hand'] ?? 0)) ?></strong>
                    <small>E-cash sales + movements</small>
                </div>
            </div>
            <div class="mini-ledger">
                <span>Cash Sales <strong><?= esc($money($readiness['cash_sales'] ?? 0)) ?></strong></span>
                <span>E-Cash Sales <strong><?= esc($money($readiness['ecash_sales'] ?? 0)) ?></strong></span>
                <span>Debt Sales <strong><?= esc($money($readiness['debt_sales'] ?? 0)) ?></strong></span>
            </div>
        </article>

        <article class="dash-panel action-quick-panel">
            <h4><i class="bi bi-lightning-charge"></i> Start Work</h4>
            <div class="quick-links action-quick-links">
                <a href="<?= site_url('store/pos') ?>">
                    <span class="quick-link-icon"><i class="bi bi-cart3"></i></span>
                    <span class="quick-link-copy">
                        <strong>Open POS</strong>
                        <small>Start selling and record today&apos;s transactions</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('store/inventory') ?>">
                    <span class="quick-link-icon"><i class="bi bi-box-seam"></i></span>
                    <span class="quick-link-copy">
                        <strong>Check Inventory</strong>
                        <small>Review stocks, low items, and product movement</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
                <a href="<?= site_url('store/reports') ?>">
                    <span class="quick-link-icon"><i class="bi bi-bar-chart-line"></i></span>
                    <span class="quick-link-copy">
                        <strong>Review Reports</strong>
                        <small>Track sales, cash activity, and store performance</small>
                    </span>
                    <i class="bi bi-arrow-right quick-link-arrow"></i>
                </a>
            </div>
        </article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <div class="panel-heading-line">
                <h4><i class="bi bi-exclamation-triangle"></i> Low Stock Watch</h4>
                <a href="<?= site_url('store/inventory') ?>" class="panel-link">Open Inventory</a>
            </div>
            <div class="stack-list">
                <?php if ($lowStockProducts !== []): ?>
                    <?php foreach ($lowStockProducts as $product): ?>
                        <a class="stack-item stack-item-link" href="<?= site_url('store/inventory') ?>">
                            <div class="stack-item-head">
                                <strong><?= esc((string) ($product['name'] ?? 'Product')) ?></strong>
                                <span><?= (int) ($product['stock_qty'] ?? 0) ?> left</span>
                            </div>
                            <div class="stack-meta">
                                <?= esc((string) ($product['sku'] ?? 'No SKU')) ?> · <?= esc((string) ($product['category'] ?? 'General')) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stack-item">
                        <div class="stack-item-head">
                            <strong>Stock levels look stable</strong>
                            <span>No urgent items</span>
                        </div>
                        <div class="stack-meta">No active products are at or below the 10-item watch level.</div>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="dash-panel">
            <div class="panel-heading-line">
                <h4><i class="bi bi-receipt"></i> Recent Transactions</h4>
                <a href="<?= site_url('store/history') ?>" class="panel-link">View History</a>
            </div>
            <div class="stack-list">
                <?php if ($recentTransactions !== []): ?>
                    <?php foreach ($recentTransactions as $txn): ?>
                        <a class="stack-item stack-item-link" href="<?= site_url('store/receipt/' . (int) ($txn['id'] ?? 0)) ?>">
                            <div class="stack-item-head">
                                <strong><?= esc($money($txn['amount'] ?? 0)) ?></strong>
                                <span><?= esc($paymentLabel($txn['payment_method'] ?? '')) ?></span>
                            </div>
                            <div class="stack-meta">
                                <?= esc((string) (($txn['customer_name'] ?? null) ?: $paymentLabel($txn['customer_type'] ?? 'Walk In'))) ?>
                                · <?= esc(ibems_datetime((string) ($txn['created_at'] ?? 'now'))) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stack-item">
                        <div class="stack-item-head">
                            <strong>No transactions yet</strong>
                            <span>Today</span>
                        </div>
                        <div class="stack-meta">New completed POS sales will appear here.</div>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </div>

    <div class="dash-panels">
        <article class="dash-panel">
            <h4><i class="bi bi-credit-card-2-front"></i> Today&apos;s Payment Mix</h4>
            <div class="stack-list">
                <?php if ($paymentBreakdown !== []): ?>
                    <?php foreach ($paymentBreakdown as $payment): ?>
                        <div class="stack-item">
                            <div class="stack-item-head">
                                <strong><?= esc($paymentLabel($payment['method'] ?? 'Unknown')) ?></strong>
                                <span><?= (int) ($payment['transactions'] ?? 0) ?> txns</span>
                            </div>
                            <div class="stack-meta"><?= esc($money($payment['sales'] ?? 0)) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stack-item">
                        <div class="stack-item-head">
                            <strong>No payments recorded today</strong>
                            <span>PHP 0.00</span>
                        </div>
                        <div class="stack-meta">Payment breakdown updates after completed sales.</div>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="dash-panel">
            <h4><i class="bi bi-shop-window"></i> Accessible Stores</h4>
            <div class="stack-list">
                <?php if (! empty($stores)): ?>
                    <?php foreach ($stores as $store): ?>
                        <div class="stack-item">
                            <div class="stack-item-head">
                                <strong><?= esc((string) ($store['store_name'] ?? 'Store')) ?></strong>
                                <span><?= (int) ($store['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?></span>
                            </div>
                            <div class="stack-meta"><?= esc((string) ($store['location'] ?? 'Store access enabled')) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="stack-item">
                        <div class="stack-item-head">
                            <strong>No assigned store</strong>
                            <span>Setup needed</span>
                        </div>
                        <div class="stack-meta">Ask an administrator to assign this account to a store.</div>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>
