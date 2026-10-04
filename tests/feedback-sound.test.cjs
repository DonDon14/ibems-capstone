const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function setup(saved = null, suspended = false, options = {}) {
    let toggle, context, played = 0;
    const frequencies = [];
    const oscillators = [];
    let disconnectedGains = 0;
    const attributes = {};
    const gestures = {};
    const button = {setAttribute(k,v) {attributes[k]=v;}, querySelector() {return null;}, addEventListener(_,fn) {toggle=fn;}};
    class AudioContext {
        constructor() { context = this; if (suspended) this.state = 'suspended'; }
        async resume() { if (options.resumeFails) throw new Error('Audio blocked'); this.state = 'running'; }
        state = 'running'; currentTime = options.currentTime ?? 1; destination = {};
        createOscillator() {const oscillator = {frequency:{}, stops:[], connect() {}, disconnect() {if(options.disconnectFails) throw new Error('Disconnect failed');}, start() {if(options.startFails) throw new Error('Start failed'); played++; frequencies.push(this.frequency.value);}, stop(time) {this.stops.push(time);}}; oscillators.push(oscillator); return oscillator;}
        createGain() {return {gain:{setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {}}, connect() {}, disconnect() {disconnectedGains++;}};}
    }
    const window = options.noAudio ? {} : {AudioContext};
    vm.runInNewContext(fs.readFileSync('public/assets/js/feedback-sound.js','utf8'), {
        window, document:{getElementById() {return button;}, addEventListener(name, handler) {gestures[name] = handler;}},
        localStorage:{getItem() {if (options.storageFails) throw new Error('Storage blocked'); return saved;}, setItem() {if (options.storageFails) throw new Error('Storage blocked');}},
    });
    return {window, toggle:() => toggle(), gesture:name=>gestures[name](), count:() => played, attributes, frequencies, oscillators, disconnectedGains:()=>disconnectedGains, advance:() => {context.currentTime += 1;}};
}

test('saved enabled preference unlocks on interaction without an unsolicited preview', async () => {
    const ui = setup('on', true);
    assert.equal(ui.attributes['aria-pressed'], 'true');
    ui.window.IbemsFeedbackSound.play('success');
    assert.equal(ui.count(), 0);
    await ui.gesture('keydown');
    assert.equal(ui.count(), 0);
    ui.window.IbemsFeedbackSound.play('success');
    assert.equal(ui.count(), 2);
});

test('saved muted preference stays silent after interaction', async () => {
    const ui = setup('off');
    await ui.gesture('pointerdown');
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(), 0);
    assert.equal(ui.attributes['aria-pressed'], 'false');
});
test('sounds stay silent until enabled, preview on enable and mute immediately', async () => {
    const ui = setup();
    ui.window.IbemsFeedbackSound.play('success');
    assert.equal(ui.count(),0);
    await ui.toggle();
    assert.equal(ui.count(),2);
    ui.window.IbemsFeedbackSound.play('success');
    assert.equal(ui.count(),2);
    ui.toggle();
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(),2);
    assert.equal(ui.attributes['aria-pressed'],'false');
});
test('unrelated feedback does not generate tones', async () => {
    const ui = setup(); await ui.toggle();
    ui.advance();
    ui.window.IbemsFeedbackSound.play('info');
    assert.equal(ui.count(),2);
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(),4);
    assert.deepEqual(ui.frequencies,[523.25,659.25,349.23,261.63]);
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(),4);
});

test('muting disconnects active and scheduled tones without touching ended tones', async () => {
    const ui = setup();
    await ui.toggle();
    ui.oscillators[0].onended();
    assert.equal(ui.disconnectedGains(), 1);
    await ui.toggle();
    assert.equal(ui.disconnectedGains(), 2);
    assert.equal(ui.oscillators[0].stops.length, 1);
    assert.equal(ui.oscillators[1].stops.length, 2);
    assert.equal(ui.oscillators[1].stops[1], undefined);
    ui.oscillators[1].onended();
    await ui.toggle();
    await ui.toggle();
    assert.equal(ui.oscillators[1].stops.length, 2);
});

test('enable preview waits for suspended audio to resume', async () => {
    const ui = setup(null, true);
    await ui.toggle();
    assert.equal(ui.count(), 2);
    assert.deepEqual(ui.frequencies, [523.25, 659.25]);
});

test('preview at audio time zero still throttles immediate transaction tones', async () => {
    const ui = setup(null, false, {currentTime: 0});
    await ui.toggle();
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(), 2);
    ui.advance();
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(), 4);
});

test('blocked storage preserves an in-session toggle and immediate mute', async () => {
    const ui = setup(null, false, {storageFails: true});
    assert.equal(ui.attributes['aria-pressed'], 'false');
    await ui.toggle();
    assert.equal(ui.count(), 2);
    assert.equal(ui.attributes['aria-pressed'], 'true');
    await ui.toggle();
    ui.advance();
    ui.window.IbemsFeedbackSound.play('error');
    assert.equal(ui.count(), 2);
    assert.equal(ui.attributes['aria-pressed'], 'false');
});

for (const [name, suspended, options] of [
    ['unavailable audio API', false, {noAudio: true}],
    ['rejected audio resume', true, {resumeFails: true}],
]) {
    test(`${name} does not throw or prevent muting`, async () => {
        const ui = setup(null, suspended, options);
        await assert.doesNotReject(ui.toggle());
        assert.doesNotThrow(() => ui.window.IbemsFeedbackSound.play('error'));
        assert.equal(ui.count(), 0);
        await assert.doesNotReject(ui.toggle());
        assert.equal(ui.attributes['aria-pressed'], 'false');
    });
}

test('audio completion cleanup failure cannot escape or prevent mute', async () => {
    const ui = setup(null, false, {disconnectFails:true});
    await ui.toggle();
    assert.doesNotThrow(()=>ui.oscillators[0].onended());
    assert.equal(ui.disconnectedGains(),1);
    await assert.doesNotReject(ui.toggle());
    assert.equal(ui.attributes['aria-pressed'],'false');
    assert.equal(ui.disconnectedGains(),2);
});

test('failed tone start releases each node and preserves usable mute toggle', async () => {
    const ui=setup(null,false,{startFails:true});
    await assert.doesNotReject(ui.toggle());
    assert.equal(ui.count(),0);
    assert.equal(ui.disconnectedGains(),2);
    await ui.toggle();
    assert.equal(ui.attributes['aria-pressed'],'false');
    assert.equal(ui.disconnectedGains(),2);
});
