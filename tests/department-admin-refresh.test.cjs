const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const start = source.indexOf('    function setAdminListState(', source.indexOf('function initAdmin()'));
const loader = source.slice(start, source.indexOf('    function syncDepartmentReason', start));
test('department refresh clears stale rows and ignores obsolete failure', async () => {
    const elements = Object.fromEntries(['department-total','department-active','department-with-head','department-admin-body','department-refresh','department-add','department-admin-table-region'].map(id => [id, {textContent:'2',innerHTML:'old rows',contains:()=>false,setAttribute(){},removeAttribute(){},classList:{toggle(){}}}]));
    const pending = [], results = [];
    const context = vm.createContext({document:{getElementById:id=>elements[id]},pageSignal:{aborted:false},
        api:()=>new Promise((resolve,reject)=>pending.push({resolve,reject})),
        dataState:(_,message)=>message,escapeHtml:String,setResult:(...args)=>results.push(args)});
    vm.runInContext('let loadRequestId=0,departmentLoadState="ready",departments=[1],people=[1];'+loader,context);
    vm.runInContext('render=()=>{document.getElementById("department-total").textContent="3";};',context);
    const first=vm.runInContext('load()',context);
    assert.equal(elements['department-total'].textContent,'Loading…');
    assert.ok(!elements['department-admin-body'].innerHTML.includes('old rows'));
    const second=vm.runInContext('load()',context);
    pending[1].resolve({departments:[1,2,3],people:[]}); await second;
    pending[0].reject(new Error('old error')); await first;
    assert.equal(elements['department-total'].textContent,'3');
    assert.equal(results.at(-1)[1],'3 departments loaded.');
    const third=vm.runInContext('load()',context);
    pending[2].reject(new Error('current error')); await third;
    assert.equal(elements['department-total'].textContent,'Unavailable');
    assert.match(elements['department-admin-body'].innerHTML,/Departments unavailable/);
    assert.equal(results.at(-1)[1],'');
});
