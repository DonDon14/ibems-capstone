<?php

namespace App\Controllers;

use App\Services\HrStaffService;
use App\Services\SalaryScheduleService;
use Config\Database;

class HrController extends BaseController
{
    public function staff()
    {
        return view('hr/staff', [
            'salarySchedules' => (new SalaryScheduleService())->catalog(Database::connect()),
        ]);
    }

    public function createStaff()
    {
        $request = str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')
            ? $this->request->getJSON(true) : $this->request->getPost();
        $result = (new HrStaffService())->create(is_array($request) ? $request : [], (int) session()->get('user_id'));

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }

    public function importStaffCsv()
    {
        $result = (new HrStaffService())->importCsv(
            $this->request->getFile('csv_file'),
            (int) session()->get('user_id')
        );

        return $this->response->setStatusCode($result['code'])->setJSON($result['payload']);
    }
}
