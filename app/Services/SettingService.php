<?php

namespace App\Services;

use App\Models\AppSettingModel;

class SettingService
{
    private AppSettingModel $model;

    public function __construct()
    {
        $this->model = new AppSettingModel();
    }

    private function tableReady(): bool
    {
        try {
            return db_connect()->tableExists('app_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    public function all(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $rows = $this->model->findAll();
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $mapped;
    }

    public function get(string $key, string $default = ''): string
    {
        if (! $this->tableReady()) {
            return $default;
        }

        $row = $this->model->where('setting_key', $key)->first();
        if ($row === null) {
            return $default;
        }

        return (string) ($row['setting_value'] ?? $default);
    }

    public function setMany(array $settings, ?int $updatedBy = null): void
    {
        if (! $this->tableReady()) {
            return;
        }

        foreach ($settings as $key => $value) {
            $existing = $this->model->where('setting_key', $key)->first();
            $payload = [
                'setting_key' => $key,
                'setting_value' => (string) $value,
                'updated_by' => $updatedBy,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($existing === null) {
                $this->model->insert($payload);
            } else {
                $this->model->update($existing['id'], $payload);
            }
        }
    }

    public function bootstrapDefaults(?int $updatedBy = null): void
    {
        if (! $this->tableReady()) {
            return;
        }

        $defaults = [
            'app_name' => 'IBEMS',
            'app_tagline' => 'Integrated Business Enterprise Management System',
            'school_name' => 'USTP Claveria Campus',
            'currency' => 'PHP',
            'receipt_footer' => 'Thank you for your purchase.',
            'support_email' => 'support@ibems.local',
            'low_stock_threshold' => '10',
            'ui_compact_mode' => '0',
        ];

        foreach ($defaults as $key => $value) {
            $existing = $this->model->where('setting_key', $key)->first();
            if ($existing === null) {
                $this->model->insert([
                    'setting_key' => $key,
                    'setting_value' => $value,
                    'updated_by' => $updatedBy,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
