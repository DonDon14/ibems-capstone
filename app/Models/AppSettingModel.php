<?php

namespace App\Models;

use CodeIgniter\Model;

class AppSettingModel extends Model
{
    protected $table = 'app_settings';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['setting_key', 'setting_value', 'updated_by', 'updated_at'];
}
