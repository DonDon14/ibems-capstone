const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/modern-controls.js', 'utf8');
const position = source.slice(source.indexOf('    const positionPopup ='), source.indexOf('    const emitChange ='));
function layout({ height, width = 600, top, bottom, date = false }) {
    const popup = { style: {}, scrollHeight: 600, classList: { contains: () => date } };
    const context = vm.createContext({ window: { innerWidth: width, innerHeight: height }, popup,
        trigger: { getBoundingClientRect: () => ({ top, bottom, left: 35, width: 521 }) } });
    vm.runInContext(position + 'positionPopup(trigger, popup, 320);', context);
    const cap = parseFloat(popup.style.maxHeight);
    const y = popup.style.top === 'auto' ? height - parseFloat(popup.style.bottom) - cap : parseFloat(popup.style.top);
    return { popup, cap, y };
}
test('short-screen select popup fits above its trigger without clipping', () => {
    const result = layout({ height: 300, top: 128, bottom: 172 });
    assert.ok(result.y >= 12);
    assert.ok(result.y + result.cap <= 300 - 12);
    assert.ok(result.cap < 180);
});
test('normal-screen select retains its full-height scrolling area', () => {
    const result = layout({ height: 844, top: 400, bottom: 444 });
    assert.equal(result.cap, 364);
    assert.ok(result.y + result.cap <= 844 - 12);
});
test('short-screen calendar uses available space and narrow viewport width', () => {
    const result = layout({ height: 300, width: 320, top: 80, bottom: 124, date: true });
    assert.ok(result.y >= 12);
    assert.ok(result.y + result.cap <= 300 - 12);
    assert.ok(parseFloat(result.popup.style.width) <= 296);
});
