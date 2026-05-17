<?php

namespace Config;

use App\Filters\AuthFilter;
use App\Filters\RoleFilter;
use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    public array $aliases = [
        'csrf' => CSRF::class,
        'toolbar' => DebugToolbar::class,
        'honeypot' => Honeypot::class,
        'invalidchars' => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'auth' => AuthFilter::class,
        'role' => RoleFilter::class,
    ];

    public array $required = [
        'before' => [],
        'after' => [],
    ];

    public array $globals = [
        'before' => [
            'invalidchars',
        ],
        'after' => [
            'secureheaders',
            'toolbar',
        ],
    ];

    public array $methods = [];

    public array $filters = [];
}
