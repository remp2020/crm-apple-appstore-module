<?php

namespace Crm\AppleAppstoreModule\Models\ServerToServerNotificationV2Processor;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Models\AppStoreServerDateTimesTrait;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Random;
use Readdle\AppStoreServerAPI\TransactionInfo;

class ServerToServerNotificationV2Processor implements ServerToServerNotificationV2ProcessorInterface
{
    use AppStoreServerDateTimesTrait;

    public function __construct(
        private AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        private PaymentMetaRepository $paymentMetaRepository,
        private UsersRepository $usersRepository,
        private UserMetaRepository $userMetaRepository,
        private UnclaimedUser $unclaimedUser,
    ) {
    }

    public function getSubscriptionType(TransactionInfo $transactionInfo): ActiveRow
    {
        $appleAppstoreProductId = $transactionInfo->getProductId();
        $offerType = $transactionInfo->getOfferType();
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository->findSubscriptionTypeByAppleAppstoreProductId($appleAppstoreProductId, is_null($offerType));
        if (!$subscriptionType) {
            throw new \Exception("Unable to find SubscriptionType by product ID [{$appleAppstoreProductId}] provided by ServerToServerNotification.");
        }
        return $subscriptionType;
    }

    public function getUser(TransactionInfo $transactionInfo): ActiveRow
    {
        $appAccountToken = $transactionInfo->getAppAccountToken();
        if (isset($appAccountToken)) {
            $user = $this->usersRepository->findBy('uuid', $appAccountToken);
            if (!$user) {
                throw new \Exception("User not found by uuid based on provided appAccountToken: [{$appAccountToken}] ");
            }
            if ($user->active === 1) {
                return $user;
            }
        }

        $originalTransactionId = $transactionInfo->getOriginalTransactionId();

        // search user by `original_transaction_id` linked to payment
        $paymentsWithMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId,
        );
        if (!empty($paymentsWithMeta)) {
            $user = reset($paymentsWithMeta)->payment->user;
            if ($user && $user->active === 1) {
                return $user;
            }
        }

        // search user by `original_transaction_id` linked to user itself (eg. imported iOS users without payments in CRM)
        $usersMetas = $this->userMetaRepository->usersWithKey(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId,
        )->fetchAll();
        if (count($usersMetas) > 1) {
            throw new \Exception("Multiple users with same original transaction ID [{$originalTransactionId}].");
        }
        if (!empty($usersMetas)) {
            $user = reset($usersMetas)->user;
            if ($user && $user->active === 1) {
                return $user;
            }
        }

        // no user found; create anonymous unclaimed user (iOS in-app purchases have to be possible without account in CRM)
        $user = $this->unclaimedUser->createUnclaimedUser(
            "apple_appstore_" . $originalTransactionId . "_" . Random::generate(),
            AppleAppstoreModule::USER_SOURCE_APP,
        );
        $this->userMetaRepository->add(
            $user,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId,
        );
        return $user;
    }
}
