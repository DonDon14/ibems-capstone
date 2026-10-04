const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/password-visibility.js', 'utf8');

function setup() {
    const events = {}, windowEvents = {}, timers = [];
    let observer, active = null;
    class Element {
        children = []; dataset = {}; attrs = {}; listeners = {}; style = {}; classes = new Set(); disabled = false;
        classList = {contains: name => this.classes.has(name)};
        get parentNode() {return this.parent;}
        insertBefore(child, before) {this.children.splice(this.children.indexOf(before), 0, child); child.parent = this;}
        appendChild(child) {if (child.parent) child.parent.children = child.parent.children.filter(el => el !== child); this.children.push(child); child.parent = this;}
        setAttribute(name, value) {this.attrs[name] = value;}
        getAttribute(name) {return this.attrs[name] ?? null;}
        matches(selector) {return selector.includes('input[type="password"]') ? this instanceof Input && this.type === 'password' : this.hidden || this.classes.has('is-hidden') || this.classes.has('hidden') || this.attrs['aria-hidden'] === 'true';}
        querySelectorAll(selector) {return this.children.flatMap(el => [...(selector.includes('visibility-enhanced') ? el.dataset.visibilityEnhanced === 'true' ? [el] : [] : el instanceof Input && el.type === 'password' ? [el] : []), ...el.querySelectorAll(selector)]);}
        addEventListener(name, handler) {this.listeners[name] = handler;}
        focus(options) {active = this; this.focusOptions = options;}
    }
    class Input extends Element {type = 'password'; value = 'synthetic unchanged value'; id = ''; inputMode = '';}
    const root = new Element(), form = new Element(); root.appendChild(form);
    const password = new Input(), pin = new Input(); password.id = 'password'; pin.id = 'pin'; pin.inputMode = 'numeric';
    form.appendChild(password); form.appendChild(pin);
    const document = {documentElement: root, querySelectorAll: selector => root.querySelectorAll(selector), createElement: () => new Element(), addEventListener: (name, fn) => {events[name] = fn;}};
    const window = {addEventListener: (name, fn) => {windowEvents[name] = fn;}};
    vm.runInNewContext(source, {document, window, Element, HTMLInputElement: Input, setTimeout: fn => timers.push(fn),
        MutationObserver: class {constructor(fn) {observer = fn;} observe() {}}});
    const toggle = input => input.parent.children.find(el => el !== input);
    return {root, form, password, pin, window, Input, toggle, active: () => active,
        flush: () => {while (timers.length) timers.shift()();},
        reset: cancelled => {const event = {target: form, defaultPrevented: false}; events.reset?.(event); event.defaultPrevented = cancelled;},
        mutate: (target, attributeName = 'class') => observer([{type: 'attributes', target, attributeName}]),
        add: input => {if (!input.parent) form.appendChild(input); observer([{type: 'childList', addedNodes: [input]}]);},
        event: name => windowEvents[name]?.()
    };
}

test('password and PIN toggle semantics preserve values and return focus to the field', () => {
    const qa = setup();
    for (const input of [qa.password, qa.pin]) {
        const toggle = qa.toggle(input); toggle.listeners.click();
        assert.equal(input.type, 'text'); assert.equal(toggle.attrs['aria-pressed'], 'true');
        assert.equal(toggle.attrs['aria-label'], input === qa.pin ? 'Hide PIN' : 'Hide password');
        assert.equal(toggle.attrs['aria-controls'], input.id);
        assert.equal(input.value, 'synthetic unchanged value'); assert.equal(qa.active(), input);
        assert.equal(input.focusOptions.preventScroll, true);
        toggle.listeners.click(); assert.equal(input.type, 'password'); assert.equal(toggle.attrs['aria-pressed'], 'false');
    }
});

test('successful form reset masks every revealed field without stealing reset-button focus', () => {
    const qa = setup(); qa.toggle(qa.password).listeners.click(); qa.toggle(qa.pin).listeners.click();
    const focus = qa.active(); qa.reset(false); qa.flush();
    for (const input of [qa.password, qa.pin]) {
        assert.equal(input.type, 'password'); assert.equal(qa.toggle(input).attrs['aria-pressed'], 'false');
        assert.match(qa.toggle(input).attrs['aria-label'], /^Show /);
    }
    assert.equal(qa.active(), focus);
});

test('a cancelled native reset retains the chosen visibility', () => {
    const qa = setup(); qa.toggle(qa.password).listeners.click(); qa.reset(true); qa.flush();
    assert.equal(qa.password.type, 'text'); assert.equal(qa.toggle(qa.password).attrs['aria-pressed'], 'true');
});

test('a newer reveal choice survives a deferred reset callback', () => {
    const qa = setup(); qa.reset(false); qa.toggle(qa.password).listeners.click(); qa.flush();
    assert.equal(qa.password.type, 'text'); assert.equal(qa.toggle(qa.password).attrs['aria-pressed'], 'true');
});

test('closing a containing panel restores masking and keeps its draft untouched', () => {
    for (const mode of ['class', 'hidden', 'style', 'aria-hidden']) {
        const qa = setup(); qa.toggle(qa.password).listeners.click();
        if (mode === 'class') qa.form.classes.add('is-hidden');
        if (mode === 'hidden') qa.form.hidden = true;
        if (mode === 'style') qa.form.style.display = 'none';
        if (mode === 'aria-hidden') qa.form.attrs['aria-hidden'] = 'true';
        qa.mutate(qa.form, mode);
        assert.equal(qa.password.type, 'password'); assert.equal(qa.password.value, 'synthetic unchanged value');
    }
});

test('ordinary visible panel changes do not cancel a chosen reveal', () => {
    const qa = setup(); qa.toggle(qa.password).listeners.click(); qa.form.classes.add('highlight'); qa.mutate(qa.form);
    assert.equal(qa.password.type, 'text');
});

test('page departure and restored pages mask fields without clearing their values', () => {
    for (const event of ['ibems:portal-page-unload', 'pagehide', 'pageshow']) {
        const qa = setup(); qa.toggle(qa.pin).listeners.click(); qa.event(event);
        assert.equal(qa.pin.type, 'password'); assert.equal(qa.pin.value, 'synthetic unchanged value');
    }
});

test('dynamic fields are enhanced once and disabled fields cannot reveal', () => {
    const qa = setup(), input = new qa.Input(); input.id = 'dynamic'; qa.add(input); const toggle = qa.toggle(input);
    qa.add(input); assert.equal(qa.toggle(input), toggle);
    input.disabled = true; toggle.disabled = true; qa.mutate(input, 'disabled'); toggle.listeners.click();
    assert.equal(input.type, 'password'); assert.equal(toggle.attrs['aria-disabled'], 'true');
    input.disabled = false; qa.mutate(input, 'disabled');
    assert.equal(toggle.disabled, true); assert.equal(toggle.attrs['aria-disabled'], 'false');
});

test('explicit masking supports a programmatically cleared PIN form', () => {
    const qa = setup(); qa.toggle(qa.pin).listeners.click(); qa.pin.value = '';
    qa.window.IbemsPasswordVisibility?.mask(qa.form);
    assert.equal(qa.pin.type, 'password'); assert.equal(qa.toggle(qa.pin).attrs['aria-label'], 'Show PIN');
});
