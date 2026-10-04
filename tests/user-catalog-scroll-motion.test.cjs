const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/user-stores.js', 'utf8');
test('live reduced-motion change finishes only a closing dialog on the active page', () => {
    let handler, closing = false;
    const calls = [];
    const signal = {aborted: false};
    const context = vm.createContext({usPageSignal: signal,
        window: {matchMedia: () => ({addEventListener: (name, fn, options) => {
            assert.equal(name, 'change'); assert.equal(options.signal, signal); handler = fn;
        }})},
        document: {getElementById: () => ({classList: {contains: () => closing}})},
        usCloseProduct: immediate => calls.push(immediate)});
    const start = source.lastIndexOf('window.matchMedia("(prefers-reduced-motion: reduce)").addEventListener');
    vm.runInContext(source.slice(start, source.indexOf('usPageSignal.addEventListener("abort"', start)), context);
    handler({matches: true});
    assert.deepEqual(calls, []);
    closing = true;
    handler({matches: false});
    assert.deepEqual(calls, []);
    handler({matches: true});
    assert.deepEqual(calls, [true]);
    signal.aborted = true;
    handler({matches: true});
    assert.deepEqual(calls, [true]);
});
test('catalog scrolling follows live motion preference while retaining alignment', () => {
    let reduced = false;
    const calls = [];
    const context = vm.createContext({window: {matchMedia: () => ({matches: reduced})},
        target: {scrollIntoView: options => calls.push(options)}});
    vm.runInContext(source.slice(source.indexOf('function usScrollIntoView'), source.indexOf('function usHasActiveFilters')), context);
    vm.runInContext('usScrollIntoView(target, {block:"nearest",inline:"center"})', context);
    assert.equal(calls[0].behavior, 'smooth');
    reduced = true;
    vm.runInContext('usScrollIntoView(target, {block:"start"})', context);
    assert.equal(calls[1].behavior, 'instant');
    assert.equal(calls[0].inline, 'center');
    assert.equal(calls[1].block, 'start');
    vm.runInContext('usScrollIntoView(null, {block:"start"})', context);
    assert.equal(calls.length, 2);
});
for (const reduced of [true, false]) test(`dialog close respects reduced motion ${reduced} and cancels swipe`, () => {
    const classes = new Set(['is-open']);
    const modal = {classList: {contains: name => classes.has(name), add: (...names) => names.forEach(name => classes.add(name)), remove: (...names) => names.forEach(name => classes.delete(name))}};
    const cancelled = [];
    let scheduled = null, focused = false;
    const context = vm.createContext({window: {matchMedia: () => ({matches: reduced}), clearTimeout: id => cancelled.push(id), setTimeout: fn => {scheduled = fn;}},
        document: {getElementById: () => modal, body: {classList: {remove: () => {}}}}});
    vm.runInContext('let usProductModalTimer=1,usSwipeMotionTimer=2,usSwipeStartX=20,usSwipeLastX=30,usSwipeStartTime=40;', context);
    context.usProductModalTrigger = {focus: () => {focused = true;}};
    vm.runInContext(source.slice(source.indexOf('function usCloseProduct'), source.indexOf('function usRenderPager')), context);
    vm.runInContext('usCloseProduct()', context);
    assert.deepEqual(cancelled, [1, 2]);
    assert.equal(vm.runInContext('usSwipeStartX', context), null);
    assert.equal(classes.has('is-hidden'), reduced);
    assert.equal(focused, reduced);
    if (!reduced) {scheduled(); assert.equal(classes.has('is-hidden'), true); assert.equal(focused, true);}
});
