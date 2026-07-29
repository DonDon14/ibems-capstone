<?= $this->extend('layouts/user') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/user-portal.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="user-shell">
    <div class="user-head">
        <h3>Receipt Details</h3>
        <p>Your transaction reference view.</p>
    </div>

    <div id="user-receipt-page-content"></div>
    <p id="user-receipt-page-result" class="stores-result"></p>
    <div class="user-receipt-actions">
        <a href="<?= site_url('user/history') ?>" class="history-action alt">
            <i class="bi bi-arrow-left"></i> Back to History
        </a>
        <button id="user-receipt-page-print" type="button" class="primary-btn">
            <i class="bi bi-printer"></i> Print Receipt
        </button>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>"></script>
<script>
    (async function () {
        const transactionId = <?= (int) ($transaction_id ?? 0) ?>;
        const resultEl = document.getElementById("user-receipt-page-result");
        const printBtn = document.getElementById("user-receipt-page-print");
        let currentReceipt = null;

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
        } catch (error) {
            resultEl.textContent = error.message || "Unable to load receipt.";
            resultEl.style.color = "#b91c1c";
        }

        printBtn?.addEventListener("click", () => {
            if (currentReceipt && window.IbemsReceipt) {
                window.IbemsReceipt.printReceipt(currentReceipt);
            }
        });
    })();
</script>
<?= $this->endSection() ?>

