<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

class AddOfficePortalRoles extends Migration
{
    private const NEW_ROLES = ['ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'BE_DIRECTOR'];

    public function up()
    {
        if (!$this->db->tableExists('users')) {
            return;
        }

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','HR','ADMIN','ACCOUNTING_HEAD','PAYROLL_CLERK','CASHIER_HEAD','COLLECTING_OFFICER','BE_DIRECTOR') NOT NULL");
            return;
        }

        if ($this->db->DBDriver === 'Postgre') {
            foreach (['users', 'user_roles'] as $table) {
                if (!$this->db->tableExists($table)) {
                    continue;
                }
                $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_role_check");
                $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$table}_role_check CHECK (role IN ('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','HR','ADMIN','ACCOUNTING_HEAD','PAYROLL_CLERK','CASHIER_HEAD','COLLECTING_OFFICER','BE_DIRECTOR'))");
            }
        }
    }

    public function down()
    {
        foreach (['users', 'user_roles'] as $table) {
            if (!$this->db->tableExists($table)) {
                continue;
            }
            if ($this->db->table($table)->whereIn('role', self::NEW_ROLES)->countAllResults() > 0) {
                throw new RuntimeException('Cannot remove office portal roles while assigned accounts exist.');
            }
        }

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query("ALTER TABLE users MODIFY role ENUM('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','HR','ADMIN') NOT NULL");
            return;
        }

        if ($this->db->DBDriver === 'Postgre') {
            foreach (['users', 'user_roles'] as $table) {
                if (!$this->db->tableExists($table)) {
                    continue;
                }
                $this->db->query("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_role_check");
                $this->db->query("ALTER TABLE {$table} ADD CONSTRAINT {$table}_role_check CHECK (role IN ('USER','STORE_SYSTEM','STORE_SUPERVISOR','ACCOUNTING_OFFICE','HR','ADMIN'))");
            }
        }
    }
}
