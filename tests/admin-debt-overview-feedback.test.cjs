const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-accounting-debts.js', 'utf8');
const start = source.indexOf('async function loadAdminDebtOverview()');
const loader = source.slice(start, source.indexOf('\ndocument.getElementById("ad-top-body")', start));
for (const scenario of ['network', 'http', 'application', 'aborted', 'empty']) {
    test(`Admin debt overview handles ${scenario}`, async () => {
        const ids = ['ad-account-count','ad-debt-accounts','ad-total-debt','ad-today-deducted','ad-top-body'];
        const elements = Object.fromEntries(ids.map(id=>[id,{textContent:'old total',innerHTML:'old rows'}]));
        const signal = {aborted:false};
        let resolve, reject;
        const response = new Promise((res,rej)=>{resolve=res;reject=rej;});
        const context = vm.createContext({window:{IbemsPortalNavigation:{currentSignal:signal}},
            document:{getElementById:id=>elements[id]},fetch:()=>response,
            adDebtDataState:(type,message)=>`${type}:${message}`,aMoney:value=>`PHP ${value}`});
        vm.runInContext(loader,context);
        const pending = vm.runInContext('loadAdminDebtOverview()',context);
        assert.equal(elements['ad-account-count'].textContent,'Loading…');
        if(scenario==='network') reject(new Error('offline'));
        else {
            signal.aborted=scenario==='aborted';
            resolve({ok:scenario!=='http',json:async()=>({status:scenario==='application'?'error':'success',summary:{account_count:9},top_debts:[]})});
        }
        await pending;
        if(scenario==='aborted') {
            assert.equal(elements['ad-account-count'].textContent,'Loading…');
            assert.equal(elements['ad-top-body'].innerHTML,'old rows');
        } else if(scenario==='empty') {
            assert.equal(elements['ad-account-count'].textContent,'9');
            assert.equal(elements['ad-top-body'].innerHTML,'empty:No outstanding debt accounts.');
        } else {
            for(const id of ids.slice(0,4)) assert.equal(elements[id].textContent,'Unavailable');
            assert.match(elements['ad-top-body'].innerHTML,/error:.*Reload this page/);
        }
    });
}
