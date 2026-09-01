<?php
namespace Crm\AppleAppstoreModule\Repositories;

use Crm\AppleAppstoreModule\Models\Offer\OfferDiscountTypeEnum;
use Crm\ApplicationModule\Models\Database\Repository;
use Crm\ApplicationModule\Repositories\AuditLogRepository;
use Nette\Caching\Storage;
use Nette\Database\Explorer;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class AppleAppstoreSubscriptionTypesRepository extends Repository
{
    protected $tableName = 'apple_appstore_subscription_types';

    public function __construct(
        AuditLogRepository $auditLogRepository,
        Explorer $database,
        ?Storage $cacheStorage = null,
    ) {
        parent::__construct($database, $cacheStorage);
        $this->auditLogRepository = $auditLogRepository;
    }

    final public function add(
        string $appleAppstoreProductId,
        ActiveRow $subscriptionType,
        ?OfferDiscountTypeEnum $offerDiscountType = null,
        ?string $offerPeriod = null,
    ): ActiveRow {
        $now = new DateTime();
        return $this->insert([
            'product_id' => $appleAppstoreProductId,
            'offer_discount_type' => $offerDiscountType?->value,
            'offer_period' => $offerPeriod,
            'subscription_type_id' => $subscriptionType->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    final public function upsert(
        string $appleAppstoreProductId,
        ActiveRow $subscriptionType,
        ?OfferDiscountTypeEnum $offerDiscountType = null,
        ?string $offerPeriod = null,
    ): ActiveRow {
        $row = $this->findSubscriptionTypeMapping($appleAppstoreProductId, $offerDiscountType, $offerPeriod);

        if ($row) {
            $this->update($row, [
                'subscription_type_id' => $subscriptionType->id,
                'updated_at' => new DateTime(),
            ]);
            return $row;
        }

        return $this->add($appleAppstoreProductId, $subscriptionType, $offerDiscountType, $offerPeriod);
    }

    final public function update(ActiveRow &$row, $data)
    {
        $data['updated_at'] = new DateTime();
        return parent::update($row, $data);
    }

    final public function findSubscriptionTypeMapping(
        string $appleAppstoreProductId,
        ?OfferDiscountTypeEnum $offerDiscountType = null,
        ?string $offerPeriod = null,
    ): ?ActiveRow {
        return $this->getTable()->where([
            'product_id' => $appleAppstoreProductId,
            'offer_discount_type' => $offerDiscountType?->value,
            'offer_period' => $offerPeriod,
        ])->fetch();
    }

    /**
     * Returns the subscription type paired with the product itself, without any offer applied.
     *
     * @param string $appleAppstoreProductId Identification of product in Apple App Store
     */
    final public function findSubscriptionType(string $appleAppstoreProductId): ?ActiveRow
    {
        return $this->findSubscriptionTypeMapping($appleAppstoreProductId)?->subscription_type;
    }

    /**
     * Returns the subscription type paired with the product for the given offer. Falls back to a mapping
     * covering all periods of the discount type before giving up.
     *
     * @param string $appleAppstoreProductId Identification of product in Apple App Store
     */
    final public function findOfferSubscriptionType(
        string $appleAppstoreProductId,
        ?OfferDiscountTypeEnum $offerDiscountType,
        ?string $offerPeriod,
    ): ?ActiveRow {
        if ($offerDiscountType === null) {
            return null;
        }

        $mapping = $this->findSubscriptionTypeMapping($appleAppstoreProductId, $offerDiscountType, $offerPeriod)
            ?? $this->findSubscriptionTypeMapping($appleAppstoreProductId, $offerDiscountType);

        return $mapping?->subscription_type;
    }

    /**
     * @deprecated Use findSubscriptionType() or findOfferSubscriptionType() instead. Kept only for the
     * legacy Apple App Store API v1 receipt handling, will be removed in the next major release.
     *
     * @param string $appleAppstoreProductId Identification of product in Apple App Store
     */
    final public function findSubscriptionTypeByAppleAppstoreProductId(string $appleAppstoreProductId, bool $followNextSubscriptionType = true): ?ActiveRow
    {
        if ($followNextSubscriptionType) {
            return $this->findSubscriptionType($appleAppstoreProductId);
        }

        return $this->findOfferSubscriptionType(
            $appleAppstoreProductId,
            OfferDiscountTypeEnum::FreeTrial,
            null,
        ) ?? $this->findSubscriptionType($appleAppstoreProductId);
    }
}
