<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Models\AppStoreServerApiFactory;
use Crm\AppleAppstoreModule\Models\AppStoreServerDateTimesTrait;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreTransactionDeviceTokensRepository;
use Crm\ApplicationModule\Models\Redis\RedisClientTrait;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\UsersModule\Models\Auth\UserTokenAuthorization;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\IResponse;
use Nette\Utils\Random;
use Readdle\AppStoreServerAPI\Exception\AppStoreServerAPIException;
use Readdle\AppStoreServerAPI\TransactionInfo;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tomaj\NetteApi\Response\ResponseInterface;
use Tracy\Debugger;
use malkusch\lock\mutex\PredisMutex;

class VerifyPurchaseV2ApiHandler extends ApiHandler
{
    use JsonValidationTrait;
    use RedisClientTrait;
    use AppStoreServerDateTimesTrait;

    public function __construct(
        private AppStoreServerApiFactory $appStoreServerApiFactory,
        private PaymentMetaRepository $paymentMetaRepository,
        private AccessTokensRepository $accessTokensRepository,
        private DeviceTokensRepository $deviceTokensRepository,
        private AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        private AppleAppstoreTransactionDeviceTokensRepository $appleAppstoreTransactionDeviceTokensRepository,
        private UserMetaRepository $userMetaRepository,
        private UnclaimedUser $unclaimedUser,
        private AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        private PaymentsRepository $paymentsRepository,
        private PaymentGatewaysRepository $paymentGatewaysRepository,
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
    ) {
        parent::__construct();
    }


    public function params(): array
    {
        return [];
    }

    public function handle(array $params): ResponseInterface
    {
        $authorization = $this->getAuthorization();
        if (!($authorization instanceof UserTokenAuthorization)) {
            throw new \Exception("Wrong authorization service used. Should be 'UserTokenAuthorization'");
        }

        // validate input
        $validator = $this->validateInput(__DIR__ . '/verify-purchase-v2.schema.json');
        if ($validator->hasErrorResponse()) {
            return $validator->getErrorResponse();
        }
        $payload = $validator->getParsedObject();

        $transaction = $this->paymentMetaRepository->findByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $payload->transaction_id,
        );

        if ($transaction) {
            $originalTransactionId = $this->paymentMetaRepository->findByPaymentAndKey(
                $transaction->payment,
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID
            );

            $this->pairUserWithAuthorizedToken(
                $authorization,
                $transaction->payment->user,
                $originalTransactionId->value
            );
            return new JsonApiResponse(IResponse::S200_OK, [
                'status' => 'ok',
                'code' => 'success',
                'message' => "Apple purchase verified (transaction was already processed).",
            ]);
        }

        $gatewayMode = $payload->gateway_mode ?? null;
        $appStoreServerApi = $this->appStoreServerApiFactory->create($gatewayMode);

        try {
            $transactionHistory = $appStoreServerApi->getTransactionHistory($payload->transaction_id, ['sort' => 'DESCENDING']);
            $transactionInfo = $transactionHistory->getTransactions()->current();
        } catch (AppStoreServerAPIException $e) {
            Debugger::log("Unable to get transaction info from App Store Server Api. Error: [{$e->getMessage()}]", Debugger::ERROR);
            return new JsonApiResponse(IResponse::S500_InternalServerError, [
                'status' => 'error',
                'error' => 'unable_to_validate',
                'message' => 'Unable to validate Apple AppStore payment.',
            ]);
        }

        $this->appleAppstoreOriginalTransactionsRepository->add($transactionInfo->getOriginalTransactionId());

        // Mutex to avoid app and S2S notification procession collision (and therefore e.g. multiple payments to be created)
        $mutex = new PredisMutex(
            [$this->redis()],
            'process_apple_transaction_id_' . $payload->transaction_id,
            20
        );

