const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/store-settings.js','utf8');
function fixture(name){
 const pending=[],renders=[],signal=new AbortController(),controls=[{value:'retail',checked:false,disabled:true}],button={disabled:true};
 const context=vm.createContext({URL,settingsPageSignal:signal.signal,settingsStoreId:5,settingsStores:[],settingsCapabilities:[],capabilitiesLoaded:false,settingsContextLoadId:0,settingsCapabilityLoadId:0,
 window:{location:{href:'http://example.test/store/settings?store_id=5'}},
 fetch:()=>new Promise(resolve=>pending.push(resolve)),renderStoreContext:()=>renders.push('render'),
 document:{getElementById:()=>button,querySelectorAll:()=>controls},
 });
 const start=source.indexOf(`async function ${name}()`),end=source.indexOf(name==='loadStoreContext'?'function renderStoreContext()':'async function saveCapabilities()',start);
 vm.runInContext(source.slice(start,end),context);
 return {context,pending,renders,signal,controls,button,run:()=>context[name](),respond(index,ok=true){pending[index]({ok,json:async()=>({status:'success',stores:[{id:5,store_name:`Store response ${index}`}],default_store_id:5,capabilities:[index?'rental':'retail']})});}};
}
for(const name of ['loadStoreContext','loadCapabilities']){
 test(`${name} latest response wins`,async()=>{
  const f=fixture(name),old=f.run(),latest=f.run();f.respond(1);await latest;f.respond(0);await old;
  if(name==='loadStoreContext'){assert.equal(f.renders.length,1);assert.equal(f.context.settingsStores[0].store_name,'Store response 1');}
  else{assert.deepEqual(Array.from(f.context.settingsCapabilities),['rental']);assert.equal(f.controls[0].checked,false);assert.equal(f.button.disabled,false);}
 });
 test(`${name} departed response does not activate controls or render`,async()=>{
  const f=fixture(name),pending=f.run();f.signal.abort();f.respond(0);await pending;
  assert.equal(f.renders.length,0);assert.equal(f.context.capabilitiesLoaded,false);assert.equal(f.button.disabled,true);
 });
 test(`${name} failed HTTP is rejected`,async()=>{
  const f=fixture(name),pending=f.run();f.respond(0,false);await assert.rejects(pending);assert.equal(f.renders.length,0);assert.equal(f.button.disabled,true);
 });
}
test('capability response for previous store cannot enable current store controls',async()=>{
 const f=fixture('loadCapabilities'),pending=f.run();f.context.settingsStoreId=6;f.respond(0);await pending;
 assert.equal(f.context.capabilitiesLoaded,false);assert.equal(f.button.disabled,true);assert.equal(f.controls[0].disabled,true);
});
