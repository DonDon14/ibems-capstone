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
        return view('auth/login');
    }

    public function dashboard()
    {
        return view('dashboard');
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
