<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRentalAndDailyFood extends Migration
{
    public function up()
    {
        $id = ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true];
        $int = ['type' => 'INT', 'unsigned' => true, 'default' => 0];
        $text = ['type' => 'TEXT'];
        $this->forge->addField(['id' => $id, 'store_id' => $int, 'product_id' => $int,
            'business_date' => ['type' => 'DATE'], 'enabled' => $int, 'capacity' => $int,
            'sold' => $int, 'price_cents' => $int]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['store_id', 'product_id', 'business_date']);
        $this->forge->createTable('daily_food_offerings', true);
        $this->forge->addField(['id' => $id, 'store_id' => $int, 'name' => ['type' => 'VARCHAR', 'constraint' => 150], 'quantity' => $int]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['store_id', 'name']);
        $this->forge->createTable('rental_assets', true);
        $this->forge->addField(['id' => $id, 'store_id' => $int, 'name' => ['type' => 'VARCHAR', 'constraint' => 150],
            'components_json' => $text, 'fee_cents' => $int, 'deposit_cents' => $int]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('rental_packages', true);
        $this->forge->addField(['id' => $id, 'store_id' => $int, 'package_id' => $int, 'product_id' => $int,
            'transaction_id' => $int, 'version' => $int, 'request_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'borrower' => ['type' => 'VARCHAR', 'constraint' => 150], 'contact' => ['type' => 'VARCHAR', 'constraint' => 150],
            'starts_on' => ['type' => 'DATE'], 'due_on' => ['type' => 'DATE'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20], 'components_json' => $text,
            'returned_json' => $text, 'fee_cents' => $int, 'deposit_cents' => $int,
            'collected_cents' => $int, 'refunded_cents' => $int]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['store_id', 'request_key']);
        $this->forge->addKey(['store_id', 'status']);
        $this->forge->createTable('rental_bookings', true);
    }

    public function down()
    {
        foreach (['rental_bookings', 'rental_packages', 'rental_assets', 'daily_food_offerings'] as $table) $this->forge->dropTable($table, true);
    }
}
