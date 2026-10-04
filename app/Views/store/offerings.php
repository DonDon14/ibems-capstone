<?= $this->extend('layouts/store') ?>
<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/store-offerings.css') ?>?v=20261003-feedback-wrap">
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<?php
$offeringSection = (string) service('request')->getGet('section');
$offeringHeading = $offeringSection === 'rental' ? 'Rentals' : ($offeringSection === 'food' ? 'Daily food' : 'Store operations');
$offeringDescription = $offeringSection === 'rental'
    ? 'Manage equipment packages, reservations, returns, and deposits for this store.'
    : ($offeringSection === 'food'
        ? 'Manage reusable dish templates and today’s prepared servings for this store.'
        : 'Manage food offerings and equipment rentals for this store.');
?>
<section id="offering-app" class="offering-shell" data-role="<?= esc((string) session()->get('role')) ?>">
    <?= view('components/page_header', [
        'eyebrow' => 'Store operations',
        'title' => $offeringHeading,
        'description' => $offeringDescription,
        'icon' => $offeringSection === 'rental' ? 'bi bi-box-seam' : 'bi bi-shop',
    ]) ?>
    <label for="offering-store">Assigned store</label><select id="offering-store" disabled></select>
    <p id="offering-date"></p>
    <p id="offering-result" role="status" aria-live="polite">Loading…</p>
    <section id="food-section" hidden>
        <h2>Today’s food</h2>
        <p>Open today’s store day in POS. Add a dish below and publish it with the servings prepared today. A saved template is reusable; only food published for today can be sold. Nothing carries over automatically tomorrow.</p>
        <a id="food-pos-link" class="secondary-btn" href="/store/pos">Open POS and store day</a>
        <form id="food-template-form" class="offering-panel">
            <h3>Add a reusable food template</h3>
            <label>Dish name <input name="name" maxlength="150" required></label>
            <label>Category <input name="category" maxlength="100" value="Daily food" required></label>
            <label>Description <textarea name="description" maxlength="2000"></textarea></label>
            <label>Allergens (leave unknown rather than assuming allergen-free) <input name="allergens" maxlength="500"></label>
            <label>Dietary information <input name="dietary" maxlength="500"></label>
            <label>Dish photo (JPG, PNG, WebP or GIF; up to 2 MB) <input name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
            <input name="image_url" type="hidden">
            <label>Quantity tracking <select name="tracking"><option value="independent">Separate prepared quantities per variant</option><option value="shared">Shared batch of base portions</option></select></label>
            <p>Shared example: Regular uses 1 base portion; Large uses 2. Add-ons and special requests are not size variants.</p>
            <div data-variant-rows></div>
            <button class="secondary-btn" type="button" data-add-food-variant>Add variant</button>
            <label><input name="publish_today" type="checkbox" checked> Publish to today’s POS after saving</label>
            <label>Prepared servings per variant today or base portions for a shared batch <input name="today_capacity" type="number" min="1" max="100000" step="1" value="10" required></label>
            <p>Leave publishing unchecked to save a template for another day. Publishing requires an open store day.</p>
            <button class="primary-btn" type="submit">Save dish</button>
        </form>
        <div id="food-templates" class="offering-grid"></div>
    </section>
    <section id="rental-section" hidden>
        <h2>Equipment rentals</h2>
        <p>Components share one equipment pool. Example: create Cap and Gown equipment, then Cap only, Gown only, and Full set packages. Add separate equipment pools for different sizes. Rental fees are fixed per package for the agreed booking period, not automatically multiplied by days.</p>
        <details id="rental-configuration" class="offering-panel">
            <summary>Supervisor: equipment and package templates</summary>
            <form id="rental-asset-form">
                <h3>Add equipment pool</h3>
                <label>Equipment / size <input name="name" maxlength="150" required placeholder="Gown — Medium"></label>
                <label>Total reusable quantity <input name="quantity" type="number" min="1" max="100000" step="1" required></label>
                <button class="primary-btn" type="submit">Add equipment</button>
            </form>
            <div id="rental-assets"></div>
            <form id="rental-package-form">
                <h3>Add rental package</h3>
                <label>Package name <input name="name" maxlength="150" required placeholder="Full set — Medium"></label>
                <div id="rental-components"></div>
                <label>Fee for one package / agreed period <input name="fee" type="number" min="0" step="0.01" required></label>
                <label>Refundable cash deposit per package <input name="deposit" type="number" min="0" step="0.01" value="0" required></label>
                <button class="primary-btn" type="submit">Save package</button>
            </form>
        </details>
        <form id="rental-book-form" class="offering-panel">
            <h3>Reserve equipment</h3>
            <label>Package <select name="package_id" id="rental-package-select" required></select></label>
            <label>Number of packages <input name="quantity" type="number" min="1" max="100000" value="1" required></label>
            <label>Borrower name <input name="borrower" maxlength="150" required></label>
            <label>Contact / university reference <input name="contact" maxlength="150" required></label>
            <label>Pickup date <input name="starts_on" type="date" aria-label="Pickup date" required></label>
            <label>Due date (inclusive) <input name="due_on" type="date" aria-label="Due date (inclusive)" required></label>
            <p>Reserve first, collect the fee, then issue equipment and collect the separate cash deposit. Reservations include the entire due date. Outstanding equipment remains unavailable until returned. Only unpaid reservations can be cancelled here.</p>
            <button class="primary-btn" type="submit">Check availability & reserve</button>
        </form>
        <h3>Bookings and returns</h3>
        <p>Outstanding bookings and deposits. Completed records remain in the audit trail and fee receipts. Only mark equipment returned when physically received in reusable condition. Keep damaged or missing pieces outstanding for supervisor review. Deposits are separate cash custody, not sales revenue.</p>
        <div id="rental-bookings" class="offering-grid"></div>
    </section>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/food-template-ui.js') ?>?v=20261002-shared-buttons"></script>
<script src="<?= base_url('assets/js/store-offerings.js') ?>?v=20261003-error-focus"></script>
<?= $this->endSection() ?>
