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

    function printWhenQrReady(popup, maximumWaitMs = 5000) {
        const startedAt = Date.now();
        let hasPrinted = false;

        const printOnce = () => {
            if (hasPrinted || popup.closed) return;
            hasPrinted = true;
            popup.focus();
            popup.print();
        };

        const check = () => {
            if (hasPrinted || popup.closed) return;
            const stylesheet = popup.document.querySelector("link[data-receipt-print-styles]");
            const stylesReady = !stylesheet || Boolean(stylesheet.sheet);
            const qr = popup.document.querySelector(".ibems-receipt-qr");
            const qrReady = !qr || (qr.complete && Number(qr.naturalWidth || 0) > 0);
            if (stylesReady && qrReady) {
                printOnce();
                return;
            }
            if (Date.now() - startedAt >= maximumWaitMs) {
                printOnce();
                return;
            }
            window.setTimeout(check, 100);
        };

        check();
    }

    function printReceipt(receipt) {
        const popup = window.open("", "_blank", "width=780,height=920");
        if (!popup) return false;

        const receiptStyleHref = document.querySelector("link[href*='receipt-standard.css']")?.href
            || new URL("/assets/css/receipt-standard.css", window.location.origin).href;

        const html = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Receipt ${esc(receipt.clientTxnId || receipt.transactionId || "")}</title>
                <link rel="stylesheet" href="${esc(receiptStyleHref)}" data-receipt-print-styles>
            </head>
            <body class="ibems-receipt-print-document">
                <main class="receipt-card receipt-print-card">${buildReceiptHtml(receipt)}</main>
            </body>
            </html>
        `;
        popup.document.open();
        popup.document.write(html);
        popup.document.close();
        wireQrFallback(popup.document);
        printWhenQrReady(popup);
        return true;
    }

    window.IbemsReceipt = {
        buildReceiptHtml,
        renderReceipt,
        printReceipt,
        getLookupUrl,
        formatDateTime,
        wireQrFallback,
        printWhenQrReady,
    };
})();
