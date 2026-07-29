<?php
$type = strtolower((string) ($type ?? 'loading'));
$message = trim((string) ($message ?? 'Loading...'));
$detail = trim((string) ($detail ?? ''));
$tag = strtolower((string) ($tag ?? 'div'));
$colspan = max(1, (int) ($colspan ?? 1));
$allowedTypes = ['loading', 'empty', 'error', 'success'];

if (!in_array($type, $allowedTypes, true)) {
    $type = 'loading';
}

$icons = [
    'loading' => 'bi bi-arrow-repeat',
    'empty' => 'bi bi-inbox',
    'error' => 'bi bi-exclamation-circle',
    'success' => 'bi bi-check-circle',
];

$content = static function () use ($type, $message, $detail, $icons): string {
    ob_start();
    ?>
    <div class="data-state data-state--<?= esc($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>" aria-live="polite">
        <i class="<?= esc($icons[$type]) ?>" aria-hidden="true"></i>
        <div>
            <strong><?= esc($message) ?></strong>
            <?php if ($detail !== ''): ?>
                <small><?= esc($detail) ?></small>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return trim((string) ob_get_clean());
};
?>
<?php if ($tag === 'tr'): ?>
    <tr class="data-state-row">
        <td colspan="<?= $colspan ?>"><?= $content() ?></td>
    </tr>
<?php else: ?>
    <?= $content() ?>
<?php endif; ?>
