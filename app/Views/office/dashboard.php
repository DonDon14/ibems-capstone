<?= $this->extend('layouts/office') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => (string) ($portalTitle ?? 'Office'),
        'title' => (string) ($portalSubtitle ?? 'Dashboard'),
        'description' => (string) ($description ?? ''),
        'icon' => 'bi bi-building',
    ]) ?>
    <article class="data-panel office-dashboard-boundary">
        <div class="data-panel-head"><div><h3>Current access boundary</h3></div></div>
        <div class="data-panel-body"><p><?= esc((string) ($boundary ?? '')) ?></p></div>
    </article>
</section>
<?= $this->endSection() ?>
