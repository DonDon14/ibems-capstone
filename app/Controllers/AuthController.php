<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\Controller;

class AuthController extends Controller
{
    public function login()
    {
        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));
        $request = str_contains($contentType, 'application/json')
            ? ($this->request->getJSON(true) ?? [])
            : $this->request->getPost();

        $email    = strtolower(trim((string) ($request['email'] ?? '')));
        $password = $request['password'] ?? null;

        if (!$email || !$password) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Email and password required'
            ]);
        }

        $userModel = new UserModel();

        $user = $userModel->getActiveUserWithRolesByEmail($email);

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

        session()->regenerate(true);

        $roles = $userModel->getEffectiveRoles($user);
        $roles = array_values(array_filter(array_unique(array_map('ibems_normalize_role', $roles))));
        $activeRole = count($roles) === 1 ? $roles[0] : null;

        session()->set([
            'user_id'   => $user['id'],
            'name'      => $user['name'],
            'email'     => $user['email'],
            'role'      => $activeRole,
            'available_roles' => $roles,
            'roles_refreshed_at' => time(),
            'profile_image_url' => $user['profile_image_url'] ?? null,
            'logged_in' => true
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Sign-in successful.',
            'user' => [
                'id'   => $user['id'],
                'name' => $user['name'],
                'role' => $activeRole,
                'roles' => $roles,
                'requires_role_selection' => count($roles) > 1 && $activeRole === null,
                'redirect_to' => $activeRole ? ibems_role_landing_path($activeRole) : site_url('auth/select-role'),
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

        ibems_refresh_session_roles();

        return $this->response->setJSON([
            'status' => 'success',
            'user' => [
                'user_id' => session()->get('user_id'),
                'name'    => session()->get('name'),
                'profile_image_url' => session()->get('profile_image_url'),
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

        ibems_refresh_session_roles();
        $availableRoles = ibems_available_roles();
        if ($availableRoles === []) {
            session()->destroy();
            return redirect()->to('/login');
        }

        if (count($availableRoles) === 1 && ibems_current_role() !== null) {
            return redirect()->to(ibems_role_landing_path($availableRoles[0]));
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

        $contentType = strtolower($this->request->getHeaderLine('Content-Type'));
        $jsonRequest = str_contains($contentType, 'application/json')
            ? $this->request->getJSON(true)
            : null;
        $request = $jsonRequest ?? $this->request->getPost();
        $role = strtoupper(trim((string) ($request['role'] ?? '')));
        $available = ibems_available_roles();

        if ($role === '' || !in_array($role, $available, true)) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Invalid role selection.',
            ]);
        }

        session()->set('role', $role);

        if ($jsonRequest === null) {
            return redirect()->to(ibems_role_landing_path($role));
        }

        return $this->response->setJSON([
            'status' => 'success',
            'role' => $role,
            'redirect_to' => ibems_role_landing_path($role),
        ]);
    }
}
