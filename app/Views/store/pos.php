<?= $this->extend('layouts/store') ?>

<?= $this->section('content') ?>

<h3>POS Screen</h3>

<div style="display: flex; gap: 30px; align-items: flex-start;">

    <div style="width: 50%;">
        <h4>Products</h4>

        <table border="1" cellpadding="10" cellspacing="0" width="100%">
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
                    <td>Coffee</td>
                    <td>50</td>
                    <td>100</td>
                    <td>
                        <button onclick="addToCart(1, 'Coffee', 50)">Add</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="width: 50%;">
        <h4>Cart</h4>

        <table border="1" cellpadding="10" cellspacing="0" width="100%">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody id="cart-body"></tbody>
        </table>

        <h4>Total: ₱<span id="grand-total">0</span></h4>

        <label>Payment Method:</label>
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

        <button onclick="submitTransaction()">Submit Transaction</button>

        <p id="result"></p>
    </div>
</div>

<script>
    let cart = [];

    function addToCart(productId, name, price) {
        const existing = cart.find(item => item.product_id === productId);

        if (existing) {
            existing.qty += 1;
        } else {
            cart.push({
                product_id: productId,
                name: name,
                price: price,
                qty: 1
            });
        }

        renderCart();
    }

    function renderCart() {
        const cartBody = document.getElementById('cart-body');
        const grandTotal = document.getElementById('grand-total');

        cartBody.innerHTML = '';
        let total = 0;

        cart.forEach(item => {
            const lineTotal = item.qty * item.price;
            total += lineTotal;

            cartBody.innerHTML += `
                <tr>
                    <td>${item.name}</td>
                    <td>${item.qty}</td>
                    <td>${item.price}</td>
                    <td>${lineTotal}</td>
                </tr>
            `;
        });

        grandTotal.textContent = total;
    }

    async function submitTransaction() {
        const paymentMethod = document.getElementById('payment-method').value;

        const payload = {
            customer_type: 'walk_in',
            store_id: 1,
            payment_method: paymentMethod,
            items: cart.map(item => ({
                product_id: item.product_id,
                qty: item.qty
            }))
        };

        const response = await fetch('/pos/transactions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        const data = await response.json();

        document.getElementById('result').innerText = JSON.stringify(data);

        if (data.status === 'success') {
            cart = [];
            renderCart();
        }
    }
</script>

<?= $this->endSection() ?>