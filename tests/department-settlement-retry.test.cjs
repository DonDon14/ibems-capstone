const test = require('node:test');
const assert = require('node:assert/strict');
const { create } = require('../public/assets/js/department-settlement-retry.js');

const payload = { department_id: 1, period_id: 10, amount: 50, reference_no: 'SYNTHETIC', remarks: 'Synthetic payment' };
function fixture() {
    const values = new Map();
    const storage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) };
    let keys = 0;
    return { storage, newKey: () => `synthetic-intent-${++keys}` };
}

test('lost response survives reload and retries the original key and payload', async () => {
    const f = fixture();
    let sent;
    await assert.rejects(create(f.storage, 900, f.newKey).openForm().submit(payload, async request => { sent = request; throw new Error('Response lost'); }));
    const recovered = create(f.storage, 900, f.newKey);
    assert.deepEqual(recovered.pending().payload, payload);
    let retry;
    await recovered.openForm().submit(payload, async request => { retry = request; return { status: 'success' }; });
    assert.deepEqual(retry, sent);
    assert.equal(recovered.pending(), null);
    let distinct;
    await recovered.openForm().submit(payload, async request => { distinct = request; return { status: 'success' }; });
    assert.notEqual(distinct.request_key, sent.request_key);
});

test('changed intent cannot replace an unresolved payment', async () => {
    const f = fixture();
    const retry = create(f.storage, 900, f.newKey);
    await assert.rejects(retry.openForm().submit(payload, async () => { throw new Error('Navigation abort'); }));
    let calls = 0;
    await assert.rejects(retry.openForm().submit({ ...payload, amount: 60 }, async () => { calls++; }), /original details/);
    assert.equal(calls, 0);
    assert.equal(retry.pending().payload.amount, 50);
});

test('concurrent submits are blocked and confirmed rejection permits corrected intent', async () => {
    const f = fixture();
    const retry = create(f.storage, 900, f.newKey);
    let finish;
    const first = retry.openForm().submit(payload, () => new Promise(resolve => { finish = resolve; }));
    await assert.rejects(retry.openForm().submit(payload, async () => {}), /already in progress/);
    finish({ status: 'success' });
    await first;
    await assert.rejects(retry.openForm().submit(payload, async () => { throw Object.assign(new Error('Rejected'), { confirmedRejection: true }); }));
    assert.equal(retry.pending(), null);
});

test('storage failure prevents sending and actor recovery stays isolated', async () => {
    let calls = 0;
    const retry = create({ getItem: () => null, setItem: () => { throw new Error('Storage unavailable'); } }, 900, () => 'synthetic-intent');
    await assert.rejects(retry.openForm().submit(payload, async () => { calls++; }));
    assert.equal(calls, 0);
    const f = fixture();
    await assert.rejects(create(f.storage, 900, f.newKey).openForm().submit(payload, async () => { throw new Error('Lost'); }));
    assert.equal(create(f.storage, 901, f.newKey).pending(), null);
});

test('shared browser lock prevents two tabs from creating different keys concurrently', async () => {
    const f = fixture();
    let locked = false;
    const exclusive = async task => {
        if (locked) throw new Error('Another tab is submitting');
        locked = true;
        try { return await task(); } finally { locked = false; }
    };
    const firstTab = create(f.storage, 900, f.newKey, exclusive);
    const secondTab = create(f.storage, 900, f.newKey, exclusive);
    let finish;
    const first = firstTab.openForm().submit(payload, () => new Promise(resolve => { finish = resolve; }));
    let calls = 0;
    await assert.rejects(secondTab.openForm().submit(payload, async () => { calls++; }), /Another tab/);
    assert.equal(calls, 0);
    finish({ status: 'success' });
    await first;
    assert.equal(firstTab.pending(), null);
});

function ledgerFixture() {
    const f = fixture();
    let locked = false;
    const exclusive = async task => {
        if (locked) throw new Error('Another tab is submitting');
        locked = true;
        try { return await task(); } finally { locked = false; }
    };
    const postings = new Map();
    const requests = [];
    const send = async request => {
        requests.push(request);
        if (!postings.has(request.request_key)) postings.set(request.request_key, { status: 'success', entry_id: postings.size + 1 });
        return postings.get(request.request_key);
    };
    return { ...f, exclusive, postings, requests, send, client: () => create(f.storage, 900, f.newKey, exclusive) };
}

