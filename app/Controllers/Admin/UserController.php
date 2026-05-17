<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\BalanceModel;
use App\Models\UserModel;
use App\Services\AuditService;
use App\Services\CreditService;

class UserController extends BaseController
{
    public function index()
    {
        $users = (new UserModel())->orderBy('id', 'DESC')->findAll();

        return view('admin/users/index', [
            'title' => 'Users',
            'users' => $users,
        ]);
    }

    public function store()
    {
        $rules = [
            'employee_id' => 'required|max_length[50]',
            'name' => 'required|max_length[120]',
            'email' => 'required|valid_email|max_length[150]|is_unique[users.email]',
            'password' => 'required|min_length[8]|max_length[120]',
            'role' => 'required|in_list[USER,ADMIN,ACCOUNTING_OFFICE,STORE_SYSTEM]',
            'user_type' => 'permit_empty|in_list[faculty,staff,student]',
            'base_salary' => 'permit_empty|decimal',
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            return redirect()->to('/admin/users')->withInput()->with('error', 'Invalid user input. Please check fields.');
        }

        $data = [
            'employee_id' => trim((string) $this->request->getPost('employee_id')),
            'name' => trim((string) $this->request->getPost('name')),
            'email' => strtolower(trim((string) $this->request->getPost('email'))),
            'password_hash' => password_hash((string) $this->request->getPost('password'), PASSWORD_DEFAULT),
            'role' => $this->request->getPost('role'),
            'user_type' => $this->request->getPost('user_type') ?: null,
            'qr_token' => $this->request->getPost('qr_token') ?: null,
            'base_salary' => $this->request->getPost('base_salary') ?: null,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $userModel = new UserModel();
        $userId = $userModel->insert($data, true);

        if (in_array((string) $data['user_type'], ['faculty', 'staff'], true)) {
            $creditLimit = (new CreditService())->computeLimit($data['base_salary'] !== null ? (float) $data['base_salary'] : null);
            (new BalanceModel())->insert([
                'user_id' => $userId,
                'credit_limit' => $creditLimit,
                'current_debt' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        (new AuditService())->log(current_user_id(), 'CREATE', 'users', (string) $userId, $data);

        return redirect()->to('/admin/users')->with('success', 'User created.');
    }
}
