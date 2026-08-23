(function () {
    const body = document.body;
    if (!body.classList.contains("user-mobile-enabled")) return;

    const installButton = document.getElementById("user-mobile-install");
    let installPrompt = null;

    window.addEventListener("beforeinstallprompt", (event) => {
        event.preventDefault();
        installPrompt = event;
        if (installButton) installButton.hidden = false;
    });

    installButton?.addEventListener("click", async () => {
        if (!installPrompt) return;
        await installPrompt.prompt();
        await installPrompt.userChoice;
        installPrompt = null;
        installButton.hidden = true;
        window.dispatchEvent(new CustomEvent("ibems:account-menu-close"));
    });

    window.addEventListener("appinstalled", () => {
        installPrompt = null;
        if (installButton) installButton.hidden = true;
    });

    const script = document.currentScript;
    const serviceWorkerUrl = script?.dataset.serviceWorkerUrl || "";
    const canRegister = "serviceWorker" in navigator
        && serviceWorkerUrl !== ""
        && (window.isSecureContext || ["localhost", "127.0.0.1", "[::1]"].includes(window.location.hostname));

    if (canRegister) {
        const scopeUrl = new URL("./", serviceWorkerUrl);
        navigator.serviceWorker.register(serviceWorkerUrl, { scope: scopeUrl.pathname })
            .then(() => { body.dataset.userPwaState = "ready"; })
            .catch(() => { body.dataset.userPwaState = "unavailable"; });
    }
}());
