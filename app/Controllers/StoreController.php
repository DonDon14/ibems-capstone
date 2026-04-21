<?php

namespace App\Controllers;

use App\Models\ProductModel;

class StoreController extends BaseController
{
    public function pos()
    {
        return view('store/pos');
    }

    public function products()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 1);

        if ($storeId <= 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid store_id.',
            ]);
        }

        $productModel = new ProductModel();
        $products = $productModel->getActiveProductsByStore($storeId);

        return $this->response->setJSON([
            'status' => 'success',
            'store_id' => $storeId,
            'products' => $products,
        ]);
    }
}
