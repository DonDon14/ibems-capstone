const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/js/admin-user-view.js', 'utf8');
test('Employee dialogs enter, contain and restore focus, resetting inner scroll', () => {
    const ids = ['uv-add-modal', 'uv-edit-modal', 'uv-import-modal', 'uv-view-modal'];
    const document = { activeElement: null, getElementById: id => modals[id] };
    const control = () => ({ tabIndex: 0, isConnected: true, dispatchEvent() {}, getClientRects: () => [1], focus() { document.activeElement = this; } });
    const opener = control();
    const modals = Object.fromEntries(ids.map(id => {
        const close = control(), last = control(), handlers = {}, classes = new Set(['is-hidden']);
        const scroll = { top: 200, scrollTo({ top }) { this.top = top; } };
        const result = { textContent: 'Prior error', attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } };
        return [id, { close, last, scroll, result, handlers, classes,
            classList: { add: value => classes.add(value), remove: value => classes.delete(value) },
            querySelector: selector => selector === '.uv-modal-scroll' ? scroll : selector === '.uv-modal-result' ? result : close,
            querySelectorAll: () => [close, last], addEventListener: (name, fn) => { handlers[name] = fn; } }];
    }));
    const helperStart = source.indexOf('const uvModalOpeners');
    const setupStart = source.indexOf('["uv-add-modal", "uv-edit-modal", "uv-import-modal", "uv-view-modal"].forEach');
    const context = vm.createContext({ document, Event, setUvImportResult() {} });
    vm.runInContext(source.slice(helperStart, source.indexOf('function setQuickChipState', helperStart)) +
        source.slice(setupStart, source.indexOf('["uv-a", "uv-e"]', setupStart)), context);
    for (const id of ids) {
        document.activeElement = opener;
        const before = vm.runInContext('uvDetailRequestId', context);
        vm.runInContext(`openModal('${id}')`, context);
        assert.equal(vm.runInContext('uvDetailRequestId', context), before + 1);
        const modal = modals[id];
        assert.equal(document.activeElement, modal.close);
        assert.equal(modal.scroll.top, 0);
        assert.equal(modal.result.textContent, '');
        assert.equal(modal.result.attributes.role, 'status');
        const key = (key, shiftKey = false) => modal.handlers.keydown({ key, shiftKey, currentTarget: modal, preventDefault() {} });
        key('Tab', true); assert.equal(document.activeElement, modal.last);
        key('Tab'); assert.equal(document.activeElement, modal.close);
        key('Escape'); assert.equal(document.activeElement, opener);
        assert.equal(vm.runInContext('uvDetailRequestId', context), before + 2);
        assert.ok(modal.classes.has('is-hidden'));
    }
});
