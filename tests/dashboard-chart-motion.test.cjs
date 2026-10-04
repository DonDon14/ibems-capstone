const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
for(const [file,variable] of [['admin-dashboard.js','adSalesTrendChart'],['accounting-dashboard.js','acdTrendChart'],['user-dashboard.js','uTrendChart']])test(`${file} reacts to reduced motion without changing chart data`,()=>{
 const source=fs.readFileSync(`public/assets/js/${file}`,'utf8');
 let callback,cleanup,removed=false;const updates=[],data=[1,2,3];
 const chart={data,options:{},stop:()=>updates.push('stop'),update:mode=>updates.push(mode)};
 const preference={addEventListener:(event,fn)=>callback=fn,removeEventListener:(event,fn)=>{removed=fn===callback;}};
 const ctx=vm.createContext({[variable]:chart,window:{matchMedia:()=>preference},uPageSignal:{addEventListener:(event,fn)=>cleanup=fn}});
 if(file==='user-dashboard.js'){
 const start=source.indexOf('const uMotionPreference =');const end=source.indexOf('window.addEventListener("ibems:dashboard-period-change"',start);vm.runInContext(source.slice(start,end),ctx);
 }else{
 const start=source.indexOf('window.matchMedia(');const end=source.indexOf('});',start)+3;vm.runInContext(source.slice(start,end),ctx);
 }
 callback({matches:true});assert.equal(chart.options.animation,false);assert.deepEqual(updates,['stop','none']);assert.equal(chart.data,data);
 callback({matches:false});assert.notEqual(chart.options.animation,false);
 if(cleanup){cleanup();assert.equal(removed,true);}
});
