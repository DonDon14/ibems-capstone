<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExpandNotificationsForPortalAndEmail extends Migration
{
    public function up()
    {
        $this->forge->modifyColumn('notifications', [
            'txn_id' => [
                'name' => 'txn_id',
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
        ]);

        $this->forge->addColumn('notifications', [
            'audit_log_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'after' => 'txn_id'],
            'notification_type' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'activity', 'after' => 'audit_log_id'],
            'title' => ['type' => 'VARCHAR', 'constraint' => 180, 'default' => 'IBEMS activity', 'after' => 'notification_type'],
            'message' => ['type' => 'TEXT', 'null' => true, 'after' => 'title'],
            'link_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'message'],
            'icon' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'bi bi-bell', 'after' => 'link_url'],
            'severity' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'info', 'after' => 'icon'],
            'read_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'severity'],
            'email_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending', 'after' => 'read_at'],
            'email_attempts' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0, 'after' => 'email_status'],
            'email_next_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'email_attempts'],
            'email_last_attempt_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'email_next_attempt_at'],
            'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'after' => 'email_last_attempt_at'],
            'created_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'error_msg'],
        ]);

        $this->db->query('CREATE INDEX notifications_user_created_idx ON notifications (user_id, created_at)');
        $this->db->query('CREATE INDEX notifications_email_queue_idx ON notifications (email_status, email_next_attempt_at)');
        $this->db->query('CREATE UNIQUE INDEX notifications_dedupe_key_uidx ON notifications (dedupe_key)');
    }

    public function down()
    {
        foreach (['notifications_dedupe_key_uidx', 'notifications_email_queue_idx', 'notifications_user_created_idx'] as $index) {
            try {
                $this->db->query('DROP INDEX ' . $index . ' ON notifications');
            } catch (\Throwable) {
                // Keep rollback portable across supported database drivers.
            }
        }

        $this->forge->dropColumn('notifications', [
            'audit_log_id', 'notification_type', 'title', 'message', 'link_url', 'icon', 'severity',
            'read_at', 'email_status', 'email_attempts', 'email_next_attempt_at', 'email_last_attempt_at',
            'dedupe_key', 'created_at',
        ]);
    }
}
