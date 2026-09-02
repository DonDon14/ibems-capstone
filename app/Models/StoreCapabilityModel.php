<?php

namespace App\Models;

use CodeIgniter\Model;

class StoreCapabilityModel extends Model
{
    protected $table = 'store_capabilities';
    protected $primaryKey = 'id';
    protected $allowedFields = ['store_id', 'capability', 'created_at', 'updated_at'];

    public function getForStore(int $storeId): array
    {
        if (!$this->db->tableExists($this->table)) {
            return ['retail'];
        }
        $values = array_column($this->where('store_id', $storeId)->orderBy('capability', 'ASC')->findAll(), 'capability');
        return $values === [] ? ['retail'] : array_values($values);
    }

    public function syncForStore(int $storeId, array $capabilities): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table($this->table)->where('store_id', $storeId)->delete();
        foreach ($capabilities as $capability) {
            $this->db->table($this->table)->insert([
                'store_id' => $storeId,
                'capability' => $capability,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
