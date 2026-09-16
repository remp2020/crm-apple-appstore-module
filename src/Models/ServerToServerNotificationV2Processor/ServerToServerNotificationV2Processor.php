<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationV2Processor;

use Crm\AppleAppstoreModule\Models\AppStoreServerDateTimesTrait;
use Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver\SubscriptionTypeResolverInterface;
use Crm\AppleAppstoreModule\Models\UserResolver\UserResolverInterface;
use Nette\Database\Table\ActiveRow;
use Readdle\AppStoreServerAPI\TransactionInfo;

class ServerToServerNotificationV2Processor implements ServerToServerNotificationV2ProcessorInterface
{
    use AppStoreServerDateTimesTrait;

    public function __construct(
        private SubscriptionTypeResolverInterface $subscriptionTypeResolver,
        private UserResolverInterface $userResolver,
    ) {
    }

    /**
     * @deprecated Use SubscriptionTypeResolverInterface::resolve() instead. Override the
     * `appleAppstoreSubscriptionTypeResolver` service to change how products and offers are paired.
     * Will be removed in the next major release.
     */
    public function getSubscriptionType(TransactionInfo $transactionInfo): ActiveRow
    {
        return $this->subscriptionTypeResolver->resolve($transactionInfo);
    }

    /**
     * @deprecated Use UserResolverInterface::resolve() instead. Override the `appleAppstoreUserResolver`
     * service to change how users are matched. Will be removed in the next major release.
     */
    public function getUser(TransactionInfo $transactionInfo): ActiveRow
    {
        return $this->userResolver->resolve($transactionInfo);
    }
}
