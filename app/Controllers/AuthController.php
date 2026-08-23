<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Services\StoreAccessService;
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
        $password = is_string($request['password'] ?? null) ? $request['password'] : '';

        if ($email === '' || $password === '') {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Email and password required'
            ]);
        }

        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($password) > 4096) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Enter a valid email and password'
            ]);
        }

        $throttler = service('throttler');
        $identityThrottleKey = 'login-identity-' . hash('sha256', $email);
        $ipThrottleKey = 'login-ip-' . hash('sha256', $this->request->getIPAddress());
        if (!$throttler->check($ipThrottleKey, 30, 300)
            || !$throttler->check($identityThrottleKey, 8, 900)) {
            $retryAfter = max(1, $throttler->getTokenTime());
            return $this->response
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) $retryAfter)
                ->setJSON([
                    'status' => 'error',
                    'message' => 'Too many sign-in attempts. Please wait before trying again.',
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

        // A successful authentication clears the account-specific bucket while
        // retaining the broader IP bucket to slow distributed credential abuse.
        if (method_exists($throttler, 'remove')) {
            $throttler->remove($identityThrottleKey);
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

        ibems_refresh_session_roles(true);

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

        $availableRoles = ibems_refresh_session_roles(true);
        if ($availableRoles === []) {
            session()->destroy();
            return redirect()->to('/login');
        }

        if (count($availableRoles) === 1 && ibems_current_role() !== null) {
            return redirect()->to(ibems_role_landing_path($availableRoles[0]));
        }

        $storeRoles = array_values(array_intersect($availableRoles, ['STORE_SYSTEM', 'STORE_SUPERVISOR']));
        $roleStores = [];
        if ($storeRoles !== []) {
            $storeAccess = new StoreAccessService();
            $userId = (int) session()->get('user_id');
            foreach ($storeRoles as $storeRole) {
                $roleStores[$storeRole] = array_map(static fn (array $store): array => [
                    'id' => (int) ($store['id'] ?? 0),
                    'store_name' => (string) ($store['store_name'] ?? 'Store'),
                ], $storeAccess->accessibleStores($userId, $storeRole));
            }
        }

        return view('auth/select_role', [
            'availableRoles' => $availableRoles,
            'roleStores' => $roleStores,
        ]);
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
        $available = ibems_refresh_session_roles(true);

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
