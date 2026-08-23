(function () {
    "use strict";

    const shell = document.querySelector("[data-user-navigation-shell]");
    const main = document.getElementById("user-page-content");
    const status = document.getElementById("user-navigation-status");
    if (!shell || window.IbemsUserNavigation || !main || typeof window.fetch !== "function" || typeof window.DOMParser !== "function") return;

    const supportedPath = /\/user\/(dashboard|stores|history|deductions)\/?$/i;
    const loadedOnceScripts = new Set(
        Array.from(document.querySelectorAll("script[data-user-page-once][src]"), (script) => script.src)
    );
    let pageController = new AbortController();
    let navigationController = null;
    let visitSequence = 0;
    let completedVisits = 0;

    shell.dataset.userNavigationReady = "true";
    shell.dataset.userNavigationVisits = "0";

    const api = {
        get currentSignal() {
            return pageController.signal;
        },
        isCurrent(signal) {
            return signal === pageController.signal && !signal.aborted;
        },
    };
    window.IbemsUserNavigation = api;

    const announce = (message) => {
        if (status) status.textContent = message;
    };

    const setBusy = (busy) => {
        shell.classList.toggle("is-user-navigating", busy);
        main.setAttribute("aria-busy", busy ? "true" : "false");
    };

    const pageName = (url) => {
        const match = url.pathname.match(supportedPath);
        return match ? match[1].toLowerCase() : "";
    };

    const isSupportedUrl = (url) => url.origin === window.location.origin && pageName(url) !== "";

    const updateCsrfMetadata = (nextDocument) => {
        ["csrf-token-name", "csrf-token-value", "csrf-header-name", "csrf-cookie-name"].forEach((name) => {
            const next = nextDocument.querySelector(`meta[name="${name}"]`);
            const current = document.querySelector(`meta[name="${name}"]`);
            if (next && current) current.content = next.content;
        });
    };

    const ensurePageStyles = async (nextDocument) => {
        const currentHrefs = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'), (link) => link.href));
        const additions = Array.from(nextDocument.querySelectorAll("link[data-user-page-style][href]"))
            .filter((link) => !currentHrefs.has(link.href));

        await Promise.all(additions.map((source) => new Promise((resolve, reject) => {
            const link = document.createElement("link");
            Array.from(source.attributes).forEach((attribute) => link.setAttribute(attribute.name, attribute.value));
            link.onload = resolve;
            link.onerror = () => reject(new Error(`Unable to load page styles: ${source.href}`));
            document.head.appendChild(link);
        })));
    };

    const executePageScripts = async (nextDocument) => {
        const scripts = Array.from(nextDocument.querySelectorAll("script[data-user-page-script]"));
        for (const source of scripts) {
            const sourceUrl = source.src;
            const loadOnce = source.hasAttribute("data-user-page-once");
            if (loadOnce && sourceUrl && loadedOnceScripts.has(sourceUrl)) continue;

            await new Promise((resolve, reject) => {
                const script = document.createElement("script");
                Array.from(source.attributes).forEach((attribute) => script.setAttribute(attribute.name, attribute.value));
                if (sourceUrl) {
                    script.onload = () => {
                        if (loadOnce) loadedOnceScripts.add(sourceUrl);
                        else script.remove();
                        resolve();
                    };
                    script.onerror = () => {
                        script.remove();
                        reject(new Error(`Unable to load page behavior: ${sourceUrl}`));
                    };
                    document.body.appendChild(script);
                    return;
                }

                script.textContent = source.textContent;
                document.body.appendChild(script);
                if (!loadOnce) script.remove();
                resolve();
            });
        }
    };

    const updateNavigationState = (url) => {
        const destination = pageName(url);
        document.querySelectorAll(".app-menu a[href], .user-mobile-nav a[href]").forEach((link) => {
            const active = pageName(new URL(link.href, window.location.href)) === destination;
            link.classList.toggle("is-active", active);
            if (active) link.setAttribute("aria-current", "page");
            else link.removeAttribute("aria-current");
        });
        localStorage.setItem("ibems.nav.last.user", url.href);
    };

    const replacePage = async (nextDocument, url) => {
        const nextMain = nextDocument.getElementById("user-page-content");
        const nextShell = nextDocument.querySelector("[data-user-navigation-shell]");
        if (!nextMain || !nextShell) {
            throw new Error("The destination is not a compatible User Portal page.");
        }

        await ensurePageStyles(nextDocument);
        pageController.abort();
        pageController = new AbortController();

        main.className = nextMain.className;
        main.innerHTML = nextMain.innerHTML;
        document.title = nextDocument.title || document.title;
        updateCsrfMetadata(nextDocument);
        updateNavigationState(url);
        await executePageScripts(nextDocument);
        document.dispatchEvent(new CustomEvent("ibems:user-page-loaded", { detail: { main, url: url.href } }));
    };

    const visit = async (destination, options = {}) => {
        const url = destination instanceof URL ? destination : new URL(destination, window.location.href);
        if (!isSupportedUrl(url)) {
            window.location.assign(url.href);
            return;
        }

        const sequence = ++visitSequence;
        navigationController?.abort();
        navigationController = new AbortController();
        window.dispatchEvent(new CustomEvent("ibems:account-menu-close"));
        setBusy(true);
        announce(`Loading ${pageName(url)}.`);

        try {
            const response = await fetch(url.href, {
                credentials: "same-origin",
                headers: {
                    "Accept": "text/html",
                    "X-Requested-With": "IBEMS-User-Navigation",
                },
                signal: navigationController.signal,
            });
            if (!response.ok) throw new Error(`Navigation failed with status ${response.status}.`);

            const finalUrl = new URL(response.url || url.href, window.location.href);
            if (!isSupportedUrl(finalUrl)) {
                window.location.assign(finalUrl.href);
                return;
            }

            const nextDocument = new DOMParser().parseFromString(await response.text(), "text/html");
            if (sequence !== visitSequence) return;

            if (options.history !== false) {
                history.replaceState({ ...(history.state || {}), ibemsUserPage: true, scrollY: window.scrollY }, "", window.location.href);
            }
            await replacePage(nextDocument, finalUrl);
            if (sequence !== visitSequence) return;

            if (options.history !== false) {
                history.pushState({ ibemsUserPage: true, scrollY: 0 }, "", finalUrl.href);
            }

            completedVisits += 1;
            shell.dataset.userNavigationVisits = String(completedVisits);
            const targetScroll = Number(options.scrollY || 0);
            window.scrollTo({ top: targetScroll, left: 0, behavior: "auto" });
            if (options.focus !== false) main.focus({ preventScroll: true });
            announce(`${pageName(finalUrl)} loaded.`);
        } catch (error) {
            if (error?.name === "AbortError") return;
            window.location.assign(url.href);
        } finally {
            if (sequence === visitSequence) setBusy(false);
        }
    };

    document.addEventListener("click", (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest("a[href]");
        if (!link || link.target || link.hasAttribute("download") || link.dataset.noUserNavigation !== undefined) return;

        const url = new URL(link.href, window.location.href);
        if (!isSupportedUrl(url) || (url.pathname === window.location.pathname && url.search === window.location.search)) return;
        event.preventDefault();
        visit(url);
    });

    window.addEventListener("popstate", (event) => {
        const url = new URL(window.location.href);
        if (!isSupportedUrl(url)) return;
        visit(url, { history: false, focus: false, scrollY: event.state?.scrollY || 0 });
    });

    window.addEventListener("beforeunload", () => {
        navigationController?.abort();
        pageController.abort();
    });

    history.replaceState({ ...(history.state || {}), ibemsUserPage: true, scrollY: window.scrollY }, "", window.location.href);
}());
