<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AllowNullOriginalTransactionsLatestReceipt extends AbstractMigration
{
    public function up(): void
    {
        $this->table('apple_appstore_original_transactions')
            ->changeColumn('latest_receipt', 'text', ['null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->output->writeln('Down migration is not available.');

//        $this->table('apple_appstore_original_transactions')
//            ->changeColumn('latest_receipt', 'text', ['null' => false])
//            ->update();
    }
}
