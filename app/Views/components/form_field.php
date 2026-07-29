<?php
$id = trim((string) ($id ?? ''));
$label = trim((string) ($label ?? ''));
$type = trim((string) ($type ?? 'text'));
$name = trim((string) ($name ?? ''));
$value = (string) ($value ?? '');
$placeholder = trim((string) ($placeholder ?? ''));
$help = trim((string) ($help ?? ''));
$error = trim((string) ($error ?? ''));
$class = trim((string) ($class ?? ''));
$required = (bool) ($required ?? false);
$attributes = is_array($attributes ?? null) ? $attributes : [];

$fieldClasses = trim('ui-field ' . ($type === 'search' ? 'ui-field--search ' : '') . $class);
$helpId = $id !== '' && $help !== '' ? $id . '-help' : '';
$errorId = $id !== '' && $error !== '' ? $id . '-error' : '';
$describedBy = trim($helpId . ' ' . $errorId);

$inputAttributes = [
    'id' => $id,
    'type' => $type,
];

if ($name !== '') {
    $inputAttributes['name'] = $name;
}
if ($value !== '') {
    $inputAttributes['value'] = $value;
}
if ($placeholder !== '') {
    $inputAttributes['placeholder'] = $placeholder;
}
if ($required) {
    $inputAttributes['required'] = true;
}
if ($error !== '') {
    $inputAttributes['aria-invalid'] = 'true';
}
if ($describedBy !== '') {
    $inputAttributes['aria-describedby'] = $describedBy;
}

foreach ($attributes as $attribute => $attributeValue) {
    $inputAttributes[(string) $attribute] = $attributeValue;
}
?>
<label class="<?= esc($fieldClasses) ?>"<?= $id !== '' ? ' for="' . esc($id) . '"' : '' ?>>
    <span><?= esc($label) ?><?= $required ? ' (required)' : '' ?></span>
    <input<?php foreach ($inputAttributes as $attribute => $attributeValue): ?><?php
        if ($attributeValue === false || $attributeValue === null || $attributeValue === '') {
            continue;
        }
        echo ' ' . esc($attribute);
        if ($attributeValue !== true) {
            echo '="' . esc((string) $attributeValue) . '"';
        }
    ?><?php endforeach; ?>>
    <?php if ($help !== ''): ?>
        <small id="<?= esc($helpId) ?>" class="ui-field-help"><?= esc($help) ?></small>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <small id="<?= esc($errorId) ?>" class="ui-field-error"><?= esc($error) ?></small>
    <?php endif; ?>
</label>
