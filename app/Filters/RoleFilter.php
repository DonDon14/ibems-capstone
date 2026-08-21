<?php

namespace App\Filters;

use Config\Authorization;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Filters\FilterInterface;

class RoleFilter implements FilterInterface
{
    private function isDocumentRequest(RequestInterface $request): bool
    {
        $secFetchDest = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        if ($secFetchDest === 'document') {
            return true;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        return str_contains($accept, 'text/html');
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (!session()->get('logged_in')) {
            if ($this->isDocumentRequest($request)) {
                return redirect()->to('/login');
            }

            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 'error',
                    'message' => 'Unauthorized. Please login first.'
                ]);
        }

        $method = strtoupper($request->getMethod());
        $availableRoles = ibems_refresh_session_roles(! in_array($method, ['GET', 'HEAD'], true));
        $userRole = ibems_current_role();

        if ($userRole === null) {
            if (count($availableRoles) > 1) {
                if ($this->isDocumentRequest($request)) {
                    return redirect()->to('/auth/select-role');
                }

                return service('response')
                    ->setStatusCode(409)
                    ->setJSON([
                        'status' => 'error',
                        'message' => 'Role selection required.',
                        'redirect_to' => '/auth/select-role',
                    ]);
            }

            if (count($availableRoles) === 1) {
                $userRole = $availableRoles[0];
                session()->set('role', $userRole);
            }
        }

        $allowedRoles = [];
        $authorization = config(Authorization::class);
        $hasUnknownPolicy = false;
        foreach ((array) $arguments as $argument) {
            $argument = trim((string) $argument);
            $policyRoles = $authorization->rolesFor($argument);
            if ($policyRoles === null) {
                $hasUnknownPolicy = true;
                continue;
            }

            $allowedRoles = array_merge($allowedRoles, $policyRoles);
        }
        $allowedRoles = array_values(array_unique(array_filter($allowedRoles)));

        if ($hasUnknownPolicy || $allowedRoles === [] || !in_array($userRole, $allowedRoles, true)) {
            if ($this->isDocumentRequest($request)) {
                return redirect()->to(ibems_role_landing_path($userRole));
            }

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
