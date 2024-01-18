<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationProcessor;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Models\LatestReceiptInfo;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Random;

class ServerToServerNotificationProcessor implements ServerToServerNotificationProcessorInterface
{
    use ServerToServerNotificationDateTimesTrait;
    use ServerToServerNotificationLatestReceiptTrait;
    use ServerToServerNotificationPendingRenewalTrait;

    private $appleAppstoreSubscriptionTypesRepository;

    private $paymentMetaRepository;

    private $unclaimedUser;

    private $userMetaRepository;

    public function __construct(
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        PaymentMetaRepository $paymentMetaRepository,
        UnclaimedUser $unclaimedUser,
        UserMetaRepository $userMetaRepository
    ) {
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->unclaimedUser = $unclaimedUser;
        $this->userMetaRepository = $userMetaRepository;
    }

    /**
     * @inheritDoc
     */
    public function getSubscriptionType(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        $appleAppstoreProductId = $latestReceiptInfo->getProductId();
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($appleAppstoreProductId, !$latestReceiptInfo->isTrialPeriod());
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
     * - If no user was found, anonymous unclaimed user is created
     *   and used to process iOS in-app purchases without registered user.
     *
     * @return ActiveRow $user
     */
    public function getUser(LatestReceiptInfo $latestReceiptInfo): ActiveRow
    {
        $originalTransactionId = $latestReceiptInfo->getOriginalTransactionId();

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

        // no user found; create anonymous unclaimed user (iOS in-app purchases have to be possible without account in CRM)
        $user = $this->unclaimedUser->createUnclaimedUser(
            "apple_appstore_" . $originalTransactionId . "_" . Random::generate(),
            AppleAppstoreModule::USER_SOURCE_APP
        );
        $this->userMetaRepository->add(
            $user,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );
        return $user;
    }
}
