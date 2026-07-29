<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

class PageController extends BaseController
{
    public function index()
    {
        //
    }

    public function login()
    {
        if (session()->get('logged_in')) {
            $role = ibems_current_role();
            if ($role !== null) {
                return redirect()->to(ibems_role_landing_path($role));
            }

            if (count(ibems_available_roles()) > 1) {
                return redirect()->to('/auth/select-role');
            }
        }

        return view('auth/login');
    }

    public function dashboard()
    {
        $role = ibems_current_role();

        return redirect()->to(ibems_role_landing_path($role));
    }

    public function userDashboard()
    {
        return view('user/dashboard');
    }

    public function userHistory()
    {
        return view('user/history');
    }
}
