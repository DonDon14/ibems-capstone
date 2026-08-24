<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>?v=20260824a" data-user-page-style>
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20260821b" data-user-page-style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="dashboard-shell user-receipt-page">
    <?= view('components/page_header', [
        'eyebrow' => 'Personal records',
        'title' => 'Receipt details',
        'description' => 'Review and print this transaction record.',
        'icon' => 'bi bi-receipt',
    ]) ?>

    <div id="user-receipt-page-content" class="user-receipt-document" aria-live="polite">
        <?= view('components/data_state', ['type' => 'loading', 'message' => 'Loading receipt...']) ?>
    </div>
    <div id="user-receipt-page-result" class="user-receipt-result" aria-live="assertive"></div>
    <div class="user-receipt-actions">
        <a href="<?= site_url('user/history') ?>" class="secondary-btn">
            <i class="bi bi-arrow-left"></i> Back to History
        </a>
        <button id="user-receipt-page-print" type="button" class="primary-btn">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260824a" data-user-page-script data-user-page-once></script>
<script {csp-script-nonce}>
    (async function () {
        const transactionId = <?= (int) ($transaction_id ?? 0) ?>;
        const resultEl = document.getElementById("user-receipt-page-result");
        const printBtn = document.getElementById("user-receipt-page-print");
        let currentReceipt = null;
        printBtn.disabled = true;

        try {
            const response = await fetch(`/user/transactions/${transactionId}`);
            const data = await response.json();
            if (!data || data.status !== "success" || !data.transaction) {
                throw new Error(data?.message || "Unable to load receipt.");
            }

            const tx = data.transaction;
            currentReceipt = {
                transactionId: tx.id,
                clientTxnId: tx.client_txn_id,
                createdAt: tx.created_at,
                storeName: tx.store_name,
                customerName: tx.customer_name,
                paymentMethod: tx.payment_method,
                totalAmount: tx.amount,
                items: tx.items,
                lookupUrl: `${window.location.origin}/user/receipt/${encodeURIComponent(String(tx.id))}`,
            };

            window.IbemsReceipt.renderReceipt("user-receipt-page-content", currentReceipt);
            printBtn.disabled = false;
        } catch (error) {
            document.getElementById("user-receipt-page-content").innerHTML = '';
            resultEl.className = "data-state data-state--error";
            resultEl.innerHTML = '<i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong></strong><small>Return to History and try opening the receipt again.</small></div>';
            resultEl.querySelector('strong').textContent = error.message || "Unable to load receipt.";
        }

        printBtn?.addEventListener("click", () => {
            if (currentReceipt && window.IbemsReceipt) {
                window.IbemsReceipt.printReceipt(currentReceipt);
            }
        });
    })();
</script>
<?= $this->endSection() ?>

