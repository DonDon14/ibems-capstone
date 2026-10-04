(function () {
    const form = document.querySelector('.finance-report-filters');
    if (!form) return;
    const period = form.querySelector('#finance-period');
    const anchor = form.querySelector('#finance-anchor-date');
    const from = form.querySelector('#finance-from-date');
    const to = form.querySelector('#finance-to-date');
    const feedback = form.querySelector('#finance-filter-feedback');
    const help = form.querySelector('#finance-filter-help');
    const serverError = document.querySelector('[data-finance-server-error]');
    const signal = window.IbemsPortalNavigation?.currentSignal;
    if (!period || !anchor || !from || !to || !feedback || signal?.aborted) return;
    const rangeMessage = 'Custom from must be on or before Custom to. Update the dates, then select View report.';

    function sync() {
        if (signal?.aborted) return;
        const custom = period.value === 'custom';
        form.querySelector('[data-finance-anchor]').hidden = custom;
        form.querySelectorAll('[data-finance-custom]').forEach(label => { label.hidden = !custom; });
        anchor.disabled = custom;
        anchor.required = !custom;
        from.disabled = to.disabled = !custom;
        from.required = to.required = custom;
        to.setCustomValidity(custom && from.value && to.value && from.value > to.value ? rangeMessage : '');
        feedback.hidden = true;
        feedback.textContent = '';
        if (help) help.textContent = custom
            ? 'Choose the first and last dates to include in this report.'
            : 'Choose a date within the daily, weekly or monthly period to report.';
    }

    form.addEventListener('invalid', event => {
        if (signal?.aborted || ![anchor, from, to].includes(event.target)) return;
        const labels = new Map([[anchor, 'Date within period'], [from, 'Custom from'], [to, 'Custom to']]);
        feedback.textContent = event.target === to && to.validity.customError
            ? rangeMessage : `Choose a valid date for ${labels.get(event.target)} before viewing the report.`;
        feedback.hidden = false;
        if (serverError) serverError.hidden = true;
    }, true);
    form.addEventListener('submit', event => {
        if (signal?.aborted) { event.preventDefault(); return; }
        if (event.defaultPrevented) return;
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
        } else {
            feedback.hidden = true;
        }
    });
    function updateDraft() {
        if (signal?.aborted) return;
        if (serverError) serverError.hidden = true;
        sync();
    }
    [period, anchor, from, to].forEach(control => {
        control.addEventListener('input', updateDraft);
        control.addEventListener('change', updateDraft);
    });
    window.addEventListener('pageshow', sync);
    window.IbemsPortalNavigation?.onCleanup?.(() => window.removeEventListener('pageshow', sync));
    sync();
}());
