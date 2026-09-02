<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\StoreCategoryModel;
use App\Models\StoreSupervisorModel;
use App\Models\UserModel;
use App\Models\UserRoleModel;
use App\Models\DebtCashbookEntryModel;
use App\Services\StoreOversightService;
use App\Services\AssetStorageService;
use App\Services\DashboardAlertPagination;
use App\Services\SalaryCreditPolicy;
use App\Services\SalaryScheduleService;
use App\Services\AdminDashboardService;
use App\Services\AdminProductService;
use App\Services\AdminDebtQueryService;
use App\Services\AdminAuditQueryService;
use App\Services\AdminUserService;
use CodeIgniter\Controller;
use Config\Database;

class AdminController extends Controller
{

    public function dashboard()
    {
        return view('admin/dashboard');
    }

    public function dashboardData()
    {
        return $this->response->setJSON((new AdminDashboardService())->data(
            (int) ($this->request->getGet('alerts_page') ?? 1),
            (string) ($this->request->getGet('period') ?? 'day')
        ));
    }

    public function storeOps()
    {
        return redirect()->to('/admin/stores');
    }

    public function accountingDebts()
    {
        return view('admin/accounting-debts');
    }

    public function userView()
    {
        return view('admin/user-view');
    }

    public function products()
    {
        return view('admin/products');
    }

