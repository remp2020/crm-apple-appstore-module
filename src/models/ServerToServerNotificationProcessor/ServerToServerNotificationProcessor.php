<?php

namespace Crm\AppleAppstoreModule\Model;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreSubscriptionTypesRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\UsersModule\Repository\UserMetaRepository;
use Nette\Database\Table\ActiveRow;

class ServerToServerNotificationProcessor implements ServerToServerNotificationProcessorInterface
{
    use ServerToServerNotificationDateTimesTrait;

    private $appleAppstoreSubscriptionTypesRepository;

    private $paymentMetaRepository;

    private $userMetaRepository;

    public function __construct(
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        PaymentMetaRepository $paymentMetaRepository,
        UserMetaRepository $userMetaRepository
    ) {
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
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
     * getUser returns User from Apple's ServerToServerNotification.
     *
     * - User is searched by original_transaction_id linked to previous payments (payment_meta).
     * - User is searched by original_transaction_id linked to user itself (user_meta).
     *
     * @return ActiveRow $user
     */
    public function getUser(ServerToServerNotification $serverToServerNotification): ActiveRow
    {
        $originalTransactionId = $serverToServerNotification->getUnifiedReceipt()->getLatestReceiptInfo()->getOriginalTransactionId();

        // search user by `original_transaction_id` linked to payment
        $paymentsWithMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );
        if (!empty($paymentsWithMeta)) {
            return reset($paymentsWithMeta)->payment->user;
        }

        // search user by `original_transaction_id` linked to user itself (eg. imported iOS users without payments in CRM)
        $usersMetas = $this->userMetaRepository->usersWithKey(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        )->fetchAll();
        if (count($usersMetas) > 1) {
            throw new \Exception("Multiple users with same original transaction ID [{$originalTransactionId}].");
        }
        if (!empty($usersMetas)) {
            return reset($usersMetas)->user;
        }

        throw new \Exception("No user found with provided original transaction ID [{$originalTransactionId}].");
    }
}
