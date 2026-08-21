(function () {
    function esc(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function money(value) {
        return window.IbemsFormat?.money(value) || `PHP ${Number(value || 0).toFixed(2)}`;
    }

    function formatDateTime(value) {
        return window.IbemsFormat?.dateTime(value) || new Date(value || Date.now()).toLocaleString();
    }

    function qrImageCandidates(text) {
        const encoded = encodeURIComponent(text);
        return [
            `https://quickchart.io/qr?size=220&text=${encoded}`,
            `https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encoded}`,
            `https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=${encoded}`,
        ];
    }

    function wireQrFallback(root) {
        const host = root || document;
        const images = host.querySelectorAll(".ibems-receipt-qr[data-qr-text]");
        images.forEach((img) => {
            const raw = img.getAttribute("data-qr-text") || "";
            if (!raw) return;

            const candidates = qrImageCandidates(raw);
            let index = 0;

            const setNext = () => {
                if (index >= candidates.length) {
                    img.removeAttribute("src");
                    img.alt = "QR unavailable";
                    img.title = "Unable to load QR image from configured providers.";
                    return;
                }
                img.src = candidates[index];
                index += 1;
            };

            img.onerror = () => {
                setNext();
            };

            setNext();
        });
    }

    function getLookupUrl(receipt) {
        if (receipt.lookupUrl) {
            return receipt.lookupUrl;
        }
        if (receipt.transactionId) {
            return `${window.location.origin}/store/receipt/${encodeURIComponent(receipt.transactionId)}`;
        }
        return `${window.location.href}`;
    }

    function normalizeItems(receipt) {
        const list = Array.isArray(receipt.items) ? receipt.items : [];
        return list.map((item) => {
            const qty = Number(item.qty || 0);
            const unit = Number(item.price ?? item.unit_price ?? 0);
            const line = Number(item.line_total ?? qty * unit);
            return {
                name: String(item.name || "Item"),
                qty,
                unitPrice: unit,
                lineTotal: line,
            };
        });
    }

    function buildReceiptHtml(receipt) {
        const items = normalizeItems(receipt);
        const total = Number(receipt.totalAmount ?? receipt.amount ?? items.reduce((sum, item) => sum + item.lineTotal, 0));
        const totalItems = items.reduce((sum, item) => sum + item.qty, 0);
        const payments = Array.isArray(receipt.payments) && receipt.payments.length > 0
            ? receipt.payments
            : [{payment_method: receipt.paymentMethod ?? receipt.payment_method ?? "", amount: total, cash_received: receipt.cashReceived, change_due: receipt.changeDue}];
        const paymentLabel = payments.length > 1
            ? "SPLIT PAYMENT"
            : String(payments[0].payment_method || "").replace(/_/g, " ").toUpperCase();
        const lookupUrl = getLookupUrl(receipt);

        const rows = items
            .map((item) => `
                <tr>
                    <td><strong>${esc(item.name)}</strong><small>${money(item.unitPrice)} each</small></td>
                    <td class="ibems-receipt-qty">${item.qty}</td>
                    <td class="ibems-receipt-amount">${money(item.lineTotal)}</td>
                </tr>
            `)
            .join("");

        const debtorLine = receipt.debtCustomerLabel
            ? `<div class="ibems-receipt-detail"><span>Debtor</span><strong>${esc(receipt.debtCustomerLabel)}</strong></div>`
            : "";

        const customerLine = receipt.customerName
            ? `<div class="ibems-receipt-detail"><span>Customer</span><strong>${esc(receipt.customerName)}</strong></div>`
            : "";

        const showPaymentLines = payments.length > 1 || payments.some((payment) => String(payment.destination_account_name || "").trim() !== "");
        const paymentLines = showPaymentLines
            ? payments.map((payment) => `<div class="ibems-receipt-payment-row"><span><strong>${esc(String(payment.payment_method || "").replace(/_/g, " ").toUpperCase())}</strong>${payment.destination_account_name ? `<small>${esc(payment.destination_account_name)}${payment.destination_account_number ? ` · ending ${esc(String(payment.destination_account_number).slice(-4))}` : ""}</small>` : ""}</span><b>${money(payment.amount)}</b></div>`).join("")
            : "";
        const cashPayment = payments.find((payment) => String(payment.payment_method || "").toLowerCase() === "cash");
        const cashTenderLines = cashPayment && Number.isFinite(Number(cashPayment.cash_received ?? receipt.cashReceived))
            ? `
                <div class="ibems-receipt-payment-row"><span><strong>Cash received</strong></span><b>${money(cashPayment.cash_received ?? receipt.cashReceived)}</b></div>
                <div class="ibems-receipt-payment-row"><span><strong>Change</strong></span><b>${money(cashPayment.change_due ?? receipt.changeDue ?? 0)}</b></div>
            `
            : "";

        return `
            <div class="ibems-receipt">
                <header class="ibems-receipt-brand">
                    <span class="ibems-receipt-mark" aria-hidden="true">IB</span>
                    <div>
                        <p class="ibems-receipt-sub">USTP IBEMS</p>
                        <h4 class="ibems-receipt-title">Transaction receipt</h4>
                    </div>
                    <span class="ibems-receipt-status">Completed</span>
                </header>

                <section class="ibems-receipt-details" aria-label="Transaction details">
                    <div class="ibems-receipt-detail is-wide"><span>Transaction number</span><strong>${esc(receipt.clientTxnId || receipt.transactionId || "-")}</strong></div>
                    <div class="ibems-receipt-detail"><span>Date</span><strong>${esc(formatDateTime(receipt.createdAt || receipt.dateTime))}</strong></div>
                    <div class="ibems-receipt-detail"><span>Store</span><strong>${esc(receipt.storeName || "Store")}</strong></div>
                    ${customerLine}
                    ${debtorLine}
                </section>

                <div class="ibems-receipt-section-head"><span>Order details</span><strong>${totalItems} item${totalItems === 1 ? "" : "s"}</strong></div>
                <table class="ibems-receipt-lines">
                    <thead>
                        <tr><th>Item</th><th>Qty</th><th>Amount</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>

                <section class="ibems-receipt-payment" aria-label="Payment details">
                    <div class="ibems-receipt-section-head"><span>Payment</span><strong class="ibems-receipt-payment-pill">${esc(paymentLabel || "N/A")}</strong></div>
                    ${paymentLines}
                    ${cashTenderLines}
                </section>

                <div class="ibems-receipt-total"><span>Total paid</span><strong>${money(total)}</strong></div>

                <div class="ibems-receipt-thankyou"><strong>Thank you for your purchase</strong><span>Keep this receipt for your records.</span></div>
                <div class="ibems-receipt-qr-wrap">
                    <img class="ibems-receipt-qr" data-qr-text="${esc(lookupUrl)}" alt="Receipt QR">
                    <div><strong>Verify this receipt</strong><span>Scan the QR code or open the transaction record.</span><a class="ibems-receipt-qr-link" href="${esc(lookupUrl)}" target="_blank" rel="noopener">Open transaction details</a></div>
                </div>
            </div>
        `;
    }

    function renderReceipt(targetId, receipt) {
        const target = typeof targetId === "string" ? document.getElementById(targetId) : targetId;
        if (!target) return;
        target.innerHTML = buildReceiptHtml(receipt);
        wireQrFallback(target);
    }

    function printReceipt(receipt) {
        const popup = window.open("", "_blank", "width=780,height=920");
        if (!popup) return false;

        const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Receipt ${esc(receipt.clientTxnId || receipt.transactionId || "")}</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 14px; color: #111; }
                    .receipt-card { max-width: 440px; margin: 0 auto; }
                    ${document.querySelector("link[href*='receipt-standard.css']") ? "" : ""}
                    *{box-sizing:border-box}
                    .ibems-receipt{overflow:hidden;border:1px solid #cbd5e1;border-radius:12px;background:#fff;color:#172033}
                    .ibems-receipt-brand{display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:10px;padding:14px;border-bottom:1px solid #dbe3ea;background:#f8fafc}
                    .ibems-receipt-mark{width:38px;height:38px;display:grid;place-items:center;border:1px solid #cbd5e1;border-radius:9px;color:#0b4a83;font-size:.72rem;font-weight:900}
                    .ibems-receipt-title{margin:2px 0 0;color:#0b3f73;font-size:1rem}.ibems-receipt-sub{margin:0;color:#64748b;font-size:.62rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
                    .ibems-receipt-status,.ibems-receipt-payment-pill{display:inline-flex;border:1px solid #a7d8be;border-radius:999px;padding:4px 8px;color:#087443;font-size:.64rem;font-weight:800}
                    .ibems-receipt-details{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;padding:14px;border-bottom:1px solid #e2e8f0}.ibems-receipt-detail{display:grid;gap:2px;min-width:0}.ibems-receipt-detail.is-wide{grid-column:1/-1}.ibems-receipt-detail span{color:#64748b;font-size:.68rem}.ibems-receipt-detail strong{overflow-wrap:anywhere;font-size:.8rem}
                    .ibems-receipt-section-head{display:flex;justify-content:space-between;align-items:center;padding:9px 14px;color:#40546b;font-size:.7rem;font-weight:800;text-transform:uppercase}
                    .ibems-receipt-lines{width:100%;border-collapse:collapse}.ibems-receipt-lines th,.ibems-receipt-lines td{padding:8px 14px;border-bottom:1px solid #e5e7eb;font-size:.8rem}.ibems-receipt-lines th{background:#f8fafc;color:#64748b;font-size:.62rem;text-transform:uppercase}.ibems-receipt-lines td strong,.ibems-receipt-lines td small{display:block}.ibems-receipt-lines td small{color:#64748b;font-size:.66rem}.ibems-receipt-qty,.ibems-receipt-lines th:nth-child(2){text-align:center}.ibems-receipt-amount,.ibems-receipt-lines th:last-child{text-align:right;white-space:nowrap}
                    .ibems-receipt-payment{border-block:1px solid #e2e8f0;background:#fbfcfe}.ibems-receipt-payment-row{display:flex;justify-content:space-between;gap:10px;padding:6px 14px;font-size:.76rem}.ibems-receipt-payment-row span,.ibems-receipt-payment-row span>*{display:block}.ibems-receipt-payment-row small{color:#64748b}
                    .ibems-receipt-total{display:flex;justify-content:space-between;margin:12px 14px;border-radius:9px;background:#0b477d;padding:11px 12px;color:#fff;font-weight:800}.ibems-receipt-thankyou{display:grid;gap:2px;padding:4px 14px 12px;text-align:center}.ibems-receipt-thankyou span{color:#64748b;font-size:.68rem}
                    .ibems-receipt-qr-wrap{display:flex;align-items:center;gap:10px;padding:12px 14px;border-top:1px solid #e2e8f0;background:#f8fafc}.ibems-receipt-qr{width:76px;height:76px;border:1px solid #dbe3ea;border-radius:8px;padding:3px;object-fit:cover}.ibems-receipt-qr-wrap>div{display:grid;gap:3px}.ibems-receipt-qr-wrap span,.ibems-receipt-qr-link{font-size:.66rem}.ibems-receipt-qr-link{color:#1d4ed8;text-decoration:none}
                    @media print{body{padding:0}.receipt-card{max-width:none}.ibems-receipt{box-shadow:none}}
                </style>
            </head>
            <body>
                <div class="receipt-card">${buildReceiptHtml(receipt)}</div>
                <script>
                    (function(){
                        var img = document.querySelector('.ibems-receipt-qr[data-qr-text]');
                        if(!img) return;
                        var text = img.getAttribute('data-qr-text') || '';
                        if(!text) return;
                        var encoded = encodeURIComponent(text);
                        var sources = [
                            'https://quickchart.io/qr?size=220&text=' + encoded,
                            'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encoded,
                            'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chl=' + encoded
                        ];
                        var i = 0;
                        var next = function(){ if(i >= sources.length) return; img.src = sources[i++]; };
                        img.onerror = next;
                        next();
                    })();
                </script>
            </body>
            </html>
        `;
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
        popup.focus();
        popup.print();
        return true;
    }

    window.IbemsReceipt = {
        buildReceiptHtml,
        renderReceipt,
        printReceipt,
        getLookupUrl,
        formatDateTime,
        wireQrFallback,
    };
})();
