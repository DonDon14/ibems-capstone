<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class BackfillProductSupplierAndLocationSamples extends Migration
{
    public function up()
    {
        $samples = [
            'MCS-COF-001' => ['supplier' => 'USTP Cafeteria Supply', 'location_bin' => 'Counter A'],
            'MCS-BRD-002' => ['supplier' => 'Campus Bakery', 'location_bin' => 'Rack B1'],
            'MCS-WTR-003' => ['supplier' => 'Claveria Water Depot', 'location_bin' => 'Cooler 1'],
            'TAS-USB-001' => ['supplier' => 'Tech Essentials PH', 'location_bin' => 'Cabinet T2'],
            'TAS-NBK-002' => ['supplier' => 'School Supplies Hub', 'location_bin' => 'Shelf S3'],
        ];

        foreach ($samples as $sku => $values) {
            $this->db->table('products')
                ->where('sku', $sku)
                ->groupStart()
                    ->where('supplier IS NULL', null, false)
                    ->orWhere('supplier', '')
                    ->orWhere('location_bin IS NULL', null, false)
                    ->orWhere('location_bin', '')
                ->groupEnd()
                ->update([
                    'supplier' => $values['supplier'],
                    'location_bin' => $values['location_bin'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        }
    }

    public function down()
    {
        $skus = ['MCS-COF-001', 'MCS-BRD-002', 'MCS-WTR-003', 'TAS-USB-001', 'TAS-NBK-002'];

        $this->db->table('products')
            ->whereIn('sku', $skus)
            ->update([
                'supplier' => null,
                'location_bin' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
