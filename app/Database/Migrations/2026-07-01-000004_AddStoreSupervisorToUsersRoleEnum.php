<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStoreSupervisorToUsersRoleEnum extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','ADMIN') NOT NULL");
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('users')) {
            return;
        }

        $this->db->table('users')
            ->where('role', 'STORE_SUPERVISOR')
            ->update(['role' => 'USER']);
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','ACCOUNTING_OFFICE','ADMIN') NOT NULL");
        }
    }
}
