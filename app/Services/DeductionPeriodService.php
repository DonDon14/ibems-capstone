<?php

namespace App\Services;

use App\Models\DeductionPeriodModel;
use DateTimeImmutable;

class DeductionPeriodService
{
    public const FREQUENCIES = ['semi_monthly', 'monthly', 'custom'];
    public const STATUSES = [
        'draft',
        'reviewed',
        'submitted',
        'partially_processed',
        'processed',
        'reconciled',
        'finalized',
        'cancelled',
    ];

    public function create(array $input, int $actorId): array
    {
        $periodCode = strtoupper(trim((string) ($input['period_code'] ?? '')));
        $label = trim((string) ($input['label'] ?? ''));
        $frequency = strtolower(trim((string) ($input['frequency'] ?? '')));
        $dateStart = $this->date((string) ($input['date_start'] ?? ''));
        $dateEnd = $this->date((string) ($input['date_end'] ?? ''));
        $expectedDate = $this->optionalDate((string) ($input['expected_processing_date'] ?? ''));
        $deadline = $this->optionalDate((string) ($input['preparation_deadline'] ?? ''));

        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{2,39}$/', $periodCode)) {
            return $this->error('Period code must be 3 to 40 letters, numbers, dots, dashes, or underscores.');
        }
        if ($label === '' || mb_strlen($label) > 120) {
            return $this->error('Period label is required and must not exceed 120 characters.');
        }
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            return $this->error('Frequency must be semi-monthly, monthly, or custom.');
        }
        if (!$dateStart || !$dateEnd) {
            return $this->error('Start and end dates must use YYYY-MM-DD format.');
        }
        if ($dateEnd < $dateStart) {
            return $this->error('End date cannot be before start date.');
        }
        if ($expectedDate && $expectedDate < $dateStart) {
            return $this->error('Expected processing date cannot be before the period starts.');
        }
        if ($deadline && $expectedDate && $deadline > $expectedDate) {
            return $this->error('Preparation deadline cannot be after the expected processing date.');
        }

        $model = new DeductionPeriodModel();
        if ($model->where('period_code', $periodCode)->countAllResults() > 0) {
            return $this->error('Period code already exists.', 409);
        }

        $overlap = $model
            ->where('status !=', 'cancelled')
            ->where('date_start <=', $dateEnd->format('Y-m-d'))
            ->where('date_end >=', $dateStart->format('Y-m-d'))
            ->first();
        if ($overlap) {
            return $this->error(
                'Deduction period overlaps ' . (string) ($overlap['period_code'] ?? 'an existing period') . '.',
                409
            );
        }

        $now = date('Y-m-d H:i:s');
        $periodId = $model->insert([
            'period_code' => $periodCode,
            'label' => $label,
            'frequency' => $frequency,
            'date_start' => $dateStart->format('Y-m-d'),
            'date_end' => $dateEnd->format('Y-m-d'),
            'expected_processing_date' => $expectedDate?->format('Y-m-d'),
            'preparation_deadline' => $deadline?->format('Y-m-d'),
            'status' => 'draft',
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            'created_by' => $actorId > 0 ? $actorId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!$periodId) {
            return $this->error('Failed to create deduction period.', 500);
        }

        return [
            'status' => 'success',
            'code' => 201,
            'period' => $model->find((int) $periodId),
        ];
    }

    private function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }

    private function optionalDate(string $value): ?DateTimeImmutable
    {
        return trim($value) === '' ? null : $this->date($value);
    }

    private function error(string $message, int $code = 400): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }
}
