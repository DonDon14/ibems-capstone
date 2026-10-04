(function () {
    'use strict';
    const button = document.getElementById('feedback-sound-toggle');
    let enabled = false;
    let context;
    const activeTones = new Set();
    function releaseTone(tone) {
        activeTones.delete(tone);
        try { tone.oscillator.disconnect(); } catch (_) {}
        try { tone.gain.disconnect(); } catch (_) {}
    }
    let lastPlayed = -Infinity;
    try { enabled = localStorage.getItem('ibems-feedback-sound') === 'on'; } catch (_) {}
    function update() {
        if (!button) return;
        const label = enabled ? 'Mute transaction sounds' : 'Enable transaction sounds';
        button.setAttribute('aria-pressed', String(enabled));
        button.setAttribute('aria-label', label);
        button.title = label;
        const status = button.querySelector('[data-feedback-sound-status]');
        if (status) status.textContent = enabled ? 'On · success and error tones' : 'Muted · enable success and error tones';
        const icon = button.querySelector('i');
        if (icon) icon.className = enabled ? 'bi bi-volume-up' : 'bi bi-volume-mute';
    }
    async function unlock() {
        if (!enabled) return;
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            if (!Audio) return;
            context ||= new Audio();
            if (context.state === 'suspended') await context.resume();
        } catch (_) {}
    }
    function play(type) {
        if (!enabled || !context || context.state !== 'running') return;
        if (!['success', 'error'].includes(type)) return;
        const now = context.currentTime;
        if (now - lastPlayed < 0.35) return;
        lastPlayed = now;
        try {
            const notes = type === 'success' ? [523.25, 659.25] : [349.23, 261.63];
            notes.forEach((frequency, index) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                const start = now + index * 0.12;
                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0, start);
                gain.gain.linearRampToValueAtTime(0.055, start + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.001, start + 0.17);
                oscillator.connect(gain);
                gain.connect(context.destination);
                const tone = {oscillator, gain};
                activeTones.add(tone);
                oscillator.onended = () => releaseTone(tone);
                try {
                    oscillator.start(start);
                    oscillator.stop(start + 0.18);
                } catch (_) {
                    releaseTone(tone);
                    try { oscillator.stop(); } catch (_) {}
                }
            });
        } catch (_) {}
    }
    button?.addEventListener('click', async () => {
        enabled = !enabled;
        if (!enabled) {
            activeTones.forEach(({oscillator, gain}) => {
                try { gain.disconnect(); } catch (_) {}
                try { oscillator.stop(); } catch (_) {}
            });
            activeTones.clear();
        }
        try { localStorage.setItem('ibems-feedback-sound', enabled ? 'on' : 'off'); } catch (_) {}
        update();
        await unlock();
        if (enabled) play('success');
    });
    document.addEventListener('pointerdown', unlock, {passive: true});
    document.addEventListener('keydown', unlock);
    window.IbemsFeedbackSound = {play};
    update();
}());
