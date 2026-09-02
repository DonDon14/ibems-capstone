<?php
/**
 * Shared authenticated portal shell.
 *
 * Expected variables:
 * - $pageTitle, $portalTitle, $portalSubtitle, $footerText
 * - $name, $role, $initials, $availableRoles, $navigation
 * Optional:
 * - $profileImageUrl, $profileDetail, $userMobileExperience
 */
$pageTitle = (string) ($pageTitle ?? $portalTitle ?? 'IBEMS');
$portalTitle = (string) ($portalTitle ?? 'IBEMS Portal');
$portalSubtitle = (string) ($portalSubtitle ?? '');
$footerText = (string) ($footerText ?? 'USTP IBEMS');
$name = (string) ($name ?? 'User');
$role = (string) ($role ?? '');
$initials = (string) ($initials ?? 'IB');
$availableRoles = is_array($availableRoles ?? null) ? $availableRoles : [];
$portalSwitches = is_array($portalSwitches ?? null) ? $portalSwitches : [];
$portalNavigationKey = trim((string) ($portalNavigationKey ?? strtoupper($role))) ?: strtoupper($role);
$navigation = is_array($navigation ?? null) ? $navigation : [];
$visibleNavigation = array_values(array_filter(
    $navigation,
    static fn (array $item): bool => ($item['visible'] ?? true) === true
));
$portalHomeItem = null;
foreach ($visibleNavigation as $navigationItem) {
    if (strcasecmp(trim((string) ($navigationItem['label'] ?? '')), 'Dashboard') === 0) {
        $portalHomeItem = $navigationItem;
        break;
    }
}
$portalHomeItem ??= $visibleNavigation[0] ?? [];
$portalHomePath = (string) ($portalHomeItem['path'] ?? '');
$portalHomeLabel = trim((string) ($portalHomeItem['label'] ?? 'Dashboard')) ?: 'Dashboard';
$profileImageUrl = trim((string) ($profileImageUrl ?? ''));
$resolvedProfileImageUrl = ibems_profile_image_url($profileImageUrl);
$bodyClass = trim((string) ($bodyClass ?? ''));
$bodyClasses = trim('ibems-modern ' . $bodyClass);
$roleLabels = [
    'ADMIN' => 'Administrator',
    'ACCOUNTING_OFFICE' => 'Accounting Office',
    'STORE_SUPERVISOR' => 'Store Supervisor',
    'STORE_SYSTEM' => 'Store Cashier',
    'USER' => 'Employee',
];
$readableRole = $roleLabels[strtoupper($role)] ?? ucwords(strtolower(str_replace('_', ' ', $role)));
$profileDetail = trim((string) ($profileDetail ?? $readableRole));
$portalContext = is_array($portalContext ?? null) ? $portalContext : [];
$userMobileExperience = ($userMobileExperience ?? false) === true && strtoupper($role) === 'USER';
$notificationsEnabled = in_array(strtoupper($role), ['ADMIN', 'USER'], true);
$pageStyles = (string) $this->renderSection('styles');
$pageStyles = (string) preg_replace('/<link\b(?![^>]*\bdata-portal-page-style\b)/i', '<link data-portal-page-style', $pageStyles);
$pageScripts = (string) $this->renderSection('scripts');
$pageScripts = (string) preg_replace('/<script\b(?![^>]*\bdata-portal-page-script\b)/i', '<script {csp-script-nonce} data-portal-page-script', $pageScripts);
if ($role !== '' && str_starts_with($profileDetail, $role)) {
    $profileDetail = $readableRole . substr($profileDetail, strlen($role));
}
$ibemsLogoUrl = base_url('assets/images/ibems-logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <meta name="csp-script-nonce" content="<?= esc(service('csp')->getScriptNonce()) ?>">
    <meta name="default-profile-image" content="<?= esc(base_url('assets/images/default-profile.svg')) ?>">
    <?php if ($userMobileExperience): ?>
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="IBEMS User">
        <link rel="manifest" href="<?= base_url('user/manifest.webmanifest') ?>">
        <link rel="apple-touch-icon" href="<?= base_url('assets/images/ibems-user-icon-192.png') ?>">
    <?php endif; ?>
    <title><?= esc($pageTitle) ?></title>
    <script {csp-script-nonce}>
    (function () {
        var saved = localStorage.getItem('ibems-theme');
        var theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = theme;
    }());
    </script>
    <link rel="icon" type="image/png" href="<?= esc($ibemsLogoUrl) ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=20260824e">
    <link rel="stylesheet" href="<?= base_url('assets/css/modern-ui.css') ?>?v=20260825e">
    <link rel="stylesheet" href="<?= base_url('assets/css/account-menu.css') ?>?v=20260902a">
    <link rel="stylesheet" href="<?= base_url('assets/css/dashboard-period.css') ?>?v=20260902a">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20260813b">
    <?php if ($userMobileExperience): ?>
        <link rel="stylesheet" href="<?= base_url('assets/css/user-mobile.css') ?>?v=20260823d">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $pageStyles ?>
</head>
<body class="<?= esc($bodyClasses) ?>" data-portal-page-classes="<?= esc($bodyClass) ?>">
<div class="app-shell" data-portal-navigation-shell data-portal-navigation-key="<?= esc($portalNavigationKey) ?>"<?= $userMobileExperience ? ' data-user-navigation-shell' : '' ?>>
    <div class="portal-navigation-progress user-navigation-progress" aria-hidden="true"></div>
    <div id="user-navigation-status" class="sr-only" role="status" aria-live="polite" data-portal-navigation-status></div>

    <aside class="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-brand">
                <div class="app-brand-mark">
                    <img src="<?= esc($ibemsLogoUrl) ?>" alt="IBEMS logo" class="app-brand-logo">
                    <button id="sidebar-toggle" type="button" class="secondary-btn sidebar-toggle sidebar-toggle-in-sidebar" aria-label="Toggle Sidebar">
                        <i class="sidebar-toggle-glyph" aria-hidden="true"></i>
                    </button>
                </div>
                <a class="app-brand-text" href="<?= esc(site_url($portalHomePath)) ?>" data-portal-navigation-link aria-label="Open <?= esc($portalHomeLabel) ?>">
                    <h1>IBEMS</h1>
                    <p><?= esc($portalTitle) ?></p>
                </a>
            </div>
        </div>

        <nav class="app-menu" aria-label="<?= esc($portalTitle) ?> navigation">
            <?php foreach ($navigation as $item): ?>
                <?php
                if (($item['visible'] ?? true) !== true) {
                    continue;
                }
                $path = (string) ($item['path'] ?? '');
                $label = (string) ($item['label'] ?? '');
                $icon = (string) ($item['icon'] ?? 'bi bi-circle');
                $activePath = (string) ($item['activePath'] ?? $path);
                $activeClass = ibems_is_active_path($activePath);
                ?>
                <a href="<?= site_url($path) ?>" class="<?= esc($activeClass) ?>" data-portal-navigation-link <?= $activeClass !== '' ? 'aria-current="page"' : '' ?>>
                    <i class="<?= esc($icon) ?>" aria-hidden="true"></i>
                    <span><?= esc($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="app-sidebar-spacer"></div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <div class="topbar-left">
                <div class="topbar-title">
                    <h2><?= esc($portalTitle) ?></h2>
                    <?php if ($portalSubtitle !== ''): ?>
                        <p><?= esc($portalSubtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="topbar-actions">
                <button id="theme-toggle" type="button" class="topbar-icon-button account-menu-theme-proxy" aria-label="Use dark mode" title="Use dark mode" aria-pressed="false" tabindex="-1">
                    <i class="bi bi-moon-stars" aria-hidden="true"></i>
                </button>

                <?php if ($notificationsEnabled): ?>
                <div class="notification-center">
                    <button id="notification-toggle" type="button" class="topbar-icon-button" aria-label="Notifications" title="Notifications" aria-expanded="false" aria-controls="notification-panel">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <span id="notification-badge" class="notification-badge" hidden>0</span>
                    </button>
                    <section id="notification-panel" class="notification-panel" aria-label="Notifications" hidden>
                        <div class="notification-panel-head">
                            <div><strong>Notifications</strong><span id="notification-summary">Up to date</span></div>
                            <button id="notification-read-all" type="button">Mark all read</button>
                        </div>
                        <div id="notification-list" class="notification-list" aria-live="polite">
                            <div class="notification-empty"><i class="bi bi-bell"></i><span>Loading notifications...</span></div>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <button id="account-menu-toggle" class="topbar-profile" type="button" data-account-menu-toggle aria-label="Open account and settings" title="Account and settings" aria-expanded="false" aria-controls="account-menu">
                    <span class="profile-avatar-wrap">
                        <img src="<?= esc($resolvedProfileImageUrl) ?>" alt="" class="profile-avatar-img" data-profile-avatar data-current-user-avatar>
                        <span class="profile-avatar-status" aria-hidden="true"><i class="bi bi-chevron-down"></i></span>
                    </span>
                    <div class="profile-meta">
                        <div class="profile-name"><?= esc($name) ?></div>
                        <div class="profile-role"><?= esc($profileDetail) ?></div>
                    </div>
                </button>
            </div>
        </header>

        <div id="account-menu" class="account-menu" hidden>
            <button type="button" class="account-menu-backdrop" data-account-menu-close aria-label="Close account menu"></button>
            <section class="account-menu-panel" role="dialog" aria-labelledby="account-menu-title">
                <div class="account-menu-head">
                    <div class="account-menu-identity">
                        <img src="<?= esc($resolvedProfileImageUrl) ?>" alt="" data-profile-avatar data-current-user-avatar>
                        <div>
                            <span>Signed in as</span>
                            <strong id="account-menu-title"><?= esc($name) ?></strong>
                            <small><?= esc($profileDetail) ?></small>
                        </div>
                    </div>
                    <button type="button" class="account-menu-close" data-account-menu-close aria-label="Close account menu"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="account-menu-actions">
                    <?php if ($userMobileExperience): ?>
                        <button id="user-mobile-install" type="button" hidden data-account-menu-action>
                            <i class="bi bi-phone" aria-hidden="true"></i>
                            <span><strong>Install app</strong><small>Add the User Portal to this device</small></span>
                        </button>
                    <?php endif; ?>
                    <button type="button" data-profile-image-open data-account-menu-action>
                        <i class="bi bi-person-bounding-box" aria-hidden="true"></i>
                        <span><strong>Profile picture</strong><small>Change or upload your account photo</small></span>
                    </button>
                    <button type="button" data-account-security-open data-account-menu-action>
                        <i class="bi bi-shield-lock" aria-hidden="true"></i>
                        <span><strong>Account security</strong><small>Change your password or purchase PIN</small></span>
                    </button>
                    <button type="button" data-account-theme data-account-menu-action>
                        <i class="bi bi-moon-stars" aria-hidden="true"></i>
                        <span><strong>Appearance</strong><small>Switch light or dark mode</small></span>
                    </button>
                    <?php foreach ($portalSwitches as $portalSwitch): ?>
                        <a href="<?= site_url((string) ($portalSwitch['path'] ?? '')) ?>" data-account-menu-action>
                            <i class="<?= esc((string) ($portalSwitch['icon'] ?? 'bi bi-shuffle')) ?>" aria-hidden="true"></i>
                            <span><strong><?= esc((string) ($portalSwitch['label'] ?? 'Switch workspace')) ?></strong><small><?= esc((string) ($portalSwitch['description'] ?? 'Open another assigned workspace')) ?></small></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($availableRoles) > 1): ?>
                        <a href="<?= site_url('auth/select-role') ?>" data-account-menu-action>
                            <i class="bi bi-shuffle" aria-hidden="true"></i>
                            <span><strong>Switch portal</strong><small>Use another assigned role</small></span>
                        </a>
                    <?php endif; ?>
                </div>
                <form method="post" action="<?= site_url('auth/logout') ?>" class="account-menu-logout" data-confirm-logout>
                    <?= csrf_field() ?>
                    <button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Log out</span></button>
                </form>
            </section>
        </div>

        <main id="user-page-content" class="app-container" tabindex="-1" data-portal-page-content>
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer">
            <?= esc($footerText) ?>
        </footer>
    </div>
</div>

<?php if ($userMobileExperience): ?>
    <nav class="user-mobile-nav user-mobile-nav-count-<?= count($visibleNavigation) ?>" aria-label="<?= esc($portalTitle) ?> mobile navigation">
        <?php foreach ($navigation as $item): ?>
            <?php
            if (($item['visible'] ?? true) !== true) {
                continue;
            }
            $path = (string) ($item['path'] ?? '');
            $label = (string) ($item['label'] ?? '');
            $icon = (string) ($item['icon'] ?? 'bi bi-circle');
            $activePath = (string) ($item['activePath'] ?? $path);
            $activeClass = ibems_is_active_path($activePath);
            ?>
            <a href="<?= site_url($path) ?>" class="<?= esc($activeClass) ?>" data-portal-navigation-link aria-label="<?= esc($label) ?>" title="<?= esc($label) ?>" <?= $activeClass !== '' ? 'aria-current="page"' : '' ?>>
                <i class="<?= esc($icon) ?>" aria-hidden="true"></i>
                <span><?= esc($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<div id="account-security-modal" class="account-security-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="account-security-title">
    <section class="account-security-card">
        <div class="account-security-head">
            <div><span>Personal account</span><h3 id="account-security-title">Account security</h3></div>
            <button type="button" class="account-menu-close" data-account-security-close aria-label="Close account security"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <form id="account-password-form" class="account-security-form">
            <div class="account-security-copy"><i class="bi bi-key" aria-hidden="true"></i><div><strong>Change password</strong><small>Confirm your current password before setting a new one.</small></div></div>
            <label>Current password<input id="account-current-password" type="password" autocomplete="current-password" required></label>
            <label>New password<input id="account-new-password" type="password" autocomplete="new-password" minlength="8" required></label>
            <label>Confirm new password<input id="account-new-password-confirm" type="password" autocomplete="new-password" minlength="8" required></label>
            <p id="account-password-result" class="account-security-result" role="status" aria-live="polite"></p>
            <button id="account-password-save" class="primary-btn" type="submit"><i class="bi bi-check2-circle" aria-hidden="true"></i> Update password</button>
        </form>
        <form id="account-pin-form" class="account-security-form" hidden>
            <div class="account-security-copy"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i><div><strong>Personal purchase PIN</strong><small id="account-pin-help">Used to authorize purchases charged to your employee account.</small></div></div>
            <label id="account-pin-current-wrap" hidden>Current password<input id="account-pin-current-password" type="password" autocomplete="current-password"></label>
            <label>New PIN<input id="account-new-pin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <label>Confirm new PIN<input id="account-new-pin-confirm" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" pattern="[0-9]{4,6}" required></label>
            <p id="account-pin-result" class="account-security-result" role="status" aria-live="polite"></p>
            <button id="account-pin-save" class="secondary-btn" type="submit"><i class="bi bi-shield-check" aria-hidden="true"></i> Save PIN</button>
        </form>
    </section>
</div>

<div id="profile-image-modal" class="profile-image-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="profile-image-title">
    <form id="profile-image-form" class="profile-image-card" enctype="multipart/form-data">
        <div class="profile-image-head">
            <div>
                <span>Personal profile</span>
                <h3 id="profile-image-title">Profile picture</h3>
            </div>
            <button type="button" class="profile-image-close" data-profile-image-close aria-label="Close profile picture dialog"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="profile-image-body">
            <img src="<?= esc($resolvedProfileImageUrl) ?>" alt="Profile picture preview" class="profile-image-preview" data-profile-avatar id="profile-image-preview">
            <div class="profile-image-copy">
                <strong><?= esc($name) ?></strong>
                <p>Choose a clear square image. JPG, PNG, WebP, and GIF files up to 2 MB are accepted.</p>
                <label class="secondary-btn profile-image-picker" for="profile-image-file"><i class="bi bi-image"></i> Choose image</label>
                <input id="profile-image-file" name="profile_image" type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                <span id="profile-image-file-name" class="profile-image-file-name">No new image selected</span>
            </div>
        </div>
        <p id="profile-image-result" class="profile-image-result" aria-live="polite"></p>
        <div class="profile-image-actions">
            <button type="button" class="secondary-btn" data-profile-image-close>Cancel</button>
            <button type="submit" class="primary-btn" id="profile-image-save"><i class="bi bi-cloud-arrow-up"></i> Save picture</button>
        </div>
    </form>
</div>

<script src="<?= base_url('assets/js/csrf.js') ?>"></script>
<script src="<?= base_url('assets/js/theme.js') ?>?v=20260825c"></script>
<?php if ($notificationsEnabled): ?>
    <script src="<?= base_url('assets/js/notifications.js') ?>?v=20260823a"></script>
<?php endif; ?>
<script src="<?= base_url('assets/js/ibems-format.js') ?>"></script>
<script src="<?= base_url('assets/js/profile-avatar.js') ?>?v=20260822a"></script>
<script src="<?= base_url('assets/js/account-menu.js') ?>?v=20260902a"></script>
<script src="<?= base_url('assets/js/account-security.js') ?>?v=20260902a"></script>
<script src="<?= base_url('assets/js/dashboard-period.js') ?>?v=20260902a"></script>
<script {csp-script-nonce} id="portal-context-data" type="application/json"><?= json_encode($portalContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<template id="portal-page-scripts"><?= $pageScripts ?></template>
<script src="<?= base_url('assets/js/user-navigation.js') ?>?v=20260825a"></script>
<script src="<?= base_url('assets/js/app-layout.js') ?>?v=20260825b"></script>
<script src="<?= base_url('assets/js/modern-controls.js') ?>?v=20260824a"></script>
<script src="<?= base_url('assets/js/app-dialog.js') ?>"></script>
<script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20260813a"></script>
<?php if ($userMobileExperience): ?>
    <script src="<?= base_url('assets/js/user-mobile.js') ?>?v=20260823a" data-service-worker-url="<?= esc(base_url('user/service-worker.js')) ?>"></script>
<?php endif; ?>
<script {csp-script-nonce}>
window.IBEMS_PORTAL_CONTEXT = JSON.parse(document.getElementById('portal-context-data')?.textContent || '{}');
</script>
</body>
</html>
