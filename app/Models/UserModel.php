<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'employee_id', 'name', 'email', 'password_hash', 'role', 'user_type', 'qr_token', 'base_salary', 'is_active', 'failed_login_attempts', 'locked_until', 'created_at', 'updated_at',
    ];
}
