const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'deduction-workspace.js'), 'utf8');

function control(value = '') {
    return {
        value,
        textContent: '',
        listeners: {},
        addEventListener(type, listener) { this.listeners[type] = listener; },
        fire(type) { this.listeners[type](); },
    };
}

function candidate(search, employeeType, salaryState) {
    const checkbox = { ...control(), checked: false };
    const choice = control('full');
    const amount = { ...control('100.00'), dataset: { cutoffDebt: '100.00' }, readOnly: false };
    const reason = { ...control(), required: false };
    return {
        ...control(),
        dataset: { search, employeeType, salaryState },
        hidden: false,
        checkbox,
        choice,
        amount,
        reason,
        querySelector(selector) {
            return {
                'input[type="checkbox"][name$="[selected]"]': checkbox,
                '.deduction-choice': choice,
                '.deduction-request-amount': amount,
                '.deduction-preparation-reason': reason,
            }[selector];
        },
    };
}

test('shared deduction register filters by search, employee type and salary, then selects only matches', () => {
    const rows = [
        candidate('ana faculty f001', 'faculty', 'provided'),
        candidate('ben staff s002', 'staff', 'missing'),
        candidate('ann staff s003', 'staff', 'provided'),
    ];
    const controls = {
        'deduction-register-search': control(),
        'deduction-register-type': control('all'),
        'deduction-register-salary': control('all'),
        'deduction-match-count': control(),
        'deduction-select-matching': control(),
        'deduction-clear-selection': control(),
    };
    vm.runInNewContext(source, {
        document: {
            querySelectorAll: () => rows,
            getElementById: (id) => controls[id],
        },
    });

    controls['deduction-register-search'].value = 'an';
    controls['deduction-register-search'].fire('input');
    assert.deepEqual(rows.map((row) => row.hidden), [false, true, false]);
    controls['deduction-register-type'].value = 'staff';
    controls['deduction-register-type'].fire('input');
    assert.deepEqual(rows.map((row) => row.hidden), [true, true, false]);
    assert.equal(controls['deduction-match-count'].textContent, 'Showing 1 of 3 employees.');
    controls['deduction-select-matching'].fire('click');
    assert.deepEqual(rows.map((row) => row.checkbox.checked), [false, false, true]);
    controls['deduction-register-salary'].value = 'missing';
    controls['deduction-register-salary'].fire('input');
    assert.deepEqual(rows.map((row) => row.hidden), [true, true, true]);
    controls['deduction-clear-selection'].fire('click');
    assert.deepEqual(rows.map((row) => row.checkbox.checked), [false, false, false]);

    rows[0].checkbox.checked = true;
    rows[0].choice.value = 'none';
    rows[0].choice.fire('change');
    assert.equal(rows[0].amount.value, '0.00');
    assert.equal(rows[0].amount.readOnly, true);
    assert.equal(rows[0].reason.required, true);
    controls['deduction-clear-selection'].fire('click');
    assert.equal(rows[0].reason.required, false, 'bulk clear releases the reason requirement');
    rows[0].hidden = false;
    controls['deduction-select-matching'].fire('click');
    assert.equal(rows[0].reason.required, true, 'bulk select synchronizes no-deduction reason validation');
    rows[0].hidden = true;
    controls['deduction-clear-selection'].fire('click');
    assert.equal(rows[0].reason.required, false, 'bulk clear also synchronizes hidden selected rows');
    rows[0].hidden = true;
    rows[0].fire('invalid');
    assert.equal(rows[0].hidden, false, 'invalid hidden fields become focusable');
    assert.match(controls['deduction-match-count'].textContent, /including employees needing correction/);
    rows[0].choice.value = 'partial';
    rows[0].choice.fire('change');
    assert.equal(rows[0].amount.value, '50.00');
    assert.equal(rows[0].amount.readOnly, false);
    assert.equal(rows[0].reason.required, false);
});

test('workspace script is inert when no unprepared register is rendered', () => {
    vm.runInNewContext(source, { document: { querySelectorAll: () => [] } });
});

test('excluded invalid drafts cannot block selected rows and selection feedback explains hidden inclusions', () => {
    const rows = [candidate('ana faculty', 'faculty', 'provided'), candidate('ben staff', 'staff', 'provided')];
    rows[0].checkbox.checked = true;
    rows[1].amount.value = '9999';
    const controls = Object.fromEntries(['deduction-match-count', 'deduction-select-matching', 'deduction-clear-selection', 'deduction-selection-summary'].map(id => [id, control()]));
    controls['deduction-register-search'] = control(); controls['deduction-register-type'] = control('all'); controls['deduction-register-salary'] = control('all');
    vm.runInNewContext(source, { document: { querySelectorAll: () => rows, getElementById: id => controls[id] } });
    assert.equal(rows[1].amount.disabled, true); assert.equal(rows[1].choice.disabled, true); assert.equal(rows[1].reason.disabled, true);
    assert.equal(rows[0].amount.disabled, false); assert.equal(rows[0].amount.required, true);
    controls['deduction-register-search'].value = 'ben'; controls['deduction-register-search'].fire('input');
    assert.match(controls['deduction-selection-summary'].textContent, /1 employee selected.*1 selected employee is hidden.*will be included/);
    assert.equal(rows[0].checkbox.checked, true, 'filtering preserves explicitly selected employees');
    controls['deduction-clear-selection'].fire('click'); assert.equal(controls['deduction-selection-summary'].textContent, 'No employees selected.');
    controls['deduction-select-matching'].fire('click'); assert.equal(rows[1].amount.disabled, false); assert.equal(rows[1].choice.disabled, false);
    rows[1].choice.value = 'none'; rows[1].choice.fire('change');
    assert.equal(rows[1].amount.min, '0'); assert.equal(rows[1].reason.required, true);
    rows[1].choice.value = 'partial'; rows[1].choice.fire('change'); assert.equal(rows[1].amount.min, '0.01');
});
