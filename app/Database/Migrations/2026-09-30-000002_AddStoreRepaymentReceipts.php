<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddStoreRepaymentReceipts extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'request_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'request_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'cash_movement_id' => ['type' => 'INT', 'unsigned' => true],
            'response_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('request_key', true);
        $this->forge->addForeignKey('cash_movement_id', 'store_cash_movements', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('store_repayment_receipts', true);
    }

    public function down()
    {
        $this->forge->dropTable('store_repayment_receipts', true);
    }
}
