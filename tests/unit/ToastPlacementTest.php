<?php

use PHPUnit\Framework\TestCase;

final class ToastPlacementTest extends TestCase
{
    public function testToastMessagesAppearAtTopRight(): void
    {
        $css = file_get_contents(ROOTPATH . 'public/assets/css/app.css');

        $this->assertMatchesRegularExpression(
            '/\.toast-stack\s*\{[^}]*position:\s*fixed;[^}]*top:\s*18px;[^}]*right:\s*18px;(?![^}]*bottom:)/s',
            $css
        );
        $this->assertStringContainsString('transform: translateY(-8px);', $css);
    }
}