        return $mutex->synchronized(function () use ($transactionInfo, $payload, $authorization) {
            $userOrResponse = $this->getUser($authorization, $transactionInfo, $payload->locale ?? null);
            if ($userOrResponse instanceof JsonApiResponse) {
                return $userOrResponse;
            }
            /** @var ActiveRow $user */
            $user = $userOrResponse;

            return $this->createPayment($user, $transactionInfo, $payload->articleId ?? null);
        });
    }

    private function pairUserWithAuthorizedToken(UserTokenAuthorization $authorization, $user, $originalTransactionId)
    {
        // pair new unclaimed user with device token from authorization
        $deviceToken = null;
        foreach ($authorization->getAccessTokens() as $accessToken) {
            if (isset($accessToken->device_token)) {
                $deviceToken = $accessToken->device_token;
                break;
            }
        }

        if (!$deviceToken) {
            // try to read the token from authorized data (if handler was authorized directly with device token)
            $token = $authorization->getAuthorizedData()['token'] ?? null;
            if (isset($token->token)) {
                // just make sure it's actual and valid device token
                $deviceToken = $this->deviceTokensRepository->findByToken($token->token);
            }
        }

        if ($deviceToken) {
            $accessToken = $this->accessTokensRepository
                ->allUserTokensBySource($user->id, AppleAppstoreModule::USER_SOURCE_APP)
                ->where('device_token_id = ?', $deviceToken->id)
                ->limit(1)
                ->fetch();
            if (!$accessToken) {
                $accessToken = $this->accessTokensRepository->add($user, 3, AppleAppstoreModule::USER_SOURCE_APP);
            }
            $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);

            $originalTransactionRow = $this->appleAppstoreOriginalTransactionsRepository
                ->findByOriginalTransactionId($originalTransactionId);
            $this->appleAppstoreTransactionDeviceTokensRepository->add(
                $originalTransactionRow,
                $deviceToken
            );
        } else {
            // TODO: shouldn't we throw an exception here? or return special error to the app?
            Debugger::log("No device token found. Unable to pair new unclaimed user [{$user->id}].", Debugger::ERROR);
        }
    }

    private function getUser(UserTokenAuthorization $authorization, TransactionInfo $transactionInfo, string $locale = null)
    {
        $user = null;

        // use authorized user if there is only one logged/claimed user or if there is only one unclaimed user
        $unclaimedUsers = [];
        $claimedUsers = [];
        foreach ($authorization->getAuthorizedUsers() as $authorizedUser) {
            if ($this->userMetaRepository->userMetaValueByKey($authorizedUser, UnclaimedUser::META_KEY)) {
                $unclaimedUsers[] = $authorizedUser;
            } else {
                $claimedUsers[] = $authorizedUser;
            }
        }

        if (count($claimedUsers) === 1) {
            $userFromToken = reset($claimedUsers);
        } elseif (count($unclaimedUsers) === 1) {
            $userFromToken = reset($unclaimedUsers);
        } else {
            // no user fits criteria; user will be created as unclaimed
            $userFromToken = null;
        }

        $userFromOriginalTransaction = $this->getUserByOriginalTransactionId($transactionInfo->getOriginalTransactionId());
        if (!$userFromOriginalTransaction) {
            $user = $userFromToken;
        } else {
            if ($userFromToken === null) {
                $user = $userFromOriginalTransaction;
            } else {
                if ($userFromToken->id !== $userFromOriginalTransaction->id) {
                    // find device token needed for claiming users
                    $deviceToken = null;
                    foreach ($authorization->getAccessTokens() as $accessToken) {
                        if (isset($accessToken->device_token_id)) {
                            $deviceToken = $accessToken->device_token;
                            break;
                        }
                    }
                    if ($deviceToken) {
                        // existing user with linked purchase is unclaimed? claim it
                        if ($this->userMetaRepository->userMetaValueByKey($userFromOriginalTransaction, UnclaimedUser::META_KEY)) {
                            $this->unclaimedUser->claimUser($userFromOriginalTransaction, $userFromToken, $deviceToken);
                            $user = $userFromToken;
                        } elseif ($this->userMetaRepository->userMetaValueByKey($userFromToken, UnclaimedUser::META_KEY)) {
                            $this->unclaimedUser->claimUser($userFromToken, $userFromOriginalTransaction, $deviceToken);
                            $user = $userFromOriginalTransaction;
                        }
                    } else {
                        return new JsonApiResponse(IResponse::S400_BadRequest, [
                            'status' => 'error',
                            'error' => 'purchase_already_owned',
                            'message' => "Unable to verify purchase for user [$userFromToken->public_name]. This or previous purchase already owned by other user.",
                        ]);
                    }
                } else {
                    $user = $userFromToken;
                }
            }
        }

        // create unclaimed user if none was provided by authorization
        if ($user === null) {
            $user = $this->unclaimedUser->createUnclaimedUser(
                "apple_appstore_" . $transactionInfo->getOriginalTransactionId() . "_" . Random::generate(),
                AppleAppstoreModule::USER_SOURCE_APP,
                $locale
            );
            $this->userMetaRepository->add(
                $user,
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                $transactionInfo->getOriginalTransactionId()
            );
        }

        $this->pairUserWithAuthorizedToken(
            $authorization,
            $user,
            $transactionInfo->getOriginalTransactionId()
        );

        return $user;
    }

    private function createPayment(ActiveRow $user, TransactionInfo $transactionInfo, ?string $articleID): JsonApiResponse
    {
        $isTrial = (bool) $transactionInfo->getOfferType();
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository
            ->findSubscriptionTypeByAppleAppstoreProductId($transactionInfo->getProductId(), !$isTrial);
        if (!$subscriptionType) {
            Debugger::log(
                "Unable to find SubscriptionType by product ID [{$transactionInfo->getProductId()}] from transaction [{$transactionInfo->getOriginalTransactionId()}].",
                Debugger::ERROR
            );
            return new JsonApiResponse(IResponse::S500_InternalServerError, [
                'status' => 'error',
                'error' => 'missing_subscription_type',
                'message' => 'Unable to find SubscriptionType by product ID from validated receipt.',
            ]);
        }

        if (!$transactionInfo->getExpiresDate()) {
            Debugger::log(
                "Unable to load expires_date from transaction [{$transactionInfo->getTransactionId()}].",
                Debugger::ERROR
            );
            return new JsonApiResponse(IResponse::S503_ServiceUnavailable, [
                'status' => 'error',
                'error' => 'transaction_without_expires_date',
                'message' => 'Unable to load expires_date from validated transaction.',
            ]);
        }

        $transactionPayment = $this->paymentsRepository->userPayments($user->id)
            ->where([
                'status' => PaymentsRepository::STATUS_PREPAID,
                'payments.subscription_type_id' => $subscriptionType->id,
                ':payment_meta.key' => AppleAppstoreModule::META_KEY_TRANSACTION_ID,
                ':payment_meta.value' => $transactionInfo->getTransactionId(),
            ])
            ->fetch();

        if ($transactionPayment) {
            return new JsonApiResponse(IResponse::S200_OK, [
                'status' => 'ok',
                'code' => 'success',
                'message' => "Apple purchase verified.",
            ]);
        }

        $metas = [
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $transactionInfo->getOriginalTransactionId(),
            AppleAppstoreModule::META_KEY_PRODUCT_ID => $transactionInfo->getProductId(),
            AppleAppstoreModule::META_KEY_TRANSACTION_ID => $transactionInfo->getTransactionId(),
        ];
        if ($articleID) {
            $metas['article_id'] = $articleID;
        }

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));

        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);
        if (!$paymentGateway) {
            Debugger::log(
                "Unable to find PaymentGateway with code [{$paymentGatewayCode}]. Is AppleAppstoreModule enabled?",
                Debugger::ERROR
            );
            $response = new JsonApiResponse(IResponse::S500_InternalServerError, [
                'status' => 'error',
                'error' => 'internal_server_error',
                'message' => "Unable to find PaymentGateway with code [{$paymentGatewayCode}].",
            ]);
            return $response;
        }

        $subscriptionStartAt = $this->getSubscriptionStartAt($transactionInfo);
        $subscriptionEndAt = $this->getSubscriptionEndAt($transactionInfo);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            subscriptionStartAt: $subscriptionStartAt,
            subscriptionEndAt: $subscriptionEndAt,
            metaData: $metas
        );
        $this->paymentsRepository->update($payment, [
            'paid_at' => $subscriptionStartAt,
        ]);
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentsRepository::STATUS_PREPAID);

        // handle recurrent payment
        // - original_transaction_id will be used as recurrent token
        // - stop any previous recurrent payments with the same original transaction id

        // TODO: moze tu prist upgrade?
        $activeOriginalTransactionRecurrents = $this->recurrentPaymentsRepository
            ->getUserActiveRecurrentPayments($payment->user_id)
            ->where(['payment_method.external_token' => $transactionInfo->getOriginalTransactionId()])
            ->fetchAll();

        $first = true;
        foreach ($activeOriginalTransactionRecurrents as $rp) {
            // TODO: nechat toto na upgrade notifikaciu?
            if ($first && $payment->subscription_end_at > $rp->parent_payment->subscription_end_at) {
                $this->recurrentPaymentsRepository->update($rp, [
                    'state' => 'charged',
                    'payment_id' => $payment->id,
                    'next_subscription_type_id' => $payment->subscription_type_id,
                ]);
                $first = false;
            } else {
                // this shouldn't happen, but still...
                $this->recurrentPaymentsRepository->stoppedBySystem($rp->id);
            }
        }

        $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $transactionInfo->getOriginalTransactionId(),
            $subscriptionEndAt
        );

        $response = new JsonApiResponse(IResponse::S200_OK, [
            'status' => 'ok',
            'code' => 'success',
            'message' => "Apple purchase verified.",
        ]);
        return $response;
    }

    /**
     * getUser returns User from Apple's ServerToServerNotification.
     *
     * - User is searched by original_transaction_id linked to previous payments (payment_meta).
     * - User is searched by original_transaction_id linked to user itself (user_meta).
     *
     * @return ActiveRow|null $user - null if no user was found.
     */
    private function getUserByOriginalTransactionId(string $originalTransactionId): ?ActiveRow
    {
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

        return null;
    }
}
