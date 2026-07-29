<?php
$title = trim((string) ($title ?? ''));
$description = trim((string) ($description ?? ''));
$eyebrow = trim((string) ($eyebrow ?? ''));
$icon = trim((string) ($icon ?? ''));
$actions = $actions ?? null;
?>
<header class="page-header">
    <div class="page-header-copy">
        <?php if ($eyebrow !== ''): ?>
            <span class="page-header-eyebrow"><?= esc($eyebrow) ?></span>
        <?php endif; ?>
        <div class="page-header-title-row">
            <?php if ($icon !== ''): ?>
                <span class="page-header-icon" aria-hidden="true"><i class="<?= esc($icon) ?>"></i></span>
            <?php endif; ?>
            <div>
                <h1><?= esc($title) ?></h1>
                <?php if ($description !== ''): ?>
                    <p><?= esc($description) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($actions !== null && trim((string) $actions) !== ''): ?>
        <div class="page-header-actions"><?= $actions ?></div>
    <?php endif; ?>
</header>
