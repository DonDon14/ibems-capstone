<?php

use CodeIgniter\Test\CIUnitTestCase;

final class DailyReportTimezoneViewTest extends CIUnitTestCase
{
    public function testUnavailableSourcePreviewNamesItsActualRecoveryDestination(): void
    {
        $html = view('store-admin/daily-report-preview', ['backUrl' => '/store-admin/stores',
            'result' => ['status' => 'error', 'message' => 'Source unavailable.']]);
        $this->assertStringContainsString('← Back to assigned stores', $html);
        $this->assertStringNotContainsString('← Back to daily report handoff', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function testSourcePreviewLabelsManilaCloseTimeAndPreservesBusinessDate(): void
    {
        $session = ['closed_at' => '2026-10-31 16:00:00', 'review_status' => 'not_required'];
        foreach (['expected_cash', 'counted_cash', 'variance_cash', 'expected_ecash', 'counted_ecash', 'variance_ecash'] as $field) {
            $session[$field] = 0;
        }
        $html = view('store-admin/daily-report-preview', ['backUrl' => '/store-admin/daily-reports', 'result' => [
            'status' => 'success', 'business_date' => '2026-10-31', 'session' => $session,
            'evidence' => ['sha256' => 'fictional'], 'totals' => ['name' => 'Timezone fixture', 'transactions' => 0,
                'sales_cents' => 0, 'cash_cents' => 0, 'debt_cents' => 0, 'other_cents' => 0],
        ]]);
        $this->assertStringContainsString('2026-10-31 · Timezone fixture', $html);
        $this->assertStringContainsString('Closed Nov 01, 2026 12:00 AM (Asia/Manila)', $html);
    }

    public function testEveryOfficeReportDisplaysUtcEventsInManilaWithoutChangingBusinessDate(): void
    {
        $report = [
            'id' => 1, 'store_name' => 'Timezone fixture', 'business_date' => '2026-10-31',
            'revision' => 1, 'status' => 'cashier_received', 'source_status' => 'verified',
            'transaction_count' => 1, 'sales_total' => 25, 'cash_total' => 25,
            'debt_total' => 0, 'other_total' => 0,
            'submitted_at' => '2026-10-31 04:00:00',
            'accounting_furnished_at' => '2026-10-31 15:59:00',
            'cashier_received_at' => '2026-10-31 16:00:00',
            'source_json' => json_encode(['session_id' => 1, 'closed_at' => '2026-10-31 04:00:00']),
            'submission_note' => '', 'be_review_note' => '',
        ];
        foreach (['be', 'cashier', 'accounting'] as $audience) {
            $html = view('office/daily_reports', ['audience' => $audience, 'reports' => [$report], 'message' => '', 'error' => '']);
            $this->assertStringContainsString('Timezone fixture · 2026-10-31', $html);
            $this->assertStringContainsString('Submitted Oct 31, 2026 12:00 PM', $html);
            $this->assertStringContainsString('Closed Oct 31, 2026 12:00 PM', $html);
            $this->assertStringContainsString('Accounting copy furnished: Oct 31, 2026 11:59 PM', $html);
            $this->assertStringContainsString('Cashier received: Nov 01, 2026 12:00 AM', $html);
            $this->assertStringContainsString('Report times are shown in Asia/Manila.', $html);
        }
    }
}
