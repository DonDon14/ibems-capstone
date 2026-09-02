<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\StoreModel;
use App\Models\StoreCategoryModel;
use App\Models\StorePaymentMethodModel;
use App\Models\PaymentDestinationAccountModel;
use App\Models\StoreOpeningBalanceModel;
use App\Models\StoreCashMovementModel;
use App\Models\StoreDaySessionModel;
use App\Models\InventoryMovementModel;
use App\Models\AuditLogModel;
use App\Models\BalanceModel;
use App\Models\DebtCashbookEntryModel;
use App\Models\StoreCapabilityModel;
use App\Services\ProductBehaviorService;
use App\Services\StoreAccessService;
use App\Services\StoreDayVarianceCaseService;
use App\Services\StoreDayExpectedService;
use App\Services\AssetStorageService;
use App\Services\StoreReportService;
use App\Services\StoreDashboardService;
use App\Services\StoreCatalogQueryService;
use App\Services\StoreTransactionQueryService;
use App\Services\StoreInventoryQueryService;
use App\Services\StoreDayOperationService;
use App\Services\StoreCashOperationService;
use App\Services\StoreConfigurationService;
use App\Services\StoreInventoryOperationService;
use App\Services\StoreReportRequestService;
use Config\Database;

class StoreController extends BaseController
{
    public function dashboard()
    {
        $data = (new StoreDashboardService())->data(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            (string) ($this->request->getGet('period') ?? 'day')
        );

        return view('store/dashboard', $data);
    }

    public function pos()
    {
        return view('store/pos');
    }

    public function history()
    {
        return view('store/history');
    }

    public function inventory()
    {
        return view('store/inventory');
    }

    public function staffRecords()
    {
        return view('store/staff-records');
    }

    public function reports()
    {
        return view('store/reports');
    }

    public function receiptPage(int $transactionId)
    {
        return view('store/receipt', [
            'transaction_id' => $transactionId,
        ]);
    }

    public function settings()
    {
        return view('store/settings');
    }

    public function reportSummary()
    {
        $filters = $this->request->getGet();

        return $this->respondWithServiceResult((new StoreReportRequestService())->summary(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            is_array($filters) ? $filters : []
        ));
    }

