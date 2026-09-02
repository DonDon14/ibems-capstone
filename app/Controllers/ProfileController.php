<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use App\Services\AssetStorageService;
use CodeIgniter\Controller;
use Config\Services;

class ProfileController extends Controller
{
    public function securityStatus()
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->invalidSessionResponse();
        }

        return $this->response->setJSON([
            'status' => 'success',
            'can_manage_debt_pin' => $this->canManageDebtPin(),
            'has_debt_pin' => trim((string) ($user['debt_pin_hash'] ?? '')) !== '',
        ]);
    }

    public function updatePassword()
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->invalidSessionResponse();
        }

        $payload = $this->requestPayload();
        $validation = Services::validation();
        $validation->setRules([
            'current_password' => 'required',
            'new_password' => 'required|min_length[8]|max_length[255]',
            'new_password_confirm' => 'required|matches[new_password]',
        ], [
            'new_password' => ['min_length' => 'New password must be at least 8 characters.'],
            'new_password_confirm' => ['matches' => 'New password confirmation does not match.'],
        ]);

        if (!$validation->run($payload)) {
            $errors = $validation->getErrors();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $errors[array_key_first($errors)] ?? 'Check the password fields and try again.',
            ]);
        }

        $currentPassword = (string) ($payload['current_password'] ?? '');
        $newPassword = (string) ($payload['new_password'] ?? '');
        if (!password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Current password is incorrect.',
            ]);
        }
        if (password_verify($newPassword, (string) ($user['password_hash'] ?? ''))) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Choose a new password that is different from your current password.',
            ]);
        }

        $userId = (int) $user['id'];
        if (!(new UserModel())->update($userId, ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)])) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Unable to update password.']);
        }

        $this->logSecurityChange($userId, 'USER_CHANGE_PASSWORD');
        return $this->response->setJSON(['status' => 'success', 'message' => 'Password updated successfully.']);
    }

    public function updateDebtPin()
    {
        $user = $this->currentUser();
        if (!$user) {
            return $this->invalidSessionResponse();
        }
        if (!$this->canManageDebtPin()) {
            return $this->response->setStatusCode(403)->setJSON([
                'status' => 'error',
                'message' => 'A personal purchase PIN is available only to employee accounts.',
            ]);
        }

        $payload = $this->requestPayload();
        $validation = Services::validation();
        $validation->setRules([
            'pin' => 'required|regex_match[/^[0-9]{4,6}$/]',
            'pin_confirm' => 'required|matches[pin]',
        ], [
            'pin' => ['regex_match' => 'Purchase PIN must be 4 to 6 digits.'],
            'pin_confirm' => ['matches' => 'Purchase PIN confirmation does not match.'],
        ]);
        if (!$validation->run($payload)) {
            $errors = $validation->getErrors();
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $errors[array_key_first($errors)] ?? 'Invalid purchase PIN.',
            ]);
        }

        $hasExistingPin = trim((string) ($user['debt_pin_hash'] ?? '')) !== '';
        if ($hasExistingPin && !password_verify((string) ($payload['current_password'] ?? ''), (string) ($user['password_hash'] ?? ''))) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Enter your current password to change your purchase PIN.',
            ]);
        }

        $userId = (int) $user['id'];
        if (!(new UserModel())->update($userId, ['debt_pin_hash' => password_hash((string) $payload['pin'], PASSWORD_BCRYPT)])) {
            return $this->response->setStatusCode(500)->setJSON(['status' => 'error', 'message' => 'Unable to update purchase PIN.']);
        }

        $this->logSecurityChange($userId, $hasExistingPin ? 'CHANGE_DEBT_PIN' : 'SET_DEBT_PIN');
        return $this->response->setJSON([
            'status' => 'success',
            'message' => $hasExistingPin ? 'Purchase PIN updated.' : 'Purchase PIN set.',
            'has_debt_pin' => true,
        ]);
    }

    public function uploadImage()
    {
        $userId = (int) session()->get('user_id');
        $userModel = new UserModel();
        $user = $userId > 0 ? $userModel->find($userId) : null;
        if (!$user) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => 'error',
                'message' => 'Your session is no longer valid. Sign in again.',
            ]);
        }

        $file = $this->request->getFile('profile_image');
        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => 'Choose a JPG, PNG, WebP, or GIF image first.',
            ]);
        }

        $storage = new AssetStorageService();
        $previousUrl = trim((string) ($user['profile_image_url'] ?? ''));
        try {
            $profileImageUrl = $storage->storeImage($file, 'profile-images');
            if (!$userModel->update($userId, ['profile_image_url' => $profileImageUrl])) {
                $storage->deleteImage($profileImageUrl);
                throw new \RuntimeException('Unable to save the new profile picture.');
            }
            $storage->deleteImage($previousUrl);
        } catch (\Throwable $exception) {
            return $this->response->setStatusCode(400)->setJSON([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }

        session()->set('profile_image_url', $profileImageUrl);
        (new AuditLogModel())->insert([
            'actor_id' => $userId,
            'action' => 'USER_UPDATE_PROFILE_IMAGE',
            'entity' => 'users',
            'entity_id' => $userId,
            'payload_json' => json_encode(['profile_image_updated' => true]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Profile picture updated.',
            'profile_image_url' => $profileImageUrl,
        ]);
    }

    private function currentUser(): ?array
    {
        $userId = (int) session()->get('user_id');
        return $userId > 0 ? (new UserModel())->find($userId) : null;
    }

    private function invalidSessionResponse()
    {
        return $this->response->setStatusCode(401)->setJSON([
            'status' => 'error',
            'message' => 'Your session is no longer valid. Sign in again.',
        ]);
    }

    private function requestPayload(): array
    {
        $payload = $this->request->getJSON(true);
        return is_array($payload) ? $payload : $this->request->getPost();
    }

    private function canManageDebtPin(): bool
    {
        return in_array('USER', ibems_available_roles(), true);
    }

    private function logSecurityChange(int $userId, string $action): void
    {
        (new AuditLogModel())->insert([
            'actor_id' => $userId,
            'action' => $action,
            'entity' => 'users',
            'entity_id' => $userId,
            'payload_json' => json_encode(['self_service' => true]),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
