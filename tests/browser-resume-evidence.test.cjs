const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');
const { validatePriorEvidence, readPriorEvidence } = require('./qa/browser_resume_evidence.cjs');
const root = path.resolve(__dirname, '..');
const page = { role: 'USER', url: 'user/dashboard', actual: '/user/dashboard', expectedPath: '/user/dashboard', requiredControls: ['#dashboard'], status: 'tested', errors: [], failures: [] };
function cleanup(directory) {
  assert.equal(path.dirname(path.resolve(directory)), path.resolve(os.tmpdir()));
  assert.ok(path.basename(directory).startsWith('ibems-resume-'));
  fs.rmSync(directory, { recursive: true, force: true });
}

for (const [name, document] of [
  ['saved runtime error', { completed: false, results: [page], errors: ['Uncaught mock runtime error'] }],
  ['saved failed page', { completed: false, results: [{ ...page, status: 'failed', failures: ['unexpected destination'] }], errors: [] }]
]) for (const flags of [['--resume'], ['--extended-only', '--resume']]) test(`real ${flags.join(' ')} rejects ${name} and preserves original evidence bytes`, () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ibems-resume-rejection-'));
  try {
    const file = path.join(directory, 'results.json');
    const bytes = Buffer.from(JSON.stringify(document, null, 4) + '\n');
    fs.writeFileSync(file, bytes);
    const run = spawnSync(process.execPath, ['tests/qa/ui_full_system.cjs', ...flags], {
      cwd: root, env: { ...process.env, IBEMS_QA_BROWSER_EVIDENCE: path.relative(root, directory) }, encoding: 'utf8', timeout: 10000, windowsHide: true
    });
    assert.equal(run.status, 1); assert.match(run.stderr, /Prior browser evidence contains page or runtime failures/);
    assert.deepEqual(fs.readFileSync(file), bytes);
    assert.deepEqual(fs.readdirSync(directory), ['results.json']);
  } finally { cleanup(directory); }
});

test('complete prior document validation rejects malformed or hidden page failures', () => {
  for (const document of [
    { results: [page] }, { results: [page], errors: null },
    { results: [null], errors: [] }, { results: [{ ...page, requiredControls: null }], errors: [] },
    { results: [{ ...page, errors: ['hidden page exception'] }], errors: [] },
    { results: [{ ...page, actual: '/login' }], errors: [] },
    { results: [{ scenario: 'workflow', status: 'tested', failures: 'malformed' }], errors: [] }
  ]) assert.throws(() => validatePriorEvidence(document, { allowIncomplete: true }));
});

test('accepted prior evidence is archived byte-for-byte before subsequent failure output', () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'ibems-resume-archive-'));
  try {
    const file = path.join(directory, 'results.json');
    const bytes = Buffer.from(JSON.stringify({ completed: true, results: [page], errors: [] }, null, 3) + '\n');
    fs.writeFileSync(file, bytes);
    const prior = readPriorEvidence(file, { required: true }); assert.deepEqual(prior.results, [page]);
    const archive = fs.readdirSync(directory).find(name => name.startsWith('results-before-resume-'));
    assert.ok(archive); fs.writeFileSync(file, '{"completed":false,"failure":"later initialization failure"}');
    assert.deepEqual(fs.readFileSync(path.join(directory, archive)), bytes);
  } finally { cleanup(directory); }
});

test('incomplete clean evidence requires explicit resume and retains its failure record', () => {
  const document = { completed: false, results: [page], errors: [], failure: 'reviewed harness timeout' };
  assert.throws(() => validatePriorEvidence(document));
  assert.equal(validatePriorEvidence(document, { allowIncomplete: true }), document);
  assert.equal(document.failure, 'reviewed harness timeout');
});
