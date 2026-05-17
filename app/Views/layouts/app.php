<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
        $settingService = new \App\Services\SettingService();
        $settingService->bootstrapDefaults(current_user_id());
        $appName = $settingService->get('app_name', 'IBEMS');
        $appTagline = $settingService->get('app_tagline', 'Integrated Business Enterprise Management System');
        $supportEmail = $settingService->get('support_email', 'support@ibems.local');
        $compactMode = $settingService->get('ui_compact_mode', '0') === '1';
    ?>
    <title><?= esc($title ?? $appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: #ffffff;
            --panel-soft: #f8fbff;
            --text: #102033;
            --muted: #62748a;
            --border: #dbe5f1;
            --brand: #2563eb;
            --brand-dark: #1d4ed8;
            --brand-ink: #0b1323;
            --sidebarA: #0a1220;
            --sidebarB: #101b2f;
            --shadow: 0 10px 30px rgba(12, 25, 45, .08);
            --radius: 14px;
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(1200px 400px at 20% -10%, #dcecff 0%, transparent 55%),
                radial-gradient(1200px 400px at 80% -20%, #e7f2ff 0%, transparent 60%),
                var(--bg);
            font-family: 'Manrope', system-ui, -apple-system, Segoe UI, sans-serif;
            line-height: 1.45;
        }

        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 9999;
            background: #111827;
            color: #fff;
            padding: .5rem .75rem;
            border-radius: 0 0 8px 0;
        }
        .skip-link:focus { left: 0; }

        .topbar {
            background: linear-gradient(90deg, var(--sidebarA), var(--sidebarB));
            border-bottom: 1px solid rgba(255,255,255,.08);
            min-height: 62px;
        }
        .app-brand strong { letter-spacing: .2px; }
        .app-brand small { opacity: .74; display: block; margin-top: -2px; font-size: .72rem; }

        .app-shell { min-height: calc(100vh - 62px); }
        .sidebar-panel {
            background: linear-gradient(180deg, var(--sidebarA), var(--sidebarB));
            min-height: calc(100vh - 62px);
            border-right: 1px solid rgba(255,255,255,.06);
        }

        .sidebar-link {
            color: #c9d7ea;
            border-radius: 10px;
            padding: .62rem .72rem;
            display: flex;
            align-items: center;
            gap: .55rem;
            font-weight: 600;
            font-size: .92rem;
            transition: .15s ease;
        }
        .sidebar-link .bi { opacity: .9; }
        .sidebar-link:hover { color: #fff; background: rgba(255,255,255,.12); transform: translateX(1px); }
        .sidebar-link.active { color: #fff; background: linear-gradient(90deg, rgba(37,99,235,.45), rgba(37,99,235,.2)); border: 1px solid rgba(147,197,253,.5); }

        .content-wrap { padding: 1.25rem; }
        .page-head { margin-bottom: 1rem; }
        .page-head h1 { margin: 0; font-weight: 800; letter-spacing: -.02em; }

        .page-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-body { padding: 1rem; }
        .table { margin-bottom: 0; }
        .table > :not(caption) > * > * {
            border-bottom-color: #e6edf6;
            padding: .72rem .75rem;
            vertical-align: middle;
        }
        .table thead th {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #51657c;
            background: var(--panel-soft);
            border-bottom: 1px solid #dbe5f1;
            font-weight: 700;
        }
        .table tbody tr:hover { background: #f7fbff; }

        .form-label {
            font-size: .78rem;
            color: #4b6077;
            text-transform: uppercase;
            letter-spacing: .04em;
            font-weight: 700;
            margin-bottom: .35rem;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border-color: #cfdbea;
            padding: .58rem .68rem;
            font-size: .93rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #93c5fd;
            box-shadow: 0 0 0 .18rem rgba(59,130,246,.18);
        }

        .btn {
            border-radius: 10px;
            font-weight: 700;
            font-size: .9rem;
        }

        .stat-card {
            display: flex;
            flex-direction: column;
            gap: .15rem;
            min-height: 92px;
        }
        .stat-label { color: #5c7088; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .stat-value { font-size: 1.45rem; font-weight: 800; letter-spacing: -.02em; }

        .offcanvas-body .sidebar-link { color: #334155; }
        .offcanvas-body .sidebar-link.active { color: #fff; background: linear-gradient(90deg, #2563eb, #3b82f6); border-color: transparent; }

        .alert {
            border: 0;
            border-radius: 12px;
            box-shadow: var(--shadow);
            font-weight: 600;
        }

        <?php if ($compactMode): ?>
        .content-wrap { padding: .85rem; }
        .card-body { padding: .78rem !important; }
        .btn, .form-control, .form-select, .table { font-size: .86rem; }
        <?php endif; ?>

        @media (max-width: 991.98px) {
            .content-wrap { padding: .9rem; }
            .page-head h1 { font-size: 1.15rem; }
        }
    </style>
</head>
<body>
<a href="#mainContent" class="skip-link">Skip to content</a>
<?php $user = current_user(); ?>
<?php if (! $user): ?>
    <main class="container py-5" id="mainContent">
        <?php if (session()->getFlashdata('success')): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= esc(session()->getFlashdata('success')) ?></div><?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= esc(session()->getFlashdata('error')) ?></div><?php endif; ?>
        <?= $this->renderSection('content') ?>
    </main>
<?php else: ?>
<?php
    $role = $user['role'];
    $path = '/' . trim(service('uri')->getPath(), '/');

    $menus = [
        ['label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'bi-speedometer2', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE', 'STORE_SYSTEM', 'USER']],
        ['label' => 'POS Terminal', 'href' => '/pos', 'icon' => 'bi-cart4', 'roles' => ['STORE_SYSTEM', 'ADMIN']],
        ['label' => 'Store Inventory', 'href' => '/store/inventory', 'icon' => 'bi-box-seam', 'roles' => ['STORE_SYSTEM']],
        ['label' => 'Users', 'href' => '/admin/users', 'icon' => 'bi-people', 'roles' => ['ADMIN']],
        ['label' => 'Stores', 'href' => '/admin/stores', 'icon' => 'bi-shop', 'roles' => ['ADMIN']],
        ['label' => 'Products (View)', 'href' => '/admin/products', 'icon' => 'bi-box-seam', 'roles' => ['ADMIN']],
        ['label' => 'HR CSV Import', 'href' => '/hr/import-csv', 'icon' => 'bi-file-earmark-arrow-up', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Accounting', 'href' => '/accounting', 'icon' => 'bi-calculator', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Daily Sales', 'href' => '/reports/daily-sales', 'icon' => 'bi-graph-up-arrow', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Debt Aging', 'href' => '/reports/debt-aging', 'icon' => 'bi-clock-history', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Low Stock', 'href' => '/reports/low-stock', 'icon' => 'bi-exclamation-triangle', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Settlement History', 'href' => '/reports/settlement-history', 'icon' => 'bi-journal-check', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
        ['label' => 'Audit Trail', 'href' => '/reports/audit-trail', 'icon' => 'bi-shield-check', 'roles' => ['ADMIN', 'ACCOUNTING_OFFICE']],
    ];

    $visibleMenus = array_filter($menus, static fn($item) => in_array($role, $item['roles'], true));
    $isDashboard = $path === '/dashboard';
?>
<nav class="navbar navbar-dark topbar sticky-top">
    <div class="container-fluid">
        <button class="btn btn-sm btn-outline-light d-md-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>
        <div class="text-white app-brand">
            <strong><?= esc($appName) ?></strong>
            <small><?= esc($appTagline) ?></small>
        </div>
        <div class="d-flex align-items-center text-white gap-2">
            <span class="small d-none d-md-inline"><?= esc($user['name']) ?> (<?= esc($user['role']) ?>)</span>
            <a href="/me" class="btn btn-sm btn-outline-light"><i class="bi bi-person-gear"></i> Account</a>
            <?php if ($role === 'ADMIN'): ?>
                <a href="/settings" class="btn btn-sm btn-outline-light"><i class="bi bi-sliders"></i> System Settings</a>
            <?php endif; ?>
            <form method="post" action="/auth/logout" class="mb-0">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i> Logout</button>
            </form>
        </div>
    </div>
</nav>

<div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title"><?= esc($appName) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="nav flex-column gap-1">
            <?php foreach ($visibleMenus as $item): ?>
                <?php $active = str_starts_with($path, $item['href']) && $item['href'] !== '/dashboard' ? true : ($path === '/dashboard' && $item['href'] === '/dashboard'); ?>
                <a href="<?= esc($item['href']) ?>" class="nav-link sidebar-link <?= $active ? 'active' : '' ?>"><i class="bi <?= esc($item['icon']) ?>"></i> <?= esc($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <hr>
        <small class="text-secondary">Support: <?= esc($supportEmail) ?></small>
    </div>
</div>

<div class="container-fluid app-shell">
    <div class="row">
        <aside class="col-md-3 col-lg-2 d-none d-md-block sidebar-panel py-3 px-2">
            <div class="nav flex-column gap-1">
                <?php foreach ($visibleMenus as $item): ?>
                    <?php $active = str_starts_with($path, $item['href']) && $item['href'] !== '/dashboard' ? true : ($path === '/dashboard' && $item['href'] === '/dashboard'); ?>
                    <a href="<?= esc($item['href']) ?>" class="nav-link sidebar-link <?= $active ? 'active' : '' ?>"><i class="bi <?= esc($item['icon']) ?>"></i> <?= esc($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="mt-4 small text-light px-2">
                <div class="opacity-75">Support</div>
                <div><?= esc($supportEmail) ?></div>
            </div>
        </aside>
        <main class="col-md-9 col-lg-10 content-wrap" id="mainContent">
            <div class="d-flex justify-content-between align-items-start mb-3 gap-2 page-head">
                <div>
                    <h1 class="h4 mb-1"><?= esc($pageTitle ?? ($title ?? $appName)) ?></h1>
                    <?php if (! empty($pageSubtitle ?? '')): ?><p class="text-secondary mb-0 small"><?= esc($pageSubtitle) ?></p><?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <?php if (! $isDashboard): ?><a href="javascript:history.back()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Back</a><?php endif; ?>
                    <a href="/dashboard" class="btn btn-outline-primary btn-sm"><i class="bi bi-house"></i> Dashboard</a>
                </div>
            </div>

            <?php if (session()->getFlashdata('success')): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= esc(session()->getFlashdata('success')) ?></div><?php endif; ?>
            <?php if (session()->getFlashdata('error')): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= esc(session()->getFlashdata('error')) ?></div><?php endif; ?>
            <?= $this->renderSection('content') ?>
        </main>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
