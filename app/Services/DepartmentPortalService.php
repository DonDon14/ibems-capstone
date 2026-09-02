<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class DepartmentPortalService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @return array<string, mixed> */
    public function overview(int $userId, string $requestedPeriod = 'day'): array
    {
        if ($userId <= 0 || !$this->db->tableExists('departments')) {
            return ['status' => 'error', 'code' => 403, 'message' => 'An active department-head assignment is required.'];
        }

        $period = DashboardPeriod::resolve($requestedPeriod);
        $assignments = $this->db->table('departments d')
            ->select('d.id, d.code, d.name, p.id AS period_id, p.period_month, p.allocation_amount, p.used_amount, p.outstanding_amount, p.status')
            ->join('department_debt_periods p', "p.department_id = d.id AND p.period_month = '" . substr(ibems_business_date(), 0, 7) . "-01'", 'left', false)
            ->where('d.head_user_id', $userId)
            ->where('d.is_active', true)
            ->orderBy('d.name', 'ASC')
            ->get()->getResultArray();
        if ($assignments === []) {
            return ['status' => 'error', 'code' => 403, 'message' => 'An active department-head assignment is required.'];
        }

        $departmentIds = array_map(static fn (array $row): int => (int) $row['id'], $assignments);
        $entries = [];
        $activityByDepartment = [];
        if ($this->db->tableExists('department_debt_entries')) {
            $activityRows = $this->db->table('department_debt_entries')
                ->select('department_id, COUNT(*) AS entry_count, COALESCE(SUM(CASE WHEN direction = \'debit\' THEN amount ELSE 0 END), 0) AS charges, COALESCE(SUM(CASE WHEN direction = \'credit\' THEN amount ELSE 0 END), 0) AS settlements', false)
                ->whereIn('department_id', $departmentIds)
                ->where('created_at >=', $period['start'])
                ->where('created_at <=', $period['end'])
                ->groupBy('department_id')
                ->get()->getResultArray();
            foreach ($activityRows as $activityRow) {
                $activityByDepartment[(int) $activityRow['department_id']] = $activityRow;
            }
            $entries = $this->db->table('department_debt_entries e')
                ->select('e.id, e.department_id, e.entry_type, e.direction, e.amount, e.requester_name, e.reference_no, e.created_at, d.name AS department_name')
                ->join('departments d', 'd.id = e.department_id', 'inner')
                ->whereIn('e.department_id', $departmentIds)
                ->where('e.created_at >=', $period['start'])
                ->where('e.created_at <=', $period['end'])
                ->orderBy('e.created_at', 'DESC')->orderBy('e.id', 'DESC')
                ->limit(8)->get()->getResultArray();
        }

        return ['status' => 'success', 'code' => 200, 'period' => DashboardPeriod::publicMeta($period), 'assignments' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'period_id' => isset($row['period_id']) ? (int) $row['period_id'] : null,
            'period_month' => $row['period_month'] ?? null,
            'allocation_amount' => (float) ($row['allocation_amount'] ?? 0),
            'used_amount' => (float) ($row['used_amount'] ?? 0),
            'remaining_allocation' => max(0, (float) ($row['allocation_amount'] ?? 0) - (float) ($row['used_amount'] ?? 0)),
            'outstanding_amount' => (float) ($row['outstanding_amount'] ?? 0),
            'status' => (string) ($row['status'] ?? 'not_configured'),
            'period_entry_count' => (int) ($activityByDepartment[(int) $row['id']]['entry_count'] ?? 0),
            'period_charges' => (float) ($activityByDepartment[(int) $row['id']]['charges'] ?? 0),
            'period_settlements' => (float) ($activityByDepartment[(int) $row['id']]['settlements'] ?? 0),
        ], $assignments), 'recent_entries' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'department_id' => (int) $row['department_id'],
            'department_name' => (string) $row['department_name'],
            'entry_type' => (string) $row['entry_type'],
            'direction' => (string) $row['direction'],
            'amount' => (float) $row['amount'],
            'requester_name' => (string) ($row['requester_name'] ?? ''),
            'reference_no' => (string) ($row['reference_no'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $entries)];
    }
}
