<?php

namespace Crm\AppleAppstoreModule\Api;

use Crm\ApiModule\Models\Api\ApiHandler;
use Crm\ApiModule\Models\Api\JsonValidationTrait;
use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Models\AppleAppstoreValidatorFactory;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreTransactionDeviceTokensRepository;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\Redis\RedisClientFactory;
use Crm\ApplicationModule\Models\Redis\RedisClientTrait;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
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
use GuzzleHttp\Exception\GuzzleException;
use Malkusch\Lock\Mutex\RedisMutex;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Utils\Random;
use ReceiptValidator\iTunes\PurchaseItem;
use ReceiptValidator\iTunes\ResponseInterface;
use Tomaj\NetteApi\Response\JsonApiResponse;
use Tracy\Debugger;

class VerifyPurchaseApiHandler extends ApiHandler
{
    use JsonValidationTrait;
    use RedisClientTrait;

    private $accessTokensRepository;
    private $appleAppstoreValidatorFactory;
    private $appleAppstoreSubscriptionTypesRepository;
    private $appleAppstoreOriginalTransactionsRepository;
    private $applicationConfig;
    private $paymentGatewaysRepository;
    private $paymentMetaRepository;
    private $paymentsRepository;
    private $recurrentPaymentsRepository;
    private $unclaimedUser;
    private $userMetaRepository;
    private $deviceTokensRepository;
    private $appleAppstoreTransactionDeviceTokensRepository;

    public function __construct(
        AccessTokensRepository $accessTokensRepository,
        AppleAppstoreValidatorFactory $appleAppstoreValidatorFactory,
        AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypesRepository,
        AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository,
        ApplicationConfig $applicationConfig,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PaymentsRepository $paymentsRepository,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        UnclaimedUser $unclaimedUser,
        UserMetaRepository $userMetaRepository,
        DeviceTokensRepository $deviceTokensRepository,
        AppleAppstoreTransactionDeviceTokensRepository $appleAppstoreTransactionDeviceTokensRepository,
        RedisClientFactory $redisClientFactory,
    ) {
        $this->accessTokensRepository = $accessTokensRepository;
        $this->appleAppstoreValidatorFactory = $appleAppstoreValidatorFactory;
        $this->appleAppstoreSubscriptionTypesRepository = $appleAppstoreSubscriptionTypesRepository;
        $this->appleAppstoreOriginalTransactionsRepository = $appleAppstoreOriginalTransactionsRepository;
        $this->applicationConfig = $applicationConfig;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->paymentsRepository = $paymentsRepository;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->unclaimedUser = $unclaimedUser;
        $this->userMetaRepository = $userMetaRepository;
        $this->deviceTokensRepository = $deviceTokensRepository;
        $this->appleAppstoreTransactionDeviceTokensRepository = $appleAppstoreTransactionDeviceTokensRepository;
        $this->redisClientFactory = $redisClientFactory;
    }

    public function params(): array
    {
        return [];
    }

    public function handle(array $params): \Tomaj\NetteApi\Response\ResponseInterface
    {
        $authorization = $this->getAuthorization();
        if (!($authorization instanceof UserTokenAuthorization)) {
            throw new \Exception("Wrong authorization service used. Should be 'UserTokenAuthorization'");
        }

        // validate input
        $validator = $this->validateInput(__DIR__ . '/verify-purchase.schema.json');
        if ($validator->hasErrorResponse()) {
            return $validator->getErrorResponse();
        }
        $payload = $validator->getParsedObject();

        // verify receipt in Apple system
        $receiptOrResponse = $this->verifyAppleAppStoreReceipt($authorization, $payload);
        if ($receiptOrResponse instanceof JsonApiResponse) {
            return $receiptOrResponse;
        }
        /** @var PurchaseItem $latestReceipt */
        $latestReceipt = $receiptOrResponse;

        // Mutex to avoid app and S2S notification procession collision (and therefore e.g. multiple payments to be created)
        $mutex = new RedisMutex(
            $this->redis(),
            'process_apple_transaction_id_' . $latestReceipt->getTransactionId(),
            20,
        );

        return $mutex->synchronized(function () use ($latestReceipt, $payload, $authorization) {
            $userOrResponse = $this->getUser($authorization, $latestReceipt, $payload->locale ?? null);
            if ($userOrResponse instanceof JsonApiResponse) {
                return $userOrResponse;
            }
            /** @var ActiveRow $user */
            $user = $userOrResponse;

            return $this->createPayment($user, $latestReceipt, $payload->articleId ?? null);
        });
    }

