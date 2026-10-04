<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateStoreCategoriesTable extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('store_categories')) {
            $this->forge->addField([
                'id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'store_id' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'name' => [
                    'type' => 'VARCHAR',
                    'constraint' => 100,
                ],
                'sort_order' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'default' => 0,
                ],
                'is_active' => [
                    'type' => 'BOOLEAN',
                    'default' => true,
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['store_id', 'name']);
            $this->forge->addKey(['store_id', 'is_active']);
            $this->forge->addForeignKey('store_id', 'stores', 'id', 'RESTRICT', 'CASCADE');
            $this->forge->createTable('store_categories');
        }

        $insert = match ($this->db->DBDriver) {
            'SQLite3' => 'INSERT OR IGNORE INTO',
            'Postgre' => 'INSERT INTO',
            default => 'INSERT IGNORE INTO',
        };
        $onConflict = $this->db->DBDriver === 'Postgre' ? ' ON CONFLICT DO NOTHING' : '';
        $this->db->query(
            "{$insert} store_categories (store_id, name, sort_order, is_active, created_at, updated_at)
             SELECT s.id, 'General', 0, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             FROM stores s{$onConflict}"
        );

        $this->db->query(
            "{$insert} store_categories (store_id, name, sort_order, is_active, created_at, updated_at)
             SELECT p.store_id, p.category, 10, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             FROM products p
             WHERE p.category IS NOT NULL AND p.category <> ''{$onConflict}"
        );
    }

    public function down()
    {
        $this->forge->dropTable('store_categories', true);
    }
}

