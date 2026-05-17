<?php

namespace App\Controllers;

use App\Models\BalanceModel;
use App\Models\TransactionModel;
use App\Models\UserModel;
use App\Services\AuditService;

class UserPortalController extends BaseController
{
    public function index()
    {
        $userId = current_user_id();
        $balance = $userId !== null ? (new BalanceModel())->find($userId) : null;
        $transactions = $userId !== null
            ? (new TransactionModel())->where('user_id', $userId)->orderBy('id', 'DESC')->findAll(100)
            : [];

        $profile = $userId !== null ? (new UserModel())->find($userId) : null;

        return view('dashboard/user_portal', [
            'title' => 'My Account',
            'balance' => $balance,
            'transactions' => $transactions,
            'profile' => $profile,
        ]);
    }

    public function updateProfile()
    {
        $userId = current_user_id();
        if ($userId === null) {
            return redirect()->to('/login');
        }

        $name = trim((string) $this->request->getPost('name'));
        $email = trim((string) $this->request->getPost('email'));

        if ($name === '' || $email === '') {
            return redirect()->to('/me')->with('error', 'Name and email are required.');
        }

        $model = new UserModel();
        $existingEmail = $model->where('email', $email)->where('id !=', $userId)->first();
        if ($existingEmail !== null) {
            return redirect()->to('/me')->with('error', 'Email is already used by another account.');
        }

        $model->update($userId, [
            'name' => $name,
            'email' => $email,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $sessionUser = current_user();
        if (is_array($sessionUser)) {
            $sessionUser['name'] = $name;
            $sessionUser['email'] = $email;
            session()->set('user', $sessionUser);
        }

        (new AuditService())->log($userId, 'UPDATE', 'users', (string) $userId, ['name' => $name, 'email' => $email]);

        return redirect()->to('/me')->with('success', 'Profile updated.');
    }

    public function updatePassword()
    {
        $userId = current_user_id();
        if ($userId === null) {
            return redirect()->to('/login');
        }

        $currentPassword = (string) $this->request->getPost('current_password');
        $newPassword = (string) $this->request->getPost('new_password');
        $confirmPassword = (string) $this->request->getPost('confirm_password');

        if (strlen($newPassword) < 8) {
            return redirect()->to('/me')->with('error', 'New password must be at least 8 characters.');
        }

        if ($newPassword !== $confirmPassword) {
            return redirect()->to('/me')->with('error', 'Password confirmation does not match.');
        }

        $model = new UserModel();
        $user = $model->find($userId);
        if ($user === null || ! password_verify($currentPassword, $user['password_hash'])) {
            return redirect()->to('/me')->with('error', 'Current password is incorrect.');
        }

        $model->update($userId, [
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        (new AuditService())->log($userId, 'UPDATE_PASSWORD', 'users', (string) $userId, null);

        return redirect()->to('/me')->with('success', 'Password updated.');
    }
}
