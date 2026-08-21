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

    public function testOfficerAndSupervisorUseTheSameAccessiblePeoplePickerPattern(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-stores.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        $this->assertSame(2, substr_count($view, 'class="people-picker"'));
        $this->assertSame(2, substr_count($view, 'role="combobox"'));
        $this->assertStringContainsString('aria-multiselectable="true"', $view);
        $this->assertStringContainsString('.people-picker:focus-within', $styles);
        $this->assertStringContainsString('.people-picker input[type="search"]:focus', $styles);
        $this->assertStringContainsString('padding: 5px 4px 5px 38px', $styles);
        $this->assertStringContainsString('renderSelectedOfficer', $script);
        $this->assertStringContainsString('handlePeoplePickerKeydown', $script);
    }

    public function testStoreManagementKeepsResponsiveCardsWithSortingPaginationAndWarnings(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-stores.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');
        $service = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');

        foreach (['store-sort', 'stores-gallery'] as $id) {
            $this->assertStringContainsString($id, $view);
        }
        foreach (['store-cards-grid', 'store-card-heading', 'store-pagination'] as $selector) {
            $this->assertStringContainsString($selector, $styles);
        }
        foreach (['sortedStoreRows', 'assignmentWarningMarkup', 'storeNeedsAssignment', 'storePaginationMarkup', 'data-store-page', 'store-cards-grid'] as $function) {
            $this->assertStringContainsString($function, $script);
        }
        $this->assertStringContainsString('store-pagination--single', $script);
        $this->assertStringContainsString('Needs assignment', $script);
        $this->assertStringContainsString('class="store-card-action"', $script);
        $this->assertStringNotContainsString('store-edit-icon', $script);
        $this->assertStringNotContainsString('<table class="stores-table">', $script);
        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM store_supervisors', $service);
    }

    public function testStoreModalUsesSectionedFormAndSynchronizedActions(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-stores.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        foreach (['Store information', 'Staff assignments', 'Availability', 'cancel-store-modal', 'store-modal-description'] as $content) {
            $this->assertStringContainsString($content, $view);
        }
        $this->assertStringContainsString('.store-form-section-head', $styles);
        $this->assertStringContainsString('setSelectValue("store-active"', $script);
        $this->assertStringContainsString('dataset.idleLabel', $script);
    }

    public function testStoreModalKeepsChromeFixedAndHidesConditionalTextarea(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-stores.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        $this->assertStringContainsString('admin-stores-modal-body', $view);
        $this->assertStringContainsString('store-deactivation-reason-field" hidden', $view);
        $this->assertStringContainsString('grid-template-rows: auto minmax(0, 1fr) auto', $styles);
        $this->assertStringContainsString('body.ibems-modern .admin-stores-modal .admin-modal-card', $styles);
        $this->assertStringContainsString('.admin-stores-modal .field[hidden]', $styles);
        $this->assertStringContainsString('.field textarea', $styles);
        $this->assertStringContainsString('admin-stores-modal-open', $script);
        $this->assertStringContainsString('reasonField.hidden = !isDeactivating', $script);
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

    public function testStoreDeactivationRequiresReasonAndConfirmation(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        $this->assertStringContainsString('store-deactivation-reason', $view);
        $this->assertStringContainsString('A deactivation reason is required.', $script);
        $this->assertStringContainsString('window.confirm', $script);
        $this->assertStringContainsString('historical records remain available', $script);
    }

    public function testStoreStatusSupportsPostgreSqlBooleanValues(): void
    {
        $service = (string) file_get_contents(APPPATH . 'Services/StoreOversightService.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        $this->assertStringContainsString("\$row['is_active'] = ibems_bool", $service);
        $this->assertStringContainsString('function sBool(value)', $script);
        $this->assertStringContainsString('const isActive = sBool(row.is_active)', $script);
        $this->assertStringNotContainsString('Number(row.is_active) === 1', $script);
        $this->assertStringNotContainsString("'is_active'  => 'boolean'", (string) file_get_contents(APPPATH . 'Models/StoreModel.php'));
        $this->assertStringContainsString("'is_active' => true", (string) file_get_contents(APPPATH . 'Services/StoreLifecycleService.php'));
        $this->assertStringContainsString("'is_active' => false", (string) file_get_contents(APPPATH . 'Services/StoreLifecycleService.php'));
        $this->assertStringContainsString("\$storePayload['is_active'] = \$isActive === 1;", (string) file_get_contents(APPPATH . 'Controllers/AdminController.php'));
    }

    public function testNewStoreDefaultsInactiveAndExplainsActivationRequirements(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/AdminController.php');
        $view = (string) file_get_contents(APPPATH . 'Views/admin/stores.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-stores.js');

        $this->assertStringContainsString("\$isActive = (int) (\$request['is_active'] ?? 0) === 1;", $controller);
        $this->assertStringContainsString('if ($isActive && ($officerId <= 0 || $supervisorIds === []))', $controller);
        $this->assertStringContainsString('mode === "edit" ? sBool(store.is_active) : false', $script);
        $this->assertStringContainsString('formData.append("is_active", String(isActive))', $script);
        $this->assertStringContainsString('Otherwise, choose Inactive and assign them later.', $script);
        $this->assertStringContainsString('New stores default to inactive', $view);
    }
}
