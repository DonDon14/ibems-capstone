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
    <title>Select Role</title>
    <script {csp-script-nonce}>
    (function () {
        var saved = localStorage.getItem('ibems-theme');
        var theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.dataset.theme = theme;
    }());
    </script>
    <link rel="icon" type="image/png" href="<?= base_url('assets/images/ibems-logo.png') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/tailwind.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/auth-login.css') ?>?v=20260825c">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="auth-modern auth-role-selection">
<?php $ibemsLogoUrl = base_url('assets/images/ibems-logo.png'); ?>
    <button id="theme-toggle" class="auth-theme-toggle" type="button" aria-label="Use dark mode" title="Use dark mode" aria-pressed="false">
        <i class="bi bi-moon-stars" aria-hidden="true"></i>
    </button>
    <main class="auth-shell">
        <section class="auth-panel auth-panel-form auth-role-panel">
            <div class="auth-mark">
                <img src="<?= esc($ibemsLogoUrl) ?>" alt="IBEMS logo" class="auth-mark-logo">
            </div>
            <h1>Select Portal</h1>
            <p>Choose which role to use for this session.</p>

            <?php
            $rolePresentation = [
                'ADMIN' => [
                    'label' => 'Admin',
                    'description' => 'Manage school-wide operations, users, stores, and audit activity.',
                    'icon' => 'bi-shield-check',
                ],
                'STORE_SYSTEM' => [
                    'label' => 'Store Cashier',
                    'description' => 'Open the assigned store POS and process checkout transactions.',
                    'icon' => 'bi-cart3',
                ],
                'STORE_SUPERVISOR' => [
                    'label' => 'Store Supervisor',
                    'description' => 'Manage assigned stores, inventory, settings, staff activity, and reports.',
                    'icon' => 'bi-diagram-3',
                ],
                'ACCOUNTING_OFFICE' => [
                    'label' => 'Accounting Office',
                    'description' => 'Monitor employee debt, deductions, and payroll records.',
                    'icon' => 'bi-calculator',
                ],
                'USER' => [
                    'label' => 'User',
                    'description' => 'Shop products, review purchases, and manage your account.',
                    'icon' => 'bi-person',
                ],
            ];
            ?>
            <div id="role-options" class="auth-role-list" role="group" aria-label="Available portals">
                <?php foreach (($availableRoles ?? ibems_available_roles()) as $availableRole): ?>
                    <?php
                    $roleKey = strtoupper((string) $availableRole);
                    $portal = $rolePresentation[$roleKey] ?? [
                        'label' => ucwords(strtolower(str_replace('_', ' ', $roleKey))),
                        'description' => 'Open this assigned IBEMS portal.',
                        'icon' => 'bi-grid',
                    ];
                    $assignedStores = array_values(array_filter(
                        (array) (($roleStores ?? [])[$roleKey] ?? []),
                        static fn ($store): bool => is_array($store) && trim((string) ($store['store_name'] ?? '')) !== ''
                    ));
                    $storeCount = count($assignedStores);
                    $storeNames = array_map(static fn (array $store): string => trim((string) $store['store_name']), $assignedStores);
                    $visibleStoreNames = array_slice($storeNames, 0, 2);
                    $remainingStoreCount = max(0, $storeCount - count($visibleStoreNames));
                    $storeContext = $storeCount === 1
                        ? $storeNames[0]
                        : ($storeCount > 1
                            ? $storeCount . ' assigned stores: ' . implode(', ', $visibleStoreNames) . ($remainingStoreCount > 0 ? ' +' . $remainingStoreCount . ' more' : '')
                            : '');
                    ?>
                    <form method="post" action="<?= site_url('auth/select-role') ?>" class="auth-role-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="auth-role-btn" name="role" value="<?= esc($roleKey) ?>">
                            <span class="auth-role-icon" aria-hidden="true">
                                <i class="bi <?= esc($portal['icon']) ?>"></i>
                            </span>
                            <span class="auth-role-copy">
                                <strong><?= esc($portal['label']) ?></strong>
                                <small><?= esc($portal['description']) ?></small>
                                <?php if ($storeContext !== ''): ?>
                                    <span class="auth-role-meta">
                                        <i class="bi bi-building" aria-hidden="true"></i>
                                        <span><?= esc($storeContext) ?></span>
                                    </span>
                                <?php endif; ?>
                            </span>
                            <i class="bi bi-arrow-right auth-role-arrow" aria-hidden="true"></i>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
            <div id="status"></div>
        </section>

        <section class="auth-panel auth-panel-art" aria-hidden="true">
            <div class="auth-art-layer auth-art-1"></div>
            <div class="auth-art-layer auth-art-2"></div>
            <div class="auth-art-layer auth-art-3"></div>
            <div class="auth-art-layer auth-art-4"></div>
            <div class="auth-art-line auth-art-line-1"></div>
            <div class="auth-art-line auth-art-line-2"></div>
        </section>
    </main>

    <script src="<?= base_url('assets/js/theme.js') ?>?v=20260825c"></script>
</body>
</html>
