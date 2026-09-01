<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationV2WebhookHandler;
use Crm\AppleAppstoreModule\Models\Offer\OfferDiscountTypeEnum;
use Crm\AppleAppstoreModule\Models\Offer\OfferTypeEnum;
use Crm\AppleAppstoreModule\Models\SubscriptionTypeResolver\TransactionProductOfferNotMatchedException;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder as AppleAppstorePaymentGatewaysSeeder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
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
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Readdle\AppStoreServerAPI\Util\JWT;
use Tomaj\Hermes\Message;

class ServerToServerNotificationV2WebhookHandlerOfferTest extends DatabaseTestCase
{
    private const REGULAR_SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_regular_subscription_type_code';
    private const INTRO_SUBSCRIPTION_TYPE_CODE = 'apple_appstore_test_intro_subscription_type_code';
    private const REGULAR_PRICE = 8.99;
    private const INTRO_PRICE = 0.99;
    private const APPLE_ORIGINAL_TRANSACTION_ID = 'test_original_transaction_id_value';
    private const APPLE_TRANSACTION_ID = '300002150129622';
    private const APPLE_PRODUCT_ID = 'apple_appstore_test_product_id';
    private const OFFER_PERIOD = 'P1M';
    private const APPLE_DOWNGRADE_PRODUCT_ID = 'apple_appstore_test_downgrade_product_id';

    private AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypeRepository;
    private AppleAppstoreServerToServerNotificationLogRepository $serverToServerNotificationLogRepository;
    private PaymentsRepository $paymentsRepository;
    private PaymentMetaRepository $paymentMetaRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;
    private SubscriptionTypeBuilder $subscriptionTypeBuilder;
    private ServerToServerNotificationV2WebhookHandler $serverToServerNotificationWebhookHandler;
    private ActiveRow $regularSubscriptionType;
    private ActiveRow $introSubscriptionType;

    protected function requiredRepositories(): array
    {
        return [
            AppleAppstoreSubscriptionTypesRepository::class,
            AppleAppstoreServerToServerNotificationLogRepository::class,

            SubscriptionTypesRepository::class,
            SubscriptionTypeBuilder::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypeNamesRepository::class, // must be present; otherwise subscription creation fails

            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,

            RecurrentPaymentsRepository::class,

            UsersRepository::class,
            UserMetaRepository::class,

            SubscriptionsRepository::class,

            // unused repositories; needed for proper DB cleanup in tearDown()
            PaymentItemMetaRepository::class,
            PaymentItemsRepository::class,

            ConfigsRepository::class,
        ];
    }

