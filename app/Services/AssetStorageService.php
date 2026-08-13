<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Composer\CaBundle\CaBundle;

class AssetStorageService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_FILE_SIZE = 2 * 1024 * 1024;

    private string $driver;
    private string $supabaseUrl;
    private string $secretKey;
    private string $bucket;
    private $transport;
    private bool $bucketReady = false;

    public function __construct(?array $config = null, ?callable $transport = null)
    {
        $config ??= [
            'driver' => getenv('IBEMS_ASSET_STORAGE_DRIVER') ?: 'local',
            'supabase_url' => getenv('IBEMS_SUPABASE_URL') ?: '',
            'secret_key' => getenv('IBEMS_SUPABASE_SECRET_KEY') ?: '',
            'bucket' => getenv('IBEMS_SUPABASE_STORAGE_BUCKET') ?: 'ibems-assets',
        ];

        $this->driver = strtolower(trim((string) ($config['driver'] ?? 'local')));
        $this->supabaseUrl = rtrim(trim((string) ($config['supabase_url'] ?? '')), '/');
        $this->secretKey = trim((string) ($config['secret_key'] ?? ''));
        $this->bucket = trim((string) ($config['bucket'] ?? 'ibems-assets'));
        $this->transport = $transport;
    }

    public function storeImage(UploadedFile $file, string $folder, ?string $currentUrl = null): string
    {
        $this->validateImage($file);
        $folder = $this->normalizeFolder($folder);
        $fileName = $file->getRandomName();

        if ($this->driver === 'supabase') {
            $storedUrl = $this->uploadToSupabase($file, $folder . '/' . $fileName);
        } elseif ($this->driver === 'local') {
            $storedUrl = $this->moveToLocalStorage($file, $folder, $fileName);
        } else {
            throw new \RuntimeException("Unsupported asset storage driver '{$this->driver}'.");
        }

        if ($currentUrl && $currentUrl !== $storedUrl) {
            $this->deleteManagedAsset($currentUrl);
        }

        return $storedUrl;
    }

    public function ensureBucket(): void
    {
        $this->assertSupabaseConfigured();
        if ($this->bucketReady) {
            return;
        }

        $bucketUrl = $this->storageEndpoint('/bucket/' . rawurlencode($this->bucket));
        $response = $this->request('GET', $bucketUrl);
        if ($response['status'] === 200) {
            $this->bucketReady = true;
            return;
        }

        if (!$this->isMissingResourceResponse($response)) {
            throw new \RuntimeException(
                'Unable to verify the Supabase Storage bucket (HTTP ' . $response['status'] . $this->responseDetail($response) . ').'
            );
        }

        $response = $this->request('POST', $this->storageEndpoint('/bucket'), json_encode([
            'id' => $this->bucket,
            'name' => $this->bucket,
            'public' => true,
            'file_size_limit' => self::MAX_FILE_SIZE,
            'allowed_mime_types' => self::ALLOWED_MIME_TYPES,
        ], JSON_THROW_ON_ERROR), ['Content-Type: application/json']);

        if (!in_array($response['status'], [200, 201], true)) {
            throw new \RuntimeException('Unable to create the Supabase Storage bucket (HTTP ' . $response['status'] . ').');
        }

        $this->bucketReady = true;
    }

    /**
     * Supabase Storage returns HTTP 400 for some missing-resource responses,
     * even though the JSON body identifies the bucket as not found.
     */
    private function isMissingResourceResponse(array $response): bool
    {
        if ($response['status'] === 404) {
            return true;
        }

        if ($response['status'] !== 400) {
            return false;
        }

        $body = strtolower((string) ($response['body'] ?? ''));

        return str_contains($body, 'not found') || str_contains($body, 'does not exist');
    }

    private function responseDetail(array $response): string
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return '';
        }

        $detail = trim((string) ($decoded['message'] ?? $decoded['error'] ?? ''));
        if ($detail === '') {
            return '';
        }

        return ': ' . substr(preg_replace('/[\r\n]+/', ' ', $detail) ?? '', 0, 180);
    }

    public function getBucketName(): string
    {
        return $this->bucket;
    }

    private function validateImage(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('Invalid uploaded image file.');
        }

        if (!in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new \RuntimeException('Image must be JPG, PNG, WEBP, or GIF.');
        }

        if ((int) $file->getSize() > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('Image file size must be 2MB or less.');
        }
    }

    private function uploadToSupabase(UploadedFile $file, string $objectPath): string
    {
        $this->ensureBucket();
        $contents = file_get_contents($file->getTempName());
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the uploaded image.');
        }

        $url = $this->storageEndpoint('/object/' . rawurlencode($this->bucket) . '/' . $this->encodeObjectPath($objectPath));
        $response = $this->request('POST', $url, $contents, [
            'Content-Type: ' . $file->getMimeType(),
            'x-upsert: false',
        ]);

        if (!in_array($response['status'], [200, 201], true)) {
            throw new \RuntimeException('Unable to upload the image to Supabase Storage (HTTP ' . $response['status'] . ').');
        }

        return $this->storageEndpoint('/object/public/' . rawurlencode($this->bucket) . '/' . $this->encodeObjectPath($objectPath));
    }

    private function moveToLocalStorage(UploadedFile $file, string $folder, string $fileName): string
    {
        $uploadDir = FCPATH . 'uploads/' . $folder;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Failed to prepare the local upload directory.');
        }

        $file->move($uploadDir, $fileName);

        return '/uploads/' . $folder . '/' . $fileName;
    }

    private function deleteManagedAsset(string $url): void
    {
        if (str_starts_with($url, '/uploads/')) {
            $localPath = FCPATH . ltrim($url, '/');
            if (is_file($localPath)) {
                @unlink($localPath);
            }
            return;
        }

        $prefix = $this->storageEndpoint('/object/public/' . rawurlencode($this->bucket) . '/');
        if ($this->driver !== 'supabase' || !str_starts_with($url, $prefix)) {
            return;
        }

        $objectPath = rawurldecode(substr($url, strlen($prefix)));
        $response = $this->request(
            'DELETE',
            $this->storageEndpoint('/object/' . rawurlencode($this->bucket)),
            json_encode(['prefixes' => [$objectPath]], JSON_THROW_ON_ERROR),
            ['Content-Type: application/json']
        );

        if (!in_array($response['status'], [200, 204], true)) {
            log_message('warning', 'Supabase Storage cleanup failed for a replaced asset with HTTP {status}.', [
                'status' => $response['status'],
            ]);
        }
    }

    private function assertSupabaseConfigured(): void
    {
        if ($this->supabaseUrl === '' || $this->secretKey === '' || $this->bucket === '') {
            throw new \RuntimeException('Supabase Storage is not configured. Set the server-side storage environment variables.');
        }

        if (!str_starts_with($this->supabaseUrl, 'https://')) {
            throw new \RuntimeException('Supabase Storage URL must use HTTPS.');
        }
    }

    private function request(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        $headers[] = 'apikey: ' . $this->secretKey;
        $headers[] = 'Authorization: Bearer ' . $this->secretKey;

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize the Supabase Storage request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CAINFO => CaBundle::getSystemCaRootBundlePath(),
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new \RuntimeException('Supabase Storage request failed: ' . $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function storageEndpoint(string $path): string
    {
        return $this->supabaseUrl . '/storage/v1' . $path;
    }

    private function encodeObjectPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder === '' || preg_match('#(^|/)\.\.?(/|$)#', $folder)) {
            throw new \RuntimeException('Invalid asset folder.');
        }

        return $folder;
    }
}
