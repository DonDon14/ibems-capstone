<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use Composer\CaBundle\CaBundle;

class AssetStorageService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_FILE_SIZE = 2 * 1024 * 1024;
    private const EVIDENCE_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_EVIDENCE_FILE_SIZE = 5 * 1024 * 1024;

    private string $driver;
    private string $supabaseUrl;
    private string $secretKey;
    private string $bucket;
    private string $privateBucket;
    private $transport;
    /** @var array<string, bool> */
    private array $readyBuckets = [];

    public function __construct(?array $config = null, ?callable $transport = null)
    {
        $config ??= [
            'driver' => getenv('IBEMS_ASSET_STORAGE_DRIVER') ?: 'local',
            'supabase_url' => getenv('IBEMS_SUPABASE_URL') ?: '',
            'secret_key' => getenv('IBEMS_SUPABASE_SECRET_KEY') ?: '',
            'bucket' => getenv('IBEMS_SUPABASE_STORAGE_BUCKET') ?: 'ibems-assets',
            'private_bucket' => getenv('IBEMS_SUPABASE_PRIVATE_STORAGE_BUCKET') ?: 'ibems-private',
        ];

        $this->driver = strtolower(trim((string) ($config['driver'] ?? 'local')));
        $this->supabaseUrl = rtrim(trim((string) ($config['supabase_url'] ?? '')), '/');
        $this->secretKey = trim((string) ($config['secret_key'] ?? ''));
        $this->bucket = trim((string) ($config['bucket'] ?? 'ibems-assets'));
        $this->privateBucket = trim((string) ($config['private_bucket'] ?? 'ibems-private'));
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
        $this->ensureSupabaseBucket($this->bucket, true, self::MAX_FILE_SIZE, self::ALLOWED_MIME_TYPES);
    }

    public function ensurePrivateBucket(): void
    {
        $this->ensureSupabaseBucket($this->privateBucket, false, self::MAX_EVIDENCE_FILE_SIZE, self::EVIDENCE_MIME_TYPES);
    }

    /**
     * @return array{stored_name: string, file_size: int, sha256: string}
     */
    public function storeEvidence(UploadedFile $file, string $folder): array
    {
        $this->validateEvidence($file);
        $folder = $this->normalizeFolder($folder);
        $fileName = $file->getRandomName();
        $contents = file_get_contents($file->getTempName());
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the uploaded evidence file.');
        }

        if ($this->driver === 'supabase') {
            $this->ensurePrivateBucket();
            $this->uploadObject($this->privateBucket, $folder . '/' . $fileName, $contents, (string) $file->getMimeType());
            $storedName = 'supabase:' . $fileName;
        } elseif ($this->driver === 'local') {
            $directory = WRITEPATH . 'private/' . str_replace('/', DIRECTORY_SEPARATOR, $folder);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Evidence storage is unavailable.');
            }
            $file->move($directory, $fileName);
            $storedName = $fileName;
        } else {
            throw new \RuntimeException("Unsupported asset storage driver '{$this->driver}'.");
        }

        return [
            'stored_name' => $storedName,
            'file_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function readEvidence(string $storedName, string $folder): string
    {
        $folder = $this->normalizeFolder($folder);
        if (str_starts_with($storedName, 'supabase:')) {
            $this->assertSupabaseConfigured($this->privateBucket);
            $fileName = basename(substr($storedName, strlen('supabase:')));
            $response = $this->request('GET', $this->storageEndpoint(
                '/object/' . rawurlencode($this->privateBucket) . '/' . $this->encodeObjectPath($folder . '/' . $fileName)
            ));
            if ($response['status'] !== 200) {
                throw new \RuntimeException('Evidence file is unavailable in Supabase Storage (HTTP ' . $response['status'] . ').');
            }

            return (string) $response['body'];
        }

        $path = WRITEPATH . 'private/' . str_replace('/', DIRECTORY_SEPARATOR, $folder)
            . DIRECTORY_SEPARATOR . basename($storedName);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException('Evidence file is missing.');
        }

        return $contents;
    }

    public function deleteEvidence(string $storedName, string $folder): void
    {
        $folder = $this->normalizeFolder($folder);
        if (str_starts_with($storedName, 'supabase:')) {
            $fileName = basename(substr($storedName, strlen('supabase:')));
            $this->deleteSupabaseObject($this->privateBucket, $folder . '/' . $fileName);
            return;
        }

        $path = WRITEPATH . 'private/' . str_replace('/', DIRECTORY_SEPARATOR, $folder)
            . DIRECTORY_SEPARATOR . basename($storedName);
        if (is_file($path)) {
            @unlink($path);
        }
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

    private function validateEvidence(UploadedFile $file): void
    {
        if (!$file->isValid() || $file->hasMoved()) {
            throw new \RuntimeException('A valid evidence file is required.');
        }

        if (!in_array((string) $file->getMimeType(), self::EVIDENCE_MIME_TYPES, true)
            || (int) $file->getSize() <= 0
            || (int) $file->getSize() > self::MAX_EVIDENCE_FILE_SIZE) {
            throw new \RuntimeException('Evidence must be a PDF, JPG, or PNG file up to 5 MB.');
        }
    }

    private function uploadToSupabase(UploadedFile $file, string $objectPath): string
    {
        $this->ensureBucket();
        $contents = file_get_contents($file->getTempName());
        if ($contents === false) {
            throw new \RuntimeException('Unable to read the uploaded image.');
        }

        $this->uploadObject($this->bucket, $objectPath, $contents, (string) $file->getMimeType());

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

        $this->deleteSupabaseObject($this->bucket, rawurldecode(substr($url, strlen($prefix))));
    }

    private function assertSupabaseConfigured(?string $bucket = null): void
    {
        if ($this->supabaseUrl === '' || $this->secretKey === '' || trim((string) ($bucket ?? $this->bucket)) === '') {
            throw new \RuntimeException('Supabase Storage is not configured. Set the server-side storage environment variables.');
        }

        if (!str_starts_with($this->supabaseUrl, 'https://')) {
            throw new \RuntimeException('Supabase Storage URL must use HTTPS.');
        }
    }

    /** @param list<string> $allowedMimeTypes */
    private function ensureSupabaseBucket(string $bucket, bool $public, int $fileSizeLimit, array $allowedMimeTypes): void
    {
        $this->assertSupabaseConfigured($bucket);
        if (isset($this->readyBuckets[$bucket])) {
            return;
        }

        $response = $this->request('GET', $this->storageEndpoint('/bucket/' . rawurlencode($bucket)));
        if ($response['status'] === 200) {
            $this->readyBuckets[$bucket] = true;
            return;
        }
        if (!$this->isMissingResourceResponse($response)) {
            throw new \RuntimeException(
                'Unable to verify the Supabase Storage bucket (HTTP ' . $response['status'] . $this->responseDetail($response) . ').'
            );
        }

        $response = $this->request('POST', $this->storageEndpoint('/bucket'), json_encode([
            'id' => $bucket,
            'name' => $bucket,
            'public' => $public,
            'file_size_limit' => $fileSizeLimit,
            'allowed_mime_types' => $allowedMimeTypes,
        ], JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
        if (!in_array($response['status'], [200, 201], true)) {
            throw new \RuntimeException('Unable to create the Supabase Storage bucket (HTTP ' . $response['status'] . ').');
        }
        $this->readyBuckets[$bucket] = true;
    }

    private function uploadObject(string $bucket, string $objectPath, string $contents, string $mimeType): void
    {
        $response = $this->request(
            'POST',
            $this->storageEndpoint('/object/' . rawurlencode($bucket) . '/' . $this->encodeObjectPath($objectPath)),
            $contents,
            ['Content-Type: ' . $mimeType, 'x-upsert: false']
        );
        if (!in_array($response['status'], [200, 201], true)) {
            throw new \RuntimeException('Unable to upload the file to Supabase Storage (HTTP ' . $response['status'] . ').');
        }
    }

    private function deleteSupabaseObject(string $bucket, string $objectPath): void
    {
        $response = $this->request(
            'DELETE',
            $this->storageEndpoint('/object/' . rawurlencode($bucket)),
            json_encode(['prefixes' => [$objectPath]], JSON_THROW_ON_ERROR),
            ['Content-Type: application/json']
        );
        if (!in_array($response['status'], [200, 204], true)) {
            log_message('warning', 'Supabase Storage cleanup failed with HTTP {status}.', ['status' => $response['status']]);
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
