<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use App\Services\DepartmentAuthorizationService;
use App\Services\DepartmentDebtService;
use App\Services\DepartmentPortalService;
use CodeIgniter\Controller;
use Config\Database;
use Throwable;

class DepartmentDebtController extends Controller
{
    public function adminPage()
    {
        return view('admin/departments');
    }

    public function accountingPage()
    {
        return view('accounting/department-debts');
    }

    public function userPage()
    {
        if (!$this->hasDepartmentAccess()) {
            return redirect()->to(site_url('user/dashboard'))->with('error', 'An active department-head assignment is required.');
        }
        return view('department/authorizations');
    }

    public function legacyUserPage()
    {
        return redirect()->to(site_url('department/authorizations'));
    }

    public function departmentDashboard()
    {
        if (!$this->hasDepartmentAccess()) {
            return redirect()->to(site_url('user/dashboard'))->with('error', 'An active department-head assignment is required.');
        }
        return view('department/dashboard');
    }

    public function departmentDashboardData()
    {
        $result = (new DepartmentPortalService())->overview(
            (int) session()->get('user_id'),
            (string) ($this->request->getGet('period') ?? 'day')
        );
        $code = (int) ($result['code'] ?? 200);
        unset($result['code']);
        return $this->response->setStatusCode($code)->setJSON($result);
    }

