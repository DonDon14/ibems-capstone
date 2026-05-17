<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\StoreModel;
use App\Services\AuditService;

class StoreController extends BaseController
{
    public function index()
    {
        $stores = (new StoreModel())->orderBy('id', 'DESC')->findAll();

        return view('admin/stores/index', [
            'title' => 'Stores',
            'stores' => $stores,
        ]);
    }

    public function store()
    {
        $rules = [
            'store_name' => 'required|max_length[120]',
            'officer_id' => 'permit_empty|integer',
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            return redirect()->to('/admin/stores')->withInput()->with('error', 'Invalid store input.');
        }

        $data = [
            'store_name' => trim((string) $this->request->getPost('store_name')),
            'officer_id' => $this->request->getPost('officer_id') ?: null,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = (new StoreModel())->insert($data, true);
        (new AuditService())->log(current_user_id(), 'CREATE', 'stores', (string) $id, $data);

        return redirect()->to('/admin/stores')->with('success', 'Store created.');
    }
}
