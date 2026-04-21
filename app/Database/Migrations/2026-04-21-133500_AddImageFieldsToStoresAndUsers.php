<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImageFieldsToStoresAndUsers extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('logo_url', 'stores')) {
            $this->forge->addColumn('stores', [
                'logo_url' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'null' => true,
                    'after' => 'officer_id',
                ],
            ]);
        }

        if (!$this->db->fieldExists('profile_image_url', 'users')) {
            $this->forge->addColumn('users', [
                'profile_image_url' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                    'null' => true,
                    'after' => 'password_hash',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('logo_url', 'stores')) {
            $this->forge->dropColumn('stores', 'logo_url');
        }

        if ($this->db->fieldExists('profile_image_url', 'users')) {
            $this->forge->dropColumn('users', 'profile_image_url');
        }
    }
}
