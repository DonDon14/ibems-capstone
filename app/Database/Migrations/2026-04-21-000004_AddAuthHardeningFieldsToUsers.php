<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAuthHardeningFieldsToUsers extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('failed_login_attempts', 'users')) {
            $this->forge->addColumn('users', [
                'failed_login_attempts' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 0,
                    'after' => 'is_active',
                ],
            ]);
        }

        if (! $this->db->fieldExists('locked_until', 'users')) {
            $this->forge->addColumn('users', [
                'locked_until' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'failed_login_attempts',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('locked_until', 'users')) {
            $this->forge->dropColumn('users', 'locked_until');
        }

        if ($this->db->fieldExists('failed_login_attempts', 'users')) {
            $this->forge->dropColumn('users', 'failed_login_attempts');
        }
    }
}
