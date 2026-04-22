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

        $userRole = session()->get('role');
        $availableRoles = (array) (session()->get('available_roles') ?? []);

        if ($userRole === null || trim((string) $userRole) === '') {
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

        if (!empty($arguments) && !in_array($userRole, $arguments)) {
            if ($this->isDocumentRequest($request)) {
                return redirect()->to('/dashboard');
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
