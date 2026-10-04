const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/store-settings.js','utf8');
function fixture(){
 const frames=[],nodes=new Map(),signal=new AbortController();
 const node=id=>{if(!nodes.has(id)){const classes=new Set(['is-hidden']);nodes.set(id,{value:'',focusCount:0,isConnected:true,focus(){this.focusCount++;},select(){},classList:{add:c=>classes.add(c),remove:c=>classes.delete(c),contains:c=>classes.has(c)}});}return nodes.get(id);};
 const context=vm.createContext({categoryDialogVersion:0,settingsPageSignal:signal.signal,categoryMutationPending:false,editingCategoryId:null,categoryEditTrigger:null,settingsCategories:[{id:1,name:'Fictional category'}],
 document:{getElementById:node,body:{classList:{add(){},remove(){}}},querySelector:()=>null},window:{requestAnimationFrame:fn=>frames.push(fn)},setCategoryEditResult(){},syncCategoryEditSaveState(){},
 });
 vm.runInContext(source.slice(source.indexOf('function openCategoryCreateModal'),source.indexOf('function renderCategories')),context);
 return {context,node,signal,frames,flush(){while(frames.length)frames.shift()();}};
}
for(const mode of ['Create','Edit'])test(`${mode} category pending focus cannot override close or page departure`,()=>{
 const f=fixture(),opener=f.node('opener');
 if(mode==='Create')f.context.openCategoryCreateModal(opener);else f.context.openCategoryEditModal(1,opener);
 f.context.closeCategoryEditModal();f.flush();assert.equal(opener.focusCount,1);assert.equal(f.node('category-edit-name').focusCount,0);
 if(mode==='Create')f.context.openCategoryCreateModal(opener);else f.context.openCategoryEditModal(1,opener);
 f.signal.abort();f.flush();assert.equal(f.node('category-edit-name').focusCount,0);
});
test('only latest category opening owns scheduled focus',()=>{
 const f=fixture();f.context.openCategoryCreateModal();f.context.openCategoryEditModal(1);f.flush();
 assert.equal(f.node('category-edit-name').focusCount,1);assert.equal(f.node('category-edit-name').value,'Fictional category');
});
