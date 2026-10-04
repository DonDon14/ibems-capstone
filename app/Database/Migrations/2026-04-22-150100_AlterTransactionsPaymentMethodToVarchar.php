<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterTransactionsPaymentMethodToVarchar extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('transactions') && $this->db->fieldExists('payment_method', 'transactions')) {
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query('PRAGMA legacy_alter_table = ON');
            }
            try {
                $this->forge->modifyColumn('transactions', [
                    'payment_method' => [
                        'type' => 'VARCHAR',
                        'constraint' => 50,
                        'null' => false,
                    ],
                ]);
            } finally {
                if ($this->db->DBDriver === 'SQLite3') {
                    $this->db->query('PRAGMA legacy_alter_table = OFF');
                }
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('transactions') && $this->db->fieldExists('payment_method', 'transactions')) {
            if ($this->db->DBDriver === 'SQLite3') {
                $this->db->query('PRAGMA legacy_alter_table = ON');
            }
            try {
                $this->forge->modifyColumn('transactions', [
                    'payment_method' => [
                        'type' => 'ENUM',
                        'constraint' => ['cash', 'gcash', 'card', 'bank_transfer', 'other', 'debt', 'advance_payment'],
                        'null' => false,
                    ],
                ]);
            } finally {
                if ($this->db->DBDriver === 'SQLite3') {
                    $this->db->query('PRAGMA legacy_alter_table = OFF');
                }
            }
        }
    }
}

