<?php

namespace App\Services;

use App\Models\AuditLogModel;
use App\Models\ProductModel;
use App\Models\StoreCategoryModel;
use App\Models\StoreModel;
use Config\Database;

final class AdminProductService
{
    public function data(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $storeId = (int) ($filters['store_id'] ?? null ?? 0);
        $stockStatus = trim((string) ($filters['stock_status'] ?? ''));
        $category = trim((string) ($filters['category'] ?? ''));
        $supplier = trim((string) ($filters['supplier'] ?? ''));
        $includeInactive = (int) ($filters['include_inactive'] ?? null ?? 0) === 1;
        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? null ?? 'store')));
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? null ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $page = max(1, (int) ($filters['page'] ?? null ?? 1));
        $pageSize = max(10, min(100, (int) ($filters['page_size'] ?? null ?? 25)));
        $offset = ($page - 1) * $pageSize;
        $sortColumns = [
            'store' => 's.store_name',
            'name' => 'p.name',
            'category' => 'p.category',
            'supplier' => 'p.supplier',
            'price' => 'p.price',
            'stock' => 'p.stock_qty',
            'updated' => 'p.updated_at',
        ];
        $sortColumn = $sortColumns[$sortBy] ?? $sortColumns['store'];

        $db = Database::connect();
        $query = $db->table('products p')
            ->select('p.id, p.store_id, p.sku, p.name, p.variant_label, p.category, p.supplier, p.location_bin, p.barcode, p.image_url, p.price, p.stock_qty, p.item_type, p.stock_policy, p.unit_code, COALESCE(p.low_stock_threshold, 10) AS low_stock_threshold, p.is_active, p.updated_at, s.store_name')
            ->join('stores s', 's.id = p.store_id', 'inner');

        if ($storeId > 0) {
            $query->where('p.store_id', $storeId);
        }
        if ($category !== '') {
            $query->where('p.category', $category);
        }
        if ($supplier !== '') {
            $query->where('p.supplier', $supplier);
        }
        if ($stockStatus === 'out') {
            $query->where('p.stock_qty <=', 0);
        } elseif ($stockStatus === 'low') {
            $query->where('p.stock_qty >', 0);
            $query->where('p.stock_qty <= COALESCE(p.low_stock_threshold, 10)', null, false);
        } elseif ($stockStatus === 'healthy') {
            $query->where('p.stock_qty > COALESCE(p.low_stock_threshold, 10)', null, false);
        }
        if (!$includeInactive) {
            $query->where('p.is_active', true);
        }
        if ($q !== '') {
            $query->groupStart()
                ->like('p.name', $q)
                ->orLike('p.sku', $q)
                ->orLike('p.category', $q)
                ->orLike('p.supplier', $q)
                ->orLike('p.location_bin', $q)
                ->orLike('p.barcode', $q)
                ->orLike('s.store_name', $q)
                ->groupEnd();
        }

        $total = (clone $query)->countAllResults();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $pageSize;
        }

        $rows = $query->orderBy($sortColumn, $sortDir)
            ->orderBy('p.name', 'ASC')
            ->orderBy('p.id', 'ASC')
            ->limit($pageSize, $offset)
            ->get()
            ->getResultArray();

        $summaryQuery = $db->table('products p')
            ->select('COUNT(*) AS total_products, SUM(CASE WHEN p.is_active = TRUE THEN 1 ELSE 0 END) AS active_products, SUM(CASE WHEN p.is_active = TRUE AND p.stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock, SUM(CASE WHEN p.is_active = TRUE AND p.stock_qty > 0 AND p.stock_qty <= COALESCE(p.low_stock_threshold, 10) THEN 1 ELSE 0 END) AS low_stock, SUM(CASE WHEN p.is_active = FALSE THEN 1 ELSE 0 END) AS inactive_products')
            ->join('stores s', 's.id = p.store_id', 'inner');
        if ($storeId > 0) {
            $summaryQuery->where('p.store_id', $storeId);
        }
        $summary = $summaryQuery->get()->getRowArray() ?? [];

        $stores = $db->table('stores')
            ->select('id, store_name')
            ->where('is_active', true)
            ->orderBy('store_name', 'ASC')
            ->get()
            ->getResultArray();

        $categories = $db->table('products')
            ->select('category')
            ->where('category IS NOT NULL', null, false)
            ->where('category !=', '')
            ->groupBy('category')
            ->orderBy('category', 'ASC')
            ->get()
            ->getResultArray();

        $suppliers = $db->table('products')
            ->select('supplier')
            ->where('supplier IS NOT NULL', null, false)
            ->where('supplier !=', '')
            ->groupBy('supplier')
            ->orderBy('supplier', 'ASC')
            ->get()
            ->getResultArray();

        return $this->result(200, [
            'status' => 'success',
            'summary' => [
                'total_products' => (int) ($summary['total_products'] ?? 0),
                'active_products' => (int) ($summary['active_products'] ?? 0),
                'out_of_stock' => (int) ($summary['out_of_stock'] ?? 0),
                'low_stock' => (int) ($summary['low_stock'] ?? 0),
                'inactive_products' => (int) ($summary['inactive_products'] ?? 0),
                'visible_products' => $total,
            ],
            'stores' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'store_name' => (string) $row['store_name'],
                ];
            }, $stores),
            'categories' => array_values(array_map(static fn(array $row): string => (string) ($row['category'] ?? ''), $categories)),
            'suppliers' => array_values(array_map(static fn(array $row): string => (string) ($row['supplier'] ?? ''), $suppliers)),
            'data' => array_map(static function (array $row): array {
                $stockQty = (int) ($row['stock_qty'] ?? 0);
                $threshold = (int) ($row['low_stock_threshold'] ?? 10);
                $stockStatus = $stockQty <= 0 ? 'out' : ($stockQty <= $threshold ? 'low' : 'healthy');
                return [
                    'id' => (int) $row['id'],
                    'store_id' => (int) $row['store_id'],
                    'store_name' => (string) $row['store_name'],
                    'sku' => (string) ($row['sku'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'variant_label' => (string) ($row['variant_label'] ?? ''),
                    'category' => (string) ($row['category'] ?? ''),
                    'supplier' => (string) ($row['supplier'] ?? ''),
                    'location_bin' => (string) ($row['location_bin'] ?? ''),
                    'barcode' => (string) ($row['barcode'] ?? ''),
                    'image_url' => (string) ($row['image_url'] ?? ''),
                    'price' => (float) ($row['price'] ?? 0),
                    'stock_qty' => $stockQty,
                    'low_stock_threshold' => $threshold,
                    'stock_status' => $stockStatus,
                    'is_active' => ibems_bool($row['is_active'] ?? false),
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }, $rows),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    public function update(array $request, int $actorId, $imageFile = null): array
    {

        $productId = (int) ($request['product_id'] ?? 0);
        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));
        $price = (float) ($request['price'] ?? -1);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($productId <= 0 || $storeId <= 0 || $sku === '' || $name === '' || $price < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $db = Database::connect();
        $productModel = new \App\Models\ProductModel();
        $auditLogModel = new AuditLogModel();

        $product = $productModel->find($productId);
        if (!$product || (int) ($product['store_id'] ?? 0) !== $storeId) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Product not found.',
            ]);
        }

        $sameSku = $db->table('products')
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->where('id !=', $productId)
            ->get()
            ->getRowArray();
        if ($sameSku) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        if ($barcode !== '') {
            $sameBarcode = $db->table('products')
                ->where('store_id', $storeId)
                ->where('barcode', $barcode)
                ->where('id !=', $productId)
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
            $resolvedImageUrl = $this->resolveProductImageUrl($inputImageUrl, $product['image_url'] ?? null, $imageFile);
        } catch (\RuntimeException $e) {
            return $this->result(400, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $productModel->update($productId, [
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category !== '' ? $category : 'General',
            'barcode' => $barcode !== '' ? $barcode : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'price' => $price,
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_UPDATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'before' => [
                    'sku' => $product['sku'] ?? '',
                    'name' => $product['name'] ?? '',
                    'variant_label' => $product['variant_label'] ?? null,
                    'category' => $product['category'] ?? '',
                    'barcode' => $product['barcode'] ?? null,
                    'image_url' => $product['image_url'] ?? null,
                    'price' => (float) ($product['price'] ?? 0),
                    'is_active' => ibems_bool($product['is_active'] ?? false),
                ],
                'after' => [
                    'sku' => $sku,
                    'name' => $name,
                    'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                    'category' => $category !== '' ? $category : 'General',
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
                    'price' => $price,
                    'is_active' => (bool) $isActive,
                ],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to update product.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'product' => $productModel->find($productId),
        ]);
    }

    public function create(array $request, int $actorId, $imageFile = null): array
    {

        $storeId = (int) ($request['store_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));
        $price = (float) ($request['price'] ?? -1);
        $stockQty = (int) ($request['stock_qty'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? 1) === 1;

        if ($storeId <= 0 || $sku === '' || $name === '' || $price < 0 || $stockQty < 0) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid product payload.',
            ]);
        }

        $storeModel = new StoreModel();
        $store = $storeModel->find($storeId);
        if (!$store) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Store not found.',
            ]);
        }

        $db = Database::connect();
        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        $auditLogModel = new AuditLogModel();

        if ($productModel->where('store_id', $storeId)->where('sku', $sku)->first()) {
            return $this->result(409, [
                'status' => 'error',
                'message' => 'SKU already exists in this store.',
            ]);
        }

        if ($barcode !== '') {
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

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        try {
            $resolvedImageUrl = $this->resolveProductImageUrl($inputImageUrl, null, $imageFile);
        } catch (\RuntimeException $e) {
            return $this->result(400, [
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $db->transStart();

        $productId = $productModel->insert([
            'store_id' => $storeId,
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'barcode' => $barcode !== '' ? $barcode : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'price' => $price,
            'stock_qty' => $stockQty,
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_CREATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => (int) $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'sku' => $sku,
                'name' => $name,
                'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                'category' => $category,
                'barcode' => $barcode !== '' ? $barcode : null,
                'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
                'price' => $price,
                'stock_qty' => $stockQty,
                'is_active' => (bool) $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus() || !$productId) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to create product.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
            'product' => $productModel->find($productId),
        ]);
    }

    public function toggleStatus(array $request, int $actorId): array
    {
        $productId = (int) ($request['product_id'] ?? 0);
        $isActive = (int) ($request['is_active'] ?? -1);

        if ($productId <= 0 || !in_array($isActive, [0, 1], true)) {
            return $this->result(400, [
                'status' => 'error',
                'message' => 'Invalid status payload.',
            ]);
        }

        $productModel = new ProductModel();
        $auditLogModel = new AuditLogModel();
        $product = $productModel->find($productId);
        if (!$product) {
            return $this->result(404, [
                'status' => 'error',
                'message' => 'Product not found.',
            ]);
        }

        $db = Database::connect();
        $db->transStart();

        $productModel->update($productId, [
            'is_active' => $isActive,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'ADMIN_TOGGLE_PRODUCT_STATUS',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => (int) ($product['store_id'] ?? 0),
                'sku' => (string) ($product['sku'] ?? ''),
                'previous_is_active' => (int) ($product['is_active'] ?? 0),
                'new_is_active' => $isActive,
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->result(500, [
                'status' => 'error',
                'message' => 'Failed to update product status.',
            ]);
        }

        return $this->result(200, [
            'status' => 'success',
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
