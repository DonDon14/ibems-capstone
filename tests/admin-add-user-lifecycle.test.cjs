const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const method = source.slice(source.indexOf('async function openAddUser()'), source.indexOf('document.getElementById("uv-add-btn").addEventListener'));
for (const scenario of ['superseded', 'departed', 'stale error', 'current']) {
    test(`Add User ignores ${scenario} salary-load state when appropriate`, async () => {
        let resolve, reject;
        const opened = [], messages = [], fields = {};
        const signal = { aborted: false };
        const context = vm.createContext({ uvDetailRequestId: 0,
            window: { IbemsPortalNavigation: { currentSignal: signal }, IbemsAvatar: { defaultUrl: '/avatar.svg' } },
            document: { getElementById: id => fields[id] ||= {} },
            loadUvSalarySchedules: () => new Promise((res, rej) => { resolve = res; reject = rej; }),
            populateUvSalaryProfile() {}, toggleUvFinancialFields() {},
            openModal: id => opened.push(id), setUvResult: message => messages.push(message) });
        vm.runInContext(method, context);
        const pending = vm.runInContext('openAddUser()', context);
        if (scenario === 'departed') signal.aborted = true;
        if (scenario === 'superseded' || scenario === 'stale error') context.uvDetailRequestId++;
        if (scenario === 'stale error') reject(new Error('Offline')); else resolve([]);
        await pending;
        assert.deepEqual(messages, []);
        assert.deepEqual(opened, scenario === 'current' ? ['uv-add-modal'] : []);
        assert.equal(Object.keys(fields).length, scenario === 'current' ? 3 : 0);
    });
}
