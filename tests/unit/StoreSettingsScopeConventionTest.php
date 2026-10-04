<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

final class StoreSettingsScopeConventionTest extends CIUnitTestCase
{
    public function testSettingsExposeAndPersistAnExplicitAssignedStoreSelection(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/store/settings.php');
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js');
        $events = (string) file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');

        $this->assertStringContainsString('id="settings-store-select"', $view);
        $this->assertStringContainsString('belongs only to the selected store', $view);
        $this->assertStringContainsString('searchParams.get("store_id")', $script);
        $this->assertStringContainsString('destination.searchParams.set("store_id", String(selected.id))', $script);
        $this->assertStringContainsString('window.location.assign(destination.href)', $script);
        $this->assertStringContainsString('switchSettingsStore(Number(event.target.value))', $events);
    }

    public function testEverySettingsRequestCarriesTheSelectedStoreId(): void
    {
        $script = (string) file_get_contents(FCPATH . 'assets/js/store-settings.js')
            . (string) file_get_contents(FCPATH . 'assets/js/store-settings.part2.js');

        foreach (['/store/capabilities', '/store/categories', '/store/payment-methods', '/store/payment-accounts'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $script);
        }
        $this->assertGreaterThanOrEqual(10, substr_count($script, 'store_id: settingsStoreId'));
    }
}