    public function adminData()
    {
        $db = Database::connect();
        if (!$db->tableExists('departments')) {
            return $this->notMigrated();
        }
        $departments = $db->table('departments d')
            ->select('d.*, u.name AS head_name, u.employee_id AS head_employee_id')
            ->join('users u', 'u.id = d.head_user_id', 'left')
            ->orderBy('d.is_active', 'DESC')->orderBy('d.name', 'ASC')->get()->getResultArray();
        $people = $db->table('users')
            ->select('id, employee_id, name, email, profile_image_url, user_type')
            ->where('is_active', true)
            ->whereIn('user_type', ['faculty', 'staff'])
            ->orderBy('name', 'ASC')->get()->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'departments' => array_map(static function (array $row): array {
                $id = (int) $row['id'];
                return [
                    'id' => $id,
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'head_user_id' => isset($row['head_user_id']) ? (int) $row['head_user_id'] : null,
                    'head_name' => (string) ($row['head_name'] ?? ''),
                    'head_employee_id' => (string) ($row['head_employee_id'] ?? ''),
                    'is_active' => ibems_bool($row['is_active'] ?? false),
                    'status_reason' => (string) ($row['status_reason'] ?? ''),
                ];
            }, $departments),
            'people' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'employee_id' => (string) ($row['employee_id'] ?? ''),
                'name' => (string) $row['name'],
                'email' => (string) $row['email'],
                'profile_image_url' => $row['profile_image_url'] ?? null,
                'user_type' => (string) $row['user_type'],
            ], $people),
        ]);
    }

    public function saveDepartment()
    {
        $payload = $this->payload();
        $id = (int) ($payload['id'] ?? 0);
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        $headUserId = (int) ($payload['head_user_id'] ?? 0);
        $isActive = !isset($payload['is_active']) || filter_var($payload['is_active'], FILTER_VALIDATE_BOOLEAN);
        $reason = trim((string) ($payload['status_reason'] ?? ''));
        $actorId = (int) session()->get('user_id');

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,39}$/', $code) || mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            return $this->jsonError('Enter a valid department code and name.');
        }
        if ($headUserId <= 0) {
            return $this->jsonError('Select an active faculty/staff department head.');
        }
        if (!$isActive && $reason === '') {
            return $this->jsonError('A reason is required when deactivating a department.');
        }
        $db = Database::connect();
        $validHead = $db->table('users')->select('id')->where('id', $headUserId)->where('is_active', true)->whereIn('user_type', ['faculty', 'staff'])->countAllResults();
        if ($validHead !== 1) {
            return $this->jsonError('The department head must be active faculty/staff.');
        }
        $duplicate = $db->table('departments')->groupStart()->where('code', $code)->orWhere('name', $name)->groupEnd();
        if ($id > 0) {
            $duplicate->where('id !=', $id);
        }
        if ($duplicate->countAllResults() > 0) {
            return $this->jsonError('Department code and name must be unique.', 409);
        }
        $existing = $id > 0 ? $db->table('departments')->where('id', $id)->get()->getRowArray() : null;
        if ($id > 0 && !$existing) {
            return $this->jsonError('Department not found.', 404);
        }

        $now = date('Y-m-d H:i:s');
        $db->transBegin();
        try {
            $departmentPayload = [
                'code' => $code,
                'name' => $name,
                'head_user_id' => $headUserId,
                'is_active' => $isActive,
                'status_reason' => $isActive ? null : $reason,
                'updated_by' => $actorId > 0 ? $actorId : null,
                'updated_at' => $now,
            ];
            if ($existing) {
                $db->table('departments')->where('id', $id)->update($departmentPayload);
                $departmentId = $id;
            } else {
                $departmentPayload['created_by'] = $actorId > 0 ? $actorId : null;
                $departmentPayload['created_at'] = $now;
                $db->table('departments')->insert($departmentPayload);
                $departmentId = (int) $db->insertID();
            }

            // Delegation was retired in favor of one accountable department head.
            // Keep rows for audit history, but remove any active authority on save.
            $db->table('department_delegates')->where('department_id', $departmentId)->where('is_active', true)->update([
                'is_active' => false,
                'updated_at' => $now,
            ]);
            (new AuditLogModel())->insert([
                'actor_id' => $actorId > 0 ? $actorId : null,
                'action' => $existing ? 'ADMIN_UPDATE_DEPARTMENT' : 'ADMIN_CREATE_DEPARTMENT',
                'entity' => 'departments',
                'entity_id' => $departmentId,
                'payload_json' => json_encode([
                    'before' => $existing,
                    'after' => ['code' => $code, 'name' => $name, 'head_user_id' => $headUserId, 'approval_policy' => 'department_head_only', 'is_active' => $isActive, 'status_reason' => $reason],
                ]),
                'created_at' => $now,
            ]);
            if (!$db->transStatus()) {
                throw new \RuntimeException('Department update failed.');
            }
            $db->transCommit();
            return $this->response->setJSON(['status' => 'success', 'department_id' => $departmentId]);
        } catch (Throwable $e) {
            $db->transRollback();
            log_message('error', 'Department save failed: {message}', ['message' => $e->getMessage()]);
            return $this->jsonError('Unable to save the department. No changes were applied.', 500);
        }
    }

    public function accountingData()
    {
        $db = Database::connect();
        if (!$db->tableExists('department_debt_periods')) {
            return $this->notMigrated();
        }
        $month = trim((string) $this->request->getGet('month'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $monthDate = $month . '-01';
        $rows = $db->table('departments d')
            ->select('d.id, d.code, d.name, d.is_active, d.head_user_id, u.name AS head_name, p.id AS period_id, p.period_month, p.allocation_amount, p.used_amount, p.outstanding_amount, p.status AS period_status, p.notes')
            ->join('users u', 'u.id = d.head_user_id', 'left')
            ->join('department_debt_periods p', "p.department_id = d.id AND p.period_month = '" . $db->escapeString($monthDate) . "'", 'left', false)
            ->orderBy('d.is_active', 'DESC')->orderBy('d.name', 'ASC')->get()->getResultArray();
        $periodIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['period_id'] ?? 0), $rows)));
        $entries = [];
        if ($periodIds !== []) {
            $entries = $db->table('department_debt_entries e')
                ->select('e.*, a.name AS actor_name, ap.name AS approver_name, s.store_name')
                ->join('users a', 'a.id = e.actor_id', 'left')
                ->join('users ap', 'ap.id = e.approved_by_user_id', 'left')
                ->join('transactions t', 't.id = e.transaction_id', 'left')
                ->join('stores s', 's.id = t.store_id', 'left')
                ->whereIn('e.period_id', $periodIds)
                ->orderBy('e.id', 'DESC')->limit(300)->get()->getResultArray();
        }

        return $this->response->setJSON([
            'status' => 'success',
            'month' => $month,
            'departments' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'is_active' => ibems_bool($row['is_active'] ?? false),
                'head_user_id' => isset($row['head_user_id']) ? (int) $row['head_user_id'] : null,
                'head_name' => (string) ($row['head_name'] ?? ''),
                'period_id' => isset($row['period_id']) ? (int) $row['period_id'] : null,
                'allocation_amount' => (float) ($row['allocation_amount'] ?? 0),
                'used_amount' => (float) ($row['used_amount'] ?? 0),
                'remaining_allocation' => max(0, (float) ($row['allocation_amount'] ?? 0) - (float) ($row['used_amount'] ?? 0)),
                'outstanding_amount' => (float) ($row['outstanding_amount'] ?? 0),
                'period_status' => (string) ($row['period_status'] ?? 'unconfigured'),
                'notes' => (string) ($row['notes'] ?? ''),
            ], $rows),
            'entries' => array_map([$this, 'entryPayload'], $entries),
        ]);
    }

    public function setAllocation()
    {
        $payload = $this->payload();
        $result = (new DepartmentDebtService())->setAllocation(
            (int) ($payload['department_id'] ?? 0),
            (string) ($payload['period_month'] ?? ''),
            (float) ($payload['allocation_amount'] ?? -1),
            (string) ($payload['status'] ?? 'open'),
            trim((string) ($payload['reason'] ?? '')),
            (int) session()->get('user_id')
        );
        return $this->result($result);
    }

    public function setAllocations()
    {
        $payload = $this->payload();
        $departmentIds = $payload['department_ids'] ?? [];
        if (!is_array($departmentIds)) {
            return $this->result(['status' => 'error', 'code' => 400, 'message' => 'Select one or more departments.']);
        }
        $result = (new DepartmentDebtService())->setAllocations(
            $departmentIds,
            (string) ($payload['period_month'] ?? ''),
            (float) ($payload['allocation_amount'] ?? -1),
            (string) ($payload['status'] ?? 'open'),
            trim((string) ($payload['reason'] ?? '')),
            (int) session()->get('user_id')
        );
        return $this->result($result);
    }

    public function recordSettlement()
    {
        $payload = $this->payload();
        $result = (new DepartmentDebtService())->recordSettlement(
            (int) ($payload['department_id'] ?? 0),
            (int) ($payload['period_id'] ?? 0),
            (float) ($payload['amount'] ?? 0),
            (string) ($payload['reference_no'] ?? ''),
            (string) ($payload['remarks'] ?? ''),
            (int) session()->get('user_id')
        );
        return $this->result($result);
    }

    public function userData()
    {
        $db = Database::connect();
        $userId = (int) session()->get('user_id');
        if (!$db->tableExists('departments')) {
            return $this->notMigrated();
        }
        if (!$this->hasDepartmentAccess()) {
            return $this->jsonError('An active department-head assignment is required.', 403);
        }
        $headRows = $db->table('departments')->select("id, code, name, 'head' AS assignment_type", false)
            ->where('head_user_id', $userId)->where('is_active', true)->get()->getResultArray();
        $pin = $db->table('department_authorization_pins')->select('updated_at, last_success_at, locked_until')->where('user_id', $userId)->get()->getRowArray();
        return $this->response->setJSON(['status' => 'success', 'assignments' => $headRows, 'pin' => [
            'is_set' => $pin !== null,
            'updated_at' => $pin['updated_at'] ?? null,
            'last_success_at' => $pin['last_success_at'] ?? null,
            'locked_until' => $pin['locked_until'] ?? null,
        ]]);
    }

    public function setPin()
    {
        $payload = $this->payload();
        $userId = (int) session()->get('user_id');
        if (!$this->hasDepartmentAccess()) {
            return $this->jsonError('An active department-head assignment is required.', 403);
        }
        $pin = trim((string) ($payload['pin'] ?? ''));
        $confirmation = trim((string) ($payload['pin_confirmation'] ?? ''));
        if (!hash_equals($pin, $confirmation)) {
            return $this->jsonError('PIN confirmation does not match.');
        }

        $db = Database::connect();
        $existingPin = $db->table('department_authorization_pins')->where('user_id', $userId)->countAllResults() > 0;
        if ($existingPin) {
            $user = (new UserModel())->find($userId);
            $currentPassword = (string) ($payload['current_password'] ?? '');
            if (!$user || $currentPassword === '' || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
                return $this->jsonError('Enter your current password to change your department approval PIN.');
            }
        }

        return $this->result((new DepartmentAuthorizationService())->setPin($userId, $pin));
    }

    public function storeData()
    {
        $db = Database::connect();
        if (!$db->tableExists('department_debt_periods')) {
            return $this->notMigrated();
        }
        $month = date('Y-m-01');
        $departments = $db->table('departments d')
            ->select('d.id, d.code, d.name, p.id AS period_id, p.allocation_amount, p.used_amount, p.outstanding_amount, p.status')
            ->join('department_debt_periods p', 'p.department_id = d.id', 'inner')
            ->where('d.is_active', true)->where('p.period_month', $month)->where('p.status', 'open')
            ->orderBy('d.name', 'ASC')->get()->getResultArray();
        $departmentIds = array_map(static fn (array $row): int => (int) $row['id'], $departments);
        $approvers = [];
        if ($departmentIds !== []) {
            $heads = $db->table('departments d')->select("d.id AS department_id, u.id, u.name, u.employee_id, CASE WHEN p.user_id IS NULL THEN 0 ELSE 1 END AS pin_set", false)
                ->join('users u', 'u.id = d.head_user_id', 'inner')
                ->join('department_authorization_pins p', 'p.user_id = u.id', 'left')
                ->whereIn('d.id', $departmentIds)->where('u.is_active', true)->get()->getResultArray();
            foreach ($heads as $approver) {
                $approvers[(int) $approver['department_id']][(int) $approver['id']] = [
                    'id' => (int) $approver['id'],
                    'name' => (string) $approver['name'],
                    'employee_id' => (string) ($approver['employee_id'] ?? ''),
                    'pin_set' => ibems_bool($approver['pin_set'] ?? false),
                ];
            }
        }
        return $this->response->setJSON(['status' => 'success', 'departments' => array_map(static function (array $row) use ($approvers): array {
            $id = (int) $row['id'];
            return [
                'id' => $id,
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'allocation_amount' => (float) $row['allocation_amount'],
                'used_amount' => (float) $row['used_amount'],
                'remaining_allocation' => max(0, (float) $row['allocation_amount'] - (float) $row['used_amount']),
                'outstanding_amount' => (float) $row['outstanding_amount'],
                'approvers' => array_values($approvers[$id] ?? []),
            ];
        }, $departments)]);
    }

    private function entryPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'department_id' => (int) $row['department_id'],
            'period_id' => (int) $row['period_id'],
            'entry_type' => (string) $row['entry_type'],
            'direction' => (string) $row['direction'],
            'amount' => (float) $row['amount'],
            'allocation_before' => (float) $row['allocation_before'],
            'allocation_after' => (float) $row['allocation_after'],
            'used_after' => (float) $row['used_after'],
            'outstanding_after' => (float) $row['outstanding_after'],
            'transaction_id' => isset($row['transaction_id']) ? (int) $row['transaction_id'] : null,
            'requester_name' => (string) ($row['requester_name'] ?? ''),
            'approver_name' => (string) ($row['approver_name'] ?? ''),
            'actor_name' => (string) ($row['actor_name'] ?? ''),
            'store_name' => (string) ($row['store_name'] ?? ''),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function payload(): array
    {
        $payload = $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
        return is_array($payload) ? $payload : [];
    }

    private function hasDepartmentAccess(): bool
    {
        return (new DepartmentAuthorizationService())->userHasAssignment((int) session()->get('user_id'));
    }

    private function result(array $result)
    {
        $code = (int) ($result['code'] ?? (($result['status'] ?? 'error') === 'success' ? 200 : 400));
        unset($result['code']);
        return $this->response->setStatusCode($code)->setJSON($result);
    }

    private function jsonError(string $message, int $code = 400)
    {
        return $this->response->setStatusCode($code)->setJSON(['status' => 'error', 'message' => $message]);
    }

    private function notMigrated()
    {
        return $this->jsonError('Department debt setup is unavailable until the latest database migration is applied.', 503);
    }
}
