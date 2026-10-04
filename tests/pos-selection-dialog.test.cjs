const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const main = fs.readFileSync('public/assets/js/store-pos.js', 'utf8');
const part3 = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
function extract(source, name) {
    const start = source.indexOf(`function ${name}(`);
    const end = source.indexOf('\nfunction ', start + 1);
    return source.slice(start, end < 0 ? undefined : end);
}
function fixture() {
    const frames = [], nodes = {};
    const document = { activeElement: null, getElementById: id => nodes[id], body: { classList: { add() {}, remove() {} } } };
    function node(id, extra = {}) {
        const classes = new Set();
        return nodes[id] = {
            id, isConnected: true, disabled: false, tabIndex: 0, style: {}, handlers: {}, scrollTop: 300,
            focus() { document.activeElement = this; },
            getClientRects() { return this.hidden ? [] : [{}]; },
            addEventListener(name, fn) { this.handlers[name] = fn; },
            removeAttribute(name) { delete this[name]; },
            classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) }, ...extra,
        };
    }
    const opener = node('original-opener'), fallback = node('product-search');
    document.activeElement = opener;
    function modal(id, close, content, controls) {
        const card = { scrollTop: 300 };
        return node(id, {
            style: { display: 'none' }, card, controls,
            querySelector(selector) {
                if (selector === '.receipt-card') return card;
                if (selector.includes('#confirm-transaction-content')) return content;
                if (selector.includes('[data-pick-variant]')) return this.controls.find(c => c.variant && !c.disabled) || null;
                return close;
            },
            querySelectorAll() { return this.controls; },
        });
    }
    const qrClose = node('customer-qr-close'), qrDone = node('customer-qr-done'), qrContent = node('qr-content');
    const qr = modal('customer-qr-modal', qrClose, qrContent, [qrClose, qrDone]);
    qr.classList.add('is-hidden');
    ['customer-qr-title', 'customer-qr-image', 'customer-qr-method', 'customer-qr-account', 'customer-qr-number'].forEach(id => node(id));
    const variantClose = node('product-variant-close'), cancel = node('product-variant-cancel');
    const option = node('variant-option', { variant: true }), unavailable = node('unavailable-option', { variant: true, disabled: true });
    const options = node('product-variant-options');
    const variant = modal('product-variant-modal', variantClose, options, [variantClose, unavailable, option, cancel]);
    node('product-variant-title'); node('product-variant-guidance');
    ['confirm-transaction-modal', 'receipt-modal', 'barcode-scanner-modal'].forEach(id => node(id));
    const account = { id: 1, account_name: '<Fictional account>', masked_number: '0000', image_url: '/fictional-qr.svg' };
    const context = vm.createContext({
        document, window: { requestAnimationFrame: fn => frames.push(fn) },
        paymentMethodsCache: [{ code: 'demo', label: 'Demo', destination_accounts: [account] }],
        productsCache: [{ id: 1, family_id: 1, name: '<Fictional product>', stock_qty: 5, variant_label: 'A', sku: '<SKU>', price: 2 }, { id: 2, family_id: 1, variant_label: 'B', stock_qty: 0, price: 3 }],
        openingBalanceReady: true, activeVariantFamilyKey: null,
        getCartQty: () => 0, productTracksStock: () => true, getStockState: () => ({ key: 'in' }),
        escapeHtml: v => String(v).replaceAll('<', '&lt;').replaceAll('>', '&gt;'), getInitials: () => 'FV', formatMoney: v => `PHP ${v}`, setResult() {},
    });
    const helpers = part3.slice(part3.indexOf('const posCheckoutDialogStates'), part3.indexOf('function openReceiptModal('));
    vm.runInContext(helpers + ['getPaymentMethod', 'openCustomerQrModal', 'closeCustomerQrModal'].map(n => extract(part3, n)).join('\n') + ['getProductFamilyKey', 'groupCatalogProducts', 'getCatalogImageUrl', 'openProductVariantPicker', 'closeProductVariantPicker'].map(n => extract(main, n)).join('\n'), context);
    context.bindPosCheckoutDialogKeys();
    function flush() { while (frames.length) frames.shift()(); }
    function key(modal, key, shiftKey = false) {
        let prevented = false;
        modal.handlers.keydown({ currentTarget: modal, key, shiftKey, preventDefault() { prevented = true; }, stopPropagation() {} });
        return prevented;
    }
    return { context, document, nodes, opener, fallback, account, qr, qrClose, qrDone, qrContent, variant, variantClose, option, options, cancel, flush, key };
}

