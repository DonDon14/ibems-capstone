const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

for (const [path, block] of [
    ['public/assets/js/store-inventory.js', 'nearest'],
]) {
    test(`${path} scrolling follows changing motion preference`, () => {
        const source = fs.readFileSync(path, 'utf8');
        const statement = source.split('\n').find(line => line.includes('scrollIntoView('));
        assert.ok(statement, 'production scroll action exists');
        const calls = [];
        const target = {scrollIntoView: options => calls.push(options)};
        let reduced = false;
        const window = {matchMedia: query => {
            assert.equal(query, '(prefers-reduced-motion: reduce)');
            return {matches: reduced};
        }};
        const context = vm.createContext({window, row: target, document: {getElementById: () => target}});
        vm.runInContext(statement, context);
        reduced = true;
        vm.runInContext(statement, context);
        delete window.matchMedia;
        vm.runInContext(statement, context);
        assert.deepEqual(calls.map(call => call.behavior), ['smooth', 'instant', 'smooth']);
        assert.deepEqual(calls.map(call => call.block), [block, block, block]);
    });
}
