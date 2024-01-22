<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\Events\PairDeviceAccessTokensEventHandler;
use Crm\ApplicationModule\Event\LazyEventEmitter;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\UsersModule\Events\PairDeviceAccessTokensEvent;
use Crm\UsersModule\Models\User\UnclaimedUser;
use Crm\UsersModule\Repositories\AccessTokensRepository;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repositories\UserMetaRepository;
use Crm\UsersModule\Repositories\UsersRepository;

class PairDeviceAccessTokensEventHandlerTest extends DatabaseTestCase
{
    const CLAIMED_LOGIN = '1test@claimed.st';
    const UNCLAIMED_LOGIN = '1test@unclaimed.st';

    /** @var LazyEventEmitter */
    private $lazyEventEmitter;

    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var DeviceTokensRepository */
    private $deviceTokensRepository;

    /** @var UsersRepository */
    private $usersRepository;

    /** @var UnclaimedUser */
    private $unclaimedUser;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            UserMetaRepository::class,
            DeviceTokensRepository::class,
            AccessTokensRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->accessTokensRepository = $this->getRepository(AccessTokensRepository::class);
        $this->deviceTokensRepository = $this->getRepository(DeviceTokensRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);

        $this->unclaimedUser = $this->inject(UnclaimedUser::class);
        $this->lazyEventEmitter = $this->inject(LazyEventEmitter::class);
        $this->lazyEventEmitter->addListener(
            PairDeviceAccessTokensEvent::class,
            $this->inject(PairDeviceAccessTokensEventHandler::class)
        );
    }

    protected function tearDown(): void
    {
        $this->lazyEventEmitter->removeAllListeners(PairDeviceAccessTokensEvent::class);

        parent::tearDown();
    }

    public function testNoUnclaimedUserPairing()
    {
        $deviceToken = $this->deviceTokensRepository->generate('foo');

        $user = $this->getClaimedUser();
        $accessToken = $this->accessTokensRepository->add($user);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);

        // nothing significant should happen, no user claiming
        $this->assertNull($this->unclaimedUser->getPreviouslyClaimedUser($user)->id ?? null);
    }

    public function testUnclaimedUserPairing()
    {
        $deviceToken = $this->deviceTokensRepository->generate('foo');

        // pair unclaimed user
        $unclaimedUser = $this->getUnclaimedUser();
        $unclaimedAccessToken = $this->accessTokensRepository->add($unclaimedUser);
        $this->accessTokensRepository->pairWithDeviceToken($unclaimedAccessToken, $deviceToken);

        // pair regular user
        $user = $this->getClaimedUser();
        $accessToken = $this->accessTokensRepository->add($user);
        $this->accessTokensRepository->pairWithDeviceToken($accessToken, $deviceToken);

        // pairing regular user should trigger claim of linked unclaimed user
        $this->assertEquals(
            $user->id,
            $this->unclaimedUser->getClaimer($unclaimedUser)->id ?? null
        );
        $this->assertEquals(
            $unclaimedUser->id,
            $this->unclaimedUser->getPreviouslyClaimedUser($user)->id ?? null
        );
    }

    private function getClaimedUser()
    {
        return $this->usersRepository->add(self::CLAIMED_LOGIN, 'secret');
    }

    private function getUnclaimedUser()
    {
        return $this->unclaimedUser->createUnclaimedUser(self::UNCLAIMED_LOGIN);
    }
}
