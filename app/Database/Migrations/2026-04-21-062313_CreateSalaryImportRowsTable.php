<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSalaryImportRowsTable extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'batch_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'employee_id' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
            ],
            'monthly_salary' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0.00,
            ],
            'status' => [
                'type' => 'ENUM',
                'constraint' => ['valid', 'invalid'],
                'default' => 'valid',
            ],
            'error_msg' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('batch_id', 'salary_import_batches', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('salary_import_rows');
    }

    public function down()
    {
        $this->forge->dropTable('salary_import_rows');
    }
}
