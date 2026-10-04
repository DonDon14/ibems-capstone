const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/accounting-investigation-reads.js','utf8');
const row=(overrides={})=>({id:900002,user_id:900001,name:'Fictional employee',summary:'Fictional reported issue',issue_type:'incorrect_amount',status:'open',updated_at:'2026-10-04 18:00:00',...overrides});
const transaction=(overrides={})=>({id:900003,client_txn_id:'FICTIONAL-TXN',amount:55,created_at:'2026-10-04 18:00:00',...overrides});
const reply=(data,ok=true)=>({ok,json:async()=>data});
function fixture(){
    const nodes=new Map(),pending=[],requests=[];let document;
    class Node {
        constructor(id){this.id=id;this.disabled=false;this.hidden=false;this.value='';this.textContent='';this.className='';this.dataset={};this.attrs={};this.style={display:'grid'};this.isConnected=true;this.children=[];this.fields={};this.html='';this.tabIndex=0;}
        set innerHTML(value){this.html=value;this.children=[];if(this.id==='investigation-transaction')this.value='';}
        get innerHTML(){return this.html;}
        setAttribute(k,v){this.attrs[k]=v;} removeAttribute(k){delete this.attrs[k];}
        contains(other){return other===this||this.children.includes(other)||this.children.some(child=>child.contains(other));}
        querySelectorAll(){return this.children;} querySelector(selector){return this.fields[selector]||null;} closest(){return null;}
        focus(){if(!this.disabled)document.activeElement=this;} scrollIntoView(){this.scrolled=true;} dispatchEvent(){}
    }
    const node=id=>{if(!nodes.has(id))nodes.set(id,new Node(id));return nodes.get(id);};
    document={body:node('body'),activeElement:node('outside'),getElementById:node};
    const signal={aborted:false};const context=vm.createContext({document,Event:class Event{},getComputedStyle:e=>e.style,
        window:{IbemsPortalNavigation:{currentSignal:signal}},aEscape:v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),aMoney:String,aDateTime:String,
        fetch:(url,options)=>{requests.push({url,options});return new Promise((resolve,reject)=>pending.push({resolve,reject}));},
        renderInvestigations:rows=>{node('investigation-list').innerHTML='current records';node('investigation-list').children=rows.map(data=>{
            const card=new Node(String(data.id));card.dataset={investigationId:String(data.id),investigationRevision:`${data.user_id}:${data.status}:${data.updated_at}`};
            if(['open','investigating'].includes(data.status))for(const name of ['investigation-findings','investigation-result-evidence','investigation-action','investigation-amount']){const field=new Node(name);field.value=name==='investigation-action'?'no_change':name==='investigation-amount'?'0':'';card.fields[`.${name}`]=field;card.children.push(field);}
            return card;
        });}});
    vm.runInContext(source,context);const reads=context.window.IbemsInvestigationReads;
    return {reads,context,node,document,signal,pending,requests,queue:(options={})=>reads.queue(options),transactions:(id,options={})=>{node('investigation-user').value=String(id||'');return reads.transactions(id,options);},card:()=>node('investigation-list').children[0]};
}
module.exports={fixture,row,transaction,reply};
