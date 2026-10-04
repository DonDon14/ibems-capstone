const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
const methods = source.slice(source.indexOf('async function saveEditedUser()'), source.indexOf('async function importUsersCsv()'));
for (const method of ['saveEditedUser', 'createUser']) for (const outcome of ['network failure', 'http failure', 'refresh failure', 'success']) {
    test(`${method} handles ${outcome} and duplicate pending calls`, async () => {
        const button = { disabled: false, setAttribute() {}, removeAttribute() {} };
        const messages = [];
        let requests = 0, closed = 0;
        const context = vm.createContext({
            uvEditingUserId: 506,
            document: { getElementById: id => id.endsWith('-save') ? button : { value: id.endsWith('-type') ? 'student' : 'Fictional', files: [] } },
            getRoleChecks: () => ['USER'], uvUserFormData: value => value,
            setUvResult: (message, type) => messages.push({ message, type }), closeModal: () => { closed++; },
            loadUserView: async () => outcome !== 'refresh failure',
            fetch: async () => { requests++; if (outcome === 'network failure') throw new Error('Offline'); return { ok: outcome !== 'http failure', json: async () => ({ status: 'success', initial_credit_limit: 0 }) }; }
        });
        vm.runInContext(methods, context);
        const pending = vm.runInContext(`${method}()`, context);
        await vm.runInContext(`${method}()`, context);
        await pending;
        assert.equal(requests, 1);
        assert.equal(button.disabled, false);
        if (outcome.includes('failure') && outcome !== 'refresh failure') {
            assert.equal(closed, 0); assert.equal(messages.at(-1).type, 'error');
        } else {
            assert.equal(closed, 1);
            assert.match(messages.at(-1).message, method === 'createUser' ? /^User created/ : /^User updated/);
            assert.equal(messages.at(-1).type, outcome === 'success' ? 'ok' : 'warning');
        }
    });
}

test('existing employee identity save sends no credit or salary mutation, while onboarding keeps its financial payload',async()=>{
 const sent=[];let financialCalls=0;const button={disabled:false,setAttribute(){},removeAttribute(){}};
 const c=vm.createContext({uvEditingUserId:501,document:{getElementById:id=>id.endsWith('-save')?button:{value:id.endsWith('-type')?'staff':'Synthetic',files:[]}},getRoleChecks:()=>['USER'],uvUserFormData:value=>value,uvFinancialPayload:()=>{financialCalls++;return {credit_percentage:25};},setUvResult(){},closeModal(){},loadUserView:async()=>true,fetch:async(url,options)=>{sent.push(options.body);return {ok:true,json:async()=>({status:'success',initial_credit_limit:0})};}});
 vm.runInContext(methods,c);await vm.runInContext('saveEditedUser()',c);assert.equal(financialCalls,0);assert.equal(sent[0].credit_percentage,undefined);assert.equal(sent[0].salary_grade,undefined);
 await vm.runInContext('createUser()',c);assert.equal(financialCalls,1);assert.equal(sent[1].credit_percentage,25);
});
