<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = session()->get('user');
        if (! is_array($user)) {
            return redirect()->to('/login')->with('error', 'Please sign in.');
        }

        $requiredRoles = is_array($arguments) ? $arguments : [];
        if ($requiredRoles !== [] && ! in_array($user['role'], $requiredRoles, true)) {
            return redirect()->to('/dashboard')->with('error', 'You are not authorized to access this page.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
