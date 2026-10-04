<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

final class AddPosRequestReceipts extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'request_key'=>['type'=>'VARCHAR','constraint'=>64],
            'request_hash'=>['type'=>'VARCHAR','constraint'=>64],
            'transaction_id'=>['type'=>'INT','unsigned'=>true],
            'response_json'=>['type'=>'TEXT'],
            'created_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('request_key',true);
        $this->forge->addForeignKey('transaction_id','transactions','id','CASCADE','CASCADE');
        $this->forge->createTable('pos_request_receipts',true);
    }

    public function down() { $this->forge->dropTable('pos_request_receipts',true); }
}
