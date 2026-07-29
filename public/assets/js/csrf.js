(function () {
    function readCookie(name) {
        const encodedName = encodeURIComponent(name) + "=";
        const parts = document.cookie.split(";");
        for (let i = 0; i < parts.length; i += 1) {
            const part = parts[i].trim();
            if (part.indexOf(encodedName) === 0) {
                return decodeURIComponent(part.substring(encodedName.length));
            }
        }
        return "";
    }

    const headerMeta = document.querySelector('meta[name="csrf-header-name"]');
    const cookieMeta = document.querySelector('meta[name="csrf-cookie-name"]');
    const tokenValueMeta = document.querySelector('meta[name="csrf-token-value"]');
    const csrfHeaderName = headerMeta ? headerMeta.getAttribute("content") || "X-CSRF-TOKEN" : "X-CSRF-TOKEN";
    const csrfCookieName = cookieMeta ? cookieMeta.getAttribute("content") || "csrf_cookie_name" : "csrf_cookie_name";
    const csrfMetaToken = tokenValueMeta ? tokenValueMeta.getAttribute("content") || "" : "";

    const nativeFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        const requestUrl = typeof input === "string" ? input : (input && input.url) || "";
        const url = new URL(requestUrl, window.location.origin);
        if (url.origin !== window.location.origin) {
            return nativeFetch(input, init);
        }

        const methodFromInput = input instanceof Request ? input.method : "";
        const method = String((init && init.method) || methodFromInput || "GET").toUpperCase();
        if (method === "GET" || method === "HEAD" || method === "OPTIONS") {
            return nativeFetch(input, init);
        }

        const sourceHeaders = (init && init.headers) || (input instanceof Request ? input.headers : {});
        const headers = new Headers(sourceHeaders);
        if (!headers.has(csrfHeaderName)) {
            const csrfToken = readCookie(csrfCookieName) || csrfMetaToken;
            if (csrfToken !== "") {
                headers.set(csrfHeaderName, csrfToken);
            }
        }

        const nextInit = Object.assign({}, init || {}, { headers });
        return nativeFetch(input, nextInit);
    };
})();
