<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class StoreSettingsUiTest extends CIUnitTestCase
{
    public function testCapabilityCardsUseTheApplicationDarkThemeAndCspSafeStatuses(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js') . file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringContainsString('html[data-theme="dark"] .ibems-modern .capability-option>span', $styles);
        $this->assertStringContainsString('html[data-theme="dark"] .ibems-modern .capability-option strong', $styles);
        $this->assertStringNotContainsString('.dark-mode .capability-option', $styles);
        $this->assertStringNotContainsString('.style.color', $script);
        $this->assertStringContainsString('classList.toggle("is-error"', $script);
    }

    public function testPaymentMethodSaveShowsAndClearsAnExclusiveLoadingState(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js') . file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringContainsString('let paymentMethodSavePending = false;', $script);
        $this->assertStringContainsString('function setPaymentMethodSavePending(isPending)', $script);
        $this->assertStringContainsString('Creating payment method...', $script);
        $this->assertStringContainsString('Saving changes...', $script);
        $this->assertStringContainsString('control.disabled = paymentMethodSavePending;', $script);
        $this->assertStringContainsString('if (paymentMethodSavePending) return;', $script);
        $this->assertStringContainsString('setPaymentMethodSavePending(false);', $script);
        $this->assertStringContainsString('.settings-submit-spinner', $styles);
        $this->assertStringContainsString('@keyframes settings-submit-spin', $styles);
    }

    public function testCategoryManagementShowsUsageAndProtectedDefaultActions(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/settings.php');
        $controller = (string) file_get_contents(APPPATH . 'Controllers/StoreController.php')
            . (string) file_get_contents(APPPATH . 'Services/StoreCatalogQueryService.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js') . file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringContainsString('id="category-count"', $view);
        $this->assertStringContainsString('id="category-search"', $view);
        $this->assertStringContainsString("'colspan' => 3", $view);
        $this->assertStringContainsString("'product_count' =>", $controller);
        $this->assertStringContainsString('const categoryPageSize = 10;', $script);
        $this->assertStringContainsString('category-default-badge', $script);
        $this->assertStringContainsString('setCategoryMutationPending(true', $script);
        $this->assertStringContainsString('payment-status-badge', $script);
        $this->assertStringContainsString('payment-method-destination', $script);
        $this->assertStringContainsString('.category-delete-btn', $styles);
        $this->assertStringContainsString('.payment-status-badge.is-ready', $styles);
    }

    public function testCategoryUpdateUsesTheDedicatedAccessibleEditor(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/settings.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js') . file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringContainsString('id="category-edit-modal"', $view);
        $this->assertStringContainsString('aria-labelledby="category-edit-title"', $view);
        $this->assertStringContainsString('Products are not deleted.', $view);
        $this->assertStringContainsString('function openCategoryEditModal(categoryId, trigger = null)', $script);
        $this->assertStringContainsString('syncCategoryEditSaveState()', $script);
        $this->assertStringContainsString('event.key === "Enter"', $script);
        $this->assertStringContainsString('event.key !== "Escape"', $script);
        $this->assertStringNotContainsString('IbemsDialog.prompt("Change the category name used by products in this store."', $script);
        $this->assertStringContainsString('.category-edit-card', $styles);
        $this->assertStringContainsString('.category-edit-result.is-error', $styles);
    }

    public function testCategoryCreationUsesTheSameModalPatternAsPaymentMethods(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/settings.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js') . file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/store-settings.css');

        $this->assertStringNotContainsString('id="new-category-name"', $view);
        $this->assertStringContainsString('class="category-head-actions"', $view);
        $this->assertStringContainsString('function openCategoryCreateModal(trigger = null)', $script);
        $this->assertStringContainsString('async function createCategory(nextName)', $script);
        $this->assertStringContainsString('Creating category...', $script);
        $this->assertStringContainsString('openCategoryCreateModal(event.currentTarget)', $script);
        $this->assertStringContainsString('await createCategory(nextName);', $script);
        $this->assertStringContainsString('.category-head-actions', $styles);
    }
}
