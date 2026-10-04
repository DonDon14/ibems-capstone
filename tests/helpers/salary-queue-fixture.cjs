const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/accounting-debts.part2.js', 'utf8');
const loader = source.slice(source.indexOf('let accountingSalaryQueueRequest'), source.indexOf('function getMainFilteredRows'));
function fixture(fetch) {
    let context;
    const nodes = new Map();
    class Node {
        constructor(id) { this.id = id; this.children = []; this.attrs = {}; this.isConnected = true; this.disabled = false; this.className = ''; this.focusCount = 0; this.events = {}; this.classList = {contains: name => this.className.split(' ').includes(name)}; }
        appendChild(child) { child.parent = this; this.children.push(child); }
        contains(node) { return node === this || this.children.some(child => child.contains(node)); }
        setAttribute(k,v) { this.attrs[k] = v; }
        removeAttribute(k) { delete this.attrs[k]; }
        addEventListener(k,v) { this.events[k] = v; }
        focus() { this.focusCount++; document.activeElement = this; }
        scrollIntoView() { this.scrolled = true; }
        clear() { if (this.contains(document.activeElement) && document.activeElement !== this) document.activeElement = document.body; this.children = []; }
        set textContent(value) { this.clear(); this.text = value; }
        get textContent() { return this.text || ''; }
        set innerHTML(value) {
            this.clear(); this.html = value;
            if (value.includes('<button')) { const button = new Node('generated-button'); button.dataset = {openSalaryUser: '900001'}; this.appendChild(button); }
            if (value.includes('<div class="flex')) this.appendChild(new Node('card'));
        }
        get innerHTML() { return this.html || ''; }
        get childElementCount() { return this.children.length; }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        querySelectorAll(selector) { const all = this.children.flatMap(child => [child,...child.querySelectorAll('*')]); return selector === '*' ? all : all.filter(child => child.id === 'generated-button'); }
    }
    const document = {body: {},activeElement: null,getElementById: id => nodes.get(id),createElement: () => new Node('generated-button')};
    document.activeElement = document.body;
    for (const id of ['acct-salary-queue','acct-salary-queue-feedback','acct-salary-refresh','acct-salary-due-count']) nodes.set(id,new Node(id));
    const signal = {aborted: false};
    const requests = [], pending = [];
    context = vm.createContext({document,window:{IbemsPortalNavigation:{currentSignal:signal}},aEscape: value=>String(value).replaceAll('<','&lt;').replaceAll('>','&gt;'),aMoney: String,openEmployeeModal(){},fetch: (url,options) => {requests.push({url,options}); return fetch ? fetch(url,options) : new Promise(resolve=>pending.push(resolve));}});
    vm.runInContext(loader, context);
    return {context,document,signal,requests,pending,body:nodes.get('acct-salary-queue'),feedback:nodes.get('acct-salary-queue-feedback'),refresh:nodes.get('acct-salary-refresh'),count:nodes.get('acct-salary-due-count'),Node,run: (page=1,manual=false) => vm.runInContext(`loadScheduledSalaryQueue(${page}, {manual:${manual}})`,context)};
}
const row = {user_id:900001,name:'Fictional employee',employee_id:'FICTIONAL',effective_date:'2026-10-01',revision:1,salary_grade:1,salary_step:1,monthly_salary:'10000.00',credit_limit:'2500.00',reason:'Fictional reason'};
const data = (overrides={}) => ({status:'success',rows:[{...row}],due_count:1,total_pending:2,page:1,page_size:1,has_more:true,...overrides});
const response = (payload,ok=true) => ({ok,json:async()=>payload});
module.exports = {fixture,row,data,response};
