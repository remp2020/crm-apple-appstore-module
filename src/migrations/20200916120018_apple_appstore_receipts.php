<?php

use Phinx\Migration\AbstractMigration;

class AppleAppstoreReceipts extends AbstractMigration
{
    public function change()
    {
        $this->table('apple_appstore_receipts')
            ->addColumn('original_transaction_id', 'string', ['null' => false])
            ->addColumn('receipt', 'text', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex('original_transaction_id', ['unique' => true])
            ->create();
    }
}
