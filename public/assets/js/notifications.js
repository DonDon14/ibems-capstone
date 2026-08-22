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
            list.innerHTML = '<div class="notification-empty"><i class="bi bi-bell"></i><span>No notifications yet</span></div>';
            return;
        }
        list.innerHTML = rows.map((row) => {
            const unread = !row.read_at;
            return `<a class="notification-item${unread ? " is-unread" : ""}" href="${escapeHtml(row.link_url || "#")}" data-notification-id="${Number(row.id)}">
                <span class="notification-item-icon notification-${escapeHtml(row.severity || "info")}"><i class="${escapeHtml(normalizeIconClass(row.icon))}"></i></span>
                <span class="notification-item-copy"><strong>${escapeHtml(row.title)}</strong><span>${escapeHtml(row.message)}</span><time>${escapeHtml(relativeTime(row.created_at))}</time></span>
                ${unread ? '<span class="notification-unread-dot" aria-label="Unread"></span>' : ""}
            </a>`;
        }).join("");
    };
    const load = async () => {
        try {
            const response = await fetch(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications?limit=12`, {headers:{Accept:"application/json"}});
            if (!response.ok) throw new Error("Unable to load notifications.");
            const data = await response.json();
            setCount(data.unread_count);
            render(data.notifications);
        } catch (error) {
            list.innerHTML = '<div class="notification-empty is-error"><i class="bi bi-wifi-off"></i><span>Notifications are temporarily unavailable.</span></div>';
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
        const item = event.target.closest("[data-notification-id]");
        if (!item) return;
        event.preventDefault();
        const href = item.getAttribute("href");
        try { await post(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications/${item.dataset.notificationId}/read`); } catch (_) {}
        if (href && href !== "#") window.location.assign(href); else load();
    });
    if (readAll) readAll.addEventListener("click", async () => {
        await post(`${window.location.origin}${window.location.pathname.includes('/index.php/') ? '/index.php' : ''}/notifications/read-all`);
        load();
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
    window.addEventListener("focus", load);
    load();
    window.setInterval(load, 60000);
}());
