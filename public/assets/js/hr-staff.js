(function () {
    const addForm = document.getElementById("hr-add-form");
    const importForm = document.getElementById("hr-import-form");
    const scheduleSelect = document.getElementById("hr-salary-schedule");
    const gradeSelect = document.getElementById("hr-salary-grade");
    const stepSelect = document.getElementById("hr-salary-step");
    const salaryPreview = document.getElementById("hr-salary-preview");
    const peso = new Intl.NumberFormat("en-PH", {style: "currency", currency: "PHP"});
    const pendingForms = new WeakSet();
    const pageSignal = window.IbemsPortalNavigation?.currentSignal;

    function selectedRates() {
        try {
            return JSON.parse(scheduleSelect?.selectedOptions[0]?.dataset.rates || "[]");
        } catch (_) {
            return [];
        }
    }

    function selectOptions(select, values, preferred, label) {
        if (!select) return;
        const previous = select.value;
        select.replaceChildren(...values.map((value) => new Option(label(value), String(value))));
        const available = values.map(String);
        select.value = available.includes(previous) ? previous : available.includes(String(preferred)) ? String(preferred) : (available[0] || "");
    }

    function updateSalaryPreview() {
        const rate = selectedRates().find((item) => String(item.salary_grade) === gradeSelect?.value && String(item.salary_step) === stepSelect?.value);
        if (salaryPreview) salaryPreview.textContent = rate
            ? `Default monthly salary: ${peso.format(Number(rate.monthly_salary))} · Immediate credit limit (25%): ${peso.format(Number(rate.monthly_salary) * 0.25)}. Accounting can approve an individual salary override.`
            : "No salary rate is available for the selected grade and step.";
    }

    function updateSteps() {
        const steps = [...new Set(selectedRates().filter((item) => String(item.salary_grade) === gradeSelect?.value).map((item) => Number(item.salary_step)))].sort((a, b) => a - b);
        selectOptions(stepSelect, steps, 1, (step) => `Step ${step}`);
        updateSalaryPreview();
    }

    function updateGrades() {
        const grades = [...new Set(selectedRates().map((item) => Number(item.salary_grade)))].sort((a, b) => a - b);
        selectOptions(gradeSelect, grades, 11, (grade) => `SG-${grade}`);
        updateSteps();
    }

    scheduleSelect?.addEventListener("change", updateGrades);
    gradeSelect?.addEventListener("change", updateSteps);
    stepSelect?.addEventListener("change", updateSalaryPreview);
    updateGrades();

    function isCurrent(form) {
        return !pageSignal?.aborted && form?.isConnected && document.getElementById(form.id) === form;
    }

    function showResult(id, message, type = "progress", focus = false) {
        const result = document.getElementById(id);
        if (!result) return;
        const isError = type === "error";
        result.setAttribute("role", isError ? "alert" : "status");
        result.setAttribute("aria-live", isError ? "assertive" : "polite");
        result.setAttribute("aria-atomic", "true");
        result.dataset.type = type;
        result.style.color = isError ? "var(--feedback-error)" : type === "success" ? "var(--feedback-success)" : type === "warning" ? "var(--feedback-warning)" : "var(--text-soft)";
        result.textContent = message || "";
        result.classList.toggle("text-red-600", Boolean(isError));
        result.classList.toggle("text-emerald-700", type === "success");
        if (focus && message) result.focus();
    }

    async function submitForm(form, url, buttonId, resultId) {
        const button = document.getElementById(buttonId);
        const body = new FormData(form);
        const controls = Array.from(form.querySelectorAll("input, select, textarea, button"));
        const previousStates = controls.map(control => [control, control.disabled]);
        const buttonLabel = button.innerHTML;
        const ownsFocus = form.contains(document.activeElement);
        pendingForms.add(form);
        form.setAttribute("aria-busy", "true");
        controls.forEach(control => { control.disabled = true; });
        button.innerHTML = '<i class="bi bi-hourglass-split" aria-hidden="true"></i> Saving…';
        showResult(resultId, form === addForm ? "Creating employee account…" : "Importing staff…", "progress", ownsFocus);
        try {
            let response, data;
            try {
                response = await fetch(url, {method: "POST", body, signal: pageSignal});
                data = await response.json();
            } catch (error) {
                throw new Error("The save was not confirmed. Check existing staff before retrying.");
            }
            if (!response.ok || data?.status !== "success") throw new Error(data?.message || "The request could not be completed.");
            return data;
        } finally {
            pendingForms.delete(form);
            form.setAttribute("aria-busy", "false");
            previousStates.forEach(([control, disabled]) => { control.disabled = disabled; });
            button.innerHTML = buttonLabel;
            if (ownsFocus && isCurrent(form) && document.activeElement === document.body) button.focus();
        }
    }

    addForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (pendingForms.has(addForm) || !isCurrent(addForm)) return;
        try {
            const data = await submitForm(addForm, "/hr/staff/create", "hr-add-submit", "hr-add-result");
            if (!isCurrent(addForm)) return;
            const userId = Number(data.user_id);
            showResult("hr-add-result", Number.isInteger(userId) && userId > 0 ? `Employee account created (ID ${userId}).` : "Employee account created.", "success");
            addForm.reset();
            updateGrades();
        } catch (error) {
            if (!isCurrent(addForm)) return;
            showResult("hr-add-result", error.message || "Unable to add staff.", "error");
        }
    });

    importForm?.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (pendingForms.has(importForm) || !isCurrent(importForm)) return;
        try {
            const data = await submitForm(importForm, "/hr/staff/import-csv", "hr-import-submit", "hr-import-result");
            if (!isCurrent(importForm)) return;
            const counts = [data.created, data.skipped, data.invalid].map(value => value === null || value === undefined || value === "" ? NaN : Number(value));
            const validCounts = counts.every(value => Number.isInteger(value) && value >= 0);
            showResult("hr-import-result", validCounts ? `${counts[0]} added, ${counts[1]} existing skipped, ${counts[2]} invalid.`
                : "Staff import completed. Reload staff records to review the counts.", validCounts ? "success" : "warning");
            importForm.reset();
        } catch (error) {
            if (!isCurrent(importForm)) return;
            showResult("hr-import-result", error.message || "Unable to import staff.", "error");
        }
    });
}());
