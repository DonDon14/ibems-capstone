const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-stores.js', 'utf8');
const helper = source.slice(source.indexOf('function handlePeoplePickerKeydown('), source.indexOf('function findOfficerById('));
for (const kind of ['officer', 'supervisor']) test(`${kind} picker consumes Escape only while suggestions are open`, () => {
    let expanded = true, prevented = false;
    const input = { id: `store-${kind}-search`, getAttribute: () => String(expanded) };
    const context = vm.createContext({ document: { getElementById: id => ({ id }) },
        renderOfficerSuggestions() {}, renderSupervisorSuggestions() {},
        setSuggestionBoxOpen: () => { expanded = false; } });
    vm.runInContext(helper, context);
    context.event = { key: 'Escape', currentTarget: input, preventDefault() { prevented = true; } };
    vm.runInContext(`handlePeoplePickerKeydown('${kind}', event)`, context);
    assert.equal(prevented, true); assert.equal(expanded, false);
    prevented = false;
    vm.runInContext(`handlePeoplePickerKeydown('${kind}', event)`, context);
    assert.equal(prevented, false);
});
