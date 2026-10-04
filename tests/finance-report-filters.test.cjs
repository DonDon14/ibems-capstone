const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/finance-report-filters.js', 'utf8');

function fixture(mode = 'month') {
    const signal = new AbortController();
    const listeners = new Map(), windowListeners = new Map();
    let cleanup, focused;
    const control = value => ({value, disabled: false, required: false, error: '', listeners: new Map(),
        get validity() { return {customError: !!this.error}; },
        setCustomValidity(message) { this.error = message; },
        addEventListener(name, fn) { this.listeners.set(name, fn); },
        fire(name = 'input') { this.listeners.get(name)?.(); }});
    const period = control(mode), anchor = control('2026-10-04'), from = control('2026-10-01'), to = control('2026-10-31');
    const feedback = {hidden: true, textContent: ''}, help = {textContent: ''}, serverError = {hidden: false};
    const anchorLabel = {hidden: false}, customLabels = [{hidden: false}, {hidden: false}];
    const controls = new Map([['#finance-period',period],['#finance-anchor-date',anchor],['#finance-from-date',from],['#finance-to-date',to],['#finance-filter-feedback',feedback],['#finance-filter-help',help],['[data-finance-anchor]',anchorLabel]]);
    const invalidControls = () => [anchor,from,to].filter(input => !input.disabled && ((input.required && !input.value) || input.error));
    const form = {querySelector: selector => controls.get(selector), querySelectorAll: () => customLabels,
        addEventListener: (name,fn) => listeners.set(name,fn),
        checkValidity() { const invalid = invalidControls(); invalid.forEach(target => listeners.get('invalid')({target})); return !invalid.length; },
        reportValidity() { const invalid = invalidControls(); focused = invalid[0]; return !invalid.length; }};
    vm.runInNewContext(source, {Map, document: {querySelector: selector => selector === '.finance-report-filters' ? form : serverError}, window: {
        IbemsPortalNavigation: {currentSignal: signal.signal, onCleanup: fn => {cleanup = fn;}},
        addEventListener: (name,fn) => windowListeners.set(name,fn), removeEventListener: name => windowListeners.delete(name)}});
    const submit = (defaultPrevented = false) => { let prevented = defaultPrevented; listeners.get('submit')({defaultPrevented,preventDefault() {prevented = true;}}); return prevented; };
    return {period,anchor,from,to,feedback,help,serverError,anchorLabel,customLabels,signal,submit,windowListeners,cleanup: () => cleanup(),focus: () => focused,
        packet: () => Object.fromEntries([['period',period],['anchor_date',anchor],['from_date',from],['to_date',to]].filter(([,input]) => !input.disabled).map(([name,input]) => [name,input.value]))};
}

test('ordinary periods submit only their required anchor and preserve custom drafts', () => {
    for (const mode of ['day','week','month']) {
        const f = fixture(mode);
        assert.equal(f.anchor.required,true); assert.equal(f.anchor.disabled,false);
        assert.equal(f.from.disabled,true); assert.equal(f.to.disabled,true);
        assert.deepEqual(f.customLabels.map(label => label.hidden),[true,true]);
        assert.deepEqual(f.packet(),{period:mode,anchor_date:'2026-10-04'});
        assert.equal(f.from.value,'2026-10-01'); assert.equal(f.to.value,'2026-10-31'); assert.equal(f.submit(),false);
    }
});
test('custom periods submit only both required range dates and retain anchor draft', () => {
    const f = fixture('custom');
    assert.equal(f.anchor.disabled,true); assert.equal(f.anchor.required,false); assert.equal(f.anchorLabel.hidden,true);
    assert.equal(f.from.required,true); assert.equal(f.to.required,true);
    assert.deepEqual(f.packet(),{period:'custom',from_date:'2026-10-01',to_date:'2026-10-31'});
    assert.equal(f.anchor.value,'2026-10-04'); assert.equal(f.submit(),false);
});
test('reversed custom dates block submission, focus end date and expose recovery guidance', () => {
    const f = fixture('custom'); f.from.value='2026-11-01'; f.from.fire();
    assert.equal(f.submit(),true); assert.equal(f.focus(),f.to);
    assert.equal(f.feedback.hidden,false); assert.match(f.feedback.textContent,/Custom from must be on or before Custom to/);
    f.to.value='2026-11-02'; f.to.fire();
    assert.equal(f.to.error,''); assert.equal(f.feedback.hidden,true); assert.equal(f.submit(),false);
});
test('missing active date blocks native packet while inactive blank dates do not', () => {
    const f = fixture(); f.from.value=''; f.to.value=''; f.to.fire(); assert.equal(f.submit(),false);
    f.anchor.value=''; f.anchor.fire(); assert.equal(f.submit(),true); assert.equal(f.focus(),f.anchor);
    assert.match(f.feedback.textContent,/Date within period/);
    f.period.value='custom'; f.period.fire(); assert.equal(f.submit(),true); assert.equal(f.focus(),f.from);
});
test('switching period releases old custom validity without discarding range drafts', () => {
    const f = fixture('custom'); f.from.value='2026-11-01'; f.from.fire(); f.submit();
    f.period.value='day'; f.period.fire('change'); assert.equal(f.to.error,''); assert.equal(f.submit(),false); assert.equal(f.feedback.hidden,true);
    f.period.value='custom'; f.period.fire(); assert.equal(f.from.value,'2026-11-01'); assert.equal(f.submit(),true);
});
test('browser history restore re-syncs restored period and cleanup removes its listener', () => {
    const f = fixture(); f.period.value='custom'; f.windowListeners.get('pageshow')();
    assert.equal(f.anchor.disabled,true); assert.equal(f.from.disabled,false); f.cleanup(); assert.equal(f.windowListeners.has('pageshow'),false);
});
test('aborted page ignores late field/validation events and blocks a stale submit', () => {
    const f = fixture(); f.signal.abort(); f.period.value='custom'; f.period.fire();
    assert.equal(f.anchor.disabled,false); assert.equal(f.from.disabled,true); assert.equal(f.submit(),true); assert.equal(f.feedback.hidden,true);
});
test('an earlier cancelling submit listener retains ownership', () => {
    const f = fixture('custom'); f.from.value=''; f.from.fire(); assert.equal(f.submit(true),true); assert.equal(f.focus(),undefined); assert.equal(f.feedback.hidden,true);
});
test('server rejection remains on initial load and is replaced by new draft validation', () => {
    const f = fixture('custom'); assert.equal(f.serverError.hidden,false);
    f.from.value='2026-11-01'; f.from.fire(); assert.equal(f.serverError.hidden,true);
    f.serverError.hidden=false; f.submit(); assert.equal(f.serverError.hidden,true); assert.equal(f.feedback.hidden,false);
    const aborted = fixture(); aborted.signal.abort(); aborted.period.fire(); assert.equal(aborted.serverError.hidden,false);
});
