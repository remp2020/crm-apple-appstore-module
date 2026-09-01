<?php

namespace Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver;

use Crm\AppleAppstoreModule\Models\Offer\OfferDiscountTypeEnum;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Nette\Database\Table\ActiveRow;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

class SubscriptionTypeResolver implements SubscriptionTypeResolverInterface
{
    public function __construct(
        private readonly AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
    ) {
    }

    public function resolve(TransactionInfo $transactionInfo): ActiveRow
    {
        return $this->resolveProductOffer($transactionInfo, $transactionInfo->getProductId());
    }

    public function resolveForRenewal(RenewalInfo $renewalInfo): ActiveRow
    {
        // RenewalInfo describes the upcoming renewal, so it's `autoRenewProductId` (not `productId`) which
        // holds the product the resolved subscription type has to be paired with.
        return $this->resolveProductOffer($renewalInfo, $renewalInfo->getAutoRenewProductId());
    }

    private function resolveProductOffer(TransactionInfo|RenewalInfo $transactionInfo, string $productId): ActiveRow
    {
        $offerType = $transactionInfo->getOfferType();

        if ($offerType === null) {
            $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionType(
                appleAppstoreProductId: $productId,
            );
        } else {
            $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findOfferSubscriptionType(
                appleAppstoreProductId: $productId,
                offerDiscountType: OfferDiscountTypeEnum::fromValue($transactionInfo->getOfferDiscountType()),
                offerPeriod: $transactionInfo->getOfferPeriod(),
            );
        }

        if (!$subscriptionType) {
            throw new TransactionProductOfferNotMatchedException($transactionInfo, $productId);
        }

        return $subscriptionType;
    }
}
