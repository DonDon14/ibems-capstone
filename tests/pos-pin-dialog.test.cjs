const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
const part4 = fs.readFileSync('public/assets/js/store-pos.part4.js', 'utf8');
function extract(text, name) {
    const start = text.indexOf(`function ${name}(`);
    if (start < 0) return '';
    const end = text.indexOf('\nfunction ', start + 1);
    return text.slice(start, end < 0 ? undefined : end);
}
function fixture() {
    const nodes = {}, frames = [], delays = [], intervals = new Map();
    let now = 1700000000000, nextInterval = 0;
    const document = { activeElement: null, getElementById: id => nodes[id] };
    function node(id, extra = {}) {
        return nodes[id] = {
            id, isConnected: true, disabled: false, tabIndex: 0, value: '', hidden: false,
            style: {}, dataset: {}, textContent: '', attributes: {}, handlers: {},
            focus() { if (!this.disabled) document.activeElement = this; },
            getClientRects() { return this.hidden ? [] : [{}]; },
            setAttribute(name, value) { this.attributes[name] = String(value); },
            removeAttribute(name) { delete this.attributes[name]; },
            addEventListener(name, fn) { this.handlers[name] = fn; }, ...extra,
        };
    }
    const opener = node('checkout-opener'), input = node('debt-pin-input');
    const close = node('debt-pin-close'), cancel = node('debt-pin-cancel'), save = node('debt-pin-save');
    const result = node('debt-pin-modal-result'), countdown = node('debt-pin-lockout-countdown', { hidden: true });
    node('debt-pin-modal-title'); node('debt-pin-modal-summary'); node('product-search');
    document.activeElement = opener;
    const card = { scrollTop: 20 };
    const modal = node('debt-pin-modal', {
        style: { display: 'none' },
        querySelector: selector => selector === '.receipt-card' ? card : selector.includes('#confirm-transaction-content') ? null : close,
        querySelectorAll: () => [close, input, cancel, save],
    });
    const proceed = node('confirm-proceed');
    const confirmation = node('confirm-transaction-modal', {
        style: { display: 'grid' }, querySelector: () => null, querySelectorAll: () => [proceed],
    });
    ['receipt-modal', 'barcode-scanner-modal'].forEach(id => node(id));
    const context = vm.createContext({
        document, setTimeout: fn => delays.push(fn), Date: { now: () => now },
        window: { requestAnimationFrame: fn => frames.push(fn), setInterval: fn => { intervals.set(++nextInterval, fn); return nextInterval; }, clearInterval: id => intervals.delete(id) },
        selectedDebtCustomerId: 501, selectedDebtCustomer: { name: 'Fictional Employee', has_debt_pin: true },
        selectedDebtPin: '', debtPinLockouts: new Map(), debtPinLockoutTimer: null,
        isSubmitting: false, pendingTransaction: {},
        getDebtAccountType: () => 'employee', getSelectedDepartmentDebtAccount: () => null, getSelectedDepartmentApprover: () => null,
        employeeUsesPurchaseCard: () => false, refreshSelectedPurchaseCard() {}, updateDebtPinUi() {}, updateCheckoutState() {}, setResult() {},
    });
    const helpers = source.slice(source.indexOf('const posCheckoutDialogStates'), source.indexOf('function openReceiptModal('));
    vm.runInContext(helpers + ['setDebtPinModalResult', 'openDebtPinModal', 'clearDebtPinLockoutTimer', 'renderDebtPinAuthorizationState', 'showDebtPinAuthorizationFailure', 'closeDebtPinModal', 'closeConfirmTransactionModal'].map(n => extract(source, n)).join('\n') + extract(part4, 'saveDebtPinFromModal'), context);
    context.bindPosCheckoutDialogKeys();
    function flush() { while (frames.length || delays.length) (frames.shift() || delays.shift())(); }
    function lock(seconds = 65) { context.debtPinLockouts.set('employee-501', { epoch: (now + seconds * 1000) / 1000 }); }
    function tick(seconds) { now += seconds * 1000; context.renderDebtPinAuthorizationState(); }
    function key(key, shiftKey = false) {
        modal.handlers.keydown?.({ currentTarget: modal, key, shiftKey, preventDefault() {}, stopPropagation() {} });
    }
    return { context, nodes, document, modal, confirmation, proceed, opener, input, close, cancel, save, result, countdown, flush, lock, tick, key, intervals };
}

test('PIN close cancels queued entry and restores the opener', () => {
    const f = fixture(); f.context.openDebtPinModal(); f.context.closeDebtPinModal(true); f.flush();
    assert.equal(f.document.activeElement, f.opener);
    assert.equal(f.modal.style.display, 'none');
});

