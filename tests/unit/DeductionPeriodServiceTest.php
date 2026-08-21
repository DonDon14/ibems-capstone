<?php

use App\Services\DeductionPeriodService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class DeductionPeriodServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = Database::connect();
        $table = $db->getPrefix() . 'deduction_periods';
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_code TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            frequency TEXT NOT NULL,
            date_start TEXT NOT NULL,
            date_end TEXT NOT NULL,
            expected_processing_date TEXT,
            preparation_deadline TEXT,
            status TEXT NOT NULL DEFAULT "draft",
            notes TEXT,
            created_by INTEGER,
            reviewed_by INTEGER,
            submitted_by INTEGER,
            confirmed_by INTEGER,
            finalized_by INTEGER,
            reviewed_at TEXT,
            submitted_at TEXT,
            confirmed_at TEXT,
            finalized_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
    }

    public function testCreatesConfigurableSemiMonthlyPeriod(): void
    {
        $result = (new DeductionPeriodService())->create([
            'period_code' => '2026-08-A',
            'label' => 'August 1-15, 2026',
            'frequency' => 'semi_monthly',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-15',
            'expected_processing_date' => '2026-08-20',
            'preparation_deadline' => '2026-08-17',
        ], 900);

        $this->assertSame('success', $result['status']);
        $this->assertSame(201, $result['code']);
        $this->assertSame('draft', $result['period']['status'] ?? null);
        $this->assertSame('semi_monthly', $result['period']['frequency'] ?? null);
        $this->assertSame('PAY-20260801-20260815', $result['period']['period_code'] ?? null);
        $this->assertSame('August 1-15, 2026', $result['period']['label'] ?? null);
        $this->assertSame(900, (int) ($result['period']['created_by'] ?? 0));
    }

    public function testRejectsOverlappingActivePeriod(): void
    {
        $service = new DeductionPeriodService();
        $first = $service->create([
            'period_code' => '2026-08-A',
            'label' => 'August first half',
            'frequency' => 'semi_monthly',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-15',
        ], 900);
        $this->assertSame('success', $first['status']);

        $second = $service->create([
            'period_code' => '2026-08-OVERLAP',
            'label' => 'Overlapping custom run',
            'frequency' => 'custom',
            'date_start' => '2026-08-10',
            'date_end' => '2026-08-20',
        ], 900);

        $this->assertSame('error', $second['status']);
        $this->assertSame(409, $second['code']);
        $this->assertStringContainsString('overlaps PAY-20260801-20260815', $second['message']);
    }

    public function testAllowsPeriodAfterCancelledOverlap(): void
    {
        $service = new DeductionPeriodService();
        $created = $service->create([
            'period_code' => '2026-08-A',
            'label' => 'Cancelled period',
            'frequency' => 'custom',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-15',
        ], 900);
        $this->assertSame('success', $created['status']);

        Database::connect()->table('deduction_periods')
            ->where('id', (int) $created['period']['id'])
            ->update(['status' => 'cancelled']);

        $replacement = $service->create([
            'period_code' => '2026-08-R',
            'label' => 'Replacement period',
            'frequency' => 'custom',
            'date_start' => '2026-08-01',
            'date_end' => '2026-08-15',
        ], 900);

        $this->assertSame('success', $replacement['status']);
        $this->assertSame('PAY-20260801-20260815-2', $replacement['period']['period_code'] ?? null);
    }

    public function testRejectsInvalidDateOrderAndUnknownFrequency(): void
    {
        $service = new DeductionPeriodService();
        $invalidDates = $service->create([
            'period_code' => '2026-09-X',
            'label' => 'Invalid dates',
            'frequency' => 'monthly',
            'date_start' => '2026-09-30',
            'date_end' => '2026-09-01',
        ], 900);
        $this->assertSame('End date cannot be before start date.', $invalidDates['message'] ?? null);

        $invalidFrequency = $service->create([
            'period_code' => '2026-09-Y',
            'label' => 'Invalid frequency',
            'frequency' => 'weekly',
            'date_start' => '2026-09-01',
            'date_end' => '2026-09-30',
        ], 900);
        $this->assertSame('Frequency must be semi-monthly, monthly, or custom.', $invalidFrequency['message'] ?? null);
    }
}
