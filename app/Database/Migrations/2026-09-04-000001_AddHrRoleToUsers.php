<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddHrRoleToUsers extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('users') || $this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','HR','ADMIN') NOT NULL");
    }

    public function down()
    {
        if (!$this->db->tableExists('users') || $this->db->DBDriver !== 'MySQLi') {
            return;
        }

        $this->db->table('users')->where('role', 'HR')->update(['role' => 'USER']);
        $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','ADMIN') NOT NULL");
    }
}
