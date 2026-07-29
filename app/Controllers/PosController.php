<?php

namespace App\Controllers;

use App\Services\TransactionService;
use CodeIgniter\RESTful\ResourceController;

class PosController extends ResourceController
{
    public function createTransaction()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Unauthorized. Please login.',
            ]);
        }

        $payload = $this->request->getJSON(true) ?? $this->request->getPost() ?? [];
        $service = new TransactionService();
        $result = $service->createTransaction(
            is_array($payload) ? $payload : [],
            (int) session()->get('user_id'),
            (string) session()->get('role')
        );

        $statusCode = (int) ($result['code'] ?? ($result['status'] === 'success' ? 200 : 400));
        unset($result['code']);

        return $this->response->setStatusCode($statusCode)->setJSON($result);
    }
}
