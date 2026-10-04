const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function setup() {
    const controls = ['retail', 'food_service', 'rental'].map(value => ({value, checked: value === 'retail', disabled: false}));
    const elements = new Map();
    const element = id => {
        if (!elements.has(id)) elements.set(id, {textContent: '', disabled: false, focusCount:0, focus(){this.focusCount++;}, attributes: {}, setAttribute(name, value) { this.attributes[name] = value; }, classList: {toggle() {}}});
        return elements.get(id);
    };
    const requests = [];
    let respond;
    const context = vm.createContext({URL, console, document: {
        getElementById: element,
        querySelector: () => null,
        querySelectorAll: selector => selector.includes(':checked') ? controls.filter(input => input.checked) : controls,
    }, window: {location: {href: 'http://localhost/store/settings?store_id=1', reload() { this.reloaded = true; }, assign(url) { this.destination = url; }}},
    fetch: async (url, options) => {
        requests.push({url, body: JSON.parse(options.body)});
        return await new Promise(resolve => { respond = resolve; });
    }});
    vm.runInContext(fs.readFileSync('public/assets/js/store-settings.js', 'utf8'), context);
    vm.runInContext('settingsStoreId = 1; settingsStores = [{id:1,store_name:"One"},{id:2,store_name:"Two"}]; settingsCapabilities = ["retail"]; capabilitiesLoaded = true;', context);
    return {context, controls, requests, element, respond: result => respond({ok: result.status === 'success', json: async () => result})};
}

test('card save is store scoped, serialized and cannot switch stores mid-save', async () => {
    const s = setup();
    s.controls[1].checked = true;
    const save = vm.runInContext('saveCapabilities()', s.context);
    assert.ok(s.controls.every(input => input.disabled));
    await vm.runInContext('saveCapabilities(); switchSettingsStore(2)', s.context);
    assert.equal(s.requests.length, 1);
    assert.equal(s.requests[0].body.store_id, 1);
    assert.deepEqual(s.requests[0].body.capabilities, ['retail', 'food_service']);
    assert.equal(s.context.window.location.destination, undefined);
    s.respond({status: 'success', capabilities: ['retail', 'food_service']});
    await save;
    assert.equal(s.context.window.location.reloaded, true);
    assert.ok(s.controls.every(input => !input.disabled));
    await vm.runInContext('switchSettingsStore(2)', s.context);
    assert.match(s.context.window.location.destination, /store_id=2/);
    assert.equal(vm.runInContext('settingsStoreId', s.context), 1);
});

test('failed save restores confirmed selections and reports the error', async () => {
    const s = setup();
    s.controls[0].checked = false;
    const save = vm.runInContext('saveCapabilities()', s.context);
    s.respond({status: 'error', message: 'Select at least one activity.'});
    await save;
    assert.equal(s.controls[0].checked, true);
    assert.equal(s.element('capability-result').textContent, 'Select at least one activity.');
    assert.equal(s.element('capability-result').attributes.role, 'alert');
    assert.equal(s.element('capability-result').attributes['aria-live'], 'assertive');
    assert.equal(s.element('capability-result').attributes['aria-atomic'], 'true');
});

test('cards cannot save before store capabilities have loaded', async () => {
    const s = setup();
    await vm.runInContext('capabilitiesLoaded = false; saveCapabilities()', s.context);
    assert.equal(s.requests.length, 0);
});

test('all settings feedback helpers switch between error and normal announcements', () => {
    const s = setup();
    for (const [helper, id] of [['settingsSetResult', 'settings-result'], ['settingsSetMethodResult', 'settings-method-result'], ['setCategoryEditResult', 'category-edit-result'], ['setCapabilityResult', 'capability-result'], ['setAccountResult', 'settings-account-result']]) {
        vm.runInContext(`${helper}('Rejected', true)`, s.context);
        assert.equal(s.element(id).attributes.role, 'alert');
        assert.equal(s.element(id).attributes['aria-live'], 'assertive');
        vm.runInContext(`${helper}('Saved', false)`, s.context);
        assert.equal(s.element(id).attributes.role, 'status');
        assert.equal(s.element(id).attributes['aria-live'], 'polite');
        assert.equal(s.element(id).attributes['aria-atomic'], 'true');
    }
    assert.equal(s.element('settings-account-result').focusCount, 1);
    assert.equal(s.element('settings-account-result').tabIndex, -1);
});
