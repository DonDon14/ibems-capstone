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
        return `PHP ${Number(value || 0).toFixed(2)}`;
    }

    function formatDateTime(value) {
        const date = value ? new Date(value) : new Date();
        return date.toLocaleString();
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
        const paymentLabel = String(receipt.paymentMethod ?? receipt.payment_method ?? "").replace(/_/g, " ").toUpperCase();
        const lookupUrl = getLookupUrl(receipt);

        const rows = items
            .map((item) => `
                <tr>
                    <td>${esc(`${item.qty}x ${item.name}`)}</td>
                    <td>${money(item.lineTotal)}</td>
                </tr>
            `)
            .join("");

        const debtorLine = receipt.debtCustomerLabel
            ? `<div><span>Debtor</span><strong>${esc(receipt.debtCustomerLabel)}</strong></div>`
            : "";

        const customerLine = receipt.customerName
            ? `<div><span>Customer</span><strong>${esc(receipt.customerName)}</strong></div>`
            : "";

        return `
            <div class="ibems-receipt">
                <div class="ibems-receipt-center">
                    <p class="ibems-receipt-sub">USTP IBEMS</p>
                    <h4 class="ibems-receipt-title">RECEIPT</h4>
                </div>
                <div class="ibems-receipt-divider"></div>
                <div class="ibems-receipt-meta">
                    <div><span>Transaction #</span><strong>${esc(receipt.clientTxnId || receipt.transactionId || "-")}</strong></div>
                    <div><span>Date</span><strong>${esc(formatDateTime(receipt.createdAt || receipt.dateTime))}</strong></div>
                    <div><span>Store</span><strong>${esc(receipt.storeName || "Store")}</strong></div>
                    <div><span>Payment</span><strong>${esc(paymentLabel || "N/A")}</strong></div>
                    ${customerLine}
                    ${debtorLine}
                </div>
                <div class="ibems-receipt-divider"></div>
                <table class="ibems-receipt-lines">
                    <thead>
                        <tr><th>Item</th><th>Amount</th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
                <div class="ibems-receipt-divider"></div>
                <div class="ibems-receipt-total">
                    <span>TOTAL</span>
                    <span>${money(total)}</span>
                </div>
                <div class="ibems-receipt-meta" style="margin-top:8px;">
                    <div><span>Items</span><strong>${totalItems}</strong></div>
                </div>
                <div class="ibems-receipt-divider"></div>
                <div class="ibems-receipt-thankyou">THANK YOU</div>
                <div class="ibems-receipt-divider"></div>
                <div class="ibems-receipt-qr-wrap">
                    <img class="ibems-receipt-qr" data-qr-text="${esc(lookupUrl)}" alt="Receipt QR">
                    <a class="ibems-receipt-qr-link" href="${esc(lookupUrl)}" target="_blank" rel="noopener">Open transaction details</a>
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
                    .ibems-receipt{border:1px dashed #94a3b8;border-radius:10px;padding:12px;background:#fff}
                    .ibems-receipt-center{text-align:center}
                    .ibems-receipt-title{margin:8px 0;font-size:1.7rem;letter-spacing:1px;color:#0f172a}
                    .ibems-receipt-sub{margin:0;color:#475569;font-size:.84rem}
                    .ibems-receipt-divider{border-top:2px dashed #475569;margin:10px 0}
                    .ibems-receipt-meta{display:grid;gap:4px;font-size:.9rem;color:#0f172a}
                    .ibems-receipt-meta>div{display:flex;justify-content:space-between;gap:10px}
                    .ibems-receipt-lines{width:100%;border-collapse:collapse;margin:8px 0}
                    .ibems-receipt-lines th,.ibems-receipt-lines td{font-size:.9rem;padding:5px 2px;border:0}
                    .ibems-receipt-lines th{color:#334155;text-transform:uppercase;font-size:.76rem;letter-spacing:.3px;border-bottom:1px dashed #94a3b8}
                    .ibems-receipt-lines td:last-child,.ibems-receipt-lines th:last-child{text-align:right}
                    .ibems-receipt-total{display:flex;justify-content:space-between;font-weight:700;color:#0f172a;font-size:1rem}
                    .ibems-receipt-thankyou{text-align:center;margin:8px 0;font-size:1.5rem;font-weight:800;letter-spacing:.4px;color:#0f172a}
                    .ibems-receipt-qr-wrap{display:grid;place-items:center;gap:6px;margin-top:8px}
                    .ibems-receipt-qr{width:124px;height:124px;border:1px solid #dbe3ea;border-radius:6px;object-fit:cover;background:#fff}
                    .ibems-receipt-qr-link{font-size:.74rem;color:#1d4ed8;text-decoration:none}
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
