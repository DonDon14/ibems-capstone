<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCategoryAndImageToProductsTable extends Migration
{
    public function up()
    {
        $fields = [];

        if (!$this->db->fieldExists('category', 'products')) {
            $fields['category'] = [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'name',
            ];
        }

        if (!$this->db->fieldExists('image_url', 'products')) {
            $fields['image_url'] = [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'category',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('products', $fields);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('image_url', 'products')) {
            $this->forge->dropColumn('products', 'image_url');
        }

        if ($this->db->fieldExists('category', 'products')) {
            $this->forge->dropColumn('products', 'category');
        }
    }
}
