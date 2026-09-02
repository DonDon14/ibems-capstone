<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\UserPurchaseCardService;
use CodeIgniter\Controller;

class PurchaseCardController extends Controller
{
    public function page()
    {
        return view('user/card');
    }

    public function status()
    {
        $userId = (int) session()->get('user_id');
        if ($userId <= 0) {
            return $this->response->setStatusCode(401)->setJSON(['status' => 'error', 'message' => 'Not authenticated.']);
        }
        $user = (new UserModel())->find($userId);
        if (!$user) {
            return $this->response->setStatusCode(404)->setJSON(['status' => 'error', 'message' => 'User account not found.']);
        }
        $service = new UserPurchaseCardService();
        if (!$service->isAvailable()) {
            return $this->response->setStatusCode(503)->setJSON(['status' => 'error', 'message' => 'Purchase card controls are unavailable until the latest database migration is applied.']);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'card' => $service->status($userId),
            'employee' => [
                'name' => (string) ($user['name'] ?? ''),
                'employee_id' => (string) ($user['employee_id'] ?? ''),
                'profile_image_url' => ibems_profile_image_url($user['profile_image_url'] ?? null),
            ],
        ]);
    }

    public function unlock()
    {
        return $this->result((new UserPurchaseCardService())->unlock((int) session()->get('user_id')));
    }

    public function lock()
    {
        return $this->result((new UserPurchaseCardService())->lock((int) session()->get('user_id')));
    }

    private function result(array $result)
    {
        $code = (int) ($result['code'] ?? (($result['status'] ?? 'error') === 'success' ? 200 : 400));
        unset($result['code']);
        return $this->response->setStatusCode($code)->setJSON($result);
    }
}
