<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSalaryImportBatchesTable extends Migration
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
            'filename' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'imported_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'imported_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'total_rows' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'valid_rows' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'invalid_rows' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('imported_by', 'users', 'id', 'RESTRICT', 'CASCADE');

        $this->forge->createTable('salary_import_batches');
    }

    public function down()
    {
        $this->forge->dropTable('salary_import_batches');
    }
}
