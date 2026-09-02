<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreCapabilityModel;
use App\Models\StoreCategoryModel;
use App\Models\StorePaymentMethodModel;
use Config\Database;

final class StoreConfigurationService
{
    public function updateCapabilities(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, ['status' => 'error', 'message' => 'You cannot update this store.']);
        }
        $capabilities = ProductBehaviorService::normalizeCapabilities($request['capabilities'] ?? []);
        $db = Database::connect();
        if (!$db->tableExists('store_capabilities')) {
            return $this->result(503, ['status' => 'error', 'message' => 'Apply the latest database migration before configuring operations.']);
        }
        $db->transBegin();
        try {
            (new StoreCapabilityModel())->syncForStore($storeId, $capabilities);
            (new AuditLogModel())->insert([
                'actor_id' => $actorId,
                'action' => 'UPDATE_STORE_CAPABILITIES',
                'entity' => 'stores',
                'entity_id' => $storeId,
                'payload_json' => json_encode(['capabilities' => $capabilities]),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$db->transStatus()) {
                throw new \RuntimeException('Could not save store capabilities.');
            }
            $db->transCommit();
            return $this->result(200, ['status' => 'success', 'capabilities' => $capabilities]);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->result(500, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function createCategory(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));

        if ($name === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Category name is required.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $categoryId = $categoryModel->ensureCategory((int) $store['id'], $name);
        $created = $categoryModel->find($categoryId);

        return $this->result(200, [
            'status' => 'success',
            'category' => [
                'id' => (int) $created['id'],
                'name' => (string) $created['name'],
                'sort_order' => (int) ($created['sort_order'] ?? 0),
            ],
        ]);
    }

    public function updateCategory(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $categoryId = (int) ($request['category_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));

        if ($categoryId <= 0 || $name === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid category update payload.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $category = $categoryModel->find($categoryId);
        if (!$category || (int) $category['store_id'] !== (int) $store['id']) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Category not found.',
            ]);
        }

        $duplicate = $categoryModel->where('store_id', (int) $store['id'])
            ->where('name', $name)
            ->where('id !=', $categoryId)
            ->first();
        if ($duplicate) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Category name already exists.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $categoryModel->update($categoryId, [
            'name' => $name,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $db->table('products')
            ->where('store_id', (int) $store['id'])
            ->where('category', (string) $category['name'])
            ->update([
                'category' => $name,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to update category.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
        ]);
    }

    public function deleteCategory(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $categoryId = (int) ($request['category_id'] ?? 0);

        if ($categoryId <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Category id is required.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $categoryModel = new StoreCategoryModel();
        $category = $categoryModel->find($categoryId);
        if (!$category || (int) $category['store_id'] !== (int) $store['id']) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Category not found.',
            ]);
        }

        if (strtolower((string) $category['name']) === 'general') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'General category cannot be deleted.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $db->table('products')
            ->where('store_id', (int) $store['id'])
            ->where('category', (string) $category['name'])
            ->update([
                'category' => 'General',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $categoryModel->delete($categoryId);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to delete category.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
        ]);
    }

    public function createPaymentMethod(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $label = trim((string) ($request['label'] ?? ''));
        $iconClass = trim((string) ($request['icon_class'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));

        if ($label === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Payment method label is required.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $model->ensureDefaults((int) $store['id']);

        $requestedCode = trim((string) ($request['code'] ?? ''));
        $code = $model->normalizeCode($requestedCode !== '' ? $requestedCode : $label);
        if ($code === '' || $code === 'debt') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid payment method code.',
            ]);
        }

        $existing = $model->where('store_id', (int) $store['id'])
            ->where('code', $code)
            ->first();

        if ($existing) {
            if (ibems_bool($existing['is_system_reserved'] ?? false)) {
                return $this->result(400, [
                    'status' => 'error',
                    'message' => 'System payment method cannot be recreated.',
                ]);
            }

            $model->update((int) $existing['id'], [
                'label' => $label,
                'icon_class' => $iconClass !== '' ? $iconClass : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'is_active' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $maxSort = $model->where('store_id', (int) $store['id'])->selectMax('sort_order')->first();
            $nextSort = (int) ($maxSort['sort_order'] ?? 0) + 10;

            $model->insert([
                'store_id' => (int) $store['id'],
                'code' => $code,
                'label' => $label,
                'icon_class' => $iconClass !== '' ? $iconClass : null,
                'image_url' => $imageUrl !== '' ? $imageUrl : null,
                'sort_order' => $nextSort,
                'is_active' => true,
                'is_system_reserved' => false,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $saved = $model->where('store_id', (int) $store['id'])->where('code', $code)->first();
        return $this->result(200, [
            'status' => 'success',
            'method' => $saved ? ['id' => (int) $saved['id'], 'code' => (string) $saved['code'], 'label' => (string) $saved['label']] : null,
        ]);
    }

    public function updatePaymentMethod(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $methodId = (int) ($request['method_id'] ?? 0);
        $label = trim((string) ($request['label'] ?? ''));
        $iconClass = trim((string) ($request['icon_class'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));

        if ($methodId <= 0 || $label === '') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid payment method update payload.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $method = $model->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id']) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Payment method not found.',
            ]);
        }

        if ((string) $method['code'] === 'debt') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Debt payment method is protected.',
            ]);
        }

        $model->update($methodId, [
            'label' => $label,
            'icon_class' => $iconClass !== '' ? $iconClass : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->result(200, [
            'status' => 'success',
        ]);
    }

    public function deletePaymentMethod(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $methodId = (int) ($request['method_id'] ?? 0);

        if ($methodId <= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Payment method id is required.',
            ]);
        }

        $store = (new StoreAccessService())->resolve($actorId, $role, $storeId);
        if (!$store) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot access this store.',
            ]);
        }

        $model = new StorePaymentMethodModel();
        $method = $model->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id']) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Payment method not found.',
            ]);
        }

        if (ibems_bool($method['is_system_reserved'] ?? false) || (string) $method['code'] === 'debt') {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'This payment method is protected.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();
        $model->update($methodId, [
            'is_active' => false,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($db->tableExists('payment_destination_accounts')) {
            $db->table('payment_destination_accounts')
                ->where('store_id', (int) $store['id'])
                ->where('payment_method_id', $methodId)
                ->update(['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        $db->transComplete();
        if ($db->transStatus() === false) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Unable to delete payment method.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'method_id' => $methodId,
        ]);
    }

    public function savePaymentAccount(array $request, int $actorId, string $role): array
    {
        $store = (new StoreAccessService())->resolve($actorId, $role, (int) ($request['store_id'] ?? 0));
        if (!$store) return $this->result(403, ['status' => 'error', 'message' => 'You cannot access this store.']);
        $methodId = (int) ($request['payment_method_id'] ?? 0);
        $name = trim((string) ($request['account_name'] ?? ''));
        $number = trim((string) ($request['account_number'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));
        if ($methodId <= 0 || $name === '' || $number === '') return $this->result(400, ['status' => 'error', 'message' => 'Payment method, account name, and account number are required.']);
        $method = (new StorePaymentMethodModel())->find($methodId);
        if (!$method || (int) $method['store_id'] !== (int) $store['id'] || in_array((string) $method['code'], ['cash', 'debt'], true)) {
            return $this->result(400, ['status' => 'error', 'message' => 'Select a non-cash receiving method.']);
        }
        if ($imageUrl !== '' && !$this->isAllowedImageUrl($imageUrl)) {
            return $this->result(400, ['status' => 'error', 'message' => 'QR image must be a valid HTTPS or application URL.']);
        }
        $model = new PaymentDestinationAccountModel();
        $accountId = (int) ($request['account_id'] ?? 0);
        $existing = $accountId > 0 ? $model->find($accountId) : null;
        if ($existing && (int) $existing['store_id'] !== (int) $store['id']) return $this->result(404, ['status' => 'error', 'message' => 'Payment account not found.']);
        $payload = ['store_id' => (int) $store['id'], 'payment_method_id' => $methodId, 'account_name' => $name,
            'account_number' => $number, 'image_url' => $imageUrl !== '' ? $imageUrl : null, 'is_active' => true, 'updated_at' => date('Y-m-d H:i:s')];
        if ($existing) $model->update($accountId, $payload); else { $payload['created_at'] = date('Y-m-d H:i:s'); $model->insert($payload); }
        return $this->result(200, ['status' => 'success']);
    }

    public function deactivatePaymentAccount(array $request, int $actorId, string $role): array
    {
        $store = (new StoreAccessService())->resolve($actorId, $role, (int) ($request['store_id'] ?? 0));
        if (!$store) return $this->result(403, ['status' => 'error', 'message' => 'You cannot access this store.']);
        $model = new PaymentDestinationAccountModel();
        $row = $model->find((int) ($request['account_id'] ?? 0));
        if (!$row || (int) $row['store_id'] !== (int) $store['id']) return $this->result(404, ['status' => 'error', 'message' => 'Payment account not found.']);
        $model->update((int) $row['id'], ['is_active' => false, 'updated_at' => date('Y-m-d H:i:s')]);
        return $this->result(200, ['status' => 'success']);
    }

    private function isAllowedImageUrl(string $url): bool
    {
        if (str_starts_with($url, '/uploads/')) {
            return !str_contains($url, '..') && !str_contains($url, "\\");
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
