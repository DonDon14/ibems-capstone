<?php

namespace App\Controllers;

use App\Services\FinanceSalesReportService;
use App\Services\FinanceDeductionReportService;
use App\Services\FinanceWasteReportService;
use App\Services\FinanceReportCsvService;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class FinanceReportController extends BaseController
{
    public function index()
    {
        [$period, $anchorDate, $from, $to, $storeId] = $this->selection();
        $error = null;
        try {
            [$report, $deductions, $waste] = $this->reports($from, $to, $storeId);
        } catch (InvalidArgumentException $exception) {
            $error = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from > $to
                ? 'The start date must be on or before the end date. Update Custom from or Custom to, then select View report.'
                : $exception->getMessage();
            $this->response->setStatusCode(400);
            $report = ['stores' => [], 'charges' => [], 'products' => [], 'customer_types' => [], 'employee_purchases' => [],
                'totals' => ['transactions' => 0, 'sales_cents' => 0, 'cash_cents' => 0, 'debt_cents' => 0, 'other_cents' => 0]];
            $deductions = ['rows' => [], 'count' => 0, 'total_cents' => 0];
            $waste = ['rows' => [], 'total_quantity' => 0, 'unit_totals' => []];
        }
        $role = ibems_current_role();
        $cashier = in_array($role, ['CASHIER_HEAD', 'COLLECTING_OFFICER'], true);
        return view('office/finance_reports', [
            'portalTitle' => $role === 'BE_DIRECTOR' ? 'Business Enterprise' : ($cashier ? 'Cashier Office' : 'Accounting Portal'),
            'portalSubtitle' => 'Finance Reports',
            'homePath' => $role === 'BE_DIRECTOR' ? 'be/dashboard' : ($cashier ? 'cashier/dashboard' : 'accounting/dashboard'),
            'period' => $period,
            'anchorDate' => $anchorDate,
            'fromDate' => $from,
            'toDate' => $to,
            'storeId' => $storeId,
            'storeOptions' => Database::connect()->table('stores')->select('id, store_name')->orderBy('store_name')->get()->getResultArray(),
            'report' => $report,
            'deductions' => $deductions,
            'waste' => $waste,
            'error' => $error,
        ]);
    }

    public function export()
    {
        [, , $from, $to, $storeId] = $this->selection();
        try {
            [$report, $deductions, $waste] = $this->reports($from, $to, $storeId);
        } catch (InvalidArgumentException $exception) {
            return $this->response->removeHeader('Content-Disposition')
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->setStatusCode(400)->setBody($exception->getMessage());
        }
        $stores = Database::connect()->table('stores')->select('id, store_name')->orderBy('store_name')->get()->getResultArray();
        $csv = (new FinanceReportCsvService())->render($report, $deductions, $waste, $stores);
        return $this->response->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="ibems-finance-' . $from . '-to-' . $to . '.csv"')
            ->setBody($csv);
    }

    /** @return array{string,string,string,string,int} */
    private function selection(): array
    {
        $timezone = new DateTimeZone('Asia/Manila');
        $today = ibems_now()->setTimezone($timezone);
        $period = (string) ($this->request->getGet('period') ?? 'month');
        if (!in_array($period, ['day', 'week', 'month', 'custom'], true)) {
            $period = 'month';
        }
        $anchorDate = (string) ($this->request->getGet('anchor_date') ?: $today->format('Y-m-d'));
        $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate, $timezone);
        if (!$anchor || $anchor->format('Y-m-d') !== $anchorDate) {
            $anchor = null;
        }
        [$from, $to] = match ($period) {
            'day' => [$anchorDate, $anchorDate],
            'week' => $anchor ? [$anchor->modify('monday this week')->format('Y-m-d'), $anchor->modify('sunday this week')->format('Y-m-d')] : [$anchorDate, $anchorDate],
            'month' => $anchor ? [$anchor->modify('first day of this month')->format('Y-m-d'), $anchor->modify('last day of this month')->format('Y-m-d')] : [$anchorDate, $anchorDate],
            default => [(string) ($this->request->getGet('from_date') ?? ''), (string) ($this->request->getGet('to_date') ?? '')],
        };
        $storeId = max(0, (int) ($this->request->getGet('store_id') ?? 0));
        return [$period, $anchorDate, $from, $to, $storeId];
    }

    /** @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>} */
    private function reports(string $from, string $to, int $storeId): array
    {
        return [
            (new FinanceSalesReportService())->build($from, $to, $storeId),
            (new FinanceDeductionReportService())->build($from, $to),
            (new FinanceWasteReportService())->build($from, $to, $storeId),
        ];
    }
}
