let cart = [];
let productsCache = [];
const STORE_ID = 1;

function getProductById(productId) {
    return productsCache.find((p) => Number(p.id) === Number(productId));
}

function getCartQty(productId) {
    const item = cart.find((entry) => entry.product_id === productId);
    return item ? item.qty : 0;
}

function canAddMore(productId) {
    const product = getProductById(productId);
    if (!product) return false;

    const stock = Number(product.stock_qty || 0);
    const cartQty = getCartQty(productId);
    return cartQty < stock;
}

function addToCart(productId, name, price) {
    if (!canAddMore(productId)) {
        setResult("Cannot add more. Reached available stock.", "error");
        return;
    }

    const existing = cart.find((item) => item.product_id === productId);

    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({
            product_id: productId,
            name,
            price,
            qty: 1,
        });
    }

    setResult("", "");
    renderCart();
}

function increaseQty(productId) {
    if (!canAddMore(productId)) {
        setResult("Cannot increase. Reached available stock.", "error");
        return;
    }

    const item = cart.find((entry) => entry.product_id === productId);
    if (!item) return;

    item.qty += 1;
    setResult("", "");
    renderCart();
}

function decreaseQty(productId) {
    const item = cart.find((entry) => entry.product_id === productId);
    if (!item) return;

    if (item.qty > 1) {
        item.qty -= 1;
    } else {
        removeFromCart(productId);
        return;
    }

    renderCart();
}

function removeFromCart(productId) {
    cart = cart.filter((item) => item.product_id !== productId);
    renderCart();
}

function setResult(message, type) {
    const resultEl = document.getElementById("result");
    resultEl.textContent = message || "";
    resultEl.style.color = type === "error" ? "#b91c1c" : "#166534";
}

function renderCart() {
    const cartBody = document.getElementById("cart-body");
    const grandTotal = document.getElementById("grand-total");

    cartBody.innerHTML = "";
    let total = 0;

    cart.forEach((item) => {
        const lineTotal = item.qty * item.price;
        total += lineTotal;

        const product = getProductById(item.product_id);
        const maxedOut = product ? item.qty >= Number(product.stock_qty || 0) : false;


        cartBody.innerHTML += `
            <tr>
                <td>${item.name}</td>
                <td>
                    <button class="cart-inc" data-product-id="${item.product_id}" type="button" ${maxedOut ? "disabled" : ""}>+</button>
                    <span style="display:inline-block; min-width:24px; text-align:center;">${item.qty}</span>
                    <button class="cart-inc" data-product-id="${item.product_id}" type="button">+</button>
                </td>
                <td>${item.price}</td>
                <td>${lineTotal}</td>
                <td>
                    <button class="cart-remove" data-product-id="${item.product_id}" type="button">Remove</button>
                </td>
            </tr>
        `;
    });

    if (cart.length === 0) {
        cartBody.innerHTML = `
            <tr>
                <td colspan="5">Cart is empty.</td>
            </tr>
        `;
    }

    grandTotal.textContent = total;
}

function renderProducts(products) {
    const productList = document.getElementById("product-list");

    if (!Array.isArray(products) || products.length === 0) {
        productList.innerHTML = '<tr><td colspan="4">No active products found.</td></tr>';
        return;
    }

    productList.innerHTML = products
         .map((product) => {
            const stock = Number(product.stock_qty || 0);
            const inCart = getCartQty(Number(product.id));
            const canAdd = inCart < stock;
            const addLabel = stock <= 0 ? "Out of stock" : "Add";

            return `
                <tr>
                    <td>${product.name}</td>
                    <td>${product.price}</td>
                    <td>${stock} (in cart: ${inCart})</td>
                    <td>
                        <button
                            class="add-to-cart"
                            data-product-id="${product.id}"
                            data-name="${product.name}"
                            data-price="${product.price}"
                            type="button"
                            ${canAdd ? "" : "disabled"}
                        >
                            ${addLabel}
                        </button>
                    </td>
                </tr>
            `;
        })
        .join("");
}

async function loadProducts() {
    const productList = document.getElementById("product-list");

    try {
        const response = await fetch(`/store/products?store_id=${STORE_ID}`);
        const data = await response.json();

        if (!data || data.status !== "success") {
            productList.innerHTML = '<tr><td colspan="4">Unable to load products.</td></tr>';
            return;
        }

        productsCache = Array.isArray(data.products) ? data.products : [];
        renderProducts(productsCache);
    } catch (error) {
        productList.innerHTML = '<tr><td colspan="4">Unable to load products.</td></tr>';
    }
}

async function submitTransaction() {
    const paymentMethod = document.getElementById("payment-method").value;

    if (cart.length === 0) {
        setResult("Add at least one item before submitting.", "error");
        return;
    }

    const payload = {
        customer_type: "walk_in",
        store_id: STORE_ID,
        payment_method: paymentMethod,
        items: cart.map((item) => ({
            product_id: item.product_id,
            qty: item.qty,
        })),
    };

    try {
        const response = await fetch("/pos/transactions", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify(payload),
        });

        const data = await response.json();

        if (data.status === "success") {
            setResult("Transaction successful.", "ok");
            cart = [];
            renderCart();
            await loadProducts();
            return;
        }

        const message =
            data?.message ||
            data?.messages?.error ||
            "Transaction failed.";
        setResult(message, "error");
    } catch (error) {
        setResult("Unable to submit transaction.", "error");
    }
}

document.getElementById("product-list").addEventListener("click", (event) => {
    const button = event.target.closest(".add-to-cart");
    if (!button) return;

    addToCart(
        Number(button.dataset.productId),
        button.dataset.name || "",
        Number(button.dataset.price)
    );
});

document.getElementById("cart-body").addEventListener("click", (event) => {
    const incBtn = event.target.closest(".cart-inc");
    const decBtn = event.target.closest(".cart-dec");
    const removeBtn = event.target.closest(".cart-remove");

    if (incBtn) {
        increaseQty(Number(incBtn.dataset.productId));
        return;
    }

    if (decBtn) {
        decreaseQty(Number(decBtn.dataset.productId));
        return;
    }

    if (removeBtn) {
        removeFromCart(Number(removeBtn.dataset.productId));
    }
});

document.getElementById("submit-transaction").addEventListener("click", submitTransaction);

loadProducts();
renderCart();