    public function daySessionStatus()
    {
        $result = (new StoreDayOperationService())->status(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            (int) ($this->request->getGet('store_id') ?? 0)
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function openDaySession()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $result = (new StoreDayOperationService())->open(
            is_array($request) ? $request : [],
            (int) session()->get('user_id'),
            (string) session()->get('role')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function closeDaySession()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $result = (new StoreDayOperationService())->close(
            is_array($request) ? $request : [],
            (int) session()->get('user_id'),
            (string) session()->get('role')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function openingBalanceStatus()
    {
        $result = (new StoreCashOperationService())->openingStatus(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            (int) ($this->request->getGet('store_id') ?? 0)
        );

        return $this->respondWithServiceResult($result);
    }

    public function setOpeningBalance()
    {
        return $this->respondWithServiceResult((new StoreCashOperationService())->setOpening(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function resetOpeningBalance()
    {
        return $this->respondWithServiceResult((new StoreCashOperationService())->resetOpening(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function cashMovements()
    {
        $filters = $this->request->getGet();

        return $this->respondWithServiceResult((new StoreCashOperationService())->movements(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            is_array($filters) ? $filters : []
        ));
    }

    public function createCashMovement()
    {
        return $this->respondWithServiceResult((new StoreCashOperationService())->createMovement(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function createDebtRepayment()
    {
        return $this->respondWithServiceResult((new StoreCashOperationService())->createDebtRepayment(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function products()
    {
        $storeId = (int) ($this->request->getGet('store_id') ?? 1);
        if ($storeId <= 0) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid store_id.']);
        }
        if (!(new StoreModel())->canUserAccessStore((int) session()->get('user_id'), (string) session()->get('role'), $storeId)) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        }

        return $this->response->setJSON((new StoreCatalogQueryService())->products($storeId));
    }

    public function myStores()
    {
        $role = (string) session()->get('role');
        if (!in_array($role, ['STORE_SYSTEM', 'STORE_SUPERVISOR', 'ADMIN'], true)) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'Forbidden']);
        }
        $stores = (new StoreModel())->getAccessibleStores((int) session()->get('user_id'), $role);

        return $this->response->setJSON((new StoreCatalogQueryService())->stores($stores));
    }

    public function categories()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getGet('store_id') ?? 0));
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        }

        return $this->response->setJSON((new StoreCatalogQueryService())->categories(Database::connect(), (int) $store['id']));
    }

    public function capabilities()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getGet('store_id') ?? 0));
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        }

        return $this->response->setJSON((new StoreCatalogQueryService())->capabilities((int) $store['id']));
    }

    public function updateCapabilities()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->updateCapabilities(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function createCategory()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->createCategory(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function updateCategory()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->updateCategory(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function deleteCategory()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->deleteCategory(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function paymentMethods()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getGet('store_id') ?? 0));
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        }

        return $this->response->setJSON((new StoreCatalogQueryService())->paymentMethods(Database::connect(), (int) $store['id']));
    }

    public function createPaymentMethod()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->createPaymentMethod(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function updatePaymentMethod()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->updatePaymentMethod(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function deletePaymentMethod()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->deletePaymentMethod(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function paymentAccounts()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getGet('store_id') ?? 0));
        if (!$store) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        }

        return $this->response->setJSON((new StoreCatalogQueryService())->paymentAccounts(Database::connect(), (int) $store['id']));
    }

    public function savePaymentAccount()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->savePaymentAccount(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function deactivatePaymentAccount()
    {
        return $this->respondWithServiceResult((new StoreConfigurationService())->deactivatePaymentAccount(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function uploadPaymentAccountQr()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getPost('store_id') ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $file = $this->request->getFile('qr_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Choose a QR image first.']);
        try {
            $url = (new AssetStorageService())->storeImage($file, 'payment-account-qr');
            return $this->response->setJSON(['status' => 'success', 'image_url' => $url]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function uploadPaymentMethodImage()
    {
        $store = $this->resolveAccessibleStore((int) ($this->request->getPost('store_id') ?? 0));
        if (!$store) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
        $file = $this->request->getFile('method_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Choose a payment method image first.']);
        try {
            return $this->response->setJSON(['status' => 'success', 'image_url' => (new AssetStorageService())->storeImage($file, 'payment-method-images')]);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }


    public function debtCustomers()
    {
        $query = new StoreCatalogQueryService();

        return $this->response->setJSON($query->debtCustomers(
            Database::connect(),
            trim((string) $this->request->getGet('q')),
            (int) ($this->request->getGet('page') ?? 1),
            (int) ($this->request->getGet('page_size') ?? 20),
            (string) ($this->request->getGet('sort_by') ?? 'name'),
            (string) ($this->request->getGet('sort_dir') ?? 'asc')
        ));
    }

    public function transactions()
    {
        $filters = $this->request->getGet();
        $result = (new StoreTransactionQueryService())->list(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            is_array($filters) ? $filters : []
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function transactionDetails(int $transactionId)
    {
        $result = (new StoreTransactionQueryService())->details(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            $transactionId
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function inventoryMovements()
    {
        $filters = $this->request->getGet();
        $result = (new StoreInventoryQueryService())->movements(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            is_array($filters) ? $filters : []
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function restock()
    {
        return $this->respondWithServiceResult((new StoreInventoryOperationService())->restock(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function adjustStock()
    {
        return $this->respondWithServiceResult((new StoreInventoryOperationService())->adjustStock(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role')
        ));
    }

    public function addProduct()
    {
        return $this->respondWithServiceResult((new StoreInventoryOperationService())->addProduct(
            $this->requestPayload(),
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            $this->request->getFile('image_file')
        ));
    }

    public function updateProduct()
    {
        $request = $this->request->getPost();
        if ($request === []) {
            $request = $this->request->getJSON(true) ?? [];
        }

        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        $storeId = (int) ($request['store_id'] ?? 0);
        $productId = (int) ($request['product_id'] ?? 0);
        $sku = trim((string) ($request['sku'] ?? ''));
        $name = trim((string) ($request['name'] ?? ''));
        $variantLabel = trim((string) ($request['variant_label'] ?? ''));
        $category = trim((string) ($request['category'] ?? ''));
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $barcode = trim((string) ($request['barcode'] ?? ''));
        $sellPrice = (float) ($request['sell_price'] ?? 0);
        $lowStockThreshold = max(0, (int) ($request['low_stock_threshold'] ?? ($request['reorder_level'] ?? 10)));
        $locationBin = trim((string) ($request['location_bin'] ?? ($request['location'] ?? '')));
        $inputImageUrl = trim((string) ($request['image_url'] ?? ''));

        try {
            $behavior = ProductBehaviorService::normalize($request);
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }
        if ($behavior['stock_policy'] === 'untracked') {
            $lowStockThreshold = 0;
        }

        if ($storeId <= 0 || $productId <= 0 || $sku === '' || $name === '' || $sellPrice < 0) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid product update payload.',
            ]);
        }

        $storeModel = new StoreModel();
        if (!$storeModel->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'You cannot update products in this store.',
            ]);
        }

        $productModel = new ProductModel();
        $categoryModel = new StoreCategoryModel();
        $product = $productModel->find($productId);
        if (!$product || (int) $product['store_id'] !== $storeId) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Product not found in selected store.',
            ]);
        }

        $db = Database::connect();
        $sameSku = $db->table('products')
            ->where('store_id', $storeId)
            ->where('sku', $sku)
            ->where('id !=', $productId)
            ->get()
            ->getRowArray();
        if ($sameSku) {
            return $this->response->setStatusCode(409)->setJSON([
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
                return $this->response->setStatusCode(409)->setJSON([
                    'status' => 'error',
                    'message' => 'Barcode already exists in this store.',
                ]);
            }
        }

        try {
            $resolvedImageUrl = $this->resolveProductImageUrl($inputImageUrl, $product['image_url'] ?? null);
        } catch (\RuntimeException $e) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }

        $category = $category !== '' ? $category : 'General';
        $categoryModel->ensureCategory($storeId, $category);

        $db->transStart();

        $productModel->update($productId, [
            'sku' => $sku,
            'name' => $name,
            'variant_label' => $variantLabel !== '' ? $variantLabel : null,
            'category' => $category,
            'supplier' => $supplier !== '' ? $supplier : null,
            'image_url' => $resolvedImageUrl !== '' ? $resolvedImageUrl : null,
            'barcode' => $barcode !== '' ? $barcode : null,
            'price' => $sellPrice,
            'low_stock_threshold' => $lowStockThreshold,
            'location_bin' => $locationBin !== '' ? $locationBin : null,
            'item_type' => $behavior['item_type'],
            'stock_policy' => $behavior['stock_policy'],
            'unit_code' => $behavior['unit_code'],
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $auditLogModel = new AuditLogModel();
        $auditLogModel->insert([
            'actor_id' => $actorId,
            'action' => 'UPDATE_PRODUCT',
            'entity' => 'products',
            'entity_id' => $productId,
            'payload_json' => json_encode([
                'store_id' => $storeId,
                'before' => [
                    'sku' => $product['sku'],
                    'name' => $product['name'],
                    'variant_label' => $product['variant_label'] ?? null,
                    'category' => $product['category'],
                    'supplier' => $product['supplier'] ?? null,
                    'image_url' => $product['image_url'],
                    'barcode' => $product['barcode'] ?? null,
                    'price' => (float) $product['price'],
                    'low_stock_threshold' => (int) ($product['low_stock_threshold'] ?? 10),
                    'location_bin' => $product['location_bin'] ?? null,
                    'item_type' => $product['item_type'] ?? 'stock_item',
                    'stock_policy' => $product['stock_policy'] ?? 'tracked',
                    'unit_code' => $product['unit_code'] ?? 'piece',
                ],
                'after' => [
                    'sku' => $sku,
                    'name' => $name,
                    'variant_label' => $variantLabel !== '' ? $variantLabel : null,
                    'category' => $category,
                    'supplier' => $supplier !== '' ? $supplier : null,
                    'image_url' => $resolvedImageUrl,
                    'barcode' => $barcode !== '' ? $barcode : null,
                    'price' => $sellPrice,
                    'low_stock_threshold' => $lowStockThreshold,
                    'location_bin' => $locationBin !== '' ? $locationBin : null,
                    'item_type' => $behavior['item_type'],
                    'stock_policy' => $behavior['stock_policy'],
                    'unit_code' => $behavior['unit_code'],
                ],
            ]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $db->transComplete();
        if (!$db->transStatus()) {
            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Failed to update product.',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'product' => $productModel->find($productId),
        ]);
    }

    public function addProductFamily()
    {
        $request = $this->request->getPost();
        $variants = json_decode((string) ($request['variants'] ?? '[]'), true);
        $storeId = (int) ($request['store_id'] ?? 0);
        $name = trim((string) ($request['name'] ?? ''));
        $category = trim((string) ($request['category'] ?? 'General')) ?: 'General';
        $supplier = trim((string) ($request['supplier'] ?? ''));
        $location = trim((string) ($request['location_bin'] ?? ''));
        $reason = trim((string) ($request['reason'] ?? 'Initial stock')) ?: 'Initial stock';
        $actorId = (int) session()->get('user_id');
        $role = (string) session()->get('role');

        try {
            $behavior = ProductBehaviorService::normalize($request);
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if ($storeId <= 0 || $name === '' || !is_array($variants) || $variants === []) {
            return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Product name and at least one variant are required.']);
        }
        if (!(new StoreModel())->canUserAccessStore($actorId, $role, $storeId)) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot add products to this store.']);
        }

        $skus = []; $barcodes = [];
        foreach ($variants as $index => $variant) {
            $sku = trim((string) ($variant['sku'] ?? '')); $label = trim((string) ($variant['label'] ?? ''));
            $barcode = trim((string) ($variant['barcode'] ?? ''));
            if ($sku === '' || $label === '' || (float) ($variant['price'] ?? -1) < 0 || (int) ($variant['stock'] ?? -1) < 0 || (float) ($variant['cost'] ?? -1) < 0) {
                return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => 'Invalid variant at row ' . ($index + 1) . '.']);
            }
            $skuKey = strtolower($sku); $barcodeKey = strtolower($barcode);
            if (isset($skus[$skuKey]) || ($barcode !== '' && isset($barcodes[$barcodeKey]))) {
                return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'Variant SKUs and barcodes must be unique.']);
            }
            $skus[$skuKey] = true; if ($barcode !== '') $barcodes[$barcodeKey] = true;
        }

        $db = Database::connect();
        if ($db->table('products')->where('store_id', $storeId)->whereIn('sku', array_column($variants, 'sku'))->countAllResults() > 0) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'One or more SKUs already exist in this store.']);
        }
        $nonEmptyBarcodes = array_values(array_filter(array_column($variants, 'barcode')));
        if ($nonEmptyBarcodes !== [] && $db->table('products')->where('store_id', $storeId)->whereIn('barcode', $nonEmptyBarcodes)->countAllResults() > 0) {
            return $this->response->setStatusCode(409)->setJSON(['status' => 'error', 'message' => 'One or more barcodes already exist in this store.']);
        }

        try { $sharedImage = $this->resolveProductImageUrl(trim((string) ($request['image_url'] ?? '')), null); }
        catch (\RuntimeException $e) { return $this->response->setStatusCode(400)->setJSON(['status' => 'error', 'message' => $e->getMessage()]); }

        $db->transBegin();
        try {
            $now = date('Y-m-d H:i:s');
            $db->table('product_families')->insert(['store_id'=>$storeId,'name'=>$name,'category'=>$category,'supplier'=>$supplier ?: null,'image_url'=>$sharedImage,'option_name'=>'Size / Variant','created_at'=>$now,'updated_at'=>$now]);
            $familyId = (int) $db->insertID(); $productIds = [];
            foreach ($variants as $variantIndex => $variant) {
                if ($behavior['stock_policy'] === 'untracked') {
                    $variant['stock'] = 0;
                    $variant['low'] = 0;
                }
                $variantImage = $sharedImage;
                if ($variantIndex > 0) {
                    $override = $this->request->getFile('variant_image_' . $variantIndex);
                    if ($override && $override->getError() !== UPLOAD_ERR_NO_FILE) $variantImage = (new AssetStorageService())->storeImage($override, 'product-images', null);
                }
                $db->table('products')->insert(['store_id'=>$storeId,'family_id'=>$familyId,'sku'=>trim((string)$variant['sku']),'name'=>$name,'variant_label'=>trim((string)$variant['label']),'category'=>$category,'supplier'=>$supplier ?: null,'image_url'=>$variantImage,'barcode'=>trim((string)($variant['barcode']??'')) ?: null,'price'=>(float)$variant['price'],'stock_qty'=>(int)$variant['stock'],'low_stock_threshold'=>max(0,(int)($variant['low']??0)),'location_bin'=>$location ?: null,'item_type'=>$behavior['item_type'],'stock_policy'=>$behavior['stock_policy'],'unit_code'=>$behavior['unit_code'],'is_active'=>true,'updated_at'=>$now]);
                $productId = (int) $db->insertID(); $productIds[] = $productId;
                if ((int)$variant['stock'] > 0) $db->table('inventory_movements')->insert(['product_id'=>$productId,'store_id'=>$storeId,'type'=>'restock','qty'=>(int)$variant['stock'],'unit_cost'=>(float)$variant['cost'],'total_cost'=>(float)$variant['cost']*(int)$variant['stock'],'expected_profit'=>((float)$variant['price']-(float)$variant['cost'])*(int)$variant['stock'],'reason'=>$reason,'created_at'=>$now]);
            }
            (new AuditLogModel())->insert(['actor_id'=>$actorId,'action'=>'CREATE_PRODUCT_FAMILY','entity'=>'product_families','entity_id'=>$familyId,'payload_json'=>json_encode(['store_id'=>$storeId,'name'=>$name,'variant_count'=>count($variants),'product_ids'=>$productIds,'item_type'=>$behavior['item_type'],'stock_policy'=>$behavior['stock_policy'],'unit_code'=>$behavior['unit_code']]),'created_at'=>$now]);
            if (!$db->transStatus()) throw new \RuntimeException('Failed to create product family.');
            $db->transCommit();
            return $this->response->setJSON(['status'=>'success','family_id'=>$familyId,'product_ids'=>$productIds,'variant_count'=>count($variants)]);
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->response->setStatusCode(500)->setJSON(['status'=>'error','message'=>$e->getMessage() ?: 'Failed to create product family.']);
        }
    }

    private function resolveAccessibleStore(int $requestedStoreId = 0): ?array
    {
        return (new StoreAccessService())->resolve(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            $requestedStoreId
        );
    }

    /** @return array<string, mixed> */
    private function requestPayload(): array
    {
        $payload = $this->request->getJSON(true) ?? $this->request->getPost();

        return is_array($payload) ? $payload : [];
    }

    /** @param array{code:int,payload:array<string, mixed>} $result */
    private function respondWithServiceResult(array $result)
    {
        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    private function resolveProductImageUrl(string $inputUrl, ?string $currentUrl): ?string
    {
        $imageFile = $this->request->getFile('image_file');
        $hasFile = $imageFile && $imageFile->getError() !== UPLOAD_ERR_NO_FILE;

        if ($hasFile) {
            if (!$imageFile->isValid()) {
                throw new \RuntimeException('Invalid uploaded image file.');
            }

            return (new AssetStorageService())->storeImage($imageFile, 'product-images', $currentUrl);
        }

        if ($inputUrl !== '') {
            return $inputUrl;
        }

        return $currentUrl;
    }

    public function staffTransactions()
    {
        $filters = $this->request->getGet();
        $result = (new StoreTransactionQueryService())->staff(
            (int) session()->get('user_id'),
            (string) session()->get('role'),
            is_array($filters) ? $filters : []
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

}
