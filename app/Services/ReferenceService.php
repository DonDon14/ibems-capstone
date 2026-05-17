<?php

namespace App\Services;

use App\Models\TransactionModel;

class ReferenceService
{
    public function generateReference(int $storeId): string
    {
        $txnModel = new TransactionModel();
        $date = date('Ymd');

        for ($i = 0; $i < 10; $i++) {
            $random = str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $reference = sprintf('IBEMS-%s-%02d-%s', $date, $storeId, $random);

            $exists = $txnModel->where('reference_no', $reference)->first();
            if ($exists === null) {
                return $reference;
            }
        }

        return sprintf('IBEMS-%s-%02d-%s', $date, $storeId, strtoupper(bin2hex(random_bytes(2))));
    }
}
