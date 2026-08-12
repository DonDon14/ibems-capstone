<?php

use App\Libraries\ConventionScanner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ConventionScannerTest extends CIUnitTestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ibems-conventions-' . bin2hex(random_bytes(6));
        mkdir($this->fixtureRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function testReportsStructuralMarkupAndCssDrift(): void
    {
        $this->write('app/Views/admin/example.php', <<<'PHP'
<div id="example-modal" class="admin-modal is-hidden">
    <h2>Example</h2>
    <button class="admin-modal-close">x</button>
    <button class="history-action">Save</button>
    <select id="status"><option>All</option></select>
    <script>window.example = true;</script>
</div>
PHP);
        $this->write('public/assets/css/example.css', '.primary-btn { color: red; }');

        $rules = array_column((new ConventionScanner($this->fixtureRoot))->scan(), 'rule');

        $this->assertContains('view.role_layout', $rules);
        $this->assertContains('view.inline_script', $rules);
        $this->assertContains('modal.source_semantics', $rules);
        $this->assertContains('button.close_name', $rules);
        $this->assertContains('button.legacy_action_variant', $rules);
        $this->assertContains('control.visible_label', $rules);
        $this->assertContains('css.global_selector_owner', $rules);
    }

    public function testAcceptsCompliantViewAndGlobalCssOwner(): void
    {
        $this->write('app/Views/admin/example.php', <<<'PHP'
<?= $this->extend('layouts/admin') ?>
<label for="status">Status</label>
<select id="status"><option>All</option></select>
<div id="example-modal" class="admin-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="example-title">
    <h2 id="example-title">Example</h2>
    <button class="admin-modal-close" aria-label="Close example">x</button>
</div>
PHP);
        $this->write('public/assets/css/app.css', '.primary-btn { color: blue; }');

        $findings = (new ConventionScanner($this->fixtureRoot))->scan();

        $this->assertSame([], $findings);
    }

    public function testReceiptInlineScriptIsAnExplicitException(): void
    {
        $this->write('app/Views/store/receipt.php', <<<'PHP'
<?= $this->extend('layouts/store') ?>
<script>window.print();</script>
PHP);

        $rules = array_column((new ConventionScanner($this->fixtureRoot))->scan(), 'rule');

        $this->assertNotContains('view.inline_script', $rules);
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->fixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
