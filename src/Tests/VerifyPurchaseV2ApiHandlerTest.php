<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\Api\VerifyPurchaseV2ApiHandler;
use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\DataProviders\AccessTokenDataProvider;
use Crm\AppleAppstoreModule\Models\AppStoreServerApiFactory;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreTransactionDeviceTokensRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder as AppleAppstorePaymentGatewaysSeeder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\ConfigsSeeder;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\Extension\ExtendSameContentAccess;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Models\Auth\UserTokenAuthorization;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Http\IResponse;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use PHPUnit\Framework\MockObject\Stub;
use Readdle\AppStoreServerAPI\AppStoreServerAPIInterface;
use Readdle\AppStoreServerAPI\Exception\AppStoreServerAPIException;
use Readdle\AppStoreServerAPI\Exception\HTTPRequestFailed;
use Readdle\AppStoreServerAPI\Request\GetTransactionHistoryRequest;
use Readdle\AppStoreServerAPI\Response\HistoryResponse;
use Readdle\AppStoreServerAPI\Util\JWT;
use Tomaj\NetteApi\Response\JsonApiResponse;

class VerifyPurchaseV2ApiHandlerTest extends DatabaseTestCase
{
    private const SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_verify_purchase_v2_subscription_type';
    private const APPLE_PRODUCT_ID = 'apple_appstore_test_product_id';
    private const APPLE_ORIGINAL_TRANSACTION_ID = '100000000000001';
    private const APPLE_TRANSACTION_ID = '100000000000001';
    private const APPLE_RENEWAL_TRANSACTION_ID = '100000000000002';

    // random working private key; signature is not verified because of JWT::unsafeMode()
    private const JWT_PRIVATE_KEY = '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIA5BAzgt5R85DaArLcVRm6sOFxMX5RcdfK1mUH2eSzKhoAoGCCqGSM49
AwEHoUQDQgAEo3xPcQUWbzPuCmRD/ihDbW3kvGgGna5R2gpgCOdF/k+Isyt4xvZL
RqFC5UYnWawkbdl1bq+JxCdP7F0HFUqnAA==
-----END EC PRIVATE KEY-----';

    private VerifyPurchaseV2ApiHandler $handler;
    private PaymentsRepository $paymentsRepository;
    private PaymentMetaRepository $paymentMetaRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private UsersRepository $usersRepository;
    private UserMetaRepository $userMetaRepository;
    private AccessTokensRepository $accessTokensRepository;
    private DeviceTokensRepository $deviceTokensRepository;
    private AppleAppstoreOriginalTransactionsRepository $appleAppstoreOriginalTransactionsRepository;
    private AppleAppstoreTransactionDeviceTokensRepository $appleAppstoreTransactionDeviceTokensRepository;
    private UnclaimedUser $unclaimedUser;
    private ActiveRow $subscriptionType;
    private array $appleTransactions = [];
    private ?AppStoreServerAPIException $appleApiException = null;
    private int $appleApiCallsCount = 0;

    protected function requiredRepositories(): array
    {
        return [
            AppleAppstoreSubscriptionTypesRepository::class,
            AppleAppstoreOriginalTransactionsRepository::class,
            AppleAppstoreTransactionDeviceTokensRepository::class,

            SubscriptionTypesRepository::class,
            SubscriptionTypeBuilder::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypeNamesRepository::class,
            SubscriptionsRepository::class,

            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,
            PaymentItemsRepository::class,
            PaymentItemMetaRepository::class,
            RecurrentPaymentsRepository::class,

            UsersRepository::class,
            UserMetaRepository::class,
            AccessTokensRepository::class,
            DeviceTokensRepository::class,

            ConfigsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            ApplicationConfigsSeeder::class,
            ConfigsSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            AppleAppstorePaymentGatewaysSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        // fresh container, so the stubbed App Store Server API is injected into a newly created handler
        $this->refreshContainer();
        $this->replaceAppStoreServerApiFactory();

        parent::setUp();

        $this->inject(ApplicationConfig::class)->setCacheExpiration(0);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userMetaRepository = $this->getRepository(UserMetaRepository::class);
        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->deviceTokensRepository = $this->getRepository(DeviceTokensRepository::class);
        $this->appleAppstoreOriginalTransactionsRepository = $this->getRepository(AppleAppstoreOriginalTransactionsRepository::class);
        $this->appleAppstoreTransactionDeviceTokensRepository = $this->getRepository(AppleAppstoreTransactionDeviceTokensRepository::class);
        $this->unclaimedUser = $this->inject(UnclaimedUser::class);

        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        // clear initialized handlers (we do not want duplicated listeners)
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class),
        );

