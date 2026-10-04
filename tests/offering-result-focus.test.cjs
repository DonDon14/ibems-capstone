const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-offerings.js','utf8');
function fixture(active=true){
 const target={attributes:{},textContent:'',focusCount:0,setAttribute(k,v){this.attributes[k]=v;},focus(){this.focusCount++;},classList:{toggle(){}}};
 const context=vm.createContext({isActive:()=>active,el:()=>target});
 vm.runInContext(source.slice(source.indexOf('    const result ='),source.indexOf('    async function request')),context);
 return {target,run:(message,error)=>vm.runInContext(`result(${JSON.stringify(message)},${Boolean(error)})`,context)};
}
test('offering error becomes focused alert while later statuses preserve focus',()=>{
 const f=fixture();f.run('Rejected',true);assert.equal(f.target.focusCount,1);assert.equal(f.target.tabIndex,-1);assert.equal(f.target.attributes.role,'alert');
 f.run('Saving',false);f.run('Saved',false);assert.equal(f.target.focusCount,1);assert.equal(f.target.attributes.role,'status');
});
test('departed offering page cannot focus or replace result',()=>{
 const f=fixture(false);f.run('Rejected',true);assert.equal(f.target.focusCount,0);assert.equal(f.target.textContent,'');
});
