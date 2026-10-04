const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/user-history.js','utf8');
for(const scenario of ['matching','edge','moved','departed']) test(`history pager focus handles ${scenario}`,()=>{
    let focused='';
    const make=label=>({getAttribute:()=>label,focus(){focused=label;}});
    const previous=make('Previous transactions page'),next=make('Next transactions page');
    const body={};
    const context=vm.createContext({uhPageSignal:{aborted:scenario==='departed'},document:{body,activeElement:scenario==='moved'?{}:body,
        getElementById:()=>({querySelectorAll:()=>scenario==='edge'?[previous]:[previous,next]})}});
    const start=source.indexOf('function uhRestorePagerFocus');
    vm.runInContext(source.slice(start,source.indexOf('document.getElementById("uh-transactions-pager").addEventListener',start)),context);
    vm.runInContext('uhRestorePagerFocus("pager","Next transactions page")',context);
    assert.equal(focused,scenario==='matching'?'Next transactions page':scenario==='edge'?'Previous transactions page':'');
});