        $this->subscriptionType = $this->inject(SubscriptionTypeBuilder::class)->createNew()
            ->setName('apple appstore test subscription month')
            ->setUserLabel('apple appstore test subscription month')
            ->setPrice(6.99)
            ->setCode(self::SUBSCRIPTION_TYPE_CODE)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class)
            ->upsert(self::APPLE_PRODUCT_ID, $this->subscriptionType);

        $this->handler = $this->inject(VerifyPurchaseV2ApiHandler::class);

        /**
         * Module data providers are not registered in tests; this one keeps app (`ios-app`) access tokens paired
         * with the device token when other user logs in on the same device.
         */
        $this->inject(DataProviderManager::class)->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->inject(AccessTokenDataProvider::class),
        );

        JWT::unsafeMode();
    }

    protected function tearDown(): void
    {
        $this->inject(Emitter::class)->removeAllListeners(PaymentChangeStatusEvent::class);
        JWT::unsafeMode(false);
        unset($_SERVER['HTTP_AUTHORIZATION']);

        parent::tearDown();

        // do not leak container with stubbed services into other tests
        $this->refreshContainer();
    }

    private function replaceAppStoreServerApiFactory(): void
    {
        /** @var AppStoreServerAPIInterface&Stub $appStoreServerApi */
        $appStoreServerApi = $this->createStub(AppStoreServerAPIInterface::class);
        $appStoreServerApi->method('getTransactionHistory')
            ->willReturnCallback(function () {
                $this->appleApiCallsCount++;
                if ($this->appleApiException) {
                    throw $this->appleApiException;
                }
                return $this->historyResponse(...array_reverse($this->appleTransactions));
            });

        $appStoreServerApiFactory = $this->createStub(AppStoreServerApiFactory::class);
        $appStoreServerApiFactory->method('create')->willReturn($appStoreServerApi);

        $serviceName = $this->container->findByType(AppStoreServerApiFactory::class)[0];
        $this->container->removeService($serviceName);
        $this->container->addService($serviceName, $appStoreServerApiFactory);
    }

    public function testEmptyPayload(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $response = $this->callHandler($deviceToken->token, null);

        $this->assertEquals(IResponse::S400_BadRequest, $response->getCode());
        $this->assertEquals(0, $this->appleApiCallsCount);
    }

    public function testInvalidPayload(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $response = $this->callHandler($deviceToken->token, ['article_id' => 'missing-transaction-id']);

        $this->assertEquals(IResponse::S400_BadRequest, $response->getCode());
        $this->assertEquals(0, $this->appleApiCallsCount);
    }

    public function testAppStoreServerApiError(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $this->appleApiException = new HTTPRequestFailed('GET', 'https://api.storekit.itunes.apple.com', 'timeout');

        $response = $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S500_InternalServerError, $response->getCode());
        $this->assertEquals('unable_to_validate', $response->getPayload()['error']);
        $this->assertEquals(0, $this->paymentsRepository->getTable()->count('*'));
    }

    public function testUnknownProduct(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $this->addAppleTransactions($this->transactionInfo(productId: 'unknown_product_id'));

        $response = $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S500_InternalServerError, $response->getCode());
        $this->assertEquals('missing_subscription_type', $response->getPayload()['error']);
        $this->assertEquals(0, $this->paymentsRepository->getTable()->count('*'));
    }

    public function testNewPurchaseWithDeviceTokenOnlyCreatesUnclaimedUser(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $transactionInfo = $this->transactionInfo();
        $this->addAppleTransactions($transactionInfo);

        $response = $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $this->assertEquals('Apple purchase verified.', $response->getPayload()['message']);

        $payment = $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID);
        $user = $payment->user;

        // new unclaimed user linked to original transaction
        $this->assertTrue($this->unclaimedUser->isUnclaimedUser($user));
        $this->assertEquals(
            self::APPLE_ORIGINAL_TRANSACTION_ID,
            $this->userMetaRepository->userMetaValueByKey($user, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID),
        );

        // unclaimed user got an access token paired with the device token
        $accessToken = $this->accessTokensRepository
            ->allUserTokensBySource($user->id, AppleAppstoreModule::USER_SOURCE_APP)
            ->fetch();
        $this->assertNotNull($accessToken);
        $this->assertEquals($deviceToken->id, $accessToken->device_token_id);

        // original transaction is paired with the device token
        $originalTransaction = $this->appleAppstoreOriginalTransactionsRepository
            ->findByOriginalTransactionId(self::APPLE_ORIGINAL_TRANSACTION_ID);
        $this->assertNotNull($originalTransaction);
        $this->assertEquals(1, $this->appleAppstoreTransactionDeviceTokensRepository->getTable()->where([
            'original_transaction_id' => $originalTransaction->id,
            'device_token_id' => $deviceToken->id,
        ])->count('*'));

        $this->assertPayment($payment, $transactionInfo);
        $this->assertActiveRecurrentPayment($payment);
    }

    public function testNewPurchaseWithLoggedInUser(): void
    {
        $user = $this->usersRepository->add('apple.appstore+verify@example.com', 'password');
        $deviceToken = $this->deviceTokensRepository->generate('test_device');

        $accessToken = $this->loginOnDevice($user, $deviceToken);

        $transactionInfo = $this->transactionInfo();
        $this->addAppleTransactions($transactionInfo);

        $response = $this->callHandler($accessToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());

        $payment = $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID);
        $this->assertEquals($user->id, $payment->user_id);
        $this->assertFalse($this->unclaimedUser->isUnclaimedUser($payment->user));

        $this->assertPayment($payment, $transactionInfo);
        $this->assertActiveRecurrentPayment($payment);
    }

    public function testAlreadyProcessedTransaction(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $this->addAppleTransactions($this->transactionInfo());

        $payload = ['transaction_id' => self::APPLE_TRANSACTION_ID];
        $this->callHandler($deviceToken->token, $payload);
        $response = $this->callHandler($deviceToken->token, $payload);

        // second call is answered from the DB, without asking Apple again
        $this->assertEquals(1, $this->appleApiCallsCount);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $this->assertEquals(
            'Apple purchase verified (transaction was already processed).',
            $response->getPayload()['message'],
        );
        $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID);
        $this->assertEquals(1, $this->recurrentPaymentsRepository->getTable()->count('*'));
    }

    public function testAlreadyProcessedTransactionPairsNewDevice(): void
    {
        $firstDeviceToken = $this->deviceTokensRepository->generate('first_device');
        $this->addAppleTransactions($this->transactionInfo());

        $this->callHandler($firstDeviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);
        $user = $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID)->user;

        // e.g. "restore purchases" on another device
        $secondDeviceToken = $this->deviceTokensRepository->generate('second_device');
        $response = $this->callHandler($secondDeviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $pairedDeviceTokenIds = $this->accessTokensRepository
            ->allUserTokensBySource($user->id, AppleAppstoreModule::USER_SOURCE_APP)
            ->fetchPairs(null, 'device_token_id');
        $this->assertEqualsCanonicalizing([$firstDeviceToken->id, $secondDeviceToken->id], $pairedDeviceTokenIds);
    }

    public function testPurchaseOwnedByOtherUserWithoutDeviceToken(): void
    {
        $owner = $this->usersRepository->add('apple.appstore+owner@example.com', 'password');
        $ownerAccessToken = $this->accessTokensRepository->add($owner, 3);

        $otherUser = $this->usersRepository->add('apple.appstore+other@example.com', 'password');
        $otherAccessToken = $this->accessTokensRepository->add($otherUser, 3);

        $this->addAppleTransactions($this->transactionInfo(purchaseDate: new DateTime('-31 days')));
        $this->callHandler($ownerAccessToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        // edge-case: renewal payments are handled by notifications
        $this->addAppleTransactions($this->transactionInfo(
            transactionId: self::APPLE_RENEWAL_TRANSACTION_ID,
            purchaseDate: new DateTime(),
        ));

        $response = $this->callHandler($otherAccessToken->token, ['transaction_id' => self::APPLE_RENEWAL_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S400_BadRequest, $response->getCode());
        $this->assertEquals('purchase_already_owned', $response->getPayload()['error']);
        $this->assertEquals(0, $this->paymentMetaRepository->getTable()->where([
            'key' => AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            'value' => self::APPLE_RENEWAL_TRANSACTION_ID,
        ])->count('*'));
    }

    public function testLoggedInUserClaimsUnclaimedOwnerOfPurchase(): void
    {
        // purchase made anonymously -> unclaimed user
        $deviceToken = $this->deviceTokensRepository->generate('test_device');
        $this->addAppleTransactions($this->transactionInfo(purchaseDate: new DateTime('-31 days')));

        $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $unclaimedOwner = $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID)->user;
        $this->assertTrue($this->unclaimedUser->isUnclaimedUser($unclaimedOwner));

        // user logs in on the same device
        $loggedUser = $this->usersRepository->add('apple.appstore+logged@example.com', 'password');
        $loggedAccessToken = $this->loginOnDevice($loggedUser, $deviceToken);

        // edge-case: renewal payments are handled by notifications
        $this->addAppleTransactions($this->transactionInfo(
            transactionId: self::APPLE_RENEWAL_TRANSACTION_ID,
            purchaseDate: new DateTime(),
        ));
        $response = $this->callHandler($loggedAccessToken->token, ['transaction_id' => self::APPLE_RENEWAL_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $this->assertEquals(
            $loggedUser->id,
            $this->userMetaRepository->userMetaValueByKey($unclaimedOwner, UnclaimedUser::CLAIMED_BY_KEY),
        );
        $renewalPayment = $this->findSinglePaymentByTransactionId(self::APPLE_RENEWAL_TRANSACTION_ID);
        $this->assertEquals($loggedUser->id, $renewalPayment->user_id);
    }

    public function testAlreadyProcessedTransactionKeepsOtherUserPairedWithDevice(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('shared_device');

        // user A buys subscription on the device
        $owner = $this->usersRepository->add('apple.appstore+owner@example.com', 'password');
        $this->loginOnDevice($owner, $deviceToken);
        $this->addAppleTransactions($this->transactionInfo());
        $this->callHandler($this->loginOnDevice($owner, $deviceToken)->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);
        $this->assertEquals($owner->id, $this->findSinglePaymentByTransactionId(self::APPLE_TRANSACTION_ID)->user_id);

        // user B logs in on the same device
        $otherUser = $this->usersRepository->add('apple.appstore+other@example.com', 'password');
        $otherUserAccessToken = $this->loginOnDevice($otherUser, $deviceToken);

        // app verifies (restores) the purchase authorized by the device token
        $response = $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $this->assertDevicePairedWithUserTokens($deviceToken, $owner, $otherUser);
        $this->assertEquals(
            $deviceToken->id,
            $this->accessTokensRepository->loadToken($otherUserAccessToken->token)->device_token_id,
        );
    }

    public function testRenewalKeepsOtherUserPairedWithDevice(): void
    {
        $deviceToken = $this->deviceTokensRepository->generate('shared_device');

        // user A buys subscription on the device
        $owner = $this->usersRepository->add('apple.appstore+owner@example.com', 'password');
        $this->addAppleTransactions($this->transactionInfo(purchaseDate: new DateTime('-31 days')));
        $this->callHandler($this->loginOnDevice($owner, $deviceToken)->token, ['transaction_id' => self::APPLE_TRANSACTION_ID]);

        // user B logs in on the same device
        $otherUser = $this->usersRepository->add('apple.appstore+other@example.com', 'password');
        $otherUserAccessToken = $this->loginOnDevice($otherUser, $deviceToken);

        // renewal is verified with the device token; device is shared by two claimed users, owner is found
        // by original transaction ID
        // edge-case: renewal payments are handled by notifications
        $this->addAppleTransactions($this->transactionInfo(
            transactionId: self::APPLE_RENEWAL_TRANSACTION_ID,
            purchaseDate: new DateTime(),
        ));
        $response = $this->callHandler($deviceToken->token, ['transaction_id' => self::APPLE_RENEWAL_TRANSACTION_ID]);

        $this->assertEquals(IResponse::S200_OK, $response->getCode());
        $this->assertEquals($owner->id, $this->findSinglePaymentByTransactionId(self::APPLE_RENEWAL_TRANSACTION_ID)->user_id);
        $this->assertDevicePairedWithUserTokens($deviceToken, $owner, $otherUser);
        $this->assertEquals(
            $deviceToken->id,
            $this->accessTokensRepository->loadToken($otherUserAccessToken->token)->device_token_id,
        );
    }

    /**
     * Simulates login in the app: new access token is paired with the device token (with the standard unpairing
     * of other claimed users, as login API handlers do).
     */
    private function loginOnDevice(ActiveRow $user, ActiveRow $deviceToken): ActiveRow
    {
        $accessToken = $this->accessTokensRepository->add($user, 3);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);
        return $this->accessTokensRepository->loadToken($accessToken->token);
    }

    private function assertDevicePairedWithUserTokens(ActiveRow $deviceToken, ActiveRow ...$users): void
    {
        $pairedUserIds = array_unique(array_values(
            $this->accessTokensRepository->allByDeviceToken($deviceToken)->fetchPairs('id', 'user_id'),
        ));
        $this->assertEqualsCanonicalizing(
            array_map(fn (ActiveRow $user) => $user->id, $users),
            $pairedUserIds,
        );
    }

    private function callHandler(string $bearerToken, ?array $payload): JsonApiResponse
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearerToken;

        /** @var UserTokenAuthorization $authorization */
        $authorization = $this->inject(UserTokenAuthorization::class);
        $this->assertTrue($authorization->authorized());
        $this->handler->setAuthorization($authorization);

        if ($payload !== null) {
            $this->handler->setRawPayload(Json::encode($payload));
        }

        $response = $this->handler->handle([]);
        $this->assertInstanceOf(JsonApiResponse::class, $response);

        return $response;
    }

    /**
     * Adds transactions to the subscription history returned by the mocked App Store Server API. Apple returns
     * the whole history of the original transaction regardless of the requested transaction ID, sorted
     * descending by the handler's request, so the latest added transaction is the one the handler processes.
     */
    private function addAppleTransactions(array ...$transactions): void
    {
        array_push($this->appleTransactions, ...$transactions);
    }

    private function historyResponse(array ...$transactions): HistoryResponse
    {
        $signedTransactions = array_map(
            fn (array $transaction) => \Firebase\JWT\JWT::encode($transaction, self::JWT_PRIVATE_KEY, 'ES256'),
            $transactions,
        );

        return HistoryResponse::createFromString(
            Json::encode([
                'bundleId' => 'sk.npress.dennikn.dennikn',
                'environment' => 'Sandbox',
                'hasMore' => false,
                'revision' => 'test_revision',
                'signedTransactions' => $signedTransactions,
            ]),
            $this->createStub(GetTransactionHistoryRequest::class),
        );
    }

    private function transactionInfo(
        string $transactionId = self::APPLE_TRANSACTION_ID,
        string $productId = self::APPLE_PRODUCT_ID,
        ?DateTime $purchaseDate = null,
    ): array {
        $purchaseDate ??= new DateTime();
        $originalPurchaseDate = $transactionId === self::APPLE_TRANSACTION_ID
            ? $purchaseDate
            : $purchaseDate->modifyClone('-31 days');

        return [
            'bundleId' => 'sk.npress.dennikn.dennikn',
            'environment' => 'Sandbox',
            'expiresDate' => $purchaseDate->modifyClone('+31 days')->format('Uv'),
            'inAppOwnershipType' => 'PURCHASED',
            'originalPurchaseDate' => $originalPurchaseDate->format('Uv'),
            'originalTransactionId' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            'productId' => $productId,
            'purchaseDate' => $purchaseDate->format('Uv'),
            'quantity' => 1,
            'signedDate' => $purchaseDate->format('Uv'),
            'transactionId' => $transactionId,
            'transactionReason' => $transactionId === self::APPLE_TRANSACTION_ID ? 'PURCHASE' : 'RENEWAL',
            'type' => 'Auto-Renewable Subscription',
        ];
    }

    private function findSinglePaymentByTransactionId(string $transactionId): ActiveRow
    {
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one payment should be linked to transaction [{$transactionId}].");
        return reset($paymentMetas)->payment;
    }

    private function assertPayment(ActiveRow $payment, array $transactionInfo): void
    {
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $payment->status);
        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals($this->timestampToDateTime($transactionInfo['purchaseDate']), $payment->subscription_start_at);
        $this->assertEquals($this->timestampToDateTime($transactionInfo['expiresDate']), $payment->subscription_end_at);
        $this->assertEquals($this->timestampToDateTime($transactionInfo['purchaseDate']), $payment->paid_at);

        $this->assertEquals(
            $transactionInfo['originalTransactionId'],
            $this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID)->value,
        );
        $this->assertEquals(
            $transactionInfo['productId'],
            $this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID)->value,
        );
    }

    private function assertActiveRecurrentPayment(ActiveRow $payment): void
    {
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertNotNull($recurrentPayment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentPayment->state);
        $this->assertEquals(self::APPLE_ORIGINAL_TRANSACTION_ID, $recurrentPayment->payment_method->external_token);
    }

    private function timestampToDateTime(string $timestampWithMilliseconds): DateTime
    {
        $dateTime = DateTime::createFromFormat('U', (string) intdiv((int) $timestampWithMilliseconds, 1000));
        $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $dateTime;
    }
}
