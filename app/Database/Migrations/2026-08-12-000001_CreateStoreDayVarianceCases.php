<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreDayVarianceCases extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_day_variance_cases')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'case_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'store_day_session_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'store_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'open'],
                'owner_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'opened_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'opened_at' => ['type' => 'DATETIME', 'null' => true],
                'resolved_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'resolved_at' => ['type' => 'DATETIME', 'null' => true],
                'disposition' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('case_ref');
            $this->forge->addUniqueKey('store_day_session_id');
            $this->forge->addKey(['store_id', 'status']);
            $this->forge->createTable('store_day_variance_cases', true);
        }

        if (!$this->db->tableExists('store_day_variance_case_events')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'case_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'actor_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'event_type' => ['type' => 'VARCHAR', 'constraint' => 40],
                'from_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'to_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'note' => ['type' => 'TEXT', 'null' => true],
                'evidence_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['case_id', 'created_at']);
            $this->forge->createTable('store_day_variance_case_events', true);
        }

        $this->backfillExistingVariances();
    }

    private function backfillExistingVariances(): void
    {
        if (!$this->db->tableExists('store_day_sessions')) {
            return;
        }

        $sessions = $this->db->table('store_day_sessions')
            ->where('review_status !=', 'not_required')
            ->get()
            ->getResultArray();
        foreach ($sessions as $session) {
            $sessionId = (int) ($session['id'] ?? 0);
            if ($sessionId <= 0 || $this->db->table('store_day_variance_cases')->where('store_day_session_id', $sessionId)->countAllResults() > 0) {
                continue;
            }

            $reviewStatus = (string) ($session['review_status'] ?? 'pending');
            $resolved = in_array($reviewStatus, ['approved', 'waived', 'corrected'], true);
            $openedAt = (string) ($session['closed_at'] ?? $session['updated_at'] ?? date('Y-m-d H:i:s'));
            $this->db->table('store_day_variance_cases')->insert([
                'case_ref' => sprintf('SDV-%s-%06d', str_replace('-', '', (string) ($session['business_date'] ?? date('Y-m-d'))), $sessionId),
                'store_day_session_id' => $sessionId,
                'store_id' => (int) ($session['store_id'] ?? 0),
                'status' => $resolved ? 'resolved' : ($reviewStatus === 'needs_investigation' ? 'investigating' : 'open'),
                'owner_user_id' => $resolved ? null : ($session['reviewed_by'] ?? null),
                'opened_by' => $session['closed_by'] ?? null,
                'opened_at' => $openedAt,
                'resolved_by' => $resolved ? ($session['reviewed_by'] ?? null) : null,
                'resolved_at' => $resolved ? ($session['reviewed_at'] ?? null) : null,
                'disposition' => $resolved ? $reviewStatus : null,
                'created_at' => $openedAt,
                'updated_at' => (string) ($session['reviewed_at'] ?? $openedAt),
            ]);
            $caseId = (int) $this->db->insertID();
            $this->db->table('store_day_variance_case_events')->insert([
                'case_id' => $caseId,
                'actor_id' => $session['reviewed_by'] ?? $session['closed_by'] ?? null,
                'event_type' => 'legacy_snapshot',
                'to_status' => $reviewStatus,
                'note' => $session['review_note'] ?? $session['closing_note'] ?? 'Variance imported from the store-day record.',
                'evidence_json' => json_encode($this->evidence($session)),
                'created_at' => (string) ($session['reviewed_at'] ?? $openedAt),
            ]);
        }
    }

    private function evidence(array $session): array
    {
        return array_intersect_key($session, array_flip([
            'business_date', 'expected_cash', 'expected_ecash', 'counted_cash', 'counted_ecash',
            'variance_cash', 'variance_ecash', 'variance_status', 'review_status', 'closing_note',
        ]));
    }

    public function down()
    {
        $this->forge->dropTable('store_day_variance_case_events', true);
        $this->forge->dropTable('store_day_variance_cases', true);
    }
}
