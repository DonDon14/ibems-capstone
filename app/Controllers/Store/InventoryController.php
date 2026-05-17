<?php

namespace App\Controllers\Store;

use App\Controllers\BaseController;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Services\AuditService;

class InventoryController extends BaseController
{
    public function index()
    {
        $store = $this->getAssignedStore();
        if ($store === null) {
            return redirect()->to('/dashboard')->with('error', 'No store is assigned to your account.');
        }

        $products = (new ProductModel())
            ->where('store_id', (int) $store['id'])
            ->orderBy('id', 'DESC')
            ->findAll();

        return view('store/inventory/index', [
            'title' => 'Store Inventory',
            'store' => $store,
            'products' => $products,
        ]);
    }

    public function createProduct()
    {
        $store = $this->getAssignedStore();
        if ($store === null) {
            return redirect()->to('/dashboard')->with('error', 'No store is assigned to your account.');
        }

        $rules = [
            'sku' => 'required|max_length[50]',
            'name' => 'required|max_length[120]',
            'category' => 'required|max_length[80]',
            'price' => 'required|decimal',
            'stock_qty' => 'required|integer|greater_than_equal_to[0]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->to('/store/inventory')->withInput()->with('error', 'Invalid product input.');
        }

        $photo = $this->request->getFile('photo');
        if ($photo !== null && $photo->getError() !== UPLOAD_ERR_NO_FILE) {
            $photoRules = [
                'photo' => 'uploaded[photo]|is_image[photo]|max_size[photo,4096]|mime_in[photo,image/jpg,image/jpeg,image/png,image/webp]',
            ];
            if (! $this->validate($photoRules)) {
                return redirect()->to('/store/inventory')->withInput()->with('error', 'Invalid product photo.');
            }
        }

        $productModel = new ProductModel();
        $sku = trim((string) $this->request->getPost('sku'));
        $existing = $productModel
            ->where('store_id', (int) $store['id'])
            ->where('sku', $sku)
            ->first();
        if ($existing !== null) {
            return redirect()->to('/store/inventory')->withInput()->with('error', 'SKU already exists in your store inventory.');
        }

        $imagePath = $this->moveProductPhoto('photo');
        $data = [
            'store_id' => (int) $store['id'],
            'sku' => $sku,
            'name' => trim((string) $this->request->getPost('name')),
            'category' => trim((string) $this->request->getPost('category')) ?: 'General',
            'image_path' => $imagePath,
            'price' => (float) $this->request->getPost('price'),
            'stock_qty' => (int) $this->request->getPost('stock_qty'),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $productId = (int) $productModel->insert($data, true);

        if ((int) $data['stock_qty'] > 0) {
            (new InventoryMovementModel())->insert([
                'product_id' => $productId,
                'store_id' => (int) $store['id'],
                'type' => 'restock',
                'qty' => (int) $data['stock_qty'],
                'reason' => 'Initial stock',
                'txn_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new AuditService())->log(current_user_id(), 'CREATE', 'products', (string) $productId, $data);

        return redirect()->to('/store/inventory')->with('success', 'Product added to store inventory.');
    }

    public function updateStock(int $productId)
    {
        $store = $this->getAssignedStore();
        if ($store === null) {
            return redirect()->to('/dashboard')->with('error', 'No store is assigned to your account.');
        }

        $rules = [
            'type' => 'required|in_list[restock,adjustment]',
            'qty' => 'required|integer|greater_than[0]',
            'reason' => 'permit_empty|max_length[120]',
        ];
        if (! $this->validate($rules)) {
            return redirect()->to('/store/inventory')->with('error', 'Invalid stock adjustment input.');
        }

        $productModel = new ProductModel();
        $product = $this->getStoreProduct($productModel, $store, $productId);
        if ($product === null) {
            return redirect()->to('/store/inventory')->with('error', 'Product not found in your assigned store.');
        }

        $type = (string) $this->request->getPost('type');
        $qty = (int) $this->request->getPost('qty');
        $reason = trim((string) $this->request->getPost('reason'));
        $currentQty = (int) $product['stock_qty'];
        $newQty = $type === 'restock' ? $currentQty + $qty : max(0, $currentQty - $qty);

        $productModel->update($productId, [
            'stock_qty' => $newQty,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new InventoryMovementModel())->insert([
            'product_id' => $productId,
            'store_id' => (int) $store['id'],
            'type' => $type,
            'qty' => $qty,
            'reason' => $reason !== '' ? $reason : ($type === 'restock' ? 'Manual restock' : 'Manual adjustment'),
            'txn_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        (new AuditService())->log(current_user_id(), 'UPDATE_STOCK', 'products', (string) $productId, [
            'type' => $type,
            'qty' => $qty,
            'before' => $currentQty,
            'after' => $newQty,
        ]);

        return redirect()->to('/store/inventory')->with('success', 'Stock updated.');
    }

    public function updatePhoto(int $productId)
    {
        $store = $this->getAssignedStore();
        if ($store === null) {
            return redirect()->to('/dashboard')->with('error', 'No store is assigned to your account.');
        }

        $rules = [
            'photo' => 'required|uploaded[photo]|is_image[photo]|max_size[photo,4096]|mime_in[photo,image/jpg,image/jpeg,image/png,image/webp]',
        ];
        if (! $this->validate($rules)) {
            return redirect()->to('/store/inventory')->with('error', 'Invalid product photo.');
        }

        $productModel = new ProductModel();
        $product = $this->getStoreProduct($productModel, $store, $productId);
        if ($product === null) {
            return redirect()->to('/store/inventory')->with('error', 'Product not found in your assigned store.');
        }

        $imagePath = $this->moveProductPhoto('photo');
        $productModel->update($productId, [
            'image_path' => $imagePath,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new AuditService())->log(current_user_id(), 'UPDATE_PHOTO', 'products', (string) $productId, ['image_path' => $imagePath]);

        return redirect()->to('/store/inventory')->with('success', 'Product photo updated.');
    }

    public function toggleStatus(int $productId)
    {
        $store = $this->getAssignedStore();
        if ($store === null) {
            return redirect()->to('/dashboard')->with('error', 'No store is assigned to your account.');
        }

        $productModel = new ProductModel();
        $product = $this->getStoreProduct($productModel, $store, $productId);
        if ($product === null) {
            return redirect()->to('/store/inventory')->with('error', 'Product not found in your assigned store.');
        }

        $next = (int) ($product['is_active'] ?? 1) === 1 ? 0 : 1;
        $productModel->update($productId, [
            'is_active' => $next,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new AuditService())->log(current_user_id(), 'TOGGLE_STATUS', 'products', (string) $productId, ['is_active' => $next]);

        return redirect()->to('/store/inventory')->with('success', $next === 1 ? 'Product activated.' : 'Product deactivated.');
    }

    private function getAssignedStore(): ?array
    {
        $userId = current_user_id();
        if ($userId === null) {
            return null;
        }

        return (new StoreModel())
            ->where('officer_id', $userId)
            ->where('is_active', 1)
            ->first();
    }

    private function getStoreProduct(ProductModel $productModel, array $store, int $productId): ?array
    {
        return $productModel
            ->where('id', $productId)
            ->where('store_id', (int) $store['id'])
            ->first();
    }

    private function moveProductPhoto(string $field): ?string
    {
        $file = $this->request->getFile($field);
        if ($file === null || ! $file->isValid() || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $targetDir = FCPATH . 'uploads/products';
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $newName = $file->getRandomName();
        $file->move($targetDir, $newName, true);

        return '/uploads/products/' . $newName;
    }
}
