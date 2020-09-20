<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Events\UserSignOutEventHandler;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreReceiptsRepository;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreTransactionDeviceTokensRepository;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Events\UserSignOutEvent;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;

class UserSignOutEventHandlerTest extends DatabaseTestCase
{
    /** @var ActiveRow */
    private $paymentGateway;

    /** @var Emitter */
    private $emitter;

    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var AppleAppstoreTransactionDeviceTokensRepository */
    private $transactionDeviceTokensRepository;

    /** @var DeviceTokensRepository */
    private $deviceTokensRepository;

    /** @var PaymentMetaRepository */
    private $paymentMetaRepository;

    /** @var AppleAppstoreReceiptsRepository */
    private $appleAppstoreReceiptsRepository;

    /** @var UserManager */
    private $userManager;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            AccessTokensRepository::class,
            DeviceTokensRepository::class,
            AppleAppstoreTransactionDeviceTokensRepository::class,
            PaymentMetaRepository::class,
            PaymentGatewaysRepository::class,
            AppleAppstoreReceiptsRepository::class,
            PaymentsRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeItemsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            \Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder::class
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        /** @var PaymentGatewaysRepository $paymentGatewaysRepository */
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $paymentGatewaysRepository->findByCode(AppleAppstoreGateway::GATEWAY_CODE);
        $this->accessTokensRepository = $this->inject(AccessTokensRepository::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->transactionDeviceTokensRepository = $this->getRepository(AppleAppstoreTransactionDeviceTokensRepository::class);
        $this->deviceTokensRepository = $this->getRepository(DeviceTokensRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->appleAppstoreReceiptsRepository = $this->getRepository(AppleAppstoreReceiptsRepository::class);

        $this->emitter = $this->inject(Emitter::class);
        $this->emitter->addListener(
            UserSignOutEvent::class,
            $this->inject(UserSignOutEventHandler::class)
        );
    }

    public function testRegularSignOut()
    {
        // login user
        $user1 = $this->getUser(1);
        $this->accessTokensRepository->add($user1, 3);
        $this->accessTokensRepository->add($user1, 3);

        // logout and verify
        $this->userManager->logoutUser($user1);
        $this->assertCount(0, $this->accessTokensRepository->allUserTokens($user1->id));
    }

    public function testSingleDeviceLinked()
    {
        // login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1, 3);
        $accessToken2 = $this->accessTokensRepository->add($user1, 3);

        // pair user with device
        $deviceToken = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);

        // pair transaction with device
        $originalTransactionId = '123';
        $this->appleAppstoreReceiptsRepository->add($originalTransactionId, 'fake');
        $this->transactionDeviceTokensRepository->add($originalTransactionId, $deviceToken);

        $payment = $this->createPayment($user1);
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(1, $userTokens);
        $token = reset($userTokens);
        $this->assertNotEquals($token->token, $accessToken1);
        $this->assertNotEquals($token->token, $accessToken2);
    }

    public function testMultipleDevicesLinked()
    {
        // login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1, 3);
        $accessToken2 = $this->accessTokensRepository->add($user1, 3);
        $accessToken3 = $this->accessTokensRepository->add($user1, 3);

        // pair user with device
        $deviceToken1 = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken1);
        $deviceToken2 = $this->deviceTokensRepository->generate('bar');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken2, $deviceToken2);

        // pair transaction with device
        $originalTransactionId = '123';
        $this->appleAppstoreReceiptsRepository->add($originalTransactionId, 'fake');
        $this->transactionDeviceTokensRepository->add($originalTransactionId, $deviceToken1); // linked during verifyPurchase
        $this->transactionDeviceTokensRepository->add($originalTransactionId, $deviceToken2); // linked during restorePurchase

        $payment = $this->createPayment($user1);
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(2, $userTokens);
    }

    public function testMultipleTransactionsLinked()
    {
        // this is probably only theoretical scenario, but let's test; login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1, 3);
        $accessToken2 = $this->accessTokensRepository->add($user1, 3);

        // pair user with device
        $deviceToken = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);

        // pair transactions with device
        foreach (['123' => 'fake', '456' => 'test'] as $originalTransactionId => $receipt) {
            $this->appleAppstoreReceiptsRepository->add($originalTransactionId, $receipt);
            $this->transactionDeviceTokensRepository->add($originalTransactionId, $deviceToken);
            $payment = $this->createPayment($user1);
            $this->paymentMetaRepository->add(
                $payment,
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                $originalTransactionId
            );
        }

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(1, $userTokens);
    }

    protected function createPayment($user)
    {
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($this->getSubscriptionType()));
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $payment = $paymentsRepository->add(
            $this->getSubscriptionType(),
            $this->getPaymentGateway(),
            $user,
            $paymentItemContainer
        );
        return $payment;
    }

    /** @var ActiveRow */
    private $users;

    protected function getUser($id)
    {
        if (!isset($this->users[$id])) {
            $usersRepository = $this->getRepository(UsersRepository::class);
            $this->users[$id] = $usersRepository->add('asfsaoihf@afasf.sk', 'q039uewt', '', '', '', 1);
        }
        return $this->users[$id];
    }

    protected function getPaymentGateway()
    {
        return $this->paymentGateway;
    }

    /** @var ActiveRow */
    private $subscriptionType;

    protected function getSubscriptionType()
    {
        if (!$this->subscriptionType) {
            $subscriptionTypeBuilder = $this->container->getByType(SubscriptionTypeBuilder::class);
            $this->subscriptionType = $subscriptionTypeBuilder->createNew()
                ->setName('my subscription type')
                ->setUserLabel('my subscription type')
                ->setPrice(12.2)
                ->setCode('my_subscription_type')
                ->setLength(31)
                ->setActive(true)
                ->save();
        }
        return $this->subscriptionType;
    }
}
