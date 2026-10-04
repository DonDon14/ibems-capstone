<?php

namespace App\Controllers;

use App\Services\CashierOfficeSalesService;
use InvalidArgumentException;

class OfficePortalController extends BaseController
{
    public function cashier()
    {
        $head = ibems_current_role() === 'CASHIER_HEAD';
        $today = ibems_business_date();
        $selectedDate = trim((string) ($this->request->getGet('date') ?? $today));
        $error = null;
        try {
            $sales = (new CashierOfficeSalesService())->daily($selectedDate);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
            $selectedDate = $today;
            $sales = (new CashierOfficeSalesService())->daily($today);
            $this->response->setStatusCode(400);
        }

        return view('office/cashier', [
            'portalTitle' => 'Cashier Office',
            'portalSubtitle' => $head ? 'Cashier Head' : 'Collecting Officer',
            'homePath' => 'cashier/dashboard',
            'head' => $head,
            'sales' => $sales,
            'selectedDate' => $selectedDate,
            'error' => $error,
        ]);
    }

    public function payroll()
    {
        return view('office/dashboard', [
            'portalTitle' => 'Accounting Payroll',
            'portalSubtitle' => 'Payroll Clerk',
            'description' => 'Salary-deduction routes are assigned to this role, separate from debt imports, financial profiles, investigations, and settlements.',
            'boundary' => 'Use the Deductions page for the shared period and batch workflow. Preparing or submitting a batch does not reduce debt; confirm actual results with an official reference before posting.',
            'homePath' => 'accounting/payroll',
        ]);
    }

    public function businessEnterprise()
    {
        return view('office/dashboard', [
            'portalTitle' => 'Business Enterprise',
            'portalSubtitle' => 'Director',
            'description' => 'The director is the first reviewer of daily business reports before Cashier receives them and Accounting gets a furnished copy.',
            'boundary' => 'An assigned Store Supervisor submits each closed-day snapshot to Daily Reports for BE review. Approval furnishes Accounting with a copy and allows the Cashier Office to record receipt.',
            'homePath' => 'be/dashboard',
        ]);
    }
}
