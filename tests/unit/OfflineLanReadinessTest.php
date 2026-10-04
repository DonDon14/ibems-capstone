<?php

use PHPUnit\Framework\TestCase;

final class OfflineLanReadinessTest extends TestCase
{
    public function testOperationalPagesDoNotDependOnPublicFrontendCdns(): void
    {
        $paths = array_merge(
            glob(APPPATH . 'Views/**/*.php') ?: [],
            glob(FCPATH . 'assets/css/*.css') ?: [],
            glob(FCPATH . 'assets/js/*.js') ?: [],
        );

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            $this->assertStringNotContainsString('cdn.jsdelivr.net', $source, $path);
            $this->assertStringNotContainsString('fonts.googleapis.com', $source, $path);
            $this->assertStringNotContainsString('quickchart.io', $source, $path);
            $this->assertStringNotContainsString('api.qrserver.com', $source, $path);
            $this->assertStringNotContainsString('chart.googleapis.com', $source, $path);
        }
    }

    public function testRequiredOfflineVendorAssetsAreCheckedIn(): void
    {
        foreach ([
            'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
            'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
            'assets/vendor/chart.js/chart.umd.js',
            'assets/vendor/html5-qrcode/html5-qrcode.min.js',
            'assets/vendor/qrcode-generator/qrcode.js',
        ] as $asset) {
            $this->assertFileExists(FCPATH . $asset, $asset);
            $this->assertGreaterThan(0, filesize(FCPATH . $asset), $asset);
        }
    }

}
