const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function nativeThemeFixture() {
    let click, motionChange;
    const transitions = [], classes = new Set(), saved = [], attributes = {};
    const root = {dataset: {theme: 'light'}, classList: {
        add: (...names) => names.forEach(name => classes.add(name)),
        remove: (...names) => names.forEach(name => classes.delete(name)),
    }};
    const motion = {matches: false, addEventListener(name, fn) {motionChange = fn;}};
    const button = {setAttribute: (name, value) => attributes[name] = value,
        querySelector: () => null, addEventListener(name, fn) {click = fn;}};
    vm.runInNewContext(fs.readFileSync('public/assets/js/theme.js', 'utf8'), {
        document: {documentElement: root, getElementById: () => button, querySelector: () => null,
            startViewTransition(commit) {
                let rejectReady, resolveReady, finish;
                const transition = {commit, ready: new Promise((resolve, reject) => {resolveReady = resolve; rejectReady = reject;}),
                    finished: new Promise(resolve => {finish = resolve;}), skipped: false, skipCalls: 0,
                    skipTransition() {this.skipped = true; this.skipCalls++; rejectReady(new Error('Animation skipped'));},
                    rejectReady, resolveReady, finish};
                transitions.push(transition);
                return transition;
            }},
        localStorage: {setItem: (key, value) => saved.push(value)}, CustomEvent: function () {},
        window: {matchMedia: () => motion, clearTimeout() {}, dispatchEvent() {}},
    });
    return {root, classes, saved, attributes, transitions, click: () => click(),
        reduce() {motion.matches = true; motionChange({matches: true});}};
}

test('rapid native theme changes handle rejected readiness without losing the latest commit', async () => {
    const fixture = nativeThemeFixture();
    fixture.click(); fixture.click();
    assert.equal(fixture.transitions[0].skipped, true);
    fixture.transitions[0].commit(); fixture.transitions[0].finish();
    fixture.transitions[1].commit(); fixture.transitions[1].resolveReady(); fixture.transitions[1].finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(fixture.root.dataset.theme, 'light');
    assert.equal(fixture.attributes['aria-label'], 'Use dark mode');
    assert.deepEqual(fixture.saved, ['light']);
    assert.equal(fixture.classes.size, 0);
});

test('reduced motion skips pending native readiness and keeps the requested theme', async () => {
    const fixture = nativeThemeFixture();
    fixture.click(); fixture.reduce();
    assert.equal(fixture.transitions[0].skipped, true);
    fixture.transitions[0].commit(); fixture.transitions[0].finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(fixture.root.dataset.theme, 'dark');
    assert.equal(fixture.attributes['aria-pressed'], 'true');
    assert.deepEqual(fixture.saved, ['dark']);
    assert.equal(fixture.classes.size, 0);
    fixture.click();
    assert.equal(fixture.root.dataset.theme, 'light');
    assert.equal(fixture.transitions.length, 1);
    assert.equal(fixture.transitions[0].skipCalls, 1);
});

test('failed native animation readiness preserves the successful theme update', async () => {
    const fixture = nativeThemeFixture();
    fixture.click(); fixture.transitions[0].commit();
    fixture.transitions[0].rejectReady(new Error('Snapshot unavailable'));
    fixture.transitions[0].finish();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(fixture.root.dataset.theme, 'dark');
    assert.deepEqual(fixture.saved, ['dark']);
    assert.equal(fixture.classes.size, 0);
});

test('superseded native transitions cannot commit or clear the latest theme switch', async () => {
    let click;
    const transitions = [], classes = new Set(), saved = [];
    const root = { dataset: { theme: 'light' }, classList: {
        add: name => classes.add(name), remove: (...names) => names.forEach(name => classes.delete(name))
    } };
    const button = { setAttribute() {}, querySelector() { return null; }, addEventListener(name, fn) { click = fn; } };
    vm.runInNewContext(fs.readFileSync('public/assets/js/theme.js', 'utf8'), {
        document: { documentElement: root, getElementById: () => button, querySelector: () => null,
            startViewTransition(commit) {
                let finish;
                const transition = { commit, skipped: false, finished: new Promise(resolve => { finish = resolve; }),
                    skipTransition() { this.skipped = true; }, finish() { finish(); } };
                transitions.push(transition); return transition;
            } },
        localStorage: { setItem(key, value) { saved.push(value); } }, CustomEvent: function () {},
        window: { matchMedia: () => ({ matches: false }), clearTimeout() {}, dispatchEvent() {} }
    });
    click(); click();
    assert.equal(transitions[0].skipped, true);
    transitions[1].commit();
    transitions[0].commit(); transitions[0].finish();
    await Promise.resolve();
    assert.equal(root.dataset.theme, 'light');
    assert.deepEqual(saved, ['light']);
    assert.equal(classes.has('theme-transitioning'), true);
    transitions[1].finish(); await Promise.resolve();
    assert.equal(classes.size, 0);
});

