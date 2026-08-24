<?php

use PHPUnit\Framework\TestCase;

final class AuditModalUiConventionTest extends TestCase
{
    public function testAuditModalUsesOneInsetVerticalScrollRegion(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/admin/audit.php');
        $styles = (string) file_get_contents(FCPATH . 'assets/css/admin-overview.css');
        $sharedStyles = (string) file_get_contents(FCPATH . 'assets/css/app.css');
        $script = (string) file_get_contents(FCPATH . 'assets/js/admin-audit.js');

        $this->assertStringContainsString('app-inset-modal-card audit-view-card', $view);
        $this->assertStringContainsString('class="app-inset-modal-scroll audit-detail-grid"', $view);
        $this->assertStringNotContainsString('w-[min(840px,95vw)] overflow-y-auto', $view);
        $this->assertStringContainsString('overflow-y: visible;', $styles);
        $this->assertStringContainsString('.audit-payload-block pre::-webkit-scrollbar-button', $styles);
        $this->assertStringContainsString('scrollbar-width: thin;', $sharedStyles);
        $this->assertStringContainsString('::-webkit-scrollbar-button:vertical:start:decrement', $sharedStyles);
        $this->assertStringContainsString('document.body.classList.add("audit-modal-open")', $script);
        $this->assertStringContainsString('document.body.classList.remove("audit-modal-open")', $script);
    }
}
