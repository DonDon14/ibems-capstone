<?php
namespace App\Controllers;

use App\Services\StoreAccessService;
use App\Services\StoreOfferingService;
use App\Services\TransactionService;
use Config\Database;

class StoreOfferingController extends BaseController
{
    public function index()
    {
        $id = (int) $this->request->getGet('store_id');
        if ((string) session()->get('role') === 'STORE_SUPERVISOR' && ($id <= 0 || !(new StoreAccessService())->resolve((int) session()->get('user_id'), 'STORE_SUPERVISOR', $id))) return redirect()->to('/store-admin/stores');
        return view('store/offerings');
    }

    public function data() { return $this->handle(null); }

    public function mutate(string $action) { return $this->handle($action); }

    private function handle(?string $action)
    {
        try {
            $input = $action === null ? $this->request->getGet() : ($action === 'food-image' ? $this->request->getPost() : $this->request->getJSON(true));
            if (!is_array($input)) throw new \InvalidArgumentException('Invalid request.');
            $storeId = (int) ($input['store_id'] ?? 0);
            $actorId = (int) session()->get('user_id');
            $role = (string) session()->get('role');
            if ($storeId <= 0 || !(new StoreAccessService())->resolve($actorId, $role, $storeId)) return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'You cannot access this store.']);
            if ($action === 'food-image') {
                if (!in_array('food_service',(new \App\Models\StoreCapabilityModel())->getForStore($storeId),true)) throw new \InvalidArgumentException('Food service is not enabled for this store.');
                $file=$this->request->getFile('image');
                if (!$file || !$file->isValid()) throw new \InvalidArgumentException('Select a valid image.');
                $url=(new \App\Services\AssetStorageService())->storeImage($file,'product-images');
                return $this->response->setJSON(['status'=>'success','image_url'=>$url]);
            }
            if ($action === 'pay') {
                if ($role !== 'STORE_SYSTEM') return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'The store cashier must collect rental fees.']);
                $booking = Database::connect()->table('rental_bookings')->where(['store_id' => $storeId, 'id' => (int) ($input['booking_id'] ?? 0)])->get()->getRowArray();
                if (!$booking) throw new \InvalidArgumentException('Booking not found.');
                if ((int) $booking['transaction_id']) return $this->response->setJSON(['status' => 'success', 'transaction_id' => (int) $booking['transaction_id']]);
                $result = (new TransactionService())->createTransaction(['store_id' => $storeId, 'rental_booking_id' => (int) $booking['id'], 'payment_method' => 'cash',
                    'cash_received' => StoreOfferingService::cents($input['cash_received'] ?? '') / 100, 'customer_type' => 'walk_in',
                    'items' => [['product_id' => (int) $booking['product_id'], 'qty' => 1]]], $actorId, $role);
                return $this->response->setStatusCode($result['code'] ?? 200)->setJSON($result);
            }
            if (in_array($action, ['issue', 'refund'], true) && $role !== 'STORE_SYSTEM') return $this->response->setStatusCode(403)->setJSON(['status' => 'error', 'message' => 'The cashier must handle cash deposits and refunds.']);
            $service = new StoreOfferingService();
            return $this->response->setJSON($action === null ? $service->data($storeId) : $service->mutate($action, $input, $storeId, $actorId, $role));
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'Store offerings: {message}', ['message' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Unable to complete the operation. Refresh to check its status before retrying.']);
        }
    }
}
