<?php

namespace App\Models;

use CodeIgniter\Model;

class SalaryImportRowModel extends Model
{
    protected $table = 'salary_import_rows';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['batch_id', 'employee_id', 'name', 'email', 'monthly_salary', 'status', 'error_msg'];
}
