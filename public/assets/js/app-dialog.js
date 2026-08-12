(function () {
    let activeResolve = null;
    let previousFocus = null;

    const root = document.createElement("div");
    root.className = "app-dialog is-hidden";
    root.setAttribute("role", "dialog");
    root.setAttribute("aria-modal", "true");
    root.setAttribute("aria-labelledby", "app-dialog-title");
    root.setAttribute("aria-describedby", "app-dialog-message");
    root.innerHTML = `
        <div class="app-dialog-card">
            <div class="app-dialog-icon" aria-hidden="true"><i class="bi bi-question-lg"></i></div>
            <div class="app-dialog-copy">
                <h2 id="app-dialog-title">Confirm action</h2>
                <p id="app-dialog-message"></p>
            </div>
            <label class="app-dialog-input-wrap is-hidden" for="app-dialog-input">
                <span id="app-dialog-input-label">Value</span>
                <input id="app-dialog-input" type="text" autocomplete="off">
                <textarea id="app-dialog-textarea" rows="4" class="is-hidden"></textarea>
            </label>
            <p id="app-dialog-error" class="app-dialog-error is-hidden" role="alert"></p>
            <div class="app-dialog-actions">
                <button type="button" class="secondary-btn" data-dialog-cancel>Cancel</button>
                <button type="button" class="primary-btn" data-dialog-confirm>Continue</button>
            </div>
        </div>`;
    document.body.appendChild(root);

    const title = root.querySelector("#app-dialog-title");
    const message = root.querySelector("#app-dialog-message");
    const icon = root.querySelector(".app-dialog-icon i");
    const inputWrap = root.querySelector(".app-dialog-input-wrap");
    const inputLabel = root.querySelector("#app-dialog-input-label");
    const input = root.querySelector("#app-dialog-input");
    const textarea = root.querySelector("#app-dialog-textarea");
    const error = root.querySelector("#app-dialog-error");
    const cancelButton = root.querySelector("[data-dialog-cancel]");
    const confirmButton = root.querySelector("[data-dialog-confirm]");
    let mode = "confirm";
    let requireValue = false;
    let activeInput = input;

    const finish = (result) => {
        if (root.classList.contains("is-hidden")) return;
        root.classList.add("is-hidden");
        document.body.classList.remove("has-app-dialog");
        const resolve = activeResolve;
        activeResolve = null;
        previousFocus?.focus?.();
        previousFocus = null;
        resolve?.(result);
    };

    const show = (options) => {
        if (activeResolve) finish(mode === "prompt" ? null : false);
        mode = options.mode || "confirm";
        requireValue = Boolean(options.required);
        previousFocus = document.activeElement;
        title.textContent = options.title || (mode === "alert" ? "Notice" : "Confirm action");
        message.textContent = options.message || "";
        icon.className = `bi ${options.icon || (mode === "alert" ? "bi-info-lg" : "bi-question-lg")}`;
        root.dataset.tone = options.tone || "default";
        inputWrap.classList.toggle("is-hidden", mode !== "prompt");
        inputLabel.textContent = options.inputLabel || "Value";
        activeInput = options.multiline ? textarea : input;
        input.classList.toggle("is-hidden", activeInput !== input);
        textarea.classList.toggle("is-hidden", activeInput !== textarea);
        inputWrap.htmlFor = activeInput.id;
        activeInput.value = options.defaultValue || "";
        activeInput.placeholder = options.placeholder || "";
        error.textContent = "";
        error.classList.add("is-hidden");
        cancelButton.classList.toggle("is-hidden", mode === "alert");
        cancelButton.textContent = options.cancelLabel || "Cancel";
        confirmButton.textContent = options.confirmLabel || (mode === "alert" ? "OK" : "Continue");
        root.classList.remove("is-hidden");
        document.body.classList.add("has-app-dialog");

        window.requestAnimationFrame(() => {
            (mode === "prompt" ? activeInput : confirmButton).focus();
            if (mode === "prompt") activeInput.select();
        });

        return new Promise((resolve) => {
            activeResolve = resolve;
        });
    };

    cancelButton.addEventListener("click", () => finish(mode === "prompt" ? null : false));
    confirmButton.addEventListener("click", () => {
        if (mode === "prompt") {
            const value = activeInput.value.trim();
            if (requireValue && !value) {
                error.textContent = "This field is required.";
                error.classList.remove("is-hidden");
                activeInput.focus();
                return;
            }
            finish(value);
            return;
        }
        finish(true);
    });
    root.addEventListener("mousedown", (event) => {
        if (event.target === root && mode !== "alert") finish(mode === "prompt" ? null : false);
    });
    root.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && mode !== "alert") {
            event.preventDefault();
            finish(mode === "prompt" ? null : false);
        }
        if (event.key === "Enter" && mode === "prompt" && event.target === input) {
            event.preventDefault();
            confirmButton.click();
        }
        if (event.key === "Tab") {
            const controls = Array.from(root.querySelectorAll("button:not(.is-hidden), input:not(.is-hidden), textarea:not(.is-hidden)"));
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    window.IbemsDialog = {
        alert(messageText, options = {}) {
            return show({ ...options, mode: "alert", message: messageText });
        },
        confirm(messageText, options = {}) {
            return show({ ...options, mode: "confirm", message: messageText });
        },
        prompt(messageText, options = {}) {
            return show({ ...options, mode: "prompt", message: messageText });
        },
    };
}());
