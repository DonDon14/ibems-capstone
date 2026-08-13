<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPaymentMethodImages extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('store_payment_methods') && !$this->db->fieldExists('image_url', 'store_payment_methods')) {
            $this->forge->addColumn('store_payment_methods', ['image_url' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true]]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('store_payment_methods') && $this->db->fieldExists('image_url', 'store_payment_methods')) $this->forge->dropColumn('store_payment_methods', 'image_url');
    }
}
