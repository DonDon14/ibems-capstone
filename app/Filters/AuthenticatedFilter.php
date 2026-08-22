<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthenticatedFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session()->get('logged_in')) {
            return null;
        }

        $accept = strtolower($request->getHeaderLine('Accept'));
        $isDocumentRequest = strtolower($request->getHeaderLine('Sec-Fetch-Dest')) === 'document'
            || str_contains($accept, 'text/html');

        if ($isDocumentRequest) {
            return redirect()->to('/login');
        }

        return service('response')
            ->setStatusCode(401)
            ->setJSON([
                'status' => 'error',
                'message' => 'Unauthorized. Please login first.',
            ]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
