const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const start = source.indexOf('document.getElementById("department-form").addEventListener("submit"');
const handler = source.slice(start, source.indexOf('    load();', start));
for (const outcome of ['success', 'refresh failure', 'save failure']) test(`Department save feedback: ${outcome}`, async () => {
    let listener, writes = 0, closed = 0;
    const messages = [];
    const button = { disabled: false, innerHTML:'Save Department' };
    const form = {addEventListener: (_type, cb) => { listener = cb; },querySelectorAll:()=>[button],setAttribute(){},removeAttribute(){}};
    const fields = {
        'department-save': button, 'department-status-reason': { required: false, value: '' },
        'department-head': { value: '2' }, 'department-id': { value: '1' },
        'department-code': { value: 'DEMO' }, 'department-name': { value: 'Fictional Department' },
        'department-active-input': { value: '1' }
    };
    fields['department-modal-result']={focus(){}};
    const context = vm.createContext({
        document: { getElementById: id => id === 'department-form' ? form : fields[id], activeElement: button, body: {} },
        departmentModal:{classList:{contains:()=>false,add(){},remove(){}}},departmentSaving:false,setHeadSuggestionsOpen(){},
        pageSignal: { aborted: false }, syncDepartmentReason() {}, jsonOptions: value => value,
        api: async () => { writes++; if (outcome === 'save failure') throw Object.assign(new Error('Save rejected'),{hasServerMessage:true}); },
        load: async () => outcome !== 'refresh failure', closeModal: () => { closed++; },
        setResult: (id, message, type) => messages.push({ id, message, type })
    });
    vm.runInContext(handler, context);
    const pending = listener({ preventDefault() {} });
    await listener({ preventDefault() {} });
    await pending;
    assert.equal(writes, 1);
    assert.equal(button.disabled, false);
    assert.equal(closed, outcome === 'save failure' ? 0 : 1);
    if (outcome === 'save failure') assert.equal(messages.at(-1).type, 'error');
    else {
        assert.match(messages.at(-1).message, /^Department saved\./);
        assert.equal(messages.at(-1).id, 'department-admin-result');
        assert.equal(messages.at(-1).type, outcome === 'success' ? 'success' : 'warning');
    }
});
