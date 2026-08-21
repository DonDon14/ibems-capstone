<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/receipt-standard.css') ?>?v=20260821b">
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<section class="history-shell">
    <div class="history-toolbar">
        <div>
            <h3>Receipt Details</h3>
            <p>Transaction reference view.</p>
        </div>
    </div>

    <div id="receipt-page-content"></div>
    <p id="receipt-page-result" class="history-result"></p>
</section>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/receipt-standard.js') ?>?v=20260821a"></script>
<script>
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
            };
            window.IbemsReceipt.renderReceipt("receipt-page-content", receipt);
        } catch (error) {
            resultEl.textContent = error.message || "Unable to load receipt.";
            resultEl.style.color = "#b91c1c";
        }
    })();
</script>
<?= $this->endSection() ?>

