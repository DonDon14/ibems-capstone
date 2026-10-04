<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

final class SalaryImportIdentityGuard
{
    /**
     * @param array<string, true> $seenEmployeeIds
     * @param array<string, true> $seenEmails
     * @return array{errors: list<string>, existing: ?array}
     */
    public function inspect(BaseConnection $db, string $employeeId, string $email, array $seenEmployeeIds, array $seenEmails): array
    {
        $employeeId = trim($employeeId);
        $email = strtolower(trim($email));
        $errors = [];

        if ($employeeId !== '' && isset($seenEmployeeIds[$employeeId])) {
            $errors[] = 'employee_id is repeated in this CSV';
        }
        if ($email !== '' && isset($seenEmails[$email])) {
            $errors[] = 'email is repeated in this CSV';
        }

        $byId = $employeeId !== '' ? $db->table('users')->where('employee_id', $employeeId)->get()->getRowArray() : null;
        $byEmail = $email !== '' ? $db->table('users')->where('LOWER(email)', $email)->get()->getRowArray() : null;

        if ($byId && $byEmail && (int) $byId['id'] !== (int) $byEmail['id']) {
            $errors[] = 'employee_id and email belong to different existing users';
        } elseif ($byEmail && $employeeId !== '' && trim((string) ($byEmail['employee_id'] ?? '')) !== ''
            && trim((string) $byEmail['employee_id']) !== $employeeId) {
            $errors[] = 'email belongs to a different employee_id';
        }

        return ['errors' => $errors, 'existing' => $byId ?: $byEmail ?: null];
    }

    /** @param array<string, true> $seenEmployeeIds @param array<string, true> $seenEmails */
    public function claim(string $employeeId, string $email, array &$seenEmployeeIds, array &$seenEmails): void
    {
        $employeeId = trim($employeeId);
        $email = strtolower(trim($email));
        if ($employeeId !== '') {
            $seenEmployeeIds[$employeeId] = true;
        }
        if ($email !== '') {
            $seenEmails[$email] = true;
        }
    }
}
