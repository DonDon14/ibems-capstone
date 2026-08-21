<?= $this->extend('layouts/admin') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/admin-overview.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/admin-hierarchy.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell hierarchy-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'System blueprint',
        'title' => 'IBEMS system hierarchy',
        'description' => 'A role and workflow map showing who governs, reviews, operates, and uses each part of the system.',
        'icon' => 'bi bi-diagram-3',
    ]) ?>

    <article class="hierarchy-panel">
        <div class="hierarchy-heading">
            <div>
                <span>Authority structure</span>
                <h3>Portal and responsibility hierarchy</h3>
            </div>
            <p>Higher levels oversee policy and records; lower levels carry out store transactions or view personal information.</p>
        </div>

        <div class="hierarchy-tree" aria-label="IBEMS authority hierarchy">
            <article class="hierarchy-node hierarchy-node--root">
                <i class="bi bi-shield-check"></i>
                <div><span>System governance</span><strong>Administrator</strong><small>Users, stores, products, audit, and school-wide oversight</small></div>
            </article>
            <div class="hierarchy-connector" aria-hidden="true"></div>
            <div class="hierarchy-level hierarchy-level--two">
                <article class="hierarchy-node hierarchy-node--finance">
                    <i class="bi bi-cash-coin"></i>
                    <div><span>Financial control</span><strong>Accounting Office</strong><small>Credit profiles, employee debt, deductions, settlements, and investigations</small></div>
                </article>
                <article class="hierarchy-node hierarchy-node--review">
                    <i class="bi bi-person-check"></i>
                    <div><span>Assigned oversight</span><strong>Store Supervisor</strong><small>Assigned-store review, day variances, evidence, and handoffs</small></div>
                </article>
            </div>
            <div class="hierarchy-connector" aria-hidden="true"></div>
            <div class="hierarchy-level hierarchy-level--two">
                <article class="hierarchy-node hierarchy-node--store">
                    <i class="bi bi-shop"></i>
                    <div><span>Store execution</span><strong>Store Officer / Cashier</strong><small>POS, products, inventory, receipts, repayments, and store-day custody</small></div>
                </article>
                <article class="hierarchy-node hierarchy-node--user">
                    <i class="bi bi-person-badge"></i>
                    <div><span>Personal access</span><strong>Employee / User</strong><small>Store catalog, purchase history, receipts, credit status, and deductions</small></div>
                </article>
            </div>
        </div>
    </article>

    <article class="hierarchy-panel">
        <div class="hierarchy-heading">
            <div>
                <span>Connected workflow</span>
                <h3>How information moves through IBEMS</h3>
            </div>
            <p>Every sale connects product availability, store custody, employee credit, accounting, and audit history.</p>
        </div>
        <ol class="hierarchy-flow">
            <li><i class="bi bi-box-seam"></i><div><strong>Store catalog</strong><span>Active stores publish active products and availability.</span></div></li>
            <li><i class="bi bi-cart-check"></i><div><strong>POS transaction</strong><span>The store records items, payment allocations, customer, and receipt.</span></div></li>
            <li><i class="bi bi-wallet2"></i><div><strong>Employee account</strong><span>History shows spending per store; debt purchases update the credit balance.</span></div></li>
            <li><i class="bi bi-calculator"></i><div><strong>Accounting workflow</strong><span>Accounting reviews debt, prepares deductions, reconciles, and finalizes periods.</span></div></li>
            <li><i class="bi bi-journal-check"></i><div><strong>Oversight and audit</strong><span>Supervisors and administrators review exceptions while audit records preserve actions.</span></div></li>
        </ol>
    </article>

    <div class="hierarchy-responsibility-grid">
        <article><i class="bi bi-shield-lock"></i><h4>Authorization</h4><p>Each portal is limited to its active role and assigned scope.</p></article>
        <article><i class="bi bi-database-check"></i><h4>Single transaction record</h4><p>Receipts, store totals, employee history, and accounting derive from connected transaction data.</p></article>
        <article><i class="bi bi-clock-history"></i><h4>Historical integrity</h4><p>Inactive stores and older transactions remain readable without reopening them for new sales.</p></article>
    </div>
</section>
<?= $this->endSection() ?>
