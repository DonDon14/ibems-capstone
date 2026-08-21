<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateHostedSessions extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('ci_sessions')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => false],
        ]);
        if ($this->db->DBDriver === 'Postgre') {
            $this->forge->addField('ip_address inet NOT NULL');
            $this->forge->addField('timestamp timestamptz DEFAULT CURRENT_TIMESTAMP NOT NULL');
            $this->forge->addField("data bytea DEFAULT '' NOT NULL");
        } else {
            $this->forge->addField([
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => false],
                'timestamp' => ['type' => 'DATETIME', 'null' => false],
                'data' => ['type' => 'BLOB', 'null' => false],
            ]);
        }
        $this->forge->addKey('id', true);
        $this->forge->addKey('timestamp');
        $this->forge->createTable('ci_sessions', true);
    }

    public function down()
    {
        // Hosted session history is intentionally retained.
    }
}
