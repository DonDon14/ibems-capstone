<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterTransactionsPaymentMethodToVarchar extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('transactions') && $this->db->fieldExists('payment_method', 'transactions')) {
            $this->forge->modifyColumn('transactions', [
                'payment_method' => [
                    'type' => 'VARCHAR',
                    'constraint' => 50,
                    'null' => false,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('transactions') && $this->db->fieldExists('payment_method', 'transactions')) {
            $this->forge->modifyColumn('transactions', [
                'payment_method' => [
                    'type' => 'ENUM',
                    'constraint' => ['cash', 'gcash', 'card', 'bank_transfer', 'other', 'debt', 'advance_payment'],
                    'null' => false,
                ],
            ]);
        }
    }
}