    public function requiredSeeders(): array
    {
        return [
            ApplicationConfigsSeeder::class,
            ConfigsSeeder::class,

            // extension and method seeders must be present; otherwise subscription_type creation fails
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            // must be present; otherwise subscription creation fails
            SubscriptionTypeNamesSeeder::class,

            AppleAppstorePaymentGatewaysSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $applicationConfig = $this->inject(ApplicationConfig::class);
        $applicationConfig->setCacheExpiration(0);

        $this->appleAppstoreSubscriptionTypeRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);
        $this->serverToServerNotificationLogRepository = $this->getRepository(AppleAppstoreServerToServerNotificationLogRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);

        $this->serverToServerNotificationWebhookHandler = $this->inject(ServerToServerNotificationV2WebhookHandler::class);

        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        // clear initialized handlers (we do not want duplicated listeners)
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class),
        );

        $this->loadSubscriptionTypes();
        JWT::unsafeMode();
    }

    protected function tearDown(): void
    {
        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        JWT::unsafeMode(false);

        parent::tearDown();
    }

    public function testInitialBuyWithIntroductoryOffer(): void
    {
        $this->handleNotification($this->prepareInitialBuyData(offer: $this->introductoryOffer()));

        $payment = $this->lastPaymentOfOriginalTransaction();
        $this->assertSame($this->introSubscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(self::INTRO_PRICE, $payment->amount);
        $this->assertSame($this->introSubscriptionType->id, $payment->subscription->subscription_type_id);
    }

    public function testInitialBuyWithoutOfferIsNotShadowedByOfferMapping(): void
    {
        $this->handleNotification($this->prepareInitialBuyData());

        $payment = $this->lastPaymentOfOriginalTransaction();
        $this->assertSame($this->regularSubscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(self::REGULAR_PRICE, $payment->amount);
    }

    /**
     * An unpaired offer has to fail loudly and stay retryable; Hermes keeps redelivering the notification,
     * so it gets processed once the offer is paired in the apple_appstore_subscription_types table.
     */
    public function testUnpairedOfferFailsAndStaysRetryable(): void
    {
        $notification = $this->prepareInitialBuyData(offer: [
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayUpFront->value,
            'offerPeriod' => 'P1Y',
        ]);

        $exception = null;
        try {
            $this->handleNotification($notification);
        } catch (TransactionProductOfferNotMatchedException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertCount(0, $this->paymentsRepository->getTable()->fetchAll());

        $logs = $this->serverToServerNotificationLogRepository->getTable()->fetchAll();
        $this->assertCount(1, $logs);
        $this->assertSame(
            AppleAppstoreServerToServerNotificationLogRepository::STATUS_NEW,
            reset($logs)->status,
        );
    }

    public function testRenewalWithinIntroductoryOffer(): void
    {
        $purchaseDate = new DateTime();
        $originalPurchaseDate = $purchaseDate->modifyClone('-30 days');

        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($this->prepareInitialBuyData(
            purchaseDate: $originalPurchaseDate,
            offer: $this->introductoryOffer(),
        ));
        $this->serverToServerNotificationWebhookHandler->setNow($purchaseDate);
        $this->recurrentPaymentsRepository->setNow($purchaseDate);

        $transactionId = '77897970';
        $this->handleNotification($this->prepareRenewalData(
            purchaseDate: $purchaseDate,
            originalPurchaseDate: $originalPurchaseDate,
            transactionId: $transactionId,
            offer: $this->introductoryOffer(),
        ));

        $renewalPayment = $this->paymentOfTransaction($transactionId);
        $this->assertSame($this->introSubscriptionType->id, $renewalPayment->subscription_type_id);
        $this->assertEquals(self::INTRO_PRICE, $renewalPayment->amount);

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(2, $recurrentPayments);
        $this->assertSame(RecurrentPaymentStateEnum::Charged->value, reset($recurrentPayments)->state);
        $this->assertSame(RecurrentPaymentStateEnum::Active->value, next($recurrentPayments)->state);
    }

    public function testRenewalAfterIntroductoryOfferUsesRegularSubscriptionType(): void
    {
        $purchaseDate = new DateTime();
        $originalPurchaseDate = $purchaseDate->modifyClone('-30 days');

        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($this->prepareInitialBuyData(
            purchaseDate: $originalPurchaseDate,
            offer: $this->introductoryOffer(),
        ));
        $this->serverToServerNotificationWebhookHandler->setNow($purchaseDate);
        $this->recurrentPaymentsRepository->setNow($purchaseDate);

        $transactionId = '77897970';
        $this->handleNotification($this->prepareRenewalData(
            purchaseDate: $purchaseDate,
            originalPurchaseDate: $originalPurchaseDate,
            transactionId: $transactionId,
        ));

        $renewalPayment = $this->paymentOfTransaction($transactionId);
        $this->assertSame($this->regularSubscriptionType->id, $renewalPayment->subscription_type_id);
        $this->assertEquals(self::REGULAR_PRICE, $renewalPayment->amount);
    }

    public function testDowngradeResolvesOfferSubscriptionTypeOfRenewalProduct(): void
    {
        $this->handleNotification($this->prepareInitialBuyData());

        $downgradeSubscriptionType = $this->createDowngradeSubscriptionType();
        $this->appleAppstoreSubscriptionTypeRepository->upsert(
            self::APPLE_DOWNGRADE_PRODUCT_ID,
            $downgradeSubscriptionType,
        );
        $this->appleAppstoreSubscriptionTypeRepository->upsert(
            appleAppstoreProductId: self::APPLE_DOWNGRADE_PRODUCT_ID,
            subscriptionType: $this->introSubscriptionType,
            offerDiscountType: OfferDiscountTypeEnum::PayAsYouGo,
            offerPeriod: self::OFFER_PERIOD,
        );

        $this->handleNotification(
            $this->prepareDowngradeData(self::APPLE_DOWNGRADE_PRODUCT_ID, $this->introductoryOffer()),
        );

        $this->assertSame(
            $this->introSubscriptionType->id,
            $this->lastRecurrentPaymentOfOriginalTransaction()->next_subscription_type_id,
        );
    }

    private function introductoryOffer(): array
    {
        return [
            'offerType' => OfferTypeEnum::Introductory->value,
            'offerDiscountType' => OfferDiscountTypeEnum::PayAsYouGo->value,
            'offerPeriod' => self::OFFER_PERIOD,
        ];
    }

    private function lastPaymentOfOriginalTransaction(): ActiveRow
    {
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertNotEmpty($paymentMetas);

        return end($paymentMetas)->payment;
    }

    private function paymentOfTransaction(string $transactionId): ActiveRow
    {
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        $this->assertCount(1, $paymentMetas);

        return reset($paymentMetas)->payment;
    }

    private function loadSubscriptionTypes(): void
    {
        $this->regularSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test regular subscription month')
            ->setCode(self::REGULAR_SUBSCRIPTION_TYPE_CODE)
            ->setPrice(self::REGULAR_PRICE)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();

        $this->introSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test intro subscription month')
            ->setCode(self::INTRO_SUBSCRIPTION_TYPE_CODE)
            ->setPrice(self::INTRO_PRICE)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->setTrialPeriods(1)
            ->setNextSubscriptionTypeId($this->regularSubscriptionType->id)
            ->save();

        $this->appleAppstoreSubscriptionTypeRepository->upsert(self::APPLE_PRODUCT_ID, $this->regularSubscriptionType);
        $this->appleAppstoreSubscriptionTypeRepository->upsert(
            self::APPLE_PRODUCT_ID,
            $this->introSubscriptionType,
            OfferDiscountTypeEnum::PayAsYouGo,
            self::OFFER_PERIOD,
        );
    }

    private function prepareInitialBuyData(?DateTime $purchaseDate = null, array $offer = []): array
    {
        $purchaseDate ??= new DateTime();
        $expireDate = $purchaseDate->modifyClone('+30 days');

        return [
            'notificationType' => 'SUBSCRIBED',
            'subtype' => 'INITIAL_BUY',
            'data' => [
                'appAppleId' => 123456,
                'bundleId' => 'sk.npress.dennikn.dennikn',
                'bundleVersion' => null,
                'environment' => 'Sandbox',
                'status' => 1,
                'transactionInfo' => array_merge([
                    'bundleId' => 'sk.npress.dennikn.dennikn',
                    'environment' => 'Sandbox',
                    'expiresDate' => $expireDate->format('Uv'),
                    'originalPurchaseDate' => $purchaseDate->format('Uv'),
                    'originalTransactionId' => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    'productId' => self::APPLE_PRODUCT_ID,
                    'purchaseDate' => $purchaseDate->format('Uv'),
                    'quantity' => 1,
                    'signedDate' => $purchaseDate->format('Uv'),
                    'transactionId' => self::APPLE_TRANSACTION_ID,
                    'transactionReason' => 'PURCHASE',
                ], $offer),
            ],
            'version' => '2.0',
            'signedDate' => $purchaseDate->format('Uv'),
            'notificationUUID' => '4a633bed-4031-4675-9ef4-60c0321af866',
        ];
    }

    private function prepareRenewalData(
        DateTime $purchaseDate,
        DateTime $originalPurchaseDate,
        string $transactionId,
        array $offer = [],
    ): array {
        $expireDate = $purchaseDate->modifyClone('+30 days');

        return [
            'notificationType' => 'DID_RENEW',
            'data' => [
                'appAppleId' => 123456,
                'bundleId' => 'sk.npress.dennikn.dennikn',
                'bundleVersion' => null,
                'environment' => 'Sandbox',
                'transactionInfo' => array_merge([
                    'bundleId' => 'sk.npress.dennikn.dennikn',
                    'environment' => 'Sandbox',
                    'expiresDate' => $expireDate->format('Uv'),
                    'originalPurchaseDate' => $originalPurchaseDate->format('Uv'),
                    'originalTransactionId' => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    'productId' => self::APPLE_PRODUCT_ID,
                    'purchaseDate' => $purchaseDate->format('Uv'),
                    'quantity' => 1,
                    'signedDate' => $purchaseDate->format('Uv'),
                    'transactionId' => $transactionId,
                    'transactionReason' => 'RENEWAL',
                ], $offer),
            ],
            'version' => '2.0',
            'signedDate' => $purchaseDate->format('Uv'),
            'notificationUUID' => '4a633bed-4031-4675-9ef4-60c0321af867',
        ];
    }

    private function prepareDowngradeData(string $downgradeProductId, array $offer = []): array
    {
        $purchaseDate = new DateTime();
        $expireDate = $purchaseDate->modifyClone('+30 days');

        $transactionInfo = array_merge([
            'autoRenewStatus' => 1,
            'autoRenewProductId' => $downgradeProductId,
            'bundleId' => 'sk.npress.dennikn.dennikn',
            'environment' => 'Sandbox',
            'expiresDate' => $expireDate->format('Uv'),
            'isUpgraded' => false,
            'originalPurchaseDate' => $purchaseDate->format('Uv'),
            'originalTransactionId' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            'productId' => self::APPLE_PRODUCT_ID,
            'purchaseDate' => $purchaseDate->format('Uv'),
            'quantity' => 1,
            'signedDate' => $purchaseDate->format('Uv'),
            'transactionId' => self::APPLE_TRANSACTION_ID,
            'renewalDate' => $expireDate->format('Uv'),
        ], $offer);

        return [
            'notificationType' => 'DID_CHANGE_RENEWAL_PREF',
            'subtype' => 'DOWNGRADE',
            'data' => [
                'appAppleId' => 123456,
                'bundleId' => 'sk.npress.dennikn.dennikn',
                'bundleVersion' => null,
                'environment' => 'Sandbox',
                'transactionInfo' => $transactionInfo,
                'renewalInfo' => $transactionInfo,
            ],
            'version' => '2.0',
            'signedDate' => $purchaseDate->format('Uv'),
            'notificationUUID' => '4a633bed-4031-4675-9ef4-60c0321af868',
        ];
    }

    private function createDowngradeSubscriptionType(): ActiveRow
    {
        return $this->subscriptionTypeBuilder->createNew()
            ->setNameAndUserLabel('apple appstore test downgrade subscription month')
            ->setCode('apple_appstore_test_downgrade_subscription_type_code')
            ->setPrice(4.99)
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
    }

    private function lastRecurrentPaymentOfOriginalTransaction(): ActiveRow
    {
        return $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id DESC')
            ->fetch();
    }

    private function handleNotification(array $notification): bool
    {
        return $this->serverToServerNotificationWebhookHandler->handle(new Message('test', [
            'notification' => $this->encodeNotificationToJWT($notification),
        ]));
    }

    private function encodeNotificationToJWT(array $payload): string
    {
        // random working private key
        $key = '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEIA5BAzgt5R85DaArLcVRm6sOFxMX5RcdfK1mUH2eSzKhoAoGCCqGSM49
AwEHoUQDQgAEo3xPcQUWbzPuCmRD/ihDbW3kvGgGna5R2gpgCOdF/k+Isyt4xvZL
RqFC5UYnWawkbdl1bq+JxCdP7F0HFUqnAA==
-----END EC PRIVATE KEY-----';

        if (isset($payload['data']['renewalInfo'])) {
            $payload['data']['signedRenewalInfo'] = \Firebase\JWT\JWT::encode($payload['data']['renewalInfo'], $key, 'ES256');
            unset($payload['data']['renewalInfo']);
        }

        if (isset($payload['data']['transactionInfo'])) {
            $payload['data']['signedTransactionInfo'] = \Firebase\JWT\JWT::encode($payload['data']['transactionInfo'], $key, 'ES256');
            unset($payload['data']['transactionInfo']);
        }

        return Json::encode([
            'signedPayload' => \Firebase\JWT\JWT::encode($payload, $key, 'ES256'),
        ]);
    }
}
