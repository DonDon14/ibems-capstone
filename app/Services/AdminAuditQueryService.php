<?php

namespace App\Services;

use Config\Database;

final class AdminAuditQueryService
{
    public function data(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $action = trim((string) ($filters['action'] ?? ''));
        $entity = trim((string) ($filters['entity'] ?? ''));
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        $pageSize = max(25, min(200, (int) ($filters['page_size'] ?? null ?? 100)));
        $page = max(1, (int) ($filters['page'] ?? null ?? 1));
        $offset = ($page - 1) * $pageSize;
        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? null ?? 'date')));
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? null ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'date' => 'al.created_at',
            'action' => 'al.action',
            'actor' => 'u.name',
            'entity' => 'al.entity',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['date'];

        $db = Database::connect();
        $query = $db->table('audit_logs al')
            ->select('al.id, al.actor_id, al.action, al.entity, al.entity_id, al.payload_json, al.created_at, u.name AS actor_name, u.email AS actor_email, u.profile_image_url AS actor_profile_image_url')
            ->join('users u', 'u.id = al.actor_id', 'left');

        if ($action !== '') {
            $query->where('al.action', $action);
        }
        if ($entity !== '') {
            $query->where('al.entity', $entity);
        }
        if ($dateFrom !== '') {
            $query->where('al.created_at >=', ibems_business_day_utc_bounds($dateFrom)['start']);
        }
        if ($dateTo !== '') {
            $query->where('al.created_at <=', ibems_business_day_utc_bounds($dateTo)['end']);
        }
        if ($q !== '') {
            $query->groupStart()
                ->like('al.action', $q)
                ->orLike('al.entity', $q)
                ->orLike('al.payload_json', $q)
                ->orLike('u.name', $q)
                ->orLike('u.email', $q)
                ->groupEnd();
        }

        $filteredTotal = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($filteredTotal / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('al.id', $sortDir)
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $summary = $db->table('audit_logs')
            ->select('COUNT(*) AS total_events, COUNT(DISTINCT actor_id) AS actor_count, COUNT(DISTINCT action) AS action_count')
            ->get()
            ->getRowArray() ?? [];
        $todayBounds = ibems_business_day_utc_bounds();
        $todayEvents = $db->table('audit_logs')
            ->where('created_at >=', $todayBounds['start'])
            ->where('created_at <=', $todayBounds['end'])
            ->countAllResults();

        $actions = $db->table('audit_logs')
            ->select('action')
            ->groupBy('action')
            ->orderBy('action', 'ASC')
            ->get()
            ->getResultArray();

        $entities = $db->table('audit_logs')
            ->select('entity')
            ->where('entity IS NOT NULL', null, false)
            ->where('entity !=', '')
            ->groupBy('entity')
            ->orderBy('entity', 'ASC')
            ->get()
            ->getResultArray();

        return $this->result(200, [
            'status' => 'success',
            'summary' => [
                'total_events' => (int) ($summary['total_events'] ?? 0),
                'today_events' => $todayEvents,
                'actor_count' => (int) ($summary['actor_count'] ?? 0),
                'action_count' => (int) ($summary['action_count'] ?? 0),
                'visible_events' => $filteredTotal,
            ],
            'actions' => array_values(array_map(static fn(array $row): string => (string) ($row['action'] ?? ''), $actions)),
            'entities' => array_values(array_map(static fn(array $row): string => (string) ($row['entity'] ?? ''), $entities)),
            'data' => array_map(function (array $row): array {
                $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                return [
                    'id' => (int) $row['id'],
                    'actor_id' => isset($row['actor_id']) ? (int) $row['actor_id'] : null,
                    'actor_name' => (string) ($row['actor_name'] ?? 'System'),
                    'actor_email' => (string) ($row['actor_email'] ?? ''),
                    'actor_profile_image_url' => $row['actor_profile_image_url'] ?? null,
                    'action' => (string) ($row['action'] ?? ''),
                    'action_label' => $this->formatAuditAction((string) ($row['action'] ?? '')),
                    'entity' => (string) ($row['entity'] ?? ''),
                    'entity_id' => isset($row['entity_id']) ? (int) $row['entity_id'] : null,
                    'payload' => $payload,
                    'payload_summary' => $this->summarizeAuditPayload($payload),
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $rows),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $filteredTotal,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    private function formatAuditAction(string $action): string
    {
        $labels = [
            'ACCOUNTING_PREPARE_DEDUCTION_BATCH' => 'Prepared deduction batch',
            'ACCOUNTING_SUBMIT_DEDUCTION_BATCH' => 'Submitted deduction batch',
            'ACCOUNTING_CONFIRM_DEDUCTION_RESULT' => 'Confirmed payroll deduction result',
            'ACCOUNTING_RECONCILE_DEDUCTION_BATCH' => 'Reconciled deduction batch',
            'ACCOUNTING_FINALIZE_DEDUCTION_BATCH' => 'Finalized deduction period',
            'OPEN_DEBT_INVESTIGATION' => 'Opened debt investigation',
            'RECOMMEND_DEBT_INVESTIGATION' => 'Recommended debt correction',
            'APPROVE_AND_POST_DEBT_REVERSAL' => 'Approved and posted debt correction',
            'DEBT_PIN_AUTHORIZED' => 'Authorized debt purchase PIN',
            'FAILED_DEBT_PIN' => 'Failed debt purchase PIN',
            'DEBT_PIN_LOCKED' => 'Locked debt purchase PIN',
            'BLOCKED_DEBT_PIN' => 'Blocked locked debt purchase PIN attempt',
        ];
        if (isset($labels[$action])) {
            return $labels[$action];
        }

        $value = str_replace('_', ' ', trim($action));
        $value = strtolower($value);

        return ucwords($value);
    }

    private function summarizeAuditPayload(array $payload): string
    {
        $parts = [];
        foreach (['store_name', 'name', 'email', 'sku', 'payment_method', 'business_date', 'month', 'run_id'] as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . (string) $payload[$key];
            }
        }

        foreach (['amount', 'total', 'created', 'updated', 'invalid', 'stock_qty', 'qty'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . (string) $payload[$key];
            }
        }

        if (isset($payload['before']) || isset($payload['after'])) {
            $parts[] = 'Changed fields recorded';
        }

        return implode(' | ', array_slice($parts, 0, 4));
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
