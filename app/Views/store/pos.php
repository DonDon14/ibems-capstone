<?= $this->extend('layouts/store') ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-pos.css') ?>">
<?= $this->endSection() ?>

<?= $this->section('content') ?>

<h3>POS Screen</h3>

<div class="pos-grid">
    <section class="panel">
        <h4>Products</h4>

        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="product-list">
                <tr>
                    <td colspan="4">Loading products...</td>
                </tr>
            </tbody>

        </table>
    </section>

    <section class="panel">
        <h4>Cart</h4>

        <table class="table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="cart-body"></tbody>
        </table>

        <h4>Total: PHP <span id="grand-total">0</span></h4>

        <label for="payment-method">Payment Method:</label>
        <select id="payment-method">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="card">Card</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="other">Other</option>
            <option value="debt">Debt</option>
            <option value="advance_payment">Advance Payment</option>
        </select>

        <br><br>

        <button id="submit-transaction" type="button">Submit Transaction</button>

        <p id="result"></p>
    </section>
</div>

<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/store-pos.js') ?>"></script>
<?= $this->endSection() ?>
