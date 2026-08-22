<?php

namespace App\Controllers;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use App\Services\AssetStorageService;
use CodeIgniter\Controller;

class ProfileController extends Controller
{
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
}
