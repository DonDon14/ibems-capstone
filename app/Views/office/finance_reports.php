<?= $this->extend('layouts/office') ?>
<?php
$storeNames = array_column($storeOptions, 'store_name', 'id');
$financeRegion = static fn(array $rows): string => $rows === [] ? 'data-finance-empty' : 'tabindex="0" role="region"';
?>

<?= $this->section('content') ?>
<section class="admin-overview-shell finance-report-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Finance reporting',
        'title' => 'Sales and charge reports',
        'description' => 'Completed transactions only. Split payments are allocated to their recorded cash, debt, or other payment lines.',
        'icon' => 'bi bi-bar-chart-line',
    ]) ?>
    <form action="<?= site_url('finance/reports') ?>" method="get" class="data-panel finance-report-filters" data-portal-navigation-form>
        <div class="data-panel-body">
            <label>Period <select name="period" id="finance-period"><option value="day"<?= $period === 'day' ? ' selected' : '' ?>>Daily</option><option value="week"<?= $period === 'week' ? ' selected' : '' ?>>Weekly</option><option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Monthly</option><option value="custom"<?= $period === 'custom' ? ' selected' : '' ?>>Custom dates</option></select></label>
            <label data-finance-anchor>Date within period <input id="finance-anchor-date" type="date" name="anchor_date" value="<?= esc($anchorDate) ?>"></label>
            <label data-finance-custom>Custom from <input id="finance-from-date" type="date" name="from_date" value="<?= esc($fromDate) ?>"></label>
            <label data-finance-custom>Custom to <input id="finance-to-date" type="date" name="to_date" value="<?= esc($toDate) ?>"></label>
            <label>Store <select name="store_id"><option value="0">All stores</option><?php foreach ($storeOptions as $store): ?><option value="<?= (int) $store['id'] ?>"<?= $storeId === (int) $store['id'] ? ' selected' : '' ?>><?= esc($store['store_name']) ?></option><?php endforeach; ?></select></label>
            <button class="primary-btn" type="submit">View report</button>
            <p id="finance-filter-help" class="finance-filter-help">Daily, weekly and monthly reports use Date within period. Custom dates use Custom from and Custom to.</p>
            <p id="finance-filter-feedback" class="finance-report-error" role="alert" aria-live="assertive" aria-atomic="true" hidden></p>
        </div>
    </form>
    <?php if ($error): ?><p class="finance-report-error" role="alert" aria-atomic="true" data-finance-server-error><?= esc($error) ?></p><?php endif; ?>
    <?php if (!$error): ?><p><a class="secondary-btn" href="<?= esc(site_url('finance/reports/export') . '?' . http_build_query(['period' => 'custom', 'from_date' => $fromDate, 'to_date' => $toDate, 'store_id' => $storeId]), 'attr') ?>">Download this report (CSV)</a></p><?php endif; ?>
    <?php if (!$error): ?>
    <p class="finance-report-range">Reporting range: <?= esc($fromDate) ?> to <?= esc($toDate) ?> (Asia/Manila). Department and office breakdowns await an approved employee-to-organization mapping.</p>

    <section class="data-panel" aria-labelledby="finance-stores-title">
        <div class="data-panel-head"><h3 id="finance-stores-title">Sales by store</h3></div>
        <div class="data-panel-body finance-table-wrap" <?= $financeRegion($report['stores']) ?> aria-labelledby="finance-stores-title"><table class="finance-table"><thead><tr><th>Store</th><th>Transactions</th><th>Total</th><th>Cash</th><th>Debt / charge</th><th>Other</th></tr></thead><tbody>
            <?php foreach ($report['stores'] as $row): ?><tr><th><?= esc($row['name']) ?></th><td><?= number_format((int) $row['transactions']) ?></td><td>₱<?= number_format($row['sales_cents'] / 100, 2) ?></td><td>₱<?= number_format($row['cash_cents'] / 100, 2) ?></td><td>₱<?= number_format($row['debt_cents'] / 100, 2) ?></td><td>₱<?= number_format($row['other_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
            <?php if ($report['stores'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 6, 'type' => 'empty', 'message' => 'No stores match the selected filter.']) ?><?php endif; ?>
        </tbody><tfoot><tr><th>All shown stores</th><td><?= number_format((int) $report['totals']['transactions']) ?></td><td>₱<?= number_format($report['totals']['sales_cents'] / 100, 2) ?></td><td>₱<?= number_format($report['totals']['cash_cents'] / 100, 2) ?></td><td>₱<?= number_format($report['totals']['debt_cents'] / 100, 2) ?></td><td>₱<?= number_format($report['totals']['other_cents'] / 100, 2) ?></td></tr></tfoot></table></div>
    </section>

    <div class="finance-report-grid">
        <section class="data-panel" aria-labelledby="finance-customers-title"><div class="data-panel-head"><h3 id="finance-customers-title">Customer type</h3></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($report['customer_types']) ?> aria-labelledby="finance-customers-title"><table class="finance-table"><thead><tr><th>Type</th><th>Audience</th><th>Transactions</th><th>Sales</th></tr></thead><tbody>
            <?php foreach ($report['customer_types'] as $row): ?><tr><th><?= esc($row['customer_label']) ?></th><td><?= esc(ucfirst($row['audience'])) ?></td><td><?= number_format((int) $row['transactions']) ?></td><td>₱<?= number_format($row['sales_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
            <?php if ($report['customer_types'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'empty', 'message' => 'No sales in this range.']) ?><?php endif; ?>
        </tbody></table></div></section>
        <section class="data-panel" aria-labelledby="finance-products-title"><div class="data-panel-head"><h3 id="finance-products-title">Products sold</h3></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($report['products']) ?> aria-labelledby="finance-products-title"><table class="finance-table"><thead><tr><th>Store</th><th>Product</th><th>Quantity</th><th>Revenue</th></tr></thead><tbody>
            <?php foreach ($report['products'] as $row): ?><tr><td><?= esc($storeNames[(int) $row['store_id']] ?? ('Store #' . $row['store_id'])) ?></td><th><?= esc($row['product_label']) ?></th><td><?= number_format((int) $row['quantity']) ?></td><td>₱<?= number_format($row['revenue_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
            <?php if ($report['products'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'empty', 'message' => 'No product lines in this range.']) ?><?php endif; ?>
        </tbody></table></div></section>
    </div>

    <section class="data-panel" aria-labelledby="finance-employees-title"><div class="data-panel-head"><h3 id="finance-employees-title">Purchases by employee</h3></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($report['employee_purchases']) ?> aria-labelledby="finance-employees-title"><table class="finance-table"><thead><tr><th>Employee</th><th>ID</th><th>Transactions</th><th>Purchases</th></tr></thead><tbody>
        <?php foreach ($report['employee_purchases'] as $row): ?><tr><th><?= esc($row['employee_name']) ?></th><td><?= esc($row['employee_id']) ?></td><td><?= number_format((int) $row['transactions']) ?></td><td>₱<?= number_format($row['sales_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
        <?php if ($report['employee_purchases'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 4, 'type' => 'empty', 'message' => 'No faculty or staff purchases in this range.']) ?><?php endif; ?>
    </tbody></table></div></section>

    <section class="data-panel" aria-labelledby="finance-charges-title"><div class="data-panel-head"><h3 id="finance-charges-title">Debt / charge transactions</h3></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($report['charges']) ?> aria-labelledby="finance-charges-title"><table class="finance-table"><thead><tr><th>Recorded</th><th>Store</th><th>Account</th><th>Transaction</th><th>Charge</th></tr></thead><tbody>
        <?php foreach ($report['charges'] as $row): ?><tr><td><?= esc((new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M j, Y g:i A')) ?></td><td><?= esc($row['store_name']) ?></td><th><?= esc($row['department_name'] ? 'Department · ' . $row['department_name'] : (($row['employee_name'] ?? 'Unknown employee') . ' · ' . ($row['employee_id'] ?? 'No ID'))) ?></th><td><?= esc($row['client_txn_id']) ?></td><td>₱<?= number_format($row['charge_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
        <?php if ($report['charges'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 5, 'type' => 'empty', 'message' => 'No debt charges in this range.']) ?><?php endif; ?>
    </tbody></table></div></section>

    <section class="data-panel" aria-labelledby="finance-deductions-title"><div class="data-panel-head"><h3 id="finance-deductions-title">Confirmed deductions</h3><p>All stores · <?= number_format($deductions['count']) ?> entries · ₱<?= number_format($deductions['total_cents'] / 100, 2) ?></p></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($deductions['rows']) ?> aria-labelledby="finance-deductions-title"><table class="finance-table"><thead><tr><th>Recorded</th><th>Employee</th><th>Source</th><th>Period / reference</th><th>Deducted</th></tr></thead><tbody>
        <?php foreach ($deductions['rows'] as $row): ?><tr><td><?= esc((new \DateTimeImmutable($row['recorded_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('M j, Y g:i A')) ?></td><th><?= esc($row['employee_name'] . ' · ' . ($row['employee_id'] ?? 'No ID')) ?></th><td><?= esc($row['source']) ?></td><td><?= esc(($row['period_code'] ? $row['period_code'] . ' · ' : '') . $row['reference']) ?></td><td>₱<?= number_format($row['amount_cents'] / 100, 2) ?></td></tr><?php endforeach; ?>
        <?php if ($deductions['rows'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 5, 'type' => 'empty', 'message' => 'No confirmed deductions in this date range.']) ?><?php endif; ?>
    </tbody></table></div></section>

    <section class="data-panel" aria-labelledby="finance-waste-title"><div class="data-panel-head"><h3 id="finance-waste-title">Explicit waste</h3><p>Closed food leftovers or stock reductions marked discarded or spoiled; quantities are totaled by unit, with no cost estimate</p></div><div class="data-panel-body finance-table-wrap" <?= $financeRegion($waste['rows']) ?> aria-labelledby="finance-waste-title"><table class="finance-table"><thead><tr><th>Business date</th><th>Store</th><th>Item</th><th>Record</th><th>Disposition</th><th>Quantity</th></tr></thead><tbody>
        <?php foreach ($waste['rows'] as $row): ?><tr><td><?= esc($row['business_date']) ?></td><td><?= esc($row['store_name']) ?></td><th><?= esc($row['item_name']) ?></th><td><?= esc($row['source']) ?></td><td><?= esc(ucfirst($row['disposition'])) ?></td><td><?= number_format($row['quantity']) ?> <?= esc($row['unit']) ?></td></tr><?php endforeach; ?>
        <?php if ($waste['rows'] === []): ?><?= view('components/data_state', ['tag' => 'tr', 'colspan' => 6, 'type' => 'empty', 'message' => 'No explicitly discarded or spoiled items in this range.']) ?><?php endif; ?>
    </tbody></table><?php if (($waste['unit_totals'] ?? []) !== []): ?><p class="finance-waste-subtotals">Totals by unit: <?php foreach ($waste['unit_totals'] as $unit => $quantity): ?><span><?= number_format($quantity) ?> <?= esc($unit) ?></span><?php endforeach; ?></p><?php endif; ?></div></section>
    <?php endif; ?>
</section>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/finance-reports.css') ?>?v=20261004-filter-states">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/finance-report-filters.js') ?>?v=20261004-period-fields"></script>
<?= $this->endSection() ?>
