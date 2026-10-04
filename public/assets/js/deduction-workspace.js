(() => {
    const rows = Array.from(document.querySelectorAll("[data-deduction-candidate]"));
    if (rows.length === 0) return;

    const search = document.getElementById("deduction-register-search");
    const type = document.getElementById("deduction-register-type");
    const salary = document.getElementById("deduction-register-salary");
    const count = document.getElementById("deduction-match-count");
    const selectionSummary = document.getElementById('deduction-selection-summary');
    const syncRows = new Map();

    function updateSelectionSummary() {
        if (!selectionSummary) return;
        const selected = rows.filter(row => row.querySelector('input[type="checkbox"][name$="[selected]"]').checked);
        const hidden = selected.filter(row => row.hidden).length;
        selectionSummary.className = `deduction-selection-summary${hidden ? ' is-warning' : ''}`;
        const message = selected.length === 0 ? 'No employees selected.'
            : `${selected.length} employee${selected.length === 1 ? '' : 's'} selected.${hidden ? ` ${hidden} selected employee${hidden === 1 ? ' is' : 's are'} hidden by the current filters and will be included.` : ''}`;
        if (selectionSummary.textContent !== message) selectionSummary.textContent = message;
    }

    for (const row of rows) {
        const checkbox = row.querySelector('input[type="checkbox"][name$="[selected]"]');
        const choice = row.querySelector('.deduction-choice');
        const amount = row.querySelector('.deduction-request-amount');
        const reason = row.querySelector('.deduction-preparation-reason');
        const cutoff = Number(amount.dataset.cutoffDebt || 0);

        function syncChoice(changed = false) {
            [choice, amount, reason].forEach(control => { control.disabled = !checkbox.checked; });
            amount.readOnly = choice.value !== 'partial';
            amount.required = checkbox.checked;
            amount.min = choice.value === 'none' ? '0' : '0.01';
            if (choice.value === 'full' && changed) amount.value = cutoff.toFixed(2);
            if (choice.value === 'none' && changed) amount.value = '0.00';
            if (choice.value === 'partial' && changed && (Number(amount.value) <= 0 || Number(amount.value) >= cutoff)) {
                amount.value = Math.max(0.01, Math.floor(cutoff * 50) / 100).toFixed(2);
            }
            reason.required = checkbox.checked && choice.value === 'none';
            updateSelectionSummary();
        }

        checkbox.addEventListener('change', () => syncChoice());
        choice.addEventListener('change', () => syncChoice(true));
        syncChoice();
        syncRows.set(row, syncChoice);
        row.addEventListener('invalid', () => {
            if (!row.hidden) return;
            row.hidden = false;
            const visible = rows.filter((candidate) => !candidate.hidden).length;
            count.textContent = `Showing ${visible} of ${rows.length} employees, including employees needing correction.`;
            updateSelectionSummary();
        }, true);
    }

    function filterRows() {
        const needle = search.value.trim().toLocaleLowerCase();
        const selectedType = type.value;
        const selectedSalary = salary.value;
        let visible = 0;
        for (const row of rows) {
            const matches = (row.dataset.search || "").toLocaleLowerCase().includes(needle)
                && (selectedType === "all" || row.dataset.employeeType === selectedType)
                && (selectedSalary === "all" || row.dataset.salaryState === selectedSalary);
            row.hidden = !matches;
            if (matches) visible += 1;
        }
        count.textContent = `Showing ${visible} of ${rows.length} employees.`;
        updateSelectionSummary();
    }

    search.addEventListener("input", filterRows);
    type.addEventListener("input", filterRows);
    type.addEventListener("change", filterRows);
    salary.addEventListener("input", filterRows);
    salary.addEventListener("change", filterRows);
    document.getElementById("deduction-select-matching").addEventListener("click", () => {
        for (const row of rows) {
            if (!row.hidden) {
                row.querySelector('input[type="checkbox"][name$="[selected]"]').checked = true;
                syncRows.get(row)();
            }
        }
    });
    document.getElementById("deduction-clear-selection").addEventListener("click", () => {
        for (const row of rows) {
            row.querySelector('input[type="checkbox"][name$="[selected]"]').checked = false;
            syncRows.get(row)();
        }
    });
})();
