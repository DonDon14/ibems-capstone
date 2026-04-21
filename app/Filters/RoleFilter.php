<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!session()->get('logged_in')) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 'error',
                    'message' => 'Unauthorized. Please login first.'
                ]);
        }

        $userRole = session()->get('role');

        if (!empty($arguments) && !in_array($userRole, $arguments)) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'status' => 'error',
                    'message' => 'Forbidden: You do not have access to this area.'
                ]);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}