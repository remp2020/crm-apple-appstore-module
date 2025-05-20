<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationV2WebhookHandler;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreServerToServerNotificationLogRepository;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder as AppleAppstorePaymentGatewaysSeeder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Models\RecurrentPaymentsProcessor;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\ConfigsSeeder;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\Extension\ExtendSameContentAccess;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Nette\Utils\Random;
use PHPUnit\Framework\Attributes\DataProvider;
use Readdle\AppStoreServerAPI\Util\JWT;
use Tomaj\Hermes\Message;

class ServerToServerNotificationV2WebhookHandlerTest extends DatabaseTestCase
{
    private const SUBSCRIPTION_TYPE_CODE = "apple_appstore_test_internal_subscription_type_code";
    private const APPLE_ORIGINAL_TRANSACTION_ID = "hsalF_no_snur_SOcaM";
    private const APPLE_TRANSACTION_ID = "300002150129622";
    private const APPLE_PRODUCT_ID = "apple_appstore_test_product_id";

    public ApplicationConfig $applicationConfig;
    protected AppleAppstoreSubscriptionTypesRepository $appleAppstoreSubscriptionTypeRepository;
    protected PaymentsRepository $paymentsRepository;
    protected PaymentMetaRepository $paymentMetaRepository;
    protected RecurrentPaymentsRepository $recurrentPaymentsRepository;
    protected SubscriptionTypesRepository $subscriptionTypesRepository;
    protected SubscriptionsRepository $subscriptionsRepository;
    protected SubscriptionTypeBuilder $subscriptionTypeBuilder;
    protected UsersRepository $usersRepository;
    protected UserMetaRepository $userMetaRepository;
    protected Emitter $emitter;
    protected ServerToServerNotificationV2WebhookHandler $serverToServerNotificationWebhookHandler;
    protected ActiveRow $subscriptionType;
    protected ActiveRow $user;
    protected UnclaimedUser $unclaimedUser;
    protected RecurrentPaymentsProcessor $recurrentPaymentsProcessor;
    protected PaymentGatewaysRepository $paymentGatewaysRepository;

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

        $this->applicationConfig = $this->inject(ApplicationConfig::class);
        $this->applicationConfig->setCacheExpiration(0);

        $this->appleAppstoreSubscriptionTypeRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);

        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);
        $this->recurrentPaymentsProcessor = $this->inject(RecurrentPaymentsProcessor::class);

        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);

        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userMetaRepository = $this->getRepository(UserMetaRepository::class);
        $this->unclaimedUser = $this->inject(UnclaimedUser::class);

        $this->serverToServerNotificationWebhookHandler = $this->inject(ServerToServerNotificationV2WebhookHandler::class);

        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        // clear initialized handlers (we do not want duplicated listeners)
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class),
        );

        // prepare subscription type, map it to apple product
        $this->loadSubscriptionType();
        $this->mapAppleProductToSubscriptionType(self::APPLE_PRODUCT_ID, $this->subscriptionType);
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

    public static function usersDataProviderWithExpectedResult(): array
    {
        return [
            'no_user' => [
                'provideUser' => false,
                'expectedResult' => [
                    'isUnclaimed' => true,
                ],
            ],
            'user_provided_found' => [
                'provideUser' => true,
                'expectedResult' => [
                    'isUnclaimed' => false,
                ],
            ],
        ];
    }

    public static function usersDataProvider(): array
    {
        return [
            'no_user' => [
                'provideUser' => false,
            ],
            'user_provided_found' => [
                'provideUser' => true,
            ],
        ];
    }

    #[DataProvider('usersDataProviderWithExpectedResult')]
    public function testInitialBuy(bool $provideUser, array $expectedResult): void
    {
        $user = $provideUser ? $this->loadUser() : null;
        $notification = $this->prepareInitialBuyData(uuid: $user->uuid ?? null);
        $this->handleNotification($notification);

        // load payment by original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `original_transaction_id`.");

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $firstRecurrentPayment = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $firstRecurrentPayment->state,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription_end_at,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription->end_time,
        );

        $this->assertEquals($expectedResult['isUnclaimed'], $this->unclaimedUser->isUnclaimedUser($payment->user));
        if (isset($user)) {
            $this->assertEquals($user->id, $payment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );
    }

    public function testInitialBuyAppTokenProvidedNoUserFound(): void
    {
        $this->expectException(\Exception::class);

        $notification = $this->prepareInitialBuyData(uuid: 'random-uuid');
        $this->handleNotification($notification);
    }

    #[DataProvider('usersDataProvider')]
    public function testResubscribe(bool $provideUser)
    {
        $purchaseDate = new DateTime();
        $originalPurchaseDate = $purchaseDate->modifyClone('-30 days');
        $expireDate = $purchaseDate->modifyClone('+30 days');
        $transactionId = '77897970';

        $user = $provideUser ? $this->loadUser() : null;
        $initialBuyNotification = $this->prepareInitialBuyData(purchaseDate: $originalPurchaseDate, uuid: $user->uuid ?? null);
        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($initialBuyNotification);
        $this->serverToServerNotificationWebhookHandler->setNow(new DateTime());
        $this->recurrentPaymentsRepository->setNow(new DateTime());

        $notification = [
            "notificationType" => "DID_RENEW",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "originalPurchaseDate" => $originalPurchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $purchaseDate->format('Uv'),
                    "transactionId" => $transactionId,
                    "transactionReason" => "RENEWAL",
                ],
            ],
            "version" => "2.0",
            "signedDate" => $purchaseDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // there should be 2 payments with the same original_transaction_id
        $this->assertCount(
            2,
            $this->paymentMetaRepository->findAllByMeta(
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                self::APPLE_ORIGINAL_TRANSACTION_ID,
            ),
        );

        // only 1 payment with transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `transaction_id`.");

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $resubscribePayment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(2, $recurrentPayments);

        // first recurrent should by charged
        $firstRecurrentPayment = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Charged->value,
            $firstRecurrentPayment->state,
        );

        $secondRecurrentPayment = next($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $secondRecurrentPayment->state,
        );

        $this->assertEquals($this->subscriptionType->id, $resubscribePayment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $resubscribePayment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $resubscribePayment->subscription_end_at,
        );

        $this->assertEquals($firstRecurrentPayment->parent_payment->user_id, $resubscribePayment->user_id);
        $this->assertEquals($firstRecurrentPayment->user_id, $secondRecurrentPayment->user_id);
        if ($user) {
            $this->assertEquals($user->id, $resubscribePayment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $this->paymentsRepository->find($resubscribePayment->id)->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testResubscribeBillingRecovery(bool $provideUser)
    {
        $purchaseDate = new DateTime();
        $originalPurchaseDate = $purchaseDate->modifyClone('-30 days');
        $expireDate = $purchaseDate->modifyClone('+30 days');
        $transactionId = '77897970';

        $user = $provideUser ? $this->loadUser() : null;
        $initialBuyNotification = $this->prepareInitialBuyData(purchaseDate: $originalPurchaseDate, uuid: $user->uuid ?? null);
        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($initialBuyNotification);
        $this->serverToServerNotificationWebhookHandler->setNow(new DateTime());
        $this->recurrentPaymentsRepository->setNow(new DateTime());

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()->where([
            'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
        ])->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $originalRecurrentPayment = reset($recurrentPayments);
        $this->prepareFailedRecurrentPaymentCharge($originalRecurrentPayment);
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->find($originalRecurrentPayment->id); // reload

        // we prepare 2 recurrent payments to simulate gateway failed recurrent charge before billing recovery notification
        $this->assertEquals(
            2,
            $this->recurrentPaymentsRepository->getTable()->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            ])->count('*'),
        );
        $this->assertEquals(
            RecurrentPaymentStateEnum::ChargeFailed->value,
            $originalRecurrentPayment->state,
        );
        $this->assertEquals(
            PaymentStatusEnum::Fail->value,
            $originalRecurrentPayment->payment->status,
        );
        $activeRecurrent = $this->recurrentPaymentsRepository->recurrent($originalRecurrentPayment->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $activeRecurrent->state,
        );

        $notification = [
            "notificationType" => "DID_RENEW",
            "subtype" => "BILLING_RECOVERY",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "originalPurchaseDate" => $originalPurchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $purchaseDate->format('Uv'),
                    "transactionId" => $transactionId,
                    "transactionReason" => "RENEWAL",
                ],
            ],
            "version" => "2.0",
            "signedDate" => $purchaseDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // there should be 3 payments with the same original_transaction_id
        $this->assertCount(
            3,
            $this->paymentMetaRepository->findAllByMeta(
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                self::APPLE_ORIGINAL_TRANSACTION_ID,
            ),
        );

        // only 1 payment with transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `transaction_id`.");

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(3, $recurrentPayments);

        // original recurrent still failed
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->find($originalRecurrentPayment->id);
        $this->assertEquals(
            RecurrentPaymentStateEnum::ChargeFailed->value,
            $originalRecurrentPayment->state,
        );

        // recurrent created by gateway should be charged now
        $chargedRecurrent = $this->recurrentPaymentsRepository->recurrent($originalRecurrentPayment->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Charged->value,
            $chargedRecurrent->state,
        );

        // new active recurrent after notification
        $activeRecurrent = $this->recurrentPaymentsRepository->recurrent($chargedRecurrent->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $activeRecurrent->state,
        );

        $resubscribePayment = $chargedRecurrent->payment;
        $this->assertEquals($this->subscriptionType->id, $resubscribePayment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $resubscribePayment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $resubscribePayment->subscription_end_at,
        );

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $this->paymentsRepository->find($resubscribePayment->id)->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testResubscribeBillingRecoveryAfterUpgradeVerifyPurchaseCall(bool $provideUser)
    {
        $purchaseDate = new DateTime();
        $originalPurchaseDate = $purchaseDate->modifyClone('-30 days');
        $expireDate = $purchaseDate->modifyClone('+30 days');
        $transactionId = '77897970';

        $user = $provideUser ? $this->loadUser() : null;
        $initialBuyNotification = $this->prepareInitialBuyData(purchaseDate: $originalPurchaseDate, uuid: $user->uuid ?? null);
        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($initialBuyNotification);
        $this->serverToServerNotificationWebhookHandler->setNow(new DateTime());
        $this->recurrentPaymentsRepository->setNow(new DateTime());

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()->where([
            'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
        ])->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $originalRecurrentPayment = reset($recurrentPayments);
        $this->prepareFailedRecurrentPaymentCharge($originalRecurrentPayment);
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->find($originalRecurrentPayment->id); // reload

        $upgradeProductId = 'apple_upgrade_product';
        $upgradeSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setName('apple appstore test upgrade')
            ->setUserLabel('apple appstore test upgrade')
            ->setPrice(9.99)
            ->setCode('apple_upgrade_type')
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->mapAppleProductToSubscriptionType($upgradeProductId, $upgradeSubscriptionType);

        $this->preparePaymentsAfterVerifyPurchase(
            user: $user,
            subscriptionType: $upgradeSubscriptionType,
            subscriptionStartAt: $upgradeStartAt = $purchaseDate->modifyClone('-5 minutes'),
            subscriptionEndAt: $upgradeStartAt->modifyClone('+30 days'),
            originalTransactionId: self::APPLE_ORIGINAL_TRANSACTION_ID,
            transactionId: $upgradeTransactionId = Random::generate(),
            productId: $upgradeProductId,
        );

        // we prepare 3 recurrent payments - 2 gateway fails and 1 upgrade after verify purchase call
        $this->assertEquals(
            3,
            $this->recurrentPaymentsRepository->getTable()->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            ])->count('*'),
        );
        $this->assertEquals(
            RecurrentPaymentStateEnum::ChargeFailed->value,
            $originalRecurrentPayment->state,
        );
        $this->assertEquals(
            PaymentStatusEnum::Fail->value,
            $originalRecurrentPayment->payment->status,
        );
        // active recurrent stopped after verify purchase call
        $activeRecurrent = $this->recurrentPaymentsRepository->recurrent($originalRecurrentPayment->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::SystemStop->value,
            $activeRecurrent->state,
        );

        $notification = [
            "notificationType" => "DID_RENEW",
            "subtype" => "BILLING_RECOVERY",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "originalPurchaseDate" => $originalPurchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $purchaseDate->format('Uv'),
                    "transactionId" => $transactionId,
                    "transactionReason" => "RENEWAL",
                ],
            ],
            "version" => "2.0",
            "signedDate" => $purchaseDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // there should be 4 payments with the same original_transaction_id
        $this->assertCount(
            4,
            $this->paymentMetaRepository->findAllByMeta(
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                self::APPLE_ORIGINAL_TRANSACTION_ID,
            ),
        );

        // only 1 payment with transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $transactionId,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `transaction_id`.");

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(4, $recurrentPayments);

        // Recover billing notification creates new recurrent payment for original payment
        // Upgrade notification will set this recurrent as charged
        $activeRecurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['state' => RecurrentPaymentStateEnum::Active->value])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(2, $activeRecurrentPayments);

        // original recurrent still failed
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->find($originalRecurrentPayment->id);
        $this->assertEquals(
            RecurrentPaymentStateEnum::ChargeFailed->value,
            $originalRecurrentPayment->state,
        );

        // recurrent created by gateway should be charged now
        $chargedRecurrent = $this->recurrentPaymentsRepository->recurrent($originalRecurrentPayment->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Charged->value,
            $chargedRecurrent->state,
        );

        // new active recurrent after notification
        $activeRecurrent = $this->recurrentPaymentsRepository->recurrent($chargedRecurrent->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $activeRecurrent->state,
        );

        $resubscribePayment = $chargedRecurrent->payment;
        $this->assertEquals($this->subscriptionType->id, $resubscribePayment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $resubscribePayment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $resubscribePayment->subscription_end_at,
        );

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($resubscribePayment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $this->paymentsRepository->find($resubscribePayment->id)->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testAutoRenewDisabled(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $originalPurchaseDate = new DateTime();
        $expireDate = $originalPurchaseDate->modifyClone('+30 days');
        $cancellationDate = $expireDate->modifyClone("-5 days");

        $this->handleNotification($this->prepareInitialBuyData($originalPurchaseDate, $expireDate, uuid: $user->uuid ?? null));

        $notification = $this->prepareDisabledAutoRenewData($originalPurchaseDate, $expireDate, $cancellationDate, uuid: $user->uuid ?? null);
        $this->handleNotification($notification);

        // there should be 1 payment with original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(
            1,
            $paymentMetas,
        );

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $recurrent = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::SystemStop->value,
            $recurrent->state,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription_end_at,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription->end_time,
        );

        if ($user) {
            $this->assertEquals($user->id, $payment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $payment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testAutoRenewEnabled(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime();
        $expireDate = $purchaseDate->modifyClone('+30 days');
        $cancellationDate = $expireDate->modifyClone("-5 days");
        $transactionId = Random::generate();

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, uuid: $user->uuid ?? null));
        $this->handleNotification($this->prepareDisabledAutoRenewData($purchaseDate, $expireDate, $cancellationDate, uuid: $user->uuid ?? null));

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_STATUS",
            "subtype" => "AUTO_RENEW_ENABLED",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "autoRenewStatus" => 1,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
                "renewalInfo" => [
                    "autoRenewStatus" => 1,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $cancellationDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(
            1,
            $paymentMetas,
        );

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $recurrent = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $recurrent->state,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription_end_at,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription->end_time,
        );

        if ($user) {
            $this->assertEquals($user->id, $payment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $payment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testDowngrade(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime();
        $expireDate = $purchaseDate->modifyClone('+30 days');
        $cancellationDate = $expireDate->modifyClone("-5 days");
        $downgradeProductId = 'apple_downgrade_product';

        $downgradeSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setName('apple appstore test downgrade')
            ->setUserLabel('apple appstore test downgrade')
            ->setPrice(4.99)
            ->setCode('apple_downgrade_type')
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->mapAppleProductToSubscriptionType($downgradeProductId, $downgradeSubscriptionType);

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, uuid: $user->uuid ?? null));

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_PREF",
            "subtype" => "DOWNGRADE",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "autoRenewStatus" => 1,
                    "autoRenewProductId" => $downgradeProductId,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                    "renewalDate" => $expireDate->format('Uv'),
                ],
                "renewalInfo" => [
                    "autoRenewStatus" => 1,
                    "autoRenewProductId" => $downgradeProductId,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "renewalDate" => $expireDate->format('Uv'),
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $cancellationDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(
            1,
            $paymentMetas,
        );

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $recurrent = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $recurrent->state,
        );
        $this->assertEquals(
            $downgradeSubscriptionType->id,
            $recurrent->next_subscription_type_id,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $recurrent->charge_at,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription_end_at,
        );

        if ($user) {
            $this->assertEquals($user->id, $payment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $payment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testRevertRenewalChange(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime();
        $expireDate = $purchaseDate->modify('+30 days');
        $cancellationDate = $expireDate->modifyClone("-5 days");

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, uuid: $user->uuid ?? null));
        $this->handleNotification($this->prepareDowngradeData($purchaseDate, $expireDate, $cancellationDate, uuid: $user->uuid ?? null));

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_PREF",
            "subtype" => null,
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "autoRenewStatus" => 1,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                    "renewalDate" => $expireDate->format('Uv'),
                ],
                "renewalInfo" => [
                    "autoRenewStatus" => 1,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "renewalDate" => $expireDate->format('Uv'),
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $cancellationDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(
            1,
            $paymentMetas,
        );

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $recurrent = reset($recurrentPayments);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $recurrent->state,
        );
        $this->assertEquals(
            null,
            $recurrent->next_subscription_type_id,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $recurrent->charge_at,
        );

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $payment->subscription_start_at,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription_end_at,
        );

        if ($user) {
            $this->assertEquals($user->id, $payment->user_id);
        }

        // check additional payment metas
        $this->assertEquals(
            $notification['data']['transactionInfo']['originalTransactionId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $notification['data']['transactionInfo']['productId'],
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );

        $subscription = $payment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $subscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $subscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testUpgradeBeforeVerifyPurchase(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime();
        $expireDate =  $purchaseDate->modifyClone("+31 days");
        $upgradeDate = $purchaseDate->modifyClone("+5 days");
        $expireUpgradeDate =  $upgradeDate->modifyClone("+31 days");
        $upgradeTransactionId = Random::generate();

        $upgradeProductId = 'apple_upgrade_product';
        $upgradeSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setName('apple appstore test upgrade')
            ->setUserLabel('apple appstore test upgrade')
            ->setPrice(9.99)
            ->setCode('apple_upgrade_type')
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->mapAppleProductToSubscriptionType($upgradeProductId, $upgradeSubscriptionType);

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, uuid: $user->uuid ?? null));

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_PREF",
            "subtype" => "UPGRADE",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireUpgradeDate->format('Uv'),
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => $upgradeProductId,
                    "purchaseDate" => $upgradeDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $upgradeDate->format('Uv'),
                    "transactionId" => $upgradeTransactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $upgradeDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // #########
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(2, $paymentMetas);

        // #########
        $originalPaymentMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            self::APPLE_TRANSACTION_ID,
        );
        $this->assertCount(1, $originalPaymentMeta);
        // get original payment
        $paymentMeta = reset($originalPaymentMeta);
        $originalPayment = $paymentMeta->payment;

        // #########
        $upgradePaymentMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $upgradeTransactionId,
        );
        $this->assertCount(1, $upgradePaymentMeta);
        // get upgrade payment
        $paymentMeta = reset($upgradePaymentMeta);
        $upgradePayment = $paymentMeta->payment;

        $this->assertEquals($originalPayment->user_id, $upgradePayment->user_id);
        if ($user) {
            $this->assertEquals($user->id, $upgradePayment->user_id);
        }

        // #########
        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(2, $recurrentPayments);

        // #########
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($originalPayment);
        $this->assertEquals(RecurrentPaymentStateEnum::Charged->value, $originalRecurrentPayment->state);
        $this->assertEquals($upgradePayment->id, $originalRecurrentPayment->payment_id);
        $this->assertEquals($upgradeSubscriptionType->id, $originalRecurrentPayment->next_subscription_type_id);

        // #########
        $recurrentRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($upgradePayment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentRecurrentPayment->state);
        $this->assertEquals(null, $recurrentRecurrentPayment->next_subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $recurrentRecurrentPayment->charge_at,
        );

        // #########
        $originalSubscription = $originalPayment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $originalSubscription->end_time,
        );

        // #########
        $upgradeSubscription = $upgradePayment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $upgradeSubscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $upgradeSubscription->end_time,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testUpgradeAfterVerifyPurchase(bool $provideUser)
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime();
        $expireDate =  $purchaseDate->modifyClone("+31 days");
        $upgradeDate = $purchaseDate->modifyClone("+5 days");
        $expireUpgradeDate =  $upgradeDate->modifyClone("+31 days");
        $upgradeTransactionId = Random::generate();

        $upgradeProductId = 'apple_upgrade_product';
        $upgradeSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setName('apple appstore test upgrade')
            ->setUserLabel('apple appstore test upgrade')
            ->setPrice(9.99)
            ->setCode('apple_upgrade_type')
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->mapAppleProductToSubscriptionType($upgradeProductId, $upgradeSubscriptionType);

        // prepare base payment and recurrent
        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, uuid: $user->uuid ?? null));

        // mock API call verify purchase
        $this->preparePaymentsAfterVerifyPurchase(
            user: $user,
            subscriptionType: $upgradeSubscriptionType,
            subscriptionStartAt: $upgradeDate,
            subscriptionEndAt: $expireUpgradeDate,
            originalTransactionId: self::APPLE_ORIGINAL_TRANSACTION_ID,
            transactionId: $upgradeTransactionId,
            productId: $upgradeProductId,
        );

        // already 2 payments
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(2, $paymentMetas);

        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $upgradeTransactionId,
        );
        $this->assertCount(1, $paymentMetas);

        $upgradePayment = reset($paymentMetas)->payment;
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $upgradePayment->status);

        $upgradeSubscription = $upgradePayment->subscription;
        $this->assertEquals(
            $upgradeDate->format('U'),
            $upgradeSubscription->start_time->format('U'),
        );
        $this->assertEquals(
            $expireUpgradeDate->format('U'),
            $upgradeSubscription->end_time->format('U'),
        );
        $this->assertEquals($upgradeSubscriptionType->id, $upgradeSubscription->subscription_type_id);

        // verify purchase API call created new payment with active recurrent
        // but stopped current active recurrent
        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
                'state' => RecurrentPaymentStateEnum::Active->value])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
                'state' => RecurrentPaymentStateEnum::SystemStop->value,
            ])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_PREF",
            "subtype" => "UPGRADE",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireUpgradeDate->format('Uv'),
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => $upgradeProductId,
                    "purchaseDate" => $upgradeDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $upgradeDate->format('Uv'),
                    "transactionId" => $upgradeTransactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $upgradeDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // #########
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(2, $paymentMetas);

        // #########
        $originalPaymentMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            self::APPLE_TRANSACTION_ID,
        );
        $this->assertCount(1, $originalPaymentMeta);
        // get original payment
        $paymentMeta = reset($originalPaymentMeta);
        $originalPayment = $paymentMeta->payment;

        // #########
        $upgradePaymentMeta = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            $upgradeTransactionId,
        );
        $this->assertCount(1, $upgradePaymentMeta);
        // get upgrade payment
        $paymentMeta = reset($upgradePaymentMeta);
        $upgradePayment = $paymentMeta->payment;

        $this->assertEquals($originalPayment->user_id, $upgradePayment->user_id);
        if ($user) {
            $this->assertEquals($user->id, $upgradePayment->user_id);
        }

        // #########
        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()
            ->where(['payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID])
            ->order('id ASC')
            ->fetchAll();
        $this->assertCount(2, $recurrentPayments);

//        // #########
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($originalPayment);
        $this->assertEquals(RecurrentPaymentStateEnum::Charged->value, $originalRecurrentPayment->state);
        $this->assertEquals($upgradePayment->id, $originalRecurrentPayment->payment_id);
        $this->assertEquals($upgradeSubscriptionType->id, $originalRecurrentPayment->next_subscription_type_id);

        // #########
        $recurrentRecurrentPayment = $this->recurrentPaymentsRepository->recurrent($upgradePayment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentRecurrentPayment->state);
        $this->assertEquals(null, $recurrentRecurrentPayment->next_subscription_type_id);
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $recurrentRecurrentPayment->charge_at,
        );

        // #########
        $originalSubscription = $originalPayment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $originalSubscription->end_time,
        );

        // #########
        $upgradeSubscription = $upgradePayment->subscription;
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['purchaseDate']),
            $upgradeSubscription->start_time,
        );
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $upgradeSubscription->end_time,
        );
        $this->assertEquals($upgradeSubscriptionType->id, $upgradeSubscription->subscription_type_id);
    }

    #[DataProvider('usersDataProvider')]
    public function testExpired(bool $provideUser)
    {
        $originalPurchaseDate = new DateTime('-30 days');
        $expireDate = $originalPurchaseDate->modifyClone('+29 days');
        $transactionId = '77897970';

        $user = $provideUser ? $this->loadUser() : null;
        $initialBuyNotification = $this->prepareInitialBuyData(
            purchaseDate: $originalPurchaseDate,
            expireDate: $expireDate,
            uuid: $user->uuid ?? null,
        );
        $this->serverToServerNotificationWebhookHandler->setNow($originalPurchaseDate);
        $this->recurrentPaymentsRepository->setNow($originalPurchaseDate);
        $this->handleNotification($initialBuyNotification);
        $this->serverToServerNotificationWebhookHandler->setNow(new DateTime());
        $this->recurrentPaymentsRepository->setNow(new DateTime());

        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()->where([
            'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
        ])->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        $originalRecurrentPayment = reset($recurrentPayments);
        $this->prepareFailedRecurrentPaymentCharge($originalRecurrentPayment);
        $originalRecurrentPayment = $this->recurrentPaymentsRepository->find($originalRecurrentPayment->id); // reload

        // we prepare 2 recurrent payments to simulate gateway failed recurrent charge before expired notification
        $this->assertEquals(
            2,
            $this->recurrentPaymentsRepository->getTable()->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            ])->count('*'),
        );
        $this->assertEquals(
            RecurrentPaymentStateEnum::ChargeFailed->value,
            $originalRecurrentPayment->state,
        );
        $this->assertEquals(
            PaymentStatusEnum::Fail->value,
            $originalRecurrentPayment->payment->status,
        );
        $activeRecurrent = $this->recurrentPaymentsRepository->recurrent($originalRecurrentPayment->payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $activeRecurrent->state,
        );

        $notification = [
            "notificationType" => "EXPIRED",
            "subtype" => "VOLUNTARY",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "originalPurchaseDate" => $originalPurchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $originalPurchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $originalPurchaseDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $originalPurchaseDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];
        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        $this->assertEquals(
            2,
            $this->recurrentPaymentsRepository->getTable()->where([
                'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
            ])->count('*'),
        );

        // reload payment
        $payment = $this->paymentsRepository->find($originalRecurrentPayment->payment);

        // active recurrent before notification should be stopped
        $stoppedRecurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::SystemStop->value,
            $stoppedRecurrent->state,
        );
    }

    #[DataProvider('usersDataProvider')]
    public function testRefund(bool $provideUser): void
    {
        $user = $provideUser ? $this->loadUser() : null;
        $purchaseDate = new DateTime("-5 days");
        $expireDate = $purchaseDate->modifyClone('+25 days');
        $refundedDate = $purchaseDate->modifyClone('+10 days');

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, $user->uuid ?? null));

        $notification = [
            "notificationType" => "REFUND",
            "notificationUUID" => "9e11ee85-2b5b-4800-8502-8a3da2105796",
            "subtype" => null,
            "version" => "2.0",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "environment" => "Sandbox",
                "bundleVersion" => null,
                "renewalInfo" => [
                    "offerType" => null,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "signedDate" => $refundedDate->format('Uv'),
                    "environment" => "Sandbox",
                    "renewalDate" => $expireDate->format('Uv'),
                    "autoRenewStatus" => 0,
                    "offerIdentifier" => null,
                    "expirationIntent" => null,
                    "autoRenewProductId" => self::APPLE_PRODUCT_ID,
                    "priceIncreaseStatus" => null,
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "gracePeriodExpiresDate" => null,
                    "isInBillingRetryPeriod" => null,
                    "recentSubscriptionStartDate" => $purchaseDate->format('Uv'),
                ],
                "transactionInfo" => [
                    "type" => "Auto-Renewable Subscription",
                    "price" => 7990,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "currency" => "EUR",
                    "quantity" => 1,
                    "offerType" => null,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "isUpgraded" => null,
                    "signedDate" => $refundedDate->format('Uv'),
                    "storefront" => "SVK",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "storefrontId" => "143496",
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                    "revocationDate" => $refundedDate->format('Uv'),
                    "offerIdentifier" => null,
                    "revocationReason" => 0,
                    "offerDiscountType" => null,
                    "transactionReason" => "RENEWAL",
                    "inAppOwnershipType" => "PURCHASED",
                    "webOrderLineItemId" => "300001000926057",
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "subscriptionGroupIdentifier" => "20675050",
                ],
            ],
        ];

        if ($user) {
            $notification['data']['transactionInfo']['appAccountToken'] = $user->uuid;
        }

        $this->handleNotification($notification);

        // there should be 1 payment with original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        // there should be 1 payment with transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            self::APPLE_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $this->assertEquals(PaymentStatusEnum::Refund->value, $payment->status);

        // check if recurrent payment is stopped
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::SystemStop->value,
            $recurrentPayment->state,
        );

        // check if subscription is ended
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['revocationDate']),
            $payment->subscription->end_time,
        );
    }

    public function testRefundReversed(): void
    {
        $user = true ? $this->loadUser() : null;
        $purchaseDate = new DateTime("-5 days");
        $expireDate = $purchaseDate->modifyClone('+25 days');
        $refundedDate = $purchaseDate->modifyClone('+10 days');

        $this->handleNotification($this->prepareInitialBuyData($purchaseDate, $expireDate, $user->uuid ?? null));

        // simulate payment refund (subscription end, recurrent stoped)
        $paymentMeta = $this->paymentMetaRepository->findByMeta(AppleAppstoreModule::META_KEY_TRANSACTION_ID, self::APPLE_TRANSACTION_ID);
        $payment = $paymentMeta->payment;

        $this->paymentsRepository->update($payment, [
            'status' => PaymentStatusEnum::Refund->value,
        ]);

        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment->id);

        $this->subscriptionsRepository->update($payment->subscription, [
            'end_time' => $refundedDate,
        ]);

        $notification = [
            "notificationType" => "REFUND_REVERSED",
            "notificationUUID" => "fbaa102f-6df1-42b7-b133-8fbd5538f036",
            "subtype" => null,
            "version" => "2.0",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "environment" => "Sandbox",
                "bundleVersion" => null,
                "renewalInfo" => [
                    "offerType" => null,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "signedDate" => $refundedDate->format('Uv'),
                    "environment" => "Sandbox",
                    "renewalDate" => $expireDate->format('Uv'),
                    "autoRenewStatus" => 0,
                    "offerIdentifier" => null,
                    "expirationIntent" => 1,
                    "autoRenewProductId" => self::APPLE_PRODUCT_ID,
                    "priceIncreaseStatus" => null,
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "gracePeriodExpiresDate" => null,
                    "isInBillingRetryPeriod" => false,
                    "recentSubscriptionStartDate" => $purchaseDate->format('Uv'),
                ],
                "transactionInfo" => [
                    "type" => "Auto-Renewable Subscription",
                    "price" => 7990,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "currency" => "EUR",
                    "quantity" => 1,
                    "offerType" => null,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "isUpgraded" => null,
                    "signedDate" => $refundedDate->format('Uv'),
                    "storefront" => "SVK",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "storefrontId" => "143496",
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                    "revocationDate" => null,
                    "offerIdentifier" => null,
                    "revocationReason" => null,
                    "offerDiscountType" => null,
                    "transactionReason" => "RENEWAL",
                    "inAppOwnershipType" => "PURCHASED",
                    "webOrderLineItemId" => "300001000926057",
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "subscriptionGroupIdentifier" => "20675050",
                ],
            ],
        ];

        $this->handleNotification($notification);

        // there should be 1 payment with original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        // there should be 1 payment with transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_TRANSACTION_ID,
            self::APPLE_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $payment->status);

        // check if recurrent payment is activated
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(
            RecurrentPaymentStateEnum::Active->value,
            $recurrentPayment->state,
        );

        // check if subscription is started again
        $this->assertEquals(
            $this->convertTimestampRemoveMilliseconds($notification['data']['transactionInfo']['expiresDate']),
            $payment->subscription->end_time,
        );
    }

    /* HELPER FUNCTION ************************************************ */

    private function prepareInitialBuyData(
        ?DateTime $purchaseDate = null,
        ?DateTime $expireDate = null,
        ?string $uuid = null,
    ): array {
        $purchaseDate ??= new DateTime();
        $expireDate ??= $purchaseDate->modifyClone('+30 days');

        $notification = [
            "notificationType" => "SUBSCRIBED",
            "subtype" => "INITIAL_BUY",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "status" => 1,
                "transactionInfo" => [
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expiresDate" => $expireDate->format('Uv'),
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $purchaseDate->format('Uv'),
                    "transactionId" => self::APPLE_TRANSACTION_ID,
                    "transactionReason" => "PURCHASE",
                ],
            ],
            "version" => "2.0",
            "signedDate" => $purchaseDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af866",
        ];

        if ($uuid) {
            $notification['data']['transactionInfo']['appAccountToken'] = $uuid;
        }

        return $notification;
    }

    private function prepareDisabledAutoRenewData(
        ?DateTime $purchaseDate = null,
        ?DateTime $expireDate = null,
        ?DateTime $cancellationDate = null,
        ?string $transactionId = null,
        ?string $uuid = null,
    ) {
        $purchaseDate ??= new DateTime();
        $expireDate ??= $purchaseDate->modifyClone('+30 days');
        $cancellationDate ??= $expireDate->modifyClone("-5 days");
        $transactionId ??= Random::generate();

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_STATUS",
            "subtype" => "AUTO_RENEW_DISABLED",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "autoRenewStatus" => 0,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => 1,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
                "renewalInfo" => [
                    "autoRenewStatus" => 0,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => 1,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $cancellationDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];

        if ($uuid) {
            $notification['data']['transactionInfo']['appAccountToken'] = $uuid;
        }

        return $notification;
    }

    private function prepareDowngradeData(
        ?DateTime $purchaseDate = null,
        ?DateTime $expireDate = null,
        ?DateTime $cancellationDate = null,
        ?string $transactionId = null,
        ?string $downgradeProductId = null,
        ?string $uuid = null,
    ) {
        $purchaseDate ??= new DateTime();
        $expireDate ??= $purchaseDate->modifyClone('+30 days');
        $cancellationDate ??= $expireDate->modifyClone("-5 days");
        $transactionId ??= self::APPLE_TRANSACTION_ID;
        $downgradeProductId ??= 'apple_downgrade_product';

        $downgradeSubscriptionType = $this->subscriptionTypeBuilder->createNew()
            ->setName('apple appstore test downgrade')
            ->setUserLabel('apple appstore test downgrade')
            ->setPrice(4.99)
            ->setCode('apple_downgrade_type')
            ->setLength(31)
            ->setActive(true)
            ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
            ->save();
        $this->mapAppleProductToSubscriptionType($downgradeProductId, $downgradeSubscriptionType);

        $notification = [
            "notificationType" => "DID_CHANGE_RENEWAL_PREF",
            "subtype" => "DOWNGRADE",
            "data" => [
                "appAppleId" => 123456,
                "bundleId" => "sk.npress.dennikn.dennikn",
                "bundleVersion" => null,
                "environment" => "Sandbox",
                "transactionInfo" => [
                    "autoRenewStatus" => 1,
                    "autoRenewProductId" => $downgradeProductId,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                    "renewalDate" => $expireDate->format('Uv'),
                ],
                "renewalInfo" => [
                    "autoRenewStatus" => 1,
                    "autoRenewProductId" => $downgradeProductId,
                    "bundleId" => "sk.npress.dennikn.dennikn",
                    "environment" => "Sandbox",
                    "expirationIntent" => null,
                    "expiresDate" => $expireDate->format('Uv'),
                    "isUpgraded" => false,
                    "originalPurchaseDate" => $purchaseDate->format('Uv'),
                    "originalTransactionId" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "productId" => self::APPLE_PRODUCT_ID,
                    "purchaseDate" => $purchaseDate->format('Uv'),
                    "quantity" => 1,
                    "renewalDate" => $expireDate->format('Uv'),
                    "signedDate" => $cancellationDate->format('Uv'),
                    "transactionId" => $transactionId,
                ],
            ],
            "version" => "2.0",
            "signedDate" => $cancellationDate->format('Uv'),
            "notificationUUID" => "4a633bed-4031-4675-9ef4-60c0321af867",
        ];

        if ($uuid) {
            $notification['data']['transactionInfo']['appAccountToken'] = $uuid;
        }

        $this->handleNotification($notification);

        return $notification;
    }

    private function prepareFailedRecurrentPaymentCharge($recurrentPayment): void
    {
        $parentPayment = $recurrentPayment->parent_payment;
        $originalTransactionId = $parentPayment->related('payment_meta')->where('key', AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID)->fetch();

        $failedPayment = $this->paymentsRepository->copyPayment($parentPayment);
        $this->paymentMetaRepository->add($failedPayment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID, $originalTransactionId->value);

        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'payment_id' => $failedPayment->id,
        ]);
        $this->paymentsRepository->update($failedPayment, [
            'status' => PaymentStatusEnum::Fail->value,
        ]);
        $this->recurrentPaymentsProcessor->processFailedRecurrent($recurrentPayment, 'FAIL', 'FAIL');
    }

    private function preparePaymentsAfterVerifyPurchase(
        $user,
        $subscriptionType,
        $subscriptionStartAt,
        $subscriptionEndAt,
        $originalTransactionId,
        $transactionId,
        $productId,
    ) {
        if (!$user) {
            $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                $originalTransactionId,
            );
            $user = reset($paymentMetas)->payment->user;
        }

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
        $paymentGatewayCode = AppleAppstoreGateway::GATEWAY_CODE;
        $paymentGateway = $this->paymentGatewaysRepository->findByCode($paymentGatewayCode);

        $payment = $this->paymentsRepository->add(
            subscriptionType: $subscriptionType,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
            amount: $subscriptionType->price,
            subscriptionStartAt: $subscriptionStartAt,
            subscriptionEndAt: $subscriptionEndAt,
            metaData: [
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID => $originalTransactionId,
                AppleAppstoreModule::META_KEY_PRODUCT_ID => $productId,
                AppleAppstoreModule::META_KEY_TRANSACTION_ID => $transactionId,
            ],
        );

        $this->paymentsRepository->update($payment, [
            'paid_at' => $subscriptionStartAt,
        ]);
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Prepaid->value);

        $activeOriginalTransactionRecurrents = $this->recurrentPaymentsRepository
            ->getUserActiveRecurrentPayments($payment->user_id)
            ->where([
                'payment_method.external_token' => $originalTransactionId,
            ])
            ->fetchAll();
        foreach ($activeOriginalTransactionRecurrents as $rp) {
            $this->recurrentPaymentsRepository->stoppedBySystem($rp->id);
        }

        $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $originalTransactionId,
            $subscriptionEndAt,
        );
    }

    private function loadSubscriptionType()
    {
        if (!isset($this->subscriptionType)) {
            $subscriptionType = $this->subscriptionTypesRepository->findByCode(self::SUBSCRIPTION_TYPE_CODE);
            if (!$subscriptionType) {
                $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                    ->setName('apple appstore test subscription month')
                    ->setUserLabel('apple appstore test subscription month')
                    ->setPrice(6.99)
                    ->setCode(self::SUBSCRIPTION_TYPE_CODE)
                    ->setLength(31)
                    ->setActive(true)
                    ->setExtensionMethod(ExtendSameContentAccess::METHOD_CODE)
                    ->save();
            }
            $this->subscriptionType = $subscriptionType;
        }
        return $this->subscriptionType;
    }

    private function loadUser()
    {
        $email = 'apple.appstore+test1@example.com';
        $user = $this->usersRepository->getByEmail($email);
        if (!$user) {
            $user = $this->usersRepository->add($email, 'MacOSrunsOnFlash');
            $this->usersRepository->update($user, ['uuid' => '123-456']);
        }

        return $user;
    }

    private function mapAppleProductToSubscriptionType(string $appleProductID, ActiveRow $subscriptionType)
    {
        $this->appleAppstoreSubscriptionTypeRepository->add($appleProductID, $subscriptionType);
    }

    private function convertTimestampRemoveMilliseconds(string $timestampWithMilliseconds): DateTime
    {
        $convertedTimestamp = intdiv((int) $timestampWithMilliseconds, 1000);
        $returnDateTime = DateTime::createFromFormat("U", $convertedTimestamp);

        $returnDateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $returnDateTime;
    }

    private function handleNotification(array $notification): void
    {
        $this->serverToServerNotificationWebhookHandler->handle(new Message('test', [
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
            $signedRenewalInfo = \Firebase\JWT\JWT::encode($payload['data']['renewalInfo'], $key, 'ES256');
            $payload['data']['signedRenewalInfo'] = $signedRenewalInfo;
            unset($payload['data']['renewalInfo']);
        }

        if (isset($payload['data']['transactionInfo'])) {
            $signedTransactionInfo = \Firebase\JWT\JWT::encode($payload['data']['transactionInfo'], $key, 'ES256');
            $payload['data']['signedTransactionInfo'] = $signedTransactionInfo;
            unset($payload['data']['transactionInfo']);
        }

        $signedPayload = \Firebase\JWT\JWT::encode($payload, $key, 'ES256');

        return Json::encode([
            'signedPayload' => $signedPayload,
        ]);
    }
}
