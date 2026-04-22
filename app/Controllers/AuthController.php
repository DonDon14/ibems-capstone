<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\Controller;

class AuthController extends Controller
{
    private function targetPathByRole(string $role): string
    {
        $role = strtoupper(trim($role));
        if ($role === 'STORE_SYSTEM') return '/store/pos';
        if ($role === 'ACCOUNTING_OFFICE') return '/accounting/debts';
        if ($role === 'ADMIN') return '/admin/stores';
        if ($role === 'USER') return '/user/dashboard';
        return '/login';
    }

    public function login()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();

        $email    = $request['email'] ?? null;
        $password = $request['password'] ?? null;

        if (!$email || !$password) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Email and password required'
            ]);
        }

        $userModel = new UserModel();

        $user = $userModel->getActiveUserByEmail($email);

        if (!$user) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ]);
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Invalid email or password'
            ]);
        }

        $roles = $userModel->getEffectiveRoles($user);
        $activeRole = count($roles) === 1 ? $roles[0] : null;

        session()->set([
            'user_id'   => $user['id'],
            'name'      => $user['name'],
            'email'     => $user['email'],
            'role'      => $activeRole,
            'available_roles' => $roles,
            'profile_image_url' => $user['profile_image_url'] ?? null,
            'logged_in' => true
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => [
                'id'   => $user['id'],
                'name' => $user['name'],
                'role' => $activeRole,
                'roles' => $roles,
                'requires_role_selection' => count($roles) > 1 && $activeRole === null,
                'redirect_to' => $activeRole ? $this->targetPathByRole($activeRole) : '/auth/select-role',
            ],
        ]);
    }

    public function logout()
    {
        session()->destroy();

        return redirect()->to('/login');
    }

    public function me()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated'
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user' => [
                'user_id' => session()->get('user_id'),
                'name'    => session()->get('name'),
                'role'    => session()->get('role'),
                'roles'   => array_values((array) (session()->get('available_roles') ?? [])),
            ]
        ]);
    }

    public function selectRolePage()
    {
        if (!session()->get('logged_in')) {
            return redirect()->to('/login');
        }

        return view('auth/select_role');
    }

    public function selectRole()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated',
            ]);
        }

        $request = $this->request->getJSON(true) ?? $this->request->getPost();
        $role = strtoupper(trim((string) ($request['role'] ?? '')));
        $available = array_map(static fn($r) => strtoupper(trim((string) $r)), (array) (session()->get('available_roles') ?? []));
        $available = array_values(array_filter(array_unique($available), static fn($r) => $r !== ''));

        if ($role === '' || !in_array($role, $available, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid role selection.',
            ]);
        }

        session()->set('role', $role);

        return $this->response->setJSON([
            'status' => 'success',
            'role' => $role,
            'redirect_to' => $this->targetPathByRole($role),
        ]);
    }
    
    public function testLogin()
    {
        $data = [
            'email' => 'faculty@test.com',
            'password' => '123456'
        ];

        $this->request->setGlobal('post', $data);

        return $this->login();
    }
}
