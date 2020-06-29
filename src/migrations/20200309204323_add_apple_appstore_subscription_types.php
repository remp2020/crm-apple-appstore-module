<?php

use Phinx\Migration\AbstractMigration;

class AddAppleAppstoreSubscriptionTypes extends AbstractMigration
{
    public function change()
    {
        $this->table('apple_appstore_subscription_types')
            ->addColumn('product_id', 'string', [
                'null' => false,
                'comment' => 'Apple AppStore`s productId identification of subscription type / product.'
            ])
            ->addColumn('subscription_type_id', 'integer', [
                'null' => false,
                'comment' => 'CRM subscription type'
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addForeignKey('subscription_type_id', 'subscription_types')
            ->addIndex(['product_id'], array('unique' => true))
            ->create();
    }
}
