<?php

namespace App\Controllers;

use App\Services\AuditService;
use App\Services\SettingService;

class SettingsController extends BaseController
{
    public function index()
    {
        $service = new SettingService();
        $service->bootstrapDefaults(current_user_id());

        return view('settings/index', [
            'title' => 'Settings',
            'settings' => $service->all(),
        ]);
    }

    public function save()
    {
        $rules = [
            'app_name' => 'required|max_length[120]',
            'app_tagline' => 'permit_empty|max_length[255]',
            'school_name' => 'permit_empty|max_length[255]',
            'currency' => 'required|max_length[5]',
            'receipt_footer' => 'permit_empty|max_length[255]',
            'support_email' => 'permit_empty|valid_email|max_length[150]',
            'low_stock_threshold' => 'required|integer',
        ];

        if (! $this->validateData($this->request->getPost(), $rules)) {
            return redirect()->to('/settings')->withInput()->with('error', 'Invalid settings input.');
        }

        $service = new SettingService();

        $payload = [
            'app_name' => trim((string) $this->request->getPost('app_name')),
            'app_tagline' => trim((string) $this->request->getPost('app_tagline')),
            'school_name' => trim((string) $this->request->getPost('school_name')),
            'currency' => strtoupper(trim((string) $this->request->getPost('currency'))),
            'receipt_footer' => trim((string) $this->request->getPost('receipt_footer')),
            'support_email' => trim((string) $this->request->getPost('support_email')),
            'low_stock_threshold' => trim((string) $this->request->getPost('low_stock_threshold')),
            'ui_compact_mode' => $this->request->getPost('ui_compact_mode') ? '1' : '0',
        ];

        $service->setMany($payload, current_user_id());
        (new AuditService())->log(current_user_id(), 'UPDATE', 'app_settings', null, $payload);

        return redirect()->to('/settings')->with('success', 'Settings updated.');
    }
}
