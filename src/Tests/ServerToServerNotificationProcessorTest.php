<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Model\ServerToServerNotification;
use Crm\AppleAppstoreModule\Model\ServerToServerNotificationProcessor;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\VariableSymbolInterface;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\User\UnclaimedUser;
use Nette\Utils\DateTime;

class ServerToServerNotificationProcessorTest extends DatabaseTestCase
{
    /** @var ServerToServerNotificationProcessor */
    private $serverToServerNotificationProcessor;

    /** @var PaymentsRepository */
    private $paymentsRepository;
    /** @var PaymentMetaRepository */
    private $paymentMetaRepository;
    /** @var UserMetaRepository */
    private $userMetaRepository;
    /** @var UsersRepository */
    private $usersRepository;

    protected function requiredRepositories(): array
    {
        // we need to truncate all these repositories before each test
        return [
            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,
            UsersRepository::class,
            UserMetaRepository::class,
            VariableSymbolInterface::class,
        ];
    }

    public function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverToServerNotificationProcessor = $this->inject(ServerToServerNotificationProcessor::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);

        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userMetaRepository = $this->getRepository(UserMetaRepository::class);
    }

    public function testGetUserFromPaymentMeta()
    {
        $originalTransactionID = "hsalFnOsnurSOcaM";

        // create user
        $userEmail = 'appleTest@example.com';
        $user = $this->usersRepository->add($userEmail, 'hsalFnOsnurSOcaM');
        // create payment with original_transaction_id in payment_meta
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $paymentGatewayRow = $paymentGatewaysRepository->findByCode(AppleAppstoreGateway::GATEWAY_CODE);
        $payment = $this->paymentsRepository->add(
            null,
            $paymentGatewayRow,
            $user,
            new PaymentItemContainer(),
            null,
            1
        );
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );

        $serverToServerNotification = $this->createTestServerToServerNotification($originalTransactionID);
        $latestReceiptInfo = $this->serverToServerNotificationProcessor->getLatestLatestReceiptInfo($serverToServerNotification);
        $user = $this->serverToServerNotificationProcessor->getUser($latestReceiptInfo);
        $this->assertIsObject($user);
        $this->assertEquals($userEmail, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            0,
            $usersWithUnclaimedFlag,
            "No unclaimed user should be created."
        );

        $paymentMetasWithOriginalTransactionID = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );
        $this->assertCount(1, $paymentMetasWithOriginalTransactionID, "Only one payment should be created with this original transaction ID in meta.");
        $userLoadedFromMeta = reset($paymentMetasWithOriginalTransactionID)->payment->user;
        $this->assertEquals($user->id, $userLoadedFromMeta->id);
    }

    public function testGetUserFromUserMeta()
    {
        $originalTransactionID = "hsalFnOsnurSOcaM";

        // create user with user_meta & $originalTransactionID
        $userEmail = 'appleTest@example.com';
        $user = $this->usersRepository->add($userEmail, 'hsalFnOsnurSOcaM');
        $this->userMetaRepository->add(
            $user,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        );

        $serverToServerNotification = $this->createTestServerToServerNotification($originalTransactionID);
        $latestReceiptInfo = $this->serverToServerNotificationProcessor->getLatestLatestReceiptInfo($serverToServerNotification);
        $user = $this->serverToServerNotificationProcessor->getUser($latestReceiptInfo);
        $this->assertIsObject($user);
        $this->assertEquals($userEmail, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            0,
            $usersWithUnclaimedFlag,
            "No unclaimed user should be created."
        );

        $usersWithOriginalTransactionID = $this->userMetaRepository->usersWithKey(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID
        )->fetchAll();
        $this->assertCount(1, $usersWithOriginalTransactionID, "Only one user should be created with this original transaction ID.");
        $userLoadedFromMeta = reset($usersWithOriginalTransactionID)->user;
        $this->assertEquals($user->id, $userLoadedFromMeta->id);
    }

    public function testGetUserCreatedNewUnclaimed()
    {
        $originalTransactionID = "hsalF_no_snur_SOcaM";

        $serverToServerNotification = $this->createTestServerToServerNotification($originalTransactionID);
        $latestReceiptInfo = $this->serverToServerNotificationProcessor->getLatestLatestReceiptInfo($serverToServerNotification);
        $user = $this->serverToServerNotificationProcessor->getUser($latestReceiptInfo);
        $this->assertIsObject($user);
        $this->assertStringContainsString('apple_appstore_' . $originalTransactionID, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            1,
            $usersWithUnclaimedFlag,
            "Only one unclaimed user should be created."
        );

        $userWithUnclaimedFlag = reset($usersWithUnclaimedFlag)->user;
        $this->assertEquals(
            $user->id,
            $userWithUnclaimedFlag->id,
            "Unclaimed user's ID should be same as ID of user returned by processor's `getUser()`."
        );
    }

    private function createTestServerToServerNotification(string $originalTransactionID): ServerToServerNotification
    {
        // must be in future because of Crm\PaywallModule\Events\SubscriptionChangeHandler check against actual date
        $originalPurchaseDate = new DateTime("2066-01-02 15:04:05");
        // purchase date is same as original purchase date for INITIAL_BUY
        $purchaseDate = (clone $originalPurchaseDate);
        $expiresDate = (clone $originalPurchaseDate)->modify("1 month");

        // JSON following scheme <module-path>/src/api/server-to-server-notification.schema.json
        $jsonData = (object) [
            "notification_type" => ServerToServerNotification::NOTIFICATION_TYPE_INITIAL_BUY,
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => [
                    (object)[
                        "expires_date_ms" => $this->convertToTimestampWithMilliseconds($expiresDate),
                        "original_purchase_date_ms" => $this->convertToTimestampWithMilliseconds($originalPurchaseDate),
                        "original_transaction_id" => $originalTransactionID,
                        "product_id" => "apple_appstore_test_product_id",
                        "purchase_date_ms" => $this->convertToTimestampWithMilliseconds($purchaseDate),
                        "quantity" => "1",
                        // transaction ID is same for INITIAL_BUY
                        "transaction_id" => $originalTransactionID,
                    ]
                ],
                "pending_renewal_info" => [],
                "status" => 0
            ],
        ];

        return new ServerToServerNotification($jsonData);
    }

    private function convertToTimestampWithMilliseconds(DateTime $datetime): string
    {
        return (string) floor($datetime->format("U.u")*1000);
    }
}
