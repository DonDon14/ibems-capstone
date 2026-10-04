<?php $simulationLabel = \App\Services\SimulationClock::portalLabel(); ?>
<?php if ($simulationLabel !== null && getenv('IBEMS_INSTRUCTOR_DEMO') !== '1'): ?>
    <aside class="simulation-notice" aria-label="Simulation environment">
        <strong>Simulation · Synthetic accounts and records</strong>
        <span>Operating time: <?= esc($simulationLabel) ?> (Asia/Manila).</span>
    </aside>
<?php endif ?>
