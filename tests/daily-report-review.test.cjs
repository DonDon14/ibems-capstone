const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function setup(value = '', options = {}) {
    const events = {}, windowEvents = {}, timers = [], frames = [], cleanups = [], controller = new AbortController();
    let document;
    class Element {
        listeners = {}; attrs = {}; dataset = {}; classes = new Set(); disabled = false; readOnly = false; textContent = ''; hidden = false; isConnected = true;
        classList = {contains: n => this.classes.has(n), toggle: (n, on) => on ? this.classes.add(n) : this.classes.delete(n)};
        addEventListener(k, fn) {this.listeners[k] = fn;}
        setAttribute(k, v) {this.attrs[k] = v;} getAttribute(k) {return this.attrs[k] ?? null;} removeAttribute(k) {delete this.attrs[k];}
        setCustomValidity(message) {this.message = message;} checkValidity() {return !this.message;}
        reportValidity() {} focus() {this.focused = true; document.activeElement = this;}
        matches() {return this.portalMain === true;}
    }
    function makeForm(kind, hasNote, value) {
        const form = new Element(); form.dataset.reportAction = kind;
        const note = hasNote ? Object.assign(new Element(), {name: 'note', value, message: ''}) : null;
        const buttons = (kind === 'review' ? ['approve', 'return'] : ['']).map(value => Object.assign(new Element(), {name: kind === 'review' ? 'decision' : '', value}));
        form.querySelector = () => note;
        form.querySelectorAll = selector => selector === 'textarea' ? note ? [note] : [] : buttons;
        form.appendChild = el => {form.status = el;};
        return {form, note, buttons};
    }
    const first = makeForm(options.kind || 'review', options.hasNote !== false, value), second = makeForm('review', true, 'Other draft');
    const flash = options.flash ? new Element() : null, body = new Element(), main = new Element(); main.portalMain = true;
    document = {body, activeElement: body, querySelectorAll: () => [first.form, second.form], createElement: () => new Element(),
        querySelector: selector => flash && selector.endsWith(options.flash === 'error' ? '.is-error' : '.is-success') ? flash : null,
        addEventListener: (k, fn) => {events[k] = fn;}, removeEventListener: k => {delete events[k];}};
    vm.runInNewContext(fs.readFileSync('public/assets/js/daily-report-review.js', 'utf8'), {document,
        window: {IbemsPortalNavigation: {currentSignal: controller.signal, onCleanup: fn => cleanups.push(fn)},
            requestAnimationFrame: fn => frames.push(fn), addEventListener: (k, fn) => {windowEvents[k] = fn;}, removeEventListener: k => {delete windowEvents[k];}},
        setTimeout: fn => timers.push(fn)});
    function submit(item, decision, prevented = false) {
        const event = {submitter: item.buttons.find(button => button.value === decision) || item.buttons[0], defaultPrevented: prevented,
            preventDefault() {this.defaultPrevented = true;}};
        item.form.listeners.submit(event); return event;
    }
    return {note: first.note, form: first.form, buttons: first.buttons, second, document, flash, main, events, windowEvents, cleanups,
        status: () => first.form.status, event: (decision, prevented) => submit(first, decision, prevented),
        run: decision => submit(first, decision).defaultPrevented, other: decision => submit(second, decision).defaultPrevented,
        clickSubmit: (decision = 'approve') => {const event = {defaultPrevented: false, preventDefault() {this.defaultPrevented = true;}}; (first.buttons.find(button => button.value === decision) || first.buttons[0]).listeners.click(event); return event;},
        flush: () => {while (timers.length) timers.shift()();}, frame: () => {while (frames.length) frames.shift()();}, abort: () => controller.abort()};
}
test('blank return reason blocks submission and focuses note', () => {
    const ui = setup('   '); assert.equal(ui.run('return'), true); assert.equal(ui.note.focused, true);
    assert.match(ui.note.message, /reason/);
});
test('approval permits an optional note', () => {
    const ui = setup(''); assert.equal(ui.run('approve'), false);
});
test('typing clears return validation and a reason permits submission', () => {
    const ui = setup(''); ui.run('return'); ui.note.value = 'Correct the source snapshot';
    ui.note.listeners.input(); assert.equal(ui.note.message, ''); assert.equal(ui.run('return'), false);
});
test('clicking a submit control clears stale validity before native validation', () => {
    const ui = setup(''); ui.run('return'); assert.notEqual(ui.note.message, '');
    ui.clickSubmit(); assert.equal(ui.note.message, ''); assert.equal(ui.run('approve'), false);
});

