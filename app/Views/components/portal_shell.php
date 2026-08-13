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
if ($role !== '' && str_starts_with($profileDetail, $role)) {
    $profileDetail = $readableRole . substr($profileDetail, strlen($role));
}
$ustpLogoUrl = base_url('assets/images/ustp_claveria_logo.jpg');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token-name" content="<?= esc(config('Security')->tokenName) ?>">
    <meta name="csrf-token-value" content="<?= esc(service('security')->getHash()) ?>">
    <meta name="csrf-header-name" content="<?= esc(config('Security')->headerName) ?>">
    <meta name="csrf-cookie-name" content="<?= esc(config('Security')->cookieName) ?>">
    <title><?= esc($pageTitle) ?></title>
    <link rel="icon" type="image/jpeg" href="<?= esc($ustpLogoUrl) ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=20260813c">
    <link rel="stylesheet" href="<?= base_url('assets/css/modern-ui.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/password-visibility.css') ?>?v=20260813a">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?= $this->renderSection('styles') ?>
</head>
<body class="<?= esc($bodyClasses) ?>">
<div class="app-shell">
    <aside class="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-brand">
                <div class="app-brand-mark">
                    <img src="<?= esc($ustpLogoUrl) ?>" alt="USTP Logo" class="app-brand-logo">
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
            <form method="post" action="<?= site_url('auth/logout') ?>" class="sidebar-logout-form">
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

                <form method="post" action="<?= site_url('auth/logout') ?>" class="topbar-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="topbar-action" aria-label="Logout" title="Logout">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                        <span>Logout</span>
                    </button>
                </form>

                <div class="topbar-profile">
                    <?php if ($profileImageUrl !== ''): ?>
                        <img src="<?= esc($profileImageUrl) ?>" alt="<?= esc($name) ?> profile" class="profile-avatar-img">
                    <?php else: ?>
                        <div class="profile-avatar" aria-hidden="true"><?= esc($initials) ?></div>
                    <?php endif; ?>
                    <div class="profile-meta">
                        <div class="profile-name"><?= esc($name) ?></div>
                        <div class="profile-role"><?= esc($profileDetail) ?></div>
                    </div>
                </div>
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

<script src="<?= base_url('assets/js/csrf.js') ?>"></script>
<script src="<?= base_url('assets/js/ibems-format.js') ?>"></script>
<script src="<?= base_url('assets/js/app-layout.js') ?>"></script>
<script src="<?= base_url('assets/js/modern-controls.js') ?>"></script>
<script src="<?= base_url('assets/js/app-dialog.js') ?>"></script>
<script src="<?= base_url('assets/js/password-visibility.js') ?>?v=20260813a"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>
