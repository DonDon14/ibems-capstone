const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-stores.part2.js', 'utf8');
const save = source.slice(source.indexOf('async function saveStore()'), source.indexOf('document.getElementById("store-search-btn").addEventListener'));

for (const scenario of ['success', 'refresh false', 'refresh throws', 'http failure']) {
    test(`Store save preserves the confirmed outcome: ${scenario}`, async () => {
        const button = { disabled: false, isConnected:true, textContent: 'Save Changes', dataset: { idleLabel: 'Save Changes' } };
        const modal = {isConnected:true,style:{display:'grid'},contains:()=>false};
        const fields = {
            'store-save-btn': button, 'store-name': { value: 'Fictional Store' },
            'store-officer-id': { value: '1' }, 'store-logo-url': { value: '' },
            'store-logo-file': { files: [] }, 'store-active': { value: '1' },
            'store-deactivation-reason': { value: '' }, 'store-modal':modal
        };
        const messages = [];
        let writes = 0, closed = 0, refreshes = 0;
        const context = vm.createContext({
            canManageStores: true, editingStoreId: 1, editingInitialIsActive: true,
            storeSaving:false,storeSaveSequence:0,window:{},
            selectedSupervisorIds: [2], logoInputMode: 'upload', editingCurrentLogoUrl: '',
            document: { getElementById: id => fields[id] }, FormData: class { append() {} },
            setStoresResult: (message, type) => messages.push({ message, type }),
            freezeStoreEditor:()=>{button.disabled=true;return()=>{button.disabled=false;};},focusStoreFeedback(){},keepStoreListFocusVisible(){},
            closeStoreModal: () => { closed++; context.editingStoreId = null; },
            fetch: async () => { writes++; return { ok: scenario !== 'http failure', json: async () => ({ status: 'success' }) }; },
            loadStores: async () => { refreshes++; if (scenario === 'refresh throws') throw new Error('Offline'); return scenario !== 'refresh false'; }
        });
        vm.runInContext(save, context);
        const pending = vm.runInContext('saveStore()', context);
        await vm.runInContext('saveStore()', context);
        await pending;
        assert.equal(writes, 1, 'Duplicate pending submissions are ignored');
        assert.equal(button.disabled, false);
        assert.equal(button.textContent, 'Save Changes');
        if (scenario === 'http failure') {
            assert.equal(closed, 0); assert.equal(refreshes, 0);
            assert.equal(messages.at(-1).type, 'warning', 'Contradictory HTTP/status confirmation remains unconfirmed');
        } else {
            assert.equal(closed, 1); assert.equal(refreshes, 1);
            assert.match(messages.at(-1).message, /^Store updated\./);
            assert.equal(messages.at(-1).type, scenario === 'success' ? 'ok' : 'warning');
            assert.equal(messages.some(item => item.type === 'error'), false);
        }
    });
}
