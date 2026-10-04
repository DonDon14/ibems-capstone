const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
function extract(name) {
    const start = source.indexOf(`function ${name}(`);
    const end = source.indexOf('\nfunction ', start + 1);
    return source.slice(start, end < 0 ? undefined : end);
}

test('pointer dismissal cannot bypass confirmation submission lock', () => {
    const modal = { style: { display: 'grid' } };
    const pending = { payload: { request_key: 'same-intent' } };
    const context = vm.createContext({
        isSubmitting: true, pendingTransaction: pending,
        document: { getElementById: () => modal },
        releasePosCheckoutDialogFocus() {},
    });
    vm.runInContext(extract('closeConfirmTransactionModal'), context);
    context.closeConfirmTransactionModal({ type: 'click' });
    assert.equal(modal.style.display, 'grid');
    assert.equal(context.pendingTransaction, pending);
    context.closeConfirmTransactionModal(true);
    assert.equal(modal.style.display, 'none');
    assert.equal(context.pendingTransaction, null);
});

function dialogFixture() {
    const frames = [], nodes = {};
    const document = { activeElement: null, getElementById: id => nodes[id] };
    function control(id, extra = {}) {
        return nodes[id] = {
            id, isConnected: true, disabled: false, tabIndex: 0, value: '200', handlers: {},
            focus() { document.activeElement = this; },
            getClientRects() { return this.hidden ? [] : [{}]; },
            addEventListener(name, handler) { this.handlers[name] = handler; },
            classList: { toggle() {} }, ...extra,
        };
    }
    const opener = control('checkout-opener');
    const productSearch = control('product-search');
    document.activeElement = opener;
    const confirmClose = control('confirm-close'), cancel = control('confirm-cancel');
    const proceed = control('confirm-proceed'), cash = control('confirm-cash-received');
    const receiptClose = control('receipt-close'), receiptPrint = control('receipt-print');
    const receiptView = control('receipt-view');
    const confirmContent = control('confirm-transaction-content', { scrollTop: 200 });
    const receiptContent = control('receipt-content', { scrollTop: 200 });
    control('confirm-change-due'); control('confirm-cash-help');
    function modal(id, close, content, controls) {
        const card = { scrollTop: 200 };
        return nodes[id] = {
            id, style: { display: 'none' }, isConnected: true, card, controls, handlers: {},
            querySelector: selector => selector === '.receipt-card' ? card : selector.includes('#confirm-transaction-content') ? content : close,
            querySelectorAll() { return this.controls; },
            addEventListener(name, handler) { this.handlers[name] = handler; },
        };
    }
    const confirm = modal('confirm-transaction-modal', confirmClose, confirmContent, [confirmClose, confirmContent, cash, cancel, proceed]);
    const receipt = modal('receipt-modal', receiptClose, receiptContent, [receiptClose, receiptContent, receiptView, receiptPrint]);
    modal('barcode-scanner-modal', control('scanner-close'), {}, []);
    const context = vm.createContext({
        document, isSubmitting: false, pendingTransaction: null, lastReceipt: null,
        window: { requestAnimationFrame: callback => frames.push(callback), IbemsReceipt: { renderReceipt() {} } },
        buildConfirmTransactionHtml: () => '', formatMoney: value => `PHP ${value}`,
        closeScannerModal() {},
    });
    const helpers = source.slice(source.indexOf('const posCheckoutDialogStates'), source.indexOf('function openReceiptModal('));
    vm.runInContext(helpers + ['openReceiptModal', 'closeReceiptModal', 'updateConfirmCashTender', 'openConfirmTransactionModal', 'closeConfirmTransactionModal'].map(extract).join('\n'), context);
    context.bindPosCheckoutDialogKeys();
    function flush() { while (frames.length) frames.shift()(); }
    function key(modal, key, shiftKey = false) {
        let prevented = false;
        modal.handlers.keydown({ currentTarget: modal, key, shiftKey, preventDefault() { prevented = true; }, stopPropagation() {} });
        return prevented;
    }
    function draft(withCash = false) {
        return { payments: withCash ? [{ payment_method: 'cash', amount: 175 }] : [], payload: { payments: [] } };
    }
    return { context, document, opener, productSearch, confirmClose, confirmContent, receiptContent, proceed, cash, cancel, receiptClose, receiptPrint, receiptView, confirm, receipt, flush, key, draft };
}

test('confirmation entry, visible boundary wrapping and Escape restore the opener', () => {
    const f = dialogFixture();
    f.cash.hidden = true;
    f.context.openConfirmTransactionModal(f.draft());
    f.flush();
    assert.equal(f.document.activeElement, f.confirmClose);
    assert.equal(f.confirm.card.scrollTop, 0);
    assert.equal(f.confirmContent.scrollTop, 0);
    assert.equal(f.key(f.confirm, 'Tab', true), true);
    assert.equal(f.document.activeElement, f.proceed);
    assert.equal(f.key(f.confirm, 'Tab'), true);
    assert.equal(f.document.activeElement, f.confirmClose);
    f.proceed.disabled = true;
    f.cancel.focus();
    f.key(f.confirm, 'Tab');
    assert.equal(f.document.activeElement, f.confirmClose);
    f.context.isSubmitting = true;
    f.key(f.confirm, 'Escape');
    assert.equal(f.confirm.style.display, 'grid');
    f.context.isSubmitting = false;
    f.key(f.confirm, 'Escape');
    assert.equal(f.document.activeElement, f.opener);
    assert.equal(f.confirm.style.display, 'none');
});

test('cash focus frame cannot steal focus after closing or after receipt handoff', () => {
    const f = dialogFixture();
    f.context.openConfirmTransactionModal(f.draft(true));
    f.context.closeConfirmTransactionModal();
    f.flush();
    assert.equal(f.document.activeElement, f.opener);
    f.context.openConfirmTransactionModal(f.draft(true));
    const opener = f.context.closeConfirmTransactionModal(true);
    assert.equal(opener, f.opener);
    f.context.openReceiptModal({ lookupUrl: '' }, opener);
    f.flush();
    assert.equal(f.document.activeElement, f.receiptClose);
    assert.equal(f.receiptContent.scrollTop, 0);
    assert.equal(f.receiptView.disabled, true);
    f.key(f.receipt, 'Tab', true);
    assert.equal(f.document.activeElement, f.receiptPrint);
    f.key(f.receipt, 'Tab');
    assert.equal(f.document.activeElement, f.receiptClose);
    f.key(f.receipt, 'Escape');
    assert.equal(f.document.activeElement, f.opener);
});

test('cash entry and replacement frames retain the original opener', () => {
    const f = dialogFixture();
    f.context.openConfirmTransactionModal(f.draft(true));
    f.flush();
    assert.equal(f.document.activeElement, f.cash);
    assert.equal(f.proceed.disabled, false);
    f.context.openConfirmTransactionModal(f.draft());
    f.context.closeConfirmTransactionModal();
    f.flush();
    assert.equal(f.document.activeElement, f.opener);
});

test('receipt closed while checkout refresh is pending falls back to product search', () => {
    const f = dialogFixture();
    f.context.openReceiptModal({ lookupUrl: '' }, f.opener);
    f.flush();
    f.opener.disabled = true;
    f.context.closeReceiptModal();
    assert.equal(f.document.activeElement, f.productSearch);
});
