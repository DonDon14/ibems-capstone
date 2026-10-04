const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-settings.js','utf8');
for(const [name,next,key] of [['loadCategories','loadPaymentMethods','categories'],['loadPaymentMethods','setAccountResult','methods'],['loadPaymentAccounts',null,'accounts']]){
    function fixture(){
        const pending=[],renders=[],signal=new AbortController();
        const context=vm.createContext({settingsStoreId:5,settingsPageSignal:signal.signal,settingsCategoryLoadId:0,settingsMethodLoadId:0,settingsAccountLoadId:0,settingsCategories:[],settingsPaymentMethods:[],settingsPaymentAccounts:[],
            fetch:()=>new Promise(resolve=>pending.push(resolve)),
            renderCategories:()=>renders.push('render'),renderPaymentMethods:()=>renders.push('render'),
            renderPaymentAccounts:()=>{},setAccountResult(){},
            settingsDataState:()=>'',settingsEscape:value=>value,document:{getElementById:()=>({})},
        });
        const start=source.indexOf(`async function ${name}()`),end=next?source.indexOf(`function ${next}(`,start):source.length;
        vm.runInContext(source.slice(start,end).replace(/async\s*$/,''),context);
        return {context,pending,renders,signal,run:()=>context[name](),respond(i,ok=true){pending[i]({ok,json:async()=>({status:'success',[key]:[{id:i+1}]})});}};
    }
    test(`${name} ignores older response after latest load`,async()=>{const f=fixture();const old=f.run(),latest=f.run();f.respond(1);await latest;f.respond(0);await old;assert.equal(f.renders.length,1);assert.equal(f.context[{categories:'settingsCategories',methods:'settingsPaymentMethods',accounts:'settingsPaymentAccounts'}[key]][0].id,2);});
    test(`${name} ignores changed store and departed page`,async()=>{const f=fixture();const pending=f.run();f.context.settingsStoreId=6;f.respond(0);await pending;assert.equal(f.renders.length,0);const departed=f.run();f.signal.abort();f.respond(1);await departed;assert.equal(f.renders.length,0);});
    test(`${name} rejects failed HTTP with success-shaped payload`,async()=>{const f=fixture();const pending=f.run();f.respond(0,false);await assert.rejects(pending,/Unable to load/);assert.equal(f.renders.length,0);});
}
