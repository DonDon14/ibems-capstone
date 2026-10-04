const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function functionSource(source, name) {
    const start = source.indexOf(`function ${name}(`);
    const end = source.indexOf('\nfunction ', start + 1);
    assert.ok(start >= 0, `Missing ${name}`);
    return source.slice(start, end < 0 ? undefined : end);
}

test('actual POS success, error and refresh warning helpers emit only transaction outcome sounds', () => {
    const source = fs.readFileSync('public/assets/js/store-pos.js', 'utf8');
    const part3 = fs.readFileSync('public/assets/js/store-pos.part3.js', 'utf8');
    const tones = [], toasts = [];
    const result = { dataset: {}, setAttribute() {} };
    const strip = { classList: { remove() {} }, innerHTML: '' };
    const nodes = { result, 'pos-success-strip': strip };
    const context = vm.createContext({
        document: {
            getElementById: id => nodes[id],
            createElement: () => ({ setAttribute() {}, remove() {} }),
        },
        ensureToastHost: () => ({ appendChild: node => toasts.push(node) }),
        window: { IbemsFeedbackSound: { play: type => tones.push(type) }, setTimeout() {} },
        formatMoney: value => `PHP ${Number(value).toFixed(2)}`,
        formatPaymentLabel: value => value,
        escapeHtml: value => String(value).replace(/</g, '&lt;').replace(/>/g, '&gt;'),
    });
    vm.runInContext(['showToast', 'setResult'].map(name => functionSource(source, name)).join('\n') + functionSource(part3, 'renderSuccessStrip'), context);
    context.setResult('Transaction successful.', 'ok');
    context.renderSuccessStrip({ totalAmount: 175, paymentMethod: 'cash', customerName: '<img src=x onerror=alert(1)>' });
    assert.deepEqual(tones, ['success']);
    assert.ok(strip.innerHTML.includes('PHP 175.00'));
    assert.ok(strip.innerHTML.includes('&lt;img'));
    assert.ok(!strip.innerHTML.includes('<img'));
    context.setResult('Saved; reload before the next sale.', 'warning');
    assert.deepEqual(tones, ['success']);
    context.setResult('Transaction rejected.', 'error');
    assert.deepEqual(tones, ['success', 'error']);
    assert.equal(result.dataset.tone, 'error');
    assert.deepEqual(toasts.map(node => node.className), ['toast-item is-success', 'toast-item is-warning', 'toast-item is-error']);
    context.renderSuccessStrip(null);
    delete nodes['pos-success-strip'];
    context.renderSuccessStrip({ totalAmount: 175 });
    assert.deepEqual(tones, ['success', 'error']);
});