    /**
     * @return JsonApiResponse|PurchaseItem - Return validated receipt (PurchaseItem) or JsonApiResponse which should be returned by API.
     */
    private function verifyAppleAppStoreReceipt(UserTokenAuthorization $authorization, $payload)
    {
        // TODO: validate multiple receipts (purchase restore)
        $receipt = reset($payload->receipts);
        $gatewayMode = $payload->gateway_mode ?? null;

        try {
            $appleAppStoreValidator = $this->appleAppstoreValidatorFactory->create($gatewayMode);
            $appleResponse = $appleAppStoreValidator
                ->setReceiptData($receipt)
                ->setExcludeOldTransactions(true)
                ->validate();
        } catch (\Exception | GuzzleException $e) {
            Debugger::log("Unable to validate Apple AppStore payment. Error: [{$e->getMessage()}]", Debugger::ERROR);
            $response = new JsonApiResponse(Response::S503_SERVICE_UNAVAILABLE, [
                'status' => 'error',
                'error' => 'unable_to_validate',
                'message' => 'Unable to validate Apple AppStore payment.',
            ]);
            return $response;
        }

        if (!$appleResponse->isValid()) {
            Debugger::log("Apple appstore receipt is not valid: " . $receipt, Debugger::WARNING);
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                'status' => 'error',
                'error' => 'receipt_not_valid',
                'message' => 'Receipt of iOS in-app purchase is not valid.',
            ]);
            return $response;
        }

        $latestReceipt = $appleResponse->getLatestReceiptInfo();

        // Even when "exclude_old_transactions" is set to true, Apple can return multiple receipts. Based on the docs,
        // it can "include only the latest renewal transaction for any subscriptions". That could be the latest
        // transaction for current subscription and latest transaction for previous subscription.
        //
        // https://developer.apple.com/documentation/appstorereceipts/requestbody
        //
        // We count on the order of receipts, the latest first. Fingers crossed for us.
        $latestReceipt = reset($latestReceipt);

        if ($latestReceipt) {
            $this->appleAppstoreOriginalTransactionsRepository->add(
                $latestReceipt->getOriginalTransactionId(),
                $appleResponse->getLatestReceipt(),
            );
        } else {
            $this->appleAppstoreOriginalTransactionsRepository->add(
                $appleResponse->getReceipt()['original_transaction_id'],
                $receipt,
            );
        }

        // expired subscription is considered valid, but doesn't return latestReceiptInfo anymore
        if ($appleResponse->getResultCode() === ResponseInterface::RESULT_RECEIPT_VALID_BUT_SUB_EXPIRED
            || $latestReceipt->getExpiresDate() < new \DateTime()) {
            $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                'status' => 'error',
                'error' => 'transaction_expired',
                'message' => "Apple purchase verified successfully, but ignored. Transaction already expired.",
            ]);
            return $response;
        }

        // check if we processed this apple transaction ID to avoid duplicates
        $payment = null;
        $transaction = $this->paymentMetaRepository->findByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $latestReceipt->getTransactionId(),
        );

        if ($transaction) {
            $payment = $transaction->payment;
        } else {
            // There were occurrences where the original payment existed, but without payment meta. Not finding it
            // caused payment duplication. This is a fallback to identify this payment and to add missing metadata
            // to the payment_meta table.
            $matchingPayments = $this->paymentsRepository->getTable()
                ->where([
                    'subscription_end_at' => $latestReceipt->getExpiresDate(),
                    'payment_gateway.code' => AppleAppstoreGateway::GATEWAY_CODE,
                    ':payment_meta.key IS NULL',
                ])
                ->fetchAll();

            // If it's zero, there's nothing to process. If there's more than 1 payment, it would be risky to decide
            // which one is the correct one.
            if (count($matchingPayments) === 1) {
                $payment = reset($matchingPayments);

                $this->paymentMetaRepository->add(
                    $payment,
                    AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                    $latestReceipt->getOriginalTransactionId(),
                );
                $this->paymentMetaRepository->add(
                    $payment,
                    AppleAppstoreModule::META_KEY_PRODUCT_ID,
                    $latestReceipt->getProductId(),
                );
                $this->paymentMetaRepository->add(
                    $payment,
                    AppleAppstoreModule::META_KEY_TRANSACTION_ID,
                    $latestReceipt->getTransactionId(),
                );
            }
        }

        // this very transaction was already processed (matched via TRANSACTION_ID) and created internally
        if ($payment) {
            $this->pairUserWithAuthorizedToken(
                $authorization,
                $payment->user,
                $latestReceipt->getOriginalTransactionId(),
            );
            $response = new JsonApiResponse(Response::S200_OK, [
                'status' => 'ok',
                'code' => 'success',
                'message' => "Apple purchase verified (transaction was already processed).",
            ]);
            return $response;
        }

        return $latestReceipt;
    }

    private function createPayment(
        ActiveRow $user,
        PurchaseItem $latestReceipt,
        ?string $articleID,
    ): JsonApiResponse {
        $subscriptionType = $this->appleAppstoreSubscriptionTypesRepository
            ->findSubscriptionTypeByAppleAppstoreProductId($latestReceipt->getProductId(), !$latestReceipt->isTrialPeriod());
        if (!$subscriptionType) {
            Debugger::log(
                "Unable to find SubscriptionType by product ID [{$latestReceipt->getProductId()}] from transaction [{$latestReceipt->getOriginalTransactionId()}].",
                Debugger::ERROR,
            );
            $response = new JsonApiResponse(Response::S500_INTERNAL_SERVER_ERROR, [
                'status' => 'error',
                'error' => 'missing_subscription_type',
                'message' => 'Unable to find SubscriptionType by product ID from validated receipt.',
            ]);
            return $response;
        }

        if (!$latestReceipt->getExpiresDate()) {
            Debugger::log(
                "Unable to load expires_date from transaction [{$latestReceipt->getOriginalTransactionId()}].",
                Debugger::ERROR,
            );
            $response = new JsonApiResponse(Response::S503_SERVICE_UNAVAILABLE, [
                'status' => 'error',
                'error' => 'receipt_without_expires_date',
                'message' => 'Unable to load expires_date from validated receipt.',
            ]);
            return $response;
        }

        $lastPayment = $this->paymentsRepository->userPayments($user->id)
            ->where([
                'status' => PaymentStatusEnum::Prepaid->value,
                'payments.subscription_type_id' => $subscriptionType->id,
                ':payment_meta.key' => AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                ':payment_meta.value' => $latestReceipt->getOriginalTransactionId(),
            ])
            ->order('subscription.end_time DESC')
            ->limit(1)
            ->fetch();

        // if we don't have payment or last receipt has expire in future, we need to create payment
        if (!$lastPayment || $latestReceipt->getExpiresDate()->greaterThan($lastPayment->subscription->end_time)) {
            $metas = [
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $latestReceipt->getOriginalTransactionId(),
                AppleAppstoreModule::META_KEY_PRODUCT_ID => $latestReceipt->getProductId(),
                AppleAppstoreModule::META_KEY_TRANSACTION_ID => $latestReceipt->getTransactionId(),
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
                    Debugger::ERROR,
                );
                $response = new JsonApiResponse(Response::S500_INTERNAL_SERVER_ERROR, [
                    'status' => 'error',
                    'error' => 'internal_server_error',
                    'message' => "Unable to find PaymentGateway with code [{$paymentGatewayCode}].",
                ]);
                return $response;
            }

            $subscriptionStartAt = $latestReceipt->getPurchaseDate()
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            $subscriptionEndAt = $latestReceipt->getExpiresDate()
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            $payment = $this->paymentsRepository->add(
                $subscriptionType,
                $paymentGateway,
                $user,
                $paymentItemContainer,
                '',
                $subscriptionType->price,
                $subscriptionStartAt,
                $subscriptionEndAt,
                null,
                0,
                null,
                null,
                null,
                false,
                $metas,
            );

            $payment = $this->paymentsRepository->updateStatus(
                payment: $payment,
                status: PaymentStatusEnum::Prepaid->value,
                sendEmail: true,
            );

            // handle recurrent payment
            // - original_transaction_id will be used as recurrent token
            // - stop any previous recurrent payments with the same original transaction id

            $activeOriginalTransactionRecurrents = $this->recurrentPaymentsRepository
                ->getUserActiveRecurrentPayments($payment->user_id)
                ->where(['payment_method.external_token' => $latestReceipt->getOriginalTransactionId()])
                ->fetchAll();
            foreach ($activeOriginalTransactionRecurrents as $rp) {
                $this->recurrentPaymentsRepository->stoppedBySystem($rp->id);
            }

            $this->recurrentPaymentsRepository->createFromPayment(
                $payment,
                $latestReceipt->getOriginalTransactionId(),
                $subscriptionEndAt,
            );
        }

        $response = new JsonApiResponse(Response::S200_OK, [
            'status' => 'ok',
            'code' => 'success',
            'message' => "Apple purchase verified.",
        ]);
        return $response;
    }

    /**
     * @return ActiveRow|JsonApiResponse - Return $user (ActiveRow) or JsonApiResponse which should be returned by API.
     */
    private function getUser(UserTokenAuthorization $authorization, PurchaseItem $latestReceipt, string $locale = null)
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

        $userFromOriginalTransaction = $this->getUserFromReceipt($latestReceipt);
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
                        $response = new JsonApiResponse(Response::S400_BAD_REQUEST, [
                            'status' => 'error',
                            'error' => 'purchase_already_owned',
                            'message' => "Unable to verify purchase for user [$userFromToken->public_name]. This or previous purchase already owned by other user.",
                        ]);
                        return $response;
                    }
                } else {
                    $user = $userFromToken;
                }
            }
        }

        // create unclaimed user if none was provided by authorization
        if ($user === null) {
            $user = $this->unclaimedUser->createUnclaimedUser(
                "apple_appstore_" . $latestReceipt->getOriginalTransactionId() . "_" . Random::generate(),
                AppleAppstoreModule::USER_SOURCE_APP,
                $locale,
            );
            $this->userMetaRepository->add(
                $user,
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                $latestReceipt->getOriginalTransactionId(),
            );
        }

        $this->pairUserWithAuthorizedToken(
            $authorization,
            $user,
            $latestReceipt->getOriginalTransactionId(),
        );

        return $user;
    }

    /**
     * getUser returns User from Apple's ServerToServerNotification.
     *
     * - User is searched by original_transaction_id linked to previous payments (payment_meta).
     * - User is searched by original_transaction_id linked to user itself (user_meta).
     *
     * @todo merge this with \Crm\AppleAppstoreModule\Model\ServerToServerNotificationProcessor::getUser()
     *
     * @return ActiveRow|null $user - null if no user was found.
     */
    private function getUserFromReceipt(PurchaseItem $latestReceiptInfo): ?ActiveRow
    {
        $originalTransactionId = $latestReceiptInfo->getOriginalTransactionId();

        // search user by `original_transaction_id` linked to payment
        $paymentsWithMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId,
        );
        if (!empty($paymentsWithMeta)) {
            return reset($paymentsWithMeta)->payment->user;
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
            return reset($usersMetas)->user;
        }

        return null;
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
                $deviceToken,
            );
        } else {
            // TODO: shouldn't we throw an exception here? or return special error to the app?
            Debugger::log("No device token found. Unable to pair new unclaimed user [{$user->id}].", Debugger::ERROR);
        }
    }
}