test('customer QR traps boundary keys, resets scroll and restores its exact opener on Escape', () => {
    const f = fixture();
    f.context.openCustomerQrModal('demo', 1, f.opener); f.flush();
    assert.equal(f.document.activeElement, f.qrClose);
    assert.equal(f.qrContent.scrollTop, 0);
    f.qrDone.focus(); assert.equal(f.key(f.qr, 'Tab'), true);
    assert.equal(f.document.activeElement, f.qrClose);
    f.key(f.qr, 'Tab', true); assert.equal(f.document.activeElement, f.qrDone);
    f.key(f.qr, 'Escape');
    assert.equal(f.qr.style.display, 'none');
    assert.equal(f.document.activeElement, f.opener);
    assert.equal(f.nodes['customer-qr-image'].src, undefined);
});

test('customer QR close invalidates queued entry and falls back from removed, disabled or hidden openers', () => {
    for (const property of ['isConnected', 'disabled', 'hidden']) {
        const f = fixture();
        f.context.openCustomerQrModal('demo', 1, f.opener);
        f.opener[property] = property !== 'isConnected';
        f.context.closeCustomerQrModal(); f.flush();
        assert.equal(f.document.activeElement, f.fallback, property);
    }
});

test('customer QR replacement retains its original opener and missing artwork cannot open a dialog', () => {
    const f = fixture();
    f.account.image_url = ''; f.context.openCustomerQrModal('demo', 1, f.opener); f.flush();
    assert.equal(f.qr.style.display, 'none');
    f.account.image_url = '/fictional-qr.svg';
    f.context.openCustomerQrModal('demo', 1, f.opener); f.flush();
    f.context.openCustomerQrModal('demo', 1, f.qrDone); f.flush();
    f.context.closeCustomerQrModal();
    assert.equal(f.document.activeElement, f.opener);
});

test('variant picker scopes enabled entry, traps Cancel and restores opener on Escape', () => {
    const f = fixture();
    f.context.openProductVariantPicker('family-1'); f.flush();
    assert.equal(f.document.activeElement, f.option);
    assert.equal(f.options.scrollTop, 0);
    assert.match(f.options.innerHTML, /&lt;SKU&gt;/);
    f.cancel.focus(); f.key(f.variant, 'Tab'); assert.equal(f.document.activeElement, f.variantClose);
    f.key(f.variant, 'Tab', true); assert.equal(f.document.activeElement, f.cancel);
    f.key(f.variant, 'Escape');
    assert.equal(f.variant.style.display, 'none');
    assert.equal(f.context.activeVariantFamilyKey, null);
    assert.equal(f.document.activeElement, f.opener);
});

test('all-disabled variants enter Close and closing before a frame cannot steal restored focus', () => {
    const f = fixture(); f.option.disabled = true;
    f.context.openProductVariantPicker('family-1'); f.flush();
    assert.equal(f.document.activeElement, f.variantClose);
    assert.match(f.nodes['product-variant-guidance'].textContent, /All variants are currently unavailable/);
    f.context.closeProductVariantPicker();
    f.context.openProductVariantPicker('family-1');
    f.context.closeProductVariantPicker(); f.flush();
    assert.equal(f.document.activeElement, f.opener);
});
