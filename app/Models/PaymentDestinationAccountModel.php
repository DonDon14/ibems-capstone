<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentDestinationAccountModel extends Model
{
    protected $table = 'payment_destination_accounts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['store_id', 'payment_method_id', 'account_name', 'account_number', 'image_url', 'sort_order', 'is_active', 'created_at', 'updated_at'];

    public function activeForMethod(int $storeId, int $methodId): array
    {
        return $this->where('store_id', $storeId)->where('payment_method_id', $methodId)->where('is_active', true)
            ->orderBy('sort_order', 'ASC')->orderBy('account_name', 'ASC')->findAll();
    }
}
