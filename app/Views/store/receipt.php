<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20261003-total-wrap">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="history-shell">
    <?= view('components/page_header', [
        'eyebrow' => 'Store records',
        'title' => 'Receipt details',
        'description' => 'Review this transaction record.',
        'icon' => 'bi bi-receipt',
    ]) ?>

    <div id="receipt-page-content" aria-live="polite">
        <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading receipt...']) ?>
    </div>
    <div id="receipt-page-result" aria-live="assertive"></div>
    <a href="<?= site_url('store/history') ?><?= (int) request()->getGet('store_id') > 0 ? '?store_id=' . (int) request()->getGet('store_id') : '' ?>" class="secondary-btn">Back to History</a>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/vendor/qrcode-generator/qrcode.js') ?>"></script>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20261002-total-label"></script>
<script {csp-script-nonce}>
    (async function () {
        const transactionId = <?= (int) ($transaction_id ?? 0) ?>;
        const resultEl = document.getElementById("receipt-page-result");
        try {
            const response = await fetch(`/store/transactions/${transactionId}`);
            const data = await response.json();
            if (!data || data.status !== "success" || !data.transaction) {
                throw new Error(data?.message || "Unable to load receipt.");
            }

            const tx = data.transaction;
            const receipt = {
                transactionId: tx.id,
                clientTxnId: tx.client_txn_id,
                createdAt: tx.created_at,
                storeName: tx.store_name,
                customerName: tx.customer_name,
                paymentMethod: tx.payment_method,
                payments: tx.payments,
                totalAmount: tx.amount,
                items: tx.items,
                lookupUrl: `${window.location.origin}/store/receipt/${encodeURIComponent(String(tx.id))}<?= (int) request()->getGet('store_id') > 0 ? '?store_id=' . (int) request()->getGet('store_id') : '' ?>`,
            };
            window.IbemsReceipt.renderReceipt("receipt-page-content", receipt);
        } catch (error) {
            document.getElementById("receipt-page-content").innerHTML = '';
            resultEl.className = "data-state data-state--error";
            resultEl.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong></strong><small>Return to History and try opening the receipt again.</small></div>';
            resultEl.querySelector('strong').textContent = error.message || "Unable to load receipt.";
        }
    })();
</script>
<?= $this->endSection() ?>

