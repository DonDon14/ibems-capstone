<?php

namespace App\Models;

use CodeIgniter\Model;

class SalaryImportBatchModel extends Model
{
    protected $table            = 'salary_import_batches';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';

    protected $allowedFields = [
        'filename',
        'imported_by',
        'imported_at',
        'total_rows',
        'valid_rows',
        'invalid_rows',
    ];

    protected $useTimestamps = false;
}
