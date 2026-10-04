<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddDepartmentSettlementReceipts extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'request_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'request_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'settlement_entry_id' => ['type' => 'INT', 'unsigned' => true],
            'response_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('request_key', true);
        $this->forge->addForeignKey('settlement_entry_id', 'department_debt_entries', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('department_settlement_receipts', true);
    }

    public function down()
    {
        $this->forge->dropTable('department_settlement_receipts', true);
    }
}
