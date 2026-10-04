const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const loader = source.slice(source.indexOf('async function loadUvSalarySchedules()'), source.indexOf('function uvSelectedSchedule'));

for (const failure of ['network', 'http', 'invalid data']) {
    test(`Salary schedules recover after ${failure} failure and share successful loads`, async () => {
        let requests = 0;
        const context = vm.createContext({
            uvSalarySchedulesPromise: null, uvSalarySchedules: [], uvDefaultCreditPercentage: 25,
            fetch: async () => {
                requests++;
                if (requests === 1) {
                    if (failure === 'network') throw new Error('Offline');
                    return { ok: failure !== 'http', json: async () => ({ status: 'success', data: failure === 'invalid data' ? [] : [{ id: 1 }] }) };
                }
                return { ok: true, json: async () => ({ status: 'success', data: [{ id: 2 }], default_credit_percentage: 30 }) };
            }
        });
        vm.runInContext(loader, context);
        await assert.rejects(vm.runInContext('loadUvSalarySchedules()', context));
        assert.equal(context.uvSalarySchedulesPromise, null);
        const pending = vm.runInContext('loadUvSalarySchedules()', context);
        const concurrent = vm.runInContext('loadUvSalarySchedules()', context);
        await Promise.all([pending, concurrent]);
        await vm.runInContext('loadUvSalarySchedules()', context);
        assert.equal(requests, 2);
        assert.equal(context.uvSalarySchedules[0].id, 2);
        assert.equal(context.uvDefaultCreditPercentage, 30);
    });
}
