const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const renderer = source.slice(source.indexOf('function applyUserFiltersAndRender()'), source.indexOf('async function loadUserView()'));
for (const page of [1, 2, 3]) test(`Employee pager restores enabled focus at page ${page}`, () => {
    const document = { activeElement: { getAttribute: () => 'Next employee page' }, getElementById: id => id === 'uv-pager' ? pager : id === 'uv-page-size' ? { value: '10' } : id === 'uv-sort' ? { value: 'name:asc' } : {} };
    const button = (label, disabled) => ({ disabled, getAttribute: () => label, focus() { document.activeElement = this; } });
    const previous = button('Previous employee page', page === 1), next = button('Next employee page', page === 3);
    const pager = { contains: () => true, querySelectorAll: () => [previous, next] };
    const rows = Array.from({ length: 25 }, (_, id) => ({ id, name: `Employee ${id}` }));
    const context = vm.createContext({ document, uvListState: 'ready', uvRows: rows, uvPage: page,
        getFilteredRows: () => [...rows], renderTopSummary() {}, renderUserTable() {} });
    vm.runInContext(renderer + '\napplyUserFiltersAndRender();', context);
    assert.equal(document.activeElement, page === 3 ? previous : next);
    assert.match(pager.innerHTML, new RegExp(`Page ${page} of 3`));
});
