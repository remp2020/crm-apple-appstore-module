<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Hermes\ServerToServerNotificationWebhookHandler;
use Crm\AppleAppstoreModule\Models\PendingRenewalInfo;
use Crm\AppleAppstoreModule\Repositories\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder as AppleAppstorePaymentGatewaysSeeder;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
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
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Tomaj\Hermes\Message;

class ServerToServerNotificationWebhookHandlerTest extends DatabaseTestCase
{
    const SUBSCRIPTION_TYPE_CODE = "apple_appstore_test_internal_subscription_type_code";
    const APPLE_ORIGINAL_TRANSACTION_ID = "hsalF_no_snur_SOcaM";
    const APPLE_PRODUCT_ID = "apple_appstore_test_product_id";

    /** @var ApplicationConfig */
    public $applicationConfig;

    /** @var AppleAppstoreSubscriptionTypesRepository */
    protected $appleAppstoreSubscriptionTypeRepository;

    /** @var PaymentsRepository */
    protected $paymentsRepository;

    /** @var PaymentMetaRepository */
    protected $paymentMetaRepository;

    /** @var RecurrentPaymentsRepository */
    protected $recurrentPaymentsRepository;

    /** @var SubscriptionTypesRepository */
    protected $subscriptionTypesRepository;

    /** @var SubscriptionsRepository */
    protected $subscriptionsRepository;

    /** @var SubscriptionTypeBuilder */
    protected $subscriptionTypeBuilder;

    /** @var UsersRepository */
    protected $usersRepository;

    /** @var UserMetaRepository */
    protected $userMetaRepository;

    /** @var LazyEventEmitter */
    protected $lazyEventEmitter;

    /** @var ServerToServerNotificationWebhookHandler */
    protected $serverToServerNotificationWebhookHandler;

    protected $subscriptionType;
    protected $user;

    protected function requiredRepositories(): array
    {
        return [
            AppleAppstoreSubscriptionTypesRepository::class,

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
        ];
    }

