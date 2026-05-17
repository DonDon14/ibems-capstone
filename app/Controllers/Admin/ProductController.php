<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ProductModel;
use App\Models\StoreModel;

class ProductController extends BaseController
{
    public function index()
    {
        $products = (new ProductModel())->orderBy('id', 'DESC')->findAll();
        $stores = (new StoreModel())->findAll();
        $storeMap = [];
        foreach ($stores as $store) {
            $storeMap[(int) $store['id']] = (string) $store['store_name'];
        }

        return view('admin/products/index', [
            'title' => 'Products',
            'products' => $products,
            'storeMap' => $storeMap,
        ]);
    }
}
