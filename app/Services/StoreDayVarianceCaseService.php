<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class StoreDayVarianceCaseService
{
    public function ensureCase(BaseConnection $db, array $session, ?int $actorId = null, ?string $now = null): ?array
    {
        if (!$db->tableExists('store_day_variance_cases') || (string) ($session['review_status'] ?? 'not_required') === 'not_required') {
            return null;
        }

        $sessionId = (int) ($session['id'] ?? 0);
        $existing = $db->table('store_day_variance_cases')->where('store_day_session_id', $sessionId)->get()->getRowArray();
        if ($existing) {
            return $existing;
        }

        $now ??= date('Y-m-d H:i:s');
        $db->table('store_day_variance_cases')->insert([
            'case_ref' => sprintf('SDV-%s-%06d', str_replace('-', '', (string) ($session['business_date'] ?? ibems_business_date())), $sessionId),
            'store_day_session_id' => $sessionId,
            'store_id' => (int) ($session['store_id'] ?? 0),
            'status' => 'open',
            'owner_user_id' => null,
            'opened_by' => $actorId,
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $caseId = (int) $db->insertID();
        $db->table('store_day_variance_case_events')->insert([
            'case_id' => $caseId,
            'actor_id' => $actorId,
            'event_type' => 'opened',
            'to_status' => 'pending',
            'note' => $session['closing_note'] ?? 'Variance case opened from store-day close.',
            'evidence_json' => json_encode($this->evidence($session)),
            'created_at' => $now,
        ]);

        return $db->table('store_day_variance_cases')->where('id', $caseId)->get()->getRowArray();
    }

    public function recordReview(BaseConnection $db, array $session, int $actorId, string $action, string $newReviewStatus, string $note, string $now): void
    {
        $case = $this->ensureCase($db, $session, $actorId, $now);
        if (!$case) {
            return;
        }

        $resolved = in_array($newReviewStatus, ['approved', 'waived', 'corrected'], true);
        $caseStatus = $resolved ? 'resolved' : ($newReviewStatus === 'needs_investigation' ? 'investigating' : 'open');
        $db->table('store_day_variance_cases')->where('id', (int) $case['id'])->update([
            'status' => $caseStatus,
            'owner_user_id' => $resolved ? null : $actorId,
            'resolved_by' => $resolved ? $actorId : null,
            'resolved_at' => $resolved ? $now : null,
            'disposition' => $resolved ? $newReviewStatus : null,
            'updated_at' => $now,
        ]);
        $db->table('store_day_variance_case_events')->insert([
            'case_id' => (int) $case['id'],
            'actor_id' => $actorId,
            'event_type' => $action,
            'from_status' => (string) ($session['review_status'] ?? 'pending'),
            'to_status' => $newReviewStatus,
            'note' => $note,
            'evidence_json' => json_encode($this->evidence($session)),
            'created_at' => $now,
        ]);
    }

    public function getCasesForSessions(BaseConnection $db, array $sessionIds): array
    {
        $sessionIds = array_values(array_filter(array_map('intval', $sessionIds)));
        if ($sessionIds === [] || !$db->tableExists('store_day_variance_cases')) {
            return [];
        }

        $cases = $db->table('store_day_variance_cases c')
            ->select('c.*, owner.name AS owner_name, resolver.name AS resolved_by_name')
            ->join('users owner', 'owner.id = c.owner_user_id', 'left')
            ->join('users resolver', 'resolver.id = c.resolved_by', 'left')
            ->whereIn('c.store_day_session_id', $sessionIds)
            ->get()->getResultArray();
        if ($cases === []) {
            return [];
        }

        $events = $db->table('store_day_variance_case_events e')
            ->select('e.*, actor.name AS actor_name')
            ->join('users actor', 'actor.id = e.actor_id', 'left')
            ->whereIn('e.case_id', array_map(static fn (array $case): int => (int) $case['id'], $cases))
            ->orderBy('e.created_at', 'ASC')->orderBy('e.id', 'ASC')
            ->get()->getResultArray();
        $eventsByCase = [];
        foreach ($events as $event) {
            $eventsByCase[(int) $event['case_id']][] = [
                'event_type' => (string) $event['event_type'],
                'from_status' => $event['from_status'] ?? null,
                'to_status' => $event['to_status'] ?? null,
                'note' => $event['note'] ?? null,
                'actor_name' => $event['actor_name'] ?? 'System',
                'created_at' => $event['created_at'] ?? null,
            ];
        }

        $attachmentsByCase = [];
        if ($db->tableExists('store_day_variance_case_attachments')) {
            foreach ($db->table('store_day_variance_case_attachments a')
                ->select('a.id, a.case_id, a.original_name, a.mime_type, a.file_size, a.description, a.retention_until, a.created_at, uploader.name AS uploaded_by_name')
                ->join('users uploader', 'uploader.id = a.uploaded_by', 'left')
                ->whereIn('a.case_id', array_map(static fn (array $case): int => (int) $case['id'], $cases))
                ->orderBy('a.created_at', 'ASC')->get()->getResultArray() as $attachment) {
                $attachmentsByCase[(int) $attachment['case_id']][] = [
                    'id' => (int) $attachment['id'], 'original_name' => (string) $attachment['original_name'],
                    'mime_type' => (string) $attachment['mime_type'], 'file_size' => (int) $attachment['file_size'],
                    'description' => $attachment['description'] ?? null, 'created_at' => $attachment['created_at'] ?? null,
                    'retention_until' => $attachment['retention_until'] ?? null,
                    'uploaded_by_name' => $attachment['uploaded_by_name'] ?? 'Unknown',
                ];
            }
        }

        $handoffsByCase = [];
        if ($db->tableExists('store_day_variance_case_handoffs')) {
            foreach ($db->table('store_day_variance_case_handoffs h')
                ->select('h.id, h.case_id, h.to_user_id, h.note, h.status, h.created_at, h.due_at, h.acknowledged_at, sender.name AS from_name, recipient.name AS to_name')
                ->join('users sender', 'sender.id = h.from_user_id', 'left')->join('users recipient', 'recipient.id = h.to_user_id', 'left')
                ->whereIn('h.case_id', array_map(static fn (array $case): int => (int) $case['id'], $cases))
                ->orderBy('h.created_at', 'ASC')->get()->getResultArray() as $handoff) {
                $handoffsByCase[(int) $handoff['case_id']][] = $handoff;
            }
        }

        $result = [];
        foreach ($cases as $case) {
            $result[(int) $case['store_day_session_id']] = [
                'id' => (int) $case['id'],
                'case_ref' => (string) $case['case_ref'],
                'status' => (string) $case['status'],
                'owner_name' => $case['owner_name'] ?? null,
                'opened_at' => $case['opened_at'] ?? null,
                'resolved_at' => $case['resolved_at'] ?? null,
                'resolved_by_name' => $case['resolved_by_name'] ?? null,
                'disposition' => $case['disposition'] ?? null,
                'events' => $eventsByCase[(int) $case['id']] ?? [],
                'attachments' => $attachmentsByCase[(int) $case['id']] ?? [],
                'handoffs' => $handoffsByCase[(int) $case['id']] ?? [],
                'can_acknowledge' => $this->canAcknowledge($handoffsByCase[(int) $case['id']] ?? []),
            ];
        }
        return $result;
    }

    private function canAcknowledge(array $handoffs): bool
    {
        if ($handoffs === []) return false;
        $latest = $handoffs[array_key_last($handoffs)];
        return (int) ($latest['to_user_id'] ?? 0) === (int) session()->get('user_id') && (string) ($latest['status'] ?? '') === 'pending';
    }

    private function evidence(array $session): array
    {
        return array_intersect_key($session, array_flip([
            'business_date', 'expected_cash', 'expected_ecash', 'counted_cash', 'counted_ecash',
            'variance_cash', 'variance_ecash', 'variance_status', 'review_status', 'closing_note',
        ]));
    }
}
