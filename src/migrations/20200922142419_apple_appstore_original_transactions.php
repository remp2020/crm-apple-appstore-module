<?php

use Phinx\Migration\AbstractMigration;

class AppleAppstoreOriginalTransactions extends AbstractMigration
{
    public function up()
    {
        $this->table('apple_appstore_transaction_device_tokens')
            ->dropForeignKey('original_transaction_id')
            ->update();
        $this->table('apple_appstore_transaction_device_tokens')
            ->renameColumn('original_transaction_id','original_transaction_id_migration')
            ->addColumn('original_transaction_id', 'integer', ['null' => true])
            ->update();

        $this->table('apple_appstore_receipts')
            ->rename('apple_appstore_original_transactions')
            ->renameColumn('receipt', 'latest_receipt')
            ->update();

        $sql = <<<SQL
UPDATE apple_appstore_transaction_device_tokens
JOIN apple_appstore_original_transactions
  ON apple_appstore_original_transactions.original_transaction_id = apple_appstore_transaction_device_tokens.original_transaction_id_migration
SET apple_appstore_transaction_device_tokens.original_transaction_id = apple_appstore_original_transactions.id
SQL;
        $this->execute($sql);

        $this->table('apple_appstore_transaction_device_tokens')
            ->changeColumn('original_transaction_id', 'integer', ['null' => false, 'after' => 'id'])
            ->removeColumn('original_transaction_id_migration')
            ->addForeignKey('original_transaction_id', 'apple_appstore_original_transactions')
            ->update();
    }

    public function down()
    {
        $this->table('apple_appstore_transaction_device_tokens')
            ->dropForeignKey('original_transaction_id')
            ->update();
        $this->table('apple_appstore_transaction_device_tokens')
            ->renameColumn('original_transaction_id','original_transaction_id_migration')
            ->addColumn('original_transaction_id', 'string', ['null' => true])
            ->update();

        $this->table('apple_appstore_original_transactions')
            ->rename('apple_appstore_receipts')
            ->renameColumn('latest_receipt', 'receipt')
            ->update();

        $sql = <<<SQL
UPDATE apple_appstore_transaction_device_tokens
JOIN apple_appstore_receipts
  ON apple_appstore_receipts.id = apple_appstore_transaction_device_tokens.original_transaction_id_migration
SET apple_appstore_transaction_device_tokens.original_transaction_id = apple_appstore_receipts.original_transaction_id
SQL;
        $this->execute($sql);

        $this->table('apple_appstore_transaction_device_tokens')
            ->changeColumn('original_transaction_id', 'string', ['null' => false, 'after' => 'id'])
            ->removeColumn('original_transaction_id_migration')
            ->addForeignKey('original_transaction_id', 'apple_appstore_receipts', 'original_transaction_id')
            ->update();
    }
}
