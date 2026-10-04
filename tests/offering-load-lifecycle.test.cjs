const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/store-offerings.js', 'utf8');
const loader = source.slice(source.indexOf('    let loadRequestId'), source.indexOf("    root.addEventListener('submit'"));
for (const scenario of ['superseded', 'departed', 'active']) {
    test(`Offering data ignores ${scenario} responses when appropriate`, async () => {
        let active = true;
        const requests = [], rendered = [];
        const context = vm.createContext({ state: null, storeId: 5, isActive: () => active,
            request: () => new Promise(resolve => requests.push(resolve)), render: () => rendered.push(context.state) });
        vm.runInContext(loader, context);
        const first = context.load();
        if (scenario === 'superseded') {
            const second = context.load(); requests[1]({ id: 2 }); await second;
        }
        if (scenario === 'departed') active = false;
        requests[0]({ id: 1 }); await first;
        assert.equal(rendered.length, scenario === 'departed' ? 0 : 1);
        if (rendered.length) assert.equal(rendered[0].id, scenario === 'superseded' ? 2 : 1);
    });
}
