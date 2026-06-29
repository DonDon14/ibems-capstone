<?php

namespace App\Filters;

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

        $availableRoles = ibems_refresh_session_roles();
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

        $allowedRoles = array_map(static fn ($role) => ibems_normalize_role((string) $role), (array) $arguments);

        if ($allowedRoles !== [] && !in_array($userRole, $allowedRoles, true)) {
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
