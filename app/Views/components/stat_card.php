<?php
$title = (string) ($title ?? '');
$value = (string) ($value ?? '0');
$valueId = (string) ($valueId ?? '');
$icon = (string) ($icon ?? 'bi bi-circle');
$tone = (string) ($tone ?? 'default');
$class = trim((string) ($class ?? ''));
$meta = (string) ($meta ?? '');
$metaId = (string) ($metaId ?? '');
$valueHtml = $valueHtml ?? null;

$classes = 'metric-card';
if ($class !== '') {
    $classes .= ' ' . $class;
}
?>
<article class="<?= esc($classes) ?>">
    <div class="metric-card-head">
        <span class="metric-card-label"><?= esc($title) ?></span>
        <span class="metric-card-icon metric-card-icon--<?= esc($tone) ?>" aria-hidden="true">
            <i class="<?= esc($icon) ?>" aria-hidden="true"></i>
        </span>
    </div>
    <strong<?= $valueId !== '' ? ' id="' . esc($valueId) . '"' : '' ?>>
        <?php if ($valueHtml !== null): ?>
            <?= $valueHtml ?>
        <?php else: ?>
            <?= esc($value) ?>
        <?php endif; ?>
    </strong>
    <?php if ($meta !== ''): ?>
        <small<?= $metaId !== '' ? ' id="' . esc($metaId) . '"' : '' ?>><?= esc($meta) ?></small>
    <?php endif; ?>
</article>
