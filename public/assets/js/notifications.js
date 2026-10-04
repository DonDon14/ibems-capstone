(function () {
    "use strict";
    const toggle = document.getElementById("notification-toggle");
    const panel = document.getElementById("notification-panel");
    const badge = document.getElementById("notification-badge");
    const list = document.getElementById("notification-list");
    const summary = document.getElementById("notification-summary");
    const readAll = document.getElementById("notification-read-all");
    if (!toggle || !panel || !badge || !list) return;

    const escapeHtml = (value) => String(value || "").replace(/[&<>'"]/g, (char) => ({"&":"&amp;","<":"&lt;",">":"&gt;","'":"&#39;",'"':"&quot;"}[char]));
    const relativeTime = (value) => {
        const raw = String(value || "").trim().replace(" ", "T");
        const normalized = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw) ? raw : `${raw}Z`;
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return "";
        const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (seconds < 60) return "Just now";
        if (seconds < 3600) return Math.floor(seconds / 60) + "m ago";
        if (seconds < 86400) return Math.floor(seconds / 3600) + "h ago";
        if (seconds < 604800) return Math.floor(seconds / 86400) + "d ago";
        return date.toLocaleDateString();
    };
    const setCount = (count) => {
        const total = Math.max(0, Number(count) || 0);
        badge.textContent = total > 99 ? "99+" : String(total);
        badge.hidden = total === 0;
        toggle.setAttribute("aria-label", total ? `Notifications, ${total} unread` : "Notifications");
        if (summary) summary.textContent = total ? `${total} unread` : "Up to date";
    };
    const normalizeIconClass = (icon) => {
        const value = String(icon || "").trim();

        if (!/^bi bi-[a-z0-9-]+$/.test(value) || value === "bi bi-bell-check") {
            return "bi bi-bell";
        }

        return value;
    };
    const render = (rows) => {
        if (!Array.isArray(rows) || rows.length === 0) {
            list.innerHTML = '<div class="notification-empty"><i class="bi bi-bell" aria-hidden="true"></i><span>No notifications yet</span></div>';
            return;
        }
        list.innerHTML = rows.map((row) => {
            const unread = !row.read_at;
            return `<a class="notification-item${unread ? " is-unread" : ""}" href="${escapeHtml(row.link_url || "#")}" data-notification-id="${Number(row.id)}">
                <span class="notification-item-icon notification-${escapeHtml(row.severity || "info")}" aria-hidden="true"><i class="${escapeHtml(normalizeIconClass(row.icon))}"></i></span>
                <span class="notification-item-copy"><strong>${escapeHtml(row.title)}</strong><span>${escapeHtml(row.message)}</span><time>${escapeHtml(relativeTime(row.created_at))}</time></span>
                ${unread ? '<span class="notification-unread-dot" aria-label="Unread"></span>' : ""}
            </a>`;
        }).join("");
    };
    let loadRequestId = 0;
    let markingAll = false;
    const renderError = (message) => {
        list.innerHTML = `<div class="notification-empty is-error" role="alert" aria-atomic="true"><i class="bi bi-wifi-off" aria-hidden="true"></i><span>${escapeHtml(message)}</span><button type="button" class="secondary-btn notification-retry" data-notification-retry>Retry notifications</button></div>`;
    };
    const load = async () => {
        if (markingAll) return;
        const requestId = ++loadRequestId;
        list.setAttribute("aria-busy", "true");
        list.innerHTML = '<div class="notification-empty" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>Loading notifications…</span></div>';
        if (summary) summary.textContent = "Loading…";
        if (readAll) readAll.disabled = true;
        try {
            const response = await fetch(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications?limit=12`, {headers:{Accept:"application/json"}});
            if (!response.ok) throw new Error("Unable to load notifications.");
            const data = await response.json();
            if (requestId !== loadRequestId) return;
            const count = data?.unread_count;
            if (!Array.isArray(data?.notifications) || !["number", "string"].includes(typeof count) || count === "" || !Number.isInteger(Number(count)) || Number(count) < 0) throw new Error("Unable to load notifications.");
            setCount(data.unread_count);
            render(data.notifications);
            if (readAll) readAll.disabled = markingAll || Number(data.unread_count) <= 0;
        } catch (error) {
            if (requestId !== loadRequestId) return;
            if (summary) summary.textContent = "Unavailable";
            renderError("Notifications are temporarily unavailable. Retry to load the latest notices.");
        } finally {
            if (requestId === loadRequestId) list.setAttribute("aria-busy", "false");
        }
    };
    const post = async (url) => fetch(url, {method:"POST", headers:{Accept:"application/json"}});

    toggle.addEventListener("click", () => {
        const opening = panel.hidden;
        panel.hidden = !opening;
        toggle.setAttribute("aria-expanded", opening ? "true" : "false");
        if (opening) load();
    });
    list.addEventListener("click", async (event) => {
        if (event.target.closest("[data-notification-retry]")) {
            event.preventDefault();
            event.stopPropagation();
            if (list.getAttribute("aria-busy") === "true" || markingAll) return;
            list.focus();
            await load();
            return;
        }
        const item = event.target.closest("[data-notification-id]");
        if (!item) return;
        event.preventDefault();
        const href = item.getAttribute("href");
        try { await post(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications/${item.dataset.notificationId}/read`); } catch (_) {}
        if (href && href !== "#") window.location.assign(href); else load();
    });
    if (readAll) readAll.addEventListener("click", async () => {
        if (markingAll) return;
        markingAll = true;
        if (!panel.hidden && panel.contains(document.activeElement)) list.focus();
        readAll.disabled = true;
        list.setAttribute("aria-busy", "true");
        list.innerHTML = '<div class="notification-empty" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>Marking notifications as read…</span></div>';
        try {
            const response = await post(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications/read-all`);
            const data = await response.json();
            if (!response.ok || data.success !== true) throw new Error("Unable to mark notifications as read.");
            markingAll = false;
            await load();
        } catch (_) {
            ++loadRequestId;
            if (summary) summary.textContent = "Unable to mark all read";
            renderError("Notifications could not be marked as read. Retry to refresh the latest notices before trying again.");
        } finally {
            markingAll = false;
            list.setAttribute("aria-busy", "false");
        }
    });
    document.addEventListener("click", (event) => {
        if (!panel.hidden && !event.target.closest(".notification-center")) {
            panel.hidden = true;
            toggle.setAttribute("aria-expanded", "false");
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !panel.hidden) { panel.hidden = true; toggle.setAttribute("aria-expanded", "false"); toggle.focus(); }
    });
    document.addEventListener("focusin", (event) => {
        if (!panel.hidden && !event.target.closest(".notification-center")) {
            panel.hidden = true;
            toggle.setAttribute("aria-expanded", "false");
        }
    });
    const refreshWhenIdle = () => {
        if (markingAll) return;
        if (!panel.hidden && panel.contains(document.activeElement)) return;
        load();
    };
    window.addEventListener("focus", refreshWhenIdle);
    load();
    window.setInterval(refreshWhenIdle, 60000);
}());
