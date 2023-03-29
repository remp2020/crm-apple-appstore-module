<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class FixResurrectedAppleRenewals extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            UPDATE recurrent_payments
            INNER JOIN payments
                ON recurrent_payments.payment_id = payments.id
                AND payments.status = 'prepaid'
            INNER JOIN payment_gateways
                ON payments.payment_gateway_id = payment_gateways.id
                AND payment_gateways.code = 'apple_appstore'
            SET recurrent_payments.state = 'charged'
            WHERE recurrent_payments.state = 'charge_failed'
            SQL
        );
    }

    public function down()
    {
        $this->output->writeln('This is data migration. Down migration is not available.');
    }
}
