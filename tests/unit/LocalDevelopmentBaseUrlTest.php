<?php

use Config\LocalDevelopmentBaseUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalDevelopmentBaseUrlTest extends TestCase
{
    public static function localHostProvider(): iterable
    {
        yield 'localhost with custom port' => ['localhost:8083', 'http://localhost:8083/'];
        yield 'localhost with default port' => ['localhost', 'http://localhost/'];
        yield 'IPv4 loopback' => ['127.0.0.1:8081', 'http://127.0.0.1:8081/'];
        yield 'IPv6 loopback' => ['[::1]:8083', 'http://[::1]:8083/'];
    }

    #[DataProvider('localHostProvider')]
    public function testUsesTheActualLocalDevelopmentHost(string $host, string $expected): void
    {
        $this->assertSame($expected, LocalDevelopmentBaseUrl::resolve('development', $host));
    }

    public function testPreservesLocalHttpsWhenPresent(): void
    {
        $this->assertSame(
            'https://localhost:8443/',
            LocalDevelopmentBaseUrl::resolve('development', 'localhost:8443', 'on'),
        );
    }

    public function testDoesNotOverrideNonDevelopmentEnvironments(): void
    {
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('production', 'localhost:8083'));
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('testing', 'localhost:8083'));
    }

    public function testRejectsUntrustedOrInvalidHosts(): void
    {
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('development', 'example.com:8083'));
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('development', 'localhost.evil.test:8083'));
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('development', 'localhost:70000'));
        $this->assertNull(LocalDevelopmentBaseUrl::resolve('development', null));
    }
}
