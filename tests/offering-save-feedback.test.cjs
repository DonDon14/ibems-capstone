const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/store-offerings.js', 'utf8');
const action = source.slice(source.indexOf('    async function action('), source.indexOf('    function render()'));

function fixture({rejectSave = false, rejectRefresh = false, active = true} = {}) {
    const messages = [], sounds = [], requests = [];
    const control = {disabled: false, isConnected: true};
    const context = vm.createContext({
        state: {bookings: []}, busy: false, storeId: 6,
        isActive: () => active,
        root: {querySelectorAll: () => [control]},
        window: {IbemsFeedbackSound: {play: tone => sounds.push(tone)}},
        request: async () => { requests.push('save'); if (rejectSave) throw new Error('Payment rejected'); return {transaction_id: 42}; },
        load: async () => { if (rejectRefresh) throw new Error('Refresh failed'); },
        result: (message, error = false) => messages.push({message, error}),
    });
    vm.runInContext(action, context);
    return {context, messages, sounds, control, requests};
}

test('confirmed rental fee emits success and releases controls', async () => {
    const f = fixture();
    await f.context.action('pay', {});
    assert.deepEqual(f.sounds, ['success']);
    assert.match(f.messages.at(-1).message, /Fee recorded\. Receipt #42/);
    assert.equal(f.control.disabled, false);
});

test('refresh failure after confirmed fee retains saved feedback and success tone', async () => {
    const f = fixture({rejectRefresh: true});
    await f.context.action('pay', {});
    assert.deepEqual(f.sounds, ['success']);
    assert.equal(f.messages.at(-1).error, false);
    assert.match(f.messages.at(-1).message, /Fee recorded.*updated view could not be loaded/);
    assert.equal(f.control.disabled, true);
    await f.context.action('pay', {});
    assert.equal(f.requests.length, 1);
});

test('rejected rental fee emits only error and releases controls', async () => {
    const f = fixture({rejectSave: true});
    await f.context.action('pay', {});
    assert.deepEqual(f.sounds, ['error']);
    assert.deepEqual(f.messages.at(-1), {message: 'Payment rejected', error: true});
    assert.equal(f.control.disabled, false);
});

test('departed offering page cannot start a financial action', async () => {
    const f = fixture({active:false});
    await f.context.action('refund', {booking_id:42});
    assert.deepEqual(f.requests, []);
    assert.deepEqual(f.messages, []);
    assert.deepEqual(f.sounds, []);
    assert.equal(f.control.disabled, false);
});

for (const [name, expected] of [['issue',/Equipment issue recorded/],['return',/Check remaining quantities before refunding/],['refund',/Cash deposit refund recorded/],['cancel',/Unpaid reservation cancelled/]]) {
    test(`${name} feedback identifies the confirmed operation even when refresh fails`, async () => {
        const f=fixture({rejectRefresh:true});
        await f.context.action(name,{});
        assert.match(f.messages.at(-1).message,expected);
        assert.match(f.messages.at(-1).message,/updated view could not be loaded/);
        assert.equal(f.messages.at(-1).error,false);
        assert.deepEqual(f.sounds,['issue','refund'].includes(name)?['success']:[]);
    });
}

for (const active of [true, false]) test(`rental confirmation ${active ? 'posts from current page' : 'ignores departed page'}`, async () => {
    const start = source.indexOf("    root.addEventListener('click', async event => {");
    const end = source.indexOf("    el('offering-store').addEventListener",start);
    let handler, resolveConfirmation;
    let pageActive = true;
    const actions=[];
    const button={textContent:'Refund cash deposit',dataset:{action:'refund',id:'42'}};
    const context=vm.createContext({
        root:{addEventListener:(_,fn)=>{handler=fn;}}, busy:false,
        isActive:()=>pageActive,
        window:{IbemsDialog:{confirm:()=>new Promise(resolve=>{resolveConfirmation=resolve;})}},
        action:(...args)=>actions.push(args),
    });
    vm.runInContext(source.slice(start,end),context);
    const pending=handler({target:{closest:selector=>selector==='[data-action]'?button:null}});
    pageActive = active;
    resolveConfirmation(true);
    await pending;
    assert.equal(actions.length,active?1:0);
    if(active) {assert.equal(actions[0][0],'refund');assert.equal(actions[0][1].booking_id,42);}
});
