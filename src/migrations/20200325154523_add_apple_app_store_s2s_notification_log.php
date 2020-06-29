<?php

use Phinx\Migration\AbstractMigration;

class AddAppleAppStoreS2SNotificationLog extends AbstractMigration
{
    public function change()
    {
        $this->table('apple_appstore_s2s_notification_log', ['comment' => 'Log of last_receipts from Apple AppStore`s ServerToServerNotification'])
            ->addColumn('payment_id', 'integer', [
                'null' => true,
                'comment' => 'CRM payment. Payment will be null if notification wasn`t processed',
            ])
            ->addColumn('original_transaction_id', 'string', [
                'null' => false,
                'comment' => 'Identificator of initial purchase which stays same through renewals',
            ])
            ->addColumn('s2s_notification', 'json', [
                'null' => false,
                'comment' => 'Apple AppStore`s ServerToServerNotification',
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addForeignKey('payment_id', 'payments')
            ->create();
    }
}
