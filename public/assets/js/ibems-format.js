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
        return new Date(String(value).replace(" ", "T"));
    }

    function dateTime(value, options = {}) {
        const date = normalizeDateValue(value);
        if (Number.isNaN(date.getTime())) return String(value || "-");
        return date.toLocaleString("en-PH", options);
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
        return parsed.toLocaleDateString("en-PH", options);
    }

    window.IbemsFormat = {
        money,
        dateTime,
        shortDateTime,
        date,
    };
}());
