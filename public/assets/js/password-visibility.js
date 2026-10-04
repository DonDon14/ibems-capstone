(function () {
    "use strict";

    const controls = new WeakMap();
    const versions = new WeakMap();

    function setVisibility(input, toggle, showing) {
        versions.set(input, (versions.get(input) || 0) + 1);
        input.type = showing ? "text" : "password";
        const label = (showing ? "Hide " : "Show ") + (input.inputMode === "numeric" ? "PIN" : "password");
        toggle.setAttribute("aria-label", label);
        toggle.setAttribute("aria-pressed", showing ? "true" : "false");
        toggle.setAttribute("title", label);
        toggle.innerHTML = '<i class="bi ' + (showing ? "bi-eye-slash" : "bi-eye") + '" aria-hidden="true"></i>';
    }

    function mask(root = document) {
        const inputs = root instanceof HTMLInputElement ? [root] : root.querySelectorAll?.('[data-visibility-enhanced="true"]') || [];
        inputs.forEach(input => {
            const toggle = controls.get(input);
            if (toggle && input.type !== "password") setVisibility(input, toggle, false);
        });
    }

    window.IbemsPasswordVisibility = {mask};

    function enhancePasswordField(input) {
        if (!(input instanceof HTMLInputElement) || input.dataset.visibilityEnhanced === "true") return;

        input.dataset.visibilityEnhanced = "true";
        const wrapper = document.createElement("span");
        wrapper.className = "password-field-shell";
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "password-visibility-toggle";
        if (input.id) toggle.setAttribute("aria-controls", input.id);
        toggle.setAttribute("aria-disabled", input.disabled ? "true" : "false");
        controls.set(input, toggle);
        setVisibility(input, toggle, false);

        toggle.addEventListener("click", function () {
            if (input.disabled || toggle.disabled) return;
            setVisibility(input, toggle, input.type !== "text");
            input.focus({preventScroll: true});
        });

        wrapper.appendChild(toggle);
    }

    function enhanceAll(root) {
        if (root instanceof HTMLInputElement && root.matches('input[type="password"]')) {
            enhancePasswordField(root);
        }
        root.querySelectorAll?.('input[type="password"]').forEach(enhancePasswordField);
    }

    enhanceAll(document);
    document.addEventListener("reset", event => {
        const pending = Array.from(event.target.querySelectorAll?.('[data-visibility-enhanced="true"]') || [], input => [input, versions.get(input)]);
        setTimeout(() => {
            if (event.defaultPrevented) return;
            pending.forEach(([input, version]) => {if (versions.get(input) === version) mask(input);});
        }, 0);
    }, true);
    ["ibems:portal-page-unload", "pagehide", "pageshow"].forEach(name => window.addEventListener(name, () => mask()));
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            if (mutation.type === "childList") {
                mutation.addedNodes.forEach(function (node) {
                    if (node instanceof Element) enhanceAll(node);
                });
            } else if (mutation.attributeName === "disabled") {
                const toggle = controls.get(mutation.target);
                if (toggle) toggle.setAttribute("aria-disabled", mutation.target.disabled ? "true" : "false");
            } else if (mutation.target.matches('[hidden], .is-hidden, .hidden, [aria-hidden="true"]') || mutation.target.style.display === "none") {
                mask(mutation.target);
            }
        });
    }).observe(document.documentElement, {childList: true, subtree: true, attributes: true, attributeFilter: ["class", "hidden", "style", "aria-hidden", "disabled"]});
})();
