<?php

use Phinx\Migration\AbstractMigration;

class AppleAppStoreS2SNotificationLogAddStatus extends AbstractMigration
{
    public function change()
    {
        $this->table('apple_appstore_s2s_notification_log')
            ->addColumn('status', 'enum', [
                'null' => true,
                'after' => 'payment_id',
                'values' => [
                    'new',
                    'processed',
                    'error',
                    'do_not_retry',
                ],
                'comment' => 'Status of ServerToServerNotification processing',
            ])
            ->update();
    }
}
