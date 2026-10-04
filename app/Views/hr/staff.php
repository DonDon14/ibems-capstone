<?= $this->extend('layouts/hr') ?>

<?= $this->section('content') ?>
<section class="admin-overview-shell hr-staff-page space-y-5">
    <?= view('components/page_header', [
        'eyebrow' => 'Human resources',
        'title' => 'Employee onboarding',
        'description' => 'Add faculty or staff with an initial salary-grade profile. The current CSV route imports standard-profile Staff only; Accounting owns individual salary overrides.',
        'icon' => 'bi bi-person-plus',
    ]) ?>

    <div class="hr-staff-grid grid gap-5 xl:grid-cols-2">
        <article class="data-panel hr-staff-card">
            <div class="data-panel-head">
                <div><h3>Add faculty or staff</h3><p>Choose the employee classification and salary grade/step. The default salary and 25% credit are shown before creation.</p></div>
            </div>
            <div class="data-panel-body">
                <form id="hr-add-form" class="hr-staff-form form-grid grid gap-4 md:grid-cols-2">
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Employee ID</span><input name="employee_id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" autocomplete="off"></label>
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Full name</span><input name="name" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="text" autocomplete="name" required></label>
                    <label class="field space-y-1 md:col-span-2"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Email</span><input name="email" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="email" autocomplete="email" required></label>
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Account type</span><select name="user_type" aria-label="Account type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" required><option value="staff">Staff</option><option value="faculty">Faculty</option></select></label>
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Employment type</span><select name="employment_type" aria-label="Employment type" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" required><option value="plantilla">Regular / plantilla</option><option value="cos">COS</option><option value="part_time">Part-time</option></select></label>
                    <label class="field space-y-1 md:col-span-2"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Salary schedule</span><select id="hr-salary-schedule" name="salary_schedule_id" aria-label="Salary schedule" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" required>
                        <?php foreach ($salarySchedules as $schedule): ?><option value="<?= (int) $schedule['id'] ?>" data-rates="<?= esc(json_encode($schedule['rates'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG)) ?>" <?= $schedule['code'] === \App\Services\SalaryScheduleService::STANDARD_SCHEDULE_CODE ? 'selected' : '' ?>><?= esc($schedule['name']) ?> (<?= esc($schedule['code']) ?>)</option><?php endforeach; ?>
                    </select></label>
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Salary grade</span><select id="hr-salary-grade" name="salary_grade" aria-label="Salary grade" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" required></select></label>
                    <label class="field space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Salary step</span><select id="hr-salary-step" name="salary_step" aria-label="Salary step" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" required></select></label>
                    <p id="hr-salary-preview" class="md:col-span-2 text-sm text-slate-600" role="status" aria-live="polite">Select a salary grade and step to preview the default monthly salary and credit limit.</p>
                    <label class="field space-y-1 md:col-span-2"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Temporary password</span><input name="password" aria-label="Temporary password" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" type="password" autocomplete="new-password" minlength="6" placeholder="Defaults to 123456 if blank"><small class="text-slate-500">The employee should change this after signing in.</small></label>
                    <div class="hr-form-actions md:col-span-2 flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
                        <p id="hr-add-result" class="text-sm font-semibold" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
                        <button id="hr-add-submit" class="primary-btn" type="submit"><i class="bi bi-person-check"></i> Add employee</button>
                    </div>
                </form>
            </div>
        </article>

        <article class="data-panel hr-staff-card">
            <div class="data-panel-head">
                <div><h3>Import standard-profile Staff CSV</h3><p>Add multiple regular/plantilla Staff accounts without granting operational or administrative roles.</p></div>
            </div>
            <div class="data-panel-body space-y-4">
                <div class="hr-import-hint rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-slate-700">
                    <strong class="block text-slate-900">CSV format</strong>
                    <span>Required: <code>name,email</code>. Optional: <code>employee_id</code>. Other columns are rejected. All rows become active Staff users with regular/plantilla employment, the current standard salary grade/step, and a 25% credit limit. Existing email or employee ID matches are skipped, never overwritten. Add Faculty, COS, part-time, or another grade/step individually until their bulk source mapping is approved.</span>
                </div>
                <a class="secondary-btn inline-flex" href="<?= base_url('hr-staff-import-template.csv') ?>" download>Download Staff-only CSV template</a>
                <form id="hr-import-form" class="space-y-4" enctype="multipart/form-data">
                    <label class="field block space-y-1"><span class="text-xs font-semibold uppercase tracking-wide text-slate-500">CSV file</span><input name="csv_file" class="block w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" type="file" accept=".csv,text/csv" required></label>
                    <div class="hr-form-actions flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
                        <p id="hr-import-result" class="text-sm font-semibold" role="status" aria-live="polite" aria-atomic="true" tabindex="-1"></p>
                        <button id="hr-import-submit" class="primary-btn" type="submit"><i class="bi bi-upload"></i> Import staff</button>
                    </div>
                </form>
            </div>
        </article>
    </div>
</section>
<?= $this->endSection() ?>

<?= $this->section('styles') ?>
<link rel="stylesheet" href="<?= base_url('assets/css/hr-staff.css') ?>?v=20261003-hr-feedback">
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/js/hr-staff.js') ?>?v=20261003-hr-feedback"></script>
<?= $this->endSection() ?>
