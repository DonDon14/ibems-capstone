(function () {
    function toNumber(value) {
        const number = Number(value || 0);
        return Number.isFinite(number) ? number : 0;
    }

    function money(value, options = {}) {
        const decimals = Number.isInteger(options.decimals) ? options.decimals : 2;
        return `PHP ${toNumber(value).toLocaleString("en-PH", {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        })}`;
    }

    function normalizeDateValue(value) {
        if (!value) return new Date();
        if (value instanceof Date) return value;
        let text = String(value).trim().replace(" ", "T");
        // Database timestamps are UTC; an unzoned SQL/ISO timestamp must not be
        // interpreted in the viewer's browser timezone.
        if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(text)) text += "Z";
        return new Date(text);
    }

    function dateTime(value, options = {}) {
        const date = normalizeDateValue(value);
        if (Number.isNaN(date.getTime())) return String(value || "-");
        return date.toLocaleString("en-PH", { timeZone: "Asia/Manila", ...options });
    }

    function shortDateTime(value) {
        return dateTime(value, {
            month: "short",
            day: "numeric",
            hour: "numeric",
            minute: "2-digit",
        });
    }

    function date(value, options = {}) {
        const parsed = normalizeDateValue(value);
        if (Number.isNaN(parsed.getTime())) return String(value || "-");
        return parsed.toLocaleDateString("en-PH", { timeZone: "Asia/Manila", ...options });
    }

    function identifierLabel(value) {
        return String(value ?? "")
            .trim()
            .replace(/[_-]+/g, " ")
            .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
            .replace(/\s+/g, " ")
            .toLowerCase()
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    window.IbemsFormat = {
        money,
        dateTime,
        shortDateTime,
        date,
        identifierLabel,
    };
}());
