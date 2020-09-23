<?php

use Phinx\Migration\AbstractMigration;

class AppstoreTransactionDeviceTokens extends AbstractMigration
{
    public function up()
    {
        // fix previous migration
        $this->table('apple_appstore_receipts')
            ->changeColumn('original_transaction_id', 'string', ['null' => false])
            ->update();

        $this->table('apple_appstore_transaction_device_tokens')
            ->addColumn('original_transaction_id', 'string', ['null' => false])
            ->addColumn('device_token_id', 'integer', ['null' => false])
            ->addForeignKey('original_transaction_id', 'apple_appstore_receipts', 'original_transaction_id')
            ->addForeignKey('device_token_id', 'device_tokens')
            ->create();
    }

    public function down()
    {
        $this->table('apple_appstore_transaction_device_tokens')
            ->drop()
            ->update();

        // fix previous migration
        $this->table('apple_appstore_receipts')
            ->changeColumn('original_transaction_id', 'string', ['null' => true])
            ->update();
    }
}
