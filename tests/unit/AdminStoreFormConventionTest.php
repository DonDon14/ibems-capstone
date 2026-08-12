<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class AdminStoreFormConventionTest extends CIUnitTestCase
{
    public function testStorePickerAvoidsDuplicateClearButtons(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');

        $this->assertStringNotContainsString('clear-store-officer', $view);
        $this->assertStringNotContainsString('clear-store-supervisor-search', $view);
        $this->assertStringContainsString('data-remove-supervisor', (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js'));
    }

    public function testStoreLogoMatchesTheProductMediaInputPattern(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        foreach (['store-logo-source', 'store-logo-file', 'store-logo-url', 'store-logo-preview'] as $id) {
            $this->assertStringContainsString($id, $view);
        }
        $this->assertStringContainsString('updateStoreLogoPreview', $script);
        $this->assertStringContainsString('URL.createObjectURL', $script);
        $this->assertStringContainsString('URL.revokeObjectURL', $script);
    }

    public function testPortalRoleTagsUseReadableLabels(): void
    {
        $shell = (string) file_get_contents(APPPATH . 'Views/components/portal_shell.php');

        $this->assertStringContainsString("'STORE_SUPERVISOR' => 'Store Supervisor'", $shell);
        $this->assertStringContainsString("'STORE_SYSTEM' => 'Store Officer'", $shell);
        $this->assertStringContainsString('$readableRole', $shell);
    }
}
