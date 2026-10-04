const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs');
const source=fs.readFileSync('public/assets/js/admin-accounting-debts.js','utf8');
const formatter=source.slice(source.indexOf('function adDebtDateTime'),source.indexOf('function adDebtLabel'));
for(const shared of [false,true])test(`Admin debt dates display UTC and explicit offsets in Manila with ${shared?'shared formatter':'fallback'}`,()=>{
    const context=vm.createContext({window:{}});
    if(shared)vm.runInContext(fs.readFileSync('public/assets/js/ibems-format.js','utf8'),context);
    vm.runInContext(formatter,context);
    for(const value of ['2026-10-03 03:00:00','2026-10-03T03:00:00','2026-10-03T03:00:00Z','2026-10-03T11:00:00+08:00']){
        const result=vm.runInContext(`adDebtDateTime(${JSON.stringify(value)})`,context);
        assert.match(result,/11:00\s*AM/);assert.match(result,/2026/);
    }
    assert.equal(vm.runInContext('adDebtDateTime(null)',context),'-');
    assert.equal(vm.runInContext('adDebtDateTime("invalid date")',context),'invalid date');
});
