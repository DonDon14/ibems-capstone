<?= $this->extend('layouts/office') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell cashier-office-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'School Cashier Office',
        'title' => $head ? 'Cashier Head' : 'Collecting Officer',
        'description' => 'Review recorded sales across university stores. This office is separate from the store POS cashier.',
        'icon' => 'bi bi-receipt',
    ]) ?>

    <div class="cashier-office-notice" role="note">
        These are direct recorded-transaction totals. BE Director-reviewed store submissions arrive in Daily Reports; they follow a separate handoff. Salary deductions use the shared Deductions page and require actual result references before posting.
    </div>

    <section class="data-panel cashier-sales-panel" aria-labelledby="cashier-sales-heading">
        <div class="data-panel-head cashier-sales-head">
            <div>
                <h3 id="cashier-sales-heading">Daily sales by store</h3>
                <p>Read-only totals for the selected business date (Asia/Manila).</p>
            </div>
            <form action="<?= site_url('cashier/dashboard') ?>" method="get" class="cashier-date-filter" data-portal-navigation-form>
                <label for="cashier-business-date">Business date</label>
                <input id="cashier-business-date" type="date" name="date" value="<?= esc($selectedDate) ?>" required>
                <button class="primary-btn" type="submit">View</button>
            </form>
        </div>
        <div class="data-panel-body cashier-sales-body">
            <?php if ($error !== null): ?>
                <p class="cashier-date-error" role="alert" aria-atomic="true"><?= esc($error) ?> Showing totals for <time datetime="<?= esc($selectedDate) ?>"><?= esc($selectedDate) ?></time> instead. Choose a valid business date to change the totals.</p>
            <?php endif; ?>
            <?php if ($head): ?>
                <div class="cashier-rollup" aria-label="Cashier Office totals">
                    <div><span>Transactions</span><strong><?= number_format((int) $sales['totals']['transactions']) ?></strong></div>
                    <div><span>Total sales</span><strong>₱<?= number_format($sales['totals']['sales_cents'] / 100, 2) ?></strong></div>
                    <div><span>Cash</span><strong>₱<?= number_format($sales['totals']['cash_cents'] / 100, 2) ?></strong></div>
                    <div><span>Debt / charge</span><strong>₱<?= number_format($sales['totals']['debt_cents'] / 100, 2) ?></strong></div>
                </div>
            <?php endif; ?>
            <?php if ($sales['stores'] === []): ?>
                <?= view('components/data_state', ['type' => 'empty', 'message' => 'No stores are configured yet.']) ?>
            <?php else: ?>
            <div class="cashier-sales-table-wrap" tabindex="0" role="region" aria-labelledby="cashier-sales-heading">
                <table class="cashier-sales-table">
                    <thead><tr><th scope="col">Store</th><th scope="col">Transactions</th><th scope="col">Total sales</th><th scope="col">Cash</th><th scope="col">Debt / charge</th><th scope="col">Other payments</th></tr></thead>
                    <tbody>
                    <?php foreach ($sales['stores'] as $store): ?>
                        <tr>
                            <th scope="row"><?= esc($store['name']) ?></th>
                            <td><?= number_format((int) $store['transactions']) ?></td>
                            <td>₱<?= number_format($store['sales_cents'] / 100, 2) ?></td>
                            <td>₱<?= number_format($store['cash_cents'] / 100, 2) ?></td>
                            <td>₱<?= number_format($store['debt_cents'] / 100, 2) ?></td>
                            <td>₱<?= number_format($store['other_cents'] / 100, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>
</section>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/cashier-office.css') ?>?v=20261004-bounded-totals">
<?= $this->endSection() ?>
