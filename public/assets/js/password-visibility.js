(function () {
    "use strict";

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
        toggle.setAttribute("aria-label", "Show " + (input.inputMode === "numeric" ? "PIN" : "password"));
        toggle.setAttribute("aria-pressed", "false");
        toggle.setAttribute("title", toggle.getAttribute("aria-label"));
        toggle.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i>';

        toggle.addEventListener("click", function () {
            const showing = input.type === "text";
            input.type = showing ? "password" : "text";
            const label = (showing ? "Show " : "Hide ") + (input.inputMode === "numeric" ? "PIN" : "password");
            toggle.setAttribute("aria-label", label);
            toggle.setAttribute("aria-pressed", showing ? "false" : "true");
            toggle.setAttribute("title", label);
            toggle.innerHTML = '<i class="bi ' + (showing ? "bi-eye" : "bi-eye-slash") + '" aria-hidden="true"></i>';
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
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node instanceof Element) enhanceAll(node);
            });
        });
    }).observe(document.documentElement, {childList: true, subtree: true});
})();
