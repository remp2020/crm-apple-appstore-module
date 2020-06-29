<?php

namespace Crm\AppleAppstoreModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\IRow;
use Nette\Utils\DateTime;

class AppleAppstoreServerToServerNotificationLogRepository extends Repository
{
    protected $tableName = 'apple_appstore_s2s_notification_log';

    final public function add(string $serverToServerNotification, string $originalTransactionID, ?ActiveRow $payment = null)
    {
        $now = new DateTime();
        $data = [
            's2s_notification' => $serverToServerNotification,
            'original_transaction_id' => $originalTransactionID,
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
            'updated_at' => new DateTime(),
        ]);
    }

    final public function update(IRow &$row, $data)
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
