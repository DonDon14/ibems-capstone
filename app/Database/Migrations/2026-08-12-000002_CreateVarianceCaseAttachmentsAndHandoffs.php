<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateVarianceCaseAttachmentsAndHandoffs extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_day_variance_case_attachments')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'case_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'uploaded_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'original_name' => ['type' => 'VARCHAR', 'constraint' => 255],
                'stored_name' => ['type' => 'VARCHAR', 'constraint' => 80],
                'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100],
                'file_size' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'sha256' => ['type' => 'CHAR', 'constraint' => 64],
                'description' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['case_id', 'created_at']);
            $this->forge->createTable('store_day_variance_case_attachments', true);
        }

        if (!$this->db->tableExists('store_day_variance_case_handoffs')) {
            $this->forge->addField([
                'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'case_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'from_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'to_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'note' => ['type' => 'VARCHAR', 'constraint' => 255],
                'status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'pending'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey(['to_user_id', 'status']);
            $this->forge->addKey(['case_id', 'created_at']);
            $this->forge->createTable('store_day_variance_case_handoffs', true);
        }
    }

    public function down()
    {
        $this->forge->dropTable('store_day_variance_case_handoffs', true);
        $this->forge->dropTable('store_day_variance_case_attachments', true);
    }
}
