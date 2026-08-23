<?php

namespace App\Controllers;

use App\Services\NotificationService;

class NotificationController extends BaseController
{
    public function index()
    {
        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['message' => 'Authentication required.']);
        if (!$this->roleSupportsNotifications()) return $this->response->setStatusCode(403)->setJSON(['message' => 'Notifications are available in the Admin and User portals.']);
        return $this->response->setJSON((new NotificationService())->feed($userId, (int) ($this->request->getGet('limit') ?? 12)));
    }

    public function markRead(int $id)
    {
        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['message' => 'Authentication required.']);
        if (!$this->roleSupportsNotifications()) return $this->response->setStatusCode(403)->setJSON(['message' => 'Notifications are available in the Admin and User portals.']);
        $updated = (new NotificationService())->markRead($id, $userId);
        return $this->response->setStatusCode($updated ? 200 : 404)->setJSON(['success' => $updated]);
    }

    public function markAllRead()
    {
        $userId = (int) session('user_id');
        if ($userId <= 0) return $this->response->setStatusCode(401)->setJSON(['message' => 'Authentication required.']);
        if (!$this->roleSupportsNotifications()) return $this->response->setStatusCode(403)->setJSON(['message' => 'Notifications are available in the Admin and User portals.']);
        return $this->response->setJSON(['success' => true, 'updated' => (new NotificationService())->markAllRead($userId)]);
    }

    private function roleSupportsNotifications(): bool
    {
        return in_array(strtoupper((string) session('role')), ['ADMIN', 'USER'], true);
    }
}
