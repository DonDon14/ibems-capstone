const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const loaders = source.slice(source.indexOf('async function openEditUser('), source.indexOf('async function saveEditedUser('));
function setup() {
    const fields = {}, pending = [], opened = [], errors = [];
    const signal = { aborted: false };
    const context = vm.createContext({ uvDetailRequestId: 0, uvEditingUserId: null, uvViewingUserId: null, uvDefaultCreditPercentage: 25,
        window: { IbemsPortalNavigation: { currentSignal: signal }, IbemsAvatar: { imageUrl: String } },
        document: { getElementById: id => fields[id] ||= { classList: { add() {} } }, querySelectorAll: () => [] },
        fetch: () => new Promise((resolve, reject) => pending.push({ resolve, reject })),
        loadUvSalarySchedules: async () => {}, setRoleChecks() {}, populateUvSalaryProfile() {}, toggleUvFinancialFields() {},
        normalizeRoles: () => [], formatRoleLabel: String, formatTypeLabel: String, aMoney: String,
        openModal: id => opened.push(id), setUvResult: error => errors.push(error) });
    vm.runInContext(loaders, context);
    return { context, fields, pending, opened, errors, signal };
}
for (const staleFailure of [false, true]) test(`Employee detail ignores older ${staleFailure ? 'failure' : 'success'} after newer edit`, async () => {
    const f = setup();
    const older = vm.runInContext('openViewUser(1)', f.context);
    const newer = vm.runInContext('openEditUser(2)', f.context);
    f.pending[1].resolve({ ok: true, json: async () => ({ status: 'success', data: { id: 2, user_type: 'staff' } }) }); await newer;
    if (staleFailure) f.pending[0].reject(Error('old failure'));
    else f.pending[0].resolve({ ok: true, json: async () => ({ status: 'success', data: { id: 1 } }) });
    await older;
    assert.deepEqual(f.opened, ['uv-edit-modal']); assert.equal(f.context.uvEditingUserId, 2); assert.equal(f.errors.length, 0);
});
test('Employee details report network failure and ignore departed-page response', async () => {
    const f = setup(); const first = vm.runInContext('openViewUser(1)', f.context);
    f.pending[0].reject(Error('offline')); await first; assert.deepEqual(f.errors, ['offline']);
    const second = vm.runInContext('openViewUser(2)', f.context); f.signal.aborted = true;
    f.pending[1].resolve({ ok: true, json: async () => ({ status: 'success', data: { id: 2 } }) }); await second;
    assert.equal(f.opened.length, 0);
});
