(function () {
    "use strict";

    const shell = document.querySelector("[data-portal-navigation-shell]");
    const main = document.querySelector("[data-portal-page-content]");
    const status = document.querySelector("[data-portal-navigation-status]");
    if (!shell || window.IbemsPortalNavigation || !main || typeof window.fetch !== "function" || typeof window.DOMParser !== "function") return;

    const nativeFetch = window.fetch.bind(window);
    const nativeSetTimeout = window.setTimeout.bind(window);
    const nativeClearTimeout = window.clearTimeout.bind(window);
    const nativeSetInterval = window.setInterval.bind(window);
    const nativeClearInterval = window.clearInterval.bind(window);
    const nativeRequestAnimationFrame = window.requestAnimationFrame.bind(window);
    const nativeCancelAnimationFrame = window.cancelAnimationFrame.bind(window);
    const nativeAddEventListener = EventTarget.prototype.addEventListener;
    const loadedOnceScripts = new Set();
    const navigationKey = shell.dataset.portalNavigationKey || "portal";
    const supportedPaths = new Set(
        Array.from(document.querySelectorAll("[data-portal-navigation-link][href]"), (link) => normalizePath(new URL(link.href, window.location.href).pathname))
    );
    let pageRuntime = createPageRuntime();
    let executingRuntime = null;
    let navigationController = null;
    let visitSequence = 0;
    let completedVisits = 0;

    shell.dataset.portalNavigationReady = "true";
    shell.dataset.userNavigationReady = "true";
    shell.dataset.portalNavigationVisits = "0";
    shell.dataset.userNavigationVisits = "0";

    function normalizePath(pathname) {
        const normalized = String(pathname || "/").replace(/\/+$/, "");
        return normalized || "/";
    }

    function isSupportedUrl(url) {
        return url.origin === window.location.origin && supportedPaths.has(normalizePath(url.pathname));
    }

    function pageName(url) {
        const matchingLink = Array.from(document.querySelectorAll("[data-portal-navigation-link][href]")).find((link) => {
            const linkUrl = new URL(link.href, window.location.href);
            return normalizePath(linkUrl.pathname) === normalizePath(url.pathname);
        });
        return (matchingLink?.textContent || normalizePath(url.pathname).split("/").pop() || "page").trim();
    }

    function createPageRuntime() {
        const controller = new AbortController();
        const timeouts = new Set();
        const intervals = new Set();
        const animationFrames = new Set();
        const cleanupCallbacks = new Set();

        return {
            controller,
            cleanupCallbacks,
            fetch(input, init = {}) {
                const suppliedSignal = init?.signal;
                const signal = suppliedSignal && suppliedSignal !== controller.signal && typeof AbortSignal.any === "function"
                    ? AbortSignal.any([suppliedSignal, controller.signal])
                    : (suppliedSignal || controller.signal);
                return nativeFetch(input, { ...init, signal });
            },
            setTimeout(callback, delay, ...args) {
                const handle = nativeSetTimeout((...callbackArgs) => {
                    timeouts.delete(handle);
                    if (!controller.signal.aborted) callback(...callbackArgs);
                }, delay, ...args);
                timeouts.add(handle);
                return handle;
            },
            clearTimeout(handle) {
                timeouts.delete(handle);
                nativeClearTimeout(handle);
            },
            setInterval(callback, delay, ...args) {
                const handle = nativeSetInterval((...callbackArgs) => {
                    if (!controller.signal.aborted) callback(...callbackArgs);
                }, delay, ...args);
                intervals.add(handle);
                return handle;
            },
            clearInterval(handle) {
                intervals.delete(handle);
                nativeClearInterval(handle);
            },
            requestAnimationFrame(callback) {
                const handle = nativeRequestAnimationFrame((timestamp) => {
                    animationFrames.delete(handle);
                    if (!controller.signal.aborted) callback(timestamp);
                });
                animationFrames.add(handle);
                return handle;
            },
            cancelAnimationFrame(handle) {
                animationFrames.delete(handle);
                nativeCancelAnimationFrame(handle);
            },
            disposeTimers() {
                timeouts.forEach(nativeClearTimeout);
                intervals.forEach(nativeClearInterval);
                animationFrames.forEach(nativeCancelAnimationFrame);
                timeouts.clear();
                intervals.clear();
                animationFrames.clear();
            },
        };
    }

    const api = {
        get currentSignal() {
            return pageRuntime.controller.signal;
        },
        isCurrent(signal) {
            return signal === pageRuntime.controller.signal && !signal.aborted;
        },
        onCleanup(callback) {
            const runtime = executingRuntime || pageRuntime;
            if (typeof callback !== "function" || runtime.controller.signal.aborted) return function () {};
            runtime.cleanupCallbacks.add(callback);
            return () => runtime.cleanupCallbacks.delete(callback);
        },
    };
    window.IbemsPortalNavigation = api;
    window.IbemsUserNavigation = api;

    function announce(message) {
        if (status) status.textContent = message;
    }

    function setBusy(busy) {
        shell.classList.toggle("is-portal-navigating", busy);
        shell.classList.toggle("is-user-navigating", busy);
        main.setAttribute("aria-busy", busy ? "true" : "false");
    }

    function withPageListenerSignal(runtime, callback) {
        EventTarget.prototype.addEventListener = function (type, listener, options) {
            if (this instanceof AbortSignal || runtime.controller.signal.aborted) {
                return nativeAddEventListener.call(this, type, listener, options);
            }
            if (typeof options === "boolean") {
                return nativeAddEventListener.call(this, type, listener, { capture: options, signal: runtime.controller.signal });
            }
            return nativeAddEventListener.call(this, type, listener, { ...(options || {}), signal: options?.signal || runtime.controller.signal });
        };
        executingRuntime = runtime;
        try {
            return callback();
        } finally {
            executingRuntime = null;
            EventTarget.prototype.addEventListener = nativeAddEventListener;
        }
    }

    function updateCsrfMetadata(nextDocument) {
        ["csrf-token-name", "csrf-token-value", "csrf-header-name", "csrf-cookie-name"].forEach((name) => {
            const next = nextDocument.querySelector(`meta[name="${name}"]`);
            const current = document.querySelector(`meta[name="${name}"]`);
            if (next && current) current.content = next.content;
        });
    }

    function updatePortalContext(nextDocument) {
        const context = nextDocument.getElementById("portal-context-data");
        if (!context) return;
        try {
            window.IBEMS_PORTAL_CONTEXT = JSON.parse(context.textContent || "{}");
            const current = document.getElementById("portal-context-data");
            if (current) current.textContent = context.textContent;
        } catch (error) {
            throw new Error("The destination portal context is invalid.");
        }
    }

    function updateBodyPageClasses(nextDocument) {
        const previousClasses = (document.body.dataset.portalPageClasses || "").split(/\s+/).filter(Boolean);
        const nextClasses = (nextDocument.body?.dataset.portalPageClasses || "").split(/\s+/).filter(Boolean);
        previousClasses.forEach((className) => document.body.classList.remove(className));
        nextClasses.forEach((className) => document.body.classList.add(className));
        document.body.dataset.portalPageClasses = nextClasses.join(" ");
    }

    async function ensurePageStyles(nextDocument) {
        const nextLinks = Array.from(nextDocument.querySelectorAll("link[data-portal-page-style][href]"));
        const nextHrefs = new Set(nextLinks.map((link) => new URL(link.getAttribute("href"), window.location.href).href));
        const currentHrefs = new Set(Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'), (link) => link.href));
        const additions = nextLinks.filter((link) => !currentHrefs.has(new URL(link.getAttribute("href"), window.location.href).href));

        await Promise.all(additions.map((source) => new Promise((resolve, reject) => {
            const link = document.createElement("link");
            Array.from(source.attributes).forEach((attribute) => link.setAttribute(attribute.name, attribute.value));
            link.href = new URL(source.getAttribute("href"), window.location.href).href;
            link.onload = resolve;
            link.onerror = () => reject(new Error(`Unable to load page styles: ${link.href}`));
            document.head.appendChild(link);
        })));

        return () => {
            document.querySelectorAll("link[data-portal-page-style][href]").forEach((link) => {
                if (!nextHrefs.has(link.href)) link.remove();
            });
        };
    }

    function shouldLoadOnce(source, sourceUrl) {
        if (source.hasAttribute("data-portal-page-once") || source.hasAttribute("data-user-page-once")) return true;
        if (sourceUrl && new URL(sourceUrl, window.location.href).origin !== window.location.origin) return true;
        return /\/assets\/js\/receipt-standard\.js$/i.test(new URL(sourceUrl || window.location.href, window.location.href).pathname);
    }

    async function loadOnceScript(source, sourceUrl) {
        if (!sourceUrl || loadedOnceScripts.has(sourceUrl)) return;
        await new Promise((resolve, reject) => {
            const script = document.createElement("script");
            Array.from(source.attributes).forEach((attribute) => {
                if (!attribute.name.startsWith("data-portal-page") && !attribute.name.startsWith("data-user-page")) script.setAttribute(attribute.name, attribute.value);
            });
            script.src = sourceUrl;
            script.onload = () => {
                loadedOnceScripts.add(sourceUrl);
                resolve();
            };
            script.onerror = () => {
                script.remove();
                reject(new Error(`Unable to load shared page behavior: ${sourceUrl}`));
            };
            document.body.appendChild(script);
        });
    }

    function executeScopedScript(sourceText, sourceUrl, runtime) {
        const script = document.createElement("script");
        const nonce = document.querySelector('meta[name="csp-script-nonce"]')?.content || "";
        if (nonce) script.nonce = nonce;
        const sourceLabel = sourceUrl ? `\n//# sourceURL=${sourceUrl}` : "";
        window.__IBEMS_PAGE_RUNTIME__ = runtime;
        script.textContent = `(function (window, fetch, setTimeout, clearTimeout, setInterval, clearInterval, requestAnimationFrame, cancelAnimationFrame) {\n${sourceText}\n}).call(window, window, window.__IBEMS_PAGE_RUNTIME__.fetch, window.__IBEMS_PAGE_RUNTIME__.setTimeout, window.__IBEMS_PAGE_RUNTIME__.clearTimeout, window.__IBEMS_PAGE_RUNTIME__.setInterval, window.__IBEMS_PAGE_RUNTIME__.clearInterval, window.__IBEMS_PAGE_RUNTIME__.requestAnimationFrame, window.__IBEMS_PAGE_RUNTIME__.cancelAnimationFrame);${sourceLabel}`;
        try {
            withPageListenerSignal(runtime, () => document.body.appendChild(script));
        } finally {
            delete window.__IBEMS_PAGE_RUNTIME__;
            script.remove();
        }
    }

    async function executePageScripts(sourceDocument, runtime) {
        const template = sourceDocument.getElementById("portal-page-scripts");
        if (!template) throw new Error("The destination page does not declare scoped page behavior.");
        const scripts = Array.from(template.content.querySelectorAll("script"));
        for (const source of scripts) {
            if (runtime.controller.signal.aborted) throw new DOMException("Page navigation was aborted.", "AbortError");
            const declaredSource = source.getAttribute("src");
            const sourceUrl = declaredSource ? new URL(declaredSource, window.location.href).href : "";
            if (shouldLoadOnce(source, sourceUrl)) {
                await loadOnceScript(source, sourceUrl);
                continue;
            }

            let sourceText = source.textContent || "";
            if (sourceUrl) {
                const response = await runtime.fetch(sourceUrl, { credentials: "same-origin" });
                if (!response.ok) throw new Error(`Unable to load page behavior: ${sourceUrl}`);
                sourceText = await response.text();
            }
            executeScopedScript(sourceText, sourceUrl, runtime);
        }
    }

    async function disposePageRuntime(runtime) {
        window.dispatchEvent(new CustomEvent("ibems:portal-page-unload", { detail: { main } }));
        const callbacks = Array.from(runtime.cleanupCallbacks, (callback) => Promise.resolve().then(callback));
        if (callbacks.length) await Promise.allSettled(callbacks);

        if (window.Chart?.getChart) main.querySelectorAll("canvas").forEach((canvas) => window.Chart.getChart(canvas)?.destroy());
        main.querySelectorAll("video").forEach((video) => video.srcObject?.getTracks?.().forEach((track) => track.stop()));
        runtime.controller.abort();
        runtime.disposeTimers();
        runtime.cleanupCallbacks.clear();
    }

    function updateNavigationState(url) {
        const destination = normalizePath(url.pathname);
        document.querySelectorAll("[data-portal-navigation-link][href]").forEach((link) => {
            const active = normalizePath(new URL(link.href, window.location.href).pathname) === destination;
            link.classList.toggle("is-active", active);
            if (active) link.setAttribute("aria-current", "page");
            else link.removeAttribute("aria-current");
        });
        localStorage.setItem(`ibems.nav.last.${navigationKey.toLowerCase()}`, url.href);
    }

    async function replacePage(nextDocument, url) {
        const nextMain = nextDocument.querySelector("[data-portal-page-content]");
        const nextShell = nextDocument.querySelector("[data-portal-navigation-shell]");
        if (!nextMain || !nextShell || nextShell.dataset.portalNavigationKey !== navigationKey) throw new Error("The destination is not a compatible portal page.");

        const removeStaleStyles = await ensurePageStyles(nextDocument);
        await disposePageRuntime(pageRuntime);
        pageRuntime = createPageRuntime();

        main.className = nextMain.className;
        main.innerHTML = nextMain.innerHTML;
        document.title = nextDocument.title || document.title;
        updateBodyPageClasses(nextDocument);
        updateCsrfMetadata(nextDocument);
        updatePortalContext(nextDocument);
        updateNavigationState(url);
        await executePageScripts(nextDocument, pageRuntime);
        removeStaleStyles();
        document.dispatchEvent(new CustomEvent("ibems:portal-page-loaded", { detail: { main, url: url.href } }));
        document.dispatchEvent(new CustomEvent("ibems:user-page-loaded", { detail: { main, url: url.href } }));
    }

    async function visit(destination, options = {}) {
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
            const response = await nativeFetch(url.href, {
                credentials: "same-origin",
                headers: { "Accept": "text/html", "X-Requested-With": "IBEMS-Portal-Navigation" },
                signal: navigationController.signal,
            });
            const contentType = response.headers.get("content-type") || "";
            if (!response.ok || !contentType.toLowerCase().includes("text/html")) throw new Error(`Navigation failed with status ${response.status}.`);

            const finalUrl = new URL(response.url || url.href, window.location.href);
            if (!isSupportedUrl(finalUrl)) {
                window.location.assign(finalUrl.href);
                return;
            }

            const nextDocument = new DOMParser().parseFromString(await response.text(), "text/html");
            if (sequence !== visitSequence) return;

            if (options.history !== false) history.replaceState({ ...(history.state || {}), ibemsPortalPage: true, ibemsUserPage: true, scrollY: window.scrollY }, "", window.location.href);
            await replacePage(nextDocument, finalUrl);
            if (sequence !== visitSequence) return;

            if (options.history !== false) history.pushState({ ibemsPortalPage: true, ibemsUserPage: true, scrollY: 0 }, "", finalUrl.href);

            completedVisits += 1;
            shell.dataset.portalNavigationVisits = String(completedVisits);
            shell.dataset.userNavigationVisits = String(completedVisits);
            window.scrollTo({ top: Number(options.scrollY || 0), left: 0, behavior: "auto" });
            if (options.focus !== false) main.focus({ preventScroll: true });
            announce(`${pageName(finalUrl)} loaded.`);
        } catch (error) {
            if (error?.name === "AbortError") return;
            window.location.assign(url.href);
        } finally {
            if (sequence === visitSequence) setBusy(false);
        }
    }

    document.addEventListener("click", (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest("a[href]");
        if (!link || link.target || link.hasAttribute("download") || link.dataset.noPortalNavigation !== undefined || link.dataset.noUserNavigation !== undefined) return;

        const url = new URL(link.href, window.location.href);
        if (!isSupportedUrl(url) || (normalizePath(url.pathname) === normalizePath(window.location.pathname) && url.search === window.location.search)) return;
        event.preventDefault();
        visit(url);
    });

    window.addEventListener("popstate", (event) => {
        const url = new URL(window.location.href);
        if (!isSupportedUrl(url)) {
            window.location.assign(url.href);
            return;
        }
        visit(url, { history: false, focus: false, scrollY: event.state?.scrollY || 0 });
    });

    window.addEventListener("beforeunload", () => {
        navigationController?.abort();
        pageRuntime.controller.abort();
        pageRuntime.disposeTimers();
    });

    history.replaceState({ ...(history.state || {}), ibemsPortalPage: true, ibemsUserPage: true, scrollY: window.scrollY }, "", window.location.href);
    const initializeRuntime = pageRuntime;
    window.addEventListener("DOMContentLoaded", async () => {
        if (initializeRuntime !== pageRuntime || initializeRuntime.controller.signal.aborted) return;
        try {
            await executePageScripts(document, initializeRuntime);
            document.dispatchEvent(new CustomEvent("ibems:portal-page-loaded", { detail: { main, url: window.location.href, initial: true } }));
        } catch (error) {
            if (error?.name !== "AbortError") window.location.reload();
        }
    }, { once: true });
}());
