<?php

namespace App\Controllers;

use App\Models\UserModel;

class AuthController extends BaseController
{
    public function login()
    {
        if (current_user() !== null) {
            return redirect()->to('/dashboard');
        }

        return view('auth/login', ['title' => 'Sign In']);
    }

    public function attempt()
    {
        $rules = [
            'email' => 'required|valid_email|max_length[150]',
            'password' => 'required|min_length[8]|max_length[120]',
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            return redirect()->back()->withInput()->with('error', 'Please provide valid credentials format.');
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->where('is_active', 1)->first();
        if ($user === null) {
            return redirect()->back()->withInput()->with('error', 'Invalid credentials.');
        }

        if (! empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            return redirect()->back()->withInput()->with('error', 'Account is temporarily locked due to failed login attempts.');
        }

        if (! password_verify($password, $user['password_hash'])) {
            $attempts = ((int) ($user['failed_login_attempts'] ?? 0)) + 1;
            $update = [
                'failed_login_attempts' => $attempts,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($attempts >= 5) {
                $update['locked_until'] = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                $update['failed_login_attempts'] = 0;
            }

            $userModel->update($user['id'], $update);
            return redirect()->back()->withInput()->with('error', 'Invalid credentials.');
        }

        $userModel->update($user['id'], [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        session()->set('user', [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'user_type' => $user['user_type'],
        ]);

        return redirect()->to('/dashboard');
    }

    public function logout()
    {
        session()->remove('user');
        session()->destroy();

        return redirect()->to('/login')->with('success', 'Signed out.');
    }
}
