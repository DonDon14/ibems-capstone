<?php

use App\Services\AssetStorageService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Composer\CaBundle\CaBundle;

/**
 * @internal
 */
final class AssetStorageServiceTest extends CIUnitTestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function testSupabaseUploadCreatesPublicBucketAndReturnsPublicUrl(): void
    {
        $requests = [];
        $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
            $requests[] = compact('method', 'url', 'headers', 'body');
            if ($method === 'GET') {
                return ['status' => 404, 'body' => '{}'];
            }

            return ['status' => 200, 'body' => '{}'];
        };
        $service = new AssetStorageService($this->supabaseConfig(), $transport);

        $url = $service->storeImage($this->uploadedPng(), 'product-images');

        $this->assertStringStartsWith(
            'https://project.supabase.co/storage/v1/object/public/ibems-assets/product-images/',
            $url
        );
        $this->assertCount(3, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertSame('POST', $requests[1]['method']);
        $this->assertStringContainsString('"public":true', (string) $requests[1]['body']);
        $this->assertSame('POST', $requests[2]['method']);
        $this->assertContains('apikey: test-secret', $requests[2]['headers']);
        $this->assertNotEmpty($requests[2]['body']);
    }

    public function testSupabaseHttp400NotFoundResponseCreatesBucket(): void
    {
        $requests = [];
        $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
            $requests[] = compact('method', 'url', 'headers', 'body');
            if ($method === 'GET') {
                return ['status' => 400, 'body' => '{"statusCode":"404","error":"Bucket not found","message":"Bucket not found"}'];
            }

            return ['status' => 200, 'body' => '{}'];
        };
        $service = new AssetStorageService($this->supabaseConfig(), $transport);

        $service->ensureBucket();

        $this->assertCount(2, $requests);
        $this->assertSame('POST', $requests[1]['method']);
        $this->assertStringEndsWith('/storage/v1/bucket', $requests[1]['url']);
    }

    public function testSupabaseHttp400AuthorizationErrorIsNotTreatedAsMissingBucket(): void
    {
        $transport = static fn (): array => [
            'status' => 400,
            'body' => '{"error":"Unauthorized","message":"invalid signature"}',
        ];
        $service = new AssetStorageService($this->supabaseConfig(), $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid signature');
        $service->ensureBucket();
    }

    public function testReplacingManagedSupabaseAssetDeletesOldObjectThroughApi(): void
    {
        $requests = [];
        $transport = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
            $requests[] = compact('method', 'url', 'headers', 'body');
            return ['status' => 200, 'body' => '{}'];
        };
        $service = new AssetStorageService($this->supabaseConfig(), $transport);
        $oldUrl = 'https://project.supabase.co/storage/v1/object/public/ibems-assets/store-logos/old.png';

        $service->storeImage($this->uploadedPng(), 'store-logos', $oldUrl);

        $delete = end($requests);
        $this->assertSame('DELETE', $delete['method']);
        $this->assertSame(['prefixes' => ['store-logos/old.png']], json_decode((string) $delete['body'], true));
    }

    public function testSupabaseDriverRejectsMissingSecretBeforeNetworkRequest(): void
    {
        $service = new AssetStorageService([
            'driver' => 'supabase',
            'supabase_url' => 'https://project.supabase.co',
            'secret_key' => '',
            'bucket' => 'ibems-assets',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supabase Storage is not configured');
        $service->ensureBucket();
    }

    public function testCaBundleIsAvailableForVerifiedHttpsRequests(): void
    {
        $this->assertFileExists(CaBundle::getSystemCaRootBundlePath());
    }

    private function supabaseConfig(): array
    {
        return [
            'driver' => 'supabase',
            'supabase_url' => 'https://project.supabase.co',
            'secret_key' => 'test-secret',
            'bucket' => 'ibems-assets',
        ];
    }

    private function uploadedPng(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ibems-image-');
        if ($path === false) {
            throw new RuntimeException('Unable to prepare test upload.');
        }
        $this->tempFiles[] = $path;
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        return new class($path, 'sample.png', 'image/png', filesize($path), UPLOAD_ERR_OK) extends UploadedFile {
            public function isValid(): bool
            {
                return true;
            }
        };
    }
}