test('locked PIN enters Close, excludes disabled controls and restores opener with Escape', () => {
    const f = fixture(); f.lock(); f.context.openDebtPinModal(); f.flush();
    assert.equal(f.input.disabled, true);
    assert.equal(f.save.disabled, true);
    assert.equal(f.document.activeElement, f.close);
    f.key('Tab', true); assert.equal(f.document.activeElement, f.cancel);
    f.key('Tab'); assert.equal(f.document.activeElement, f.close);
    f.key('Escape'); assert.equal(f.document.activeElement, f.opener);
});

test('authorization failure hands confirmation original opener to PIN dialog', () => {
    const f = fixture();
    f.context.focusPosCheckoutDialog(f.confirmation, f.proceed); f.flush();
    f.context.showDebtPinAuthorizationFailure({ attempts_remaining: 2 }); f.flush();
    assert.equal(f.confirmation.style.display, 'none');
    assert.equal(f.document.activeElement, f.input);
    assert.match(f.result.textContent, /2 attempts remaining/);
    f.context.closeDebtPinModal(true);
    assert.equal(f.document.activeElement, f.opener);
});

test('countdown updates separately from the error announcement and expiry clears locked feedback', () => {
    const f = fixture(); f.lock(); f.context.openDebtPinModal(); f.flush();
    const announcement = f.result.textContent;
    assert.equal(f.result.attributes.role, 'alert');
    assert.equal(f.countdown.hidden, false);
    assert.equal(f.countdown.attributes['aria-live'], 'off');
    assert.match(f.countdown.textContent, /1:05/);
    f.tick(1);
    assert.equal(f.result.textContent, announcement);
    assert.match(f.countdown.textContent, /1:04/);
    f.tick(65);
    assert.equal(f.input.disabled, false); assert.equal(f.save.disabled, false);
    assert.equal(f.countdown.hidden, true);
    assert.doesNotMatch(f.result.textContent, /locked after/);
    assert.match(f.result.textContent, /Lockout ended/);
    assert.equal(f.result.attributes.role, 'status');
    assert.equal(f.context.debtPinLockouts.size, 0);
    assert.equal(f.document.activeElement, f.close);
});

test('epoch-only lockout response enters the locked state without relying on a date string', () => {
    const f = fixture();
    f.context.showDebtPinAuthorizationFailure({ locked_until_epoch: 1700000065 }); f.flush();
    assert.equal(f.input.disabled, true);
    assert.equal(f.document.activeElement, f.close);
    assert.match(f.countdown.textContent, /1:05/);
});

test('department PIN lockout is scoped to its selected department and approver', () => {
    const f = fixture();
    let approverId = 25;
    f.context.getDebtAccountType = () => 'department';
    f.context.getSelectedDepartmentDebtAccount = () => ({ id: 20, name: 'Fictional Department' });
    f.context.getSelectedDepartmentApprover = () => ({ id: approverId, name: 'Fictional Approver', pin_set: true });
    f.context.debtPinLockouts.set('department-20-25', { epoch: 1700000065 });
    f.context.openDebtPinModal(); f.flush();
    assert.equal(f.input.disabled, true);
    assert.match(f.result.textContent, /department approval PIN/);
    assert.match(f.nodes['debt-pin-modal-summary'].textContent, /Fictional Department/);
    f.context.closeDebtPinModal(true);
    approverId = 26; f.context.openDebtPinModal(); f.flush();
    assert.equal(f.input.disabled, false);
    assert.equal(f.result.textContent, '');
    assert.equal(f.countdown.hidden, true);
    assert.equal(f.context.debtPinLockouts.has('department-20-25'), true);
});

test('PIN required feedback marks the input invalid and clearing feedback resets it', () => {
    const f = fixture(); f.context.openDebtPinModal(); f.flush();
    assert.equal(f.context.saveDebtPinFromModal(), false);
    assert.equal(f.input.attributes['aria-invalid'], 'true');
    assert.equal(f.result.attributes.role, 'alert');
    assert.match(f.result.textContent, /4 to 6 digit/);
    f.context.setDebtPinModalResult();
    assert.equal(f.input.attributes['aria-invalid'], undefined);
    assert.equal(f.result.textContent, '');
});

test('locked server failure disables input before focus frame and closing retains lockout state', () => {
    const f = fixture();
    f.context.focusPosCheckoutDialog(f.confirmation, f.proceed); f.flush();
    f.context.showDebtPinAuthorizationFailure({ locked_until: '2023-11-14 22:14:25', locked_until_epoch: 1700000065 });
    f.flush(); assert.equal(f.document.activeElement, f.close);
    f.context.closeDebtPinModal(true);
    assert.equal(f.context.debtPinLockouts.size, 1);
    assert.equal(f.intervals.size, 1);
    f.context.openDebtPinModal(); f.flush();
    assert.equal(f.document.activeElement, f.close);
    f.tick(70); assert.equal(f.intervals.size, 0);
});