test('pending review retains native note and named decision while preventing cross-form submits', () => {
    const ui = setup('Fictional return reason'); const event = ui.event('return');
    assert.equal(event.defaultPrevented, false);
    assert.equal(ui.note.readOnly, true); assert.equal(ui.note.value, 'Fictional return reason');
    assert.equal(ui.buttons[1].disabled, false); assert.equal(ui.buttons[1].name, 'decision'); assert.equal(ui.buttons[1].value, 'return');
    assert.equal(ui.form.attrs['aria-busy'], 'true'); assert.equal(ui.status().attrs.role, 'status');
    assert.equal(ui.document.activeElement, ui.status());
    assert.equal(ui.run('approve'), true); assert.equal(ui.other('return'), true); assert.equal(ui.clickSubmit().defaultPrevented, true);
    assert.equal(ui.second.note.readOnly, true);
});

test('receipt without a note and supervisor handoff have pending feedback', () => {
    for (const kind of ['receive', 'submit']) {
        const ui = setup('', {kind, hasNote: false}); assert.equal(ui.run(''), false);
        assert.match(ui.status().textContent, kind === 'receive' ? /receipt/ : /snapshot/);
        assert.equal(ui.buttons[0].disabled, false); assert.equal(ui.status().attrs['aria-live'], 'polite');
    }
});

test('history restoration preserves original availability, notes and button labels', () => {
    const ui = setup('Kept draft'); ui.buttons[0].disabled = true; ui.buttons[1].attrs['aria-disabled'] = 'false'; ui.note.readOnly = true;
    ui.run('return'); ui.windowEvents.pageshow();
    assert.equal(ui.buttons[0].disabled, true); assert.equal(ui.buttons[0].getAttribute('aria-disabled'), null);
    assert.equal(ui.buttons[1].getAttribute('aria-disabled'), 'false'); assert.equal(ui.note.readOnly, true);
    assert.equal(ui.second.note.readOnly, false); assert.equal(ui.note.value, 'Kept draft');
    assert.equal(ui.status().hidden, true); assert.equal(ui.form.attrs['aria-busy'], 'false');
});

test('later native-submit cancellation releases feedback and readonly state', () => {
    const ui = setup(); const event = ui.event('approve'); event.preventDefault();
    ui.document.activeElement = ui.main; ui.flush();
    assert.equal(ui.note.readOnly, false); assert.equal(ui.buttons[0].getAttribute('aria-disabled'), null);
    assert.equal(ui.status().hidden, true); assert.equal(ui.document.activeElement, ui.main);
    assert.equal(ui.run('approve'), false);
});

test('cancelled pending feedback returns owned focus to the initiating submitter', () => {
    const ui = setup(); const event = ui.event('approve'); event.preventDefault(); ui.flush();
    assert.equal(ui.document.activeElement, ui.buttons[0]);
    assert.equal(ui.status().hidden, true);
});

test('an obsolete cancellation timer cannot release a newer submission', () => {
    const ui = setup(); const old = ui.event('approve'); old.preventDefault(); ui.windowEvents.pageshow(); ui.event('approve'); ui.flush();
    assert.equal(ui.form.attrs['aria-busy'], 'true'); assert.equal(ui.note.readOnly, true); assert.equal(ui.status().hidden, false);
});

test('cancelled or departed forms cannot enter a pending native submission', () => {
    const ui = setup(); ui.event('approve', true); assert.equal(ui.note.readOnly, false); ui.abort(); assert.equal(ui.run('approve'), true);
    assert.equal(ui.status().hidden, true);
});

test('server feedback focus respects page ownership and skips stale queued work', () => {
    const ui = setup('', {flash: 'error'}); ui.frame(); assert.equal(ui.document.activeElement, ui.flash);
    ui.document.activeElement = {matches: () => false}; ui.events['ibems:portal-page-loaded'](); ui.frame(); assert.notEqual(ui.document.activeElement, ui.flash);
    ui.document.activeElement = ui.main; ui.events['ibems:portal-page-loaded'](); ui.frame(); assert.equal(ui.document.activeElement, ui.flash);
    ui.document.activeElement = ui.document.body; ui.events['ibems:portal-page-loaded'](); ui.abort(); ui.frame(); assert.equal(ui.document.activeElement, ui.document.body);
});

test('page cleanup restores controls and removes shared recovery listeners', () => {
    const ui = setup('', {flash: 'success'}); ui.run('approve'); ui.cleanups[0]();
    assert.equal(ui.note.readOnly, false); assert.equal(ui.windowEvents.pageshow, undefined);
    assert.equal(ui.events['ibems:portal-page-loaded'], undefined);
});
