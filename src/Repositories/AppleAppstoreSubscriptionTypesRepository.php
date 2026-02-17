<?php
namespace Crm\AppleAppstoreModule\Repositories;

use Crm\ApplicationModule\Models\Database\Repository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class AppleAppstoreSubscriptionTypesRepository extends Repository
{
    protected $tableName = 'apple_appstore_subscription_types';

    final public function add(string $appleAppstoreProductId, ActiveRow $subscriptionType): ActiveRow
    {
        $now = new DateTime();
        return $this->getTable()->insert([
            'product_id' => $appleAppstoreProductId,
            'subscription_type_id' => $subscriptionType->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    final public function upsert(string $appleAppstoreProductId, ActiveRow $subscriptionType): ActiveRow
    {
        $row = $this->getTable()->where('product_id = ?', $appleAppstoreProductId)->limit(1)->fetch();

        if ($row) {
            $this->update($row, [
                'subscription_type_id' => $subscriptionType->id,
                'updated_at' => new DateTime(),
            ]);
            return $row;
        }

        return $this->add($appleAppstoreProductId, $subscriptionType);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    /**
     * @param string $appleAppstoreProductId Identification of product in Apple App Store
     */
    final public function findSubscriptionTypeByAppleAppstoreProductId(string $appleAppstoreProductId, bool $followNextSubscriptionType = true): ?ActiveRow
    {
        $appStoreSubscriptionType = $this->findBy('product_id', $appleAppstoreProductId);
        if (!$appStoreSubscriptionType) {
            return null;
        }
        // TODO [crm#2938]: Apple - doesn't need to be in first batch of changes
        if ($followNextSubscriptionType && $appStoreSubscriptionType->subscription_type->next_subscription_type_id !== null) {
            return $appStoreSubscriptionType->subscription_type->next_subscription_type;
        }
        return $appStoreSubscriptionType->subscription_type;
    }
}
