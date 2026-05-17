<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$pageTitle = 'Receipt';
$pageSubtitle = 'Transaction reference: ' . $transaction['reference_no'];
$settings = new \App\Services\SettingService();
$schoolName = $settings->get('school_name', 'USTP Claveria Campus');
$currency = $settings->get('currency', 'PHP');
$receiptFooter = $settings->get('receipt_footer', 'Thank you for your purchase.');
?>
<style>
.receipt-wrap { max-width: 420px; margin: 0 auto; }
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .receipt-wrap { max-width: 100%; box-shadow: none !important; border: 0 !important; }
}
</style>
<div class="d-flex justify-content-center mb-3 no-print">
    <button class="btn btn-outline-dark" onclick="window.print()">Print Receipt</button>
</div>
<div class="card page-card receipt-wrap"><div class="card-body">
<div class="text-center mb-3">
    <div class="fw-bold"><?= esc($schoolName) ?></div>
    <div class="small text-secondary">POS Receipt</div>
</div>
<div class="row mb-2">
    <div class="col-7"><strong>Reference:</strong></div><div class="col-5 text-end"><?= esc($transaction['reference_no']) ?></div>
    <div class="col-7"><strong>Date:</strong></div><div class="col-5 text-end"><?= esc($transaction['created_at']) ?></div>
    <div class="col-7"><strong>Customer:</strong></div><div class="col-5 text-end"><?= $transaction['customer_type'] === 'walk_in' ? 'WALK-IN' : esc(strtoupper($transaction['customer_type'])) ?></div>
    <div class="col-7"><strong>Payment:</strong></div><div class="col-5 text-end"><?= esc(strtoupper($transaction['payment_method'])) ?></div>
</div>
<hr>
<table class="table table-sm mb-2">
    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Total</th></tr></thead>
    <tbody><?php foreach ($items as $i): ?><tr><td>#<?= esc($i['product_id']) ?></td><td class="text-end"><?= esc($i['qty']) ?></td><td class="text-end"><?= number_format((float) $i['unit_price'], 2) ?></td><td class="text-end"><?= number_format((float) $i['line_total'], 2) ?></td></tr><?php endforeach; ?></tbody>
</table>
<hr>
<div class="d-flex justify-content-between fw-bold">
    <span>TOTAL</span>
    <span><?= esc($currency) ?> <?= number_format((float) $transaction['amount'], 2) ?></span>
</div>
<div class="text-center small text-secondary mt-3"><?= esc($receiptFooter) ?></div>
</div></div>
<?= $this->endSection() ?>
