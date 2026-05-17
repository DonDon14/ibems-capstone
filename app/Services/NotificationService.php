<?php

namespace App\Services;

use App\Models\NotificationModel;
use App\Models\UserModel;

class NotificationService
{
    public function sendTransactionEmail(?int $userId, int $transactionId, string $referenceNo): void
    {
        $model = new NotificationModel();

        if ($userId === null) {
            $model->insert([
                'user_id' => null,
                'txn_id' => $transactionId,
                'channel' => 'email',
                'status' => 'skipped',
                'sent_at' => date('Y-m-d H:i:s'),
                'error_msg' => 'Walk-in customer has no email.',
            ]);
            return;
        }

        $user = (new UserModel())->find($userId);
        if ($user === null || empty($user['email'])) {
            $model->insert([
                'user_id' => $userId,
                'txn_id' => $transactionId,
                'channel' => 'email',
                'status' => 'failed',
                'sent_at' => date('Y-m-d H:i:s'),
                'error_msg' => 'User email not found.',
            ]);
            return;
        }

        $email = service('email');
        $email->setTo($user['email']);
        $email->setSubject('IBEMS Transaction Confirmation');
        $email->setMessage("Your transaction reference is {$referenceNo}.");

        $sent = $email->send();
        $model->insert([
            'user_id' => $userId,
            'txn_id' => $transactionId,
            'channel' => 'email',
            'status' => $sent ? 'sent' : 'failed',
            'sent_at' => date('Y-m-d H:i:s'),
            'error_msg' => $sent ? null : (string) $email->printDebugger(['headers']),
        ]);
    }
}
