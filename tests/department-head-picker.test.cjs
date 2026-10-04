const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const start = source.indexOf('    function setActiveHeadSuggestion(');
const helpers = source.slice(start, source.indexOf('    function renderHeadSummary()', start));

test('Department picker wraps active options and returns selection focus to the assignment card', () => {
    const options = [0, 1].map(index => ({ id: `option-${index}`, attrs: {}, active: false,
        classList: { toggle(name, value) { options[index].active = value; } },
        setAttribute(name, value) { this.attrs[name] = value; }, scrollIntoView() {} }));
    const input = { value: 'Alyssa', attrs: {}, setAttribute(name, value) { this.attrs[name] = value; } };
    const head = { value: '' };
    let focus = false, rendered = false, closed = false;
    const context = vm.createContext({ activeHeadSuggestionIndex: -1, headEditorOpen: true,
        people: [{ id: 506 }],
        document: { getElementById: id => id === 'department-head-search' ? input : head,
            querySelectorAll: () => options, querySelector: () => ({ focus() { focus = true; } }) },
        setHeadSuggestionsOpen(value) { closed = !value; }, renderHeadSummary() { rendered = true; } });
    vm.runInContext(helpers, context);
    vm.runInContext('setActiveHeadSuggestion(0)', context);
    assert.equal(input.attrs['aria-activedescendant'], 'option-0');
    assert.deepEqual(options.map(option => option.attrs['aria-selected']), ['true', 'false']);
    vm.runInContext('setActiveHeadSuggestion(-1)', context);
    assert.equal(input.attrs['aria-activedescendant'], 'option-1');
    assert.deepEqual(options.map(option => option.attrs['aria-selected']), ['false', 'true']);
    vm.runInContext('chooseDepartmentHead(999)', context);
    assert.equal(focus, false);
    vm.runInContext('chooseDepartmentHead(506)', context);
    assert.equal(head.value, '506');
    assert.equal(input.value, '');
    assert.equal(context.headEditorOpen, false);
    assert.ok(closed && rendered && focus);
});
