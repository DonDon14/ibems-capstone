const fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync('public/assets/js/accounting-import.js','utf8');
const part=fs.readFileSync('public/assets/js/accounting-debts.part2.js','utf8');
const validRow=overrides=>({line:2,name:'Fictional employee',email:'fictional@example.test',user_type:'staff',employment_type:'regular',salary_schedule_code:'STANDARD',salary_grade:'1',salary_step:1,salary_effective_date:'2026-10-01',monthly_salary:12000,credit_limit:3000,credit_percentage:25,action:'update',...overrides});
const previewData=overrides=>({status:'success',filename:'fictional.csv',total_rows:2,valid_rows:1,invalid_rows:1,create_count:0,update_count:1,credit_limit_update_count:1,valid_preview:[validRow()],invalid_preview:[{line:3,name:'Fictional invalid employee',email:'invalid@example.test',error:'Fictional invalid schedule'}],...overrides});
const appliedData=overrides=>({status:'success',filename:'fictional.csv',batch_id:900001,total_rows:2,valid_rows:1,invalid_rows:1,invalid_preview:[{line:3,error:'Fictional invalid schedule'}],...overrides});
const reply=(data,ok=true)=>({ok,json:async()=>data});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function fixture({fetch=async url=>reply(url.includes('preview')?previewData():appliedData()),confirm=async()=>true,refresh=async()=>true,soundThrows=false}={}){
    const nodes=new Map(),requests=[],reviews=[],sounds=[];let document;
    class Node{
        constructor(id,control=false){this.id=id;this.control=control;this.disabled=false;this.isConnected=true;this.visible=true;this.style={display:'grid'};this.children=[];this.attrs={};this.innerHTML=id==='import-csv-submit'?'Apply Import':id==='import-csv-preview'?'Preview CSV':'';this.textContent='';this.className='';this.tabIndex=0;this.files=[];this.events={};this.classList={contains:name=>this.className.split(' ').includes(name)};}
        append(child){this.children.push(child);child.parent=this;}contains(child){return child===this||this.children.some(node=>node.contains(child));}
        getClientRects(){return this.visible?[{}]:[];}getAttribute(k){return this.attrs[k]??null;}setAttribute(k,v){this.attrs[k]=v;}removeAttribute(k){delete this.attrs[k];}
        querySelectorAll(){return this.children.filter(child=>child.control);}querySelector(){return null;}addEventListener(k,fn){this.events[k]=fn;}
        focus(){if(!this.disabled)document.activeElement=this;}scrollIntoView(){this.scrolled=true;}compareDocumentPosition(){return 4;}
    }
    const node=(id,control=false)=>{if(!nodes.has(id))nodes.set(id,new Node(id,control));return nodes.get(id);};
    const modal=node('import-csv-modal');for(const id of ['close-import-csv','import-csv-file','import-csv-preview','import-csv-submit','import-csv-result','import-csv-valid','import-csv-invalid'])modal.append(node(id,!['import-csv-result','import-csv-valid','import-csv-invalid'].includes(id)));
    node('import-csv-submit').disabled=true;const file={name:'fictional.csv'};node('import-csv-file').files=[file];
    document={body:node('body'),activeElement:node('import-csv-preview'),getElementById:node};const signal={aborted:false};
    const context=vm.createContext({document,getComputedStyle:e=>e.style,Node:{DOCUMENT_POSITION_PRECEDING:2,DOCUMENT_POSITION_FOLLOWING:4},importCsvPreviewReady:false,acctDefaultCreditPercentage:25,aMoney:String,aCategory:String,aEscape:v=>String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('"','&quot;'),
        FormData:class{constructor(){this.fields=[];}append(...field){this.fields.push(field);}},
        window:{IbemsPortalNavigation:{currentSignal:signal},IbemsDialog:{confirm:async(...args)=>{reviews.push(args);return confirm(...args);}},IbemsFeedbackSound:{play:type=>{sounds.push(type);if(soundThrows)throw Error('Audio unavailable');}}},
        fetch:async(url,options)=>{requests.push({url,options});return fetch(url,options);},loadData:refresh});
    vm.runInContext(source,context);
    vm.runInContext(part.slice(part.indexOf('function invalidateImportCsvPreview()'),part.indexOf('async function previewImportCsv()')),context);
    vm.runInContext(part.slice(part.indexOf('function containAccountingDialogKeyboard'),part.indexOf('containAccountingDialogKeyboard("import-csv-modal"')),context);
    vm.runInContext('containAccountingDialogKeyboard("import-csv-modal",closeImportModal)',context);
    const actions=context.window.IbemsAccountingImport;
    return {actions,context,modal,node,document,signal,file,requests,reviews,sounds,result:node('import-csv-result'),preview:()=>actions.preview(),apply:()=>actions.apply(),key(key,handled=false){let prevented=false;modal.events.keydown({key,currentTarget:modal,defaultPrevented:handled,preventDefault(){prevented=true;}});return prevented;}};
}
module.exports={fixture,previewData,appliedData,validRow,reply,tick};
