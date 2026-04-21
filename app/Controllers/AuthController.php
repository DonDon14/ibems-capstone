<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\Controller;

class AuthController extends Controller
{
    public function login()
    {
        $request = $this->request->getJSON(true) ?? $this->request->getPost();

        $email    = $request['email'] ?? null;
        $password = $request['password'] ?? null;

        if (!$email || !$password) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Email and password required'
            ]);
        }

        $userModel = new UserModel();

        $user = $userModel->getActiveUserByEmail($email);

        if (!$user) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'User not found'
            ]);
        }

        if (!password_verify($password, $user['password_hash'])) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Invalid password'
            ]);
        }

        session()->set([
            'user_id'   => $user['id'],
            'name'      => $user['name'],
            'role'      => $user['role'],
            'logged_in' => true
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Login successful',
            'user' => [
                'id'   => $user['id'],
                'name' => $user['name'],
                'role' => $user['role']
            ]
        ]);
    }

    public function logout()
    {
        session()->destroy();

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Logged out'
        ]);
    }

    public function me()
    {
        if (!session()->get('logged_in')) {
            return $this->response->setJSON([
                'status' => 'error',
                'message' => 'Not authenticated'
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'user' => [
                'user_id' => session()->get('user_id'),
                'name'    => session()->get('name'),
                'role'    => session()->get('role')
            ]
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