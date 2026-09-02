(function () {
"use strict";
const page = document.getElementById("user-purchase-card-page");
if (!page) return;
const signal = window.IbemsUserNavigation?.currentSignal || new AbortController().signal;
let card = null;
let timer = null;
const result = document.getElementById("purchase-card-result");

function setResult(message = "", error = false) { result.textContent = message; result.classList.toggle("is-error", error); }
function secondsLeft() { return Math.max(0, Number(card?.unlocked_until_epoch || 0) - Math.floor(Date.now() / 1000)); }
function render() {
    const locked = card?.is_locked !== false || secondsLeft() <= 0;
    const visual = document.getElementById("purchase-card-visual");
    visual.classList.toggle("is-locked", locked);
    document.getElementById("purchase-card-state-icon").className = `bi ${locked ? "bi-lock-fill" : "bi-unlock-fill"}`;
    document.getElementById("purchase-card-state-label").textContent = locked ? "Locked" : "Ready for One Purchase";
    document.getElementById("purchase-card-control-title").textContent = locked ? "Your card is locked" : "Your card is ready";
    document.getElementById("purchase-card-control-copy").textContent = locked
        ? "Your employee ID may be scanned, but a debt transaction cannot proceed while this card is locked."
        : "Give your employee ID to the Store Cashier now. This unlock will be consumed by the next successful debt purchase.";
    document.getElementById("purchase-card-unlock").hidden = !locked;
    document.getElementById("purchase-card-lock").hidden = locked;
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
        const data = await api("/user/purchase-card/status"); card = data.card;
        document.getElementById("purchase-card-name").textContent = data.employee?.name || "Employee";
        document.getElementById("purchase-card-employee-id").textContent = data.employee?.employee_id || "Employee ID not assigned";
        if (data.employee?.profile_image_url) document.getElementById("purchase-card-avatar").src = data.employee.profile_image_url;
        render(); startTimer();
    } catch (error) { if (!signal.aborted) setResult(error.message, true); }
}
async function update(url, busyText) {
    const buttons = Array.from(document.querySelectorAll("#purchase-card-unlock, #purchase-card-lock")); buttons.forEach((button) => { button.disabled = true; }); setResult(busyText);
    try { const data = await api(url, { method: "POST" }); card = data.card; render(); startTimer(); setResult(card.is_locked ? "Purchase card locked." : "Purchase card unlocked for one purchase."); }
    catch (error) { if (!signal.aborted) setResult(error.message, true); }
    finally { buttons.forEach((button) => { button.disabled = false; }); }
}
document.getElementById("purchase-card-unlock").addEventListener("click", () => update("/user/purchase-card/unlock", "Unlocking purchase card..."));
document.getElementById("purchase-card-lock").addEventListener("click", () => update("/user/purchase-card/lock", "Locking purchase card..."));
window.IbemsPortalNavigation?.onCleanup(() => { if (timer) clearInterval(timer); });
load();
}());
