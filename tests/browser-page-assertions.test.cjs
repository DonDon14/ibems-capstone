const { test } = require('node:test');
const assert = require('node:assert/strict');
const { pageFailures } = require('./qa/browser_page_assertions.cjs');
const page = { url: '/index.php/store/inventory', title: 'Inventory', text: 'Products', controls: { '#inventory-search': true } };
const expected = { expectedPath: '/store/inventory', requiredControls: ['#inventory-search'] };
test('navigation verifies destination and the requested workflow controls', () => {
  assert.deepEqual(pageFailures(page, expected), []);
  assert.deepEqual(pageFailures({ ...page, url: '/store-admin/stores' }, expected), ['unexpected destination']);
  assert.deepEqual(pageFailures({ ...page, controls: {} }, expected), ['missing control #inventory-search']);
});
test('HTTP error pages and runtime exceptions fail acceptance', () => {
  assert.ok(pageFailures({ ...page, title: '500', text: 'DatabaseException' }, expected).includes('error page'));
  assert.ok(pageFailures(page, { ...expected, exceptions: ['Uncaught exception'] }).includes('JavaScript exception'));
  assert.ok(pageFailures({ ...page, url: '/login' }, expected).includes('unexpected login'));
});
