<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\InventoryMovementModel;
use App\Models\ProductModel;
use App\Models\StoreCategoryModel;
use App\Models\StoreModel;
use Config\Database;
use Throwable;

final class StoreInventoryOperationService
{
    public function restock(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $qtyRaw = $request['qty'] ?? null;
        $qty = filter_var($qtyRaw, FILTER_VALIDATE_INT);
        $unitCost = (float) ($request['unit_cost'] ?? 0);
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $reason = trim((string) ($request['reason'] ?? 'Stock in'));

        if ($storeId <= 0 || $productId <= 0 || $qty === false || $qty <= 0 || $unitCost < 0 || $sellPrice < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid restock payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot restock this store.',
            ]);
        }

        $productModel = new ProductModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Product does not belong to the selected store.',
            ]);
        }

        if (!ProductBehaviorService::tracksStock($product)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'This offering does not track physical stock and cannot be restocked.',
            ]);
        }

        $totalCost = $unitCost * $qty;
        $profitPerPiece = $sellPrice - $unitCost;
        $expectedProfit = $profitPerPiece * $qty;
        $db = Database::connect();
        $db->transStart();

        $productModel->update($productId, ['price' => $sellPrice]);
        $productModel->addStock($productId, $qty);

        $movementModel = new InventoryMovementModel();
        $movementId = $movementModel->insert([
            'product_id' => $productId,
            'store_id' => $storeId,
            'type' => 'restock',
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'expected_profit' => $expectedProfit,
            'reason' => $reason !== '' ? $reason : 'Stock in',
            'created_at' => ibems_now()->format('Y-m-d H:i:s'),
        ]);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'RESTOCK_PRODUCT',
            'entity' => 'inventory_movements',
            'entity_id' => $movementId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'product_id' => $productId,
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'expected_profit' => $expectedProfit,
                'sell_price' => $sellPrice,
                'profit_per_piece' => $profitPerPiece,
                'reason' => $reason,
            ]),
            'created_at' => ibems_now()->format('Y-m-d H:i:s'),
        ]);

        $db->transComplete();

        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Restock failed.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'movement_id' => $movementId,
            'sell_price' => $sellPrice,
            'profit_per_piece' => $profitPerPiece,
            'expected_profit' => $expectedProfit,
            'total_cost' => $totalCost,
        ]);
    }

    public function adjustStock(array $request, int $actorId, string $role): array
    {
        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $actualQtyRaw = $request['actual_qty'] ?? null;
        $actualQty = filter_var($actualQtyRaw, FILTER_VALIDATE_INT);
        $expectedQtyRaw = $request['expected_qty'] ?? null;
        $expectedQty = $expectedQtyRaw === null ? null : filter_var($expectedQtyRaw, FILTER_VALIDATE_INT);
        $reason = trim((string) ($request['reason'] ?? 'Physical count adjustment'));
        $wasteDisposition = strtolower(trim((string) ($request['waste_disposition'] ?? '')));

        if ($storeId <= 0 || $productId <= 0 || $actualQty === false || $actualQty < 0
            || ($expectedQtyRaw !== null && ($expectedQty === false || $expectedQty < 0))
            || !in_array($wasteDisposition, ['', 'discarded', 'spoiled'], true)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid stock adjustment payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot adjust this store stock.',
            ]);
        }

        $productModel = new ProductModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Product does not belong to the selected store.',
            ]);
        }

        if (!ProductBehaviorService::tracksStock($product)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'This offering does not track physical stock and cannot have its stock adjusted.',
            ]);
        }

        $previousQty = (int) $product['stock_qty'];
        if ($expectedQty !== null && $expectedQty !== $previousQty) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'Stock changed since this product was opened. Reload it before adjusting.',
            ]);
        }
        $diffQty = $actualQty - $previousQty;
        if ($wasteDisposition !== '' && $diffQty >= 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Discarded or spoiled stock must reduce the current quantity.',
            ]);
        }

        if ($diffQty === 0) {
            return $this->result(200, [
                'status' => 'success',
                'product_id' => $productId,
                'previous_qty' => $previousQty,
                'actual_qty' => $actualQty,
                'diff_qty' => 0,
            ]);
        }

        $db = Database::connect();
        if ($wasteDisposition !== '' && !$db->fieldExists('waste_disposition', 'inventory_movements')) {
            return $this->result(503, [
                'status' => 'error',
                'message' => 'Waste classification is unavailable until the inventory migration is applied.',
            ]);
        }
        $db->transBegin();
        try {
            $db->table('products')->where('id', $productId)->where('store_id', $storeId)
                ->where('stock_qty', $previousQty)->update([
                    'stock_qty' => $actualQty,
                    'updated_at' => ibems_now()->format('Y-m-d H:i:s'),
                ]);
            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return $this->result(409, [
                    'status' => 'error',
                    'message' => 'Stock changed during this adjustment. Reload it and retry.',
                ]);
            }

            $movementValues = [
                'product_id' => $productId,
                'store_id' => $storeId,
                'type' => 'adjustment',
                'qty' => $diffQty,
                'reason' => $reason !== '' ? $reason : 'Physical count adjustment',
                'created_at' => ibems_now()->format('Y-m-d H:i:s'),
            ];
            if ($wasteDisposition !== '') {
                $movementValues['waste_disposition'] = $wasteDisposition;
            }
            $movementId = (new InventoryMovementModel())->insert($movementValues);
            if (!$movementId) {
                throw new \RuntimeException('Inventory movement insert failed.');
            }

            $auditId = (new AuditLogModel())->insert([
                'actor_id' => $actorId,
                'action' => 'ADJUST_PRODUCT_STOCK',
                'entity' => 'products',
                'entity_id' => $productId,
                'payload_json' => json_encode([
                    'store_id' => $storeId,
                    'product_id' => $productId,
                    'previous_qty' => $previousQty,
                    'actual_qty' => $actualQty,
                    'diff_qty' => $diffQty,
                    'reason' => $reason,
                    'waste_disposition' => $wasteDisposition !== '' ? $wasteDisposition : null,
                    'movement_id' => $movementId,
                ]),
                'created_at' => ibems_now()->format('Y-m-d H:i:s'),
            ]);
            if (!$auditId || !$db->transStatus()) {
                throw new \RuntimeException('Stock adjustment audit failed.');
            }
            $db->transCommit();
        } catch (Throwable) {
            $db->transRollback();
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Stock adjustment failed.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'product_id' => $productId,
            'previous_qty' => $previousQty,
            'actual_qty' => $actualQty,
            'diff_qty' => $diffQty,
        ]);
    }

    public function addProduct(array $request, int $actorId, string $role, $imageFile = null): array
    {

        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $imageUrl = trim((string) ($request['image_url'] ?? ''));
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $initialStock = (int) ($request['initial_stock'] ?? 0);
        $unitCost = (float) ($request['unit_cost'] ?? 0);
        $lowStockThreshold = max(0, (int) ($request['low_stock_threshold'] ?? 10));
        $locationBin = trim((string) ($request['location_bin'] ?? ($request['location'] ?? '')));
        $reason = trim((string) ($request['reason'] ?? 'Initial stock'));

        try {
            $behavior = ProductBehaviorService::normalize($request);
        } catch (\InvalidArgumentException $e) {
            return $this->result(400, ['status' => 'error', 'message' => $e->getMessage()]);
        }
        if ($behavior['stock_policy'] === 'untracked') {
            $initialStock = 0;
            $lowStockThreshold = 0;
        }

        if ($storeId <= 0 || $sku === '' || $name === '' || $sellPrice < 0 || $initialStock < 0 || $unitCost < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->result(403, [
                'status' => 'error',
                'message' => 'You cannot add products to this store.',
            ]);
        }

        try {
            ProductBehaviorService::assertStoreAllows($storeId, $behavior);
        } catch (\InvalidArgumentException $e) {
            return $this->result(400, ['status' => 'error', 'message' => $e->getMessage()]);
        }
        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        if ($productModel->getBySku($storeId, $sku)) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        if ($barcode !== '') {
            $db = Database::connect();
            $sameBarcode = $db->table('products')
                ->where('store_id', $storeId)
                ->where('barcode', $barcode)
                ->get()
                ->getRowArray();
            if ($sameBarcode) {
                return $this->result(409, [
                    'status' => 'error',
                    'message' => 'Barcode already exists in this store.',
                ]);
            }
        }

        try {
            $imageUrl = $this->resolveProductImageUrl($imageUrl, null, $imageFile);
        } catch (\RuntimeException $e) {
            return $this->result(400, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $productId = $productModel->insert([
            'store_id' => $storeId,
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'supplier' => $supplier !== '' ? $supplier : null,
            'image_url' => $imageUrl !== '' ? $imageUrl : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'price' => $sellPrice,
            'stock_qty' => $initialStock,
            'low_stock_threshold' => $lowStockThreshold,
            'location_bin' => $locationBin !== '' ? $locationBin : null,
            'item_type' => $behavior['item_type'],
            'stock_policy' => $behavior['stock_policy'],
            'unit_code' => $behavior['unit_code'],
            'is_active' => true,
            'updated_at' => ibems_now()->format('Y-m-d H:i:s'),
        ]);

        $movementId = null;
        if ($initialStock > 0) {
            $totalCost = $unitCost * $initialStock;
            $profitPerPiece = $sellPrice - $unitCost;
            $expectedProfit = $profitPerPiece * $initialStock;

            $movementModel = new InventoryMovementModel();
            $movementId = $movementModel->insert([
                'product_id' => $productId,
                'store_id' => $storeId,
                'type' => 'restock',
                'qty' => $initialStock,
                'unit_cost' => $unitCost,
                'item_type' => $behavior['item_type'],
                'stock_policy' => $behavior['stock_policy'],
                'unit_code' => $behavior['unit_code'],
                'total_cost' => $totalCost,
                'expected_profit' => $expectedProfit,
                'reason' => $reason !== '' ? $reason : 'Initial stock',
                'created_at' => ibems_now()->format('Y-m-d H:i:s'),
            ]);
        }

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'CREATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'sku' => $sku,
                'name' => $name,
                'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                'category' => $category,
                'supplier' => $supplier !== '' ? $supplier : null,
                'image_url' => $imageUrl,
                'barcode' => $barcode !== '' ? $barcode : null,
                'sell_price' => $sellPrice,
                'initial_stock' => $initialStock,
                'low_stock_threshold' => $lowStockThreshold,
                'location_bin' => $locationBin !== '' ? $locationBin : null,
                'unit_cost' => $unitCost,
                'movement_id' => $movementId,
            ]),
            'created_at' => ibems_now()->format('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to create product.',
            ]);
        }

        $created = $productModel->find($productId);

        return $this->result(200, [
            'status' => 'success',
            'product' => $created,
        ]);
    }

    private function resolveProductImageUrl(string $inputUrl, ?string $currentUrl, $imageFile = null): ?string
    {
        $hasFile = $imageFile && $imageFile->getError() !== UPLOAD_ERR_NO_FILE;
        if ($hasFile) {
            if (!$imageFile->isValid()) {
                throw new \RuntimeException('Invalid uploaded image file.');
            }

            return (new AssetStorageService())->storeImage($imageFile, 'product-images', $currentUrl);
        }

        return $inputUrl !== '' ? $inputUrl : $currentUrl;
    }

    /** @param array<string, mixed> $payload @return array{code:int,payload:array<string, mixed>} */
    private function result(int $code, array $payload): array
    {
        return ['code' => $code, 'payload' => $payload];
    }
}
