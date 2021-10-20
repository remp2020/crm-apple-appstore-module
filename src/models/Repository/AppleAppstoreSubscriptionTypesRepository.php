<?php
namespace Crm\AppleAppstoreModule\Repository;

use Crm\ApplicationModule\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class AppleAppstoreSubscriptionTypesRepository extends Repository
{
    protected $tableName = 'apple_appstore_subscription_types';

    final public function add(string $appleAppstoreProductId, ActiveRow $subscriptionType)
    {
        $now = new DateTime();
        return $this->getTable()->insert([
            'product_id' => $appleAppstoreProductId,
            'subscription_type_id' => $subscriptionType->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    /**
     * @param string $appleAppstoreProductId Identification of product in Apple App Store
     */
    final public function findSubscriptionTypeByAppleAppstoreProductId(string $appleAppstoreProductId): ?ActiveRow
    {
        return ($this->findBy('product_id', $appleAppstoreProductId))->subscription_type ?? null;
    }
}
