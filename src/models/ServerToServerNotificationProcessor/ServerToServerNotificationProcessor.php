<?php

namespace Crm\AppleAppstoreModule\Model;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreSubscriptionTypesRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Nette\Database\Table\ActiveRow;

class ServerToServerNotificationProcessor implements ServerToServerNotificationProcessorInterface
{
    use ServerToServerNotificationDateTimesTrait;

    private $appleAppstoreSubscriptionTypesRepository;

    private $userMetaRepository;

    public function __construct(
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        UserMetaRepository $userMetaRepository
    ) {
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->userMetaRepository = $userMetaRepository;
    }

    /**
     * @inheritDoc
     */
    public function getSubscriptionType(ServerToServerNotification $serverToServerNotification): ActiveRow
    {
        $appleAppstoreProductId = $serverToServerNotification->getUnifiedReceipt()->getLatestReceiptInfo()->getProductId();
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($appleAppstoreProductId);
        if (!$subscriptionType) {
            throw new \Exception("Unable to find SubscriptionType by product ID [{$appleAppstoreProductId}] provided by ServerToServerNotification.");
        }
        return $subscriptionType;
    }

    /**
     * @inheritDoc
     */
    public function getUser(ServerToServerNotification $serverToServerNotification): ActiveRow
    {
        $originalTransactionId = $serverToServerNotification->getUnifiedReceipt()->getLatestReceiptInfo()->getOriginalTransactionId();
        $usersMetas = $this->userMetaRepository->usersWithKey(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        )->fetchAll();
        if (empty($usersMetas)) {
            throw new \Exception("No user found with provided original transaction ID [{$originalTransactionId}].");
        }
        if (count($usersMetas) > 1) {
            throw new \Exception("Multiple users with same original transaction ID [{$originalTransactionId}].");
        }

        $userMeta = reset($usersMetas);
        return $userMeta->user;
    }
}