    public function salarySchedules()
    {
        $catalog = (new SalaryScheduleService())->catalog(Database::connect());
        if ($catalog === []) {
            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'error',
                'message' => 'Salary schedules are unavailable. Apply the latest development database migration, then refresh this page.',
            ]);
        }
        return $this->response->setJSON([
            'status' => 'success',
            'data' => $catalog,
            'default_credit_percentage' => SalaryCreditPolicy::percentageFromRate(SalaryCreditPolicy::DEFAULT_CREDIT_RATE),
        ]);
    }

    public function audit()
    {
        return view('admin/audit');
    }

    public function auditData()
    {
        $filters = $this->request->getGet();
        $result = (new AdminAuditQueryService())->data(is_array($filters) ? $filters : []);

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function productsData()
    {
        $filters = $this->request->getGet();
        $result = (new AdminProductService())->data(is_array($filters) ? $filters : []);

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function updateProduct()
    {
        $result = (new AdminProductService())->update(
            $this->getRequestData(),
            (int) session()->get('user_id'),
            $this->request->getFile('image_file')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function createProduct()
    {
        $result = (new AdminProductService())->create(
            $this->getRequestData(),
            (int) session()->get('user_id'),
            $this->request->getFile('image_file')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function toggleProductStatus()
    {
        $result = (new AdminProductService())->toggleStatus(
            $this->getRequestData(),
            (int) session()->get('user_id')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function accountingDebtsData()
    {
        $result = (new AdminDebtQueryService())->summary();

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function accountingDebtUserDetail(int $userId)
    {
        $filters = $this->request->getGet();
        $result = (new AdminDebtQueryService())->detail($userId, is_array($filters) ? $filters : []);

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function userViewData()
    {
        $result = (new AdminUserService())->data(trim((string) $this->request->getGet('q')));

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function userViewDetail(int $userId)
    {
        $result = (new AdminUserService())->detail($userId);

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function createUser()
    {
        $result = (new AdminUserService())->create(
            $this->getRequestData(),
            (int) session()->get('user_id'),
            $this->request->getFile('profile_image')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function updateUser()
    {
        $result = (new AdminUserService())->update(
            $this->getRequestData(),
            (int) session()->get('user_id'),
            $this->request->getFile('profile_image')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function importUsersCsv()
    {
        $result = (new AdminUserService())->importCsv(
            $this->request->getFile('csv_file'),
            (int) session()->get('user_id')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function stores()
    {
        return view('admin/stores', [
            'canManageStores' => ibems_current_role() === 'ADMIN',
        ]);
    }

    public function storesData()
    {
        return (new StoreOversightService())->storesData($this->request, $this->response);
    }

    public function storeDetails(int $storeId)
    {
        if ($storeId <= 0) {
            return redirect()->to('/admin/stores');
        }
        if (!$this->canAccessStoreForAdminArea($storeId)) {
            return redirect()->to('/admin/stores');
        }

        return view('admin/store-details', ['storeId' => $storeId]);
    }

    public function storeDetailsData(int $storeId)
    {
        return (new StoreOversightService())->storeDetailsData($this->request, $this->response, $storeId);
    }

    public function reviewStoreDayVariance(int $sessionId)
    {
        return (new StoreOversightService())->reviewStoreDayVariance($this->request, $this->response, $sessionId);
    }

    public function resolveStaleStoreDay(int $sessionId)
    {
        return (new StoreOversightService())->resolveStaleStoreDay($this->request, $this->response, $sessionId);
    }

    public function uploadVarianceCaseAttachment(int $caseId) { return (new StoreOversightService())->uploadVarianceCaseAttachment($this->request, $this->response, $caseId); }
    public function downloadVarianceCaseAttachment(int $attachmentId) { return (new StoreOversightService())->downloadVarianceCaseAttachment($this->response, $attachmentId); }
    public function handoffVarianceCase(int $caseId) { return (new StoreOversightService())->handoffVarianceCase($this->request, $this->response, $caseId); }
    public function acknowledgeVarianceCase(int $caseId) { return (new StoreOversightService())->acknowledgeVarianceCase($this->response, $caseId); }

    public function officers()
    {
        $q = trim((string) $this->request->getGet('q'));
        $db = Database::connect();
        $query = $db->table('users u')
            ->select('u.id, u.employee_id, u.name, u.email, u.profile_image_url, u.role, u.user_type, s.id AS assigned_store_id, s.store_name AS assigned_store_name')
            ->join('stores s', 's.officer_id = u.id', 'left')
            ->where('u.is_active', true)
            ->whereIn('u.user_type', ['faculty', 'staff']);

        if ($q !== '') {
            $query->groupStart()
                ->like('u.name', $q)
                ->orLike('u.email', $q)
                ->orLike('u.employee_id', $q)
                ->groupEnd();
        }

        $rows = $query->orderBy('u.name', 'ASC')->limit(300)->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'profile_image_url' => $row['profile_image_url'] ?? null,
                    'role' => $row['role'],
                    'user_type' => $row['user_type'],
                    'assigned_store_id' => isset($row['assigned_store_id']) ? (int) $row['assigned_store_id'] : null,
                    'assigned_store_name' => $row['assigned_store_name'] ?? null,
                ];
            }, $rows),
        ]);
    }

    public function createStore()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeName = trim((string) ($request['store_name'] ?? ''));
        $officerId = (int) ($request['officer_id'] ?? 0);
        $supervisorIds = $this->extractIntegerList($request['supervisor_ids'] ?? []);
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));
        $isActive = (int) ($request['is_active'] ?? 0) === 1;

        if ($storeName === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Store name is required.',
            ]);
        }

        $db = Database::connect();
        $storeModel = new StoreModel();
        $auditLogModel = new AuditLogModel();
        $userModel = new UserModel();

        if ($officerId > 0) {
            $officer = $userModel->find($officerId);
            if (!$officer || !ibems_bool($officer['is_active']) || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid Store Cashier. Only active faculty/staff can be assigned.',
                ]);
            }

            $existingOfficerStore = $storeModel->where('officer_id', $officerId)->first();
            if ($existingOfficerStore) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Store Cashier is already assigned to another store.',
                ]);
            }
        }
        try {
            $supervisorIds = $this->validateStoreSupervisors($supervisorIds, $userModel);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
        if ($isActive && ($officerId <= 0 || $supervisorIds === [])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'An active store requires a Store Cashier and at least one supervisor.',
            ]);
        }

        try {
            $logoUrl = $this->resolveStoreLogoUrl($logoUrl, null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $storeId = $storeModel->insert([
            'store_name' => $storeName,
            'officer_id' => $officerId > 0 ? $officerId : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($storeId && $officerId > 0) {
            $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);
        }
        if ($storeId) {
            (new StoreSupervisorModel())->syncStoreSupervisors((int) $storeId, $supervisorIds, $actorId);
            foreach ($supervisorIds as $supervisorId) {
                $this->addRoleToUser($supervisorId, 'STORE_SUPERVISOR', $userModel);
            }
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_STORE',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'store_name' => $storeName,
                'officer_id' => $officerId > 0 ? $officerId : null,
                'supervisor_ids' => $supervisorIds,
                'logo_url' => $logoUrl,
                'is_active' => $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to create store.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => (int) $storeId,
        ]);
    }

    public function updateStore()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeId = (int) ($request['store_id'] ?? 0);
        $storeName = trim((string) ($request['store_name'] ?? ''));
        $officerId = (int) ($request['officer_id'] ?? 0);
        $supervisorIds = $this->extractIntegerList($request['supervisor_ids'] ?? []);
        $logoUrl = trim((string) ($request['logo_url'] ?? ''));

        $isActive = isset($request['is_active']) ? (int) $request['is_active'] : null;
        $deactivationReason = trim((string) ($request['deactivation_reason'] ?? ''));

        if ($storeId <= 0 || $storeName === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store update payload.',
            ]);
        }
        if ($isActive !== null && !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store status.',
            ]);
        }

        $db = Database::connect();
        $storeModel = new StoreModel();
        $userModel = new UserModel();
        $auditLogModel = new AuditLogModel();

        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        if ($officerId > 0) {
            $officer = $userModel->find($officerId);
            if (!$officer || !ibems_bool($officer['is_active']) || !in_array($officer['user_type'], ['faculty', 'staff'], true)) {
                return $this->response->setStatusCode(400)->setJSON([
                    'status' => 'error',
                    'message' => 'Invalid Store Cashier. Only active faculty/staff can be assigned.',
                ]);
            }

            $existingOfficerStore = $storeModel->where('officer_id', $officerId)->first();
            if ($existingOfficerStore && (int) $existingOfficerStore['id'] !== $storeId) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Store Cashier is already assigned to another store.',
                ]);
            }
        }
        $previousSupervisorIds = (new StoreSupervisorModel())->getSupervisorIdsByStore($storeId);
        try {
            $supervisorIds = $this->validateStoreSupervisors($supervisorIds, $userModel);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
        $willBeActive = $isActive !== null ? $isActive === 1 : ibems_bool($store['is_active'] ?? false);
        if ($willBeActive && ($officerId <= 0 || $supervisorIds === [])) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'An active store requires a Store Cashier and at least one supervisor.',
            ]);
        }
        $statusIsChanging = $isActive !== null && $isActive !== (int) ibems_bool($store['is_active'] ?? false);
        if ($statusIsChanging && $isActive === 0) {
            $lifecycleErrors = (new \App\Services\StoreLifecycleService($db))->validateDeactivation($storeId, $deactivationReason);
            if ($lifecycleErrors !== []) {
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => implode(' ', $lifecycleErrors),
                ]);
            }
        }

        try {
            $logoUrl = $this->resolveStoreLogoUrl($logoUrl, $store['logo_url'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $previousOfficerId = (int) $store['officer_id'];

        $storePayload = [
            'store_name' => $storeName,
            'officer_id' => $officerId > 0 ? $officerId : null,
            'logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ];
        if ($isActive !== null) {
            $storePayload['is_active'] = $isActive === 1;
            if ($statusIsChanging) {
                $storePayload = array_merge(
                    $storePayload,
                    (new \App\Services\StoreLifecycleService($db))->lifecyclePayload($isActive === 1, $actorId, $deactivationReason)
                );
            }
        }

        $storeModel->update($storeId, $storePayload);

        if ($officerId > 0) {
            $this->addRoleToUser($officerId, 'STORE_SYSTEM', $userModel);
        }
        (new StoreSupervisorModel())->syncStoreSupervisors($storeId, $supervisorIds, $actorId);
        foreach ($supervisorIds as $supervisorId) {
            $this->addRoleToUser($supervisorId, 'STORE_SUPERVISOR', $userModel);
        }
        foreach (array_diff($previousSupervisorIds, $supervisorIds) as $previousSupervisorId) {
            $assignedCount = $db->table('store_supervisors')->where('user_id', (int) $previousSupervisorId)->countAllResults();
            if ($assignedCount === 0) {
                $this->removeRoleFromUser((int) $previousSupervisorId, 'STORE_SUPERVISOR', $userModel);
            }
        }

        if ($previousOfficerId > 0 && $previousOfficerId !== $officerId) {
            $assignedCount = $db->table('stores')->where('officer_id', $previousOfficerId)->countAllResults();
            if ($assignedCount === 0) {
                $this->removeRoleFromUser($previousOfficerId, 'STORE_SYSTEM', $userModel);
            }
        }

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_UPDATE_STORE',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'before' => [
                    'store_name' => $store['store_name'],
                    'officer_id' => (int) $store['officer_id'],
                    'supervisor_ids' => $previousSupervisorIds,
                    'logo_url' => $store['logo_url'] ?? null,
                    'is_active' => (int) ($store['is_active'] ?? 0),
                ],
                'after' => [
                    'store_name' => $storeName,
                    'officer_id' => $officerId > 0 ? $officerId : null,
                    'supervisor_ids' => $supervisorIds,
                    'logo_url' => $logoUrl !== '' ? $logoUrl : null,
                    'is_active' => $isActive !== null ? $isActive : (int) ($store['is_active'] ?? 0),
                    'deactivation_reason' => $isActive === 0 ? $deactivationReason : null,
                ],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update store.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
        ]);
    }

    private function resolveStoreLogoUrl(string $inputUrl, ?string $currentUrl): ?string
    {
        $logoFile = $this->request->getFile('logo_file');
        $hasFile = $logoFile && $logoFile->getError() !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if (!$logoFile->isValid()) {
                throw new \RuntimeException('Invalid uploaded logo file.');
            }

            return (new AssetStorageService())->storeImage($logoFile, 'store-logos', $currentUrl);
        }

        if ($inputUrl !== '') {
            return $inputUrl;
        }

        return $currentUrl;
    }

    public function toggleStoreStatus()
    {
        $request = $this->getRequestData();
        $actorId = (int) session()->get('user_id');
        $storeId = (int) ($request['store_id'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? -1);
        $reason = trim((string) ($request['deactivation_reason'] ?? ''));

        if ($storeId <= 0 || !in_array($isActive, [0, 1], true)) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid status payload.']);
        }

        $storeModel = new StoreModel();
        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'Store not found.']);
        }
        if ($isActive === (int) ibems_bool($store['is_active'] ?? false)) {
            return $this->response->setJSON(['status' => 'success']);
        }

        $lifecycle = new \App\Services\StoreLifecycleService();
        if ($isActive === 1 && ($errors = $lifecycle->validateReactivation($store)) !== []) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => implode(' ', $errors)]);
        }
        if ($isActive === 0 && ($errors = $lifecycle->validateDeactivation($storeId, $reason)) !== []) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => implode(' ', $errors)]);
        }

        $db = Database::connect();
        $db->transStart();
        $storeModel->update($storeId, $lifecycle->lifecyclePayload($isActive === 1, $actorId, $reason));
        (new AuditLogModel())->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_TOGGLE_STORE_STATUS',
            'entity' => 'stores',
            'entity_id' => $storeId,
            'payload_json' => json_encode([
                'previous_is_active' => (int) $store['is_active'],
                'new_is_active' => $isActive,
                'deactivation_reason' => $isActive === 0 ? $reason : null,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Failed to update store status.']);
        }

        return $this->response->setJSON(['status' => 'success']);
    }

    private function getRequestData(): array
    {
        $contentType = strtolower((string) $this->request->getHeaderLine('Content-Type'));
        if (strpos($contentType, 'application/json') !== false) {
            try {
                return $this->request->getJSON(true) ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        return $this->request->getPost();
    }

    private function extractIntegerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s|;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
    }

    private function validateStoreSupervisors(array $supervisorIds, UserModel $userModel): array
    {
        $validIds = [];
        foreach ($supervisorIds as $supervisorId) {
            $supervisor = $userModel->find((int) $supervisorId);
            if (!$supervisor || !ibems_bool($supervisor['is_active'] ?? false) || !in_array($supervisor['user_type'], ['faculty', 'staff'], true)) {
                throw new \RuntimeException('Invalid Store Supervisor. Only active faculty/staff can be assigned.');
            }
            $validIds[] = (int) $supervisorId;
        }

        return array_values(array_unique($validIds));
    }

    private function canAccessStoreForAdminArea(int $storeId): bool
    {
        $role = ibems_current_role();
        if ($role === 'ADMIN') {
            return true;
        }
        if ($role !== 'STORE_SUPERVISOR') {
            return false;
        }

        return (new StoreModel())->canUserAccessStore((int) session()->get('user_id'), $role, $storeId);
    }

    private function addRoleToUser(int $userId, string $role, UserModel $userModel): void
    {
        $user = $userModel->find($userId);
        if (!$user) {
            return;
        }

        $role = strtoupper(trim($role));
        if ($role === '') {
            return;
        }

        $roles = $this->resolveUserRoles($userId, (string) ($user['role'] ?? 'USER'));
        if (!in_array('USER', $roles, true)) {
            $roles[] = 'USER';
        }
        if (!in_array($role, $roles, true)) {
            $roles[] = $role;
        }

        $this->syncUserRoles($userId, $roles);
        $primaryRole = $this->pickPrimaryRole($roles);
        if (strtoupper((string) ($user['role'] ?? '')) !== $primaryRole) {
            $userModel->update($userId, ['role' => $primaryRole]);
        }
    }

    private function removeRoleFromUser(int $userId, string $role, UserModel $userModel): void
    {
        $user = $userModel->find($userId);
        if (!$user) {
            return;
        }

        $role = strtoupper(trim($role));
        if ($role === '') {
            return;
        }

        $roles = $this->resolveUserRoles($userId, (string) ($user['role'] ?? 'USER'));
        $roles = array_values(array_filter($roles, static fn(string $selectedRole): bool => $selectedRole !== $role));
        if (empty($roles)) {
            $roles = ['USER'];
        }

        $this->syncUserRoles($userId, $roles);
        $primaryRole = $this->pickPrimaryRole($roles);
        if (strtoupper((string) ($user['role'] ?? '')) !== $primaryRole) {
            $userModel->update($userId, ['role' => $primaryRole]);
        }
    }
}
