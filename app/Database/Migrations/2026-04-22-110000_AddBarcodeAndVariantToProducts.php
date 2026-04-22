<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBarcodeAndVariantToProducts extends Migration
{
    public function up()
    {
        $fields = [];

        if (!$this->db->fieldExists('variant_label', 'products')) {
            $fields['variant_label'] = [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
                'after' => 'name',
            ];
        }

        if (!$this->db->fieldExists('barcode', 'products')) {
            $fields['barcode'] = [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'after' => 'image_url',
            ];
        }

        if ($fields !== []) {
            $this->forge->addColumn('products', $fields);
        }

        $this->db->query("UPDATE products SET category = 'General' WHERE category IS NULL OR category = ''");
        $this->forge->modifyColumn('products', [
            'category' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
                'default' => 'General',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_products_store_barcode ON products (store_id, barcode)');
    }

    public function down()
    {
        if ($this->db->fieldExists('barcode', 'products')) {
            $this->db->query('DROP INDEX idx_products_store_barcode ON products');
        }

        if ($this->db->fieldExists('barcode', 'products')) {
            $this->forge->dropColumn('products', 'barcode');
        }

        if ($this->db->fieldExists('variant_label', 'products')) {
            $this->forge->dropColumn('products', 'variant_label');
        }
    }
}

