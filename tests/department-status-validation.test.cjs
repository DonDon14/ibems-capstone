const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/department-debt-pages.js', 'utf8');
const start = source.indexOf('    function syncDepartmentReason()');
const helper = source.slice(start, source.indexOf('\n    function openModal', start));
const submitStart = source.indexOf('        const save = document.getElementById("department-save");');
const guard = source.slice(submitStart, source.indexOf('        if (Number(document.getElementById("department-head")', submitStart));

for (const scenario of [
    { status: '0', value: '   ', blocked: true },
    { status: '0', value: 'Closed for restructuring', blocked: false },
    { status: '1', value: '', blocked: false },
]) {
    test(`Department reason validation: status ${scenario.status}, blocked ${scenario.blocked}`, () => {
        let focused = false, reported = false, reachedRequest = false;
        const reason = { value: scenario.value, required: false, validity: 'old error',
            setCustomValidity(message) { this.validity = message; },
            reportValidity() { reported = true; }, focus() { focused = true; } };
        const context = vm.createContext({ document: { getElementById: id => id === 'department-status-reason' ? reason : id === 'department-active-input' ? { value: scenario.status } : {} },
            departmentSaving:false,pageSignal:{aborted:false},departmentModal:{classList:{contains:()=>false}},request: () => { reachedRequest = true; } });
        vm.runInContext(`${helper}\nfunction submit() {${guard}\nrequest();}\nsubmit();`, context);
        assert.equal(reason.required, scenario.status === '0');
        assert.equal(reachedRequest, !scenario.blocked);
        assert.equal(focused, scenario.blocked);
        assert.equal(reported, scenario.blocked);
        assert.equal(reason.validity, scenario.blocked ? 'Enter a reason before deactivating this department.' : '');
    });
}
