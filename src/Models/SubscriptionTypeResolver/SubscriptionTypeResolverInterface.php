<?php

namespace Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver;

use Nette\Database\Table\ActiveRow;
use Readdle\AppStoreServerAPI\RenewalInfo;
use Readdle\AppStoreServerAPI\TransactionInfo;

interface SubscriptionTypeResolverInterface
{
    /**
     * resolve returns the SubscriptionType paired with the product and the offer of Apple's TransactionInfo.
     *
     * @throws TransactionProductOfferNotMatchedException if the product and its offer have no pairing
     */
    public function resolve(TransactionInfo $transactionInfo): ActiveRow;

    /**
     * resolveForRenewal returns the SubscriptionType paired with the product and the offer of the upcoming
     * renewal described by Apple's RenewalInfo.
     *
     * @throws TransactionProductOfferNotMatchedException if the product and its offer have no pairing
     */
    public function resolveForRenewal(RenewalInfo $renewalInfo): ActiveRow;
}
