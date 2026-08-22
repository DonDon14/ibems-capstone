<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UserModalScrollConventionTest extends TestCase
{
    public function testUserModalsKeepScrollingInsideTheRoundedCard(): void
    {
        $view = file_get_contents(APPPATH . 'Views/admin/user-view.php');
        $styles = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertIsString($view);
        $this->assertIsString($styles);
        $this->assertSame(4, substr_count($view, 'admin-modal-card uv-modal-card'));
        $this->assertSame(4, substr_count($view, 'class="uv-modal-scroll"'));
        $this->assertStringNotContainsString('admin-modal-card max-h-[92vh]', $view);
        $this->assertStringContainsString('body .admin-modal .uv-modal-card', $styles);
        $this->assertStringContainsString('overflow: hidden;', $styles);
        $this->assertStringContainsString('.uv-modal-scroll::-webkit-scrollbar-button', $styles);
    }
}
