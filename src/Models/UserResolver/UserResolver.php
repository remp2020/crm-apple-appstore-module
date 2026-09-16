<?php

namespace Crm\AppleAppstoreModule\Models\UserResolver;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\Random;
use Readdle\AppStoreServerAPI\TransactionInfo;

class UserResolver implements UserResolverInterface
{
    public function __construct(
        protected readonly PaymentMetaRepository $paymentMetaRepository,
        protected readonly UsersRepository $usersRepository,
        protected readonly UserMetaRepository $userMetaRepository,
        protected readonly UnclaimedUser $unclaimedUser,
    ) {
    }

    public function resolve(TransactionInfo $transactionInfo): ActiveRow
    {
        $user = $this->resolveByAppAccountToken($transactionInfo);
        if ($user) {
            return $user;
        }

        $originalTransactionId = $transactionInfo->getOriginalTransactionId();

        $user = $this->resolveByOriginalTransactionId($originalTransactionId);
        if ($user) {
            return $user;
        }

        return $this->createUnclaimedUser($originalTransactionId);
    }

    protected function resolveByAppAccountToken(TransactionInfo $transactionInfo): ?ActiveRow
    {
        $appAccountToken = $transactionInfo->getAppAccountToken();
        if (!isset($appAccountToken)) {
            return null;
        }

        $user = $this->usersRepository->findBy('uuid', $appAccountToken);
        if (!$user) {
            throw new \Exception("User not found by uuid based on provided appAccountToken: [{$appAccountToken}] ");
        }

        return $user->active === 1 ? $user : null;
    }

    protected function resolveByOriginalTransactionId(string $originalTransactionId): ?ActiveRow
    {
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

        return null;
    }

    protected function createUnclaimedUser(string $originalTransactionId): ActiveRow
    {
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
