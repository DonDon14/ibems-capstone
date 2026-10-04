(function () {
"use strict";
const page = document.getElementById("user-purchase-card-page");
if (!page) return;
const signal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
let card = null;
let timer = null;
const result = document.getElementById("purchase-card-result");

function setResult(message = "", error = false) { result.textContent = message; result.classList.toggle("is-error", error); result.setAttribute("role", error ? "alert" : "status"); result.setAttribute("aria-live", error ? "assertive" : "polite"); }
function secondsLeft() { return Math.max(0, Number(card?.unlocked_until_epoch || 0) - Math.floor(Date.now() / 1000)); }
function render() {
    const locked = card?.is_locked !== false || secondsLeft() <= 0;
    const visual = document.getElementById("purchase-card-visual");
    visual.classList.toggle("is-locked", locked);
    document.getElementById("purchase-card-state-icon").className = `bi ${locked ? "bi-lock-fill" : "bi-unlock-fill"}`;
    const stateLabel = document.getElementById("purchase-card-state-label");
    const stateText = locked ? "Locked" : "Ready for One Purchase";
    if (stateLabel.textContent !== stateText) stateLabel.textContent = stateText;
    document.getElementById("purchase-card-control-title").textContent = locked ? "Your card is locked" : "Your card is ready";
    document.getElementById("purchase-card-control-copy").textContent = locked
        ? "Your account ID may be scanned, but a debt transaction cannot proceed while this card is locked."
        : "Give your account ID to the Store Cashier now. This unlock will be consumed by the next successful debt purchase.";
    const unlock = document.getElementById("purchase-card-unlock");
    const lock = document.getElementById("purchase-card-lock");
    const focusedAction = document.activeElement === unlock || document.activeElement === lock;
    unlock.hidden = !locked;
    lock.hidden = locked;
    const visibleAction = locked ? unlock : lock;
    if (focusedAction && !visibleAction.disabled) visibleAction.focus();
    const countdown = document.getElementById("purchase-card-countdown");
    countdown.hidden = locked;
    if (!locked) {
        const seconds = secondsLeft();
        document.getElementById("purchase-card-time-left").textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")}`;
    }
}
function startTimer() { if (timer) clearInterval(timer); timer = setInterval(() => { render(); if (secondsLeft() <= 0) { card.is_locked = true; card.unlocked_until_epoch = null; clearInterval(timer); } }, 1000); }
async function api(url, options = {}) { const response = await fetch(url, { ...options, signal: signal }); const data = await response.json(); if (!response.ok || data.status !== "success") throw new Error(data.message || "Unable to update the purchase card."); return data; }
async function load() {
    try {
        const data = await api("/user/purchase-card/status");
        if (signal.aborted) return;
        card = data.card;
        document.getElementById("purchase-card-name").textContent = data.employee?.name || "User";
        document.getElementById("purchase-card-employee-id").textContent = data.employee?.employee_id || "Account ID not assigned";
        if (data.employee?.profile_image_url) document.getElementById("purchase-card-avatar").src = data.employee.profile_image_url;
        render(); startTimer();
        document.querySelectorAll("#purchase-card-unlock, #purchase-card-lock").forEach((button) => { button.disabled = false; });
    } catch (error) {
        if (signal.aborted) return;
        document.getElementById("purchase-card-state-label").textContent = "Status unavailable";
        document.getElementById("purchase-card-employee-id").textContent = "Account ID unavailable";
        document.getElementById("purchase-card-control-title").textContent = "Unable to check your card";
        document.getElementById("purchase-card-control-copy").textContent = "Refresh the page to load your current card status before changing it.";
        setResult(error.message, true);
    }
}
async function update(url, busyText) {
    const focusedAction = document.activeElement;
    const buttons = Array.from(document.querySelectorAll("#purchase-card-unlock, #purchase-card-lock")); buttons.forEach((button) => { button.disabled = true; }); setResult(busyText);
    try { const data = await api(url, { method: "POST" }); if (signal.aborted) return; card = data.card; render(); startTimer(); setResult(card.is_locked ? "Purchase card locked." : "Purchase card unlocked for one purchase."); }
    catch (error) { if (!signal.aborted) setResult(error.message, true); }
    finally {
        if (!signal.aborted) {
            buttons.forEach((button) => { button.disabled = false; });
            if (buttons.includes(focusedAction) && (document.activeElement === focusedAction || document.activeElement === document.body)) {
                buttons.find((button) => !button.hidden)?.focus();
            }
        }
    }
}
document.getElementById("purchase-card-unlock").addEventListener("click", () => update("/user/purchase-card/unlock", "Unlocking purchase card..."));
document.getElementById("purchase-card-lock").addEventListener("click", () => update("/user/purchase-card/lock", "Locking purchase card..."));
window.IbemsPortalNavigation?.onCleanup(() => { if (timer) clearInterval(timer); });
signal.addEventListener("abort", () => { if (timer) clearInterval(timer); }, { once: true });
load();
}());
