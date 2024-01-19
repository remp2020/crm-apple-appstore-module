<?php

namespace Crm\AppleAppstoreModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class AppleAppstoreServerToServerNotificationLogRepository extends Repository
{
    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_ERROR = 'error';
    public const STATUS_DO_NOT_RETRY = 'do_not_retry';

    protected $tableName = 'apple_appstore_s2s_notification_log';

    final public function add(string $serverToServerNotification, string $originalTransactionID, ?ActiveRow $payment = null)
    {
        $now = new DateTime();
        $data = [
            's2s_notification' => $serverToServerNotification,
            'original_transaction_id' => $originalTransactionID,
            'status' => self::STATUS_NEW,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($payment !== null) {
            $data['payment_id'] = $payment->id;
        }

        return $this->insert($data);
    }

    final public function addPayment(ActiveRow $serverToServerNotification, ActiveRow $payment)
    {
        return $this->update($serverToServerNotification, [
            'payment_id' => $payment->id,
            'status' => self::STATUS_PROCESSED,
        ]);
    }

    final public function changeStatus(ActiveRow $serverToServerNotification, string $status)
    {
        return $this->update($serverToServerNotification, [
            'status' => $status,
        ]);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    final public function findLastByOriginalTransactionID(string $originalTransactionID)
    {
        return $this->getTable()
            ->where('original_transaction_id', $originalTransactionID)
            ->where('payment_id IS NOT NULL')
            ->order('created_at DESC')
            ->fetch();
    }
}
