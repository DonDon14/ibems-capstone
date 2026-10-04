<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Allow the same primary office roles on SQLite that MySQL and PostgreSQL already accept. */
final class ExpandSQLiteOfficeRoles extends Migration
{
    private const ROLES = [
        'USER', 'STORE_SYSTEM', 'STORE_SUPERVISOR', 'ACCOUNTING_OFFICE', 'HR', 'ADMIN',
        'ACCOUNTING_HEAD', 'PAYROLL_CLERK', 'CASHIER_HEAD', 'COLLECTING_OFFICER', 'BE_DIRECTOR',
    ];

    public function up()
    {
        if ($this->db->DBDriver !== 'SQLite3' || !$this->db->tableExists('users')) {
            return;
        }

        // CI's SQLite Forge rebuilds the table. Keep child foreign keys aimed at
        // users, not at the temporary table name used during that rebuild.
        $this->db->query('PRAGMA legacy_alter_table = ON');
        try {
            $this->forge->modifyColumn('users', [
                'role' => ['type' => 'ENUM', 'constraint' => self::ROLES, 'null' => false],
            ]);
        } finally {
            $this->db->query('PRAGMA legacy_alter_table = OFF');
        }
        $this->db->resetDataCache();
    }

    public function down()
    {
        // Do not narrow the role set while any new office role may be assigned.
    }
}
