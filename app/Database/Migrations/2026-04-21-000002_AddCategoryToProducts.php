<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCategoryToProducts extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('category', 'products')) {
            $this->forge->addColumn('products', [
                'category' => [
                    'type' => 'VARCHAR',
                    'constraint' => 80,
                    'null' => false,
                    'default' => 'General',
                    'after' => 'name',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('category', 'products')) {
            $this->forge->dropColumn('products', 'category');
        }
    }
}
