<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Models\ServerToServerNotificationV2Processor\ServerToServerNotificationV2Processor;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\VariableSymbolInterface;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;
use Readdle\AppStoreServerAPI\TransactionInfo;

class ServerToServerNotificationV2ProcessorTest extends DatabaseTestCase
{
    private ServerToServerNotificationV2Processor $serverToServerNotificationV2Processor;
    private PaymentsRepository $paymentsRepository;
    private PaymentMetaRepository $paymentMetaRepository;
    private UserMetaRepository $userMetaRepository;
    private UsersRepository $usersRepository;

    protected function requiredRepositories(): array
    {
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

        $this->serverToServerNotificationV2Processor = $this->inject(ServerToServerNotificationV2Processor::class);

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
            1,
        );
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID,
        );

        $transactionInfo = TransactionInfo::createFromRawTransactionInfo([
            'originalTransactionId' => $originalTransactionID,
        ]);

        $user = $this->serverToServerNotificationV2Processor->getUser($transactionInfo);
        $this->assertIsObject($user);
        $this->assertEquals($userEmail, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            0,
            $usersWithUnclaimedFlag,
            "No unclaimed user should be created.",
        );

        $paymentMetasWithOriginalTransactionID = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID,
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
            $originalTransactionID,
        );

        $transactionInfo = TransactionInfo::createFromRawTransactionInfo([
            'originalTransactionId' => $originalTransactionID,
        ]);

        $user = $this->serverToServerNotificationV2Processor->getUser($transactionInfo);
        $this->assertIsObject($user);
        $this->assertEquals($userEmail, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            0,
            $usersWithUnclaimedFlag,
            "No unclaimed user should be created.",
        );

        $usersWithOriginalTransactionID = $this->userMetaRepository->usersWithKey(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionID,
        )->fetchAll();
        $this->assertCount(1, $usersWithOriginalTransactionID, "Only one user should be created with this original transaction ID.");
        $userLoadedFromMeta = reset($usersWithOriginalTransactionID)->user;
        $this->assertEquals($user->id, $userLoadedFromMeta->id);
    }

    public function testGetUserCreatedNewUnclaimed()
    {
        $originalTransactionID = "hsalF_no_snur_SOcaM";
        $transactionInfo = TransactionInfo::createFromRawTransactionInfo([
            'originalTransactionId' => $originalTransactionID,
        ]);

        $user = $this->serverToServerNotificationV2Processor->getUser($transactionInfo);
        $this->assertIsObject($user);
        $this->assertStringContainsString('apple_appstore_' . $originalTransactionID, $user->email);

        $usersWithUnclaimedFlag = $this->userMetaRepository
            ->usersWithKey(UnclaimedUser::META_KEY, 1)
            ->fetchAll();
        $this->assertCount(
            1,
            $usersWithUnclaimedFlag,
            "Only one unclaimed user should be created.",
        );

        $userWithUnclaimedFlag = reset($usersWithUnclaimedFlag)->user;
        $this->assertEquals(
            $user->id,
            $userWithUnclaimedFlag->id,
            "Unclaimed user's ID should be same as ID of user returned by processor's `getUser()`.",
        );
    }
}