    public function requiredSeeders(): array
    {
        return [
            ApplicationConfigsSeeder::class,

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

        $this->appleAppstoreSubscriptionTypeRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);

        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);

        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);

        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userMetaRepository = $this->getRepository(UserMetaRepository::class);

        $this->serverToServerNotificationWebhookHandler = $this->inject(ServerToServerNotificationWebhookHandler::class);

        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->lazyEventEmitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class),
        );
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(PaymentChangeStatusEvent::class);

        parent::tearDown();
    }

    /**
     * Prepare data for initial buy and test that payment was created.
     *
     * This function will be run in multiple tests to preload first payment.
     * InitialBuy is properly tested by testInitialBuySucessful().
     */
    public function prepareInitialBuyData(): array
    {
        // prepare subscription type, map it to apple product
        $this->loadSubscriptionType();
        $this->mapAppleProductToSubscriptionType(self::APPLE_PRODUCT_ID, $this->subscriptionType);
        // prepare user & map user to original transaction ID used in tests
        $this->loadUser();
        $this->userMetaRepository->add($this->user, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID, self::APPLE_ORIGINAL_TRANSACTION_ID);

        $originalPurchaseDate = new DateTime();
        // purchase date is same as original purchase date for INITIAL_BUY
        $purchaseDate = (clone $originalPurchaseDate);
        $expiresDate = (clone $originalPurchaseDate)->modify("1 month");

        $olderTransactionPurchaseDate = (clone $originalPurchaseDate)->modify('-2 months');
        $olderTransactionExpiresDate = (clone $olderTransactionPurchaseDate)->modify("1 month");

        $notification = [
            "notification_type" => "INITIAL_BUY", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => [
                    (object)[
                        "expires_date_ms" => $this->convertToTimestampFlooredToSeconds($expiresDate),
                        "original_purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($originalPurchaseDate),
                        "original_transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                        "product_id" => self::APPLE_PRODUCT_ID,
                        "purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($purchaseDate),
                        "quantity" => "1",
                        // transaction ID is same for INITIAL_BUY
                        "transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    ],
                    // older transaction to simulate multiple transactions in array
                    (object)[
                        "expires_date_ms" => $this->convertToTimestampFlooredToSeconds($olderTransactionExpiresDate),
                        "original_purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($olderTransactionPurchaseDate),
                        "original_transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                        "product_id" => self::APPLE_PRODUCT_ID,
                        "purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($olderTransactionPurchaseDate),
                        "quantity" => "1",
                        // transaction ID is same for INITIAL_BUY
                        "transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    ],
                ],
                "pending_renewal_info" => [],
                "status" => 0,
            ],
        ];
        $notification["unified_receipt"]->latest_receipt = base64_encode(json_encode($notification["unified_receipt"]->latest_receipt_info));

        $this->handleNotification($notification);

        // load payment by original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `original_transaction_id`.");
        $recurrentPayments = $this->recurrentPaymentsRepository->getTable()->where([
            'payment_method.external_token' => self::APPLE_ORIGINAL_TRANSACTION_ID,
        ])->fetchAll();
        $this->assertCount(1, $recurrentPayments);

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        return ["notification" => $notification, "payment" => $payment];
    }

    public function testInitialBuySucessful()
    {
        ["notification" => $initialBuyRequestData, "payment" => $payment] = $this->prepareInitialBuyData();

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->purchase_date_ms,
            $this->convertToTimestampFlooredToSeconds($payment->subscription_start_at),
        );
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->expires_date_ms,
            $this->convertToTimestampFlooredToSeconds($payment->subscription_end_at),
        );
        $this->assertEquals($this->user->id, $payment->user_id);

        // check additional payment metas
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value,
        );
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value,
        );
    }

    public function testCancellationSuccessful()
    {
        ["notification" => $initialBuyRequestData, "payment" => $initPayment] = $this->prepareInitialBuyData();

        $cancellationDate = (new DateTime($initPayment->subscription_start_at))->modify("+1 day");

        $notification = [
            "notification_type" => "CANCEL", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => [
                    (object) [
                        "cancellation_date_ms" => $this->convertToTimestampFlooredToSeconds($cancellationDate),
                        "cancellation_reason" => "1",
                        'purchase_date_ms' => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->purchase_date_ms,
                        "original_purchase_date_ms" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_purchase_date_ms,
                        "original_transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
                        "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
                        "transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->transaction_id,
                    ],
                    $initialBuyRequestData["unified_receipt"]->latest_receipt_info[1],
                ],
                "pending_renewal_info" => [],
                "status" => 0,
            ],
        ];
        $notification["unified_receipt"]->latest_receipt = base64_encode(json_encode($notification["unified_receipt"]->latest_receipt_info[0]));

        $this->handleNotification($notification);

        // reload payment after changes
        $cancelledPayment = $this->paymentsRepository->find($initPayment->id);
        $this->assertNotFalse($cancelledPayment);
        $this->assertEquals(PaymentStatusEnum::Refund->value, $cancelledPayment->status); // TODO: maybe we need new state? -> PREPAID_REFUND?
        $this->assertStringContainsStringIgnoringCase($cancellationDate->format('Y-m-d H:i:s'), $cancelledPayment->note);

        // check cancellation details in payment meta
        $this->assertEquals(
            $cancellationDate->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($cancelledPayment, AppleAppstoreModule::META_KEY_CANCELLATION_DATE)->value,
        );
        $this->assertEquals(
            $notification["unified_receipt"]->latest_receipt_info[0]->cancellation_reason,
            $this->paymentMetaRepository->findByPaymentAndKey($cancelledPayment, AppleAppstoreModule::META_KEY_CANCELLATION_REASON)->value,
        );
    }

    public function testDidRecover()
    {
        ["notification" => $initialBuyRequestData, "payment" => $initPayment] = $this->prepareInitialBuyData();

        // between end time of previous subscription and "purchase date" of recovered payment will be gap
        $originalExpiresDate = new DateTime($initPayment->subscription_end_at);
        $purchaseDate = (clone $originalExpiresDate)->modify("+1 day");
        $expiresDate = (clone $purchaseDate)->modify('+1 month');

        $notification = [
            "notification_type" => "DID_RECOVER", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => [
                    (object) [
                        "expires_date_ms" => $this->convertToTimestampFlooredToSeconds($expiresDate),
                        // original purchase date and original transaction ID will be same as initial buy payment
                        "original_purchase_date_ms" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_purchase_date_ms,
                        "original_transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
                        "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
                        "purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($purchaseDate),
                        "quantity" => "1",
                        "transaction_id" => 'DID_RECOVER_transaction_id',
                    ],
                    $initialBuyRequestData["unified_receipt"]->latest_receipt_info[1],
                ],
                "pending_renewal_info" => [],
                "status" => 0,
            ],
        ];
        $notification["unified_receipt"]->latest_receipt = base64_encode(json_encode($notification["unified_receipt"]->latest_receipt_info));

        $this->handleNotification($notification);

        // load payments by original transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $notification["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
        );
        $this->assertCount(2, $paymentMetas, "Exactly two payments should have `payment_meta` with expected `original_transaction_id`.");

        // metas are ordered descending by ID; load recovered payment first
        $recoveredPayment = reset($paymentMetas)->payment;
        $initPaymentReloaded = next($paymentMetas)->payment;

        // check original payment; should be intact
        $this->assertEquals($initPayment->status, $initPaymentReloaded->status);
        $this->assertEquals($initPayment->subscription_start_at, $initPaymentReloaded->subscription_start_at);
        $this->assertEquals($initPayment->subscription_end_at, $initPaymentReloaded->subscription_end_at);
        $this->assertEquals($initPayment->subscription_type_id, $initPaymentReloaded->subscription_type_id);

        // check new recovered payment
        // user, subscription type should be same as first payment
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $recoveredPayment->status);
        $this->assertEquals($initPayment->subscription_type_id, $recoveredPayment->subscription_type_id);
        $this->assertEquals($initPayment->user_id, $recoveredPayment->user_id);
        // dates will be set by request payload
        $this->assertEquals(
            $notification["unified_receipt"]->latest_receipt_info[0]->purchase_date_ms,
            $this->convertToTimestampFlooredToSeconds($recoveredPayment->subscription_start_at),
        );
        $this->assertEquals(
            $notification["unified_receipt"]->latest_receipt_info[0]->expires_date_ms,
            $this->convertToTimestampFlooredToSeconds($recoveredPayment->subscription_end_at),
        );
    }

    public function testDidChangeRenewalPrefSucessful()
    {
        // initial buy
        ["notification" => $initialBuyRequestData, "payment" => $payment] = $this->prepareInitialBuyData();

        // **********************************************************
        // check subscription type of recurrent payment
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals($this->subscriptionType->id, $recurrentPayment->subscription_type_id);
        $this->assertNull($recurrentPayment->next_subscription_type_id);
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->expires_date_ms,
            $this->convertToTimestampFlooredToSeconds($recurrentPayment->charge_at),
        );

        // **********************************************************
        // create new subscription tyoe & map it to new apple product id
        $betterSubscriptionTypeCode = self::SUBSCRIPTION_TYPE_CODE . '_better_type';
        $betterSubscriptionType = $this->subscriptionTypesRepository->findByCode($betterSubscriptionTypeCode);
        if (!$betterSubscriptionType) {
            $betterSubscriptionType = $this->subscriptionTypeBuilder->createNew()
                ->setName('BETTER apple appstore test subscription month')
                ->setUserLabel('BETTER apple appstore test subscription month')
                ->setPrice(9.99)
                ->setCode($betterSubscriptionTypeCode)
                ->setLength(31)
                ->setActive(true)
                ->save();
        }
        $betterAppleProductID = self::APPLE_PRODUCT_ID . '_better';
        $this->mapAppleProductToSubscriptionType($betterAppleProductID, $betterSubscriptionType);

        // **********************************************************
        // create and process DID_CHANGE_RENEWAL_PREF notification
        // notification is same, only type, end time & product ID are different
        $notification = $initialBuyRequestData;
        $notification["notification_type"] = "DID_CHANGE_RENEWAL_PREF";
        $notification["unified_receipt"]->latest_receipt_info[0]->product_id = $betterAppleProductID;
        $changedExpiresDate = clone(new DateTime($payment->subscription->end_time))->modify('-3 days');
        $notification["unified_receipt"]->latest_receipt_info[0]->expires_date_ms = $this->convertToTimestampFlooredToSeconds($changedExpiresDate);
        $notification["unified_receipt"]->latest_receipt = base64_encode(json_encode($notification["unified_receipt"]->latest_receipt_info));

        $this->handleNotification($notification);

        // **********************************************************
        // check subscription type of recurrent payment again; not it should be new
        $payment = $this->paymentsRepository->find($payment->id);
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        // subscription type of recurrent payment is same
        $this->assertEquals($this->subscriptionType->id, $recurrentPayment->subscription_type_id);
        // subscription type of next payment is "better" subscription type
        $this->assertEquals($betterSubscriptionType->id, $recurrentPayment->next_subscription_type_id);
        $this->assertEquals(
            $changedExpiresDate,
            $recurrentPayment->charge_at,
        );
    }

    public function testDidChangeRenewalStatusSucessful()
    {
        // initial buy
        ["notification" => $initialBuyRequestData, "payment" => $payment] = $this->prepareInitialBuyData();

        // **********************************************************
        // check state of recurrent payment
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentPayment->state);

        // **********************************************************
        // create and process DID_CHANGE_RENEWAL_STATUS notification
        // notification is same, field auto_renew_status is added with 'false' (STOP recurrent)
        $notification = $initialBuyRequestData;
        $notification["notification_type"] = "DID_CHANGE_RENEWAL_STATUS";
        $notification["auto_renew_status"] = false;

        $this->handleNotification($notification);

        // check state of recurrent
        $payment = $this->paymentsRepository->find($payment->id);
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentStateEnum::SystemStop->value, $recurrentPayment->state);

        // **********************************************************
        // create and process DID_CHANGE_RENEWAL_STATUS notification
        // notification is same, field auto_renew_status is added with 'true' (REACTIVATE recurrent)
        $notification = $initialBuyRequestData;
        $notification["notification_type"] = "DID_CHANGE_RENEWAL_STATUS";
        $notification["auto_renew_status"] = true;

        $this->handleNotification($notification);

        // check state of recurrent
        $payment = $this->paymentsRepository->find($payment->id);
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentPayment->state);
    }

    public function testDidChangeRenewalStatusMissingRecurrent()
    {
        // initial buy
        ["notification" => $initialBuyRequestData, "payment" => $payment] = $this->prepareInitialBuyData();

        // **********************************************************
        // remove recurrent
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $recurrentPaymentID = $recurrentPayment->id;
        $this->recurrentPaymentsRepository->delete($recurrentPayment);
        $this->assertNull($this->recurrentPaymentsRepository->find($recurrentPaymentID));

        // **********************************************************
        // create and process DID_CHANGE_RENEWAL_STATUS notification
        // notification is same, field auto_renew_status is added with 'false' (STOP recurrent)
        $notification = $initialBuyRequestData;
        $notification["notification_type"] = "DID_CHANGE_RENEWAL_STATUS";
        $notification["auto_renew_status"] = false;

        $this->handleNotification($notification);

        // check state of recurrent (there shouldn't be any)
        $payment = $this->paymentsRepository->find($payment->id);
        $this->assertNull($this->recurrentPaymentsRepository->recurrent($payment));

        // **********************************************************
        // create and process DID_CHANGE_RENEWAL_STATUS notification
        // notification is same, field auto_renew_status is added with 'true' (REACTIVATE recurrent)
        $notification = $initialBuyRequestData;
        $notification["notification_type"] = "DID_CHANGE_RENEWAL_STATUS";
        $notification["auto_renew_status"] = true;

        $this->handleNotification($notification);

        // check state of recurrent (should be created and active)
        $payment = $this->paymentsRepository->find($payment->id);
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrentPayment->state);
    }

    private function didFailToRenew(string $expirationIntent, ?DateTime $gracePeriodEndDate)
    {
        ["notification" => $initialBuyRequestData, "payment" => $initPayment] = $this->prepareInitialBuyData();

        // between end time of previous subscription and "purchase date" of recovered payment will be gap
        $originalExpiresDate = new DateTime($initPayment->subscription_end_at);
        $purchaseDate = (clone $originalExpiresDate)->modify("+1 day");
        $expiresDate = (clone $purchaseDate)->modify('+1 month');

        $notification = [
            "notification_type" => "DID_FAIL_TO_RENEW", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => [
                    (object) [
                        "expires_date_ms" => $this->convertToTimestampFlooredToSeconds($expiresDate),
                        // original purchase date and original transaction ID will be same as initial buy payment
                        "original_purchase_date_ms" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_purchase_date_ms,
                        "original_transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
                        "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
                        "purchase_date_ms" => $this->convertToTimestampFlooredToSeconds($purchaseDate),
                        "quantity" => "1",
                        "transaction_id" => 'DID_FAIL_TO_RENEW_transaction_id',
                    ],
                    $initialBuyRequestData["unified_receipt"]->latest_receipt_info[1],
                ],
                "pending_renewal_info" => [
                    (object) [
                        "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
                        "auto_renew_status" => "1",
                        "expiration_intent" => $expirationIntent,
                        "grace_period_expires_date_ms" => $gracePeriodEndDate ? $this->convertToTimestampFlooredToSeconds($gracePeriodEndDate) : null,
                        "auto_renew_product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->product_id,
                        "original_transaction_id" =>  $initialBuyRequestData["unified_receipt"]->latest_receipt_info[0]->original_transaction_id,
                        "is_in_billing_retry_period" => "1",
                    ],
                ],
                "status" => 0,
            ],
        ];
        $notification["unified_receipt"]->latest_receipt = base64_encode(json_encode($notification["unified_receipt"]->latest_receipt_info));

        $this->handleNotification($notification);
    }

    public function testDidFailToRenewNoGracePeriod()
    {
        $this->didFailToRenew(PendingRenewalInfo::EXPIRATION_INTENT_BILLING_ERROR, null);

        // load payments by original transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        $paymentMeta = reset($paymentMetas);
        $user = $paymentMeta->payment->user;

        $userSubscriptions = $this->subscriptionsRepository->actualUserSubscriptions($user->id);
        $this->assertCount(1, $userSubscriptions);

        $graceSubscriptions = $this->subscriptionsRepository
            ->actualUserSubscriptions($user->id)
            ->where('type = ?', SubscriptionsRepository::TYPE_FREE);
        $this->assertEmpty($graceSubscriptions);

        $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentMeta->payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrent->state);
    }

    public function testDidFailToRenewWithGracePeriod()
    {
        $gracePeriodEndTime = DateTime::from('+40 days');
        $this->didFailToRenew(
            PendingRenewalInfo::EXPIRATION_INTENT_BILLING_ERROR,
            $gracePeriodEndTime,
        );

        // load payments by original transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );
        $this->assertCount(1, $paymentMetas);

        $paymentMeta = reset($paymentMetas);
        $user = $paymentMeta->payment->user;

        $userSubscriptions = $this->subscriptionsRepository->actualUserSubscriptions($user->id);
        $this->assertCount(2, $userSubscriptions);

        $graceSubscriptions = $this->subscriptionsRepository
            ->actualUserSubscriptions($user->id)
            ->where('type = ?', SubscriptionsRepository::TYPE_FREE)
            ->fetchAll();
        $this->assertCount(1, $graceSubscriptions);
        $graceSubscription = reset($graceSubscriptions);

        $this->assertEquals(
            $this->convertToTimestampFlooredToSeconds($gracePeriodEndTime),
            $this->convertToTimestampFlooredToSeconds($graceSubscription->end_time),
        );

        $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentMeta->payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrent->state);
    }

    public function testDidFailToRenewIntendedToStop()
    {
        $this->didFailToRenew(
            PendingRenewalInfo::EXPIRATION_INTENT_CANCELLED_SUBSCRIPTION,
            null,
        );

        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            self::APPLE_ORIGINAL_TRANSACTION_ID,
        );

        $paymentMeta = reset($paymentMetas);
        $user = $paymentMeta->payment->user;

        $this->assertCount(1, $paymentMetas);

        $userSubscriptions = $this->subscriptionsRepository->actualUserSubscriptions($user->id);
        $this->assertCount(1, $userSubscriptions);

        $graceSubscriptions = $this->subscriptionsRepository
            ->actualUserSubscriptions($user->id)
            ->where('type = ?', SubscriptionsRepository::TYPE_FREE);
        $this->assertEmpty($graceSubscriptions);

        $recurrent = $this->recurrentPaymentsRepository->recurrent($paymentMeta->payment);
        $this->assertEquals(RecurrentPaymentStateEnum::SystemStop->value, $recurrent->state);
    }

    /* HELPER FUNCTION ************************************************ */

    private function loadSubscriptionType()
    {
        if (!$this->subscriptionType) {
            $subscriptionType = $this->subscriptionTypesRepository->findByCode(self::SUBSCRIPTION_TYPE_CODE);
            if (!$subscriptionType) {
                $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                    ->setName('apple appstore test subscription month')
                    ->setUserLabel('apple appstore test subscription month')
                    ->setPrice(6.99)
                    ->setCode(self::SUBSCRIPTION_TYPE_CODE)
                    ->setLength(31)
                    ->setActive(true)
                    ->save();
            }
            $this->subscriptionType = $subscriptionType;
        }
        return $this->subscriptionType;
    }

    private function loadUser()
    {
        if (!$this->user) {
            $email = 'apple.appstore+test1@example.com';
            $user = $this->usersRepository->getByEmail($email);
            if (!$user) {
                $user = $this->usersRepository->add($email, 'MacOSrunsOnFlash');
            }
            $this->user = $user;
        }
        return $this->user;
    }

    private function mapAppleProductToSubscriptionType(string $appleProductID, ActiveRow $subscriptionType)
    {
        $this->appleAppstoreSubscriptionTypeRepository->add($appleProductID, $subscriptionType);
    }

    private function convertToTimestampFlooredToSeconds(\DateTime $datetime): string
    {
        return (string) floor($datetime->format("U")*1000);
    }

    private function handleNotification(array $notification): void
    {
        $this->serverToServerNotificationWebhookHandler->handle(new Message('test', [
            'notification' => $notification,
        ]));
    }
}
