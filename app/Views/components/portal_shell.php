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
$navigation = is_array($navigation ?? null) ? $navigation : [];
$profileImageUrl = trim((string) ($profileImageUrl ?? ''));
$resolvedProfileImageUrl = ibems_profile_image_url($profileImageUrl);
$bodyClass = trim((string) ($bodyClass ?? ''));
$bodyClasses = trim('ibems-modern ' . $bodyClass);
$roleLabels = [
    'ADMIN' => 'Administrator',
    'ACCOUNTING_OFFICE' => 'Accounting Office',
    'STORE_SUPERVISOR' => 'Store Supervisor',
    'STORE_SYSTEM' => 'Store Officer',
    'USER' => 'Employee',
];
$readableRole = $roleLabels[strtoupper($role)] ?? ucwords(strtolower(str_replace('_', ' ', $role)));
$profileDetail = trim((string) ($profileDetail ?? $readableRole));
$portalContext = is_array($portalContext ?? null) ? $portalContext : [];
$userMobileExperience = ($userMobileExperience ?? false) === true && strtoupper($role) === 'USER';
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
    <script>
    (function () {
        var saved = localStorage.getItem('ibems-theme');
        var theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = theme;
    }());
    </script>
    <link rel="icon" type="image/png" href="<?= esc($ibemsLogoUrl) ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=20260822k">
    <link rel="stylesheet" href="<?= base_url('assets/css/modern-ui.css') ?>?v=20260823g">
    <link rel="stylesheet" href="<?= base_url('assets/css/account-menu.css') ?>?v=20260823a">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20260813b">
    <?php if ($userMobileExperience): ?>
        <link rel="stylesheet" href="<?= base_url('assets/css/user-mobile.css') ?>?v=20260823d">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->renderSection('styles') ?>
</head>
<body class="<?= esc($bodyClasses) ?>">
<div class="app-shell"<?= $userMobileExperience ? ' data-user-navigation-shell' : '' ?>>
    <aside class="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-brand">
                <div class="app-brand-mark">
                    <img src="<?= esc($ibemsLogoUrl) ?>" alt="IBEMS logo" class="app-brand-logo">
                    <button id="sidebar-toggle" type="button" class="secondary-btn sidebar-toggle sidebar-toggle-in-sidebar" aria-label="Toggle Sidebar">
                        <i class="sidebar-toggle-glyph" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="app-brand-text">
                    <h1>IBEMS</h1>
                    <p><?= esc($portalTitle) ?></p>
                </div>
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
                <a href="<?= site_url($path) ?>" class="<?= esc($activeClass) ?>" <?= $activeClass !== '' ? 'aria-current="page"' : '' ?>>
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

                <button id="account-menu-toggle" class="topbar-profile" type="button" aria-label="Open account and settings" title="Account and settings" aria-expanded="false" aria-controls="account-menu">
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
                        <button id="u-open-pin-modal" type="button" data-account-menu-action>
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            <span><strong>Debt authorization PIN</strong><small id="u-debt-pin-menu-status">Set or change your purchase PIN</small></span>
                        </button>
                    <?php endif; ?>
                    <button type="button" data-profile-image-open data-account-menu-action>
                        <i class="bi bi-person-bounding-box" aria-hidden="true"></i>
                        <span><strong>Profile picture</strong><small>Change or upload your account photo</small></span>
                    </button>
                    <button type="button" data-account-theme data-account-menu-action>
                        <i class="bi bi-moon-stars" aria-hidden="true"></i>
                        <span><strong>Appearance</strong><small>Switch light or dark mode</small></span>
                    </button>
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

        <div class="user-navigation-progress" aria-hidden="true"></div>
        <div id="user-navigation-status" class="sr-only" role="status" aria-live="polite"></div>

        <main id="user-page-content" class="app-container" tabindex="-1">
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer">
            <?= esc($footerText) ?>
        </footer>
    </div>
</div>

<?php if ($userMobileExperience): ?>
    <div id="u-debt-pin-modal" class="app-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="u-debt-pin-title">
        <div class="app-modal-card">
            <div class="app-modal-head">
                <h4 id="u-debt-pin-title"><i class="bi bi-shield-lock"></i> Debt Authorization PIN</h4>
                <button id="u-pin-modal-close" type="button" class="app-modal-close" aria-label="Close debt authorization PIN form">x</button>
            </div>
            <form id="u-debt-pin-form" class="user-pin-form">
                <p id="u-pin-form-help">Use a 4 to 6 digit PIN. Stores will ask for this only when charging purchases to debt.</p>
                <label id="u-current-password-wrap" class="user-pin-field is-hidden">
                    <span>Current Password</span>
                    <input id="u-current-password" name="current_password" type="password" autocomplete="current-password">
                </label>
                <label class="user-pin-field">
                    <span>New Debt PIN</span>
                    <input id="u-debt-pin" name="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" required>
                </label>
                <label class="user-pin-field">
                    <span>Confirm Debt PIN</span>
                    <input id="u-debt-pin-confirm" name="pin_confirm" type="password" inputmode="numeric" maxlength="6" autocomplete="off" required>
                </label>
                <p id="u-pin-form-result" class="user-pin-result" aria-live="polite"></p>
                <div class="app-modal-actions">
                    <button id="u-pin-modal-cancel" type="button" class="secondary-btn">Cancel</button>
                    <button id="u-save-pin" type="submit" class="primary-btn"><i class="bi bi-check2-circle"></i> Save PIN</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($userMobileExperience): ?>
    <nav class="user-mobile-nav" aria-label="User mobile navigation">
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
            <a href="<?= site_url($path) ?>" class="<?= esc($activeClass) ?>" aria-label="<?= esc($label) ?>" title="<?= esc($label) ?>" <?= $activeClass !== '' ? 'aria-current="page"' : '' ?>>
                <i class="<?= esc($icon) ?>" aria-hidden="true"></i>
                <span><?= esc($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

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
<script src="<?= base_url('assets/js/theme.js') ?>?v=20260822b"></script>
<script src="<?= base_url('assets/js/notifications.js') ?>?v=20260822c"></script>
<script src="<?= base_url('assets/js/ibems-format.js') ?>"></script>
<script src="<?= base_url('assets/js/profile-avatar.js') ?>?v=20260822a"></script>
<script src="<?= base_url('assets/js/account-menu.js') ?>?v=20260823a"></script>
<?php if ($userMobileExperience): ?>
    <script src="<?= base_url('assets/js/user-navigation.js') ?>?v=20260823a"></script>
<?php endif; ?>
<script src="<?= base_url('assets/js/app-layout.js') ?>?v=20260823a"></script>
<script src="<?= base_url('assets/js/modern-controls.js') ?>"></script>
<script src="<?= base_url('assets/js/app-dialog.js') ?>"></script>
<script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20260813a"></script>
<?php if ($userMobileExperience): ?>
    <script src="<?= base_url('assets/js/user-account-settings.js') ?>?v=20260823a"></script>
    <script src="<?= base_url('assets/js/user-mobile.js') ?>?v=20260823a" data-service-worker-url="<?= esc(base_url('user/service-worker.js')) ?>"></script>
<?php endif; ?>
<script>
window.IBEMS_PORTAL_CONTEXT = <?= json_encode($portalContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
