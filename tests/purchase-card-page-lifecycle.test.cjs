const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/user-purchase-card.js','utf8');
test('expiry returns focus from hidden Lock action without rewriting unchanged status', () => {
    const nodes={}; let writes=0, focused='';
    const node=id=>nodes[id] ||= {textContent:'',disabled:false,classList:{toggle(){}},focus(){focused=id;}};
    const label=node('purchase-card-state-label');
    Object.defineProperty(label,'textContent',{get:()=> 'Locked',set:()=>{writes++;}});
    const document={getElementById:node,activeElement:node('purchase-card-lock')};
    const context=vm.createContext({document, card:{is_locked:false},secondsLeft:()=>0});
    vm.runInContext(source.slice(source.indexOf('function render()'),source.indexOf('function startTimer()')),context);
    vm.runInContext('render()',context);
    assert.equal(node('purchase-card-lock').hidden,true);
    assert.equal(node('purchase-card-unlock').hidden,false);
    assert.equal(focused,'purchase-card-unlock');
    assert.equal(writes,0);
});
test('late purchase-card status does not render or restart a timer after leaving', async () => {
    let resolve, renders=0, timers=0, writes=0;
    const signal={aborted:false};
    const context=vm.createContext({signal, card:null, api:()=>new Promise(done=>resolve=done),
        document:{getElementById(){writes++; return {}; }}, render(){renders++;}, startTimer(){timers++;}, setResult(){writes++;}});
    vm.runInContext(source.slice(source.indexOf('async function load()'),source.indexOf('async function update(')),context);
    const pending=vm.runInContext('load()',context);
    signal.aborted=true;
    resolve({card:{is_locked:false},employee:{name:'Test'}});
    await pending;
    assert.equal(writes,0); assert.equal(renders,0); assert.equal(timers,0); assert.equal(context.card,null);
});
test('failed status load replaces assumed state with unavailable feedback', async () => {
    const nodes={}, feedback=[];
    const context=vm.createContext({signal:{aborted:false}, api:async()=>{throw Error('Offline');},
        document:{getElementById:id=>nodes[id] ||= {}},
        setResult:(message,error)=>feedback.push({message,error})});
    vm.runInContext(source.slice(source.indexOf('async function load()'),source.indexOf('async function update(')),context);
    await vm.runInContext('load()',context);
    assert.equal(nodes['purchase-card-state-label'].textContent,'Status unavailable');
    assert.equal(nodes['purchase-card-employee-id'].textContent,'Account ID unavailable');
    assert.match(nodes['purchase-card-control-copy'].textContent,/Refresh/);
    assert.deepEqual(feedback,[{message:'Offline',error:true}]);
});
