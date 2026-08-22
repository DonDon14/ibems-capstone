<?php
/**
 * Shared authenticated portal shell.
 *
 * Expected variables:
 * - $pageTitle, $portalTitle, $portalSubtitle, $footerText
 * - $name, $role, $initials, $availableRoles, $navigation
 * Optional:
 * - $profileImageUrl, $profileDetail
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
    <link rel="stylesheet" href="<?= base_url('assets/css/modern-ui.css') ?>?v=20260822w">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20260813b">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->renderSection('styles') ?>
</head>
<body class="<?= esc($bodyClasses) ?>">
<div class="app-shell">
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

        <div class="sidebar-logout">
            <form method="post" action="<?= site_url('auth/logout') ?>" class="sidebar-logout-form" data-confirm-logout>
                <?= csrf_field() ?>
                <button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span>Logout</span></button>
            </form>
        </div>
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
                <?php if (count($availableRoles) > 1): ?>
                    <a href="<?= site_url('auth/select-role') ?>" class="topbar-action">
                        <i class="bi bi-shuffle" aria-hidden="true"></i>
                        <span>Switch Portal</span>
                    </a>
                <?php endif; ?>

                <button id="theme-toggle" type="button" class="topbar-icon-button" aria-label="Use dark mode" title="Use dark mode" aria-pressed="false">
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

                <form method="post" action="<?= site_url('auth/logout') ?>" class="topbar-logout" data-confirm-logout>
                    <?= csrf_field() ?>
                    <button type="submit" class="topbar-action" aria-label="Logout" title="Logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        <span>Logout</span>
                    </button>
                </form>

                <button class="topbar-profile" type="button" data-profile-image-open aria-label="Change profile picture" title="Change profile picture">
                    <span class="profile-avatar-wrap">
                        <img src="<?= esc($resolvedProfileImageUrl) ?>" alt="" class="profile-avatar-img" data-profile-avatar data-current-user-avatar>
                        <span class="profile-avatar-edit" aria-hidden="true"><i class="bi bi-camera-fill"></i></span>
                    </span>
                    <div class="profile-meta">
                        <div class="profile-name"><?= esc($name) ?></div>
                        <div class="profile-role"><?= esc($profileDetail) ?></div>
                    </div>
                </button>
            </div>
        </header>

        <main class="app-container">
            <?= $this->renderSection('content') ?>
        </main>

        <footer class="app-footer">
            <?= esc($footerText) ?>
        </footer>
    </div>
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
<script src="<?= base_url('assets/js/theme.js') ?>?v=20260822b"></script>
<script src="<?= base_url('assets/js/notifications.js') ?>?v=20260822c"></script>
<script src="<?= base_url('assets/js/ibems-format.js') ?>"></script>
<script src="<?= base_url('assets/js/profile-avatar.js') ?>?v=20260822a"></script>
<script src="<?= base_url('assets/js/app-layout.js') ?>?v=20260822f"></script>
<script src="<?= base_url('assets/js/modern-controls.js') ?>"></script>
<script src="<?= base_url('assets/js/app-dialog.js') ?>"></script>
<script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20260813a"></script>
<script>
window.IBEMS_PORTAL_CONTEXT = <?= json_encode($portalContext, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
