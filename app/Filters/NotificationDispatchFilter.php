<?php

namespace App\Filters;

use App\Services\NotificationService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class NotificationDispatchFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (is_cli() || ENVIRONMENT === 'testing' || strtoupper($request->getMethod()) === 'GET') return null;
        try {
            (new NotificationService())->dispatchPendingEmails(3);
        } catch (\Throwable $exception) {
            log_message('error', 'Notification email dispatcher could not run: {message}', ['message' => $exception->getMessage()]);
        }
        return null;
    }
}
