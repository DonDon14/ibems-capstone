<?php if (!empty($pagination)): $pageUrl = $pagination['url'] . (str_contains($pagination['url'], '?') ? '&' : '?') . 'page='; ?>
<nav class="daily-report-pagination" aria-label="Report history pages">
    <p>Showing <?= esc($pagination['label'] ?? 'reports') ?> <?= (int) $pagination['first'] ?>–<?= (int) $pagination['last'] ?> of <?= (int) $pagination['total'] ?> · Page <?= (int) $pagination['page'] ?> of <?= (int) $pagination['lastPage'] ?></p>
    <div>
        <?php if ($pagination['page'] > 1): ?>
            <a class="secondary-btn" rel="prev" href="<?= esc($pageUrl . ($pagination['page'] - 1)) ?>"><?= esc($pagination['previousLabel'] ?? 'Previous newer reports') ?></a>
        <?php endif ?>
        <?php if ($pagination['page'] < $pagination['lastPage']): ?>
            <a class="secondary-btn" rel="next" href="<?= esc($pageUrl . ($pagination['page'] + 1)) ?>"><?= esc($pagination['nextLabel'] ?? 'Next older reports') ?></a>
        <?php endif ?>
    </div>
</nav>
<?php endif ?>