test('native transition startup failure still applies theme and clears animation state',()=>{
    let click;
    const classes=new Set();
    const attributes={};
    const saved=[];
    const root={dataset:{theme:'light'},classList:{add:n=>classes.add(n),remove:(...names)=>names.forEach(n=>classes.delete(n))}};
    const button={setAttribute:(k,v)=>attributes[k]=v,querySelector:()=>null,addEventListener:(name,fn)=>click=fn};
    vm.runInNewContext(fs.readFileSync('public/assets/js/theme.js','utf8'),{
        document:{documentElement:root,getElementById:()=>button,querySelector:()=>null,startViewTransition(){throw new Error('Unavailable');}},
        localStorage:{setItem:(key,value)=>saved.push(value)},CustomEvent:function(){},
        window:{matchMedia:()=>({matches:false}),clearTimeout(){},dispatchEvent(){}}
    });
    assert.doesNotThrow(()=>click());
    assert.equal(root.dataset.theme,'dark');
    assert.equal(attributes['aria-label'],'Use light mode');
    assert.equal(classes.size,0);
    assert.deepEqual(saved,['dark']);
    click();
    assert.equal(root.dataset.theme,'light');
    assert.equal(classes.size,0);
});

for (const view of ['login', 'select_role']) {
    test(`${view} theme bootstrap honors system theme when preference storage is denied`, () => {
        const source = fs.readFileSync(`app/Views/auth/${view}.php`, 'utf8');
        const bootstrap = source.match(/<script \{csp-script-nonce\}>([\s\S]*?)<\/script>/)[1];
        for (const dark of [true, false]) {
            const root = {dataset: {}};
            assert.doesNotThrow(() => vm.runInNewContext(bootstrap, {
                document: {documentElement: root},
                localStorage: {getItem() {throw new Error('Storage denied');}},
                matchMedia() {return {matches: dark};},
            }));
            assert.equal(root.dataset.theme, dark ? 'dark' : 'light');
        }
    });
}

test('reduced motion cancels a pending fallback theme commit even when storage is denied', () => {
    let click, reduced = false, next = 0;
    const timers = new Map();
    const root = {dataset: {theme: 'light'}, classList: {add() {}, remove() {}}};
    const button = {setAttribute() {}, querySelector() {return null;}, addEventListener(name, fn) {click = fn;}};
    const context = vm.createContext({
        document: {documentElement: root, getElementById() {return button;}, querySelector() {return null;}},
        localStorage: {setItem() {throw new Error('Storage denied');}}, CustomEvent: function () {},
        window: {
            matchMedia() {return {matches: reduced};}, dispatchEvent() {},
            setTimeout(fn) {timers.set(++next, fn); return next;},
            clearTimeout(id) {timers.delete(id);},
        },
    });
    vm.runInContext(fs.readFileSync('public/assets/js/theme.js', 'utf8'), context);
    click();
    reduced = true;
    click();
    for (const fn of timers.values()) fn();
    assert.equal(root.dataset.theme, 'light');
    assert.equal(timers.size, 0);
});

test('enabling reduced motion finishes a pending theme switch without another click', () => {
    let click, motionChange, next = 0;
    const timers = new Map();
    const classes = new Set();
    const root = {dataset: {theme: 'light'}, classList: {
        add(...names) {names.forEach(name => classes.add(name));},
        remove(...names) {names.forEach(name => classes.delete(name));},
    }};
    const button = {setAttribute() {}, querySelector() {return null;}, addEventListener(name, fn) {click = fn;}};
    const motion = {matches: false, addEventListener(name, fn) {motionChange = fn;}};
    vm.runInNewContext(fs.readFileSync('public/assets/js/theme.js', 'utf8'), {
        document: {documentElement: root, getElementById() {return button;}, querySelector() {return null;}},
        localStorage: {setItem() {}}, CustomEvent: function () {},
        window: {matchMedia() {return motion;}, dispatchEvent() {},
            setTimeout(fn) {timers.set(++next, fn); return next;},
            clearTimeout(id) {timers.delete(id);}},
    });
    click();
    assert.equal(root.dataset.theme, 'light');
    assert.equal(timers.size, 2);
    motion.matches = true;
    motionChange({matches: true});
    assert.equal(root.dataset.theme, 'dark');
    assert.equal(timers.size, 0);
    assert.equal(classes.size, 0);
});
