const fs = require('node:fs');
const path = require('node:path');
const { randomUUID } = require('node:crypto');
const { normalizedPath } = require('./browser_page_assertions.cjs');

function validatePriorEvidence(document, { allowIncomplete = false } = {}) {
  if (!document || typeof document !== 'object' || Array.isArray(document)
    || !Array.isArray(document.results) || !Array.isArray(document.errors)
    || (document.completed !== undefined && typeof document.completed !== 'boolean')
    || (document.failure !== undefined && typeof document.failure !== 'string')) {
    throw new Error('Malformed prior browser evidence document');
  }
  if (document.errors.length || document.results.some(row => row?.status === 'failed'
    || (Array.isArray(row?.errors) && row.errors.length)
    || (Array.isArray(row?.failures) && row.failures.length))) {
    throw new Error('Prior browser evidence contains page or runtime failures; preserve it and use a fresh evidence directory');
  }
  for (const row of document.results) {
    if (!row || typeof row !== 'object' || Array.isArray(row) || row.status !== 'tested'
      || (row.errors !== undefined && !Array.isArray(row.errors))
      || (row.failures !== undefined && !Array.isArray(row.failures))) {
      throw new Error('Malformed prior browser evidence result');
    }
    if (row.url !== undefined) {
      if (![row.role, row.url, row.actual, row.expectedPath].every(value => typeof value === 'string' && value.length)
        || !Array.isArray(row.requiredControls) || !row.requiredControls.every(value => typeof value === 'string')
        || !Array.isArray(row.errors) || !Array.isArray(row.failures)) {
        throw new Error('Malformed prior browser page evidence');
      }
      if (normalizedPath(row.actual) !== normalizedPath(row.expectedPath)) {
        throw new Error('Prior browser evidence contains an unexpected saved page destination');
      }
    } else if (typeof row.scenario !== 'string' || !row.scenario) {
      throw new Error('Malformed prior browser scenario evidence');
    }
  }
  if ((document.completed === true && document.failure)
    || (!allowIncomplete && document.completed !== true)) {
    throw new Error('A successful base browser run is required; explicit resume permits only a reviewed harness failure without page/runtime failures');
  }
  return document;
}

function readPriorEvidence(file, { required = false, allowIncomplete = false } = {}) {
  if (!fs.existsSync(file)) {
    if (required) throw new Error('Prior browser evidence is required');
    return null;
  }
  const bytes = fs.readFileSync(file);
  const document = validatePriorEvidence(JSON.parse(bytes.toString('utf8')), { allowIncomplete });
  // Validate before any browser/output writes. Retain exact accepted prior bytes
  // before a resumed run can update results.json or fail during initialization.
  const archive = path.join(path.dirname(file), `results-before-resume-${randomUUID()}.json`);
  fs.writeFileSync(archive, bytes, { flag: 'wx' });
  return document;
}

module.exports = { validatePriorEvidence, readPriorEvidence };
