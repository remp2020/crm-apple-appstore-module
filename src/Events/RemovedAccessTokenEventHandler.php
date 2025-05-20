<?php

namespace Crm\AppleAppstoreModule\Events;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\UsersModule\Events\RemovedAccessTokenEvent;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;

class RemovedAccessTokenEventHandler extends AbstractListener
{
    private $paymentMetaRepository;

    private $deviceTokensRepository;

    private $accessTokensRepository;

    private $usersRepository;

    public function __construct(
        PaymentMetaRepository $paymentMetaRepository,
        DeviceTokensRepository $deviceTokensRepository,
        AccessTokensRepository $accessTokensRepository,
        UsersRepository $usersRepository,
    ) {
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
        $this->accessTokensRepository = $accessTokensRepository;
        $this->usersRepository = $usersRepository;
    }

    public function handle(EventInterface $event)
    {
        if (!$event instanceof RemovedAccessTokenEvent) {
            throw new \Exception('Invalid type of event received, UserSignOutEvent expected: ' . get_class($event));
        }

        $user = $this->usersRepository->find($event->getUserId());
        if (!$user) {
            throw new \Exception('Unable to find user to process: ' . $event->getUserId());
        }

        // We need to make sure that any user with inapp purchase has all its device token linked correctly.
        // We do this by creating access tokens that are backend only to preserve this link.

        $userOriginalTransactionIds = $this->paymentMetaRepository->getTable()
            ->select('DISTINCT value')
            ->where([
                'key' => AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                'payment.user_id' => $user->id,
            ])
            ->fetchPairs('value', 'value');

        if (!count($userOriginalTransactionIds)) {
            return;
        }

        foreach ($userOriginalTransactionIds as $originalTransactionId) {
            $deviceTokens = $this->deviceTokensRepository->getTable()
                ->where([
                    ':apple_appstore_transaction_device_tokens.original_transaction.original_transaction_id' => $originalTransactionId,
                ])
                ->fetchAll();

            foreach ($deviceTokens as $deviceToken) {
                $isTokenLinked = $this->accessTokensRepository->getTable()->where([
                    'device_token_id' => $deviceToken->id,
                    'user_id' => $user->id,
                ])->count('*');
                if ($isTokenLinked) {
                    continue;
                }

                $accessToken = $this->accessTokensRepository->add(
                    $user,
                    3,
                    AppleAppstoreModule::USER_SOURCE_APP,
                );
                $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);
            }
        }
    }
}
