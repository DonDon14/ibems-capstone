const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'), vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/deduction-workflow-feedback.js', 'utf8');
function fixture() {
    const events = {}, statuses = [], forms = [], cleanups = [];
    const document = { activeElement: null, querySelectorAll: () => forms, createElement: () => {
        const status = { attrs: {}, setAttribute(k, v) { this.attrs[k] = String(v); }, focus() { document.activeElement = this; } }; statuses.push(status); return status;
    } };
    function form(kind) {
        const button = { textContent: 'Original action', disabled: false, before(status) { f.status = status; } };
        const amount = { name: 'confirmed_amount', value: '100', max: '100', disabled: false, handlers: {}, addEventListener(k, fn) { this.handlers[k] = fn; } };
        const reason = { name: 'reason_code', value: '', disabled: false, required: false };
        const start = { value: '2026-10-01' }, end = { value: '2026-10-15' }, expected = { value: '' };
        const f = { button, amount, reason, start, end, expected, selected: true, attrs: {}, dataset: { deductionWorkflowForm: kind }, handlers: {},
            setAttribute(k, v) { this.attrs[k] = String(v); }, addEventListener(k, fn) { this.handlers[k] = fn; },
            querySelector(selector) { return selector === 'button[type="submit"]' ? button : selector.includes('date_start') ? start : selector.includes('date_end') ? end : selector.includes('expected_processing_date') ? expected : selector.includes('confirmed_amount') ? amount : selector.includes('reason_code') ? reason : this.selected ? {} : null; },
            querySelectorAll(selector) { return selector.includes('button') ? [button] : this.status?.className.includes('is-progress') ? [this.status] : []; },
        }; forms.push(f); return f;
    }
    const prepare = form('prepare'), result = form('result'), finalize = form('finalize'), period = form('period');
    const context = { document, window: { addEventListener: (k, fn) => { events[k] = fn; }, removeEventListener: k => { delete events[k]; }, IbemsPortalNavigation: { onCleanup: fn => cleanups.push(fn) } } };
    vm.runInNewContext(source, context);
    const submit = (f, defaultPrevented = false) => { const event = { defaultPrevented, prevented: false, preventDefault() { this.prevented = true; } }; f.handlers.submit(event); return event; };
    return { document, forms, prepare, result, finalize, period, events, statuses, cleanups, submit };
}

test('empty selection remains local with a focused correction alert', () => {
    const f = fixture(); f.prepare.selected = false;
    assert.equal(f.submit(f.prepare).prevented, true); assert.equal(f.document.activeElement, f.prepare.status);
    assert.equal(f.prepare.status.attrs.role, 'alert'); assert.match(f.prepare.status.textContent, /Select at least one employee/);
    assert.ok(f.forms.every(form => !form.button.disabled));
});

test('native pending action blocks all repeated actions without removing named inputs from the POST', () => {
    const f = fixture(); f.finalize.button.disabled = true;
    const initial = f.submit(f.result); assert.equal(initial.prevented, false, 'the first native POST must proceed');
    assert.ok(f.forms.every(form => form.button.disabled)); assert.equal(f.result.attrs['aria-busy'], 'true');
    assert.equal(f.result.amount.disabled, false); assert.equal(f.result.reason.disabled, false);
    assert.equal(f.result.amount.value, '100'); assert.equal(f.result.button.textContent, 'Processing…');
    assert.equal(f.document.activeElement, f.result.status);
    assert.equal(f.submit(f.prepare).prevented, true); assert.equal(f.submit(f.result).prevented, true);
    f.events.pageshow(); assert.equal(f.result.button.disabled, false); assert.equal(f.finalize.button.disabled, true);
    assert.equal(f.result.button.textContent, 'Original action'); assert.equal(f.result.status.hidden, true); assert.equal(f.result.attrs['aria-busy'], 'false');
});

test('partial and zero confirmed results require a reason; full result releases the requirement', () => {
    const f = fixture(); assert.equal(f.result.reason.required, false);
    for (const amount of ['50', '0', '99.99']) { f.result.amount.value = amount; f.result.amount.handlers.input(); assert.equal(f.result.reason.required, true); }
    f.result.amount.value = '100'; f.result.amount.handlers.change(); assert.equal(f.result.reason.required, false);
});

test('cancelled submit is untouched and partial navigation releases window restoration listener', () => {
    const f = fixture(); f.submit(f.prepare, true); assert.ok(f.forms.every(form => !form.button.disabled));
    f.cleanups[0](); assert.equal(f.events.pageshow, undefined);
});

test('no rendered financial forms leaves the enhancement inert', () => {
    vm.runInNewContext(source, { document: { querySelectorAll: () => [] } });
});

test('reversed period and premature pay dates are corrected locally while valid dates retain native POST', () => {
    const f = fixture(); f.period.end.value = '2026-09-30';
    assert.equal(f.submit(f.period).prevented, true); assert.match(f.period.status.textContent, /End date/);
    assert.ok(f.forms.every(form => !form.button.disabled));
    f.period.end.value = '2026-10-15'; f.period.expected.value = '2026-09-30';
    assert.equal(f.submit(f.period).prevented, true); assert.match(f.period.status.textContent, /Expected pay date/);
    f.period.expected.value = '2026-10-15'; assert.equal(f.submit(f.period).prevented, false);
});

test('a zero request still requires an actual zero-result reason',()=>{const f=fixture();f.result.amount.max='0';f.result.amount.value='0';f.result.amount.handlers.change();assert.equal(f.result.reason.required,true);});
