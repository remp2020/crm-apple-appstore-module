<?php

namespace Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver;

use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

class TransactionProductOfferNotMatchedException extends \Exception
{
    public function __construct(
        TransactionInfo|RenewalInfo $transactionInfo,
        string $productId,
    ) {
        $offerType = $transactionInfo->getOfferType();
        $originalTransactionId = $transactionInfo->getOriginalTransactionId();

        if ($offerType) {
            parent::__construct("Unable to find SubscriptionType for [{$offerType}] offer " .
                "[{$transactionInfo->getOfferDiscountType()}][{$transactionInfo->getOfferPeriod()}] of product ID " .
                "[{$productId}], original transaction ID [{$originalTransactionId}].");
        } else {
            parent::__construct("Unable to find SubscriptionType for product ID [{$productId}], " .
                "original transaction ID [{$originalTransactionId}].");
        }
    }
}