test('resolved recovery form in another tab never becomes a new equal payment', async () => {
    const f = ledgerFixture();
    const tabA = f.client();
    const originalForm = tabA.openForm();
    await assert.rejects(originalForm.submit(payload, async request => { await f.send(request); throw new Error('Response lost'); }));
    const originalKey = originalForm.pending.request_key;
    const tabB = f.client();
    const recoveryForm = tabB.openForm();
    assert.equal(recoveryForm.pending.request_key, originalKey);
    await originalForm.submit(payload, f.send);
    assert.equal(tabA.pending(), null);
    await assert.rejects(recoveryForm.submit(payload, f.send), /no longer pending/);
    assert.equal(f.requests.length, 2);
    assert.equal(f.postings.size, 1);
    assert.deepEqual(f.requests.map(request => request.request_key), [originalKey, originalKey]);
    // An explicitly reopened fresh form can represent a separate equal payment.
    await tabB.openForm().submit(payload, f.send);
    assert.equal(f.postings.size, 2);
    assert.notEqual(f.requests.at(-1).request_key, originalKey);
});

test('stale recovery cannot send or remove a newer unresolved intent', async () => {
    const f = ledgerFixture();
    const tabA = f.client();
    const originalForm = tabA.openForm();
    await assert.rejects(originalForm.submit(payload, async request => { await f.send(request); throw new Error('Lost'); }));
    const staleForm = f.client().openForm();
    await originalForm.submit(payload, f.send);
    const changed = { ...payload, amount: 60 };
    const nextForm = tabA.openForm();
    await assert.rejects(nextForm.submit(changed, async request => { await f.send(request); throw new Error('Lost again'); }));
    const newerIntent = tabA.pending();
    const before = f.requests.length;
    await assert.rejects(staleForm.submit(payload, f.send), /no longer pending/);
    assert.equal(f.requests.length, before);
    assert.deepEqual(tabA.pending(), newerIntent);
    // A reload recovers the newer frozen payload and key, rather than the old form.
    const reloadedForm = f.client().openForm();
    await reloadedForm.submit(changed, f.send);
    assert.equal(f.requests.at(-1).request_key, newerIntent.request_key);
    assert.equal(f.postings.size, 2);
});

test('cancel and reopen retain unresolved intent, while resolved forms cannot submit again', async () => {
    const f = ledgerFixture();
    const tabA = f.client();
    // Opening then cancelling an unsent form does not create a pending payment.
    tabA.openForm();
    assert.equal(tabA.pending(), null);
    const sentForm = tabA.openForm();
    await assert.rejects(sentForm.submit(payload, async request => { await f.send(request); throw new Error('Navigation abort'); }));
    const original = tabA.pending();
    // Discarding the form simulates Cancel/portal cleanup; persisted recovery survives.
    const reopened = f.client().openForm();
    assert.deepEqual(reopened.pending, original);
    await reopened.submit(payload, f.send);
    await assert.rejects(reopened.submit(payload, f.send), /no longer pending/);
    await assert.rejects(sentForm.submit(payload, f.send), /no longer pending/);
    assert.equal(f.requests.length, 2);
    assert.equal(f.postings.size, 1);
});

test('confirmed rejected form requires reopening before a corrected payment', async () => {
    const f = ledgerFixture();
    const client = f.client();
    const rejected = client.openForm();
    await assert.rejects(rejected.submit(payload, async () => { throw Object.assign(new Error('Rejected'), { confirmedRejection: true }); }));
    assert.equal(client.pending(), null);
    await assert.rejects(rejected.submit({ ...payload, amount: 60 }, f.send), /no longer pending/);
    assert.equal(f.requests.length, 0);
    await client.openForm().submit({ ...payload, amount: 60 }, f.send);
    assert.equal(f.postings.size, 1);
});

test('fresh form opened before another tab sends cannot reinterpret the new pending payment', async () => {
    const f = ledgerFixture();
    const tabA = f.client();
    const staleFreshForm = f.client().openForm();
    await assert.rejects(tabA.openForm().submit(payload, async request => { await f.send(request); throw new Error('Lost'); }));
    await assert.rejects(staleFreshForm.submit(payload, f.send), /Another settlement is now pending/);
    assert.equal(f.requests.length, 1);
    assert.equal(f.postings.size, 1);
});
